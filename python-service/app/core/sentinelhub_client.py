"""Client minimale per il Process API di Copernicus Data Space Ecosystem
(compatibile Sentinel Hub), usato per scaricare composite True Color
Sentinel-2 di una bounding box e periodo temporale dati.

Richiede un client OAuth2 (client_credentials grant) creato su
https://dataspace.copernicus.eu -> Dashboard -> User Settings -> OAuth clients.
Il piano gratuito copre ampiamente un uso "casalingo" saltuario.
"""
import json
import time

import requests

from ..config import settings

_CREDENTIALS_FILE = settings.storage_root / "config" / "sentinelhub_credentials.json"


def _get_credentials():
    """Le credenziali possono arrivare da .env (installazione) oppure essere
    impostate a caldo dalla pagina Impostazioni del webapp PHP, che le scrive
    in storage/config/sentinelhub_credentials.json. Il file, se presente, ha
    la precedenza così l'utente non deve mai toccare il .env dopo il setup
    iniziale.
    """
    if _CREDENTIALS_FILE.exists():
        try:
            data = json.loads(_CREDENTIALS_FILE.read_text())
            client_id = data.get("client_id") or settings.sentinelhub_client_id
            client_secret = data.get("client_secret") or settings.sentinelhub_client_secret
            return client_id, client_secret
        except (json.JSONDecodeError, OSError):
            pass
    return settings.sentinelhub_client_id, settings.sentinelhub_client_secret

_EVALSCRIPT_TRUE_COLOR = """
//VERSION=3
function setup() {
  return {
    input: ["B02", "B03", "B04", "dataMask"],
    output: { bands: 4 }
  };
}
function evaluatePixel(sample) {
  let gain = 2.5;
  return [
    sample.B04 * gain, sample.B03 * gain, sample.B02 * gain, sample.dataMask
  ];
}
"""

# Coppia Rosso (B04) + vicino infrarosso/NIR (B08), codificata nei canali di
# un PNG RGBA esattamente come il vero colore sopra (stesso gain, stessa
# scala 0-255) così può essere caricata con lo stesso load_image() usato
# ovunque nel servizio, senza dipendenze aggiuntive per formati float/TIFF.
# Canali: R=Red*RED_NIR_GAIN, G=NIR*RED_NIR_GAIN, B=0 (inutilizzato), A=dataMask.
# Serve a calcolare indici spettrali (NDVI, falso colore infrarosso) — vedi
# core/spectral.py — possibili SOLO per riprese Sentinel-2 (Esri World
# Imagery e i caricamenti manuali non hanno mai dati oltre il visibile RGB).
_EVALSCRIPT_RED_NIR = """
//VERSION=3
function setup() {
  return {
    input: ["B04", "B08", "dataMask"],
    output: { bands: 4 }
  };
}
function evaluatePixel(sample) {
  let gain = 1.0;
  return [
    sample.B04 * gain, sample.B08 * gain, 0, sample.dataMask
  ];
}
"""

# Guadagno applicato alla coppia Rosso+NIR qui sopra. Era 2.5 come per il
# vero colore, ma l'uscita è a 8 bit: ogni riflettanza oltre 0.4 saturava a
# 255 — e il NIR della vegetazione sana sta proprio fra 0.3 e 0.6, così
# l'NDVI risultava compresso (0.77 reale → 0.67; 0.05 → 0.00). A 1.0 l'intero
# intervallo di riflettanza sta nei 256 livelli. Il valore viene restituito
# al chiamante e salvato nei metadati della ripresa, così NDWI e falso colore
# (che combinano questa coppia con il vero colore a guadagno 2.5) possono
# riportare i due prodotti alla stessa scala anche per file più vecchi.
RED_NIR_GAIN = 1.0
TRUE_COLOR_GAIN = 2.5

_token_cache = {"token": None, "expires_at": 0}


class SentinelHubError(RuntimeError):
    pass


