<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(['error' => 'Metodo non consentito'], 405);
}

$studyId = (int) ($_POST['study_id'] ?? 0);
$study = $studyId ? Study::find($studyId) : null;
if (!$study) {
    respond_json(['error' => 'Studio non trovato'], 404);
}

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    respond_json(['error' => 'Caricamento file non riuscito'], 400);
}

$allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($_FILES['image']['tmp_name']);

if (!isset($allowed[$mime])) {
    respond_json(['error' => 'Formato non supportato (usare PNG, JPEG o WEBP)'], 400);
}

$ext = $allowed[$mime];
$id = bin2hex(random_bytes(8));
$destDir = Config::storageRoot() . '/raw';
if (!is_dir($destDir)) {
    mkdir($destDir, 0770, true);
}
$destPath = $destDir . '/' . $id . '.' . $ext;

if (!move_uploaded_file($_FILES['image']['tmp_name'], $destPath)) {
    respond_json(['error' => 'Impossibile salvare il file'], 500);
}

$size = @getimagesize($destPath);
$width = $size[0] ?? null;
$height = $size[1] ?? null;

$label = trim($_POST['label'] ?? '') ?: null;
$captureDate = trim($_POST['capture_date'] ?? '') ?: null;

// Eredita la scala reale (metri/pixel) dalla ripresa sorgente, se indicata:
// "Salva come nuova ripresa" e "Salva ritaglio" caricano qui il risultato
// (stessa densità di pixel della sorgente, nessun ridimensionamento), ma
// senza questo la nuova ripresa non aveva alcuna informazione di scala e la
// vista di analisi ricadeva sulla bbox generica dello studio — sbagliata,
// specialmente per un ritaglio molto più piccolo dell'area intera (vedi
// Capture::resolveMpp).
$meta = ['original_filename' => $_FILES['image']['name']];
$sourceCaptureId = (int) ($_POST['source_capture_id'] ?? 0);
if ($sourceCaptureId) {
    $sourceCapture = Capture::find($sourceCaptureId);
    if ($sourceCapture) {
        $mpp = Capture::resolveMpp($sourceCapture);
        if ($mpp) {
            $meta += $mpp;
        }
        $meta['source_capture_id'] = $sourceCaptureId;
    }
}

$captureId = Capture::create(
    $studyId,
    $label,
    'upload',
    $captureDate,
    'raw/' . basename($destPath),
    $width,
    $height,
    $meta
);

Study::touch($studyId);

respond_json([
    'id' => $captureId,
    'relative_path' => 'raw/' . basename($destPath),
    'url' => storage_url('raw/' . basename($destPath)),
    'width' => $width,
    'height' => $height,
]);
