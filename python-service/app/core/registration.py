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


def _ecc_refine(gray_a: np.ndarray, gray_b_warped: np.ndarray, warp_mode=cv2.MOTION_EUCLIDEAN):
    """Rifinisce l'allineamento massimizzando la correlazione (ECC) tra le due
    immagini, lavorando su versioni CLAHE-normalizzate: l'ECC confronta
    direttamente le intensità dei pixel, quindi differenze di bilanciamento
    colore/contrasto tra fonti diverse fanno fallire la convergenza molto più
    spesso che con il matching a feature locali (ORB)."""
    warp_matrix = np.eye(2, 3, dtype=np.float32)
    criteria = (cv2.TERM_CRITERIA_EPS | cv2.TERM_CRITERIA_COUNT, 200, 1e-6)
    try:
        cc, warp_matrix = cv2.findTransformECC(
            _clahe_normalize(gray_a), _clahe_normalize(gray_b_warped), warp_matrix, warp_mode, criteria, None, 5
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
