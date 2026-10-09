<?php

final class Database
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo === null) {
            $config = Config::get();
            $dbPath = $config['db_path'];
            $isNew = !file_exists($dbPath);

            @mkdir(dirname($dbPath), 0770, true);

            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $pdo->exec('PRAGMA foreign_keys = ON;');

            self::$pdo = $pdo;

            if ($isNew) {
                self::applySchema();
            } else {
                self::ensureSchema();
            }
            self::migrate();
        }

        return self::$pdo;
    }

    private static function applySchema(): void
    {
        $schema = file_get_contents(__DIR__ . '/../schema.sql');
        self::$pdo->exec($schema);
    }

    /**
     * Riallinea lo schema di un database già esistente.
     *
     * Lo schema è interamente idempotente (CREATE TABLE/INDEX IF NOT EXISTS),
     * ma rieseguirlo per intero a OGNI richiesta significa rileggere il file
     * ed eseguire una ventina di statement anche solo per mostrare una
     * pagina. Lo si fa quindi solo quando il file dello schema è cambiato
     * rispetto all'ultima volta, tenendone traccia nel database stesso.
     *
     * "IF NOT EXISTS" crea tabelle nuove ma non aggiunge colonne a tabelle
     * già esistenti: per quello ci sono le migrazioni (vedi migrate()).
     */
    private static function ensureSchema(): void
    {
        $schemaFile = __DIR__ . '/../schema.sql';
        $fingerprint = (string) @filemtime($schemaFile) . ':' . (string) @filesize($schemaFile);

        try {
            $stmt = self::$pdo->query("SELECT value FROM app_settings WHERE key = '_schema_fingerprint'");
            if ($stmt && $stmt->fetchColumn() === $fingerprint) {
                return; // schema già allineato a questa versione del file
            }
        } catch (PDOException $e) {
            // app_settings non esiste ancora (database pre-esistente creato
            // prima di questa tabella): si prosegue applicando lo schema.
        }

        self::applySchema();

        $stmt = self::$pdo->prepare(
            "INSERT INTO app_settings (key, value) VALUES ('_schema_fingerprint', :v)
             ON CONFLICT(key) DO UPDATE SET value = :v"
        );
        $stmt->execute([':v' => $fingerprint]);
    }

    /**
     * Migrazioni numerate (migrations/NNN_descrizione.sql), applicate una
     * volta sola, in ordine, ciascuna in una transazione e registrata in
     * schema_migrations. È il posto per ciò che schema.sql non può fare da
     * solo — aggiungere colonne, trasformare dati — su database già in uso.
     *
     * Costo a regime: l'elenco dei file e una lettura di schema_migrations,
     * senza aprire i file.
     */
    private static function migrate(): void
    {
        $files = glob(__DIR__ . '/../migrations/[0-9][0-9][0-9]_*.sql') ?: [];
        self::$pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                version TEXT PRIMARY KEY,
                applied_at TEXT NOT NULL DEFAULT (datetime('now'))
            )"
        );
        $applied = self::$pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        // Confronto per nome, non per numero: un file rinominato o rimosso
        // non deve far saltare una migrazione nuova.
        $pending = array_diff(array_map(fn($f) => basename($f, '.sql'), $files), $applied);
        if (!$pending) {
            return;
        }
        sort($pending);
        $dir = __DIR__ . '/../migrations/';
        foreach ($pending as $version) {
            // BEGIN IMMEDIATE prende subito il lock di scrittura: due richieste
            // arrivate insieme dopo un aggiornamento la applicano una volta
            // sola — la seconda, ottenuto il lock, la trova già registrata.
            self::$pdo->exec('BEGIN IMMEDIATE');
            try {
                $check = self::$pdo->prepare('SELECT 1 FROM schema_migrations WHERE version = :v');
                $check->execute([':v' => $version]);
                if (!$check->fetchColumn()) {
                    self::$pdo->exec((string) file_get_contents($dir . $version . '.sql'));
                    self::$pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:v)')->execute([':v' => $version]);
                }
                self::$pdo->exec('COMMIT');
            } catch (Throwable $e) {
                // SQLite può aver già annullato la transazione da sé (disco
                // pieno, I/O): un ROLLBACK fallito nasconderebbe l'errore vero.
                if (self::$pdo->inTransaction()) {
                    self::$pdo->exec('ROLLBACK');
                }
                // Una migrazione fallita blocca l'applicazione invece di farla
                // funzionare a metà su uno schema inconsistente.
                throw new RuntimeException("Migrazione del database $version non riuscita: " . $e->getMessage(), 0, $e);
            }
        }
    }
}
