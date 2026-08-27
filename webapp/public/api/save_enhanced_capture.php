<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(['error' => 'Metodo non consentito'], 405);
}

$body = json_body();
$sourceCaptureId = (int) ($body['source_capture_id'] ?? 0);
$relativePath = trim($body['relative_path'] ?? '');

$sourceCapture = $sourceCaptureId ? Capture::find($sourceCaptureId) : null;
if (!$sourceCapture) {
    respond_json(['error' => 'Ripresa di origine non trovata'], 404);
}
if ($relativePath === '' || strpos($relativePath, '..') !== false || strpos($relativePath, 'processed/') !== 0) {
    respond_json(['error' => 'Percorso anteprima non valido'], 400);
}
$full = Config::storageRoot() . '/' . $relativePath;
if (!is_file($full)) {
    respond_json(['error' => 'File di anteprima non trovato (potrebbe essere scaduto: riapplica i filtri)'], 404);
}

$newId = Capture::create(
    (int) $sourceCapture['study_id'],
    trim($body['label'] ?? '') ?: ('Enhanced: ' . ($sourceCapture['label'] ?? $sourceCapture['id'])),
    'processed',
    $sourceCapture['capture_date'],
    $relativePath,
    $sourceCapture['width'],
    $sourceCapture['height'],
    ['source_capture_id' => $sourceCapture['id'], 'steps' => $body['steps'] ?? null]
);

respond_json([
    'id' => $newId,
    'relative_path' => $relativePath,
    'url' => storage_url($relativePath),
]);
