"""Sagoma dei velivoli: apertura e lunghezza vere, con l'ombra tolta."""
import unittest

import cv2
import numpy as np

from . import _env  # noqa: F401
from app.core import silhouette as sil

S = 0.3  # metri per pixel del disegno (pixel quadrati)


def plane_mask(shape, cx, cy, span_m, length_m, angle_deg):
    """Sagoma schematica vista dall'alto: fusoliera, ali, piano di coda."""
    m = np.zeros(shape, np.uint8)
    L, B = length_m / S, span_m / S
    parts = [
        ((0, 0), (L, 0.11 * B)),               # fusoliera
        ((0.05 * L, 0), (0.18 * L, B)),        # ala
        ((-0.42 * L, 0), (0.08 * L, 0.38 * B)),  # piano di coda
    ]
    for (ox, oy), (w, h) in parts:
        a = np.radians(angle_deg)
        c = (cx + ox * np.cos(a) - oy * np.sin(a), cy + ox * np.sin(a) + oy * np.cos(a))
        box = cv2.boxPoints((c, (w, h), angle_deg)).astype(np.int32)
        cv2.fillPoly(m, [box], 255)
    return m > 0


def scene(planes, shadow_px=(-12, -16), size=(900, 900), seed=5):
    """Piazzale chiaro con trama, aerei grigio chiaro, ombre scure spostate
    tutte dello stesso vettore (un solo sole)."""
    rng = np.random.default_rng(seed)
    img = np.full(size + (3,), (190, 196, 200), np.uint8)
    noise = cv2.GaussianBlur(rng.normal(0, 6, size + (3,)), (0, 0), 2)
    img = np.clip(img + noise, 0, 255).astype(np.uint8)
    masks = [plane_mask(size, *p) for p in planes]
    M = np.float32([[1, 0, shadow_px[0]], [0, 1, shadow_px[1]]])
    for m in masks:
        sh = cv2.warpAffine(m.astype(np.uint8), M, size[::-1]) > 0
        img[sh] = (95, 98, 100)
    for m in masks:
        img[m] = (215, 212, 205)
    return img


def detection(cx, cy, span_m, length_m, angle_deg, extra=1.25):
    """Riquadro come lo darebbe il rilevatore: più grande della sagoma."""
    w, h = length_m / S * extra, span_m / S * extra
    pts = cv2.boxPoints(((cx, cy), (w, h), angle_deg))
    return {"class": "plane", "polygon": pts.tolist()}


class SilhouetteTest(unittest.TestCase):
    PLANES = [
        (220, 220, 42.4, 45.1, 20),   # tipo A400M
        (620, 260, 42.4, 45.1, -35),
        (300, 640, 60.3, 58.8, 70),   # tipo A330
        (680, 650, 51.8, 53.0, 5),    # tipo C-17
    ]

    def _run(self, mpp_x, mpp_y):
        img = scene(self.PLANES)
        dets = [detection(*p) for p in self.PLANES]
        # Pixel non quadrati, come le riprese in gradi.
        fx, fy = S / mpp_x, S / mpp_y
        img = cv2.resize(img, (round(img.shape[1] * fx), round(img.shape[0] * fy)), interpolation=cv2.INTER_AREA)
        for d in dets:
            d["polygon"] = [[x * fx, y * fy] for x, y in d["polygon"]]
        return sil.analyze(img, dets, mpp_x, mpp_y)

    def test_apertura_e_lunghezza_al_loro_posto(self):
        r = self._run(0.3, 0.45)
        self.assertIsNotNone(r["shadow_m"])
        for i, (_cx, _cy, span, length, _a) in enumerate(self.PLANES):
            o = r["objects"][i]
            self.assertEqual(o["quality"], "ok", o.get("reason"))
            self.assertAlmostEqual(o["span_m"] / span, 1.0, delta=0.07, msg=f"apertura dell'aereo {i}")
            self.assertAlmostEqual(o["length_m"] / length, 1.0, delta=0.08, msg=f"lunghezza dell'aereo {i}")
            self.assertGreater(len(o["outline"]), 4)

    def test_ombra_stimata(self):
        r = self._run(0.3, 0.3)
        dx, dy = r["shadow_m"]
        self.assertAlmostEqual(dx, -12 * S, delta=1.0)
        self.assertAlmostEqual(dy, -16 * S, delta=1.0)

    def test_aereo_tagliato_dal_bordo(self):
        # Un terzo dell'aereo fuori dall'immagine: la parte visibile è ancora
        # simmetrica, ma la lunghezza sarebbe sbagliata.
        img = scene([(150, 300, 42.4, 45.1, 0)], size=(600, 600))
        d = detection(150, 300, 42.4, 45.1, 0)
        cut = img[:, 100:]
        d["polygon"] = [[x - 100, y] for x, y in d["polygon"]]
        r = sil.analyze(cut, [d], 0.3, 0.3)
        self.assertEqual(r["objects"][0]["quality"], "bassa")
        self.assertIn("bordo", r["objects"][0]["reason"])

    def test_macchia_non_attendibile(self):
        img = np.full((500, 500, 3), (190, 196, 200), np.uint8)
        cv2.ellipse(img, (250, 250), (90, 70), 0, 0, 360, (60, 140, 60), -1)
        r = sil.analyze(img, [detection(250, 250, 45, 50, 0, extra=1.0)], 0.3, 0.3)
        self.assertEqual(r["objects"][0]["quality"], "bassa")

    def test_senza_scala_o_troppo_grossolana(self):
        img = scene(self.PLANES[:1])
        dets = [detection(*self.PLANES[0])]
        self.assertEqual(sil.analyze(img, dets, None, None)["objects"], {})
        self.assertEqual(sil.analyze(img, dets, 2.0, 2.0)["objects"], {})


if __name__ == "__main__":
    unittest.main()
