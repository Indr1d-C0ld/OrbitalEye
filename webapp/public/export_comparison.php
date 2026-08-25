<?php
require __DIR__ . '/../src/bootstrap.php';
Auth::requireLogin();

$id = (int) ($_GET['id'] ?? 0);
$bundle = $id ? ExportBuilder::comparisonBundle($id) : null;
if (!$bundle) {
    http_response_code(404);
    exit('Confronto non trovato');
}

$tmpZip = tempnam(sys_get_temp_dir(), 'orbitaleye_export_');
$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Impossibile creare il pacchetto di esportazione');
}

foreach ($bundle['files'] as $f) {
    $zip->addFile($f['source'], $f['zip_name']);
}
$zip->addFromString('report.html', $bundle['report_html']);
$zip->addFromString('report.json', $bundle['report_json']);
$zip->close();

$filename = 'orbitaleye_' . $bundle['folder_name'] . '_' . date('Ymd_His') . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpZip));
header('Cache-Control: private');
readfile($tmpZip);
unlink($tmpZip);
