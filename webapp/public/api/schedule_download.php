<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $studyId = (int) ($_GET['study_id'] ?? 0);
    if (!$studyId) {
        respond_json(['error' => 'study_id mancante'], 400);
    }
    $rows = array_map(function ($r) {
        $r['params'] = json_decode($r['params_json'], true);
        unset($r['params_json']);
        return $r;
    }, ScheduledDownload::forStudy($studyId));
    respond_json(['schedules' => $rows]);
}

if ($method === 'POST') {
    $body = json_body();
    $action = $body['action'] ?? 'create';

    if ($action === 'toggle') {
        $id = (int) ($body['id'] ?? 0);
        $sched = $id ? ScheduledDownload::find($id) : null;
        if (!$sched) {
            respond_json(['error' => 'Pianificazione non trovata'], 404);
        }
        ScheduledDownload::setActive($id, !$sched['is_active']);
        respond_json(['ok' => true]);
    }

    if ($action === 'delete') {
        $id = (int) ($body['id'] ?? 0);
        if (!$id) {
            respond_json(['error' => 'id mancante'], 400);
        }
        ScheduledDownload::delete($id);
        respond_json(['ok' => true]);
    }

    // action === 'create' (default)
    $studyId = (int) ($body['study_id'] ?? 0);
    $study = $studyId ? Study::find($studyId) : null;
    if (!$study) {
        respond_json(['error' => 'Studio non trovato'], 404);
    }
    $source = $body['source'] ?? '';
    if (!in_array($source, ['sentinelhub', 'sentinel1', 'esri'], true)) {
        respond_json(['error' => 'Fonte non valida'], 400);
    }
    $intervalDays = max(1, (int) ($body['interval_days'] ?? 1));
    $duplicateThreshold = max(0.0, min(1.0, (float) ($body['duplicate_threshold'] ?? 0.005)));

    // Parametri "di ricetta" per il cron (vedi cli/run_scheduled_downloads.php).
    // Per Sentinel NON si salvano date: ogni controllo cerca i passaggi
    // successivi all'ultimo scaricato; date_window_days è solo il periodo in
    // cui cercare il primo (e il limite se la ripresa precedente sparisce).
    $params = [
        'bbox' => $body['bbox'] ?? null,
        'rotation' => (float) ($body['rotation'] ?? 0),
        'width' => (int) ($body['width'] ?? 1024),
        'height' => (int) ($body['height'] ?? 1024),
    ];
    if (!is_array($params['bbox']) || count($params['bbox']) !== 4) {
        respond_json(['error' => 'Bounding box mancante o non valida'], 400);
    }
    if ($source === 'sentinelhub' || $source === 'sentinel1') {
        $params['date_window_days'] = max(1, min(365, (int) ($body['date_window_days'] ?? 30)));
    }
    if ($source === 'sentinelhub') {
        // Nuvole massime SULL'AREA (non sull'intero tassello), in %.
        $params['max_cloud_coverage'] = max(0, min(100, (int) ($body['max_cloud_coverage'] ?? 20)));
    }
    if ($source === 'sentinel1' && isset($body['relative_orbit']) && $body['relative_orbit'] !== '') {
        $params['relative_orbit'] = (int) $body['relative_orbit'];
    }

    $scheduleId = ScheduledDownload::create($studyId, $source, $params, $intervalDays, $duplicateThreshold);

    // Scarica subito una prima ripresa "base" (così la pianificazione non
    // resta vuota fino al prossimo passaggio del cron, che potrebbe essere
    // anche tra un giorno intero) e la registra come esito della prima
    // esecuzione, esattamente come farebbe il cron alla sua prima chiamata:
    // nessun confronto da fare (non esiste ancora una ripresa precedente),
    // diventa semplicemente il punto di partenza.
    $runNow = $body['run_now'] ?? true;
    $baseline = null;
    if ($runNow) {
        try {
            $fetchParams = $params;
            $fetchParams['study_id'] = $studyId;
            $fetchParams['source'] = $source;
            if ($source === 'sentinelhub' || $source === 'sentinel1') {
                $fetchParams['date_to'] = date('Y-m-d');
                $fetchParams['date_from'] = date('Y-m-d', strtotime('-' . $params['date_window_days'] . ' days'));
            }
            $baseline = CaptureFetcher::fetchAndSave($fetchParams);
            ScheduledDownload::recordRun($scheduleId, 'new', $baseline['capture_id']);
            Alert::create($studyId, $baseline['capture_id'], $scheduleId, 'Prima ripresa pianificata scaricata.');
        } catch (CaptureFetchException $e) {
            ScheduledDownload::recordRun($scheduleId, 'error', null, $e->getMessage());
            respond_json(['id' => $scheduleId, 'warning' => 'Pianificazione creata ma il primo scaricamento è fallito: ' . $e->getMessage()], 200);
        }
    }

    respond_json(['id' => $scheduleId, 'baseline_capture_id' => $baseline['capture_id'] ?? null]);
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) {
        respond_json(['error' => 'id mancante'], 400);
    }
    ScheduledDownload::delete($id);
    respond_json(['ok' => true]);
}

respond_json(['error' => 'Metodo non consentito'], 405);
