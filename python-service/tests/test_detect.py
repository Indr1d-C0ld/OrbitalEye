"""Rilevamento: suddivisione in tasselli e soppressione dei frammenti
(senza il modello: solo la logica di contorno)."""
import unittest

from . import _env  # noqa: F401
from app.core import detect


class TilesTest(unittest.TestCase):
    def test_tasselli_coprono_tutta_l_immagine_con_sovrapposizione(self):
        for size in (500, 1024, 1025, 2048, 2500):
            origins = detect._tile_origins(size)
            self.assertEqual(origins[0], 0)
            self.assertEqual(origins[-1] + min(size, detect.INPUT), size)
            for a, b in zip(origins, origins[1:]):
                self.assertGreaterEqual(a + detect.INPUT - b, detect.OVERLAP)

    def test_frammento_di_un_oggetto_soppresso(self):
        full = {"rect": ((100.0, 100.0), (80.0, 60.0), 0.0), "confidence": 0.9}
        fragment = {"rect": ((80.0, 100.0), (40.0, 60.0), 0.0), "confidence": 0.5}
        other = {"rect": ((400.0, 400.0), (80.0, 60.0), 0.0), "confidence": 0.6}
        kept = detect._suppress_fragments([full, other, fragment])
        self.assertEqual(kept, [full, other])

    def test_immagine_enorme_rifiutata(self):
        import numpy as np
        with self.assertRaises(ValueError):
            detect.detect(np.zeros((6000, 6000, 3), np.uint8), None)


if __name__ == "__main__":
    unittest.main()
