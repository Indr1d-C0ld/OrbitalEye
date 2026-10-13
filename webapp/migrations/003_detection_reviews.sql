-- Verifica dei rilevamenti da parte dell'analista (vedi src/DetectionReview.php
-- e src/DetectionQuality.php): ogni oggetto confermato (con la classe vera e,
-- per i velivoli, il tipo) o scartato, più gli oggetti che il rilevatore non
-- ha trovato. Sono la "verità" con cui si misura quanto sbaglia il
-- rilevamento e quanto è giusto il tipo proposto.
--
-- La posizione (frazioni della ripresa) e non il numero dell'oggetto: un
-- rilevamento rieseguito rinumera gli oggetti, la verifica resta.
-- verdict: 'confirmed' | 'rejected'; source: 'detection' | 'manual' (oggetto
-- non rilevato, aggiunto dall'analista).
CREATE TABLE IF NOT EXISTS detection_reviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    capture_id INTEGER NOT NULL REFERENCES captures(id) ON DELETE CASCADE,
    study_id INTEGER NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
    verdict TEXT NOT NULL,
    class TEXT,
    type_name TEXT,
    center_x REAL NOT NULL,
    center_y REAL NOT NULL,
    radius REAL NOT NULL,
    polygon_json TEXT,
    source TEXT NOT NULL DEFAULT 'detection',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_detection_reviews_capture ON detection_reviews(capture_id);

-- Ripresa verificata per intero (ogni rilevamento giudicato e gli oggetti
-- mancanti aggiunti): solo su queste si può contare ciò che è sfuggito.
CREATE TABLE IF NOT EXISTS capture_reviews (
    capture_id INTEGER PRIMARY KEY REFERENCES captures(id) ON DELETE CASCADE,
    complete INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
