<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();

/**
 * Zone di conteggio di uno studio (vedi StudyZone).
 * GET ?study_id=N: elenco. POST {annotation_id, name?}: zona da
 * un'annotazione. DELETE {id}: elimina.
 */
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $studyId = (int) ($_GET['study_id'] ?? 0);
    if (!$studyId || !Study::find($studyId)) {
        respond_json(['error' => 'Studio non trovato'], 404);
    }
    respond_json(['zones' => StudyZone::forStudy($studyId)]);
}

if ($method === 'POST') {
    $body = json_body();
    $name = $body['name'] ?? null;
    if ($name !== null && !is_string($name)) {
        respond_json(['error' => 'Nome non valido'], 400);
    }
    try {
        $id = StudyZone::fromAnnotation((int) ($body['annotation_id'] ?? 0), $name);
    } catch (InvalidArgumentException $e) {
        respond_json(['error' => $e->getMessage()], 400);
    }
    respond_json(['id' => $id, 'zone' => StudyZone::find($id)]);
}

if ($method === 'DELETE') {
    $body = json_body();
    $zone = StudyZone::find((int) ($body['id'] ?? 0));
    if (!$zone || (isset($body['study_id']) && (int) $body['study_id'] !== $zone['study_id'])) {
        respond_json(['error' => 'Zona non trovata'], 404);
    }
    StudyZone::delete($zone['id']);
    respond_json(['ok' => true]);
}

respond_json(['error' => 'Metodo non consentito'], 405);
