"""Allineamento geometrico tra due riprese della stessa area.

Le immagini satellitari scaricate in momenti diversi raramente coincidono
pixel per pixel (leggero disallineamento del crop, angolo di ripresa,
risoluzione). Prima di calcolare qualunque differenza bisogna riallineare
la seconda immagine (B) sulla prima (A), altrimenti il "rumore da
disallineamento" genera falsi positivi massicci lungo tutti i bordi.

Strategia a due stadi:
  1. Allineamento grossolano via feature matching (ORB + RANSAC/homografia).
     Robusto a rotazioni, scala e traslazioni moderate.
  2. Rifinitura via ECC (Enhanced Correlation Coefficient) sub-pixel, che
     converge bene quando le immagini sono già quasi sovrapposte.

Se il feature matching fallisce (poche feature, area troppo uniforme come
mare/deserto, oppure fonti visivamente molto diverse tra loro come Esri
World Imagery vs Sentinel Hub), si degrada a un allineamento via ECC diretto
con moto affine (rotazione + scala + shear, non solo traslazione/rotazione)
o, in ultima istanza, nessun allineamento (identità) con un flag di warning.

Per rendere il matching più robusto tra fonti con bilanciamento colore e
contrasto molto diversi, sia ORB che ECC lavorano su versioni delle immagini
normalizzate con CLAHE (vedi `_clahe_normalize`) invece che sui grigi grezzi.
"""
from dataclasses import dataclass, field
from typing import Callable

import cv2
import numpy as np

from .utils import to_gray


@dataclass
class RegistrationResult:
    aligned: np.ndarray
    method: str
    success: bool
    valid_mask: np.ndarray  # 255 dove img_b_aligned copre dati reali, 0 nei bordi extrapolati dal warp
    warp_matrix: list = field(default_factory=list)
    matched_features: int = 0
    confidence: float = 0.0
    # Applica a un'immagine a un canale (es. la maschera dei dati validi di
    # B) la STESSA trasformazione geometrica usata per allineare B su A, così
    # le zone senza dati di B finiscono esattamente dove finisce B.
    warp_like: Callable[[np.ndarray], np.ndarray] | None = None


def _erode_valid_mask(mask: np.ndarray, margin: int = 4) -> np.ndarray:
    """Restringe la maschera di validità di qualche pixel: i pixel appena
    dentro il bordo warpato sono spesso interpolati/sfocati e non affidabili
    per il change detection, e senza questa erosione generano una cornice di
    falsi positivi lungo tutto il perimetro (il rumore da disallineamento
    residuo più insidioso da individuare)."""
    if margin <= 0:
        return mask
    kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (margin * 2 + 1, margin * 2 + 1))
    return cv2.erode(mask, kernel, iterations=1)


def _clahe_normalize(gray: np.ndarray) -> np.ndarray:
    """Normalizza il contrasto locale prima del feature matching / ECC.

    Fonti diverse (es. Esri World Imagery vs Sentinel Hub) hanno bilanciamento
    colore, contrasto e curve di tono molto differenti anche sulla stessa
    identica area: senza questa normalizzazione ORB trova pochissime feature
    in comune tra le due fonti, l'omografia fallisce (o resta con pochi
    inlier) e si degrada al fallback ECC diretto che, con un moto solo
    euclideo, non recupera differenze di scala/inclinazione tra le due
    riprese — il risultato è l'allineamento "storto" percepito con coppie
    cross-fonte."""
    clahe = cv2.createCLAHE(clipLimit=2.5, tileGridSize=(8, 8))
    return clahe.apply(gray)


