<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(['error' => 'Metodo non consentito'], 405);
}

// La logica vera e propria (validazione, chiamata al servizio Python,
// rotazione/ritaglio, salvataggio) vive in CaptureFetcher: condivisa con
// cli/run_scheduled_downloads.php per gli scaricamenti pianificati (vedi
// ScheduledDownload.php), stesso comportamento in entrambi i casi.
try {
    $result = CaptureFetcher::fetchAndSave(json_body());
} catch (CaptureFetchException $e) {
    respond_json(['error' => $e->getMessage()], $e->httpStatus);
}

respond_json([
    'id' => $result['capture_id'],
    'relative_path' => $result['relative_path'],
    'url' => storage_url($result['relative_path']),
    'width' => $result['width'],
    'height' => $result['height'],
]);
