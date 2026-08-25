<?php
require __DIR__ . '/../src/bootstrap.php';
Auth::requireLogin();

$id = (int) ($_GET['id'] ?? 0);
$capture = $id ? Capture::find($id) : null;
if (!$capture) {
    http_response_code(404);
    exit('Ripresa non trovata');
}

$full = Config::storageRoot() . '/' . $capture['relative_path'];
if (!is_file($full)) {
    http_response_code(404);
    exit('File non trovato su disco');
}

$study = Study::find((int) $capture['study_id']);
$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION)) ?: 'jpg';
$labelPart = ExportBuilder::slug($capture['label'] ?: ('ripresa_' . $capture['id']));
$studyPart = $study ? ExportBuilder::slug($study['title']) : 'orbitaleye';
$datePart = $capture['capture_date'] ? ExportBuilder::slug($capture['capture_date']) : format_datetime_it($capture['created_at'], 'Ymd');
$filename = "{$studyPart}_{$labelPart}_{$datePart}.{$ext}";

$mime = [
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
][$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($full));
header('Cache-Control: private');
readfile($full);
