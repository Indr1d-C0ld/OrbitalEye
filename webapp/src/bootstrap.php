<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

// Fuso orario dell'applicazione: influenza date() e i confronti/formattazioni
// fatti da qui in poi (il server può girare in UTC — è il default di questo
// PHP — ma l'interfaccia deve mostrare sempre l'ora italiana).
date_default_timezone_set('Europe/Rome');

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
]);

spl_autoload_register(function (string $class) {
    $path = __DIR__ . '/' . $class . '.php';
    if (file_exists($path)) {
        require $path;
    }
});

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function respond_json(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function storage_url(string $relativePath): string
{
    return 'media.php?path=' . urlencode($relativePath);
}

/** Formatta un timestamp memorizzato in UTC (es. i campi created_at/updated_at
 * di SQLite, popolati con datetime('now')) in formato italiano, convertito
 * all'ora di Roma (gestisce automaticamente CET/CEST). */
function format_datetime_it(?string $utcDateTime, string $format = 'd/m/Y H:i'): string
{
    if (!$utcDateTime) {
        return '—';
    }
    try {
        $dt = new DateTime($utcDateTime, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Europe/Rome'));
        return $dt->format($format);
    } catch (Exception $e) {
        return e($utcDateTime);
    }
}

/** Formatta una data pura (senza ora, es. capture_date: 'YYYY-MM-DD') in
 * formato italiano gg/mm/aaaa. Nessuna conversione di fuso orario: è già
 * un giorno di calendario, non un istante temporale. */
function format_date_it(?string $isoDate): string
{
    if (!$isoDate) {
        return '—';
    }
    $ts = strtotime($isoDate);
    return $ts !== false ? date('d/m/Y', $ts) : e($isoDate);
}

try {
    Config::get();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre>Errore di configurazione: ' . htmlspecialchars($e->getMessage()) . '</pre>';
    exit;
}
