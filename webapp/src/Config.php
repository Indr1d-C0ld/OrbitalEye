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

    public static function storageRoot(): string
    {
        return rtrim(self::get()['storage_root'], '/');
    }
}
