"""Sagoma dei velivoli rilevati: apertura e lunghezza vere, al posto dei lati
del riquadro del rilevatore.

Il riquadro orientato del rilevatore (vedi detect.py) include margine e
ombra: su Brize Norton i riquadri di A400M, C-17 e A330 erano più grandi del
15-25% e non dicevano quale lato fosse l'apertura, e il confronto con la
tabella dei tipi proponeva B-1 e KC-135 per un A400M. Qui, per ogni aereo:

1. il riquadro viene ritagliato con un margine, portato a pixel quadrati in
   metri (le riprese in gradi hanno scale diverse sui due assi) e ruotato;
2. lo sfondo (mediana in L*a*b* di un anello intorno al riquadro) separa
   ciò che differisce: l'aereo e la sua ombra portata, che si distingue
   perché molto più scura dello sfondo (sotto il 68% della luminosità);
3. si tiene la componente connessa al centro (gli aerei vicini, parcheggiati
   ala contro ala, e i mezzi di servizio restano fuori);
4. l'asse della fusoliera è quello di simmetria della parte chiara (senza
   ombra: l'ombra, da un lato solo, sposterebbe l'asse verso di sé),
   affinato di qualche grado: un aereo visto dall'alto è simmetrico;
5. l'ombra è una copia traslata della sagoma, con lo stesso spostamento per
   tutti gli aerei di una ripresa (un solo sole): lo si stima per ciascun
   aereo come la traslazione che meglio porta la parte chiara sull'ombra
   senza finire sullo sfondo libero, se ne prende la mediana e lo si toglie
   dagli estremi della sagoma completa.
   In larghezza tutto (le estremità alari stanno all'altezza delle ali), in
   lunghezza metà (il muso è più basso delle ali, la deriva più alta).

Su Brize Norton (5 A400M, 3 A330, 1 C-17, a 0,32 × 0,52 m/pixel) l'errore
medio è del 2,5% sull'apertura e del 3,0% sulla lunghezza (massimi 5,0% e
9,9%, il C-17); a Bergamo un Boeing 757 misura 37,3 × 48,9 m (38,1 × 47,3
reali). Dove la sagoma non è attendibile (sfondo misto erba/asfalto, aereo
poco simmetrico o "pieno" come una macchia) lo si dice, e si ripiega sul
riquadro.

Provata e scartata: la freccia alare dalla linea a metà corda. A questa
risoluzione motori, eliche e piani di coda falsano la corda: 30° per
l'A400M (circa 12-15° attesi), 40° per il C-17 (circa 22°), fra 5° e 18°
per quattro aerei dello stesso tipo a Bergamo.
"""
import math
import time

import cv2
import numpy as np

# Ombra: più scura dello sfondo oltre questa frazione della sua luminosità.
SHADOW_DARK = 0.68
# Simmetria minima della parte chiara perché asse e misure siano credibili.
MIN_SYMMETRY = 0.6
# Un aereo occupa al più ~45% del rettangolo apertura × lunghezza: oltre,
# la "sagoma" è una macchia (sfondo non uniforme).
MAX_FILL = 0.5
# Quota dello spostamento dell'ombra tolta dalla lunghezza (vedi sopra).
LENGTH_SHADOW_SHARE = 0.5
# Peso della sovrapposizione con lo sfondo libero nella stima dell'ombra.
SHADOW_BG_PENALTY = 0.3
# Spostamento massimo cercato per l'ombra, in metri.
MAX_SHADOW_M = 15.0
# Oltre questa risoluzione la sagoma è troppo grossolana per misurare.
MAX_MPP = 1.0
# Riquadri più lunghi di così non sono aerei (l'An-225 era 88 m): niente sagoma.
MAX_BOX_M = 110.0
# Tempo massimo per tutte le sagome di un rilevamento: oltre, gli aerei
# restanti usano il riquadro (il rilevamento non deve scadere per questo).
TIME_BUDGET_S = 60.0
# Ricerca dell'asse: angoli rispetto al riquadro, in gradi.
_ANGLES = np.arange(-8, 8.01, 1.0)


