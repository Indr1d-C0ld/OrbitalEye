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
    # Deve stare DENTRO lo storage: "" o "." risolvevano alla radice stessa,
    # superavano il controllo e fallivano solo più avanti con un 500.
    if root not in candidate.parents:
        raise ValueError("Percorso non consentito")
    return candidate


# Tetto alle dimensioni delle immagini elaborate. I download sono limitati a
# 2500×2500 px, ma un caricamento manuale non ha limiti propri: SSIM lavora
# in float64 su una decina di matrici, e oltre questa soglia un singolo
# confronto potrebbe occupare molti GB e far intervenire l'OOM killer.
MAX_IMAGE_PIXELS = 25_000_000


def _png_header(path: Path):
    """(profondità in bit, tipo di colore) di un PNG, leggendo solo
    l'intestazione IHDR; None se il file non è un PNG."""
    try:
        with open(path, "rb") as f:
            head = f.read(26)
    except OSError:
        return None
    if len(head) < 26 or head[:8] != b"\x89PNG\r\n\x1a\n":
        return None
    return head[24], head[25]


def load_image_with_mask(path: Path) -> tuple[np.ndarray, np.ndarray | None]:
    """Carica un'immagine come BGR a 8 bit e restituisce anche, se presente,
    la maschera dei pixel VALIDI (255) ricavata dalla trasparenza.

    - Trasparenza: le riprese Sentinel Hub usano l'alfa (dataMask) per le
      zone senza dati. Con una lettura a 3 canali quei pixel diventavano neri
      qualsiasi, e il confronto li contava come cambiamento.
    - 16 bit: la conversione predefinita di OpenCV divide per 256, così un
      PNG a 16 bit che contiene dati a 12 bit (massimo 4095) diventava quasi
      nero. Qui si scala in base alla profondità effettivamente usata.
    - Tutti gli altri casi (JPEG, PNG a 8 bit senza alfa, WEBP) passano per la
      lettura standard, che applica anche l'orientamento EXIF.
    """
    header = _png_header(path)
    special = header is not None and (header[0] == 16 or header[1] in (4, 6))
    mask = None
    if special:
        raw = cv2.imread(str(path), cv2.IMREAD_UNCHANGED)
        if raw is None:
            raise ValueError(f"Impossibile leggere l'immagine: {path}")
        if raw.dtype == np.uint16:
            color = raw[:, :, :3] if raw.ndim == 3 else raw
            bits = max(8, int(color.max()).bit_length())
            scaled = (color.astype(np.float32) * (255.0 / ((1 << bits) - 1))).clip(0, 255).astype(np.uint8)
            if raw.ndim == 3 and raw.shape[2] == 4:
                raw = np.dstack([scaled, (raw[:, :, 3] > 0).astype(np.uint8) * 255])
            else:
                raw = scaled
        if raw.ndim == 2:
            img = cv2.cvtColor(raw, cv2.COLOR_GRAY2BGR)
        elif raw.shape[2] == 2:  # grigio + alfa
            img = cv2.cvtColor(raw[:, :, 0], cv2.COLOR_GRAY2BGR)
            alpha = raw[:, :, 1]
            mask = np.where(alpha > 0, 255, 0).astype(np.uint8) if alpha.min() == 0 else None
        elif raw.shape[2] == 4:
            img = np.ascontiguousarray(raw[:, :, :3])
            alpha = raw[:, :, 3]
            mask = np.where(alpha > 0, 255, 0).astype(np.uint8) if alpha.min() == 0 else None
        else:
            img = raw
    else:
        img = cv2.imread(str(path), cv2.IMREAD_COLOR)
        if img is None:
            raise ValueError(f"Impossibile leggere l'immagine: {path}")

    h, w = img.shape[:2]
    if h * w > MAX_IMAGE_PIXELS:
        raise ValueError(
            f"Immagine troppo grande da elaborare ({w}×{h} px, massimo "
            f"{MAX_IMAGE_PIXELS // 1_000_000} megapixel): riducila prima di caricarla."
        )
    return img, mask


def load_image(path: Path) -> np.ndarray:
    return load_image_with_mask(path)[0]


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
