PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS app_settings (
    key TEXT PRIMARY KEY,
    value TEXT
);

CREATE TABLE IF NOT EXISTS studies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    area_name TEXT,
    bbox_json TEXT,
    notes TEXT,
    status TEXT NOT NULL DEFAULT 'active',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS captures (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    study_id INTEGER NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
    label TEXT,
    source TEXT NOT NULL DEFAULT 'upload',
    capture_date TEXT,
    relative_path TEXT NOT NULL,
    width INTEGER,
    height INTEGER,
    meta_json TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS comparisons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    study_id INTEGER NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
    capture_a_id INTEGER NOT NULL REFERENCES captures(id) ON DELETE CASCADE,
    capture_b_id INTEGER NOT NULL REFERENCES captures(id) ON DELETE CASCADE,
    title TEXT,
    params_json TEXT,
    stats_json TEXT,
    regions_json TEXT,
    result_paths_json TEXT,
    registration_json TEXT,
    is_saved_to_library INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS annotations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    study_id INTEGER NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
    capture_id INTEGER REFERENCES captures(id) ON DELETE CASCADE,
    comparison_id INTEGER REFERENCES comparisons(id) ON DELETE CASCADE,
    target_image TEXT,
    shape_type TEXT NOT NULL DEFAULT 'rect',
    coords_json TEXT NOT NULL,
    color TEXT NOT NULL DEFAULT '#00fff2',
    label TEXT,
    notes TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_captures_study ON captures(study_id);
CREATE INDEX IF NOT EXISTS idx_comparisons_study ON comparisons(study_id);
CREATE INDEX IF NOT EXISTS idx_annotations_study ON annotations(study_id);
