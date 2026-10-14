-- Zone di conteggio di uno studio (vedi StudyZone): poligoni geografici,
-- creati da un'annotazione, in cui lo storico dell'area conta aerei, navi
-- e veicoli rilevati in ogni ripresa (un piazzale, una banchina, un deposito).
CREATE TABLE IF NOT EXISTS study_zones (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    study_id INTEGER NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    polygon_json TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_study_zones_study ON study_zones(study_id);
