"""Rilevamento automatico di oggetti (aerei, elicotteri, navi, veicoli...) su
immagini ad alta risoluzione, con riquadri orientati.

Modello: YOLO11s-OBB di Ultralytics addestrato sul dataset DOTA v1 (15
classi di oggetti in immagini aeree e satellitari), esportato in ONNX ed
eseguito con il modulo DNN di OpenCV già presente nel servizio — nessuna
dipendenza aggiuntiva. Licenze: il modello è AGPL-3.0 (compatibile con la
GPLv3 del progetto); i pesi addestrati su DOTA sono per uso di ricerca, non
commerciale. Il file non è nel repository: vedi tools/fetch_detector_model.sh.

Il modello è addestrato su immagini con risoluzione fra ~0,1 e ~1 m/pixel:
a 10 m/pixel (Sentinel) un aereo è qualche pixel e il rilevamento non ha
senso — il chiamante lo impedisce sopra i 2 m/pixel. Su richiesta ("oggetti
piccoli"), un'immagine meno dettagliata di 0,5 m/pixel viene ingrandita
(fino a 2×) prima del rilevamento: gli oggetti piccoli (un caccia è 15 m,
cioè 13 pixel a 1,1 m/pixel) vengono riconosciuti meglio.
"""
import math
import threading
from pathlib import Path

import cv2
import numpy as np

MODEL_PATH = Path(__file__).resolve().parents[2] / "models" / "yolo11s-obb.onnx"
INPUT = 1024
# Sovrapposizione fra tasselli: un oggetto più piccolo di così sta sempre
# per intero in almeno un tassello (un C-17 a 0,15 m/pixel è ~350 pixel).
OVERLAP = 384
NMS_IOU = 0.4
# Un riquadro coperto per oltre questa quota da uno più sicuro (di qualunque
# classe) è un suo frammento: oggetto tagliato al bordo di un tassello, o
# lo stesso oggetto classificato in due modi in due tasselli.
CONTAINED = 0.6
TARGET_MPP = 0.5
MAX_UPSCALE = 2.0
# Tetto al lavoro di una richiesta (~5 s per tassello su una CPU desktop):
# oltre, l'ingrandimento si riduce; senza ingrandimento si rifiuta.
MAX_TILES = 25
# Attesa massima se un altro rilevamento è in corso: meglio rispondere
# "occupato" che restare in coda oltre il timeout di chi ha chiesto.
LOCK_WAIT = 20

# Classi DOTA v1, nell'ordine del modello, con nome italiano.
CLASSES = [
    ("plane", "aereo"), ("ship", "nave"), ("storage tank", "serbatoio"),
    ("baseball diamond", "campo da baseball"), ("tennis court", "campo da tennis"),
    ("basketball court", "campo da basket"), ("ground track field", "pista d'atletica"),
    ("harbor", "porto"), ("bridge", "ponte"), ("large vehicle", "veicolo grande"),
    ("small vehicle", "veicolo piccolo"), ("helicopter", "elicottero"),
    ("roundabout", "rotatoria"), ("soccer ball field", "campo da calcio"),
    ("swimming pool", "piscina"),
]

_net = None
_lock = threading.Lock()


class DetectorUnavailable(RuntimeError):
    pass


class DetectorBusy(RuntimeError):
    pass


def available() -> bool:
    return MODEL_PATH.is_file()


def _get_net():
    global _net
    if _net is None:
        if not MODEL_PATH.is_file():
            raise DetectorUnavailable(
                "Modello di rilevamento non installato (python-service/models/yolo11s-obb.onnx): "
                "vedi python-service/tools/fetch_detector_model.sh"
            )
        _net = cv2.dnn.readNetFromONNX(str(MODEL_PATH))
    return _net


def _infer_tile(net, tile: np.ndarray, conf: float):
    """Un riquadro fino a INPUT×INPUT (BGR). Ritorna array (N, 7):
    cx, cy, w, h, angolo (radianti), confidenza, classe — in pixel del riquadro."""
    h, w = tile.shape[:2]
    canvas = np.full((INPUT, INPUT, 3), 114, np.uint8)
    canvas[:h, :w] = tile
    blob = cv2.dnn.blobFromImage(canvas, 1 / 255.0, (INPUT, INPUT), swapRB=True)
    net.setInput(blob)
    out = net.forward()[0].T  # (21504, 4 + 15 classi + angolo)
    scores = out[:, 4:4 + len(CLASSES)]
    cls = scores.argmax(1)
    cf = scores.max(1)
    keep = cf >= conf
    if not keep.any():
        return np.zeros((0, 7), np.float32)
    o = out[keep]
    return np.column_stack([o[:, 0], o[:, 1], o[:, 2], o[:, 3], o[:, 4 + len(CLASSES)], cf[keep], cls[keep]]).astype(np.float32)


def _tile_count(w: int, h: int) -> int:
    return len(_tile_origins(h)) * len(_tile_origins(w))


