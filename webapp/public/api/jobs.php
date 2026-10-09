<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();
session_write_close();

/**
 * Lavori in background (vedi Job):
 *  POST {type, params}      avvia un lavoro ('fetch' | 'compare' | 'detect' |
 *                           'detail'), con gli stessi parametri delle API
 *                           dirette (fetch_capture.php, compare.php,
 *                           detect.php; per 'detail' vedi DetailFetcher)
 *  GET  ?id=N               stato, avanzamento, messaggio, risultato o errore
 *  GET  ?study_id=N         lavori ancora in corso di uno studio
 */
$method = $_SERVER['REQUEST_METHOD'];

$public = fn(array $j) => [
    'id' => (int) $j['id'],
    'type' => $j['type'],
    'status' => $j['status'],
    'progress' => (int) $j['progress'],
    'message' => $j['message'],
    'error' => $j['error'],
    'result' => $j['status'] === 'done' ? $j['result'] : null,
    // Per riconoscere il lavoro riaprendo la pagina (es. quale ripresa si
    // stava scaricando), senza esporre altro.
    'label' => $j['params']['label'] ?? null,
];

if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $job = Job::find((int) $_GET['id']);
        if (!$job) {
            respond_json(['error' => 'Lavoro non trovato'], 404);
        }
        respond_json(['job' => $public($job)]);
    }
    $studyId = (int) ($_GET['study_id'] ?? 0);
    if (!$studyId) {
        respond_json(['error' => 'study_id mancante'], 400);
    }
    respond_json(['jobs' => array_map($public, Job::activeForStudy($studyId))]);
}

if ($method !== 'POST') {
    respond_json(['error' => 'Metodo non consentito'], 405);
}
$body = json_body();
$type = (string) ($body['type'] ?? '');
$params = is_array($body['params'] ?? null) ? $body['params'] : [];
if (!in_array($type, Job::types(), true)) {
    respond_json(['error' => 'Tipo di lavoro non valido'], 400);
}
// Lo studio del lavoro: dai parametri, o dalla ripresa per un rilevamento.
$studyId = (int) ($params['study_id'] ?? 0);
if (!$studyId && !empty($params['capture_id']) && ($c = Capture::find((int) $params['capture_id']))) {
    $studyId = (int) $c['study_id'];
}
if (!$studyId || !Study::find($studyId)) {
    respond_json(['error' => 'Studio non trovato'], 404);
}
Job::purgeOld();
$id = Job::start($type, $studyId, $params);
respond_json(['job' => $public(Job::find($id))]);
