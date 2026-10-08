from datetime import datetime

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel, Field

from ..config import settings
from ..core.esri_client import EsriError, fetch_world_imagery
from ..core.sentinelhub_client import (
    RED_NIR_GAIN,
    SentinelHubError,
    fetch_red_nir,
    fetch_sentinel1,
    fetch_true_color,
    search_sentinel1,
    search_sentinel2,
)
from ..core.wayback_client import WaybackError, fetch_wayback
from ..core.utils import new_id
from ..deps import require_service_key

router = APIRouter(prefix="/fetch", tags=["fetch"], dependencies=[Depends(require_service_key)])


class SentinelHubFetchRequest(BaseModel):
    bbox: list[float] = Field(..., min_length=4, max_length=4)
    date_from: str
    date_to: str
    width: int = 1024
    height: int = 1024
    max_cloud_coverage: int = 20
    # Data e ora UTC di un singolo passaggio (dal catalogo): se presente si
    # scarica solo quel passaggio, e la ripresa ha una data certa.
    pass_datetime: str | None = None


@router.post("/sentinelhub")
def fetch_sentinelhub(req: SentinelHubFetchRequest):
    try:
        png_bytes = fetch_true_color(
            bbox=req.bbox,
            date_from=req.date_from,
            date_to=req.date_to,
            width=req.width,
            height=req.height,
            max_cloud_coverage=req.max_cloud_coverage,
            pass_datetime=req.pass_datetime,
        )
    except SentinelHubError as e:
        raise HTTPException(status_code=502, detail=str(e))

    file_id = new_id()
    filename = f"{file_id}.png"
    path = settings.raw_dir / filename
    path.write_bytes(png_bytes)

    # Scarica anche la coppia Rosso+NIR in aggiunta al vero colore, per
    # abilitare NDVI/falso colore infrarosso su questa ripresa (vedi
    # /analysis/spectral_view). Un fallimento qui (es. banda momentaneamente
    # non disponibile) non deve bloccare il download del vero colore, che
    # resta comunque il prodotto principale: si ignora e basta, l'utente può
    # sempre ri-scaricare più tardi per riprovare ad ottenere anche la banda NIR.
    nir_relative_path = None
    try:
        nir_bytes = fetch_red_nir(
            bbox=req.bbox,
            date_from=req.date_from,
            date_to=req.date_to,
            width=req.width,
            height=req.height,
            max_cloud_coverage=req.max_cloud_coverage,
            pass_datetime=req.pass_datetime,
        )
        nir_filename = f"{file_id}_nir.png"
        (settings.raw_dir / nir_filename).write_bytes(nir_bytes)
        nir_relative_path = f"raw/{nir_filename}"
    except Exception:
        # Qualunque errore (non solo SentinelHubError: anche un timeout o una
        # risposta inattesa) sulla sola banda NIR non deve far fallire un
        # download il cui prodotto principale è già stato scritto su disco —
        # altrimenti quel file resterebbe orfano, non registrato da nessuno.
        nir_relative_path = None

    return {
        "id": file_id,
        "filename": filename,
        "relative_path": f"raw/{filename}",
        "nir_relative_path": nir_relative_path,
        "nir_gain": RED_NIR_GAIN if nir_relative_path else None,
        "source": "sentinel-2-l2a",
        "bbox": req.bbox,
        "date_from": req.date_from,
        "date_to": req.date_to,
        "pass_datetime": req.pass_datetime,
        "fetched_at": datetime.utcnow().isoformat() + "Z",
        "width": req.width,
        "height": req.height,
    }


class EsriFetchRequest(BaseModel):
    bbox: list[float] = Field(..., min_length=4, max_length=4)
    width: int = 1024
    height: int = 1024


