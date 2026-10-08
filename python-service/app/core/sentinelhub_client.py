"""Client per le API Sentinel Hub di Copernicus Data Space Ecosystem:
catalogo dei passaggi (Sentinel-2 ottico, Sentinel-1 radar), statistiche
di nuvolosità sull'area (Statistical API) e scaricamento di un singolo
passaggio (Process API).

Richiede un client OAuth2 (client_credentials grant) creato su
https://dataspace.copernicus.eu -> Dashboard -> User Settings -> OAuth clients.
Il piano gratuito copre ampiamente un uso "casalingo" saltuario.
"""
import json
import math
import re
import time
from datetime import datetime, timedelta, timezone

from concurrent.futures import ThreadPoolExecutor

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


def _api_url(path: str) -> str:
    """URL di un'altra API Sentinel Hub (catalogo, statistiche) sullo stesso
    host del Process API configurato: chi usa un'istanza diversa da quella
    di Copernicus Data Space la configura una volta sola."""
    base = settings.sentinelhub_process_url.rsplit("/api/", 1)[0]
    return base + path


def _post(url: str, payload: dict, timeout: tuple, what: str) -> requests.Response:
    token = _get_token()
    for attempt in range(4):
        try:
            resp = requests.post(url, json=payload, headers={"Authorization": f"Bearer {token}"}, timeout=timeout)
        except requests.RequestException as e:
            raise SentinelHubError(f"{what} Copernicus non raggiungibile: {e}") from e
        # Limite di frequenza del piano gratuito: si attende e si riprova.
        if resp.status_code != 429 or attempt == 3:
            break
        try:
            wait = float(resp.headers.get("Retry-After", ""))
        except ValueError:
            wait = 0
        time.sleep(min(10.0, max(wait, 1.5 * (attempt + 1))))
    if resp.status_code != 200:
        raise SentinelHubError(f"Richiesta {what} fallita: {resp.status_code} {resp.text[:500]}")
    return resp


