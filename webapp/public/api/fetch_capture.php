<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(['error' => 'Metodo non consentito'], 405);
}

$body = json_body();
$studyId = (int) ($body['study_id'] ?? 0);
$study = $studyId ? Study::find($studyId) : null;
if (!$study) {
    respond_json(['error' => 'Studio non trovato'], 404);
}

$bbox = $body['bbox'] ?? null;
if (!is_array($bbox) || count($bbox) !== 4 || !array_reduce($bbox, fn($ok, $v) => $ok && is_numeric($v), true)) {
    respond_json(['error' => 'Bounding box mancante o non valida (min_lon,min_lat,max_lon,max_lat)'], 400);
}
[$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $bbox);
if ($minLon >= $maxLon || $minLat >= $maxLat) {
    respond_json(['error' => 'Bounding box non valida: "Min Lon" deve essere minore di "Max Lon" e "Min Lat" minore di "Max Lat" (hai forse invertito due valori?).'], 400);
}
if ($minLon < -180 || $maxLon > 180 || $minLat < -90 || $maxLat > 90) {
    respond_json(['error' => 'Bounding box fuori range: longitudine tra -180 e 180, latitudine tra -90 e 90.'], 400);
}

$source = $body['source'] ?? 'sentinelhub';
if (!in_array($source, ['sentinelhub', 'esri'], true)) {
    respond_json(['error' => 'Fonte non valida'], 400);
}
$width = (int) ($body['width'] ?? 1024);
$height = (int) ($body['height'] ?? 1024);

$client = new PythonServiceClient();

if ($source === 'sentinelhub') {
    $dateFrom = $body['date_from'] ?? null;
    $dateTo = $body['date_to'] ?? null;
    if (!$dateFrom || !$dateTo) {
        respond_json(['error' => 'Intervallo date mancante'], 400);
    }
    if (strtotime($dateFrom) === false || strtotime($dateTo) === false) {
        respond_json(['error' => 'Formato data non valido.'], 400);
    }
    if (strtotime($dateFrom) >= strtotime($dateTo)) {
        respond_json(['error' => 'Intervallo date non valido: "Da data" (' . $dateFrom . ') deve essere precedente a "A data" (' . $dateTo . '). Controlla di non averle invertite.'], 400);
    }
    if (strtotime($dateTo) > time()) {
        respond_json(['error' => 'Intervallo date non valido: "A data" (' . $dateTo . ') è nel futuro — Copernicus non ha ancora immagini per quella data.'], 400);
    }

    try {
        $result = $client->post('/fetch/sentinelhub', [
            'bbox' => array_map('floatval', $bbox),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'width' => $width,
            'height' => $height,
            'max_cloud_coverage' => (int) ($body['max_cloud_coverage'] ?? 20),
        ]);
    } catch (PythonServiceException $e) {
        respond_json(['error' => $e->getMessage()], 502);
    }

    $captureId = Capture::create(
        $studyId,
        'Sentinel-2 ' . $dateFrom . ' → ' . $dateTo,
        'sentinelhub',
        $dateTo,
        $result['relative_path'],
        $result['width'],
        $result['height'],
        [
            'bbox' => $bbox, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'source' => 'sentinel-2-l2a',
            // Presente solo se il fetch della banda NIR è andato a buon fine
            // (vedi fetch.py): abilita i pulsanti NDVI/Falso colore IR sulla
            // scheda di questa ripresa. Assente per le riprese scaricate
            // prima dell'introduzione di questa funzione.
            'nir_relative_path' => $result['nir_relative_path'] ?? null,
        ]
    );
} else {
    try {
        $result = $client->post('/fetch/esri', [
            'bbox' => array_map('floatval', $bbox),
            'width' => $width,
            'height' => $height,
        ]);
    } catch (PythonServiceException $e) {
        respond_json(['error' => $e->getMessage()], 502);
    }

    $captureId = Capture::create(
        $studyId,
        'Esri World Imagery — scaricata il ' . date('d/m/Y'),
        'esri',
        null, // Esri non espone la data reale della ripresa, solo "il composito più recente disponibile": non possiamo mostrarla come se fosse la data della ripresa
        $result['relative_path'],
        $result['width'],
        $result['height'],
        [
            // $result['bbox'] è la bbox EFFETTIVAMENTE coperta dall'immagine
            // (il servizio Esri, se l'aspect ratio richiesto non combacia,
            // espande la bbox per evitare distorsioni: senza usare quella
            // corretta qui, lo strumento di misura calcolerebbe una scala
            // sbagliata — anche di un fattore 2× o più su bbox molto
            // rettangolari). Fallback a $bbox solo per compatibilità con
            // un servizio Python non ancora aggiornato.
            'bbox' => $result['bbox'] ?? $bbox,
            'source' => 'esri-world-imagery', 'fetched_at' => date('c'),
        ]
    );
}

Study::touch($studyId);

respond_json([
    'id' => $captureId,
    'relative_path' => $result['relative_path'],
    'url' => storage_url($result['relative_path']),
    'width' => $result['width'],
    'height' => $result['height'],
]);