def _orb_homography(gray_a: np.ndarray, gray_b: np.ndarray, max_features: int = 4000):
    norm_a = _clahe_normalize(gray_a)
    norm_b = _clahe_normalize(gray_b)
    orb = cv2.ORB_create(nfeatures=max_features)
    kp_a, des_a = orb.detectAndCompute(norm_a, None)
    kp_b, des_b = orb.detectAndCompute(norm_b, None)

    if des_a is None or des_b is None or len(kp_a) < 8 or len(kp_b) < 8:
        return None, 0

    matcher = cv2.BFMatcher(cv2.NORM_HAMMING, crossCheck=False)
    matches = matcher.knnMatch(des_a, des_b, k=2)

    good = []
    for m_n in matches:
        if len(m_n) != 2:
            continue
        m, n = m_n
        if m.distance < 0.75 * n.distance:
            good.append(m)

    if len(good) < 10:
        return None, len(good)

    pts_a = np.float32([kp_a[m.queryIdx].pt for m in good]).reshape(-1, 1, 2)
    pts_b = np.float32([kp_b[m.trainIdx].pt for m in good]).reshape(-1, 1, 2)

    homography, mask = cv2.findHomography(pts_b, pts_a, cv2.RANSAC, 5.0)
    inliers = int(mask.sum()) if mask is not None else 0
    return homography, inliers


def _ecc_refine(gray_a: np.ndarray, gray_b_warped: np.ndarray, warp_mode=cv2.MOTION_EUCLIDEAN,
                mask: np.ndarray | None = None):
    """Rifinisce l'allineamento massimizzando la correlazione (ECC) tra le due
    immagini, lavorando su versioni CLAHE-normalizzate: l'ECC confronta
    direttamente le intensità dei pixel, quindi differenze di bilanciamento
    colore/contrasto tra fonti diverse fanno fallire la convergenza molto più
    spesso che con il matching a feature locali (ORB). Con mask, la
    correlazione si calcola solo dove B ha dati reali (i bordi neri del warp
    la falserebbero)."""
    warp_matrix = np.eye(2, 3, dtype=np.float32)
    criteria = (cv2.TERM_CRITERIA_EPS | cv2.TERM_CRITERIA_COUNT, 200, 1e-6)
    try:
        cc, warp_matrix = cv2.findTransformECC(
            _clahe_normalize(gray_a), _clahe_normalize(gray_b_warped), warp_matrix, warp_mode, criteria, mask, 5
        )
        return warp_matrix, True, float(cc)
    except cv2.error:
        return warp_matrix, False, 0.0


# Correlazione ECC minima perché l'allineamento di ripiego sia considerato
# riuscito. ECC "converge" quasi sempre, anche fra immagini senza alcuna
# relazione: su due texture scorrelate trovava una deformazione del 10% con
# correlazione 0.17 e la dichiarava riuscita, per poi calcolare il
# cambiamento su un allineamento privo di senso. Coppie davvero sovrapponibili
# stanno ben oltre 0.9.
ECC_MIN_CORRELATION = 0.5

# Quota minima dell'immagine che deve restare coperta da dati reali dopo il
# warp: sotto questa soglia la trasformazione è degenerata (punti allineati,
# omografia che collassa l'immagine) e il confronto non avrebbe senso.
MIN_VALID_COVERAGE = 0.2


def _fit(ch: np.ndarray, w: int, h: int) -> np.ndarray:
    return cv2.resize(ch, (w, h), interpolation=cv2.INTER_NEAREST) if ch.shape[:2] != (h, w) else ch


def _coverage(valid_mask: np.ndarray) -> float:
    return float(np.count_nonzero(valid_mask)) / float(valid_mask.size or 1)


