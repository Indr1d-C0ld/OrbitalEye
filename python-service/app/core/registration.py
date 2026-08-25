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
mare/deserto), si degrada a un semplice allineamento via ECC diretto o,
in ultima istanza, nessun allineamento (identità) con un flag di warning.
"""
from dataclasses import dataclass, field

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


def _orb_homography(gray_a: np.ndarray, gray_b: np.ndarray, max_features: int = 4000):
    orb = cv2.ORB_create(nfeatures=max_features)
    kp_a, des_a = orb.detectAndCompute(gray_a, None)
    kp_b, des_b = orb.detectAndCompute(gray_b, None)

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
    warp_matrix = np.eye(2, 3, dtype=np.float32)
    criteria = (cv2.TERM_CRITERIA_EPS | cv2.TERM_CRITERIA_COUNT, 200, 1e-6)
    try:
        _, warp_matrix = cv2.findTransformECC(
            gray_a, gray_b_warped, warp_matrix, warp_mode, criteria, None, 5
        )
        return warp_matrix, True
    except cv2.error:
        return warp_matrix, False


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
        warp2x3, ecc_ok = _ecc_refine(gray_a, gray_aligned)
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
        return RegistrationResult(
            aligned=aligned,
            method="orb+ecc" if ecc_ok else "orb",
            success=True,
            valid_mask=_erode_valid_mask(valid_mask),
            warp_matrix=homography.tolist(),
            matched_features=inliers,
            confidence=confidence,
        )

    warp2x3, ecc_ok = _ecc_refine(gray_a, gray_b)
    if ecc_ok:
        aligned = cv2.warpAffine(
            img_b_resized, warp2x3, (w, h), flags=cv2.INTER_LINEAR | cv2.WARP_INVERSE_MAP,
            borderMode=cv2.BORDER_CONSTANT, borderValue=0,
        )
        valid_mask = cv2.warpAffine(
            full_mask, warp2x3, (w, h), flags=cv2.INTER_NEAREST | cv2.WARP_INVERSE_MAP,
            borderMode=cv2.BORDER_CONSTANT, borderValue=0,
        )
        return RegistrationResult(
            aligned=aligned,
            method="ecc",
            success=True,
            valid_mask=_erode_valid_mask(valid_mask),
            warp_matrix=warp2x3.tolist(),
            matched_features=inliers,
            confidence=0.4,
        )

    return RegistrationResult(
        aligned=img_b_resized,
        method="none",
        success=False,
        valid_mask=full_mask,
        matched_features=inliers,
        confidence=0.0,
    )
