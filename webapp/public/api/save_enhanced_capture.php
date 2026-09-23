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

// Eredita la scala reale (metri/pixel) dalla ripresa sorgente: né i filtri
// di enhancing né gli indici spettrali (NDVI/NDWI/falso colore IR, unico
// altro chiamante di questo endpoint) ridimensionano l'immagine. Senza
// questo la ripresa derivata non aveva alcuna informazione di scala propria
// — vedi Capture::resolveMpp per i dettagli del bug corretto.
$savedMeta = ['source_capture_id' => $sourceCapture['id'], 'steps' => $body['steps'] ?? null];
$mpp = Capture::resolveMpp($sourceCapture);
if ($mpp) {
    $savedMeta += $mpp;
}
// Stessa area e rotazione della sorgente (i filtri non spostano i pixel):
// senza, la copia perdeva la rotazione (misure sbagliate) e gli export
// KML/GeoJSON ricadevano sull'area generica dello studio.
$savedMeta += Capture::geoMetaForDerived($sourceCapture);
// Dimensioni lette dal file prodotto, non copiate dalla sorgente: se quelle
// della sorgente fossero errate l'errore si propagherebbe alla copia.
$producedSize = @getimagesize(Config::storageRoot() . '/' . $relativePath);

$newId = Capture::create(
    (int) $sourceCapture['study_id'],
    trim($body['label'] ?? '') ?: ('Enhanced: ' . ($sourceCapture['label'] ?? $sourceCapture['id'])),
    'processed',
    $sourceCapture['capture_date'],
    $relativePath,
    $producedSize[0] ?? $sourceCapture['width'],
    $producedSize[1] ?? $sourceCapture['height'],
    $savedMeta
);

respond_json([
    'id' => $newId,
    'relative_path' => $relativePath,
    'url' => storage_url($relativePath),
]);