def register_images(img_a: np.ndarray, img_b: np.ndarray) -> RegistrationResult:
    """Allinea img_b su img_a. Ritorna img_b riallineata alla shape di img_a
    insieme a una maschera di validità che marca i bordi privi di dati reali
    (introdotti dal warp) da escludere dal change detection.
    """
    h, w = img_a.shape[:2]
    gray_a = to_gray(img_a)

    if img_b.shape[:2] != img_a.shape[:2]:
        img_b_resized = cv2.resize(img_b, (w, h), interpolation=cv2.INTER_AREA)
    else:
        img_b_resized = img_b
    gray_b = to_gray(img_b_resized)

    full_mask = np.full((h, w), 255, dtype=np.uint8)

    homography, inliers = _orb_homography(gray_a, gray_b)

    if homography is not None and inliers >= 10:
        aligned = cv2.warpPerspective(
            img_b_resized, homography, (w, h), flags=cv2.INTER_LINEAR,
            borderMode=cv2.BORDER_CONSTANT, borderValue=0,
        )
        valid_mask = cv2.warpPerspective(
            full_mask, homography, (w, h), flags=cv2.INTER_NEAREST,
            borderMode=cv2.BORDER_CONSTANT, borderValue=0,
        )
        gray_aligned = to_gray(aligned)
        warp2x3, ecc_ok, _cc = _ecc_refine(gray_a, gray_aligned)
        if ecc_ok:
            aligned = cv2.warpAffine(
                aligned, warp2x3, (w, h), flags=cv2.INTER_LINEAR | cv2.WARP_INVERSE_MAP,
                borderMode=cv2.BORDER_CONSTANT, borderValue=0,
            )
            valid_mask = cv2.warpAffine(
                valid_mask, warp2x3, (w, h), flags=cv2.INTER_NEAREST | cv2.WARP_INVERSE_MAP,
                borderMode=cv2.BORDER_CONSTANT, borderValue=0,
            )
        confidence = min(1.0, inliers / 200.0)
        if _coverage(valid_mask) < MIN_VALID_COVERAGE:
            # Omografia degenerata nonostante gli inlier: meglio ammettere
            # di non aver allineato che confrontare un'immagine collassata.
            return RegistrationResult(
                warp_like=lambda ch: _fit(ch, w, h),
                aligned=img_b_resized, method="none", success=False,
                valid_mask=full_mask, matched_features=inliers, confidence=0.0,
            )
        def warp_orb(ch, _h=homography, _m=warp2x3, _ecc=ecc_ok):
            out = cv2.warpPerspective(_fit(ch, w, h), _h, (w, h), flags=cv2.INTER_NEAREST,
                                      borderMode=cv2.BORDER_CONSTANT, borderValue=0)
            if _ecc:
                out = cv2.warpAffine(out, _m, (w, h), flags=cv2.INTER_NEAREST | cv2.WARP_INVERSE_MAP,
                                     borderMode=cv2.BORDER_CONSTANT, borderValue=0)
            return out

        return RegistrationResult(
            warp_like=warp_orb,
            aligned=aligned,
            method="orb+ecc" if ecc_ok else "orb",
            success=True,
            valid_mask=_erode_valid_mask(valid_mask),
            warp_matrix=homography.tolist(),
            matched_features=inliers,
            confidence=confidence,
        )

    # Fallback: nessuna omografia ORB affidabile (tipico quando le due fonti
    # hanno stile visivo molto diverso, es. Esri World Imagery vs Sentinel
    # Hub, e il feature matching non trova abbastanza corrispondenze). Si usa
    # un moto affine (rotazione + scala + shear + traslazione, 6 gradi di
    # libertà) invece che euclideo (solo rotazione + traslazione): l'euclideo
    # da solo non può correggere differenze di scala/inclinazione tra
    # riprese della stessa area provenienti da fonti diverse, ed è la causa
    # più probabile dell'allineamento "storto" osservato in questi casi.
    warp2x3, ecc_ok, cc = _ecc_refine(gray_a, gray_b, warp_mode=cv2.MOTION_AFFINE)
    if ecc_ok and cc >= ECC_MIN_CORRELATION:
        aligned = cv2.warpAffine(
            img_b_resized, warp2x3, (w, h), flags=cv2.INTER_LINEAR | cv2.WARP_INVERSE_MAP,
            borderMode=cv2.BORDER_CONSTANT, borderValue=0,
        )
        valid_mask = cv2.warpAffine(
            full_mask, warp2x3, (w, h), flags=cv2.INTER_NEAREST | cv2.WARP_INVERSE_MAP,
            borderMode=cv2.BORDER_CONSTANT, borderValue=0,
        )
        if _coverage(valid_mask) >= MIN_VALID_COVERAGE:
            return RegistrationResult(
                warp_like=lambda ch, _m=warp2x3: cv2.warpAffine(
                    _fit(ch, w, h), _m, (w, h), flags=cv2.INTER_NEAREST | cv2.WARP_INVERSE_MAP,
                    borderMode=cv2.BORDER_CONSTANT, borderValue=0),
                aligned=aligned,
                method="ecc-affine",
                success=True,
                valid_mask=_erode_valid_mask(valid_mask),
                warp_matrix=warp2x3.tolist(),
                matched_features=inliers,
                # La correlazione raggiunta è una misura reale della bontà
                # dell'allineamento (prima era un 0.4 fisso).
                confidence=round(max(0.0, min(1.0, cc)), 3),
            )

    return RegistrationResult(
        warp_like=lambda ch: _fit(ch, w, h),
        aligned=img_b_resized,
        method="none",
        success=False,
        valid_mask=full_mask,
        matched_features=inliers,
        confidence=0.0,
    )