def _suppress_fragments(dets: list) -> list:
    """Toglie i riquadri contenuti per lo più in uno più sicuro (vedi
    CONTAINED), qualunque sia la classe. dets: lista di dict con rect
    ((cx, cy), (w, h), gradi) e confidence, ordinata per confidenza."""
    kept = []
    for d in dets:
        area = max(1e-6, d["rect"][1][0] * d["rect"][1][1])
        fragment = False
        for k in kept:
            kind, pts = cv2.rotatedRectangleIntersection(d["rect"], k["rect"])
            if kind != cv2.INTERSECT_NONE and pts is not None and len(pts) >= 3:
                if cv2.contourArea(cv2.convexHull(pts)) / area > CONTAINED:
                    fragment = True
                    break
        if not fragment:
            kept.append(d)
    return kept


def _tile_origins(size: int) -> list:
    if size <= INPUT:
        return [0]
    step = INPUT - OVERLAP
    origins = list(range(0, size - INPUT, step)) + [size - INPUT]
    return sorted(set(origins))


def detect(image: np.ndarray, mpp: float | None, conf: float = 0.25) -> dict:
    """image: BGR (o BGRA: le zone trasparenti diventano grigie). mpp:
    metri/pixel della ripresa, se noto (decide l'eventuale ingrandimento).
    Ritorna rilevamenti in pixel dell'immagine originale."""
    if image.ndim == 3 and image.shape[2] == 4:
        alpha = image[:, :, 3:4].astype(np.float32) / 255.0
        image = (image[:, :, :3] * alpha + 114 * (1 - alpha)).astype(np.uint8)
    elif image.ndim == 2:
        image = cv2.cvtColor(image, cv2.COLOR_GRAY2BGR)

    h0, w0 = image.shape[:2]
    if _tile_count(w0, h0) > MAX_TILES:
        raise ValueError(f"Immagine troppo grande per il rilevamento ({w0}×{h0} pixel): ritagliala sull'area che interessa.")
    upscale = 1.0
    if mpp and mpp > TARGET_MPP:
        upscale = min(MAX_UPSCALE, mpp / TARGET_MPP)
        # Ingrandimento ridotto finché il lavoro resta entro MAX_TILES.
        while upscale > 1.0 and _tile_count(int(w0 * upscale), int(h0 * upscale)) > MAX_TILES:
            upscale = max(1.0, upscale - 0.1)
    work = image if upscale == 1.0 else cv2.resize(image, None, fx=upscale, fy=upscale, interpolation=cv2.INTER_CUBIC)
    H, W = work.shape[:2]

    found = []
    # La rete OpenCV non va usata da più thread insieme.
    if not _lock.acquire(timeout=LOCK_WAIT):
        raise DetectorBusy("Un altro rilevamento è in corso: riprova fra poco.")
    try:
        net = _get_net()
        for oy in _tile_origins(H):
            for ox in _tile_origins(W):
                t = _infer_tile(net, work[oy:oy + INPUT, ox:ox + INPUT], conf)
                if len(t):
                    t[:, 0] += ox
                    t[:, 1] += oy
                    found.append(t)
    finally:
        _lock.release()
    if not found:
        return {"detections": [], "upscale": round(upscale, 3), "tiles": _tile_count(W, H)}
    allt = np.concatenate(found)

    # Soppressione dei doppioni (sovrapposizione fra riquadri vicini), per
    # classe, poi dei frammenti fra classi diverse.
    candidates = []
    for c in np.unique(allt[:, 6]).astype(int):
        sub = allt[allt[:, 6] == c]
        boxes = [((float(r[0]), float(r[1])), (float(r[2]), float(r[3])), float(math.degrees(r[4]))) for r in sub]
        idx = cv2.dnn.NMSBoxesRotated(boxes, sub[:, 5].tolist(), conf, NMS_IOU)
        for i in np.array(idx).flatten():
            candidates.append({"rect": boxes[i], "confidence": float(sub[i, 5]), "row": sub[i], "cls": c})
    candidates.sort(key=lambda d: -d["confidence"])
    detections = []
    for cand in _suppress_fragments(candidates):
        c = cand["cls"]
        cx, cy, bw, bh, ang, cf, _ = cand["row"]
        cx, cy, bw, bh = cx / upscale, cy / upscale, bw / upscale, bh / upscale
        pts = cv2.boxPoints(((float(cx), float(cy)), (float(bw), float(bh)), float(math.degrees(ang))))
        detections.append({
            "class": CLASSES[c][0],
            "label": CLASSES[c][1],
            "confidence": round(float(cf), 4),
            "cx": round(float(cx), 2), "cy": round(float(cy), 2),
            "w": round(float(bw), 2), "h": round(float(bh), 2),
            "angle": round(float(ang), 5),
            "polygon": [[round(float(x), 2), round(float(y), 2)] for x, y in pts],
        })
    detections.sort(key=lambda d: (d["class"], -d["confidence"]))
    return {
        "detections": detections,
        "upscale": round(upscale, 3),
        "tiles": _tile_count(W, H),
    }
