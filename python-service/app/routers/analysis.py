import numpy as np
from fastapi import APIRouter, Depends, HTTPException
from pydantic import BaseModel, Field

from ..config import settings
from ..core import diff as diffmod
from ..core import enhance as enhancemod
from ..core.registration import register_images, register_with_points
from ..core.utils import load_image, new_id, safe_storage_path, save_image
from ..deps import require_service_key

router = APIRouter(prefix="/analysis", tags=["analysis"], dependencies=[Depends(require_service_key)])


class EnhanceStep(BaseModel):
    filter: str
    params: dict = Field(default_factory=dict)


class EnhanceRequest(BaseModel):
    capture_path: str
    steps: list[EnhanceStep] = Field(default_factory=list)


@router.post("/enhance")
def enhance(req: EnhanceRequest):
    try:
        src = safe_storage_path(req.capture_path)
    except ValueError:
        raise HTTPException(status_code=400, detail="Percorso non valido")
    if not src.exists():
        raise HTTPException(status_code=404, detail="Immagine non trovata")

    img = load_image(src)
    steps = [s.model_dump() for s in req.steps]
    result_img = enhancemod.apply_pipeline(img, steps)

    out_id = new_id()
    out_path = settings.processed_dir / f"{out_id}.png"
    save_image(result_img, out_path)

    return {"id": out_id, "relative_path": f"processed/{out_id}.png"}


class ControlPoint(BaseModel):
    ax: float
    ay: float
    bx: float
    by: float


class CompareRequest(BaseModel):
    capture_a_path: str
    capture_b_path: str
    align: bool = True
    diff_method: str = "ssim"  # 'ssim' | 'absdiff'
    threshold: int = 30
    use_otsu: bool = False
    morph_kernel: int = 3
    open_iterations: int = 1
    close_iterations: int = 2
    min_blob_area: int = 40
    overlay_alpha: float = 0.35
    enhance_a: list[EnhanceStep] = Field(default_factory=list)
    enhance_b: list[EnhanceStep] = Field(default_factory=list)
    # Punti di controllo per l'allineamento manuale (vedi register_with_points
    # in core/registration.py). Se presenti (>=3), hanno sempre la precedenza
    # su "align": l'analista ha già indicato lui stesso le corrispondenze,
    # niente motore automatico di mezzo.
    control_points: list[ControlPoint] = Field(default_factory=list)


