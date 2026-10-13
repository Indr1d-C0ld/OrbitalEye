"""Classificatore dei tipi MAR20: con un modello finto (il vero non è nel
repository), ordine dei tipi, media sugli orientamenti, solo aerei."""
import tempfile
import unittest
from pathlib import Path

import numpy as np

from . import _env  # noqa: F401
from app.core import embed, typeclass


class TypeClassTest(unittest.TestCase):
    NAMES = ["C-17 Globemaster III", "C-5 Galaxy", "KC-135 Stratotanker", "Tu-95"]

    def setUp(self):
        self._saved = (typeclass.MODEL_PATH, typeclass._model, typeclass._invalid)
        self.tmp = tempfile.TemporaryDirectory()
        # Quattro tipi: il punteggio di ciascuno è una componente del vettore.
        W = np.zeros((384, 4), np.float32)
        for k in range(4):
            W[k, k] = 40.0
        self.use(self.write("mar20-types.npz", W=W))

    def tearDown(self):
        typeclass.MODEL_PATH, typeclass._model, typeclass._invalid = self._saved
        self.tmp.cleanup()

    def write(self, name, **over):
        arrays = dict(W=np.zeros((384, 4), np.float32), b=np.zeros(4, np.float32), mu=np.zeros(384, np.float32),
                      sd=np.ones(384, np.float32), names=np.array(self.NAMES), embedding_model=np.array(embed.MODEL_NAME))
        arrays.update(over)
        path = Path(self.tmp.name) / name
        np.savez(path, **arrays)
        return path

    @staticmethod
    def use(path):
        typeclass.MODEL_PATH, typeclass._model, typeclass._invalid = path, None, None

    @staticmethod
    def vec(k):
        v = np.full(384, 0.01, np.float32)
        v[k] = 1.0
        return embed.encode(v / np.linalg.norm(v))

    def test_tipo_piu_probabile_primo(self):
        g = typeclass.classify([self.vec(1)])
        self.assertEqual(len(g), typeclass.TOP)
        self.assertEqual(g[0]["type"], "C-5 Galaxy")
        self.assertGreater(g[0]["p"], 0.9)
        self.assertGreaterEqual(g[1]["p"], g[2]["p"])

    def test_media_sugli_orientamenti(self):
        # Un orientamento dice C-17, l'altro KC-135: metà e metà.
        g = typeclass.classify([self.vec(0), self.vec(2)])
        self.assertAlmostEqual(g[0]["p"], 0.5, delta=0.02)
        self.assertEqual({g[0]["type"], g[1]["type"]}, {"C-17 Globemaster III", "KC-135 Stratotanker"})

    def test_solo_aerei_con_vettori(self):
        dets = [{"class": "plane"}, {"class": "helicopter"}, {"class": "plane"}]
        out = typeclass.classify_objects(dets, {0: [self.vec(0)], 1: [self.vec(1)], 2: []})
        self.assertEqual(list(out), [0])

    def test_senza_modello(self):
        self.use(Path(self.tmp.name) / "manca.npz")
        self.assertFalse(typeclass.available())
        self.assertEqual(typeclass.classify_objects([{"class": "plane"}], {0: [self.vec(0)]}), {})

    def test_modello_non_valido_come_assente(self):
        # Valori non finiti (addestramento divergente), dimensioni sbagliate,
        # vettori di un altro modello: niente proposte, il rilevamento resta.
        bad_w = np.zeros((384, 4), np.float32)
        bad_w[0, 0] = np.nan
        for path in (self.write("nan.npz", W=bad_w), self.write("dim.npz", W=np.zeros((10, 4), np.float32)),
                     self.write("altro.npz", embedding_model=np.array("dinov2-large"))):
            self.use(path)
            self.assertFalse(typeclass.available(), path.name)
            self.assertEqual(typeclass.classify_objects([{"class": "plane"}], {0: [self.vec(0)]}), {})


if __name__ == "__main__":
    unittest.main()
