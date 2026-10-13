"""Libreria visiva: il ritaglio di un velivolo diventa un vettore di 384
numeri, e velivoli dello stesso tipo danno vettori vicini. Confrontando un
aereo rilevato con quelli già identificati dall'analista (vedi
webapp/src/VisualLibrary.php) si propone il tipo per somiglianza, non solo
per dimensioni: separa tipi di misure quasi identiche (A400M e B-1 ad ali
aperte, A330 e 787) che la tabella dei tipi non può distinguere.

Modello: DINOv2 ViT-S/14 di Meta (licenza Apache-2.0), in ONNX, eseguito
con il modulo DNN di OpenCV già presente nel servizio. Il file non è nel
repository: vedi tools/fetch_embedding_model.sh. Gira in locale.

Ritaglio: la fusoliera orizzontale (asse della sagoma, vedi silhouette.py),
una finestra di 1,3 volte la dimensione maggiore dell'aereo, a scala in
metri: lo stesso tipo appare sempre alla stessa grandezza e orientamento.
Il muso può stare a destra o a sinistra: si calcolano i due orientamenti e
si confronta il migliore. Senza sagoma attendibile si usa il riquadro, il
cui lato lungo può essere apertura o lunghezza: quattro orientamenti.

Prova su 24 aerei di 6 tipi (Brize Norton nel 2021 e oggi, Bergamo,
Sigonella): il più simile preso da un'altra ripresa era dello stesso tipo in
20 casi su 20 (token CLS, sfondo originale; neutralizzando lo sfondo fuori
dalla sagoma, 18 su 20).
"""
import base64
import math
import threading
import time
from pathlib import Path

import cv2
import numpy as np

MODEL_PATH = Path(__file__).resolve().parents[2] / "models" / "dinov2-small.onnx"
MODEL_NAME = "dinov2-small"
INPUT = 224
WINDOW = 1.3
# ImageNet, come nell'addestramento di DINOv2 (RGB).
MEAN = np.array([0.485, 0.456, 0.406], np.float32)
STD = np.array([0.229, 0.224, 0.225], np.float32)
# Tempo massimo per tutti i vettori di un rilevamento: oltre, gli oggetti
# restanti restano senza (circa 0,25 s per orientamento su questa CPU).
TIME_BUDGET_S = 60.0
CLASSES = ("plane", "helicopter")

_net = None
_lock = threading.Lock()


def available() -> bool:
    return MODEL_PATH.is_file()


def _get_net():
    global _net
    if _net is None:
        _net = cv2.dnn.readNetFromONNX(str(MODEL_PATH))
    return _net


def crop(iso: np.ndarray, center, axis_deg: float, size_m: float, s: float) -> np.ndarray:
    """Ritaglio INPUT×INPUT con l'asse dato orizzontale. iso: immagine a
    pixel quadrati (s metri per pixel); center in pixel di iso."""
    side = max(16, int(round(size_m * WINDOW / s)))
    M = cv2.getRotationMatrix2D((float(center[0]), float(center[1])), axis_deg, 1.0)
    M[0, 2] += side / 2 - center[0]
    M[1, 2] += side / 2 - center[1]
    c = cv2.warpAffine(iso, M, (side, side), flags=cv2.INTER_AREA, borderValue=(124, 116, 104))
    return cv2.resize(c, (INPUT, INPUT), interpolation=cv2.INTER_AREA)


def vector(img: np.ndarray) -> np.ndarray:
    """Vettore normalizzato (token CLS) di un ritaglio BGR INPUT×INPUT."""
    x = (cv2.cvtColor(img, cv2.COLOR_BGR2RGB).astype(np.float32) / 255.0 - MEAN) / STD
    with _lock:
        net = _get_net()
        net.setInput(x.transpose(2, 0, 1)[None].astype(np.float32))
        h = net.forward()[0]
    v = h[0]
    return v / max(1e-9, float(np.linalg.norm(v)))


def encode(v: np.ndarray) -> str:
    """Vettore → int8 in base64 (384 byte), leggero da salvare con il
    rilevamento. La scala porta la componente più grande a ±127 (le
    componenti di un vettore normalizzato restano sotto ~0,2: con una scala
    fissa si perderebbe quasi tutta la precisione); chi decodifica
    rinormalizza, quindi la scala non va salvata."""
    m = float(np.abs(v).max()) or 1.0
    return base64.b64encode(np.clip(np.round(v * (127.0 / m)), -127, 127).astype(np.int8).tobytes()).decode("ascii")


def decode(b64: str) -> np.ndarray:
    v = np.frombuffer(base64.b64decode(b64), np.int8).astype(np.float32)
    return v / max(1e-9, float(np.linalg.norm(v)))


def embed_objects(image: np.ndarray, detections: list, silhouettes: dict, mpp_x: float, mpp_y: float) -> dict:
    """Vettori degli aerei e degli elicotteri rilevati: {indice: [b64, ...]}
    (un vettore per orientamento)."""
    out = {}
    if not available() or not mpp_x or not mpp_y:
        return out
    s = min(mpp_x, mpp_y)
    fx, fy = mpp_x / s, mpp_y / s
    iso = None
    started = time.monotonic()
    for i, d in enumerate(detections):
        if d.get("class") not in CLASSES:
            continue
        if time.monotonic() - started > TIME_BUDGET_S:
            break
        if iso is None:
            iso = cv2.resize(image, (round(image.shape[1] * fx), round(image.shape[0] * fy)), interpolation=cv2.INTER_AREA)
        sil = silhouettes.get(i) or {}
        if sil.get("quality") == "ok" and sil.get("outline") and sil.get("axis_deg") is not None:
            pts = np.array(sil["outline"], float) * [fx, fy]
            center = pts.mean(0)
            size = max(sil["span_m"], sil["length_m"])
            angles = [sil["axis_deg"], sil["axis_deg"] + 180]
        else:
            # Senza sagoma: asse dal lato lungo del riquadro (apertura o
            # lunghezza, non si sa), quindi quattro orientamenti.
            pts = (np.array(d["polygon"], float) * [fx, fy]).astype(np.float32)
            (cx, cy), (w, h), a = cv2.minAreaRect(pts)
            center = (cx, cy)
            size = max(w, h) * s * 0.87  # il riquadro include margine e ombra
            base = a if w >= h else a + 90
            angles = [base, base + 90, base + 180, base + 270]
        if not math.isfinite(size) or size <= 0:
            continue
        out[i] = [encode(vector(crop(iso, center, ang, size, s))) for ang in angles]
    return out