@router.post("/compare")
def compare(req: CompareRequest):
    try:
        path_a = safe_storage_path(req.capture_a_path)
        path_b = safe_storage_path(req.capture_b_path)
    except ValueError:
        raise HTTPException(status_code=400, detail="Percorso non valido")
    if not path_a.exists() or not path_b.exists():
        raise HTTPException(status_code=404, detail="Una o entrambe le immagini non sono state trovate")

    img_a = load_image(path_a)
    img_b = load_image(path_b)

    if req.enhance_a:
        img_a = enhancemod.apply_pipeline(img_a, [s.model_dump() for s in req.enhance_a])
    if req.enhance_b:
        img_b = enhancemod.apply_pipeline(img_b, [s.model_dump() for s in req.enhance_b])

    import cv2

    reg_method = "skipped"
    reg_confidence = None
    h, w = img_a.shape[:2]
    valid_mask = np.full((h, w), 255, dtype=np.uint8)

    if req.control_points and len(req.control_points) >= 3:
        try:
            reg_result = register_with_points(
                img_a, img_b, [(p.ax, p.ay, p.bx, p.by) for p in req.control_points]
            )
        except ValueError as e:
            raise HTTPException(status_code=400, detail=str(e))
        img_b_aligned = reg_result.aligned
        reg_method = reg_result.method
        reg_confidence = reg_result.confidence
        valid_mask = reg_result.valid_mask
    elif req.align:
        reg_result = register_images(img_a, img_b)
        img_b_aligned = reg_result.aligned
        reg_method = reg_result.method
        reg_confidence = reg_result.confidence
        valid_mask = reg_result.valid_mask
    else:
        img_b_aligned = cv2.resize(img_b, (w, h)) if img_b.shape[:2] != (h, w) else img_b

    diff_map = diffmod.compute_diff(img_a, img_b_aligned, method=req.diff_method)
    mask = diffmod.apply_threshold(
        diff_map, threshold=req.threshold, use_otsu=req.use_otsu, valid_mask=valid_mask
    )
    mask = diffmod.clean_mask(
        mask,
        morph_kernel=req.morph_kernel,
        open_iterations=req.open_iterations,
        close_iterations=req.close_iterations,
        min_blob_area=req.min_blob_area,
    )
    mask = cv2.bitwise_and(mask, valid_mask)
    regions = diffmod.find_change_regions(mask, min_area=req.min_blob_area)
    overlay = diffmod.build_overlay(img_b_aligned, mask, regions, alpha=req.overlay_alpha)
    heatmap = diffmod.build_heatmap(diff_map)
    # Vista contorni: i bordi netti (Canny) della ripresa "dopo" aiutano a
    # distinguere il profilo di strutture nuove da semplice rumore diffuso,
    # in modo complementare alla mappa di calore delle differenze.
    edges = enhancemod.edge_detect(img_b_aligned)
    stats = diffmod.compute_stats(mask, regions, valid_mask=valid_mask)

    result_id = new_id()
    result_dir = settings.results_dir / result_id
    result_dir.mkdir(parents=True, exist_ok=True)

    save_image(img_b_aligned, result_dir / "aligned_b.jpg", quality=92)
    save_image(mask, result_dir / "mask.png")
    save_image(overlay, result_dir / "overlay.jpg", quality=92)
    save_image(heatmap, result_dir / "heatmap.jpg", quality=92)
    save_image(edges, result_dir / "edges.jpg", quality=92)

    return {
        "result_id": result_id,
        "paths": {
            "aligned_b": f"results/{result_id}/aligned_b.jpg",
            "mask": f"results/{result_id}/mask.png",
            "overlay": f"results/{result_id}/overlay.jpg",
            "heatmap": f"results/{result_id}/heatmap.jpg",
            "edges": f"results/{result_id}/edges.jpg",
        },
        "registration": {"method": reg_method, "confidence": reg_confidence},
        "params": req.model_dump(exclude={"enhance_a", "enhance_b"}),
        "stats": stats,
        "regions": [
            {"x": r.x, "y": r.y, "w": r.w, "h": r.h, "area": r.area, "cx": r.cx, "cy": r.cy}
            for r in regions[:200]
        ],
    }


class RegisterManualRequest(BaseModel):
    capture_a_path: str
    capture_b_path: str
    control_points: list[ControlPoint]


@router.post("/register_manual")
def register_manual(req: RegisterManualRequest):
    """Anteprima rapida dell'allineamento manuale a punti di controllo, senza
    eseguire un confronto completo: utile per giudicare la qualità dei punti
    indicati (tramite l'immagine allineata e un blend 50/50 con A, dove un
    disallineamento residuo si vede come "fantasma"/bordi doppi) prima di
    lanciare l'analisi vera e propria."""
    try:
        path_a = safe_storage_path(req.capture_a_path)
        path_b = safe_storage_path(req.capture_b_path)
    except ValueError:
        raise HTTPException(status_code=400, detail="Percorso non valido")
    if not path_a.exists() or not path_b.exists():
        raise HTTPException(status_code=404, detail="Una o entrambe le immagini non sono state trovate")
    if len(req.control_points) < 3:
        raise HTTPException(status_code=400, detail="Servono almeno 3 punti di controllo")

    import cv2

    img_a = load_image(path_a)
    img_b = load_image(path_b)
    points = [(p.ax, p.ay, p.bx, p.by) for p in req.control_points]

    try:
        reg_result = register_with_points(img_a, img_b, points)
    except ValueError as e:
        raise HTTPException(status_code=400, detail=str(e))

    blend = cv2.addWeighted(img_a, 0.5, reg_result.aligned, 0.5, 0)

    out_id = new_id()
    out_dir = settings.results_dir / out_id
    out_dir.mkdir(parents=True, exist_ok=True)
    save_image(reg_result.aligned, out_dir / "aligned_b.jpg", quality=92)
    save_image(blend, out_dir / "blend.jpg", quality=92)

    return {
        "result_id": out_id,
        "method": reg_result.method,
        "paths": {
            "aligned_b": f"results/{out_id}/aligned_b.jpg",
            "blend": f"results/{out_id}/blend.jpg",
        },
    }
