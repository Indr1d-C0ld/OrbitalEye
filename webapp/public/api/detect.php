<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();
// Un rilevamento dura da pochi secondi a un paio di minuti: la sessione si
// chiude subito, così il resto dell'interfaccia non resta bloccato.
session_write_close();

/**
 * Rilevamento automatico di oggetti su una ripresa (vedi Detection.php e
 * python-service/app/core/detect.py).
 *  GET  ?capture_id=N           ultimo rilevamento della ripresa
 *  POST {capture_id, confidence, small_objects}  esegue e salva
 * Per ogni aereo/elicottero con scala nota: misure del riquadro in metri e
 * tipi compatibili dalla tabella locale (AircraftCatalog).
 */
$method = $_SERVER['REQUEST_METHOD'];
$body = $method === 'POST' ? json_body() : $_GET;
$captureId = (int) ($body['capture_id'] ?? 0);
$capture = $captureId ? Capture::find($captureId) : null;
if (!$capture) {
    respond_json(['error' => 'Ripresa non trovata'], 404);
}

if ($method === 'GET') {
    respond_json(['detection' => Detection::forCapture($captureId)]);
}
if ($method !== 'POST') {
    respond_json(['error' => 'Metodo non consentito'], 405);
}

$mpp = Detection::resolveMpp($capture);
$mppX = $mpp ? (float) $mpp['mpp_x'] : null;
$mppY = $mpp ? (float) $mpp['mpp_y'] : null;
$mppMean = $mpp ? ($mppX + $mppY) / 2 : null;
if ($mppMean !== null && $mppMean > Detection::MAX_MPP) {
    respond_json(['error' => sprintf(
        'Risoluzione troppo bassa per il rilevamento (%s m/pixel): il modello riconosce oggetti in immagini fra circa 0,1 e 1 m/pixel. Usa una ripresa più dettagliata (Esri, area più piccola).',
        str_replace('.', ',', (string) round($mppMean, 1))
    )], 400);
}

$confidence = max(0.05, min(0.95, (float) ($body['confidence'] ?? 0.25)));
$smallObjects = !empty($body['small_objects']);

try {
    $result = (new PythonServiceClient())->post('/analysis/detect', [
        'capture_path' => $capture['relative_path'],
        'mpp' => $mppMean,
        'confidence' => $confidence,
        'small_objects' => $smallObjects,
    ], 300);
} catch (PythonServiceException $e) {
    respond_json(['error' => $e->getMessage()], 502);
}

$w = max(1, (int) ($result['width'] ?? $capture['width']));
$h = max(1, (int) ($result['height'] ?? $capture['height']));
$counts = [];
$objects = [];
foreach ($result['detections'] ?? [] as $i => $d) {
    $counts[$d['class']] = ($counts[$d['class']] ?? 0) + 1;
    $o = [
        'n' => $i + 1,
        'class' => $d['class'],
        'label' => $d['label'],
        'confidence' => $d['confidence'],
        // Coordinate frazionarie (come le annotazioni): indipendenti dalla
        // risoluzione con cui la ripresa viene mostrata.
        'polygon' => array_map(fn($p) => [round($p[0] / $w, 6), round($p[1] / $h, 6)], $d['polygon']),
        'center' => [round($d['cx'] / $w, 6), round($d['cy'] / $h, 6)],
    ];
    if ($mpp) {
        // Lati del riquadro in metri: ogni lato è inclinato di "angle", e la
        // scala può differire fra i due assi dell'immagine.
        $a = (float) $d['angle'];
        $side1 = $d['w'] * hypot($mppX * cos($a), $mppY * sin($a));
        $side2 = $d['h'] * hypot($mppX * sin($a), $mppY * cos($a));
        $o['size_m'] = [round(max($side1, $side2), 1), round(min($side1, $side2), 1)];
        if (in_array($d['class'], ['plane', 'helicopter'], true)) {
            $o['candidates'] = array_map(fn($c) => [
                'name' => $c['name'], 'maker' => $c['maker'], 'category' => $c['category'],
                'compatibility' => $c['compatibility'], 'note' => $c['note'], 'wiki_url' => $c['wiki_url'],
                'span_m' => $c['span_m'], 'length_m' => $c['length_m'], 'rotor_m' => $c['rotor_m'],
            ], AircraftCatalog::match($side1, $side2, $mppMean, 'box', $d['class'] === 'helicopter' ? 'elicottero' : 'ala_fissa', 5));
        }
    }
    $objects[] = $o;
}

$stored = [
    'objects' => $objects,
    'upscale' => $result['upscale'] ?? 1,
    'tiles' => $result['tiles'] ?? 1,
    'mpp' => $mpp,
];
// "Oggetti piccoli" registrato solo se l'ingrandimento è stato davvero
// applicato (non serve sotto 0,5 m/pixel, né senza scala nota).
Detection::save($captureId, (int) $capture['study_id'], (string) ($result['model'] ?? 'yolo11s-obb'), $confidence,
    $smallObjects && (float) ($result['upscale'] ?? 1) > 1, $mppMean, $stored, $counts);
respond_json(['detection' => Detection::forCapture($captureId)]);
