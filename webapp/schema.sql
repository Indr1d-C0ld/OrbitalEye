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

-- Punti di controllo per l'allineamento manuale (fallback assistito quando il
-- motore automatico ORB+ECC non trova corrispondenze affidabili, tipico tra
-- fonti visivamente molto diverse come Esri World Imagery e Sentinel Hub).
-- Legati alla COPPIA di riprese (non al singolo confronto): restano
-- disponibili e riutilizzabili per qualunque confronto futuro sulle stesse
-- due immagini. points_json: array di {ax, ay, bx, by} in pixel
-- dell'immagine originale (non ridimensionata) di ciascuna ripresa.
CREATE TABLE IF NOT EXISTS manual_control_points (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    capture_a_id INTEGER NOT NULL REFERENCES captures(id) ON DELETE CASCADE,
    capture_b_id INTEGER NOT NULL REFERENCES captures(id) ON DELETE CASCADE,
    points_json TEXT NOT NULL DEFAULT '[]',
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(capture_a_id, capture_b_id)
);

-- Scaricamento automatico a intervalli regolari per una data area/fonte di
-- uno studio (vedi src/ScheduledDownload.php + cli/run_scheduled_downloads.php,
-- eseguito da cron). params_json contiene tutto il necessario per rieseguire
-- lo stesso fetch fatto a mano (bbox, rotation, width/height, e per Sentinel
-- Hub anche date_from_days_ago/date_to_days_ago: una finestra "scorrevole"
-- rispetto ad "adesso", non date fisse, altrimenti ogni esecuzione futura
-- richiederebbe le stesse identiche date già passate). last_result: 'new'
-- (ripresa diversa dalla precedente, tenuta) | 'duplicate' (scartata
-- automaticamente) | 'error' (fetch fallito, vedi last_error).
CREATE TABLE IF NOT EXISTS scheduled_downloads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    study_id INTEGER NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
    source TEXT NOT NULL,
    params_json TEXT NOT NULL,
    interval_days INTEGER NOT NULL DEFAULT 1,
    duplicate_threshold REAL NOT NULL DEFAULT 0.005,
    is_active INTEGER NOT NULL DEFAULT 1,
    last_run_at TEXT,
    last_capture_id INTEGER REFERENCES captures(id) ON DELETE SET NULL,
    last_result TEXT,
    last_error TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Notifiche "nuova ripresa diversa dalla precedente rilevata" generate dallo
-- scaricamento automatico (vedi sopra). Puramente interne alla piattaforma
-- (nessun invio email): un'icona con badge nella barra laterale + una
-- pagina dedicata (alerts.php) le mostrano appena l'utente riapre l'app.
CREATE TABLE IF NOT EXISTS alerts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    study_id INTEGER NOT NULL REFERENCES studies(id) ON DELETE CASCADE,
    capture_id INTEGER REFERENCES captures(id) ON DELETE CASCADE,
    schedule_id INTEGER REFERENCES scheduled_downloads(id) ON DELETE SET NULL,
    message TEXT NOT NULL,
    is_read INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- Registro delle condivisioni esterne (vedi src/TelegramClient.php,
-- public/api/share.php): un analista che pubblica materiale d'analisi
-- verso l'esterno (Telegram, o l'apertura della finestra di composizione
-- X/Twitter) deve poter sapere in seguito COSA ha reso pubblico e quando —
-- coerente con l'attenzione OPSEC già seguita per la ricerca inversa per
-- immagini. kind: 'capture' | 'comparison' | 'study'. platform: 'telegram'
-- | 'twitter' (per Twitter, che non ha un invio server-side in questa
-- piattaforma, registrato comunque al momento dell'apertura della finestra
-- di composizione, per completezza del registro).
CREATE TABLE IF NOT EXISTS shares (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    study_id INTEGER REFERENCES studies(id) ON DELETE SET NULL,
    kind TEXT NOT NULL,
    ref_id INTEGER,
    platform TEXT NOT NULL,
    caption TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_captures_study ON captures(study_id);
CREATE INDEX IF NOT EXISTS idx_comparisons_study ON comparisons(study_id);
CREATE INDEX IF NOT EXISTS idx_annotations_study ON annotations(study_id);
CREATE INDEX IF NOT EXISTS idx_manual_cp_pair ON manual_control_points(capture_a_id, capture_b_id);
CREATE INDEX IF NOT EXISTS idx_scheduled_downloads_study ON scheduled_downloads(study_id);
CREATE INDEX IF NOT EXISTS idx_scheduled_downloads_active ON scheduled_downloads(is_active);
CREATE INDEX IF NOT EXISTS idx_alerts_study ON alerts(study_id);
CREATE INDEX IF NOT EXISTS idx_alerts_unread ON alerts(is_read);
CREATE INDEX IF NOT EXISTS idx_shares_study ON shares(study_id);
