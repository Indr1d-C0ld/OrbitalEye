<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

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

try {
    Config::get();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre>Errore di configurazione: ' . htmlspecialchars($e->getMessage()) . '</pre>';
    exit;
}
