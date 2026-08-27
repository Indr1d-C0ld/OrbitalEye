<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();

$method = $_SERVER['REQUEST_METHOD'];

function require_capture_pair(int $captureAId, int $captureBId): array
{
    $captureA = $captureAId ? Capture::find($captureAId) : null;
    $captureB = $captureBId ? Capture::find($captureBId) : null;
    if (!$captureA || !$captureB) {
        respond_json(['error' => 'Riprese non trovate'], 404);
    }
    return [$captureA, $captureB];
}

if ($method === 'GET') {
    $captureAId = (int) ($_GET['capture_a_id'] ?? 0);
    $captureBId = (int) ($_GET['capture_b_id'] ?? 0);
    require_capture_pair($captureAId, $captureBId);
    respond_json(['points' => ManualControlPoints::getPoints($captureAId, $captureBId)]);
}

if ($method === 'POST') {
    $body = json_body();
    $captureAId = (int) ($body['capture_a_id'] ?? 0);
    $captureBId = (int) ($body['capture_b_id'] ?? 0);
    require_capture_pair($captureAId, $captureBId);

    $points = $body['points'] ?? null;
    if (!is_array($points)) {
        respond_json(['error' => 'Punti mancanti'], 400);
    }
    // Validazione minima: ogni punto deve avere le 4 coordinate numeriche.
    $clean = [];
    foreach ($points as $p) {
        if (!is_array($p) || !isset($p['ax'], $p['ay'], $p['bx'], $p['by'])) {
            respond_json(['error' => 'Formato punto non valido'], 400);
        }
        $clean[] = [
            'ax' => (float) $p['ax'], 'ay' => (float) $p['ay'],
            'bx' => (float) $p['bx'], 'by' => (float) $p['by'],
        ];
    }

    ManualControlPoints::save($captureAId, $captureBId, $clean);
    respond_json(['ok' => true, 'count' => count($clean)]);
}

if ($method === 'DELETE') {
    parse_str(file_get_contents('php://input'), $params);
    $captureAId = (int) ($_GET['capture_a_id'] ?? $params['capture_a_id'] ?? 0);
    $captureBId = (int) ($_GET['capture_b_id'] ?? $params['capture_b_id'] ?? 0);
    require_capture_pair($captureAId, $captureBId);
    ManualControlPoints::delete($captureAId, $captureBId);
    respond_json(['ok' => true]);
}

respond_json(['error' => 'Metodo non consentito'], 405);
