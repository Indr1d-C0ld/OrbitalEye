<?php

final class Config
{
    private static ?array $values = null;

    public static function get(): array
    {
        if (self::$values === null) {
            $path = __DIR__ . '/../config/config.php';
            if (!file_exists($path)) {
                throw new RuntimeException(
                    'config/config.php mancante. Copia config/config.example.php in config/config.php e configuralo.'
                );
            }
            self::$values = require $path;
        }
        return self::$values;
    }

    /** Configurazione esplicita, senza config.php: per i test (tests/run.php). */
    public static function set(array $values): void
    {
        self::$values = $values;
    }

    public static function storageRoot(): string
    {
        return rtrim(self::get()['storage_root'], '/');
    }
}
