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

    resp = requests.post(
        settings.sentinelhub_token_url,
        data={
            "grant_type": "client_credentials",
            "client_id": client_id,
            "client_secret": client_secret,
        },
        timeout=30,
    )
    if resp.status_code != 200:
        raise SentinelHubError(f"Autenticazione Sentinel Hub fallita: {resp.status_code} {resp.text[:300]}")

    data = resp.json()
    _token_cache["token"] = data["access_token"]
    _token_cache["expires_at"] = now + data.get("expires_in", 300)
    _token_cache["client_id"] = client_id
    return _token_cache["token"]


def fetch_true_color(
    bbox: list,
    date_from: str,
    date_to: str,
    width: int = 1024,
    height: int = 1024,
    max_cloud_coverage: int = 20,
) -> bytes:
    """bbox: [min_lon, min_lat, max_lon, max_lat] in EPSG:4326.
    date_from/date_to: stringhe ISO 'YYYY-MM-DD'.
    Ritorna i byte PNG dell'immagine True Color composita (mosaico del
    periodo, immagine più recente/con meno nuvole in primo piano).
    """
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
        "evalscript": _EVALSCRIPT_TRUE_COLOR,
    }

    resp = requests.post(
        settings.sentinelhub_process_url,
        json=payload,
        headers={"Authorization": f"Bearer {token}"},
        timeout=90,
    )
    if resp.status_code != 200:
        raise SentinelHubError(f"Richiesta Process API fallita: {resp.status_code} {resp.text[:500]}")

    return resp.content
