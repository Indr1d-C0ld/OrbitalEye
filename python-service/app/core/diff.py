"""Motore di change detection tra due riprese allineate.

Pipeline tipica:
  1. compute_diff        -> mappa di differenza grezza (0-255, grayscale)
  2. apply_threshold      -> maschera binaria (soglia manuale o Otsu automatica)
  3. clean_mask           -> riduzione rumore/falsi positivi (morfologia + area minima)
  4. find_change_regions  -> bounding box / contorni delle zone di cambiamento
  5. build_overlay/heatmap -> rendering per il report grafico
"""
from dataclasses import dataclass

import cv2
import numpy as np

from .utils import to_gray


def _ssim_map(gray_a: np.ndarray, gray_b: np.ndarray) -> np.ndarray:
    """Mappa SSIM locale (windowed), implementazione autonoma equivalente a
    skimage.metrics.structural_similarity(..., full=True) ma senza dipendere
    da scikit-image (pacchetto pesante, richiede build da sorgente su alcune
    versioni di Python non ancora coperte da wheel precompilate).

    Usa una finestra gaussiana (sigma=1.5, come nell'implementazione di
    riferimento Wang et al. 2004) per stimare media/varianza/covarianza
    locali sui due canali di luminanza.
    """
    a = gray_a.astype(np.float64)
    b = gray_b.astype(np.float64)

    C1 = (0.01 * 255) ** 2
    C2 = (0.03 * 255) ** 2

    ksize = (11, 11)
    sigma = 1.5

    mu_a = cv2.GaussianBlur(a, ksize, sigma)
    mu_b = cv2.GaussianBlur(b, ksize, sigma)

    mu_a_sq = mu_a ** 2
    mu_b_sq = mu_b ** 2
    mu_ab = mu_a * mu_b

    sigma_a_sq = cv2.GaussianBlur(a * a, ksize, sigma) - mu_a_sq
    sigma_b_sq = cv2.GaussianBlur(b * b, ksize, sigma) - mu_b_sq
    sigma_ab = cv2.GaussianBlur(a * b, ksize, sigma) - mu_ab

    numerator = (2 * mu_ab + C1) * (2 * sigma_ab + C2)
    denominator = (mu_a_sq + mu_b_sq + C1) * (sigma_a_sq + sigma_b_sq + C2)

    return numerator / denominator


# Scala predefinita del confronto robusto, in metri: tollera gli scarti di
# georeferenza fra fonti (qualche metro) e vede ancora un velivolo.
ROBUST_SCALE_M = 2.0
# Limite del guadagno nell'uniformare i colori (vedi _lab_matched).
MAX_MATCH_GAIN = 4.0
# Dispersione minima dei canali a*/b* (unità OpenCV a 8 bit) perché
# un'immagine conti come a colori.
MIN_CHROMA_SPREAD = 2.0


def _lab_matched(img_a: np.ndarray, img_b: np.ndarray, valid_mask: np.ndarray | None):
    """Le due immagini in L*a*b*, con media e deviazione di ogni canale di B
    portate su quelle di A (calcolate solo dove entrambe hanno dati): toglie
    le differenze globali di colore, contrasto e luminosità fra sensori ed
    elaborazioni diverse, che non sono cambiamenti sul terreno."""
    lab_a = cv2.cvtColor(img_a, cv2.COLOR_BGR2LAB).astype(np.float32)
    lab_b = cv2.cvtColor(img_b, cv2.COLOR_BGR2LAB).astype(np.float32)
    has_mask = valid_mask is not None and np.count_nonzero(valid_mask) > 0
    sel = valid_mask > 0 if has_mask else slice(None)
    stats = [(float(lab_a[..., c][sel].mean()), float(lab_a[..., c][sel].std()),
              float(lab_b[..., c][sel].mean()), float(lab_b[..., c][sel].std())) for c in range(3)]
    # Una delle due senza colore (foto storica in bianco e nero, radar):
    # uniformare il colore inventerebbe differenze su ogni pixel colorato
    # dell'altra, quindi si confronta solo la luminosità.
    color = min(stats[1][1], stats[1][3], stats[2][1], stats[2][3]) >= MIN_CHROMA_SPREAD
    for c, (ma, sa, mb, sb) in enumerate(stats):
        if c > 0 and not color:
            lab_b[..., c] = lab_a[..., c]
            continue
        # Guadagno limitato: un canale quasi piatto in B non va amplificato
        # fino a trasformarne il rumore in "cambiamento".
        gain = min(MAX_MATCH_GAIN, max(1 / MAX_MATCH_GAIN, sa / max(sb, 1e-3)))
        lab_b[..., c] = (lab_b[..., c] - mb) * gain + ma
    if has_mask:
        # Fuori dai dati validi (bordi neri del warp) B prende i valori di
        # A: altrimenti, mediando le celle, il nero si mescolerebbe ai
        # pixel validi vicini e lungo i bordi comparirebbe una fascia di
        # falso cambiamento, tanto più larga quanto più fini sono i pixel.
        invalid = valid_mask == 0
        lab_b[invalid] = lab_a[invalid]
    return lab_a, lab_b


