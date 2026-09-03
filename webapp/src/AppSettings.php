<?php

final class AppSettings
{
    private const DEFAULTS = [
        'default_diff_method' => 'ssim',
        'default_threshold' => '30',
        'default_use_otsu' => '0',
        'default_morph_kernel' => '3',
        'default_open_iterations' => '1',
        'default_close_iterations' => '2',
        'default_min_blob_area' => '40',
        'default_overlay_alpha' => '0.35',
        'sentinelhub_client_id' => '',
        'sentinelhub_client_secret' => '',
        'esri_api_key' => '',
        'telegram_bot_token' => '',
        'telegram_chat_id' => '',
    ];

    public static function all(): array
    {
        $stmt = Database::get()->query('SELECT key, value FROM app_settings');
        $stored = [];
        foreach ($stmt->fetchAll() as $row) {
            $stored[$row['key']] = $row['value'];
        }
        return array_merge(self::DEFAULTS, $stored);
    }

    public static function get(string $key)
    {
        return self::all()[$key] ?? (self::DEFAULTS[$key] ?? null);
    }

    public static function set(string $key, string $value): void
    {
        $stmt = Database::get()->prepare(
            'INSERT INTO app_settings (key, value) VALUES (:k, :v)
             ON CONFLICT(key) DO UPDATE SET value = :v'
        );
        $stmt->execute([':k' => $key, ':v' => $value]);
    }

    public static function setMany(array $values): void
    {
        foreach ($values as $k => $v) {
            self::set($k, (string) $v);
        }
    }

    /** Scrive le credenziali Sentinel Hub nel file letto dal servizio Python. */
    public static function syncSentinelHubCredentialsFile(): void
    {
        $settings = self::all();
        $dir = Config::storageRoot() . '/config';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $payload = [
            'client_id' => $settings['sentinelhub_client_id'],
            'client_secret' => $settings['sentinelhub_client_secret'],
        ];
        file_put_contents($dir . '/sentinelhub_credentials.json', json_encode($payload));
        chmod($dir . '/sentinelhub_credentials.json', 0640);
    }

    /** Scrive l'eventuale API key Esri (opzionale) nel file letto dal servizio Python. */
    public static function syncEsriCredentialsFile(): void
    {
        $settings = self::all();
        $dir = Config::storageRoot() . '/config';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        file_put_contents($dir . '/esri_credentials.json', json_encode(['api_key' => $settings['esri_api_key']]));
        chmod($dir . '/esri_credentials.json', 0640);
    }
}