@router.post("/esri")
def fetch_esri(req: EsriFetchRequest):
    try:
        jpg_bytes, adjusted_bbox, (real_w, real_h) = fetch_world_imagery(bbox=req.bbox, width=req.width, height=req.height)
    except EsriError as e:
        raise HTTPException(status_code=502, detail=str(e))

    file_id = new_id()
    filename = f"{file_id}.jpg"
    path = settings.raw_dir / filename
    path.write_bytes(jpg_bytes)

    return {
        "id": file_id,
        "filename": filename,
        "relative_path": f"raw/{filename}",
        "source": "esri-world-imagery",
        # bbox effettivamente coperta dall'immagine (può differire da quella
        # richiesta se ArcGIS ha dovuto adattarne il rapporto d'aspetto —
        # vedi _adjust_bbox_to_aspect in esri_client.py): è questa che va
        # salvata come riferimento geografico della ripresa.
        "bbox": adjusted_bbox,
        "fetched_at": datetime.utcnow().isoformat() + "Z",
        # Dimensioni REALI dell'immagine: dopo un tentativo a risoluzione
        # ridotta non coincidono con quelle richieste, e sono queste che la
        # scala (metri/pixel) deve usare.
        "width": real_w,
        "height": real_h,
    }


class Sentinel1FetchRequest(BaseModel):
    bbox: list[float] = Field(..., min_length=4, max_length=4)
    pass_datetime: str
    width: int = 1024
    height: int = 1024


@router.post("/sentinel1")
def fetch_s1(req: Sentinel1FetchRequest):
    try:
        png_bytes = fetch_sentinel1(req.bbox, req.pass_datetime, req.width, req.height)
    except SentinelHubError as e:
        raise HTTPException(status_code=502, detail=str(e))
    file_id = new_id()
    filename = f"{file_id}.png"
    (settings.raw_dir / filename).write_bytes(png_bytes)
    return {
        "id": file_id,
        "filename": filename,
        "relative_path": f"raw/{filename}",
        "source": "sentinel-1-grd",
        "bbox": req.bbox,
        "pass_datetime": req.pass_datetime,
        "fetched_at": datetime.utcnow().isoformat() + "Z",
        "width": req.width,
        "height": req.height,
    }


class WaybackFetchRequest(BaseModel):
    bbox: list[float] = Field(..., min_length=4, max_length=4)
    release: int = Field(..., ge=1)
    width: int = Field(1024, ge=64, le=2500)
    height: int = Field(1024, ge=64, le=2500)


@router.post("/wayback")
def fetch_esri_wayback(req: WaybackFetchRequest):
    try:
        jpg_bytes, bbox, (w, h), zoom = fetch_wayback(req.release, req.bbox, req.width, req.height)
    except WaybackError as e:
        raise HTTPException(status_code=502, detail=str(e))
    file_id = new_id()
    filename = f"{file_id}.jpg"
    (settings.raw_dir / filename).write_bytes(jpg_bytes)
    return {
        "id": file_id,
        "filename": filename,
        "relative_path": f"raw/{filename}",
        "source": "esri-wayback",
        "release": req.release,
        "zoom": zoom,
        # Come per Esri corrente: bbox adattata al rapporto d'aspetto, quella
        # effettivamente coperta dall'immagine.
        "bbox": bbox,
        "fetched_at": datetime.utcnow().isoformat() + "Z",
        "width": w,
        "height": h,
    }


class CatalogRequest(BaseModel):
    bbox: list[float] = Field(..., min_length=4, max_length=4)
    date_from: str
    date_to: str


@router.post("/catalog/sentinel2")
def catalog_s2(req: CatalogRequest):
    try:
        return search_sentinel2(req.bbox, req.date_from, req.date_to)
    except SentinelHubError as e:
        raise HTTPException(status_code=502, detail=str(e))


@router.post("/catalog/sentinel1")
def catalog_s1(req: CatalogRequest):
    try:
        return search_sentinel1(req.bbox, req.date_from, req.date_to)
    except SentinelHubError as e:
        raise HTTPException(status_code=502, detail=str(e))
