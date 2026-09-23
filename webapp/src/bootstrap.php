<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

// Fuso orario dell'applicazione: influenza date() e i confronti/formattazioni
// fatti da qui in poi (il server può girare in UTC — è il default di questo
// PHP — ma l'interfaccia deve mostrare sempre l'ora italiana).
date_default_timezone_set('Europe/Rome');

session_start([
    // Nome di sessione dedicato: con il PHPSESSID di default e path '/', le app
    // che convivono su questo host condividono un unico cookie. Poiché ognuna
    // rigenera l'id al login, accedere a una disconnetteva le altre.
    'name' => 'ORBITALEYESESSID',
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    // Su HTTPS il cookie di sessione non deve poter viaggiare in chiaro.
    // Impostato in base alla connessione in corso, non a un valore fisso:
    // l'app resta raggiungibile anche via HTTP semplice (accesso per IP,
    // dove il redirect a HTTPS del vhost non scatta) e lì un flag Secure
    // fisso impedirebbe del tutto l'accesso.
    'cookie_secure' => (
        (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
    ),
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

// Qualunque eccezione non gestita in un endpoint api/ deve arrivare al
// browser come JSON: il frontend fa sempre res.json(), e una pagina d'errore
// vuota lo lasciava senza alcun messaggio da mostrare all'analista (es. una
// violazione di chiave esterna annotando una ripresa appena eliminata in
// un'altra scheda). Il dettaglio tecnico finisce nel log, non nella risposta.
if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
    set_exception_handler(function (Throwable $e): void {
        error_log('OrbitalEye API: ' . $e::class . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        echo json_encode(['error' => 'Errore interno del server: operazione non completata.']);
    });
}

try {
    Config::get();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre>Errore di configurazione: ' . htmlspecialchars($e->getMessage()) . '</pre>';
    exit;
}