def register_with_points(
    img_a: np.ndarray, img_b: np.ndarray, points: list[tuple[float, float, float, float]]
) -> RegistrationResult:
    """Allinea img_b su img_a usando punti di controllo indicati manualmente
    dall'analista, invece del feature matching automatico.

    Fallback assistito per i casi in cui il motore automatico (ORB+ECC, vedi
    `register_images`) non trova corrispondenze affidabili — tipicamente tra
    fonti con stile visivo molto diverso (es. Esri World Imagery vs Sentinel
    Hub) o in aree con poca texture. Qui è l'analista a indicare direttamente
    coppie di punti che rappresentano lo stesso luogo reale nelle due
    immagini: niente ECC di rifinitura automatica dopo, perché reintrodurrebbe
    lo stesso rischio di convergenza sbagliata che ha reso necessario
    l'intervento manuale in primo luogo — i punti indicati sono presi come
    riferimento definitivo.

    points: lista di (ax, ay, bx, by) in pixel dell'immagine ORIGINALE (piena
    risoluzione, non ridimensionata) di A e B rispettivamente. Servono almeno
    3 punti: con esattamente 3 si stima un moto affine (rotazione + scala +
    shear), con 4 o più un'omografia (proiettiva, con RANSAC se >4 per
    tollerare qualche punto impreciso).
    """
    if len(points) < 3:
        raise ValueError("Servono almeno 3 punti di controllo per l'allineamento manuale.")

    h, w = img_a.shape[:2]

    if img_b.shape[:2] != (h, w):
        scale_x = w / img_b.shape[1]
        scale_y = h / img_b.shape[0]
        img_b_resized = cv2.resize(img_b, (w, h), interpolation=cv2.INTER_AREA)
    else:
        scale_x = scale_y = 1.0
        img_b_resized = img_b

    pts_a = np.float32([[p[0], p[1]] for p in points])
    # I punti su B sono stati presi sull'immagine originale: se B è stata
    # ridimensionata per combaciare con A, le coordinate vanno riscalate di
    # conseguenza prima di stimare la trasformazione.
    pts_b = np.float32([[p[2] * scale_x, p[3] * scale_y] for p in points])

    # Punti coincidenti o allineati lungo una retta non determinano una
    # trasformazione: getAffineTransform restituisce una matrice nulla (tutta
    # l'immagine B diventa il colore di un solo pixel) e findHomography senza
    # RANSAC una trasformazione che collassa l'immagine — in entrambi i casi
    # prima si dichiarava "successo, confidenza 1.0" e il confronto riportava
    # cambiamenti enormi anche fra due immagini identiche.
    diag = float(np.hypot(w, h))
    for label, pts in (("A", pts_a), ("B", pts_b)):
        centered = pts - pts.mean(axis=0)
        singular = np.linalg.svd(centered, compute_uv=False)
        # Il secondo valore singolare, diviso per √n, è lo scarto quadratico
        # medio dei punti dalla retta che meglio li approssima: sotto lo 0,5%
        # della diagonale dell'immagine (≈7 px su 1024²) sono di fatto
        # allineati e la trasformazione non è determinata.
        if len(singular) < 2 or singular[1] / np.sqrt(len(points)) < 0.005 * diag:
            raise ValueError(
                f"I punti di controllo su {label} sono troppo vicini tra loro o allineati "
                "lungo una linea: sceglili ben distribuiti sull'immagine (per esempio "
                "vicino ai quattro angoli)."
            )

    full_mask = np.full((h, w), 255, dtype=np.uint8)

    if len(points) == 3:
        matrix = cv2.getAffineTransform(pts_b, pts_a)
        aligned = cv2.warpAffine(
            img_b_resized, matrix, (w, h), flags=cv2.INTER_LINEAR,
            borderMode=cv2.BORDER_CONSTANT, borderValue=0,
        )
        valid_mask = cv2.warpAffine(
            full_mask, matrix, (w, h), flags=cv2.INTER_NEAREST,
            borderMode=cv2.BORDER_CONSTANT, borderValue=0,
        )
        method = "manual-affine"
        warp_out = matrix.tolist()
        warp_like = lambda ch, _m=matrix: cv2.warpAffine(  # noqa: E731
            _fit(ch, w, h), _m, (w, h), flags=cv2.INTER_NEAREST,
            borderMode=cv2.BORDER_CONSTANT, borderValue=0)
    else:
        ransac_method = cv2.RANSAC if len(points) > 4 else 0
        matrix, _inlier_mask = cv2.findHomography(pts_b, pts_a, ransac_method, 5.0)
        if matrix is None:
            raise ValueError(
                "Impossibile calcolare una trasformazione valida da questi punti "
                "(probabilmente troppo vicini o allineati tra loro: sceglili più "
                "distribuiti sull'immagine)."
            )
        aligned = cv2.warpPerspective(
            img_b_resized, matrix, (w, h), flags=cv2.INTER_LINEAR,
            borderMode=cv2.BORDER_CONSTANT, borderValue=0,
        )
        valid_mask = cv2.warpPerspective(
            full_mask, matrix, (w, h), flags=cv2.INTER_NEAREST,
            borderMode=cv2.BORDER_CONSTANT, borderValue=0,
        )
        method = "manual-homography"
        warp_out = matrix.tolist()
        warp_like = lambda ch, _m=matrix: cv2.warpPerspective(  # noqa: E731
            _fit(ch, w, h), _m, (w, h), flags=cv2.INTER_NEAREST,
            borderMode=cv2.BORDER_CONSTANT, borderValue=0)

    if _coverage(valid_mask) < MIN_VALID_COVERAGE:
        raise ValueError(
            "La trasformazione ricavata da questi punti fa uscire quasi tutta la ripresa B "
            "dall'area di A: controlla che ogni coppia indichi davvero lo stesso punto nelle due immagini."
        )

    return RegistrationResult(
        aligned=aligned,
        method=method,
        success=True,
        valid_mask=_erode_valid_mask(valid_mask),
        warp_matrix=warp_out,
        matched_features=len(points),
        confidence=1.0,
        warp_like=warp_like,
    )


