-- Lavori in background con avanzamento (vedi src/Job.php, cli/run_job.php):
-- scaricamenti, confronti e rilevamenti girano in un processo separato,
-- e la pagina ne segue l'avanzamento invece di restare bloccata in attesa
-- di una richiesta che poteva durare minuti.
-- status: 'queued' | 'running' | 'done' | 'error'.
CREATE TABLE IF NOT EXISTS jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL,
    study_id INTEGER REFERENCES studies(id) ON DELETE CASCADE,
    params_json TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'queued',
    progress INTEGER NOT NULL DEFAULT 0,
    message TEXT,
    result_json TEXT,
    error TEXT,
    pid INTEGER,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    started_at TEXT,
    finished_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_jobs_study ON jobs(study_id);
CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs(status);
