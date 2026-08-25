<?php
require __DIR__ . '/../src/bootstrap.php';
Auth::requireLogin();

$idsParam = trim($_GET['ids'] ?? '');
$search = trim($_GET['q'] ?? '');

if ($idsParam !== '') {
    $ids = array_values(array_filter(array_map('intval', explode(',', $idsParam))));
    $comparisons = array_filter(array_map(fn($id) => Comparison::find($id), $ids));
    // esporta solo quelli effettivamente salvati in libreria, per coerenza con il pulsante che li mostra
    $comparisons = array_filter($comparisons, fn($c) => (int) $c['is_saved_to_library'] === 1);
} else {
    // nessun ID esplicito: esporta il risultato della stessa ricerca/filtro
    // attualmente visibile in Libreria (o tutta la libreria se non filtrata)
    $comparisons = Comparison::library($search ?: null);
}

if (!$comparisons) {
    http_response_code(404);
    exit('Nessun confronto da esportare');
}

$tmpZip = tempnam(sys_get_temp_dir(), 'orbitaleye_library_');
$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Impossibile creare il pacchetto di esportazione');
}

$usedFolders = [];
$index = "ORBITALEYE — Esportazione libreria\nGenerata il " . date('d/m/Y H:i') . "\n\n";

foreach ($comparisons as $c) {
    $bundle = ExportBuilder::comparisonBundle((int) $c['id']);
    if (!$bundle) {
        continue;
    }
    $folder = $bundle['folder_name'];
    if (isset($usedFolders[$folder])) {
        $folder .= '_' . $c['id'];
    }
    $usedFolders[$folder] = true;

    foreach ($bundle['files'] as $f) {
        $zip->addFile($f['source'], $folder . '/' . $f['zip_name']);
    }
    $zip->addFromString($folder . '/report.html', $bundle['report_html']);
    $zip->addFromString($folder . '/report.json', $bundle['report_json']);

    $index .= "- {$folder}/ (confronto #{$c['id']}" . ($c['title'] ? ', "' . $c['title'] . '"' : '') . ")\n";
}

$zip->addFromString('INDICE.txt', $index);
$zip->close();

$filename = 'orbitaleye_libreria_' . date('Ymd_His') . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmpZip));
header('Cache-Control: private');
readfile($tmpZip);
unlink($tmpZip);