# Spostamento massimo (frazione della diagonale) che la rifinitura ECC può
# aggiungere all'allineamento geografico: le coordinate sono già corrette a
# meno di qualche metro (ortorettifica, georeferenza delle fonti); una
# correzione più grande vuol dire che ECC ha agganciato altro.
GEO_REFINE_MAX_SHIFT = 0.02


def _masked_cc(gray_a: np.ndarray, gray_b: np.ndarray, mask: np.ndarray) -> float:
    """Correlazione (ECC) fra le due immagini dove B ha dati reali."""
    try:
        return float(cv2.computeECC(_clahe_normalize(gray_a), _clahe_normalize(gray_b), mask))
    except cv2.error:
        return 0.0


def register_auto(
    img_a: np.ndarray, img_b: np.ndarray, points: list[tuple[float, float, float, float]], refine: bool = True
) -> RegistrationResult:
    """Allineamento automatico: dalle coordinate (register_geo) se possibile,
    con le immagini (register_images) come riserva.

    Le coordinate salvate non sono sempre giuste — riprese Esri scaricate
    prima della correzione dell'aspect ratio hanno bbox sbagliate anche di
    un fattore 2 —: se dopo l'allineamento geografico le immagini restano
    poco correlate si prova anche quello dalle immagini e si tiene il
    migliore, misurato allo stesso modo (correlazione dove B ha dati). Se
    le coordinate non bastano (sovrapposizione minima, punti in fila) si
    usano direttamente le immagini, invece di fallire.
    """
    try:
        geo = register_geo(img_a, img_b, points, refine)
    except ValueError:
        return register_images(img_a, img_b)
    if geo.confidence >= ECC_MIN_CORRELATION:
        return geo
    feat = register_images(img_a, img_b)
    if not feat.success:
        return geo
    feat_cc = _masked_cc(to_gray(img_a), to_gray(feat.aligned), feat.valid_mask)
    if feat_cc > geo.confidence + 0.05:
        # Stessa misura per entrambi i metodi anche nel risultato salvato.
        feat.confidence = round(max(0.0, min(1.0, feat_cc)), 3)
        return feat
    return geo