def compute_robust_diff(
    img_a: np.ndarray,
    img_b: np.ndarray,
    mpp_x: float | None = None,
    mpp_y: float | None = None,
    scale_m: float = ROBUST_SCALE_M,
    valid_mask: np.ndarray | None = None,
) -> np.ndarray:
    """Differenza di colore e luminosità a scala di oggetto, per riprese ad
    alta risoluzione di epoche, sensori o elaborazioni diverse.

    Sotto il metro lo SSIM misura la correlazione della trama fine (cemento,
    erba, macchie), che fra due foto diverse è quasi nulla ovunque: fra
    riprese Esri di anni diversi segnava come cambiato il 94-99% dell'area,
    e bastavano 1,5 m di disallineamento della STESSA foto per passare dallo
    0% al 95%. Qui invece:
      1. colori di B uniformati a quelli di A (_lab_matched);
      2. entrambe ricampionate a celle di scale_m metri (media dei pixel):
         la trama e gli scarti di pochi metri si annullano, gli oggetti
         (velivoli, veicoli grandi, edifici, cantieri) restano;
      3. distanza di colore L*a*b* fra le celle, riportata alla griglia
         originale (0-255, stesse soglie degli altri metodi). Nella codifica
         a 8 bit di OpenCV la luminosità vale circa 2,5 volte il colore: è
         voluto, il colore della vegetazione cambia con la stagione (a pesi
         uguali Ghedi passava dal 62% al 76% di area variata).
    Sulle stesse coppie: 9-21% di area variata, concentrata su velivoli
    comparsi o spariti e cantieri; 1,5% con 1,5 m di disallineamento.
    Oggetti più piccoli della scala si attenuano fino a sparire.
    """
    h, w = img_a.shape[:2]
    # Un filtro pre-analisi può aver lasciato un solo canale.
    img_a = cv2.cvtColor(img_a, cv2.COLOR_GRAY2BGR) if img_a.ndim == 2 else img_a
    img_b = cv2.cvtColor(img_b, cv2.COLOR_GRAY2BGR) if img_b.ndim == 2 else img_b
    lab_a, lab_b = _lab_matched(img_a, img_b, valid_mask)
    # Celle quadrate a terra: le riprese in gradi hanno pixel diversi sui
    # due assi. Un asse già più grossolano della scala resta com'è.
    fx = min(1.0, mpp_x / scale_m) if mpp_x and scale_m > 0 else 1.0
    fy = min(1.0, mpp_y / scale_m) if mpp_y and scale_m > 0 else 1.0
    if fx < 1.0 or fy < 1.0:
        size = (max(8, round(w * fx)), max(8, round(h * fy)))
        lab_a = cv2.resize(lab_a, size, interpolation=cv2.INTER_AREA)
        lab_b = cv2.resize(lab_b, size, interpolation=cv2.INTER_AREA)
    d = lab_a - lab_b
    dist = np.sqrt(np.sum(d * d, axis=2))
    if dist.shape[:2] != (h, w):
        dist = cv2.resize(dist, (w, h), interpolation=cv2.INTER_LINEAR)
    return np.clip(dist, 0, 255).astype(np.uint8)


