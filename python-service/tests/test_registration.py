"""Allineamento: dalle coordinate (register_geo) e dalle immagini."""
import unittest

import numpy as np

from . import _env  # noqa: F401
from app.core.registration import frac_homography, register_auto, register_geo, register_images


def textured(h=400, w=400, seed=1):
    rng = np.random.default_rng(seed)
    base = rng.integers(0, 255, (h // 8, w // 8, 3), dtype=np.uint8)
    import cv2
    img = cv2.resize(base, (w, h), interpolation=cv2.INTER_CUBIC)
    for i in range(0, w, 40):  # strutture riconoscibili
        cv2.rectangle(img, (i, i // 2), (i + 15, i // 2 + 25), (255, 255, 255), -1)
    return img


class RegisterGeoTest(unittest.TestCase):
    def test_spostamento_noto_recuperato_dalle_coordinate(self):
        a = textured()
        # B è A spostata di 30 px a destra e 10 in basso: il punto (x, y) di A
        # è in (x+30, y+10) di B.
        import cv2
        m = np.float32([[1, 0, 30], [0, 1, 10]])
        b = cv2.warpAffine(a, m, (400, 400))
        pts = [(x, y, x + 30, y + 10) for x in (40, 200, 360) for y in (40, 200, 360)]
        r = register_geo(a, b, pts, refine=False)
        self.assertEqual(r.method, "geo")
        diff = np.abs(r.aligned[60:340, 60:340].astype(int) - a[60:340, 60:340].astype(int)).mean()
        self.assertLess(diff, 3.0)

    def test_rifinitura_corregge_un_piccolo_scarto(self):
        a = textured()
        import cv2
        b = cv2.warpAffine(a, np.float32([[1, 0, 33], [0, 1, 12]]), (400, 400))
        # Coordinate imprecise di 3 px e 2 px (georeferenza delle fonti).
        pts = [(x, y, x + 30, y + 10) for x in (40, 200, 360) for y in (40, 200, 360)]
        r = register_geo(a, b, pts, refine=True)
        self.assertEqual(r.method, "geo+ecc")
        diff = np.abs(r.aligned[60:340, 60:340].astype(int) - a[60:340, 60:340].astype(int)).mean()
        self.assertLess(diff, 5.0)

    def test_aree_disgiunte_rifiutate(self):
        a = textured()
        pts = [(x, y, x + 2000, y) for x in (40, 200, 360) for y in (40, 200, 360)]
        with self.assertRaises(ValueError):
            register_geo(a, textured(seed=2), pts)


    def test_punti_in_fila_rifiutati(self):
        a = textured()
        pts = [(x, 200, x, 200) for x in (40, 120, 200, 280, 360)]
        with self.assertRaises(ValueError):
            register_geo(a, a.copy(), pts)

    def test_rifinitura_con_sovrapposizione_parziale(self):
        # B copre solo i due terzi di A: la correlazione si misura dove B ha
        # dati, altrimenti i bordi neri farebbero scartare la rifinitura.
        import cv2
        a = textured()
        b = cv2.warpAffine(a, np.float32([[1, 0, -127], [0, 1, 4]]), (400, 400))
        pts = [(x, y, x - 130, y) for x in (160, 260, 360) for y in (40, 200, 360)]
        r = register_geo(a, b, pts, refine=True)
        self.assertEqual(r.method, "geo+ecc")

    def test_rifinitura_inutile_non_gonfia_la_confidenza(self):
        # Coordinate esatte, B rumorosa: la rifinitura non migliora nulla e
        # non deve risultare accettata né alzare la correlazione dichiarata
        # (findTransformECC la misura su immagini sfocate, più alta).
        a = textured()
        rng = np.random.default_rng(7)
        b = np.clip(a.astype(int) + rng.normal(0, 60, a.shape), 0, 255).astype(np.uint8)
        pts = [(x, y, x, y) for x in (40, 200, 360) for y in (40, 200, 360)]
        plain = register_geo(a, b, pts, refine=False)
        refined = register_geo(a, b, pts, refine=True)
        self.assertEqual(refined.method, "geo")
        self.assertAlmostEqual(refined.confidence, plain.confidence, places=3)


class RegisterAutoTest(unittest.TestCase):
    def test_coordinate_sbagliate_ripiego_sulle_immagini(self):
        # Le coordinate dicono "nessuno spostamento" ma B è spostata di
        # 40 px: troppo per la rifinitura, l'allineamento dalle immagini vince.
        import cv2
        a = textured()
        b = cv2.warpAffine(a, np.float32([[1, 0, 40], [0, 1, 25]]), (400, 400))
        pts = [(x, y, x, y) for x in (40, 200, 360) for y in (40, 200, 360)]
        r = register_auto(a, b, pts)
        self.assertNotIn("geo", r.method)
        diff = np.abs(r.aligned[80:320, 80:320].astype(int) - a[80:320, 80:320].astype(int)).mean()
        self.assertLess(diff, 8.0)

    def test_coordinate_insufficienti_ripiego_sulle_immagini(self):
        a = textured()
        pts = [(x, 200, x, 200) for x in (40, 120, 200, 280, 360)]
        r = register_auto(a, a.copy(), pts)
        self.assertNotIn("geo", r.method)

    def test_coordinate_giuste_restano(self):
        import cv2
        a = textured()
        b = cv2.warpAffine(a, np.float32([[1, 0, 30], [0, 1, 10]]), (400, 400))
        pts = [(x, y, x + 30, y + 10) for x in (40, 200, 360) for y in (40, 200, 360)]
        self.assertIn("geo", register_auto(a, b, pts).method)


class FracHomographyTest(unittest.TestCase):
    def _map(self, hmat, x, y):
        v = np.array(hmat) @ np.array([x, y, 1.0])
        return v[0] / v[2], v[1] / v[2]

    def test_punto_di_b_riportato_in_a(self):
        # B = A spostata di 30 px a destra e 10 in basso: il punto (x, y) di
        # A sta in (x+30, y+10) di B, e la trasformazione B→A lo riporta.
        import cv2
        a = textured()
        b = cv2.warpAffine(a, np.float32([[1, 0, 30], [0, 1, 10]]), (400, 400))
        pts = [(x, y, x + 30, y + 10) for x in (40, 200, 360) for y in (40, 200, 360)]
        r = register_geo(a, b, pts, refine=False)
        hmat = frac_homography(r, 400, 400)
        x, y = self._map(hmat, (230 + 0.5) / 400, (110 + 0.5) / 400)
        self.assertAlmostEqual(x * 400, 200.5, delta=1.0)
        self.assertAlmostEqual(y * 400, 100.5, delta=1.0)

    def test_comprende_la_rifinitura(self):
        # Coordinate sbagliate di 3 px, corrette dalla rifinitura: la
        # trasformazione restituita è quella finale, non quella geografica.
        import cv2
        a = textured()
        b = cv2.warpAffine(a, np.float32([[1, 0, 33], [0, 1, 12]]), (400, 400))
        pts = [(x, y, x + 30, y + 10) for x in (40, 200, 360) for y in (40, 200, 360)]
        r = register_geo(a, b, pts, refine=True)
        self.assertEqual(r.method, "geo+ecc")
        x, y = self._map(frac_homography(r, 400, 400), (233 + 0.5) / 400, (112 + 0.5) / 400)
        self.assertAlmostEqual(x * 400, 200.5, delta=1.0)
        self.assertAlmostEqual(y * 400, 100.5, delta=1.0)

    def test_nessuna_trasformazione_senza_allineamento(self):
        rng = np.random.default_rng(3)
        r = register_images(rng.integers(0, 255, (300, 300, 3), dtype=np.uint8),
                            rng.integers(0, 255, (300, 300, 3), dtype=np.uint8))
        self.assertIsNone(frac_homography(r, 300, 300))


class RegisterImagesTest(unittest.TestCase):
    def test_immagini_scorrelate_non_dichiarate_allineate(self):
        rng = np.random.default_rng(3)
        a = rng.integers(0, 255, (300, 300, 3), dtype=np.uint8)
        b = rng.integers(0, 255, (300, 300, 3), dtype=np.uint8)
        r = register_images(a, b)
        self.assertEqual(r.method, "none")
        self.assertFalse(r.success)


if __name__ == "__main__":
    unittest.main()
