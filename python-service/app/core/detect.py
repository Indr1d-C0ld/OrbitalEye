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

Oggetti grandi: l'immagine è divisa in tasselli di 1024 pixel sovrapposti di
384, quindi un oggetto più lungo di 384 pixel (119 m a 0,31 m/pixel, 60 m
con l'ingrandimento) può non stare intero in nessun tassello e arrivare a
pezzi. Con la scala nota, una seconda passata sull'immagine ridotta a 1
m/pixel (pochi tasselli) trova intere le navi fino a ~380 m — una portaerei
è 333 m — e gli aerei grandi; sostituisce i pezzi solo dove la passata
normale non ha l'oggetto intero.
"""
import math
import threading
from pathlib import Path

import cv2
import numpy as np

MODEL_DIR = Path(__file__).resolve().parents[2] / "models"
# Varianti del modello: "s" (veloce, di serie) e "m" (più accurato, circa tre
# volte più lento), installate con tools/fetch_detector_model.sh.
MODELS = {"s": MODEL_DIR / "yolo11s-obb.onnx", "m": MODEL_DIR / "yolo11m-obb.onnx"}
MODEL_PATH = MODELS["s"]
INPUT = 1024
# Sovrapposizione fra tasselli: un oggetto più piccolo di così sta sempre
# per intero in almeno un tassello (un C-17 a 0,15 m/pixel è ~350 pixel).
OVERLAP = 384
NMS_IOU = 0.4
# Un riquadro coperto per oltre questa quota da uno più sicuro (anche di
# un'altra classe) è un suo frammento: oggetto tagliato al bordo di un
# tassello, o lo stesso oggetto classificato in due modi in due tasselli.
CONTAINED = 0.6
# Classi "d'area" (impianti e strutture): un porto contiene navi, un ponte
# veicoli, un piazzale aerei. Non sono frammenti degli oggetti che contengono
# né viceversa: la soppressione dei frammenti vale solo fra classi dello
# stesso gruppo (oggetti con oggetti, aree con aree).
AREA_CLASSES = frozenset({"baseball diamond", "tennis court", "basketball court", "ground track field",
                          "harbor", "bridge", "roundabout", "soccer ball field", "swimming pool"})
# Passata per gli oggetti grandi (vedi sopra): scala, classi cercate, quota
# coperta dal riquadro grande perché un riquadro della passata normale
# sia lo stesso oggetto intero.
LARGE_MPP = 1.0
LARGE_CLASSES = frozenset({"plane", "ship", "storage tank"})
LARGE_SAME = 0.7
# La passata ridotta si fa solo se riduce davvero (almeno a 0,6 della scala
# di lavoro): sotto, gli oggetti in più sarebbero pochi e il lavoro doppio.
LARGE_MAX_RATIO = 0.6
# Confidenza minima di un riquadro della passata ridotta: a 1 m/pixel il
# modello può fondere in un'unica "nave" una fila di barche affiancate.
LARGE_MIN_CONF = 0.35
# Un pezzo tagliato al bordo di un tassello ha un lato a meno di tanti pixel
# da quel bordo.
EDGE_TOL = 8
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

_nets = {}
_lock = threading.Lock()


class DetectorUnavailable(RuntimeError):
    pass


class DetectorBusy(RuntimeError):
    pass


def available(variant: str = "s") -> bool:
    return variant in MODELS and MODELS[variant].is_file()


def _get_net(variant: str = "s"):
    if variant not in MODELS:
        raise DetectorUnavailable(f"Modello di rilevamento sconosciuto: {variant}")
    if variant not in _nets:
        path = MODELS[variant]
        if not path.is_file():
            raise DetectorUnavailable(
                f"Modello di rilevamento non installato (python-service/models/{path.name}): "
                "vedi python-service/tools/fetch_detector_model.sh"
            )
        _nets[variant] = cv2.dnn.readNetFromONNX(str(path))
    return _nets[variant]


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


def _is_area(d: dict) -> bool:
    c = d.get("cls")
    return c is not None and CLASSES[c][0] in AREA_CLASSES


def _covered(d: dict, by: dict) -> float:
    """Quota dell'area di d coperta da by (riquadri ruotati)."""
    area = max(1e-6, d["rect"][1][0] * d["rect"][1][1])
    kind, pts = cv2.rotatedRectangleIntersection(d["rect"], by["rect"])
    if kind == cv2.INTERSECT_NONE or pts is None or len(pts) < 3:
        return 0.0
    return cv2.contourArea(cv2.convexHull(pts)) / area


def _suppress_fragments(dets: list) -> list:
    """Toglie i riquadri contenuti per lo più in uno più sicuro (vedi
    CONTAINED), anche di un'altra classe ma dello stesso gruppo (vedi
    AREA_CLASSES). dets: lista di dict con rect ((cx, cy), (w, h), gradi),
    confidence e cls (indice della classe), ordinata per confidenza."""
    kept = []
    for d in dets:
        if not any(_is_area(d) == _is_area(k) and _covered(d, k) > CONTAINED for k in kept):
            kept.append(d)
    return kept


def _run_tiles(net, work: np.ndarray, conf: float):
    """Inferenza su tutti i tasselli: array (N, 7) in pixel di work, o None."""
    H, W = work.shape[:2]
    found = []
    for oy in _tile_origins(H):
        for ox in _tile_origins(W):
            t = _infer_tile(net, work[oy:oy + INPUT, ox:ox + INPUT], conf)
            if len(t):
                t[:, 0] += ox
                t[:, 1] += oy
                found.append(t)
    return np.concatenate(found) if found else None


def _candidates(allt, conf: float) -> list:
    """Soppressione dei doppioni (sovrapposizione fra riquadri vicini), per classe."""
    out = []
    if allt is None:
        return out
    for c in np.unique(allt[:, 6]).astype(int):
        sub = allt[allt[:, 6] == c]
        boxes = [((float(r[0]), float(r[1])), (float(r[2]), float(r[3])), float(math.degrees(r[4]))) for r in sub]
        idx = cv2.dnn.NMSBoxesRotated(boxes, sub[:, 5].tolist(), conf, NMS_IOU)
        for i in np.array(idx).flatten():
            out.append({"rect": boxes[i], "confidence": float(sub[i, 5]), "row": sub[i], "cls": int(c)})
    return out


def _widened(d: dict, k: float = 1.2) -> dict:
    """Lo stesso riquadro, allargato sul lato corto: assorbe la piccola
    differenza d'angolo fra la passata ridotta e quella normale."""
    (cx, cy), (w, h), a = d["rect"]
    return {"rect": ((cx, cy), (w * k, h) if w < h else (w, h * k), a)}


def _touches_edge(d: dict, edges: tuple) -> bool:
    """Il riquadro arriva a un bordo interno dei tasselli (edges: ascisse e
    ordinate dei bordi, pixel di work): è un pezzo tagliato dal tassello."""
    pts = cv2.boxPoints(d["rect"])
    x0, y0 = pts.min(0)
    x1, y1 = pts.max(0)
    xs, ys = edges
    return any(abs(x0 - e) < EDGE_TOL or abs(x1 - e) < EDGE_TOL for e in xs) or \
        any(abs(y0 - e) < EDGE_TOL or abs(y1 - e) < EDGE_TOL for e in ys)


def _merge_large(normal: list, large: list, edges: tuple = ((), ())) -> tuple:
    """Oggetti grandi: un riquadro della passata ridotta si usa solo se la
    passata normale non ha lo stesso oggetto intero (un suo riquadro della
    stessa classe che copre almeno LARGE_SAME del grande, o che ne sta quasi
    tutto dentro ed è lungo quasi quanto lui). Si tolgono solo i suoi pezzi:
    riquadri della stessa classe contenuti nel grande, larghi almeno metà
    del grande (una barca affiancata è più stretta) e tagliati al bordo di un
    tassello o meno sicuri del grande. Ritorna (candidati, quanti aggiunti)."""
    added = []
    for g in large:
        if g["confidence"] < LARGE_MIN_CONF:
            continue
        gw = _widened(g)
        long_g, short_g = max(g["rect"][1]), min(g["rect"][1])
        same = [d for d in normal if d["cls"] == g["cls"]]
        if any(_covered(g, d) >= LARGE_SAME
               or (_covered(d, gw) >= 0.8 and max(d["rect"][1]) >= 0.8 * long_g) for d in same):
            continue
        added.append(g)

        def piece(d):
            return (d["cls"] == g["cls"] and _covered(d, gw) > CONTAINED
                    and min(d["rect"][1]) >= 0.5 * short_g
                    and (d["confidence"] <= g["confidence"] or _touches_edge(d, edges)))
        normal = [d for d in normal if not piece(d)]
    return normal + added, len(added)


def _large_plan(w0: int, h0: int, W: int, H: int, mpp: float | None, upscale: float):
    """Lati dell'immagine ridotta per la passata degli oggetti grandi, o None
    se non va fatta (vedi detect)."""
    if not mpp or (W <= INPUT and H <= INPUT):
        return None
    down = min(1.0, mpp / LARGE_MPP)
    sw, sh = max(1, round(w0 * down)), max(1, round(h0 * down))
    if down / upscale > LARGE_MAX_RATIO or min(sw, sh) < 32 or _tile_count(W, H) + _tile_count(sw, sh) > MAX_TILES:
        return None
    return sw, sh


def _inner_edges(size: int) -> list:
    """Bordi interni dei tasselli lungo un asse (pixel)."""
    out = set()
    for o in _tile_origins(size):
        if o > 0:
            out.add(o)
        if o + INPUT < size:
            out.add(o + INPUT)
    return sorted(out)


def _tile_origins(size: int) -> list:
    if size <= INPUT:
        return [0]
    step = INPUT - OVERLAP
    origins = list(range(0, size - INPUT, step)) + [size - INPUT]
    return sorted(set(origins))


def detect(image: np.ndarray, mpp: float | None, conf: float = 0.25, variant: str = "s",
           small_objects: bool = False) -> dict:
    """image: BGR (o BGRA: le zone trasparenti diventano grigie). mpp:
    metri/pixel della ripresa, se noto: decide l'ingrandimento per gli
    oggetti piccoli (solo con small_objects) e la passata per gli oggetti
    grandi. variant: modello, "s" (veloce) o "m". Sulle riprese di prova (Brize
    Norton 2021 e oggi, Bergamo, Sigonella 2022 e 2024) il modello "m" ha
    trovato gli stessi aerei con 3 frammenti o doppioni in meno su 52, in un
    tempo tre volte maggiore. Ripetere il rilevamento sull'immagine
    specchiata e trasposta (test-time augmentation) non ha migliorato nulla,
    a un costo da 3 a 4 volte maggiore: non è previsto.
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
    if small_objects and mpp and mpp > TARGET_MPP:
        upscale = min(MAX_UPSCALE, mpp / TARGET_MPP)
        # Ingrandimento ridotto finché il lavoro resta entro MAX_TILES.
        while upscale > 1.0 and _tile_count(int(w0 * upscale), int(h0 * upscale)) > MAX_TILES:
            upscale = max(1.0, upscale - 0.1)
    work = image if upscale == 1.0 else cv2.resize(image, None, fx=upscale, fy=upscale, interpolation=cv2.INTER_CUBIC)
    H, W = work.shape[:2]
    # Passata per gli oggetti grandi: solo se l'immagine è divisa in più
    # tasselli (altrimenti nulla viene spezzato), se riduce davvero rispetto
    # alla scala di lavoro (LARGE_MAX_RATIO) e se il lavoro totale resta
    # entro MAX_TILES. down: riduzione dall'originale (mai un ingrandimento).
    plan = _large_plan(w0, h0, W, H, mpp, upscale)
    large_pass = plan is not None
    sw, sh = plan or (0, 0)

    # La rete OpenCV non va usata da più thread insieme.
    if not _lock.acquire(timeout=LOCK_WAIT):
        raise DetectorBusy("Un altro rilevamento è in corso: riprova fra poco.")
    try:
        net = _get_net(variant)
        allt = _run_tiles(net, work, conf)
        large_t = None
        if large_pass:
            small = cv2.resize(image, (sw, sh), interpolation=cv2.INTER_AREA)
            large_t = _run_tiles(net, small, conf)
    finally:
        _lock.release()

    # Doppioni per classe, poi oggetti grandi, poi frammenti.
    candidates = _candidates(allt, conf)
    added = 0
    if large_t is not None:
        # Dalla passata ridotta ai pixel di work (i due assi differiscono solo
        # per l'arrotondamento dei lati: un fattore unico, perché i riquadri
        # sono ruotati).
        large_t[:, :4] *= (W / sw + H / sh) / 2
        large = [d for d in _candidates(large_t, conf)
                 if CLASSES[d["cls"]][0] in LARGE_CLASSES and max(d["rect"][1]) > OVERLAP * 0.8]
        candidates, added = _merge_large(candidates, large, (_inner_edges(W), _inner_edges(H)))
    tiles = _tile_count(W, H) + (_tile_count(sw, sh) if large_pass else 0)
    if not candidates:
        return {"detections": [], "upscale": round(upscale, 3), "tiles": tiles, "large_objects": 0}
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
        "tiles": tiles,
        # Oggetti presi dalla passata ridotta al posto dei loro pezzi.
        "large_objects": added,
    }
