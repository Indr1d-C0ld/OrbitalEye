<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $body = json_body();
    $studyId = (int) ($body['study_id'] ?? 0);
    $study = $studyId ? Study::find($studyId) : null;
    if (!$study) {
        respond_json(['error' => 'Studio non trovato'], 404);
    }

    $coords = $body['coords'] ?? null;
    if (!is_array($coords)) {
        respond_json(['error' => 'Coordinate mancanti'], 400);
    }

    $id = Annotation::create(
        $studyId,
        !empty($body['capture_id']) ? (int) $body['capture_id'] : null,
        !empty($body['comparison_id']) ? (int) $body['comparison_id'] : null,
        (string) ($body['target_image'] ?? 'unknown'),
        (string) ($body['shape_type'] ?? 'rect'),
        $coords,
        (string) ($body['color'] ?? '#00fff2'),
        trim($body['label'] ?? '') ?: null,
        trim($body['notes'] ?? '') ?: null
    );

    respond_json(['id' => $id]);
}

if ($method === 'DELETE') {
    parse_str(file_get_contents('php://input'), $params);
    $id = (int) ($_GET['id'] ?? $params['id'] ?? 0);
    if (!$id) {
        respond_json(['error' => 'ID mancante'], 400);
    }
    Annotation::delete($id);
    respond_json(['ok' => true]);
}

if ($method === 'GET') {
    $studyId = (int) ($_GET['study_id'] ?? 0);
    $target = $_GET['target_image'] ?? null;
    if (!$studyId) {
        respond_json(['error' => 'study_id mancante'], 400);
    }
    $rows = $target ? Annotation::forTarget($studyId, $target) : Annotation::forStudy($studyId);
    foreach ($rows as &$r) {
        $r['coords'] = json_decode($r['coords_json'], true);
        unset($r['coords_json']);
    }
    respond_json(['annotations' => $rows]);
}

respond_json(['error' => 'Metodo non consentito'], 405);
