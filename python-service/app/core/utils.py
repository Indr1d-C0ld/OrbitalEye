import uuid
from pathlib import Path

import cv2
import numpy as np

from ..config import settings


def new_id() -> str:
    return uuid.uuid4().hex[:16]


def safe_storage_path(relative_path: str) -> Path:
    """Risolve un percorso relativo allo storage root, impedendo path
    traversal (../..) verso file fuori dall'area consentita."""
    candidate = (settings.storage_root / relative_path).resolve()
    root = settings.storage_root.resolve()
    if root not in candidate.parents and candidate != root:
        raise ValueError("Percorso non consentito")
    return candidate


def load_image(path: Path) -> np.ndarray:
    img = cv2.imread(str(path), cv2.IMREAD_COLOR)
    if img is None:
        raise ValueError(f"Impossibile leggere l'immagine: {path}")
    return img


def save_image(img: np.ndarray, path: Path, quality: int = 95) -> Path:
    path.parent.mkdir(parents=True, exist_ok=True)
    ext = path.suffix.lower()
    params = []
    if ext in (".jpg", ".jpeg"):
        params = [cv2.IMWRITE_JPEG_QUALITY, quality]
    elif ext == ".png":
        params = [cv2.IMWRITE_PNG_COMPRESSION, 3]
    ok = cv2.imwrite(str(path), img, params)
    if not ok:
        raise IOError(f"Impossibile salvare l'immagine: {path}")
    return path


def to_gray(img: np.ndarray) -> np.ndarray:
    if img.ndim == 2:
        return img
    return cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)


def resize_to_match(img: np.ndarray, target_shape) -> np.ndarray:
    h, w = target_shape[:2]
    if img.shape[0] == h and img.shape[1] == w:
        return img
    return cv2.resize(img, (w, h), interpolation=cv2.INTER_AREA)


def bytes_to_image(data: bytes) -> np.ndarray:
    arr = np.frombuffer(data, dtype=np.uint8)
    img = cv2.imdecode(arr, cv2.IMREAD_COLOR)
    if img is None:
        raise ValueError("Impossibile decodificare i byte come immagine")
    return img


def image_to_bytes(img: np.ndarray, ext: str = ".png") -> bytes:
    ok, buf = cv2.imencode(ext, img)
    if not ok:
        raise IOError("Impossibile codificare l'immagine")
    return buf.tobytes()
