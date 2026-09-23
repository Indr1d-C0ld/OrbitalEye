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

// Stesso tetto del servizio di analisi (MAX_IMAGE_PIXELS in core/utils.py),
// letto dalla sola intestazione: meglio rifiutare subito con un messaggio
// chiaro che archiviare un'immagine che poi nessuna analisi può elaborare.
$probe = @getimagesize($_FILES['image']['tmp_name']);
if (!$probe || $probe[0] < 1 || $probe[1] < 1) {
    respond_json(['error' => 'Immagine non leggibile'], 400);
}
if ($probe[0] * $probe[1] > 25000000) {
    respond_json(['error' => "Immagine troppo grande ({$probe[0]}×{$probe[1]} px, massimo 25 megapixel): riducila prima di caricarla."], 400);
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

        // Ritaglio: rettangolo in frazioni dell'immagine sorgente, inviato da
        // analyze.js. Serve a calcolare l'area geografica ESATTA del
        // frammento; senza, gli export KML/GeoJSON e la stima delle ombre di
        // un ritaglio usavano l'area dell'intero studio.
        $crop = null;
        if (isset($_POST['crop_x'], $_POST['crop_y'], $_POST['crop_w'], $_POST['crop_h'])) {
            $c = array_map('floatval', [$_POST['crop_x'], $_POST['crop_y'], $_POST['crop_w'], $_POST['crop_h']]);
            if ($c[2] > 0 && $c[3] > 0 && $c[0] >= 0 && $c[1] >= 0 && $c[0] + $c[2] <= 1.0001 && $c[1] + $c[3] <= 1.0001) {
                $crop = ['x' => $c[0], 'y' => $c[1], 'w' => $c[2], 'h' => $c[3]];
            }
        }
        // Area e rotazione della sorgente (o del ritaglio): senza, la copia
        // salvata perdeva la rotazione e le sue misure risultavano sbagliate.
        $meta += Capture::geoMetaForDerived($sourceCapture, $crop);

        // Stessa data di acquisizione della sorgente, se non indicata: la
        // copia di una ripresa è un'elaborazione, non una nuova acquisizione.
        if ($captureDate === null && !empty($sourceCapture['capture_date'])) {
            $captureDate = $sourceCapture['capture_date'];
        }
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
