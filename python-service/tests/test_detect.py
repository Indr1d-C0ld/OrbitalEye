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

    def test_nave_dentro_un_porto_non_soppressa(self):
        # Il porto (area) contiene la nave: non è un suo frammento, e viceversa.
        ship_c = [n for n, _ in detect.CLASSES].index("ship")
        harbor_c = [n for n, _ in detect.CLASSES].index("harbor")
        plane_c = [n for n, _ in detect.CLASSES].index("plane")
        harbor = {"rect": ((300.0, 300.0), (400.0, 300.0), 0.0), "confidence": 0.95, "cls": harbor_c}
        ship = {"rect": ((300.0, 300.0), (150.0, 30.0), 0.0), "confidence": 0.7, "cls": ship_c}
        # Fra oggetti di classi diverse il frammento si toglie ancora.
        piece = {"rect": ((280.0, 300.0), (80.0, 30.0), 0.0), "confidence": 0.5, "cls": plane_c}
        kept = detect._suppress_fragments([harbor, ship, piece])
        self.assertEqual(kept, [harbor, ship])

    def test_oggetto_grande_al_posto_dei_pezzi(self):
        ship_c = [n for n, _ in detect.CLASSES].index("ship")
        big = {"rect": ((500.0, 300.0), (600.0, 60.0), 0.0), "confidence": 0.6, "cls": ship_c}
        # Due pezzi della nave nella passata normale, più una barca lontana.
        a = {"rect": ((330.0, 300.0), (260.0, 60.0), 0.0), "confidence": 0.8, "cls": ship_c}
        b = {"rect": ((670.0, 300.0), (260.0, 60.0), 0.0), "confidence": 0.7, "cls": ship_c}
        boat = {"rect": ((100.0, 900.0), (30.0, 10.0), 0.0), "confidence": 0.9, "cls": ship_c}
        # I pezzi arrivano al bordo fra due tasselli (x = 460 e 540).
        edges = ([460.0, 540.0], [])
        merged, added = detect._merge_large([a, b, boat], [big], edges)
        self.assertEqual(added, 1)
        self.assertEqual(sorted(d["confidence"] for d in merged), [0.6, 0.9])
        # Senza bordi vicini e più sicuri del grande non sono pezzi: restano.
        merged, added = detect._merge_large([a, b, boat], [big])
        self.assertEqual(sorted(d["confidence"] for d in merged), [0.6, 0.7, 0.8, 0.9])
        # Una barca affiancata, stretta, resta anche se meno sicura del grande.
        tug = {"rect": ((500.0, 315.0), (40.0, 12.0), 0.0), "confidence": 0.5, "cls": ship_c}
        merged, _ = detect._merge_large([tug], [big], edges)
        self.assertIn(tug, merged)
        # Un riquadro grande poco sicuro (barche fuse in una "nave") è ignorato.
        weak = dict(big, confidence=0.2)
        self.assertEqual(detect._merge_large([a, b], [weak], edges), ([a, b], 0))
        # La passata normale ha già la nave intera: il riquadro grande, più
        # grossolano, non serve.
        whole = {"rect": ((500.0, 300.0), (590.0, 58.0), 0.0), "confidence": 0.8, "cls": ship_c}
        merged, added = detect._merge_large([whole, boat], [big], edges)
        self.assertEqual(added, 0)
        self.assertEqual(merged, [whole, boat])

    def test_bordi_interni_dei_tasselli(self):
        self.assertEqual(detect._inner_edges(1000), [])
        self.assertEqual(detect._inner_edges(2000), [640, 976, 1024, 1664])

    def test_passata_ridotta_solo_se_riduce_ed_entro_il_tetto(self):
        # 0,3 m/pixel, 2500 pixel: riduzione a 1 m (750 pixel, un tassello).
        self.assertEqual(detect._large_plan(2500, 2000, 2500, 2000, 0.3, 1.0), (750, 600))
        # Un tassello solo: niente da spezzare.
        self.assertIsNone(detect._large_plan(1000, 900, 1000, 900, 0.3, 1.0))
        # Scala ignota.
        self.assertIsNone(detect._large_plan(2500, 2000, 2500, 2000, None, 1.0))
        # 0,85 m/pixel: la riduzione sarebbe minima (0,85), non vale il doppio lavoro.
        self.assertIsNone(detect._large_plan(3584, 3584, 3584, 3584, 0.85, 1.0))
        # Oltre 1 m/pixel non si ingrandisce mai: con l'ingrandimento per gli
        # oggetti piccoli (1,7 m → ×2) la passata ridotta coinciderebbe con l'originale.
        self.assertIsNone(detect._large_plan(1750, 1750, 3500, 3500, 1.7, 2.0))
        # Il lavoro totale resta entro MAX_TILES.
        self.assertIsNone(detect._large_plan(5000, 4000, 5000, 4000, 0.9, 1.0))

    def test_immagine_enorme_rifiutata(self):
        import numpy as np
        with self.assertRaises(ValueError):
            detect.detect(np.zeros((6000, 6000, 3), np.uint8), None)


if __name__ == "__main__":
    unittest.main()
