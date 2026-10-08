<?php
/**
 * Entrypoint da cron per gli scaricamenti automatici pianificati (vedi
 * schema.sql: scheduled_downloads/alerts, src/ScheduledDownload.php,
 * src/Alert.php, src/CaptureFetcher.php, webapp/public/api/schedule_download.php
 * per la creazione dal browser).
 *
 * Uso: php run_scheduled_downloads.php
 * Pensato per un cron che gira ogni giorno (o più spesso: ScheduledDownload::due()
 * filtra da sola quali pianificazioni sono realmente dovute in base al proprio
 * interval_days, quindi eseguire questo script più spesso del necessario è
 * innocuo, semplicemente non troverà nulla da fare per la maggior parte delle
 * chiamate).
 *
 * Per ogni pianificazione dovuta: scarica una nuova ripresa con la stessa
 * identica "ricetta" (bbox/rotazione/parametri) usata dall'utente, la
 * confronta con l'ultima ripresa già tenuta da questa pianificazione
 * (riusando /analysis/compare, la stessa identica pipeline SSIM+maschera
 * usata per il confronto manuale in piattaforma) e:
 *  - se la variazione rilevata è sotto la soglia di duplicato configurata:
 *    scarta automaticamente la nuova ripresa (nessun accumulo di doppioni
 *    identici nell'archivio);
 *  - altrimenti: la tiene e genera un alert (vedi Alert.php + partials/nav.php
 *    + alerts.php) per segnalare che è arrivata una ripresa diversa.
 */
require __DIR__ . '/../src/bootstrap.php';

function sched_log(string $msg): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] $msg\n");
}

// Una sola esecuzione per volta. Uno scaricamento + confronto può durare
// minuti (Esri ritenta a risoluzione ridotta fino a 4 volte): se un'altra
// esecuzione partisse nel frattempo — dal cron o lanciata a mano — entrambe
// vedrebbero le stesse pianificazioni come "dovute" e scaricherebbero due
// volte, generando riprese e alert duplicati.
$lockFile = __DIR__ . '/logs/run_scheduled_downloads.lock';
@mkdir(dirname($lockFile), 0770, true);
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    sched_log('Un\'altra esecuzione è già in corso: esco senza fare nulla.');
    exit(0);
}

$due = ScheduledDownload::due();
if (!$due) {
    sched_log('Nessuna pianificazione dovuta.');
}

$client = new PythonServiceClient();

foreach ($due as $sched) {
    $id = (int) $sched['id'];
    $studyId = (int) $sched['study_id'];
    $source = $sched['source'];
    $params = json_decode($sched['params_json'], true) ?: [];
    $params['study_id'] = $studyId;
    $params['source'] = $source;

    // Finestra date "scorrevole" per Sentinel Hub: ricalcolata ad ogni
    // esecuzione rispetto a "oggi", non memorizzata come date fisse (vedi
    // api/schedule_download.php).
    if ($source === 'sentinelhub' && isset($params['date_window_days'])) {
        $params['date_to'] = date('Y-m-d');
        $params['date_from'] = date('Y-m-d', strtotime('-' . (int) $params['date_window_days'] . ' days'));
    }

    sched_log("Pianificazione #$id (studio #$studyId, fonte $source): avvio fetch...");

    try {
        $result = CaptureFetcher::fetchAndSave($params);
    } catch (CaptureFetchException $e) {
        sched_log('  ERRORE: ' . $e->getMessage());
        ScheduledDownload::recordRun($id, 'error', null, $e->getMessage());
        continue;
    }

    $newCaptureId = $result['capture_id'];
    $prevCaptureId = !empty($sched['last_capture_id']) ? (int) $sched['last_capture_id'] : null;

    if ($prevCaptureId === null) {
        sched_log('  Prima ripresa della pianificazione: nessun confronto da fare, diventa la base.');
        ScheduledDownload::recordRun($id, 'new', $newCaptureId);
        Alert::create($studyId, $newCaptureId, $id, 'Prima ripresa pianificata scaricata.');
        continue;
    }

    $prevCapture = Capture::find($prevCaptureId);
    if (!$prevCapture) {
        // La ripresa precedente non esiste più (es. eliminata a mano
        // dall'utente nel frattempo): non c'è più nulla con cui confrontare,
        // si riparte da questa come nuova base.
        sched_log('  Ripresa precedente non più esistente: questa diventa la nuova base.');
        ScheduledDownload::recordRun($id, 'new', $newCaptureId);
        Alert::create($studyId, $newCaptureId, $id, 'Nuova ripresa pianificata scaricata (la precedente non è più disponibile per il confronto).');
        continue;
    }

    try {
        $cmp = $client->post('/analysis/compare', [
            'capture_a_path' => $prevCapture['relative_path'],
            'capture_b_path' => $result['relative_path'],
            'align' => true,
            'diff_method' => 'ssim',
        ]);
        $changedRatio = (float) ($cmp['stats']['changed_ratio'] ?? 1.0);
        // /analysis/compare scrive sempre una cartella results/<id>/ con sei
        // immagini. Qui serve solo il numero changed_ratio: nessuna riga
        // comparisons viene creata, quindi quei file non sarebbero
        // referenziati da nulla e nessuno li eliminerebbe mai — si
        // accumulavano a ogni singola esecuzione del cron.
        Comparison::deleteResultFiles(json_encode($cmp['paths'] ?? []));
    } catch (PythonServiceException $e) {
        // Non sappiamo se sia un duplicato: per prudenza la teniamo (un falso
        // "nuovo" da verificare a mano è preferibile a scartare per errore
        // una ripresa che potrebbe davvero mostrare un cambiamento).
        sched_log('  ERRORE nel confronto con la ripresa precedente: ' . $e->getMessage());
        ScheduledDownload::recordRun($id, 'new', $newCaptureId, 'confronto fallito: ' . $e->getMessage());
        Alert::create($studyId, $newCaptureId, $id, 'Nuova ripresa scaricata (confronto automatico con la precedente non riuscito: verificala a mano).');
        continue;
    }

    $threshold = (float) $sched['duplicate_threshold'];
    if ($changedRatio < $threshold) {
        sched_log(sprintf('  Duplicato (variazione %.4f%% < soglia %.4f%%): scarto.', $changedRatio * 100, $threshold * 100));
        Capture::delete($newCaptureId);
        ScheduledDownload::recordRun($id, 'duplicate', $prevCaptureId);
    } else {
        sched_log(sprintf('  Ripresa diversa (variazione %.4f%% >= soglia %.4f%%): tenuta, genero alert.', $changedRatio * 100, $threshold * 100));
        ScheduledDownload::recordRun($id, 'new', $newCaptureId);
        Alert::create($studyId, $newCaptureId, $id, sprintf('Nuova ripresa diversa dalla precedente (variazione rilevata: %.2f%%).', $changedRatio * 100));
    }
}

// Manutenzione dello storage: rimuove anteprime e risultati di confronto mai
// salvati (vedi StorageMaintenance). Gira insieme al cron già esistente,
// senza bisogno di una seconda voce in crontab.
$cleaned = StorageMaintenance::cleanOrphans();
if ($cleaned['processed'] || $cleaned['results']) {
    sched_log(sprintf(
        'Manutenzione storage: rimossi %d file di anteprima e %d risultati di confronto mai salvati (%s liberati).',
        $cleaned['processed'],
        $cleaned['results'],
        StorageMaintenance::formatBytes($cleaned['bytes'])
    ));
}

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
