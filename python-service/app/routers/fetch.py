from datetime import datetime

from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel, Field

from ..config import settings
from ..core.esri_client import EsriError, fetch_world_imagery
from ..core.sentinelhub_client import RED_NIR_GAIN, SentinelHubError, fetch_red_nir, fetch_true_color
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
