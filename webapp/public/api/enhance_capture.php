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

// json_body() decodifica gli oggetti JSON come array associativi PHP: un
// "params": {} (filtro senza parametri, es. white_balance/clahe/hist_eq)
// arriva quindi come array PHP vuoto [], che json_encode() più avanti
// riserializza come "[]" invece di "{}" — il servizio Python si aspetta
// sempre un dizionario per "params" e rifiuta la richiesta con 422. Stessa
// correzione già applicata lato compare.php (build_enhance_steps).
foreach ($steps as &$step) {
    if (isset($step['params']) && is_array($step['params']) && empty($step['params'])) {
        $step['params'] = new stdClass();
    }
}
unset($step);

try {
    $client = new PythonServiceClient();
    $result = $client->post('/analysis/enhance', [
        'capture_path' => $capture['relative_path'],
        'steps' => $steps,
    ]);
} catch (PythonServiceException $e) {
    respond_json(['error' => $e->getMessage()], 502);
}

// Modalità anteprima: elabora e ritorna il risultato senza creare subito una
// ripresa permanente, così si possono provare i filtri liberamente prima di
// decidere se tenerli (vedi save_enhanced_capture.php per il salvataggio
// esplicito una volta soddisfatti del risultato).
if (!empty($body['preview'])) {
    respond_json([
        'preview' => true,
        'relative_path' => $result['relative_path'],
        'url' => storage_url($result['relative_path']),
    ]);
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