def register_geo(
    img_a: np.ndarray, img_b: np.ndarray, points: list[tuple[float, float, float, float]], refine: bool = True
) -> RegistrationResult:
    """Allinea img_b su img_a a partire dalle coordinate geografiche delle
    due riprese: i punti (ax, ay, bx, by) sono calcolati dal webapp
    proiettando una griglia di punti di A, tramite lon/lat, sull'immagine B
    (pixel originali di entrambe).

    È il metodo più robusto fra fonti diverse (Sentinel ed Esri, epoche
    lontane, sensori diversi), dove il feature matching trova poco o
    aggancia dettagli sbagliati: la geometria viene dalle coordinate, non
    dalle immagini. Con refine, una rifinitura ECC corregge poi i piccoli
    scarti di georeferenza fra le fonti — accettata solo se migliora davvero
    la correlazione e resta entro GEO_REFINE_MAX_SHIFT.
    """
    if len(points) < 4:
        raise ValueError("Servono almeno 4 punti per l'allineamento geografico.")
    h, w = img_a.shape[:2]
    if img_b.shape[:2] != (h, w):
        sx, sy = w / img_b.shape[1], h / img_b.shape[0]
        img_b_resized = cv2.resize(img_b, (w, h), interpolation=cv2.INTER_AREA)
    else:
        sx = sy = 1.0
        img_b_resized = img_b
    pts_a = np.float32([[p[0], p[1]] for p in points])
    pts_b = np.float32([[p[2] * sx, p[3] * sy] for p in points])
    # Punti quasi allineati (B che si sovrappone ad A solo in una striscia):
    # l'omografia sarebbe indeterminata nella direzione mancante.
    if cv2.contourArea(cv2.convexHull(pts_a)) < 0.01 * w * h:
        raise ValueError("Le due riprese si sovrappongono troppo poco per un allineamento dalle coordinate.")
    # Punti esatti (non misure): minimi quadrati, nessun RANSAC.
    homography, _ = cv2.findHomography(pts_b, pts_a, 0)
    if homography is None:
        raise ValueError("Impossibile ricavare la trasformazione dalle coordinate delle riprese.")

    full_mask = np.full((h, w), 255, dtype=np.uint8)
    warp = lambda ch, _h=homography: cv2.warpPerspective(  # noqa: E731
        _fit(ch, w, h), _h, (w, h), flags=cv2.INTER_NEAREST, borderMode=cv2.BORDER_CONSTANT, borderValue=0)
    aligned = cv2.warpPerspective(img_b_resized, homography, (w, h), flags=cv2.INTER_LINEAR,
                                  borderMode=cv2.BORDER_CONSTANT, borderValue=0)
    valid_mask = warp(full_mask)
    if _coverage(valid_mask) < MIN_VALID_COVERAGE:
        raise ValueError("Le due riprese coprono aree quasi del tutto diverse: non c'è abbastanza superficie in comune da confrontare.")

    method = "geo"
    warp_like = warp
    gray_a = to_gray(img_a)
    base_cc = _masked_cc(gray_a, to_gray(aligned), valid_mask)
    # Confidenza: la correlazione fra le immagini dove si sovrappongono
    # (le coordinate da sole non dicono quanto siano giuste).
    confidence = round(max(0.0, min(1.0, base_cc)), 3)
    if refine:
        gray_b = to_gray(aligned)
        m, ok, _ = _ecc_refine(gray_a, gray_b, mask=valid_mask)
        shift = float(np.hypot(m[0, 2], m[1, 2])) / float(np.hypot(w, h))
        if ok and shift <= GEO_REFINE_MAX_SHIFT:
            refined = cv2.warpAffine(aligned, m, (w, h), flags=cv2.INTER_LINEAR | cv2.WARP_INVERSE_MAP,
                                     borderMode=cv2.BORDER_CONSTANT, borderValue=0)
            refined_mask = cv2.warpAffine(valid_mask, m, (w, h), flags=cv2.INTER_NEAREST | cv2.WARP_INVERSE_MAP,
                                          borderMode=cv2.BORDER_CONSTANT, borderValue=0)
            # La correlazione restituita da findTransformECC è misurata sulle
            # immagini sfocate (gaussFiltSize), sistematicamente più alta di
            # base_cc: il confronto si rifà con la stessa misura.
            cc = _masked_cc(gray_a, to_gray(refined), refined_mask)
            if cc > base_cc + 0.01:
                aligned, valid_mask = refined, refined_mask
                warp_like = lambda ch, _w=warp, _m=m: cv2.warpAffine(  # noqa: E731
                    _w(ch), _m, (w, h), flags=cv2.INTER_NEAREST | cv2.WARP_INVERSE_MAP,
                    borderMode=cv2.BORDER_CONSTANT, borderValue=0)
                method = "geo+ecc"
                confidence = round(max(0.0, min(1.0, cc)), 3)

    return RegistrationResult(
        aligned=aligned,
        method=method,
        success=True,
        valid_mask=_erode_valid_mask(valid_mask),
        warp_matrix=homography.tolist(),
        matched_features=len(points),
        confidence=confidence,
        warp_like=warp_like,
    )