def compute_diff(
    img_a: np.ndarray,
    img_b: np.ndarray,
    method: str = "ssim",
    mpp_x: float | None = None,
    mpp_y: float | None = None,
    scale_m: float = ROBUST_SCALE_M,
    valid_mask: np.ndarray | None = None,
) -> np.ndarray:
    """Ritorna una mappa di differenza a 8 bit (255 = massima differenza).

    method:
      'absdiff' - differenza assoluta pixel per pixel sui 3 canali, veloce,
                  sensibile a variazioni di illuminazione/colore.
      'ssim'    - 1 - similarità strutturale locale, più robusto a piccole
                  variazioni di luce/contrasto, si concentra su cambi di
                  struttura/texture (es. nuove costruzioni) piuttosto che
                  su variazioni cromatiche globali. Adatto a risoluzioni di
                  metri (Sentinel); sotto il metro vedi 'robust'.
      'robust'  - vedi compute_robust_diff (usa mpp, scale_m, valid_mask).
    """
    if method == "robust":
        return compute_robust_diff(img_a, img_b, mpp_x, mpp_y, scale_m, valid_mask)

    gray_a = to_gray(img_a)
    gray_b = to_gray(img_b)

    if method == "absdiff":
        diff = cv2.absdiff(gray_a, gray_b)
        return diff

    ssim_map = _ssim_map(gray_a, gray_b)
    diff_map = np.clip(1.0 - ssim_map, 0, 1)
    return (diff_map * 255).astype(np.uint8)


