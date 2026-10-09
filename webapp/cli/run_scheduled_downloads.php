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
 * Per ogni pianificazione dovuta si controlla prima se la fonte ha
 * davvero qualcosa di nuovo, e solo allora si scarica:
 *  - Sentinel-2: un passaggio successivo all'ultimo scaricato, con nuvole
 *    sull'area entro la soglia (vedi ImageryCatalog);
 *  - Sentinel-1: un passaggio successivo della stessa orbita relativa
 *    (stessa geometria di vista, confrontabile);
 *  - Esri: la data delle immagini dell'area (metadati ufficiali) diversa da
 *    quella dell'ultima ripresa — il mosaico si aggiorna di rado, e prima
 *    si scaricava e scartava ogni giorno la stessa immagine.
 * Se non c'è nulla di nuovo l'esito è 'no_new' e non si scarica niente.
 * Una ripresa nuova viene confrontata con la precedente (riusando
 * /analysis/compare, la stessa pipeline SSIM+maschera del confronto
 * manuale) per riportare la variazione nell'alert. Solo per Esri senza
 * metadati leggibili resta il vecchio criterio: sotto la soglia di
 * duplicato la ripresa viene scartata.
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

/** Metadati JSON di una ripresa come array. */
function sched_meta(?array $capture): array
{
    $meta = $capture ? json_decode($capture['meta_json'] ?? '', true) : null;
    return is_array($meta) ? $meta : [];
}

/** "08/10/2026 10:00 UTC" per una data/ora ISO, "08/10/2026" per una data. */
function sched_it(?string $iso): string
{
    if (!$iso) {
        return 'data non nota';
    }
    return strlen($iso) > 10 ? ImageryCatalog::label($iso) : date('d/m/Y', strtotime($iso));
}

