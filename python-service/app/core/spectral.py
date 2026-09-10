"""Indici spettrali derivati dalla banda del vicino infrarosso (NIR).

Disponibili SOLO per riprese scaricate da Sentinel Hub (Sentinel-2), e solo
per quelle scaricate dopo l'introduzione della coppia Rosso+NIR in
fetch_red_nir() (sentinelhub_client.py): Esri World Imagery è una basemap
RGB già renderizzata e i caricamenti manuali non hanno mai dati oltre il
visibile, quindi non possono supportare NDVI o falso colore infrarosso.

La coppia Rosso+NIR è codificata come un'immagine BGR "normale" (stesso
schema dell'evalscript di fetch): canale R=Rosso*gain, G=NIR*gain, B=0.
"""
import cv2
import numpy as np


def compute_ndvi(nir_red_img: np.ndarray) -> np.ndarray:
    """Calcola l'NDVI = (NIR - Rosso) / (NIR + Rosso) dalla coppia
    Rosso+NIR scaricata in aggiunta al vero colore. Ritorna una mappa in
    scala di grigi 0-255 (0 = NDVI -1, 128 = NDVI 0, 255 = NDVI +1): valori
    alti indicano vegetazione densa/in salute, valori bassi acqua/suolo
    nudo/superfici artificiali.
    """
    red = nir_red_img[:, :, 2].astype(np.float32)
    nir = nir_red_img[:, :, 1].astype(np.float32)
    denom = nir + red
    denom[denom < 1e-3] = 1e-3
    ndvi = (nir - red) / denom  # range teorico -1..1
    return np.clip((ndvi + 1.0) * 127.5, 0, 255).astype(np.uint8)


def _build_ndvi_lut() -> np.ndarray:
    """Palette diverging standard per NDVI: bruno/rosso (acqua o suolo nudo)
    -> giallo (vegetazione rada) -> verde (vegetazione densa), invece della
    sola scala di grigi meno leggibile a colpo d'occhio."""
    stops = [
        (0, (140, 80, 30)),     # BGR: bruno scuro — acqua/ombra/suolo nudo
        (100, (70, 130, 180)),  # BGR: ocra — suolo/vegetazione scarsissima
        (128, (60, 210, 230)),  # BGR: giallo — transizione (NDVI ~ 0)
        (190, (60, 170, 70)),   # BGR: verde — vegetazione moderata
        (255, (20, 100, 20)),   # BGR: verde scuro — vegetazione densa
    ]
    lut = np.zeros((256, 3), dtype=np.uint8)
    for (x0, c0), (x1, c1) in zip(stops, stops[1:]):
        span = max(1, x1 - x0)
        for x in range(x0, x1 + 1):
            t = (x - x0) / span
            lut[x] = [round(c0[k] + (c1[k] - c0[k]) * t) for k in range(3)]
    return lut


_NDVI_LUT = _build_ndvi_lut().reshape(256, 1, 3)


def colorize_ndvi(ndvi_gray: np.ndarray) -> np.ndarray:
    """Applica la palette diverging bruno→giallo→verde alla mappa NDVI in
    scala di grigi, per una lettura immediata senza dover interpretare i
    livelli di grigio."""
    return cv2.applyColorMap(ndvi_gray, _NDVI_LUT)


def compute_ndwi(nir_red_img: np.ndarray, true_color_img: np.ndarray) -> np.ndarray:
    """Calcola l'NDWI (McFeeters, 1996) = (Verde - NIR) / (Verde + NIR).
    Il Verde non fa parte della coppia Rosso+NIR (per scaricare una sola
    immagine aggiuntiva): si riusa il canale verde della ripresa vero-colore
    già scaricata in coppia, stesso schema già seguito da false_color_ir().
    Ritorna una mappa in scala di grigi 0-255 (0 = NDWI -1, 128 = NDWI 0,
    255 = NDWI +1): valori alti indicano acqua, valori bassi vegetazione/
    suolo nudo/superfici artificiali — un secondo indice complementare
    all'NDVI, utile per individuare corpi d'acqua/allagamenti a colpo
    d'occhio invece di dedurli per esclusione dalla sola vegetazione.
    """
    nir = nir_red_img[:, :, 1].astype(np.float32)
    green = true_color_img[:, :, 1].astype(np.float32)
    denom = green + nir
    denom[denom < 1e-3] = 1e-3
    ndwi = (green - nir) / denom  # range teorico -1..1
    return np.clip((ndwi + 1.0) * 127.5, 0, 255).astype(np.uint8)


def _build_ndwi_lut() -> np.ndarray:
    """Palette diverging per NDWI: bruno/verde (terra/vegetazione) -> blu
    (acqua), scelta apposta diversa da quella NDVI così i due indici non si
    confondono a colpo d'occhio anche se derivati dagli stessi due canali."""
    stops = [
        (0, (30, 90, 110)),      # BGR: bruno — terra/vegetazione, NDWI molto negativo
        (110, (60, 150, 90)),    # BGR: verde-bruno — vegetazione/suolo, NDWI negativo
        (128, (180, 180, 160)),  # BGR: grigio chiaro — transizione (NDWI ~ 0)
        (190, (200, 130, 40)),   # BGR: azzurro — acqua poco profonda/umida
        (255, (160, 60, 10)),    # BGR: blu scuro — acqua profonda/netta
    ]
    lut = np.zeros((256, 3), dtype=np.uint8)
    for (x0, c0), (x1, c1) in zip(stops, stops[1:]):
        span = max(1, x1 - x0)
        for x in range(x0, x1 + 1):
            t = (x - x0) / span
            lut[x] = [round(c0[k] + (c1[k] - c0[k]) * t) for k in range(3)]
    return lut


_NDWI_LUT = _build_ndwi_lut().reshape(256, 1, 3)


def colorize_ndwi(ndwi_gray: np.ndarray) -> np.ndarray:
    """Applica la palette diverging terra→blu alla mappa NDWI in scala di
    grigi, per una lettura immediata senza dover interpretare i livelli di
    grigio (stesso principio di colorize_ndvi())."""
    return cv2.applyColorMap(ndwi_gray, _NDWI_LUT)


def false_color_ir(nir_red_img: np.ndarray, true_color_img: np.ndarray) -> np.ndarray:
    """Composito falso colore infrarosso classico (R=NIR, G=Rosso, B=Verde):
    la vegetazione viva appare in rosso/rosa acceso (la clorofilla riflette
    fortemente il NIR), suolo nudo/superfici artificiali in toni blu-grigi,
    acqua quasi nera. Molto usato per distinguere vegetazione reale da
    superfici che sembrano verdi a vero colore ma non lo sono (es. reti
    mimetiche, teli, vernici) e viceversa.

    Il canale Verde non fa parte della coppia Rosso+NIR (per scaricare una
    sola immagine aggiuntiva): si riusa il canale verde della ripresa
    vero-colore già scaricata in coppia (stessa area/risoluzione).
    """
    nir = nir_red_img[:, :, 1]
    red = nir_red_img[:, :, 2]
    green = true_color_img[:, :, 1]
    return cv2.merge([green, red, nir])  # BGR: B=Verde, G=Rosso, R=NIR