def _lab(img: np.ndarray) -> np.ndarray:
    return cv2.cvtColor(img.astype(np.float32) / 255.0, cv2.COLOR_BGR2LAB)


class _Crop:
    """Ritaglio di un rilevamento: pixel quadrati in metri, riquadro
    allineato agli assi e centrato. Conserva le trasformazioni per riportare
    i punti nell'immagine originale."""

    def __init__(self, image: np.ndarray, polygon: list, mpp_x: float, mpp_y: float, margin: float = 0.45):
        self.s = min(mpp_x, mpp_y)
        self.fx, self.fy = mpp_x / self.s, mpp_y / self.s
        poly = np.array(polygon, np.float32)
        x0, y0 = poly.min(0)
        x1, y1 = poly.max(0)
        m = margin * max(x1 - x0, y1 - y0)
        self.X0, self.Y0 = int(max(0, x0 - m)), int(max(0, y0 - m))
        X1, Y1 = int(min(image.shape[1], x1 + m)), int(min(image.shape[0], y1 + m))
        crop = image[self.Y0:Y1, self.X0:X1]
        size = (max(1, round(crop.shape[1] * self.fx)), max(1, round(crop.shape[0] * self.fy)))
        crop = cv2.resize(crop, size, interpolation=cv2.INTER_CUBIC)
        p = ((poly - [self.X0, self.Y0]) * [self.fx, self.fy]).astype(np.float32)
        (cx, cy), (w, h), self.box_angle = cv2.minAreaRect(p)
        self.wh = (w, h)
        side = max(8, int(math.hypot(w, h) * (1 + 2 * margin)))
        M = cv2.getRotationMatrix2D((cx, cy), self.box_angle, 1.0)
        M[0, 2] += side / 2 - cx
        M[1, 2] += side / 2 - cy
        self.M = M
        self.center = (side / 2, side / 2)
        self.img = cv2.warpAffine(crop, M, (side, side), flags=cv2.INTER_LINEAR, borderMode=cv2.BORDER_CONSTANT)
        valid = np.full(crop.shape[:2], 255, np.uint8)
        self.valid = cv2.warpAffine(valid, M, (side, side), flags=cv2.INTER_NEAREST, borderMode=cv2.BORDER_CONSTANT) > 0

    def to_image(self, pts: np.ndarray) -> np.ndarray:
        """Punti (x, y) del ritaglio → pixel dell'immagine originale."""
        inv = cv2.invertAffineTransform(self.M)
        p = np.hstack([pts, np.ones((len(pts), 1))]) @ inv.T
        return p / [self.fx, self.fy] + [self.X0, self.Y0]

    def vector_to_image(self, v: np.ndarray) -> np.ndarray:
        """Vettore (metri) dal sistema del ritaglio a quello dell'immagine
        (a pixel quadrati: assi dell'immagine, scala in metri)."""
        # Il ritaglio è ruotato con R = [[cos, sin], [-sin, cos]]: si torna
        # indietro con la trasposta.
        a = math.radians(self.box_angle)
        return np.array([v[0] * math.cos(a) - v[1] * math.sin(a), v[0] * math.sin(a) + v[1] * math.cos(a)])

    def vector_from_image(self, v: np.ndarray) -> np.ndarray:
        a = math.radians(self.box_angle)
        return np.array([v[0] * math.cos(a) + v[1] * math.sin(a), -v[0] * math.sin(a) + v[1] * math.cos(a)])


