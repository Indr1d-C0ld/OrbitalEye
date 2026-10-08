"""Versioni storiche di Esri World Imagery (archivio Wayback).

Esri conserva ogni versione del mosaico World Imagery pubblicata dal 2014
(circa una al mese) e la espone come servizio WMTS a tasselli, lo stesso
che documenta per l'uso in client GIS di terze parti come QGIS. A
differenza del servizio corrente non esiste un'operazione "export": qui si
fa quello che fa un client GIS quando esporta una mappa — si scaricano i
pochi tasselli che coprono l'area, alla scala adatta, e si ricompone
l'immagine.

L'immagine restituita è nella stessa griglia delle riprese Esri correnti
(lon/lat lineari, bbox adattata al rapporto d'aspetto con la stessa regola
di esri_client.py): una versione storica e una corrente della stessa area
risultano già allineate pixel per pixel.
"""
import math
import time
from concurrent.futures import ThreadPoolExecutor

import cv2
import numpy as np
import requests

from .esri_client import _adjust_bbox_to_aspect

TILE_URL = ("https://wayback.maptiles.arcgis.com/arcgis/rest/services/World_Imagery/WMTS/1.0.0/"
            "default028mm/MapServer/tile/{release}/{z}/{y}/{x}")
TILEMAP_URL = ("https://wayback.maptiles.arcgis.com/arcgis/rest/services/World_Imagery/MapServer/"
               "tilemap/{release}/{z}/{y}/{x}")

TILE_SIZE = 256
EARTH_CIRCUMFERENCE = 40_075_016.686
MAX_ZOOM = 19
MIN_ZOOM = 10
# Tetto ai tasselli per immagine: 2500×2500 px a scala piena ne richiedono
# circa 130; oltre si scende di un livello di zoom.
MAX_TILES = 160
USER_AGENT = "OrbitalEye (self-hosted imagery analysis)"


class WaybackError(RuntimeError):
    pass


def _lon_to_x(lon: float, z: int) -> float:
    return (lon + 180.0) / 360.0 * (2 ** z)


def _lat_to_y(lat: float, z: int) -> float:
    lat = max(-85.05112878, min(85.05112878, lat))
    r = math.radians(lat)
    return (1.0 - math.log(math.tan(r) + 1.0 / math.cos(r)) / math.pi) / 2.0 * (2 ** z)


def _zoom_for(bbox: list, width: int, height: int) -> int:
    """Livello i cui tasselli hanno una risoluzione pari o più fine di
    quella richiesta (metri/pixel sul lato più dettagliato)."""
    lat = (bbox[1] + bbox[3]) / 2
    m_per_deg_lon = EARTH_CIRCUMFERENCE * math.cos(math.radians(lat)) / 360
    mpp_x = (bbox[2] - bbox[0]) * m_per_deg_lon / width
    mpp_y = (bbox[3] - bbox[1]) * 111_320 / height
    target = max(0.01, min(mpp_x, mpp_y))
    tile_mpp0 = EARTH_CIRCUMFERENCE * math.cos(math.radians(lat)) / TILE_SIZE
    z = math.ceil(math.log2(tile_mpp0 / target))
    return max(MIN_ZOOM, min(MAX_ZOOM, z))


def _has_data(session: requests.Session, release: int, z: int, x: int, y: int) -> bool:
    try:
        r = session.get(TILEMAP_URL.format(release=release, z=z, y=y, x=x), timeout=(5, 15))
        return r.status_code == 200 and (r.json().get("data") or [0])[0] == 1
    except (requests.RequestException, ValueError):
        return False


def _fetch_tile(session: requests.Session, release: int, z: int, x: int, y: int):
    url = TILE_URL.format(release=release, z=z, y=y, x=x)
    last = None
    for attempt in range(3):
        try:
            r = session.get(url, timeout=(5, 20))
        except requests.RequestException as e:
            last = str(e)
            time.sleep(0.5 * (attempt + 1))
            continue
        if r.status_code == 200 and r.headers.get("content-type", "").startswith("image"):
            img = cv2.imdecode(np.frombuffer(r.content, np.uint8), cv2.IMREAD_COLOR)
            if img is not None:
                return img
            return None  # risposta "immagine" illeggibile: tassello assente
        if r.status_code == 404 or r.status_code == 200:
            # 404, o 200 con un errore JSON al posto dell'immagine: il
            # tassello non esiste a questo livello.
            return None
        last = f"HTTP {r.status_code}"
        time.sleep(0.5 * (attempt + 1))
    raise WaybackError(f"Tassello Wayback {z}/{y}/{x} non scaricabile: {last}")


