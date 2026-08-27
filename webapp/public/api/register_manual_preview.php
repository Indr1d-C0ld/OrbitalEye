<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(['error' => 'Metodo non consentito'], 405);
}

$body = json_body();
$captureAId = (int) ($body['capture_a_id'] ?? 0);
$captureBId = (int) ($body['capture_b_id'] ?? 0);
$captureA = $captureAId ? Capture::find($captureAId) : null;
$captureB = $captureBId ? Capture::find($captureBId) : null;
if (!$captureA || !$captureB) {
    respond_json(['error' => 'Riprese non trovate'], 404);
}

$points = $body['points'] ?? [];
if (!is_array($points) || count($points) < 3) {
    respond_json(['error' => 'Servono almeno 3 punti di controllo'], 400);
}

try {
    $client = new PythonServiceClient();
    $result = $client->post('/analysis/register_manual', [
        'capture_a_path' => $captureA['relative_path'],
        'capture_b_path' => $captureB['relative_path'],
        'control_points' => array_values($points),
    ]);
} catch (PythonServiceException $e) {
    respond_json(['error' => $e->getMessage()], 502);
}

respond_json([
    'method' => $result['method'],
    'urls' => [
        'aligned_b' => storage_url($result['paths']['aligned_b']),
        'blend' => storage_url($result['paths']['blend']),
    ],
]);