def _masks(c: _Crop):
    lab = _lab(c.img)
    H, W = c.img.shape[:2]
    cx, cy = c.center
    w, h = c.wh
    yy, xx = np.mgrid[0:H, 0:W]
    dx, dy = np.abs(xx - cx), np.abs(yy - cy)
    inbox = (dx <= w / 2 * 1.1) & (dy <= h / 2 * 1.1) & c.valid
    ring = (dx <= w / 2 * 1.5) & (dy <= h / 2 * 1.5) & ~((dx <= w / 2 * 1.15) & (dy <= h / 2 * 1.15)) & c.valid
    if ring.sum() < 50:
        return None  # riquadro minuscolo o quasi tutto fuori dall'immagine
    bg = np.median(lab[ring], axis=0)
    d = np.linalg.norm(lab - bg, axis=2)
    dr = d[ring]
    med = float(np.median(dr))
    thr = max(7.0, med + 3.0 * 1.4826 * float(np.median(np.abs(dr - med))))
    shadow = (lab[..., 0] < SHADOW_DARK * bg[0]) & inbox
    differ = (d > thr) & inbox
    return differ & ~shadow, differ, shadow


def _clean(mask: np.ndarray, min_area: int) -> np.ndarray:
    m = mask.astype(np.uint8) * 255
    m = cv2.morphologyEx(m, cv2.MORPH_OPEN, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (3, 3)))
    m = cv2.morphologyEx(m, cv2.MORPH_CLOSE, cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (5, 5)))
    n, lab, st, _ = cv2.connectedComponentsWithStats(m, 8)
    keep = np.zeros(n, bool)
    keep[1:] = st[1:, cv2.CC_STAT_AREA] >= min_area
    out = keep[lab]
    # Buchi interni (finestrini, marcature scure) riempiti: le zone vuote
    # che non toccano il bordo del ritaglio.
    inv = (~out).astype(np.uint8)
    nn, ll, ss, _ = cv2.connectedComponentsWithStats(inv, 4)
    H, W = out.shape
    x, y, ww, hh = ss[:, 0], ss[:, 1], ss[:, 2], ss[:, 3]
    interior = (x > 0) & (y > 0) & (x + ww < W) & (y + hh < H)
    interior[0] = False
    return out | interior[ll]


def _main_component(m: np.ndarray, c: _Crop) -> np.ndarray:
    n, lab, _st, _ = cv2.connectedComponentsWithStats(m.astype(np.uint8), 8)
    if n <= 1:
        return m
    cx, cy = c.center
    w, h = c.wh
    yy, xx = np.mgrid[0:m.shape[0], 0:m.shape[1]]
    core = (np.abs(xx - cx) <= w * 0.25) & (np.abs(yy - cy) <= h * 0.25)
    counts = np.bincount(lab[core].ravel(), minlength=n)
    counts[0] = 0
    k = int(counts.argmax())
    return lab == k if counts[k] else np.zeros_like(m)


def _rotate(m: np.ndarray, angle: float, center) -> np.ndarray:
    M = cv2.getRotationMatrix2D(center, angle, 1.0)
    return cv2.warpAffine(m.astype(np.uint8), M, (m.shape[1], m.shape[0]), flags=cv2.INTER_NEAREST) > 0


def _symmetry_at(m: np.ndarray, angle: float, center):
    """Miglior simmetria per un angolo: (punteggio, trasposta, riga dell'asse)."""
    r = _rotate(m, angle, center)
    best = (-1.0, False, 0.0)
    for t in (False, True):
        mm = np.ascontiguousarray(r.T) if t else r
        ys, xs = np.nonzero(mm)
        if len(ys) < 20:
            continue
        offsets = ys.mean() + np.arange(-4, 4.01, 0.5)
        ry = np.round(2 * offsets[:, None] - ys[None, :]).astype(int)
        ok = (ry >= 0) & (ry < mm.shape[0])
        hits = np.where(ok, mm[np.clip(ry, 0, mm.shape[0] - 1), xs[None, :]], False).sum(1) / len(ys)
        k = int(hits.argmax())
        if hits[k] > best[0]:
            best = (float(hits[k]), t, float(offsets[k]))
    return best