def apply_threshold(
    diff_map: np.ndarray,
    threshold: int = 30,
    use_otsu: bool = False,
    valid_mask: np.ndarray | None = None,
) -> np.ndarray:
    """threshold: 0-255. Valori più alti => solo differenze molto marcate
    vengono segnalate (meno falsi positivi, rischio di perdere cambi sottili).
    Valori più bassi => maggiore sensibilità, più rumore.

    valid_mask: se fornita (255=valido, 0=bordo privo di dati reali dopo
    l'allineamento), i pixel non validi vengono sempre esclusi dal risultato
    e — per Otsu — anche dal calcolo della soglia automatica, altrimenti la
    grande area di bordo a differenza nulla sposterebbe artificialmente la
    soglia stimata.
    """
    if use_otsu:
        if valid_mask is not None and np.count_nonzero(valid_mask) > 0:
            sample = diff_map[valid_mask > 0]
            otsu_thresh, _ = cv2.threshold(sample, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
            _, mask = cv2.threshold(diff_map, otsu_thresh, 255, cv2.THRESH_BINARY)
        else:
            _, mask = cv2.threshold(diff_map, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    else:
        threshold = max(0, min(int(threshold), 255))
        _, mask = cv2.threshold(diff_map, threshold, 255, cv2.THRESH_BINARY)

    if valid_mask is not None:
        mask = cv2.bitwise_and(mask, valid_mask)

    return mask


def clean_mask(
    mask: np.ndarray,
    morph_kernel: int = 3,
    open_iterations: int = 1,
    close_iterations: int = 2,
    min_blob_area: int = 40,
) -> np.ndarray:
    """Rimuove il rumore a livello di singolo pixel (opening) e richiude
    piccoli buchi dentro le regioni di cambiamento (closing), poi scarta
    i blob sotto min_blob_area: la sorgente principale di falsi positivi
    nel change detection satellitare è il rumore fotografico/di compressione
    e i micro-disallineamenti residui, che generano tanti puntini isolati
    invece di una regione compatta come una nuova costruzione.
    """
    # Limiti: un kernel o un numero di iterazioni arbitrari arrivavano tali
    # e quali dalla richiesta. Con kernel 301 un confronto 1024² impiegava
    # ~7 s solo qui; con valori ancora più grandi OpenCV tenta allocazioni di
    # gigabyte. Oltre questi valori il risultato non è comunque più utile.
    morph_kernel = max(1, min(int(morph_kernel), 31))
    open_iterations = max(0, min(int(open_iterations), 10))
    close_iterations = max(0, min(int(close_iterations), 10))
    kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (morph_kernel, morph_kernel))

    cleaned = mask
    if open_iterations > 0:
        cleaned = cv2.morphologyEx(cleaned, cv2.MORPH_OPEN, kernel, iterations=open_iterations)
    if close_iterations > 0:
        cleaned = cv2.morphologyEx(cleaned, cv2.MORPH_CLOSE, kernel, iterations=close_iterations)

    if min_blob_area > 0:
        num_labels, labels, stats, _ = cv2.connectedComponentsWithStats(cleaned, connectivity=8)
        # Una sola passata vettoriale: prima si scandiva l'intera immagine una
        # volta PER OGNI blob tenuto (labels == label), costo proporzionale a
        # blob × pixel — oltre 6 s con ~13mila blob su 1024², e crescita
        # quadratica. Su scene urbane con soglia bassa bastava a superare il
        # timeout del PHP mentre il servizio continuava a lavorare.
        keep = stats[:, cv2.CC_STAT_AREA] >= min_blob_area
        keep[0] = False  # etichetta 0 = sfondo
        cleaned = np.where(keep[labels], 255, 0).astype(np.uint8)

    return cleaned


@dataclass
class ChangeRegion:
    x: int
    y: int
    w: int
    h: int
    area: int
    cx: float
    cy: float


def find_change_regions(mask: np.ndarray, min_area: int = 40) -> list:
    """Regioni di cambiamento = componenti connesse della maschera pulita.

    L'area è il NUMERO DI PIXEL effettivamente cambiati, la stessa unità di
    misura di clean_mask (che filtra i blob per pixel) e di changed_pixels
    nelle statistiche. In precedenza si usava cv2.contourArea sul contorno
    esterno, che misura il poligono passante per i centri dei pixel di bordo
    e riempie i buchi: un blob 7×7 (49 px) risultava 36 e spariva dalle
    regioni pur essendo nella maschera, e un cambiamento ad anello veniva
    riportato più grande dell'intera area cambiata (con m² di regione
    incoerenti col totale).
    """
    num, _, stats, centroids = cv2.connectedComponentsWithStats(mask, connectivity=8)
    regions = []
    for label in range(1, num):
        area = int(stats[label, cv2.CC_STAT_AREA])
        if area < min_area:
            continue
        regions.append(ChangeRegion(
            x=int(stats[label, cv2.CC_STAT_LEFT]),
            y=int(stats[label, cv2.CC_STAT_TOP]),
            w=int(stats[label, cv2.CC_STAT_WIDTH]),
            h=int(stats[label, cv2.CC_STAT_HEIGHT]),
            area=area,
            cx=float(centroids[label][0]),
            cy=float(centroids[label][1]),
        ))
    regions.sort(key=lambda r: r.area, reverse=True)
    return regions


def build_overlay(
    base_img: np.ndarray,
    mask: np.ndarray,
    regions: list,
    fill_color=(0, 255, 255),
    box_color=(0, 60, 255),
    alpha: float = 0.35,
) -> np.ndarray:
    """Immagine 'after' con le zone di cambiamento evidenziate in falso colore
    (stile intel report) e bounding box numerate sulle regioni più rilevanti.
    """
    overlay = base_img.copy()
    color_layer = np.zeros_like(base_img)
    color_layer[mask > 0] = fill_color
    blended = cv2.addWeighted(overlay, 1.0, color_layer, alpha, 0)

    for i, r in enumerate(regions, start=1):
        cv2.rectangle(blended, (r.x, r.y), (r.x + r.w, r.y + r.h), box_color, 2)
        label = str(i)
        cv2.putText(
            blended, label, (r.x, max(0, r.y - 6)),
            cv2.FONT_HERSHEY_SIMPLEX, 0.5, box_color, 1, cv2.LINE_AA,
        )
    return blended


def build_heatmap(diff_map: np.ndarray) -> np.ndarray:
    return cv2.applyColorMap(diff_map, cv2.COLORMAP_INFERNO)


def compute_stats(
    mask: np.ndarray,
    regions: list,
    valid_mask: np.ndarray | None = None,
    mpp_x: float | None = None,
    mpp_y: float | None = None,
) -> dict:
    total_px = int(np.count_nonzero(valid_mask)) if valid_mask is not None else mask.shape[0] * mask.shape[1]
    changed_px = int(np.count_nonzero(mask))
    largest_px = regions[0].area if regions else 0
    stats = {
        "changed_pixels": changed_px,
        "total_pixels": total_px,
        "changed_ratio": round(changed_px / total_px, 6) if total_px else 0,
        "num_regions": len(regions),
        "largest_region_area": largest_px,
    }
    # Aree in metri quadri quando la scala reale della ripresa è nota
    # (mpp_x/mpp_y = metri per pixel per asse): un pixel a terra vale
    # mpp_x*mpp_y m².
    if mpp_x and mpp_y:
        px_m2 = mpp_x * mpp_y
        stats["changed_area_m2"] = round(changed_px * px_m2, 2)
        stats["largest_region_area_m2"] = round(largest_px * px_m2, 2)
    return stats
