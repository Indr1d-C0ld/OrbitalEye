"""Regioni di cambiamento: aree in pixel coerenti con il totale; confronto
robusto per riprese ad alta risoluzione di epoche diverse."""
import unittest

import numpy as np

from . import _env  # noqa: F401
import cv2

from app.core.diff import apply_threshold, clean_mask, compute_diff, find_change_regions


class RegionsTest(unittest.TestCase):
    def test_aree_in_pixel_e_somma_coerente(self):
        mask = np.zeros((200, 200), np.uint8)
        mask[10:17, 10:17] = 255           # 49 px
        mask[50:70, 50:70] = 255           # 400 px
        mask[100:140, 100:140] = 255       # anello: 1600 - 576 = 1024 px
        mask[112:136, 112:136] = 0
        regions = find_change_regions(mask, min_area=40)
        self.assertEqual(sorted(r.area for r in regions), [49, 400, 1024])
        self.assertEqual(sum(r.area for r in regions), int(np.count_nonzero(mask)))

    def test_area_minima(self):
        mask = np.zeros((50, 50), np.uint8)
        mask[5:10, 5:10] = 255  # 25 px
        self.assertEqual(find_change_regions(mask, min_area=40), [])


def ground(seed=4, size=500):
    """Terreno a 0,4 m/pixel: trama fine (cemento, macchie) e qualche
    struttura."""
    rng = np.random.default_rng(seed)
    base = cv2.resize(rng.integers(90, 170, (size // 4, size // 4, 3), dtype=np.uint8), (size, size),
                      interpolation=cv2.INTER_CUBIC)
    noise = rng.integers(-25, 25, (size, size, 3))
    img = np.clip(base.astype(int) + noise, 0, 255).astype(np.uint8)
    cv2.rectangle(img, (40, 300), (160, 420), (60, 50, 140), -1)
    return img


def changed(a, b, method, mpp=0.4):
    dm = compute_diff(a, b, method, mpp_x=mpp, mpp_y=mpp)
    m = clean_mask(apply_threshold(dm, 30), 3, 1, 2, 40)
    return np.count_nonzero(m) / m.size, m


def shifted(img, px):
    return cv2.warpAffine(img, np.float32([[1, 0, px], [0, 1, 0]]), (img.shape[1], img.shape[0]),
                          borderMode=cv2.BORDER_REFLECT)


class RobustDiffTest(unittest.TestCase):
    def test_disallineamento_di_un_metro_e_mezzo(self):
        # La stessa foto spostata di 1,5 m: per lo SSIM sotto il metro è
        # "cambiata" quasi ovunque, per il confronto robusto quasi nulla.
        a = ground()
        b = shifted(a, 1.5 / 0.4)
        self.assertGreater(changed(a, b, "ssim")[0], 0.5)
        self.assertLess(changed(a, b, "robust")[0], 0.05)

    def test_colori_diversi_non_sono_un_cambiamento(self):
        # Altro sensore o altra elaborazione: più scura, meno contrastata.
        a = ground()
        b = np.clip(a.astype(np.float32) * 0.75 + 20, 0, 255).astype(np.uint8)
        self.assertLess(changed(a, b, "robust")[0], 0.01)

    def test_oggetto_comparso_rilevato(self):
        # Un velivolo chiaro di ~16 m su trama diversa (altra epoca).
        a = ground(seed=4)
        b = ground(seed=4)
        rng = np.random.default_rng(9)
        b = np.clip(b.astype(int) + rng.integers(-25, 25, b.shape), 0, 255).astype(np.uint8)
        cv2.rectangle(b, (300, 100), (340, 140), (235, 235, 235), -1)
        ratio, m = changed(a, b, "robust")
        self.assertGreater(np.count_nonzero(m[100:140, 300:340]), 0.6 * 40 * 40)
        self.assertLess(ratio, 0.02)

    def test_bordi_senza_dati_non_sono_un_cambiamento(self):
        # B identica ad A, ma con i bordi neri lasciati dal warp (dati
        # mancanti): nessuna fascia di cambiamento lungo il loro confine.
        a = ground()
        m = cv2.getRotationMatrix2D((250, 250), 3, 1.0)
        valid = cv2.warpAffine(np.full(a.shape[:2], 255, np.uint8), m, (500, 500))
        b = a.copy()
        b[valid == 0] = 0
        dm = compute_diff(a, b, "robust", mpp_x=0.15, mpp_y=0.15, valid_mask=valid)
        mask = clean_mask(apply_threshold(dm, 30, valid_mask=valid), 3, 1, 2, 40)
        self.assertEqual(np.count_nonzero(mask), 0)

    def test_bianco_e_nero_contro_colore(self):
        # Foto storica senza colore contro una a colori della stessa scena.
        a = ground()
        b = cv2.cvtColor(cv2.cvtColor(a, cv2.COLOR_BGR2GRAY), cv2.COLOR_GRAY2BGR)
        self.assertLess(changed(a, b, "robust")[0], 0.02)


if __name__ == "__main__":
    unittest.main()