def fetch_wayback(release: int, bbox: list, width: int = 1024, height: int = 1024) -> tuple[bytes, list, tuple[int, int], int]:
    """Immagine della versione Wayback `release` sull'area.

    Ritorna (byte JPEG, bbox effettivamente coperta, (larghezza, altezza),
    livello di zoom usato). Se a scala piena la versione non ha tasselli
    sull'area (le versioni più vecchie arrivano spesso solo al livello 17 o
    18) si scende di livello finché li trova.
    """
    bbox = _adjust_bbox_to_aspect([float(v) for v in bbox], width, height)
    min_lon, min_lat, max_lon, max_lat = bbox
    session = requests.Session()
    session.headers["User-Agent"] = USER_AGENT

    z = _zoom_for(bbox, width, height)
    cx = (min_lon + max_lon) / 2
    cy = (min_lat + max_lat) / 2
    while z > MIN_ZOOM:
        x0, x1 = int(_lon_to_x(min_lon, z)), int(_lon_to_x(max_lon, z))
        y0, y1 = int(_lat_to_y(max_lat, z)), int(_lat_to_y(min_lat, z))
        if (x1 - x0 + 1) * (y1 - y0 + 1) <= MAX_TILES and _has_data(
                session, release, z, int(_lon_to_x(cx, z)), int(_lat_to_y(cy, z))):
            break
        z -= 1

    # Si controlla solo il tassello centrale prima di scaricare: se ai bordi
    # ne manca qualcuno (versioni vecchie, coperture parziali) si scende di
    # un livello invece di lasciare blocchi neri nell'immagine, fino a tre
    # volte; poi si accetta solo un'immagine con almeno il 90% dei tasselli.
    for _attempt in range(4):
        x0, x1 = int(_lon_to_x(min_lon, z)), int(_lon_to_x(max_lon, z))
        y0, y1 = int(_lat_to_y(max_lat, z)), int(_lat_to_y(min_lat, z))
        coords = [(x, y) for y in range(y0, y1 + 1) for x in range(x0, x1 + 1)]
        with ThreadPoolExecutor(max_workers=6) as pool:
            tiles = list(pool.map(lambda c: _fetch_tile(session, release, z, c[0], c[1]), coords))
        missing = sum(t is None for t in tiles)
        if missing == 0 or z <= MIN_ZOOM:
            break
        z -= 1
    if missing == len(tiles):
        raise WaybackError("Questa versione dell'archivio non ha immagini per l'area richiesta.")
    if missing > 0.1 * len(tiles):
        raise WaybackError("Questa versione dell'archivio copre solo in parte l'area richiesta.")

    mosaic = np.zeros(((y1 - y0 + 1) * TILE_SIZE, (x1 - x0 + 1) * TILE_SIZE, 3), np.uint8)
    for (x, y), tile in zip(coords, tiles):
        if tile is None:
            continue
        if tile.shape[:2] != (TILE_SIZE, TILE_SIZE):
            tile = cv2.resize(tile, (TILE_SIZE, TILE_SIZE), interpolation=cv2.INTER_AREA)
        oy, ox = (y - y0) * TILE_SIZE, (x - x0) * TILE_SIZE
        mosaic[oy:oy + TILE_SIZE, ox:ox + TILE_SIZE] = tile

    # Ricampionamento dalla proiezione dei tasselli (Web Mercator) alla
    # griglia lon/lat lineare delle riprese: colonna -> longitudine,
    # riga -> latitudine (centri dei pixel).
    lons = min_lon + (np.arange(width) + 0.5) / width * (max_lon - min_lon)
    lats = max_lat - (np.arange(height) + 0.5) / height * (max_lat - min_lat)
    map_x = ((lons + 180.0) / 360.0 * (2 ** z) - x0) * TILE_SIZE - 0.5
    lat_r = np.radians(np.clip(lats, -85.05112878, 85.05112878))
    my = (1.0 - np.log(np.tan(lat_r) + 1.0 / np.cos(lat_r)) / np.pi) / 2.0 * (2 ** z)
    map_y = (my - y0) * TILE_SIZE - 0.5
    grid_x, grid_y = np.meshgrid(map_x.astype(np.float32), map_y.astype(np.float32))
    # Riduzione forte (zoom più fine del necessario non capita, ma un
    # livello sceso per mancanza di dati sì, in senso opposto): AREA solo
    # quando si rimpicciolisce di oltre 2×.
    scale = (map_x[-1] - map_x[0]) / max(1, width - 1)
    if scale > 2:
        f = 1.0 / scale
        mosaic = cv2.resize(mosaic, None, fx=f, fy=f, interpolation=cv2.INTER_AREA)
        grid_x *= f
        grid_y *= f
    out = cv2.remap(mosaic, grid_x, grid_y, interpolation=cv2.INTER_LINEAR, borderMode=cv2.BORDER_REPLICATE)
    ok, buf = cv2.imencode(".jpg", out, [cv2.IMWRITE_JPEG_QUALITY, 92])
    if not ok:
        raise WaybackError("Codifica dell'immagine non riuscita")
    return buf.tobytes(), bbox, (width, height), z
