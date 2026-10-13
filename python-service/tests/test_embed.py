"""Libreria visiva: ritaglio orientato e vettori (questi solo se il modello
è installato: non è nel repository)."""
import unittest

import cv2
import numpy as np

from . import _env  # noqa: F401
from app.core import embed


class EmbedTest(unittest.TestCase):
    def test_codifica_int8(self):
        rng = np.random.default_rng(1)
        v = rng.normal(size=384).astype(np.float32)
        v /= np.linalg.norm(v)
        w = embed.decode(embed.encode(v))
        self.assertGreater(float(v @ w), 0.999)
        self.assertEqual(len(embed.encode(v)), 512)  # 384 byte in base64

    def test_ritaglio_con_la_fusoliera_orizzontale(self):
        # Una barra lunga 40 m inclinata di 30°: nel ritaglio deve risultare
        # orizzontale e centrata.
        img = np.full((600, 600, 3), 200, np.uint8)
        box = cv2.boxPoints(((300, 300), (40 / 0.3, 4 / 0.3), 30)).astype(np.int32)
        cv2.fillPoly(img, [box], (20, 20, 20))
        c = embed.crop(img, (300, 300), 30, 40, 0.3)
        self.assertEqual(c.shape, (embed.INPUT, embed.INPUT, 3))
        dark = c[..., 0] < 100
        ys, xs = np.nonzero(dark)
        self.assertGreater(np.ptp(xs), 4 * np.ptp(ys))
        self.assertAlmostEqual(xs.mean(), embed.INPUT / 2, delta=4)

    @unittest.skipUnless(embed.available(), "modello DINOv2 non installato")
    def test_stessa_forma_piu_simile_di_una_diversa(self):
        def plane(span, length):
            img = np.full((224, 224, 3), (180, 185, 190), np.uint8)
            cv2.rectangle(img, (112 - length // 2, 104), (112 + length // 2, 120), (230, 230, 230), -1)
            cv2.rectangle(img, (100, 112 - span // 2), (124, 112 + span // 2), (230, 230, 230), -1)
            return img
        a = embed.vector(plane(150, 160))
        b = embed.vector(cv2.GaussianBlur(plane(150, 160), (3, 3), 0))
        c = embed.vector(plane(60, 200))
        self.assertGreater(float(a @ b), float(a @ c))


if __name__ == "__main__":
    unittest.main()
