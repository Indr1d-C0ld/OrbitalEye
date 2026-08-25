<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(['error' => 'Metodo non consentito'], 405);
}

$body = json_body();
$type = $body['type'] ?? '';
$id = (int) ($body['id'] ?? 0);

if (!$id) {
    respond_json(['error' => 'ID mancante'], 400);
}

switch ($type) {
    case 'study':
        Study::delete($id);
        break;
    case 'capture':
        Capture::delete($id);
        break;
    case 'comparison':
        Comparison::delete($id);
        break;
    case 'annotation':
        Annotation::delete($id);
        break;
    default:
        respond_json(['error' => 'Tipo non valido'], 400);
}

respond_json(['ok' => true]);