def _symmetry(m: np.ndarray, center):
    """(punteggio, angolo, trasposta, riga dell'asse): la fusoliera, dopo
    la rotazione e l'eventuale trasposizione, è orizzontale alla riga.
    Angolo cercato a passi di 2° e poi affinato di 1°.

    Si lavora su un quadrato centrato sul centro del ritaglio e appena più
    grande della maschera (non sull'intero ritaglio, che per un riquadro
    grande ha milioni di pixel): rotazioni e trasposizioni lo lasciano
    centrato, e la riga dell'asse si riporta al ritaglio con lo scarto."""
    ys, xs = np.nonzero(m)
    cx, cy = int(round(center[0])), int(round(center[1]))
    half = int(max(np.abs(xs - cx).max(), np.abs(ys - cy).max()) * 1.05) + 3
    sq = np.zeros((2 * half, 2 * half), bool)
    y0, x0 = cy - half, cx - half
    sy0, sx0 = max(0, y0), max(0, x0)
    sy1, sx1 = min(m.shape[0], cy + half), min(m.shape[1], cx + half)
    sq[sy0 - y0:sy1 - y0, sx0 - x0:sx1 - x0] = m[sy0:sy1, sx0:sx1]
    local = (float(half), float(half))
    coarse = max(((_symmetry_at(sq, a, local), a) for a in _ANGLES[::2]), key=lambda x: x[0][0])
    best_s, best_a = coarse
    for a in (best_a - 1, best_a + 1):
        cand = _symmetry_at(sq, a, local)
        if cand[0] > best_s[0]:
            best_s, best_a = cand, a
    score, t, axc = best_s
    # Con la trasposizione le righe sono le colonne del ritaglio.
    return score, float(best_a), t, axc + (x0 if t else y0)


def _shadow_shift(bright: np.ndarray, shadow: np.ndarray, max_px: int, background: np.ndarray | None = None):
    """Traslazione (dx, dy) in pixel che porta la parte chiara sull'ombra,
    con la correlazione via FFT: copre più ombra visibile possibile senza
    finire sullo sfondo libero. (Nella posizione giusta l'ombra traslata cade
    in parte sotto l'aereo stesso: penalizzare quella sovrapposizione,
    invece dello sfondo, spingeva la stima verso spostamenti più grandi.)"""
    H, W = bright.shape
    fh, fw = cv2.getOptimalDFTSize(H + max_px), cv2.getOptimalDFTSize(W + max_px)

    def corr(a, b):
        A = np.fft.rfft2(a.astype(np.float32), (fh, fw))
        B = np.fft.rfft2(b.astype(np.float32), (fh, fw))
        return np.fft.irfft2(B * np.conj(A), (fh, fw))

    if background is None:
        background = ~(bright | shadow)
    score = corr(bright, shadow & ~bright) - SHADOW_BG_PENALTY * corr(bright, background)
    lags = np.arange(-max_px, max_px + 1)
    window = score[np.ix_(lags % fh, lags % fw)]
    iy, ix = np.unravel_index(int(window.argmax()), window.shape)
    return (int(lags[ix]), int(lags[iy])) if window[iy, ix] > 0 else None


