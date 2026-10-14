"""Misure delle navi: lunghezza e larghezza dello scafo visto dall'alto.

Il riquadro del rilevamento include margine, ombra e talvolta un pezzo di
banchina, e quello della passata per gli oggetti grandi (detect.py) è
grossolano: per una nave anfibia di ~257 m dava 310 m. Qui lo scafo si
separa dall'acqua:

- la ripresa si porta a pixel quadrati e si ritaglia lungo l'asse del
  riquadro, con un margine;
- l'acqua è il colore dominante della fascia esterna (la metà più scura e
  uniforme dei suoi pixel, in Lab);
- lo scafo sono i pixel lontani da quel colore e non più scuri dell'acqua
  (l'ombra sull'acqua è più scura: resta fuori);
- lunghezza: il tratto continuo lungo l'asse che contiene il centro, esteso
  alle estremità affusolate; larghezza: la larghezza tipica a metà nave
  (75° percentile delle sezioni nel 60% centrale);
- uno scafo più scuro dell'acqua (sottomarini) si cerca, se il primo passo
  non trova nulla, fra i pixel scuri vicino all'asse (qualità "media").

La lunghezza si misura sulla sola fascia centrale lungo l'asse, dove una
banchina o una nave affiancata non arrivano. Una banchina o un'altra nave
affiancata si attacca invece alla larghezza: se lo scafo arriva al bordo
della fascia per buona parte della lunghezza, la larghezza viene dalle sole
sezioni libere o resta ignota (qualità "media"). Ricavarla da un lato solo,
come doppio della distanza dall'asse del riquadro, sbagliava del doppio
dello scarto fra quell'asse e quello dello scafo. Le classi compatibili
sono in webapp/src/ShipCatalog.php.

Prova su riprese Esri di Norfolk, Portsmouth, La Spezia e Taranto
(0,3–0,5 m/pixel): una portaerei classe Ford 340,7 × 80,8 m (337 m, ponte
di volo ~78 m), due Type 45 155,3 × 21,5 e 148,1 × 23,0 m (152,4 × 21,2
m). Le navi ormeggiate affiancate danno larghezze sbagliate (due fregate
fuse: 34 m) e sono scartate quando la larghezza supera di molto quella del
riquadro; una nave chiara su acqua chiara può essere separata solo in parte.
"""
import time

import cv2
import numpy as np

# Navi più corte di così: misura poco utile (barche, rimorchiatori).
MIN_LENGTH_M = 20.0
MAX_MPP = 1.0
# Margine del ritaglio: lungo l'asse (frazione della lunghezza del
# riquadro) e di traverso (frazione della larghezza).
PAD_ALONG = 0.15
PAD_ACROSS = 0.9
# Distanza dal colore dell'acqua (unità Lab normalizzate dalla sua
# variabilità) oltre la quale un pixel è "non acqua".
WATER_SIGMAS = 4.0
MIN_WATER_DIST = 10.0
# Un pixel più scuro dell'acqua di così è ombra, non scafo.
SHADOW_DARKER = 4.0
# Quota della lunghezza su cui la maschera tocca il bordo della fascia
# perché sia una banchina o una nave affiancata.
ATTACHED = 0.3
TIME_BUDGET_S = 30.0


def _rotated_crop(iso: np.ndarray, center, angle_deg: float, length: float, width: float):
    """Ritaglio con l'asse lungo orizzontale; ritorna (crop, M) con M la
    trasformazione da iso al ritaglio."""
    Lw = int(round(length * (1 + 2 * PAD_ALONG))) + 8
    Hw = int(round(width * (1 + 2 * PAD_ACROSS))) + 8
    M = cv2.getRotationMatrix2D((float(center[0]), float(center[1])), angle_deg, 1.0)
    M[0, 2] += Lw / 2 - center[0]
    M[1, 2] += Hw / 2 - center[1]
    crop = cv2.warpAffine(iso, M, (Lw, Hw), flags=cv2.INTER_LINEAR, borderMode=cv2.BORDER_REPLICATE)
    return crop, M


