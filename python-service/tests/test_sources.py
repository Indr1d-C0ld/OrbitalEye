"""Fonti: passaggi Copernicus e tasselli Wayback (senza rete)."""
import unittest
from datetime import datetime, timezone

from . import _env  # noqa: F401
from app.core import sentinelhub_client as sh
from app.core import wayback_client as wb


def feature(dt, pid, platform="sentinel-2b", cloud=10.0):
    return {"id": pid, "properties": {"datetime": dt, "platform": platform, "eo:cloud_cover": cloud}}


class PassesTest(unittest.TestCase):
    def test_tasselli_dello_stesso_passaggio_raggruppati(self):
        feats = [
            feature("2026-09-30T10:00:30Z", "S2A_MSIL2A_20260930T095041_N0513_R079_T33SVB_X"),
            feature("2026-09-30T10:00:34Z", "S2A_MSIL2A_20260930T095041_N0513_R079_T33SWB_X"),
            feature("2026-09-30T09:50:23Z", "S2B_MSIL2A_20260930T094029_N0513_R036_T33SVB_X"),
        ]
        groups = sh._group_passes(feats)
        self.assertEqual(len(groups), 2)  # due orbite a 10 minuti, non una
        self.assertEqual(groups[0]["first"], datetime(2026, 9, 30, 10, 0, 30, tzinfo=timezone.utc))
        self.assertEqual(sh._relative_orbit(groups[0]["props"][0]), 79)

    def test_statistiche_con_nan_testuale(self):
        data = {"data": [
            {"interval": {"from": "2026-09-30T00:00:00Z"}, "outputs": {"cloud": {"bands": {"B0": {"stats": {"mean": "NaN", "sampleCount": 10, "noDataCount": 2}}}}}},
            {"interval": {"from": "2026-10-01T00:00:00Z"}, "outputs": {"cloud": {"bands": {"B0": {"stats": {"mean": 0.25, "sampleCount": 10, "noDataCount": 0}}}}}},
            {"interval": {"from": "2026-10-02T00:00:00Z"}, "outputs": {"cloud": {"bands": {"B0": {"stats": {"mean": "NaN", "sampleCount": 10, "noDataCount": 10}}}}}},
        ]}
        out = sh._parse_stats(data)
        self.assertIsNone(out["2026-09-30"]["aoi_cloud"])
        self.assertAlmostEqual(out["2026-09-30"]["aoi_coverage"], 0.8)
        self.assertEqual(out["2026-10-01"]["aoi_cloud"], 0.25)
        self.assertNotIn("2026-10-02", out)  # nessun pixel valido

    def test_intervallo_troppo_ampio_rifiutato(self):
        with self.assertRaises(sh.SentinelHubError):
            sh._check_range("2024-01-01", "2026-01-01")


class WaybackTest(unittest.TestCase):
    def test_tassello_noto(self):
        # Sigonella al livello 17: tassello 70965 / 50830 (verificato sul servizio).
        self.assertEqual(int(wb._lon_to_x(14.9138, 17)), 70965)
        self.assertEqual(int(wb._lat_to_y(37.4075, 17)), 50830)

    def test_livello_cresce_con_il_dettaglio(self):
        bbox = [14.90, 37.40, 14.92, 37.41]
        self.assertLess(wb._zoom_for(bbox, 256, 256), wb._zoom_for(bbox, 2048, 2048))
        self.assertLessEqual(wb._zoom_for(bbox, 20000, 20000), wb.MAX_ZOOM)


if __name__ == "__main__":
    unittest.main()