def _process_request(evalscript: str, bbox: list, data: dict, width: int, height: int) -> bytes:
    """bbox: [min_lon, min_lat, max_lon, max_lat] in EPSG:4326; data: la
    voce "input.data" del Process API (collezione, filtro temporale,
    elaborazione). Ritorna i byte PNG — condivisa da tutte le fonti
    Copernicus, che differiscono solo per collezione e bande richieste."""
    payload = {
        "input": {
            "bounds": {
                "bbox": bbox,
                "properties": {"crs": "http://www.opengis.net/def/crs/EPSG/0/4326"},
            },
            "data": [data],
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
    return _post(settings.sentinelhub_process_url, payload, (10, 70), "Process API").content


def _s2_data(date_from: str, date_to: str, max_cloud_coverage: int, pass_datetime: str | None) -> dict:
    """Filtro Sentinel-2: un singolo passaggio (pass_datetime, ISO UTC) o,
    per compatibilità, il mosaico "meno nuvoloso" di un intervallo."""
    if pass_datetime:
        t = _parse_dt(pass_datetime)
        return {
            "type": "sentinel-2-l2a",
            "dataFilter": {
                "timeRange": {"from": _iso(t - PASS_WINDOW), "to": _iso(t + PASS_WINDOW)},
                "mosaickingOrder": "mostRecent",
            },
        }
    return {
        "type": "sentinel-2-l2a",
        "dataFilter": {
            "timeRange": {"from": f"{date_from}T00:00:00Z", "to": f"{date_to}T23:59:59Z"},
            "maxCloudCoverage": max_cloud_coverage,
            "mosaickingOrder": "leastCC",
        },
    }


def fetch_true_color(
    bbox: list,
    date_from: str,
    date_to: str,
    width: int = 1024,
    height: int = 1024,
    max_cloud_coverage: int = 20,
    pass_datetime: str | None = None,
) -> bytes:
    return _process_request(_EVALSCRIPT_TRUE_COLOR, bbox,
                            _s2_data(date_from, date_to, max_cloud_coverage, pass_datetime), width, height)


def fetch_red_nir(
    bbox: list,
    date_from: str,
    date_to: str,
    width: int = 1024,
    height: int = 1024,
    max_cloud_coverage: int = 20,
    pass_datetime: str | None = None,
) -> bytes:
    """Stessa area/passaggio della ripresa vero-colore (stessa richiesta,
    evalscript diverso): la coppia Rosso+NIR risultante è quindi
    pixel-allineata alla ripresa vero-colore scaricata in coppia, utile per
    calcolare NDVI/falso colore infrarosso (vedi core/spectral.py).
    """
    return _process_request(_EVALSCRIPT_RED_NIR, bbox,
                            _s2_data(date_from, date_to, max_cloud_coverage, pass_datetime), width, height)


# ----------------------------------------------------------------------------
# Sentinel-1 (radar SAR)
# ----------------------------------------------------------------------------

# Retrodiffusione VV in decibel, in scala di grigi: piste e piazzali (superfici
# lisce) risultano scuri, edifici e oggetti metallici — velivoli compresi —
# punti chiari. Tra le rese provate è la più leggibile e la più adatta al
# confronto fra date (un falso colore VV/VH/rapporto dava tinte dominate dalla
# vegetazione). Intervallo -22..+4 dB: copre suolo nudo e strutture senza
# saturare i riflettori forti.
_EVALSCRIPT_S1_VV = """
//VERSION=3
function setup() {
  return { input: ["VV", "dataMask"], output: { bands: 4 } };
}
function evaluatePixel(s) {
  let db = 10 * Math.log(Math.max(s.VV, 1e-5)) / Math.LN10;
  let g = Math.max(0, Math.min(1, (db + 22) / 26));
  return [g, g, g, s.dataMask];
}
"""


def fetch_sentinel1(bbox: list, pass_datetime: str, width: int = 1024, height: int = 1024) -> bytes:
    """Un passaggio Sentinel-1 IW (modalità standard sulle terre emerse),
    calibrato gamma0 sul terreno, ortorettificato sul DEM Copernicus e con
    filtro anti-speckle Lee 3×3. La finestra temporale è stretta: i passaggi
    ascendente e discendente sono a 12 ore di distanza, e i prodotti
    consecutivi della stessa orbita (25 s l'uno) vanno presi insieme se
    l'area cade a cavallo di due."""
    t = _parse_dt(pass_datetime)
    data = {
        "type": "sentinel-1-grd",
        "dataFilter": {
            "timeRange": {"from": _iso(t - PASS_WINDOW), "to": _iso(t + PASS_WINDOW)},
            "acquisitionMode": "IW",
            "polarization": "DV",
            "resolution": "HIGH",
        },
        "processing": {
            "backCoeff": "GAMMA0_TERRAIN",
            "orthorectify": True,
            "demInstance": "COPERNICUS",
            "speckleFilter": {"type": "LEE", "windowSizeX": 3, "windowSizeY": 3},
        },
    }
    return _process_request(_EVALSCRIPT_S1_VV, bbox, data, width, height)


# ----------------------------------------------------------------------------
# Catalogo dei passaggi
# ----------------------------------------------------------------------------

# Mezza ampiezza della finestra con cui si isola un passaggio: i prodotti
# dello stesso passaggio distano pochi secondi, due orbite diverse sulla
# stessa area almeno 10 minuti (Sentinel-2, orbite relative adiacenti).
PASS_WINDOW = timedelta(minutes=3)

# Nuvole e copertura sull'area, un passaggio per giorno. Classi della Scene
# Classification (SCL) di Sentinel-2 L2A contate come nuvola: ombra di
# nuvola (3), nuvola a probabilità media (8) e alta (9), cirro sottile (10).
#
# Per giorno, non per mosaico: con due passaggi nello stesso giorno (aree
# nella sovrapposizione fra orbite adiacenti) il mosaico giornaliero
# riempirebbe i pixel che uno non copre con l'altro, e nuvole e copertura
# risulterebbero di entrambi. Né il filtro temporale dei dati né la
# mosaicatura per orbita lo evitano (quest'ultima raggruppa per giorno):
# per tassello sì, tenendo solo i tasselli del primo o dell'ultimo
# passaggio del giorno. Due richieste in tutto, qualunque sia il periodo.
_EVALSCRIPT_S2_CLOUD = """
//VERSION=3
function setup() {
  return {
    input: [{ bands: ["SCL", "dataMask"] }],
    output: [{ id: "cloud", bands: 1 }, { id: "dataMask", bands: 1 }],
    mosaicking: "TILE"
  };
}
var WIN = __WINDOW_MS__, PICK = "__PICK__";
function preProcessScenes(collections) {
  var tiles = collections.scenes.tiles;
  if (!tiles.length) return collections;
  var ts = tiles.map(function (t) { return Date.parse(t.date); });
  var ref = PICK === "first" ? Math.min.apply(null, ts) : Math.max.apply(null, ts);
  collections.scenes.tiles = tiles.filter(function (t, i) { return Math.abs(ts[i] - ref) <= WIN; });
  return collections;
}
function evaluatePixel(samples) {
  for (var i = 0; i < samples.length; i++) {
    if (samples[i].dataMask) {
      return { cloud: [[3, 8, 9, 10].indexOf(samples[i].SCL) >= 0 ? 1 : 0], dataMask: [1] };
    }
  }
  return { cloud: [0], dataMask: [0] };
}
"""

MAX_SEARCH_DAYS = 400


def _parse_dt(value: str) -> datetime:
    v = value.strip().replace("Z", "+00:00")
    try:
        dt = datetime.fromisoformat(v)
    except ValueError as e:
        raise SentinelHubError(f"Data/ora del passaggio non valida: {value}") from e
    if dt.tzinfo is None:
        dt = dt.replace(tzinfo=timezone.utc)
    return dt.astimezone(timezone.utc)


def _iso(dt: datetime) -> str:
    return dt.astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def _catalog_search(collection: str, bbox: list, date_from: str, date_to: str) -> list:
    """Tutti i prodotti della collezione che intersecano l'area nel
    periodo, seguendo la paginazione del catalogo STAC."""
    url = _api_url("/api/v1/catalog/1.0.0/search")
    body = {
        "bbox": bbox,
        "datetime": f"{date_from}T00:00:00Z/{date_to}T23:59:59Z",
        "collections": [collection],
        "limit": 100,
    }
    features = []
    for _page in range(30):
        data = _post(url, body, (10, 40), "Catalogo").json()
        features.extend(data.get("features", []))
        nxt = (data.get("context") or {}).get("next")
        if nxt is None:
            return features
        body = {**body, "next": nxt}
    # Risultati troncati: i passaggi mancanti potrebbero essere proprio i più
    # recenti, e "il migliore/il più recente" sarebbe sbagliato in silenzio.
    raise SentinelHubError("Troppi prodotti nel periodo per quest'area: restringi l'intervallo di date.")


def _group_passes(features: list) -> list:
    """Raggruppa i prodotti dello stesso passaggio (tasselli adiacenti,
    prodotti consecutivi, versioni rielaborate) e li ordina dal più
    recente."""
    items = []
    for f in features:
        p = f.get("properties") or {}
        try:
            dt = _parse_dt(p["datetime"])
        except (KeyError, SentinelHubError):
            continue
        items.append((dt, {**p, "_id": f.get("id", "")}))
    items.sort(key=lambda x: x[0])
    groups = []
    for dt, p in items:
        if groups and dt - groups[-1]["last"] <= PASS_WINDOW:
            groups[-1]["last"] = dt
            groups[-1]["props"].append(p)
        else:
            groups.append({"first": dt, "last": dt, "props": [p]})
    groups.reverse()
    return groups


def _platform(props: list) -> str:
    # "sentinel-2b" -> "Sentinel-2B"
    names = sorted({"Sentinel-" + str(p["platform"]).split("-", 1)[-1].upper() for p in props if p.get("platform")})
    return ", ".join(names)


def _relative_orbit(props: dict):
    """Orbita relativa: dalle proprietà STAC se presenti (Sentinel-1), se no
    dal nome del prodotto Sentinel-2 (…_N0513_R079_T33SVB_…)."""
    if props.get("sat:relative_orbit") is not None:
        return int(props["sat:relative_orbit"])
    m = re.search(r"_R(\d{3})_", props.get("_id", ""))
    return int(m.group(1)) if m else None


def _aoi_cloud_stats(bbox: list, date_from: str, date_to: str, pick: str = "last") -> dict:
    """Quota di nuvole e di copertura SULL'AREA per ogni giorno con dati, del
    primo o dell'ultimo passaggio del giorno (Statistical API, SCL a ~20 m).
    La copertura nuvolosa del catalogo riguarda l'intero tassello di 110 km:
    su un'area di qualche chilometro può essere 20% con l'area coperta, o
    30% con l'area sgombra."""
    evalscript = (_EVALSCRIPT_S2_CLOUD
                  .replace("__WINDOW_MS__", str(int(PASS_WINDOW.total_seconds() * 1000)))
                  .replace("__PICK__", "first" if pick == "first" else "last"))
    payload = {
        "input": {
            "bounds": {"bbox": bbox, "properties": {"crs": "http://www.opengis.net/def/crs/EPSG/0/4326"}},
            "data": [{"type": "sentinel-2-l2a"}],
        },
        "aggregation": {
            "timeRange": {"from": f"{date_from}T00:00:00Z", "to": f"{date_to}T23:59:59Z"},
            "aggregationInterval": {"of": "P1D", "lastIntervalBehavior": "SHORTEN"},
            "evalscript": evalscript,
            **_stats_resolution(bbox),
        },
    }
    return _parse_stats(_post(_api_url("/api/v1/statistics"), payload, (10, 90), "Statistical API").json())


def _stats_resolution(bbox: list) -> dict:
    """~20 m (la risoluzione della SCL), in gradi."""
    lat = (bbox[1] + bbox[3]) / 2
    res_lat = 20 / 111_320
    return {"resx": res_lat / max(0.2, math.cos(math.radians(lat))), "resy": res_lat}


def _parse_stats(data: dict) -> dict:
    out = {}
    for entry in data.get("data", []):
        try:
            day = entry["interval"]["from"][:10]
            st = entry["outputs"]["cloud"]["bands"]["B0"]["stats"]
        except (KeyError, TypeError):
            continue
        samples = st.get("sampleCount") or 0
        nodata = st.get("noDataCount") or 0
        if samples <= 0 or samples == nodata:
            continue
        # Il servizio può restituire "NaN" come stringa.
        try:
            mean = float(st.get("mean"))
        except (TypeError, ValueError):
            mean = float("nan")
        out[day] = {
            "aoi_cloud": None if math.isnan(mean) else mean,
            "aoi_coverage": 1 - nodata / samples,
        }
    return out


def _check_range(date_from: str, date_to: str) -> None:
    try:
        d1 = datetime.strptime(date_from, "%Y-%m-%d")
        d2 = datetime.strptime(date_to, "%Y-%m-%d")
    except ValueError as e:
        raise SentinelHubError("Date non valide (formato AAAA-MM-GG)") from e
    if d2 < d1:
        raise SentinelHubError("Intervallo di date non valido")
    if (d2 - d1).days > MAX_SEARCH_DAYS:
        raise SentinelHubError(f"Intervallo troppo ampio: al massimo {MAX_SEARCH_DAYS} giorni per ricerca")


def search_sentinel2(bbox: list, date_from: str, date_to: str) -> dict:
    """Passaggi Sentinel-2 L2A sull'area, dal più recente: data e ora UTC,
    satellite, orbita relativa, nuvole del tassello e — se il servizio
    statistiche risponde — nuvole e copertura sull'area stessa."""
    _check_range(date_from, date_to)
    groups = _group_passes(_catalog_search("sentinel-2-l2a", bbox, date_from, date_to))
    days = [g["first"].strftime("%Y-%m-%d") for g in groups]
    doubles = sorted({d for d in days if days.count(d) > 1})
    last, first, stats_error = {}, {}, None
    try:
        with ThreadPoolExecutor(max_workers=2) as pool:
            f_last = pool.submit(_aoi_cloud_stats, bbox, date_from, date_to, "last")
            f_first = pool.submit(_aoi_cloud_stats, bbox, doubles[0], doubles[-1], "first") if doubles else None
            last = f_last.result()
            first = f_first.result() if f_first else {}
    except SentinelHubError as e:
        stats_error = str(e)
        last, first = {}, {}

    passes = []
    for g in groups:
        day = g["first"].strftime("%Y-%m-%d")
        # groups va dal più recente: in un giorno doppio il primo incontrato è
        # l'ultimo passaggio del giorno, il successivo il primo. Un eventuale
        # terzo passaggio (raro) resta senza statistiche sull'area.
        same_day = [x for x in groups if x["first"].strftime("%Y-%m-%d") == day]
        # Statistiche lette ma nessun pixel valido quel giorno: il passaggio
        # non copre l'area.
        none = {} if stats_error else {"aoi_cloud": None, "aoi_coverage": 0.0}
        if same_day[0] is g:
            st = last.get(day, none)
        elif same_day[-1] is g:
            st = first.get(day, none)
        else:
            st = {}
        clouds = [p.get("eo:cloud_cover") for p in g["props"] if p.get("eo:cloud_cover") is not None]
        orbits = sorted({_relative_orbit(p) for p in g["props"]} - {None})
        passes.append({
            "datetime": _iso(g["first"]),
            "date": day,
            "platform": _platform(g["props"]),
            "relative_orbit": orbits[0] if orbits else None,
            "scene_cloud": round(min(clouds) / 100, 4) if clouds else None,
            "aoi_cloud": round(st["aoi_cloud"], 4) if st.get("aoi_cloud") is not None else None,
            "aoi_coverage": round(st["aoi_coverage"], 4) if "aoi_coverage" in st else None,
        })
    return {"passes": passes, "stats_error": stats_error}


def search_sentinel1(bbox: list, date_from: str, date_to: str) -> dict:
    """Passaggi Sentinel-1 IW sull'area, dal più recente, con direzione e
    orbita relativa: due riprese radar si confrontano bene solo se hanno la
    stessa geometria di vista (stessa orbita relativa)."""
    _check_range(date_from, date_to)
    # Solo IW a doppia polarizzazione VV+VH: è ciò che fetch_sentinel1 scarica
    # (polarization "DV"); un passaggio SH/SV/DH verrebbe elencato ma
    # scaricato come immagine vuota.
    feats = [f for f in _catalog_search("sentinel-1-grd", bbox, date_from, date_to)
             if (f.get("properties") or {}).get("sar:instrument_mode", "IW") == "IW"
             and {"VV", "VH"} <= set((f.get("properties") or {}).get("sar:polarizations") or [])]
    passes = []
    for g in _group_passes(feats):
        p0 = g["props"][0]
        passes.append({
            "datetime": _iso(g["first"]),
            "date": g["first"].strftime("%Y-%m-%d"),
            "platform": _platform(g["props"]),
            "orbit_state": p0.get("sat:orbit_state"),
            "relative_orbit": p0.get("sat:relative_orbit"),
            "polarizations": p0.get("sar:polarizations"),
        })
    return {"passes": passes, "stats_error": None}
