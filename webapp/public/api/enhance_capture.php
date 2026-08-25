<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(['error' => 'Metodo non consentito'], 405);
}

$body = json_body();
$captureId = (int) ($body['capture_id'] ?? 0);
$capture = $captureId ? Capture::find($captureId) : null;
if (!$capture) {
    respond_json(['error' => 'Ripresa non trovata'], 404);
}

$steps = $body['steps'] ?? [];
if (!is_array($steps) || empty($steps)) {
    respond_json(['error' => 'Nessun filtro specificato'], 400);
}

try {
    $client = new PythonServiceClient();
    $result = $client->post('/analysis/enhance', [
        'capture_path' => $capture['relative_path'],
        'steps' => $steps,
    ]);
} catch (PythonServiceException $e) {
    respond_json(['error' => $e->getMessage()], 502);
}

$newId = Capture::create(
    (int) $capture['study_id'],
    trim($body['label'] ?? '') ?: ('Enhanced: ' . ($capture['label'] ?? $capture['id'])),
    'processed',
    $capture['capture_date'],
    $result['relative_path'],
    $capture['width'],
    $capture['height'],
    ['source_capture_id' => $capture['id'], 'steps' => $steps]
);

respond_json([
    'id' => $newId,
    'relative_path' => $result['relative_path'],
    'url' => storage_url($result['relative_path']),
]);