def _get_token() -> str:
    client_id, client_secret = _get_credentials()
    if not client_id or not client_secret:
        raise SentinelHubError(
            "Credenziali Sentinel Hub / Copernicus non configurate (Impostazioni webapp o .env)"
        )

    now = time.time()
    if _token_cache["token"] and _token_cache["expires_at"] > now + 30 and _token_cache.get("client_id") == client_id:
        return _token_cache["token"]

    # Errori di rete e risposte malformate vanno tradotti in SentinelHubError:
    # altrimenti arrivano al chiamante come 500 opachi, senza messaggio utile.
    try:
        resp = requests.post(
            settings.sentinelhub_token_url,
            data={
                "grant_type": "client_credentials",
                "client_id": client_id,
                "client_secret": client_secret,
            },
            timeout=(10, 20),
        )
    except requests.RequestException as e:
        raise SentinelHubError(f"Servizio di autenticazione Copernicus non raggiungibile: {e}") from e
    if resp.status_code != 200:
        raise SentinelHubError(f"Autenticazione Sentinel Hub fallita: {resp.status_code} {resp.text[:300]}")

    try:
        data = resp.json()
        access_token = data["access_token"]
    except (ValueError, KeyError, TypeError) as e:
        raise SentinelHubError("Risposta di autenticazione Copernicus non valida") from e
    _token_cache["token"] = access_token
    _token_cache["expires_at"] = now + data.get("expires_in", 300)
    _token_cache["client_id"] = client_id
    return _token_cache["token"]


def _process_request(
    evalscript: str,
    bbox: list,
    date_from: str,
    date_to: str,
    width: int,
    height: int,
    max_cloud_coverage: int,
) -> bytes:
    """bbox: [min_lon, min_lat, max_lon, max_lat] in EPSG:4326.
    date_from/date_to: stringhe ISO 'YYYY-MM-DD'.
    Ritorna i byte PNG dell'immagine composita (mosaico del periodo,
    immagine più recente/con meno nuvole in primo piano) per l'evalscript
    indicato — condivisa da fetch_true_color e fetch_red_nir, che differiscono
    solo per le bande richieste."""
    token = _get_token()

    payload = {
        "input": {
            "bounds": {
                "bbox": bbox,
                "properties": {"crs": "http://www.opengis.net/def/crs/EPSG/0/4326"},
            },
            "data": [
                {
                    "type": "sentinel-2-l2a",
                    "dataFilter": {
                        "timeRange": {
                            "from": f"{date_from}T00:00:00Z",
                            "to": f"{date_to}T23:59:59Z",
                        },
                        "maxCloudCoverage": max_cloud_coverage,
                        "mosaickingOrder": "leastCC",
                    },
                }
            ],
        },
        "output": {
            "width": width,
            "height": height,
            "responses": [{"identifier": "default", "format": {"type": "image/png"}}],
        },
        "evalscript": evalscript,
    }

    # Timeout di lettura contenuto: vero colore + NIR vengono scaricati in
    # sequenza e l'intera richiesta deve chiudersi entro il timeout con cui il
    # PHP attende la risposta (vedi CaptureFetcher), altrimenti i file
    # verrebbero scritti dopo che il PHP ha già rinunciato, restando orfani.
    try:
        resp = requests.post(
            settings.sentinelhub_process_url,
            json=payload,
            headers={"Authorization": f"Bearer {token}"},
            timeout=(10, 70),
        )
    except requests.RequestException as e:
        raise SentinelHubError(f"Process API Copernicus non raggiungibile: {e}") from e
    if resp.status_code != 200:
        raise SentinelHubError(f"Richiesta Process API fallita: {resp.status_code} {resp.text[:500]}")

    return resp.content


def fetch_true_color(
    bbox: list,
    date_from: str,
    date_to: str,
    width: int = 1024,
    height: int = 1024,
    max_cloud_coverage: int = 20,
) -> bytes:
    return _process_request(_EVALSCRIPT_TRUE_COLOR, bbox, date_from, date_to, width, height, max_cloud_coverage)


def fetch_red_nir(
    bbox: list,
    date_from: str,
    date_to: str,
    width: int = 1024,
    height: int = 1024,
    max_cloud_coverage: int = 20,
) -> bytes:
    """Stessa area/periodo/copertura nuvolosa della ripresa vero-colore
    (stessa richiesta, evalscript diverso): la coppia Rosso+NIR risultante è
    quindi pixel-allineata alla ripresa vero-colore scaricata in coppia,
    utile per calcolare NDVI/falso colore infrarosso (vedi core/spectral.py).
    """
    return _process_request(_EVALSCRIPT_RED_NIR, bbox, date_from, date_to, width, height, max_cloud_coverage)