foreach ($due as $sched) {
    $id = (int) $sched['id'];
    $studyId = (int) $sched['study_id'];
    $source = $sched['source'];
    $params = json_decode($sched['params_json'], true) ?: [];
    $params['study_id'] = $studyId;
    $params['source'] = $source;

    $prevCaptureId = !empty($sched['last_capture_id']) ? (int) $sched['last_capture_id'] : null;
    $prevCapture = $prevCaptureId ? Capture::find($prevCaptureId) : null;
    $prevMeta = sched_meta($prevCapture);

    sched_log("Pianificazione #$id (studio #$studyId, fonte $source): controllo...");

    // ---- C'è qualcosa di nuovo? ----
    $newInfo = null;       // descrizione della novità, per l'alert
    $knownPass = null;     // passaggio già letto dal catalogo
    $knownChange = false;  // novità certa (nuovo passaggio / nuova data Esri): la ripresa si tiene
    if ($source === 'sentinelhub' || $source === 'sentinel1') {
        $isS1 = $source === 'sentinel1';
        $prevPass = $prevMeta[$isS1 ? 's1_pass' : 's2_pass'] ?? null;
        // Limite anche per le pianificazioni create prima dei passaggi (la
        // finestra del vecchio mosaico non aveva un massimo, e oltre 400
        // giorni la ricerca verrebbe rifiutata a ogni giro).
        $window = max(1, min(365, (int) ($params['date_window_days'] ?? 30)));
        $from = date('Y-m-d', strtotime("-$window days"));
        // Da dopo l'ultima ripresa: il suo passaggio o, per una ripresa del
        // vecchio mosaico (senza passaggio), la fine del suo intervallo.
        $after = $prevPass['datetime'] ?? (!empty($prevCapture['capture_date']) ? substr($prevCapture['capture_date'], 0, 10) . 'T23:59:59Z' : null);
        if ($after !== null) {
            $from = max($from, substr($after, 0, 10));
        }
        try {
            $bbox = array_map('floatval', $params['bbox']);
            $found = $isS1 ? ImageryCatalog::sentinel1($bbox, $from, date('Y-m-d')) : ImageryCatalog::sentinel2($bbox, $from, date('Y-m-d'));
        } catch (Throwable $e) {
            sched_log('  ERRORE nella ricerca dei passaggi: ' . $e->getMessage());
            ScheduledDownload::recordRun($id, 'error', null, 'ricerca passaggi: ' . $e->getMessage());
            continue;
        }
        $pass = $isS1
            ? ImageryCatalog::bestSentinel1($found['passes'], isset($prevPass['relative_orbit']) ? (int) $prevPass['relative_orbit']
                : (isset($params['relative_orbit']) ? (int) $params['relative_orbit'] : null), $after)
            : ImageryCatalog::bestSentinel2($found['passes'], max(0, min(100, (int) ($params['max_cloud_coverage'] ?? 20))) / 100, $after);
        if (!$pass) {
            sched_log(sprintf('  Nessun passaggio nuovo utilizzabile (%d trovati dal %s).', count($found['passes']), $from));
            ScheduledDownload::recordRun($id, 'no_new', null);
            continue;
        }
        $params['pass_datetime'] = $pass['datetime'];
        $knownPass = $pass;
        $knownChange = true;
        $newInfo = 'Nuovo passaggio ' . ($isS1 ? 'Sentinel-1' : 'Sentinel-2') . ' del ' . sched_it($pass['datetime'])
            . (!$isS1 && isset($pass['aoi_cloud']) ? ' (nuvole sull\'area ' . round($pass['aoi_cloud'] * 100) . '%)' : '')
            . ($prevPass ? ', il precedente era del ' . sched_it($prevPass['datetime']) : '');
    } elseif ($source === 'esri' && $prevCapture && !empty($prevMeta['esri_imagery']['acquisitions']) && !empty($prevMeta['bbox'])) {
        // Stessa area e stessa scala usate al download della ripresa
        // precedente (vedi CaptureFetcher), così il livello di metadati
        // interrogato è lo stesso e il confronto delle date è affidabile.
        try {
            $mpp = Capture::resolveMpp([
                'meta_json' => json_encode(['bbox' => $prevMeta['bbox']]),
                'width' => $prevCapture['width'], 'height' => $prevCapture['height'],
            ]);
            $current = EsriImageryMetadata::query($prevMeta['fetch_aabb'] ?? $prevMeta['bbox'], (float) ($mpp['mpp_x'] ?? 0));
            if (EsriImageryMetadata::signature($current) === EsriImageryMetadata::signature($prevMeta['esri_imagery'])) {
                sched_log('  Immagini Esri invariate (immagine del ' . sched_it($current['dominant_date']) . '): nessuno scaricamento.');
                ScheduledDownload::recordRun($id, 'no_new', null);
                continue;
            }
            $knownChange = true;
            $newInfo = 'Esri ha aggiornato le immagini dell\'area: ora immagine del ' . sched_it($current['dominant_date'])
                . ', prima del ' . sched_it($prevMeta['esri_imagery']['dominant_date'] ?? null);
        } catch (Throwable $e) {
            // Metadati non leggibili: si scarica e si confronta come prima.
            sched_log('  Metadati Esri non leggibili (' . $e->getMessage() . '): confronto per pixel.');
        }
    }

    // ---- Scaricamento ----
    try {
        $result = CaptureFetcher::fetchAndSave($params, $knownPass);
    } catch (CaptureFetchException $e) {
        sched_log('  ERRORE: ' . $e->getMessage());
        ScheduledDownload::recordRun($id, 'error', null, $e->getMessage());
        continue;
    }
    $newCaptureId = $result['capture_id'];

    if ($prevCaptureId === null) {
        sched_log('  Prima ripresa della pianificazione: nessun confronto da fare, diventa la base.');
        ScheduledDownload::recordRun($id, 'new', $newCaptureId);
        Alert::create($studyId, $newCaptureId, $id, 'Prima ripresa pianificata scaricata.');
        continue;
    }
    if (!$prevCapture) {
        // La ripresa precedente non esiste più (es. eliminata a mano
        // dall'utente nel frattempo): non c'è più nulla con cui confrontare,
        // si riparte da questa come nuova base.
        sched_log('  Ripresa precedente non più esistente: questa diventa la nuova base.');
        ScheduledDownload::recordRun($id, 'new', $newCaptureId);
        Alert::create($studyId, $newCaptureId, $id, ($newInfo ? $newInfo . '. ' : '') . 'Nuova ripresa pianificata scaricata (la precedente non è più disponibile per il confronto).');
        continue;
    }

    // ---- Confronto con la precedente ----
    try {
        // Stessa scelta del confronto dalla pagina: sotto 1,5 m/pixel lo
        // SSIM segna come cambiata quasi tutta l'area fra due immagini
        // diverse, e la percentuale dell'alert non direbbe nulla.
        $prevMpp = Capture::resolveMpp($prevCapture);
        $cmp = $client->post('/analysis/compare', [
            'capture_a_path' => $prevCapture['relative_path'],
            'capture_b_path' => $result['relative_path'],
            'align' => true,
            'diff_method' => ComparisonRunner::resolveDiffMethod('auto', $prevMpp),
            'mpp_x' => $prevMpp['mpp_x'] ?? null,
            'mpp_y' => $prevMpp['mpp_y'] ?? null,
            'analysis_scale_m' => ComparisonRunner::ROBUST_SCALE_M,
            // Allineamento dalle coordinate, come dalla pagina (vedi
            // ComparisonRunner::geoControlPoints).
            'geo_points' => ($newCapture = Capture::find($newCaptureId))
                ? (ComparisonRunner::geoControlPoints($prevCapture, $newCapture) ?? []) : [],
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
        Alert::create($studyId, $newCaptureId, $id, ($newInfo ? $newInfo . '. ' : 'Nuova ripresa scaricata. ') . 'Confronto automatico con la precedente non riuscito: verificala a mano.');
        continue;
    }

    if ($knownChange) {
        // Nuovo passaggio o nuova immagine Esri: è un dato nuovo in ogni
        // caso, si tiene anche se la variazione è minima.
        sched_log(sprintf('  %s. Variazione rilevata %.4f%%: tenuta, genero alert.', $newInfo, $changedRatio * 100));
        ScheduledDownload::recordRun($id, 'new', $newCaptureId);
        Alert::create($studyId, $newCaptureId, $id, sprintf('%s. Variazione rilevata rispetto alla ripresa precedente: %.2f%%.', $newInfo, $changedRatio * 100));
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