def frac_homography(result: RegistrationResult, w: int, h: int, grid: int = 24) -> list | None:
    """La trasformazione B→A dell'allineamento come omografia 3×3 fra
    coordinate frazionarie (0-1) delle due immagini: un punto di B in
    frazioni va nel punto di A, e quindi di aligned_b, che mostra lo stesso
    contenuto.

    Ogni metodo (coordinate + rifinitura, ORB + ECC, ECC affine, punti
    manuali) espone la propria trasformazione solo come warp_like: qui la si
    applica a due griglie che contengono le coordinate di B, e dai punti di
    A che ricevono dati si stima l'omografia (esatta: tutti i metodi sono
    omografie o affini, anche composte). Serve a confrontare gli oggetti
    rilevati con lo stesso allineamento delle immagini, non con le sole
    coordinate salvate, che fra fonti diverse possono differire di 5-15 m.

    None se l'allineamento non è riuscito o è disattivato.
    """
    if result is None or not result.success or result.warp_like is None or result.method in ("none", "skipped"):
        return None
    ys, xs = np.mgrid[0:h, 0:w].astype(np.float32)
    gx = (xs + 0.5) / w
    gy = (ys + 0.5) / h
    valid = result.warp_like(np.full((h, w), 255, np.uint8))
    wx = result.warp_like(gx)
    wy = result.warp_like(gy)
    iy = np.linspace(0, h - 1, grid).astype(int)
    ix = np.linspace(0, w - 1, grid).astype(int)
    pts_a, pts_b = [], []
    for y in iy:
        for x in ix:
            if valid[y, x] > 0:
                pts_a.append(((x + 0.5) / w, (y + 0.5) / h))
                pts_b.append((float(wx[y, x]), float(wy[y, x])))
    if len(pts_a) < 8:
        return None
    homography, _ = cv2.findHomography(np.float32(pts_b), np.float32(pts_a), 0)
    return homography.tolist() if homography is not None else None
