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

    private static function ensureSchema(): void
    {
        // idempotent: CREATE TABLE IF NOT EXISTS / CREATE INDEX IF NOT EXISTS
        self::applySchema();
    }
}
