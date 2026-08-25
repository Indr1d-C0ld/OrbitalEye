"""Client per l'operazione "export" del servizio pubblico Esri World Imagery
(ArcGIS REST API), usato come seconda fonte di fetch automatico oltre a
Sentinel Hub/Copernicus.

A differenza dello scraping delle tile XYZ grezze (vietato dai termini
d'uso di praticamente tutti i provider di basemap, Esri incluso), questo
client chiama l'operazione REST "export" del MapServer — il punto di
integrazione che Esri stessa documenta per la generazione programmatica di
immagini da parte di applicazioni esterne:
https://developers.arcgis.com/rest/services-reference/enterprise/export-map.htm

Non richiede credenziali per un uso leggero/occasionale sul servizio
pubblico. Per un uso sostenuto o applicazioni con traffico significativo,
Esri richiede un account ArcGIS Developer (livello gratuito disponibile su
developers.arcgis.com) e il rispetto dei relativi termini d'uso.
"""
import json

import requests

from ..config import settings

EXPORT_URL = "https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/export"

_CREDENTIALS_FILE = settings.storage_root / "config" / "esri_credentials.json"


class EsriError(RuntimeError):
    pass


def _get_api_key() -> str:
    """Come per Sentinel Hub: una API key (opzionale, il servizio pubblico
    funziona anche senza per uso leggero) impostata dalla pagina
    Impostazioni ha precedenza su quella statica nel .env."""
    if _CREDENTIALS_FILE.exists():
        try:
            data = json.loads(_CREDENTIALS_FILE.read_text())
            return data.get("api_key") or settings.esri_api_key
        except (json.JSONDecodeError, OSError):
            pass
    return settings.esri_api_key


def fetch_world_imagery(bbox: list, width: int = 1024, height: int = 1024) -> bytes:
    """bbox: [min_lon, min_lat, max_lon, max_lat] in EPSG:4326.
    Ritorna i byte JPEG del composito World Imagery corrente per l'area
    (il servizio non permette di scegliere una data storica specifica: la
    copertura è il mosaico "più recente disponibile" mantenuto da Esri,
    che varia da regione a regione).
    """
    params = {
        "bbox": ",".join(str(v) for v in bbox),
        "bboxSR": 4326,
        "imageSR": 4326,
        "size": f"{width},{height}",
        "format": "jpg",
        "transparent": "false",
        "f": "image",
    }
    api_key = _get_api_key()
    if api_key:
        params["token"] = api_key

    resp = requests.get(EXPORT_URL, params=params, timeout=60)
    if resp.status_code != 200 or not resp.headers.get("content-type", "").startswith("image"):
        raise EsriError(f"Richiesta a Esri World Imagery fallita: {resp.status_code} {resp.text[:300]}")

    return resp.content
