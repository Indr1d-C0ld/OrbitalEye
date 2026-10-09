"""Regioni di cambiamento: aree in pixel coerenti con il totale."""
import unittest

import numpy as np

from . import _env  # noqa: F401
from app.core.diff import find_change_regions


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


if __name__ == "__main__":
    unittest.main()