def _image_shadow(iso: np.ndarray, center_px, radius: int):
    """Spostamento dell'ombra (pixel, sistema dell'immagine a pixel quadrati)
    per un aereo: maschere calcolate su un ritaglio non ruotato, così il
    vettore non passa per nessuna rotazione."""
    cx, cy = center_px
    H, W = iso.shape[:2]
    x0, y0 = max(0, int(cx - radius)), max(0, int(cy - radius))
    x1, y1 = min(W, int(cx + radius)), min(H, int(cy + radius))
    sub = _lab(iso[y0:y1, x0:x1])
    if sub.shape[0] < 20 or sub.shape[1] < 20:
        return None
    k = int(min(sub.shape[:2]) * 0.2)
    ring = np.ones(sub.shape[:2], bool)
    ring[k:-k, k:-k] = False
    bg = np.median(sub[ring], axis=0)
    d = np.linalg.norm(sub - bg, axis=2)
    shadow = sub[..., 0] < SHADOW_DARK * bg[0]
    bright = (d > 8) & ~shadow
    if bright.sum() < 30 or shadow.sum() < 30:
        return None
    return _shadow_shift(bright, shadow & ~bright, radius // 2)


def analyze(image: np.ndarray, detections: list, mpp_x: float, mpp_y: float, budget_s: float = TIME_BUDGET_S) -> dict:
    """Misure dalla sagoma per i rilevamenti di classe "plane".

    image: BGR; detections: come da detect.detect (pixel dell'immagine).
    Ritorna {"objects": {indice: risultato}, "shadow_m": [dx, dy] | None}.
    Il risultato ha span_m, length_m, quality ("ok" o "bassa"), reason,
    symmetry, fill e outline (contorno della sagoma, pixel dell'immagine).
    budget_s: tempo massimo (di serie TIME_BUDGET_S; il chiamante lo riduce
    se il rilevamento ha già consumato parte del suo tempo).
    """
    out = {"objects": {}, "shadow_m": None}
    if not mpp_x or not mpp_y or (mpp_x + mpp_y) / 2 > MAX_MPP:
        return out
    planes = [i for i, d in enumerate(detections) if d.get("class") == "plane"]
    if not planes:
        return out
    s = min(mpp_x, mpp_y)
    fx, fy = mpp_x / s, mpp_y / s
    iso = cv2.resize(image, (round(image.shape[1] * fx), round(image.shape[0] * fy)), interpolation=cv2.INTER_AREA)
    started = time.monotonic()
    H0, W0 = image.shape[:2]

    def box_len_m(d):
        p = (np.array(d["polygon"], float) * [mpp_x, mpp_y]).astype(np.float32)
        return float(max(cv2.minAreaRect(p)[1]))

    usable = []
    for i in planes:
        if box_len_m(detections[i]) > MAX_BOX_M:
            out["objects"][i] = {"quality": "bassa", "reason": "riquadro troppo grande per un aereo"}
        else:
            usable.append(i)
    planes = usable
    if budget_s <= 0:
        # Il rilevamento ha già consumato il tempo della richiesta.
        for i in planes:
            out["objects"][i] = {"quality": "bassa", "reason": "tempo esaurito: il rilevamento ha usato tutto il tempo disponibile"}
        return out

    # Un solo sole per ripresa: spostamento dell'ombra stimato su ciascun
    # aereo, poi la mediana (metri, assi dell'immagine).
    vecs = []
    for i in planes:
        if time.monotonic() - started > budget_s / 2:
            break  # bastano gli aerei già visti; il resto del tempo alle sagome
        d = detections[i]
        poly = np.array(d["polygon"], float) * [fx, fy]
        radius = int(np.ptp(poly, axis=0).max() * 0.8)
        sh = _image_shadow(iso, poly.mean(0), max(20, radius))
        if sh is not None and max(abs(sh[0]), abs(sh[1])) * s <= MAX_SHADOW_M:
            vecs.append(np.array(sh, float) * s)
    shadow_m = np.median(np.array(vecs), axis=0) if vecs else np.zeros(2)
    if vecs:
        out["shadow_m"] = [round(float(shadow_m[0]), 2), round(float(shadow_m[1]), 2)]

    for i in planes:
        if time.monotonic() - started > budget_s:
            out["objects"][i] = {"quality": "bassa", "reason": "tempo esaurito: troppi aerei nella ripresa"}
            continue
        try:
            c = _Crop(image, detections[i]["polygon"], mpp_x, mpp_y)
        except cv2.error:
            continue
        ms = _masks(c)
        if ms is None:
            out["objects"][i] = {"quality": "bassa", "reason": "riquadro troppo piccolo o quasi tutto fuori dall'immagine"}
            continue
        bright, differ, _shadow = ms
        min_area = max(4, int(2.0 / (c.s * c.s)))
        mb = _main_component(_clean(bright, min_area), c)
        md = _main_component(_clean(differ, min_area), c)
        if mb.sum() < 30 or md.sum() < 30:
            out["objects"][i] = {"quality": "bassa", "reason": "sagoma non separabile dallo sfondo"}
            continue
        score, ang, transposed, axc = _symmetry(mb, c.center)
        r = _rotate(md, ang, c.center)
        r = r.T if transposed else r
        ys, xs = np.nonzero(r)
        fill = r.sum() / max(1, (np.ptp(xs) + 1) * (np.ptp(ys) + 1))
        # Spostamento dell'ombra nel sistema allineato alla fusoliera (x).
        v = c.vector_from_image(shadow_m) / c.s
        b = math.radians(ang)
        vx = v[0] * math.cos(b) + v[1] * math.sin(b)
        vy = -v[0] * math.sin(b) + v[1] * math.cos(b)
        if transposed:
            vx, vy = vy, vx
        length = (np.ptp(xs) + 1 - LENGTH_SHADOW_SHARE * abs(vx)) * c.s
        span = (np.ptp(ys) + 1 - abs(vy)) * c.s
        # Aereo tagliato dal bordo dell'immagine: la parte che manca falsa
        # le misure anche se il resto è perfettamente simmetrico.
        cut = bool((cv2.dilate(md.astype(np.uint8), np.ones((5, 5), np.uint8)) > 0)[~c.valid].any())
        poly = np.array(detections[i]["polygon"], float)
        cut = cut or poly[:, 0].min() <= 1 or poly[:, 1].min() <= 1 or poly[:, 0].max() >= W0 - 2 or poly[:, 1].max() >= H0 - 2
        reason = None
        if cut:
            reason = "aereo tagliato dal bordo dell'immagine"
        elif score < MIN_SYMMETRY:
            reason = "sagoma poco simmetrica (aereo parzialmente coperto o sfondo irregolare)"
        elif fill > MAX_FILL:
            reason = "sagoma confusa con lo sfondo"
        elif span < 3 or length < 3:
            reason = "sagoma troppo piccola"
        # Contorno nel sistema dell'immagine, per mostrarlo all'analista:
        # la parte simmetrica rispetto all'asse (l'ombra, da un lato solo,
        # resta fuori quasi tutta).
        ys2, xs2 = np.nonzero(r)
        mirror = cv2.dilate(r.astype(np.uint8), np.ones((3, 3), np.uint8)) > 0
        ry = np.round(2 * axc - ys2).astype(int)
        ok = (ry >= 0) & (ry < r.shape[0])
        keep = np.zeros(len(ys2), bool)
        keep[ok] = mirror[ry[ok], xs2[ok]]
        sym = np.zeros_like(r)
        sym[ys2[keep], xs2[keep]] = True
        back = sym.T if transposed else sym
        back = _rotate(back, -ang, c.center)
        contours, _ = cv2.findContours(back.astype(np.uint8), cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        outline = []
        if contours:
            cnt = cv2.approxPolyDP(max(contours, key=cv2.contourArea), 1.0, True).reshape(-1, 2).astype(float)
            outline = [[round(float(x), 1), round(float(y), 1)] for x, y in c.to_image(cnt)]
        # Direzione della fusoliera nel sistema dell'immagine a pixel
        # quadrati (gradi, 0-180): serve a ritagliare l'aereo sempre
        # orientato allo stesso modo (vedi embed.py).
        dr = (0.0, 1.0) if transposed else (1.0, 0.0)
        cb, sb = math.cos(b), math.sin(b)
        d_crop = np.array([cb * dr[0] - sb * dr[1], sb * dr[0] + cb * dr[1]])
        d_img = c.vector_to_image(d_crop)
        axis_deg = math.degrees(math.atan2(d_img[1], d_img[0])) % 180
        out["objects"][i] = {
            "quality": "bassa" if reason else "ok",
            "reason": reason,
            "axis_deg": round(axis_deg, 1),
            "span_m": round(float(span), 1),
            "length_m": round(float(length), 1),
            "symmetry": round(score, 2),
            "fill": round(float(fill), 2),
            "outline": outline,
        }
    return out
