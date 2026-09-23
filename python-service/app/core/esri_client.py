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
import time

import cv2
import numpy as np
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


def _adjust_bbox_to_aspect(bbox: list, width: int, height: int) -> list:
    """L'operazione "export" di ArcGIS MapServer, se il rapporto larghezza/
    altezza della bbox richiesta non combacia esattamente con quello di
    `size`, ESPANDE (mai ritaglia) la bbox effettivamente utilizzata per
    farla combaciare — centrando l'espansione — così l'immagine restituita
    non risulta distorta. Il problema: l'immagine risultante copre quindi
    un'area geografica diversa (di norma più estesa in una dimensione) da
    quella richiesta, ma se si continua a considerare la bbox ORIGINALE come
    riferimento per calcolare la scala (metri/pixel) di quella ripresa, ogni
    misura risulta sballata — l'errore osservato può arrivare facilmente a
    un fattore 2× o più su bbox con rapporto d'aspetto molto diverso da 1:1
    (tipico quando si disegna un'area rettangolare stretta ma si richiede
    un'immagine quadrata, il default in questa piattaforma).

    Pre-adattando qui la bbox esattamente con la stessa logica (stesso
    confronto diretto lon/lat vs width/height, senza conversione a metri:
    è così che ArcGIS stesso la interpreta con bboxSR=4326), la bbox
    richiesta ad ArcGIS ha già il rapporto d'aspetto corretto — ArcGIS non
    deve più modificarla — e possiamo salvare CON CERTEZZA questa bbox
    (ritornata al chiamante) come area realmente coperta dall'immagine.
    """
    min_lon, min_lat, max_lon, max_lat = bbox
    d_lon = max_lon - min_lon
    d_lat = max_lat - min_lat
    if d_lon <= 0 or d_lat <= 0 or width <= 0 or height <= 0:
        return bbox

    bbox_aspect = d_lon / d_lat
    image_aspect = width / height

    if abs(bbox_aspect - image_aspect) < 1e-9:
        return bbox

    if bbox_aspect > image_aspect:
        # bbox proporzionalmente più "larga" del richiesto -> l'altezza (lat)
        # va estesa per raggiungere lo stesso rapporto.
        new_d_lat = d_lon / image_aspect
        extra = (new_d_lat - d_lat) / 2
        min_lat -= extra
        max_lat += extra
    else:
        # bbox proporzionalmente più "stretta" del richiesto -> la larghezza
        # (lon) va estesa.
        new_d_lon = d_lat * image_aspect
        extra = (new_d_lon - d_lon) / 2
        min_lon -= extra
        max_lon += extra

    return [min_lon, min_lat, max_lon, max_lat]


def fetch_world_imagery(bbox: list, width: int = 1024, height: int = 1024) -> tuple[bytes, list, tuple[int, int]]:
    """bbox: [min_lon, min_lat, max_lon, max_lat] in EPSG:4326.
    Ritorna (byte JPEG del composito World Imagery corrente per l'area, bbox
    EFFETTIVAMENTE coperta dall'immagine — vedi _adjust_bbox_to_aspect —,
    (larghezza, altezza) REALI dell'immagine restituita). Il chiamante deve
    salvare queste ultime due, non i valori richiesti, come riferimento
    geografico e dimensionale della ripresa.

    Il servizio non permette di scegliere una data storica specifica: la
    copertura è il mosaico "più recente disponibile" mantenuto da Esri, che
    varia da regione a regione.
    """
    adjusted_bbox = _adjust_bbox_to_aspect(bbox, width, height)

    params_base = {
        "bbox": ",".join(str(v) for v in adjusted_bbox),
        "bboxSR": 4326,
        "imageSR": 4326,
        "format": "jpg",
        "transparent": "false",
        "f": "image",
    }
    api_key = _get_api_key()
    if api_key:
        params_base["token"] = api_key

    # Esri rifiuta a volte le richieste troppo "complesse" (limite non
    # documentato): si riprova a risoluzione ridotta. Entrambi i lati vengono
    # ridotti dello STESSO fattore — un minimo applicato a un solo lato
    # cambierebbe il rapporto d'aspetto, e ArcGIS allargherebbe di nuovo la
    # bbox per conto suo, rendendo falsa quella che restituiamo.
    # Tetto al tempo totale: il chiamante PHP ha un proprio timeout, e un
    # file scritto dopo che il PHP ha già rinunciato resterebbe orfano.
    deadline = time.monotonic() + 150
    factor = 1.0
    last_status, last_body = None, None
    for _attempt in range(4):
        cur_w, cur_h = int(round(width * factor)), int(round(height * factor))
        if min(cur_w, cur_h) < 64 or time.monotonic() > deadline:
            break
        try:
            resp = requests.get(
                EXPORT_URL,
                params={**params_base, "size": f"{cur_w},{cur_h}"},
                timeout=(10, 40),
            )
        except requests.RequestException as e:
            last_status, last_body = "errore di rete", str(e)[:300]
            factor *= 0.7
            continue
        if resp.status_code == 200 and resp.headers.get("content-type", "").startswith("image"):
            # Dimensioni lette dall'immagine stessa, non da ciò che si è
            # chiesto: sono quelle che la scala (metri/pixel) deve usare.
            # Registrare quelle richieste dopo un tentativo ridotto dava
            # distanze dimezzate e aree ridotte a un quarto.
            decoded = cv2.imdecode(np.frombuffer(resp.content, np.uint8), cv2.IMREAD_UNCHANGED)
            if decoded is None:
                last_status, last_body = resp.status_code, "immagine non decodificabile"
                factor *= 0.7
                continue
            real_h, real_w = decoded.shape[:2]
            return resp.content, adjusted_bbox, (int(real_w), int(real_h))
        last_status, last_body = resp.status_code, resp.text[:300]
        factor *= 0.7

    raise EsriError(f"Richiesta a Esri World Imagery fallita anche a risoluzione ridotta: {last_status} {last_body}")
