"""Filtri di enhancement per riprese satellitari.

Ogni funzione è pura (input -> output) e opera su immagini BGR uint8,
così da poter essere incatenata liberamente da una pipeline definita
lato client (PHP/JS) tramite un elenco ordinato di filtri + parametri.
"""
import cv2
import numpy as np


def clahe(img: np.ndarray, clip_limit: float = 2.0, tile_grid_size: int = 8) -> np.ndarray:
    """Contrast Limited Adaptive Histogram Equalization sul canale luminanza.

    Migliora il contrasto locale senza saturare le zone già chiare (utile
    su riprese con foschia o forte variazione di illuminazione).
    """
    lab = cv2.cvtColor(img, cv2.COLOR_BGR2LAB)
    l, a, b = cv2.split(lab)
    clahe_op = cv2.createCLAHE(clipLimit=clip_limit, tileGridSize=(tile_grid_size, tile_grid_size))
    l2 = clahe_op.apply(l)
    return cv2.cvtColor(cv2.merge((l2, a, b)), cv2.COLOR_LAB2BGR)


def histogram_equalization(img: np.ndarray) -> np.ndarray:
    ycrcb = cv2.cvtColor(img, cv2.COLOR_BGR2YCrCb)
    y, cr, cb = cv2.split(ycrcb)
    y2 = cv2.equalizeHist(y)
    return cv2.cvtColor(cv2.merge((y2, cr, cb)), cv2.COLOR_YCrCb2BGR)


def gamma_correction(img: np.ndarray, gamma: float = 1.0) -> np.ndarray:
    gamma = max(0.1, min(gamma, 5.0))
    inv = 1.0 / gamma
    table = (np.arange(256) / 255.0) ** inv * 255
    table = table.astype(np.uint8)
    return cv2.LUT(img, table)


def denoise(img: np.ndarray, method: str = "gaussian", strength: int = 3) -> np.ndarray:
    """Riduzione del rumore fotografico/di compressione.

    method: 'gaussian' | 'median' | 'bilateral' | 'nlmeans'
    strength: 1-10, mappato sul kernel/parametro del filtro scelto.
    """
    strength = max(1, min(int(strength), 10))
    if method == "median":
        k = strength * 2 + 1
        return cv2.medianBlur(img, k)
    if method == "bilateral":
        return cv2.bilateralFilter(img, d=strength * 2 + 3, sigmaColor=strength * 15, sigmaSpace=strength * 15)
    if method == "nlmeans":
        h = float(strength) * 2.5
        return cv2.fastNlMeansDenoisingColored(img, None, h, h, 7, 21)
    k = strength * 2 + 1
    return cv2.GaussianBlur(img, (k, k), 0)


def sharpen(img: np.ndarray, amount: float = 1.0) -> np.ndarray:
    amount = max(0.0, min(amount, 5.0))
    blurred = cv2.GaussianBlur(img, (0, 0), sigmaX=3)
    return cv2.addWeighted(img, 1 + amount, blurred, -amount, 0)


def edge_detect(img: np.ndarray, low: int = 50, high: int = 150) -> np.ndarray:
    """Canny edge detection: utile per evidenziare contorni di strutture/edifici."""
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    edges = cv2.Canny(gray, low, high)
    return cv2.cvtColor(edges, cv2.COLOR_GRAY2BGR)


def auto_white_balance(img: np.ndarray) -> np.ndarray:
    """Bilanciamento del bianco semplice (gray-world) per correggere dominanti
    cromatiche dovute ad atmosfera/sensore differenti tra due riprese."""
    result = img.astype(np.float32)
    avg_b, avg_g, avg_r = [result[:, :, i].mean() for i in range(3)]
    avg_gray = (avg_b + avg_g + avg_r) / 3.0
    result[:, :, 0] *= (avg_gray / max(avg_b, 1e-5))
    result[:, :, 1] *= (avg_gray / max(avg_g, 1e-5))
    result[:, :, 2] *= (avg_gray / max(avg_r, 1e-5))
    return np.clip(result, 0, 255).astype(np.uint8)


FILTER_REGISTRY = {
    "clahe": clahe,
    "histogram_equalization": histogram_equalization,
    "gamma": gamma_correction,
    "denoise": denoise,
    "sharpen": sharpen,
    "edge_detect": edge_detect,
    "white_balance": auto_white_balance,
}


def apply_pipeline(img: np.ndarray, steps: list) -> np.ndarray:
    """steps: lista di {"filter": nome, "params": {...}} applicati in ordine."""
    out = img
    for step in steps:
        name = step.get("filter")
        params = step.get("params", {}) or {}
        fn = FILTER_REGISTRY.get(name)
        if fn is None:
            continue
        out = fn(out, **params)
    return out
