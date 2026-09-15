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
     * Attenzione, limite noto: "IF NOT EXISTS" crea tabelle nuove ma NON
     * aggiunge colonne a tabelle già esistenti. Se in futuro servisse una
     * colonna nuova, va aggiunta qui una migrazione esplicita (ALTER TABLE
     * condizionale) — lo schema da solo non basterebbe e il problema si
     * manifesterebbe solo a runtime.
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
}