def _water_model(lab: np.ndarray, band: int):
    """Colore dell'acqua dalla fascia esterna (sopra e sotto la nave): la
    metà più scura dei pixel, che esclude banchine e sovrastrutture chiare."""
    ring = np.concatenate([lab[:band].reshape(-1, 3), lab[-band:].reshape(-1, 3)])
    if len(ring) < 50:
        return None
    dark = ring[ring[:, 0] <= np.median(ring[:, 0])]
    mean = dark.mean(0)
    std = np.maximum(dark.std(0), 1.5)
    return mean, std


def _hull_mask(lab, mean, std, s, dark=False):
    """Pixel di scafo: lontani dal colore dell'acqua e non più scuri (l'ombra
    sull'acqua è più scura). dark: il contrario, per gli scafi scuri
    (sottomarini), usato solo se il primo non trova nulla."""
    dist = np.sqrt((((lab - mean) / std) ** 2).sum(2))
    darker = (mean[0] - lab[..., 0]) / std[0]
    if dark:
        mask = (dist > WATER_SIGMAS) & (darker >= SHADOW_DARKER)
    else:
        mask = (dist > WATER_SIGMAS) & (np.abs(lab - mean).max(2) > MIN_WATER_DIST) & (darker < SHADOW_DARKER)
    mask = mask.astype(np.uint8)
    k = max(1, int(round(1.0 / s)))  # ~1 m
    mask = cv2.morphologyEx(mask, cv2.MORPH_OPEN, np.ones((k, k), np.uint8))
    return cv2.morphologyEx(mask, cv2.MORPH_CLOSE, np.ones((2 * k + 1, 2 * k + 1), np.uint8))


def _close_1d(on: np.ndarray, gap: int) -> np.ndarray:
    on = cv2.dilate(on.astype(np.uint8).reshape(1, -1), np.ones((1, gap), np.uint8))
    return cv2.erode(on, np.ones((1, gap), np.uint8)).ravel().astype(bool)


def _extent(mask, y_mid, h, w, s):
    """Estremi (x0, x1) dello scafo lungo l'asse, o None. Nella fascia
    centrale (±0,3 della larghezza del riquadro), dove una banchina o una
    nave affiancata non arrivano: il tratto continuo, con buchi fino a ~3 m,
    di colonne per lo più scafo che contiene il centro; poi esteso verso
    prua e poppa finché c'è ancora scafo nella fascia, anche poco (le
    estremità affusolate occupano una parte piccola della fascia)."""
    Hc, Wc = mask.shape
    c0, c1 = max(0, int(round(y_mid - 0.3 * h))), int(round(y_mid + 0.3 * h)) + 1
    band = mask[c0:c1]
    frac = band.mean(0)
    gap = max(1, int(round(3.0 / s)))
    on = _close_1d(frac >= 0.5, gap)
    xc = Wc // 2
    if not on[xc]:
        lo = max(0, xc - int(0.25 * w))
        near = np.nonzero(on[lo:xc + int(0.25 * w) + 1])[0]
        if not len(near):
            return None
        xc = lo + int(near[np.argmin(np.abs(near + lo - xc))])
    # Estensione in una fascia stretta attorno all'asse dello scafo (il
    # baricentro della parte già trovata), non del riquadro: con il riquadro
    # spostato verso una banchina, la fascia centrale ne prende un bordo, e
    # l'estensione la seguirebbe.
    xa = xb = xc
    while xa > 0 and on[xa - 1]:
        xa -= 1
    while xb < Wc - 1 and on[xb + 1]:
        xb += 1
    prof = band[:, xa:xb + 1].sum(1).astype(float)
    yc = c0 + (float((prof * np.arange(len(prof))).sum() / prof.sum()) if prof.sum() else (len(prof) - 1) / 2)
    n0, n1 = max(0, int(round(yc - 0.15 * h))), int(round(yc + 0.15 * h)) + 1
    # Almeno un quinto della fascia stretta: le estremità vere sì, una cima
    # d'ormeggio o lo spigolo di una banchina no.
    weak = _close_1d(mask[n0:n1].sum(0) >= max(2, 0.2 * (n1 - n0)), gap) | on
    # Al più il 12% della parte già trovata per estremità: sulle riprese
    # vere i riquadri sono larghi 1–1,3 volte lo scafo, e un'estensione più
    # lunga raccoglieva pezzi di banchina oltre la poppa.
    cap = int(0.12 * (xb - xa + 1)) + 1
    x0 = xa
    while x0 > 0 and weak[x0 - 1] and xa - x0 < cap:
        x0 -= 1
    x1 = xb
    while x1 < Wc - 1 and weak[x1 + 1] and x1 - xb < cap:
        x1 += 1
    return (x0, x1) if x1 - x0 + 1 >= 5 else None


