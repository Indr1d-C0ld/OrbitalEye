"""Misure dello scafo su navi sintetiche: acqua scura, scafo grigio, ombra,
banchina, nave affiancata."""
import unittest

import cv2
import numpy as np

from . import _env  # noqa: F401
from app.core import hull

S = 0.3  # metri per pixel


def scene(length_m=150.0, beam_m=20.0, angle=20.0, pier=False, shadow=False, neighbour=False,
          bow=False, hull_color=(130, 130, 125), box_k=1.15, box_shift_m=0.0):
    img = np.zeros((1400, 1400, 3), np.uint8)
    img[:] = (60, 50, 20)  # acqua (BGR) scura
    rng = np.random.default_rng(3)
    img = np.clip(img.astype(int) + rng.normal(0, 3, img.shape), 0, 255).astype(np.uint8)
    c = (700, 700)
    L, B = length_m / S, beam_m / S
    a = np.radians(angle)
    n = np.array([-np.sin(a), np.cos(a)])  # normale all'asse
    if shadow:
        sh = cv2.boxPoints(((c[0] + 12 * n[0], c[1] + 12 * n[1]), (L, B), angle)).astype(np.int32)
        cv2.fillPoly(img, [sh], (20, 15, 5))
    if pier:
        # Banchina lungo un fianco, più lunga della nave.
        pc = (c[0] + n[0] * (B / 2 + 60), c[1] + n[1] * (B / 2 + 60))
        cv2.fillPoly(img, [cv2.boxPoints((pc, (L * 1.6, 120), angle)).astype(np.int32)], (150, 160, 165))
    if neighbour:
        nc = (c[0] + n[0] * B * 1.02, c[1] + n[1] * B * 1.02)
        cv2.fillPoly(img, [cv2.boxPoints((nc, (L * 0.9, B), angle)).astype(np.int32)], (125, 125, 120))
    u = np.array([np.cos(a), np.sin(a)])  # asse
    if bow:
        # Ultimo 20% affusolato fino alla punta.
        body = [c - u * L / 2 + n * B / 2, c + u * L * 0.3 + n * B / 2, c + u * L / 2, c + u * L * 0.3 - n * B / 2, c - u * L / 2 - n * B / 2]
        cv2.fillPoly(img, [np.array(body).astype(np.int32)], hull_color)
    else:
        cv2.fillPoly(img, [cv2.boxPoints((c, (L, B), angle)).astype(np.int32)], hull_color)
    # Riquadro del rilevamento: un po' più grande dello scafo, eventualmente
    # spostato di traverso.
    bc = (c[0] + n[0] * box_shift_m / S, c[1] + n[1] * box_shift_m / S)
    det = {"class": "ship", "polygon": cv2.boxPoints((bc, (L * 1.05, B * box_k), angle)).tolist()}
    return img, det


class HullTest(unittest.TestCase):
    def test_nave_isolata(self):
        img, det = scene()
        r = hull.analyze(img, [det], S, S)[0]
        self.assertEqual(r["quality"], "ok", r)
        self.assertAlmostEqual(r["length_m"], 150, delta=150 * 0.03)
        self.assertAlmostEqual(r["beam_m"], 20, delta=2)
        self.assertGreater(len(r["outline"]), 3)

    def test_ombra_sull_acqua_esclusa(self):
        img, det = scene(shadow=True)
        r = hull.analyze(img, [det], S, S)[0]
        self.assertEqual(r["quality"], "ok", r)
        self.assertAlmostEqual(r["beam_m"], 20, delta=2)

    def test_banchina_su_un_lato(self):
        # La banchina più lunga della nave non allunga la misura; la
        # larghezza, con la banchina lungo tutto lo scafo, resta ignota.
        for shift in (0.0, 4.0, -4.0):
            img, det = scene(pier=True, box_shift_m=shift)
            r = hull.analyze(img, [det], S, S)[0]
            self.assertEqual(r["quality"], "media", (shift, r))
            self.assertAlmostEqual(r["length_m"], 150, delta=150 * 0.03)
            self.assertIsNone(r["beam_m"])

    def test_prua_affusolata(self):
        # La punta occupa poco della fascia centrale: la lunghezza la include
        # anche con un riquadro largo (sulle riprese vere 1–1,3 volte lo scafo).
        for k in (1.15, 1.5, 2.0):
            img, det = scene(bow=True, box_k=k)
            r = hull.analyze(img, [det], S, S)[0]
            self.assertAlmostEqual(r["length_m"], 150, delta=150 * 0.04, msg=(k, r))

    def test_scafo_scuro(self):
        # Più scuro dell'acqua (un sottomarino): misura indicativa.
        img, det = scene(length_m=110, beam_m=12, hull_color=(15, 15, 15))
        r = hull.analyze(img, [det], S, S)[0]
        self.assertEqual(r["quality"], "media", r)
        self.assertAlmostEqual(r["length_m"], 110, delta=110 * 0.04)

    def test_navi_affiancate_non_ok(self):
        img, det = scene(neighbour=True)
        r = hull.analyze(img, [det], S, S)[0]
        self.assertNotEqual(r["quality"], "ok", r)

    def test_barche_e_scala_grossolana_ignorate(self):
        img, det = scene(length_m=12, beam_m=4)
        self.assertEqual(hull.analyze(img, [det], S, S), {})
        img, det = scene()
        self.assertEqual(hull.analyze(img, [det], 2.0, 2.0), {})


if __name__ == "__main__":
    unittest.main()
