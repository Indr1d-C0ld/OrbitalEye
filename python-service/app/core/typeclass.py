"""Tipo di un aereo dai vettori della libreria visiva (embed.py), con un
classificatore addestrato su MAR20: 20 tipi militari, soprattutto
statunitensi e russi (C-130, C-17, C-5, KC-135, KC-10, E-3, E-8, P-3, B-1,
B-52, F-15, F-16, F-22, F/A-18, Su-24, Su-34, Su-35, Tu-22M, Tu-95,
Tu-160). Riconosce questi tipi senza bisogno che l'analista li abbia già
identificati, come invece serve alla libreria visiva.

MAR20 (Northwestern Polytechnical University): 3.842 immagini Google Earth
di 60 basi, licenza CC BY-NC 4.0, solo uso non commerciale e di ricerca.
Yu, Cheng, Wang, Yao, Xie, Yao, Han: "MAR20: A Benchmark for Military
Aircraft Recognition in Remote Sensing Images", National Remote Sensing
Bulletin, 2022. Né il dataset né il classificatore sono nel repository: si
ricrea con tools/train_type_classifier.py.

Il classificatore è lineare (regressione softmax) sui vettori DINOv2
standardizzati; la probabilità di un oggetto è la media sui suoi
orientamenti. Sull'insieme di prova ufficiale di MAR20 (60 aerei per tipo)
il tipo giusto è al primo posto nell'80% dei casi e fra i primi tre nel 93%;
con probabilità almeno 0,9 (44% degli aerei) è giusto nel 99%. I caccia sono
il punto debole (F/A-18 38%, F-15 e Su-35 58%). Con la sagoma il servizio
calcola due orientamenti invece di quattro, e le probabilità sono un po'
più sicure di quanto dovrebbero: primo posto 78-79%, con probabilità almeno
0,7 giusto nel 92% (95% con quattro), almeno 0,9 nel 97-99%.

Un tipo che MAR20 non contiene riceve comunque uno dei 20, talvolta con
probabilità alta (su Brize Norton A330 come C-5 o E-8, A400M come C-17): le
misure lo smascherano, vedi webapp/src/TypeClassifier.php.

Il file viene letto una volta per processo: dopo averlo riaddestrato si
riavvia il servizio. Un file non valido (dimensioni che non tornano, valori
non finiti, vettori di un altro modello) è trattato come assente.
"""
import threading
from pathlib import Path

import numpy as np

from . import embed

MODEL_PATH = Path(__file__).resolve().parents[2] / "models" / "mar20-types.npz"
MODEL_NAME = "mar20-linear"
TOP = 3
CLASSES = ("plane",)

_model = None
_invalid = None  # (percorso, motivo) dell'ultimo file scartato
_lock = threading.Lock()


class ModelInvalid(Exception):
    pass


def available() -> bool:
    if not MODEL_PATH.is_file():
        return False
    try:
        _get()
        return True
    except ModelInvalid:
        return False


def _load(path: Path) -> dict:
    try:
        d = np.load(path, allow_pickle=False)
        m = {k: d[k] for k in ("W", "b", "mu", "sd", "names")}
        source = str(d["embedding_model"]) if "embedding_model" in d.files else embed.MODEL_NAME
    except Exception as e:  # noqa: BLE001
        raise ModelInvalid(f"file illeggibile: {e}") from e
    dim, n = len(m["mu"]), len(m["names"])
    if m["W"].shape != (dim, n) or m["b"].shape != (n,) or m["sd"].shape != (dim,) or n < 2:
        raise ModelInvalid("dimensioni incoerenti")
    if not all(np.isfinite(m[k]).all() for k in ("W", "b", "mu", "sd")) or (m["sd"] <= 0).any():
        raise ModelInvalid("valori non finiti")
    if source != embed.MODEL_NAME:
        raise ModelInvalid(f"addestrato sui vettori di {source}, non di {embed.MODEL_NAME}")
    return m


def _get():
    global _model, _invalid
    with _lock:
        if _model is None:
            if _invalid and _invalid[0] == MODEL_PATH:
                raise ModelInvalid(_invalid[1])
            try:
                _model = _load(MODEL_PATH)
            except ModelInvalid as e:
                _invalid = (MODEL_PATH, str(e))
                raise
        return _model


def probabilities(vectors: list) -> np.ndarray:
    """Probabilità per tipo (nell'ordine di names) da vettori in base64,
    uno per orientamento."""
    m = _get()
    X = np.stack([embed.decode(v) for v in vectors])
    if X.shape[1] != len(m["mu"]):
        raise ValueError(f"vettori di {X.shape[1]} numeri, il classificatore ne attende {len(m['mu'])}")
    z = ((X - m["mu"]) / m["sd"]) @ m["W"] + m["b"]
    z -= z.max(1, keepdims=True)
    p = np.exp(z)
    p /= p.sum(1, keepdims=True)
    return p.mean(0)


def classify(vectors: list) -> list:
    """I TOP tipi più probabili: [{"type": nome del catalogo, "p": 0..1}]."""
    if not vectors:
        return []
    p = probabilities(vectors)
    if not np.isfinite(p).all():
        return []
    names = _get()["names"]
    return [{"type": str(names[i]), "p": round(float(p[i]), 3)} for i in np.argsort(-p)[:TOP]]


def classify_objects(detections: list, embeddings: dict) -> dict:
    """{indice: classify(...)} per gli aerei con vettori."""
    if not available():
        return {}
    out = {i: classify(v) for i, v in embeddings.items() if detections[i].get("class") in CLASSES and v}
    return {i: g for i, g in out.items() if g}