def _sections(mask, x0, x1, y0, y1, ym):
    """Bordi superiore e inferiore del tratto di scafo che contiene l'asse,
    nelle sezioni del 60% centrale (solo dove l'asse cade sullo scafo)."""
    L = x1 - x0 + 1
    xs = np.arange(x0 + int(0.2 * L), x1 - int(0.2 * L) + 1)
    sub = mask[y0:y1, xs].astype(bool)
    yy = ym - y0
    if not (0 <= yy < sub.shape[0]) or not len(xs):
        return None, None
    sub = sub[:, sub[yy]]
    if sub.shape[1] < 3:
        return None, None
    above = ~sub[:yy + 1][::-1]
    up = np.where(above.any(0), above.argmax(0), above.shape[0])
    below = ~sub[yy:]
    down = np.where(below.any(0), below.argmax(0), below.shape[0])
    return yy - up + 1 + y0, yy + down - 1 + y0


def measure(iso: np.ndarray, poly_iso: np.ndarray, s: float) -> dict:
    """Misura di una nave. iso: immagine a pixel quadrati (s metri per
    pixel); poly_iso: riquadro in pixel di iso."""
    (cx, cy), (w, h), a = cv2.minAreaRect(poly_iso.astype(np.float32))
    if w < h:
        w, h, a = h, w, a + 90
    if w * s < MIN_LENGTH_M:
        return {"quality": "bassa", "reason": "nave troppo piccola per misurarla"}
    crop, M = _rotated_crop(iso, (cx, cy), a, w, h)
    Hc, Wc = crop.shape[:2]
    lab = cv2.cvtColor(crop, cv2.COLOR_BGR2LAB).astype(np.float32)
    band = max(3, int(h * PAD_ACROSS * 0.5))
    wm = _water_model(lab, band)
    if wm is None:
        return {"quality": "bassa", "reason": "ritaglio troppo piccolo"}
    mean, std = wm
    y_mid = Hc / 2
    ym = int(round(y_mid))
    # Fascia utile di traverso: il riquadro allargato.
    y0 = int(max(0, y_mid - h * (0.5 + PAD_ACROSS * 0.7)))
    y1 = int(min(Hc, y_mid + h * (0.5 + PAD_ACROSS * 0.7)))
    dark = False
    mask = _hull_mask(lab, mean, std, s)
    mask[:y0] = 0
    mask[y1:] = 0
    ext = _extent(mask, y_mid, h, w, s)
    if ext is None:
        # Scafo più scuro dell'acqua (sottomarini): stessi passi sui pixel
        # scuri, ma solo vicino all'asse, dove un'ombra (spostata di lato)
        # non arriva per intero.
        mask = _hull_mask(lab, mean, std, s, dark=True)
        keep = np.zeros_like(mask)
        r0, r1 = max(0, int(y_mid - 0.6 * h)), int(y_mid + 0.6 * h) + 1
        keep[r0:r1] = mask[r0:r1]
        mask = keep
        ext = _extent(mask, y_mid, h, w, s)
        dark = True
        if ext is None:
            return {"quality": "bassa", "reason": "scafo non separabile dall'acqua"}
    x0, x1 = ext
    length_px = x1 - x0 + 1
    tops, bots = _sections(mask, x0, x1, y0, y1, ym)
    if tops is None:
        return {"quality": "bassa", "reason": "larghezza non misurabile"}
    top_att = tops <= y0
    bot_att = bots >= y1 - 1
    attached = bool(max(top_att.mean(), bot_att.mean()) > ATTACHED)
    free = ~top_att & ~bot_att
    beam_px = None
    if not attached:
        beam_px = float(np.percentile(bots - tops + 1, 75))
    elif free.sum() >= 3 and free.mean() >= 0.2:
        # Banchina o nave vicina solo su una parte dello scafo: la larghezza
        # dalle sezioni libere.
        beam_px = float(np.percentile((bots - tops + 1)[free], 75))
    # Altrimenti la larghezza resta ignota: l'asse del riquadro non è
    # quello dello scafo, e misurarla da un lato solo dava errori del doppio
    # dello scarto.
    cut = x0 <= 1 or x1 >= Wc - 2
    length_m = length_px * s
    beam_m = beam_px * s if beam_px is not None else None
    quality, reason = "ok", None
    if cut:
        quality, reason = "bassa", "nave tagliata dal bordo del ritaglio o in fila con altre"
    elif length_m > w * s * 1.15 or length_m < w * s * 0.75:
        quality, reason = "bassa", "lunghezza incoerente con il riquadro (parte dello scafo non separata, ombra o navi vicine)"
    elif beam_m is not None and (beam_m > length_m * 0.35 or beam_m > h * s * 1.35):
        quality, reason = "bassa", "troppo larga per una nave: banchina o navi affiancate"
    elif beam_m is not None and beam_m < length_m * 0.05:
        quality, reason = "bassa", "larghezza implausibile"
    elif dark:
        quality, reason = "media", "scafo più scuro dell'acqua: misura indicativa"
    elif attached:
        quality = "media"
        reason = ("banchina o nave affiancata su un lato: larghezza dalle sole sezioni libere" if beam_m is not None
                  else "banchina o nave affiancata: misurata la sola lunghezza")
    # Sagoma misurata: lo scafo fra prua e poppa nella fascia della sua
    # larghezza (o del riquadro, se la larghezza è ignota).
    half = beam_px / 2 if beam_px is not None else h / 2
    hull = np.zeros_like(mask)
    r0, r1 = int(max(0, y_mid - half)), int(min(Hc, y_mid + half)) + 1
    hull[r0:r1, x0:x1 + 1] = mask[r0:r1, x0:x1 + 1]
    # Contorno in pixel di iso.
    contours, _ = cv2.findContours(hull, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    Minv = cv2.invertAffineTransform(M)
    outline = []
    if contours:
        c = max(contours, key=cv2.contourArea)
        c = cv2.approxPolyDP(c, max(1.0, 0.5 / s), True).reshape(-1, 2).astype(np.float64)
        outline = (c @ Minv[:, :2].T + Minv[:, 2]).tolist()
    return {
        "quality": quality, "reason": reason,
        "length_m": round(length_m, 1), "beam_m": round(beam_m, 1) if beam_m is not None else None,
        "axis_deg": round(float(a), 2), "attached": bool(attached), "outline_iso": outline,
    }


def analyze(image: np.ndarray, detections: list, mpp_x: float, mpp_y: float, budget_s: float = TIME_BUDGET_S) -> dict:
    """Misure dello scafo per i rilevamenti di classe "ship": {indice:
    risultato}. Il contorno (outline) è in pixel dell'immagine."""
    out = {}
    if not mpp_x or not mpp_y or (mpp_x + mpp_y) / 2 > MAX_MPP:
        return out
    ships = [i for i, d in enumerate(detections) if d.get("class") == "ship"]
    if not ships:
        return out
    s = min(mpp_x, mpp_y)
    fx, fy = mpp_x / s, mpp_y / s
    iso = None
    started = time.monotonic()
    for i in ships:
        poly = np.array(detections[i]["polygon"], float) * [fx, fy]
        if max(cv2.minAreaRect(poly.astype(np.float32))[1]) * s < MIN_LENGTH_M:
            continue  # barche: niente misura, niente messaggio
        if time.monotonic() - started > budget_s:
            out[i] = {"quality": "bassa", "reason": "tempo esaurito: troppe navi nella ripresa"}
            continue
        if iso is None:
            iso = image if fx == 1 and fy == 1 else \
                cv2.resize(image, (round(image.shape[1] * fx), round(image.shape[0] * fy)), interpolation=cv2.INTER_AREA)
        try:
            r = measure(iso, poly, s)
        except Exception:  # noqa: BLE001 - una nave non misurabile non deve togliere le altre
            continue
        outline = r.pop("outline_iso", [])
        r["outline"] = [[round(x / fx, 1), round(y / fy, 1)] for x, y in outline]
        out[i] = r
    return out
