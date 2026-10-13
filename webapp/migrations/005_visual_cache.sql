-- Cache dei suggerimenti della libreria visiva per rilevamento (vedi
-- VisualLibrary::annotate): si ricalcolano solo quando cambiano i
-- riferimenti, non a ogni apertura della pagina di analisi.
ALTER TABLE detections ADD COLUMN visual_json TEXT;
