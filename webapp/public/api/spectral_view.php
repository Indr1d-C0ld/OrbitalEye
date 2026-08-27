<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(['error' => 'Metodo non consentito'], 405);
}

$body = json_body();
$captureId = (int) ($body['capture_id'] ?? 0);
$mode = $body['mode'] ?? 'ndvi';
if (!in_array($mode, ['ndvi', 'false_color_ir'], true)) {
    respond_json(['error' => 'Modalità non valida'], 400);
}

$capture = $captureId ? Capture::find($captureId) : null;
if (!$capture) {
    respond_json(['error' => 'Ripresa non trovata'], 404);
}

$meta = json_decode($capture['meta_json'] ?? '', true);
$nirPath = is_array($meta) ? ($meta['nir_relative_path'] ?? null) : null;
if (!$nirPath) {
    respond_json([
        'error' => 'Questa ripresa non ha la banda NIR disponibile: solo le riprese Sentinel Hub scaricate '
            . 'da ora in poi la includono automaticamente. Ri-scarica la stessa area/periodo per abilitare NDVI e falso colore infrarosso.',
    ], 400);
}

try {
    $client = new PythonServiceClient();
    $result = $client->post('/analysis/spectral_view', [
        'true_color_path' => $capture['relative_path'],
        'nir_red_path' => $nirPath,
        'mode' => $mode,
    ]);
} catch (PythonServiceException $e) {
    respond_json(['error' => $e->getMessage()], 502);
}

respond_json([
    'relative_path' => $result['relative_path'],
    'url' => storage_url($result['relative_path']),
]);
