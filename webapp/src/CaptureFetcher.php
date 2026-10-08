<?php

final class CaptureFetchException extends RuntimeException
{
    /** Codice HTTP suggerito per chi chiama via api/fetch_capture.php (ignorato dal cron). */
    public int $httpStatus;

    public function __construct(string $message, int $httpStatus = 400, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->httpStatus = $httpStatus;
    }
}

/**
 * Logica di scaricamento di una ripresa (Sentinel-2, Sentinel-1, Esri
 * attuale o storico), condivisa
 * tra api/fetch_capture.php (richiesta interattiva dall'utente) e
 * cli/run_scheduled_downloads.php (eseguito da cron per gli scaricamenti
 * pianificati — vedi ScheduledDownload.php): stesso identico comportamento
 * nei due casi, un solo posto da mantenere. Vedi anche ImageRotateCrop.php
 * per la rotazione dell'area di interesse.
 */
final class CaptureFetcher
{
    /** Timeout (s) di attesa del servizio di analisi per un download: vero
     * colore e banda NIR vengono scaricati in sequenza, e il servizio ha i
     * propri tetti di tempo (vedi esri_client.py/sentinelhub_client.py)
     * tarati per restare sotto questo valore. Con i 60 s generici il PHP
     * poteva rinunciare mentre il servizio stava ancora scrivendo i file,
     * che restavano orfani. */
    private const FETCH_TIMEOUT = 180;

    /** Allinea larghezza/altezza al file realmente salvato. Il file è la
     * fonte di verità: dopo un tentativo a risoluzione ridotta di Esri le
     * dimensioni registrate erano quelle richieste, non quelle reali, e la
     * scala calcolata su di esse falsava distanze e aree (la metà e un
     * quarto del vero su un'immagine 501×501 registrata come 1024×1024). */
    private static function syncRealSize(array &$result): void
    {
        $size = @getimagesize(Config::storageRoot() . '/' . $result['relative_path']);
        if ($size) {
            $result['width'] = $size[0];
            $result['height'] = $size[1];
        }
    }

    /** Rimuove i file già scritti di un download non andato a buon fine. */
    private static function discardFiles(array $relativePaths): void
    {
        foreach ($relativePaths as $rel) {
            if ($rel && strpos($rel, '..') === false) {
                @unlink(Config::storageRoot() . '/' . $rel);
            }
        }
    }

    /**
     * @param array $params Body JSON di api/fetch_capture.php: study_id,
     *   source ('sentinelhub' = Sentinel-2, 'sentinel1', 'esri'), bbox, width,
     *   height, rotation; per le fonti Copernicus pass_datetime (un passaggio
     *   preciso) oppure date_from/date_to (il migliore del periodo, con
     *   max_cloud_coverage sull'area per Sentinel-2 e relative_orbit
     *   facoltativa per Sentinel-1); per Esri wayback_release facoltativo
     *   (una versione storica dell'archivio Wayback).
     * @param array|null $knownPass Passaggio già letto dal catalogo (dal cron):
     *   evita una seconda ricerca, che se fallisse per un momento lascerebbe
     *   la ripresa senza orbita/nuvole — e il cron perderebbe il filtro
     *   sull'orbita radar al giro successivo.
     * @return array{capture_id:int, relative_path:string, width:int, height:int}
     * @throws CaptureFetchException Messaggio già pronto per l'utente/il log.
     */
    public static function fetchAndSave(array $params, ?array $knownPass = null): array
    {
        $studyId = (int) ($params['study_id'] ?? 0);
        $study = $studyId ? Study::find($studyId) : null;
        if (!$study) {
            throw new CaptureFetchException('Studio non trovato', 404);
        }

        $bbox = $params['bbox'] ?? null;
        if (!is_array($bbox) || count($bbox) !== 4 || !array_reduce($bbox, fn($ok, $v) => $ok && is_numeric($v), true)) {
            throw new CaptureFetchException('Bounding box mancante o non valida (min_lon,min_lat,max_lon,max_lat)');
        }
        [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $bbox);
        if ($minLon >= $maxLon || $minLat >= $maxLat) {
            throw new CaptureFetchException('Bounding box non valida: "Min Lon" deve essere minore di "Max Lon" e "Min Lat" minore di "Max Lat" (hai forse invertito due valori?).');
        }
        if ($minLon < -180 || $maxLon > 180 || $minLat < -90 || $maxLat > 90) {
            throw new CaptureFetchException('Bounding box fuori range: longitudine tra -180 e 180, latitudine tra -90 e 90.');
        }

        $source = $params['source'] ?? 'sentinelhub';
        if (!in_array($source, ['sentinelhub', 'sentinel1', 'esri'], true)) {
            throw new CaptureFetchException('Fonte non valida');
        }
        // Limiti coerenti con quelli delle fonti (Sentinel Hub rifiuta oltre
        // 2500px per lato; Esri ha un tetto di complessità non documentato,
        // gestito con i tentativi a risoluzione ridotta in esri_client.py).
        // Senza questo controllo un valore assurdo — o negativo — veniva
        // inoltrato tale e quale al servizio di analisi, che tentava di
        // allocare l'immagine richiesta.
        $width = max(64, min((int) ($params['width'] ?? 1024), 2500));
        $height = max(64, min((int) ($params['height'] ?? 1024), 2500));

        // Rotazione dell'area di interesse (vedi map-picker.js + ImageRotateCrop.php):
        // $bbox resta sempre il rettangolo "di base" (non ruotato) scelto
        // dall'utente — è quello che viene salvato in meta_json e usato per il
        // calcolo della scala, invariato rispetto a prima. Se la rotazione è
        // diversa da zero, si scarica invece un'area di raccolta più ampia
        // ($fetchBbox, che racchiude il rettangolo ruotato) e la si ritaglia dopo.
        $rotation = (float) ($params['rotation'] ?? 0);
        if (!is_finite($rotation)) $rotation = 0.0;
        $rotation = max(-180.0, min(180.0, $rotation));
        $fetchBbox = $bbox;
        $fetchWidth = $width;
        $fetchHeight = $height;
        if (abs($rotation) >= 0.01) {
            $fetchBbox = ImageRotateCrop::enclosingBbox([$minLon, $minLat, $maxLon, $maxLat], $rotation);
            // Non può comunque uscire dai range validi lon/lat: un margine perso
            // ai bordi in casi estremi (area vicina a polo/antimeridiano) è accettato.
            $fetchBbox = [
                max(-180.0, $fetchBbox[0]), max(-90.0, $fetchBbox[1]),
                min(180.0, $fetchBbox[2]), min(90.0, $fetchBbox[3]),
            ];
            [$fetchWidth, $fetchHeight] = ImageRotateCrop::scaledFetchSize([$minLon, $minLat, $maxLon, $maxLat], $fetchBbox, $width, $height);
        }

        $client = new PythonServiceClient();
        $rect = [$minLon, $minLat, $maxLon, $maxLat];

        if ($source === 'sentinelhub' || $source === 'sentinel1') {
            $pass = ($knownPass && ($knownPass['datetime'] ?? null) === ($params['pass_datetime'] ?? null))
                ? $knownPass
                : self::resolvePass($source, $params, $rect);
            $passDate = substr($pass['datetime'], 0, 10);
            $isS1 = $source === 'sentinel1';

            try {
                $result = $isS1
                    ? $client->post('/fetch/sentinel1', [
                        'bbox' => array_map('floatval', $fetchBbox),
                        'pass_datetime' => $pass['datetime'],
                        'width' => $fetchWidth,
                        'height' => $fetchHeight,
                    ], self::FETCH_TIMEOUT)
                    : $client->post('/fetch/sentinelhub', [
                        'bbox' => array_map('floatval', $fetchBbox),
                        'date_from' => $passDate,
                        'date_to' => $passDate,
                        'pass_datetime' => $pass['datetime'],
                        'width' => $fetchWidth,
                        'height' => $fetchHeight,
                    ], self::FETCH_TIMEOUT);
            } catch (PythonServiceException $e) {
                throw new CaptureFetchException($e->getMessage(), 502, $e);
            }

            if (abs($rotation) >= 0.01) {
                try {
                    // Sentinel Hub non applica correzioni di aspect ratio (a
                    // differenza di Esri sotto): la bbox effettivamente coperta
                    // è sempre esattamente quella richiesta, $fetchBbox.
                    $cropped = self::applyRotationToStoredImage($result['relative_path'], $fetchBbox, $rect, $rotation);
                    $result['relative_path'] = $cropped['relative_path'];
                    $result['width'] = $cropped['width'];
                    $result['height'] = $cropped['height'];
                    if (!empty($result['nir_relative_path'])) {
                        $croppedNir = self::applyRotationToStoredImage($result['nir_relative_path'], $fetchBbox, $rect, $rotation);
                        $result['nir_relative_path'] = $croppedNir['relative_path'];
                    }
                } catch (Throwable $e) {
                    self::discardFiles([$result['relative_path'] ?? null, $result['nir_relative_path'] ?? null]);
                    throw new CaptureFetchException('Ripresa scaricata ma ritaglio ruotato fallito: ' . $e->getMessage(), 500, $e);
                }
            }
            self::syncRealSize($result);

            $label = $isS1
                ? 'Sentinel-1 SAR — ' . ImageryCatalog::label($pass['datetime'])
                    . (isset($pass['relative_orbit']) ? ' (orbita ' . (int) $pass['relative_orbit']
                        . (($pass['orbit_state'] ?? '') === 'ascending' ? ' ascendente' : (($pass['orbit_state'] ?? '') === 'descending' ? ' discendente' : '')) . ')' : '')
                : 'Sentinel-2 — ' . ImageryCatalog::label($pass['datetime'])
                    . (isset($pass['aoi_cloud']) ? ' (nuvole sull\'area ' . round($pass['aoi_cloud'] * 100) . '%)' : '');

            $captureId = Capture::create(
                $studyId,
                $label,
                $source,
                $passDate,
                $result['relative_path'],
                $result['width'],
                $result['height'],
                array_filter([
                    'bbox' => $bbox,
                    'source' => $isS1 ? 'sentinel-1-grd' : 'sentinel-2-l2a',
                    // Il passaggio scaricato: data e ora UTC, satellite, orbita,
                    // nuvole e copertura sull'area (vedi ImageryCatalog).
                    $isS1 ? 's1_pass' : 's2_pass' => $pass,
                    'date_from' => $passDate, 'date_to' => $passDate,
                    'fetched_at' => date('c'),
                    'nir_relative_path' => $result['nir_relative_path'] ?? null,
                    // Guadagno della coppia Rosso+NIR (vedi sentinelhub_client.py):
                    // serve a NDWI/falso colore per combinarla col vero colore.
                    'nir_gain' => !empty($result['nir_relative_path']) ? ($result['nir_gain'] ?? null) : null,
                    'rotation' => abs($rotation) >= 0.01 ? $rotation : null,
                    'rotation_model' => abs($rotation) >= 0.01 ? ImageRotateCrop::ROTATION_MODEL : null,
                    'fetch_aabb' => abs($rotation) >= 0.01 ? $fetchBbox : null,
                ], fn($v) => $v !== null)
            );
        } else {
            // Versione storica dell'archivio Wayback (vedi EsriWayback) o
            // mosaico corrente.
            $wayback = null;
            if (!empty($params['wayback_release'])) {
                try {
                    $wayback = EsriWayback::release((int) $params['wayback_release']);
                } catch (Throwable $e) {
                    throw new CaptureFetchException('Archivio Wayback non raggiungibile: ' . $e->getMessage(), 502, $e);
                }
                if (!$wayback) {
                    throw new CaptureFetchException('Versione Wayback non trovata.', 404);
                }
            }
            try {
                $result = $wayback
                    ? $client->post('/fetch/wayback', [
                        'bbox' => array_map('floatval', $fetchBbox),
                        'release' => $wayback['release'],
                        'width' => $fetchWidth,
                        'height' => $fetchHeight,
                    ], self::FETCH_TIMEOUT)
                    : $client->post('/fetch/esri', [
                        'bbox' => array_map('floatval', $fetchBbox),
                        'width' => $fetchWidth,
                        'height' => $fetchHeight,
                    ], self::FETCH_TIMEOUT);
            } catch (PythonServiceException $e) {
                throw new CaptureFetchException($e->getMessage(), 502, $e);
            }

            // Bbox EFFETTIVAMENTE coperta dall'immagine scaricata: Esri, se
            // l'aspect ratio richiesto non combacia, la espande ulteriormente
            // per evitare distorsioni (vedi _adjust_bbox_to_aspect in
            // esri_client.py) — è quella (non $fetchBbox) il vero riferimento
            // geografico dei pixel scaricati, necessario per un ritaglio
            // ruotato accurato.
            $actualFetchedBbox = $result['bbox'] ?? $fetchBbox;
            if (abs($rotation) >= 0.01) {
                try {
                    $cropped = self::applyRotationToStoredImage($result['relative_path'], $actualFetchedBbox, $rect, $rotation);
                    $result['relative_path'] = $cropped['relative_path'];
                    $result['width'] = $cropped['width'];
                    $result['height'] = $cropped['height'];
                } catch (Throwable $e) {
                    self::discardFiles([$result['relative_path'] ?? null]);
                    throw new CaptureFetchException('Ripresa scaricata ma ritaglio ruotato fallito: ' . $e->getMessage(), 500, $e);
                }
            }
            self::syncRealSize($result);

            // Data REALE delle immagini (non del download): metadati ufficiali
            // Esri sull'area effettivamente scaricata, alla scala della ripresa.
            $metaBbox = (abs($rotation) >= 0.01) ? $bbox : ($result['bbox'] ?? $bbox);
            $imagery = null;
            $imageryError = null;
            try {
                $areaBbox = (abs($rotation) >= 0.01) ? $actualFetchedBbox : $metaBbox;
                $mpp = Capture::resolveMpp([
                    'meta_json' => json_encode(['bbox' => $metaBbox]),
                    'width' => $result['width'], 'height' => $result['height'],
                ]);
                $imagery = $wayback
                    ? EsriWayback::imageryFor($wayback['release'], $areaBbox, (float) ($mpp['mpp_x'] ?? 0))
                    : EsriImageryMetadata::query($areaBbox, (float) ($mpp['mpp_x'] ?? 0));
            } catch (Throwable $e) {
                // Non deve far fallire il download: la data si può recuperare
                // in seguito (cli/refresh_esri_metadata.php o dalla vista di analisi).
                $imageryError = $e->getMessage();
            }

            $captureId = Capture::create(
                $studyId,
                ImageryAttribution::esriCaptureLabel($imagery, $wayback['date'] ?? null),
                'esri',
                $imagery['dominant_date'] ?? null,
                $result['relative_path'],
                $result['width'],
                $result['height'],
                array_filter([
                    'bbox' => $metaBbox,
                    'esri_imagery' => $imagery,
                    'esri_imagery_error' => $imageryError,
                    'wayback' => $wayback ? [
                        'release' => $wayback['release'],
                        'release_date' => $wayback['date'],
                        'zoom' => $result['zoom'] ?? null,
                    ] : null,
                    'source' => $wayback ? 'esri-world-imagery-wayback' : 'esri-world-imagery', 'fetched_at' => date('c'),
                    'rotation' => abs($rotation) >= 0.01 ? $rotation : null,
                    'rotation_model' => abs($rotation) >= 0.01 ? ImageRotateCrop::ROTATION_MODEL : null,
                    'fetch_aabb' => abs($rotation) >= 0.01 ? $actualFetchedBbox : null,
                ], fn($v) => $v !== null)
            );
        }

        Study::touch($studyId);

        return [
            'capture_id' => $captureId,
            'relative_path' => $result['relative_path'],
            'width' => $result['width'],
            'height' => $result['height'],
        ];
    }

    private const PASS_DATETIME_RE = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    /**
     * Il passaggio da scaricare: quello indicato (pass_datetime, scelto
     * dall'elenco "Cerca passaggi" o dal cron) oppure, dato un periodo, il
     * migliore del periodo — per Sentinel-2 il più recente con nuvole
     * sull'area entro la soglia, per Sentinel-1 il più recente (della
     * stessa orbita relativa, se indicata).
     */
    private static function resolvePass(string $source, array $params, array $rect): array
    {
        $isS1 = $source === 'sentinel1';
        $passDatetime = $params['pass_datetime'] ?? null;
        if ($passDatetime !== null && $passDatetime !== '') {
            if (!is_string($passDatetime) || !preg_match(self::PASS_DATETIME_RE, $passDatetime)) {
                throw new CaptureFetchException('Data/ora del passaggio non valida.');
            }
            if (strtotime($passDatetime) > time()) {
                throw new CaptureFetchException('Il passaggio indicato è nel futuro.');
            }
            // Dettagli del passaggio dal catalogo (satellite, orbita, nuvole).
            // Se il catalogo non risponde si scarica comunque, con la sola
            // data; se risponde e non lo conosce, il passaggio non esiste per
            // quest'area e fonte (es. un passaggio Sentinel-2 chiesto come
            // Sentinel-1): si salverebbe un'immagine vuota con una data precisa.
            try {
                $pass = ImageryCatalog::findPass($source, $rect, $passDatetime);
            } catch (Throwable $e) {
                return ['datetime' => $passDatetime, 'date' => substr($passDatetime, 0, 10)];
            }
            if (!$pass) {
                throw new CaptureFetchException('Passaggio non trovato nel catalogo ' . ($isS1 ? 'Sentinel-1' : 'Sentinel-2') . " per quest'area: ripeti la ricerca dei passaggi.", 404);
            }
            return $pass;
        }

        $dateFrom = $params['date_from'] ?? null;
        $dateTo = $params['date_to'] ?? null;
        if (!$dateFrom || !$dateTo) {
            throw new CaptureFetchException('Intervallo date mancante');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $dateTo)) {
            throw new CaptureFetchException('Formato data non valido.');
        }
        if (strtotime($dateFrom) > strtotime($dateTo)) {
            throw new CaptureFetchException('Intervallo date non valido: "Da data" (' . $dateFrom . ') deve essere precedente a "A data" (' . $dateTo . '). Controlla di non averle invertite.');
        }
        if (strtotime($dateTo) > time()) {
            throw new CaptureFetchException('Intervallo date non valido: "A data" (' . $dateTo . ') è nel futuro — Copernicus non ha ancora immagini per quella data.');
        }
        try {
            $found = $isS1 ? ImageryCatalog::sentinel1($rect, $dateFrom, $dateTo) : ImageryCatalog::sentinel2($rect, $dateFrom, $dateTo);
        } catch (RuntimeException $e) {
            throw new CaptureFetchException('Ricerca dei passaggi non riuscita: ' . $e->getMessage(), 502, $e);
        }
        $passes = $found['passes'];
        if (!$passes) {
            throw new CaptureFetchException('Nessun passaggio ' . ($isS1 ? 'Sentinel-1' : 'Sentinel-2') . " sull'area tra il $dateFrom e il $dateTo.", 404);
        }
        if ($isS1) {
            $orbit = isset($params['relative_orbit']) && $params['relative_orbit'] !== '' ? (int) $params['relative_orbit'] : null;
            $pass = ImageryCatalog::bestSentinel1($passes, $orbit);
            if (!$pass) {
                throw new CaptureFetchException("Nessun passaggio Sentinel-1 dell'orbita $orbit nel periodo.", 404);
            }
            return $pass;
        }
        $maxCloud = max(0, min(100, (int) ($params['max_cloud_coverage'] ?? 20))) / 100;
        $pass = ImageryCatalog::bestSentinel2($passes, $maxCloud);
        if (!$pass) {
            throw new CaptureFetchException(sprintf(
                "Nessuno dei %d passaggi Sentinel-2 tra il %s e il %s ha meno del %d%% di nuvole sull'area (o la copre per intero). Usa \"Cerca passaggi\" per vederli tutti, o alza la soglia.",
                count($passes), $dateFrom, $dateTo, (int) round($maxCloud * 100)
            ), 404);
        }
        return $pass;
    }

    /**
     * Applica il ritaglio ruotato (vedi ImageRotateCrop) a un file già salvato
     * dal servizio Python nello storage condiviso, sostituendolo con la
     * versione ritagliata: il file "grezzo" allargato (l'intera area di
     * raccolta scaricata per racchiudere il rettangolo ruotato) non serve a
     * nessuno degli usi successivi della ripresa, quindi viene eliminato.
     */
    private static function applyRotationToStoredImage(string $relativePath, array $fetchedBboxActual, array $rect, float $rotation): array
    {
        $storageRoot = Config::storageRoot();
        $absPath = $storageRoot . '/' . $relativePath;
        $bytes = @file_get_contents($absPath);
        if ($bytes === false) {
            throw new RuntimeException("impossibile leggere \"$relativePath\" per il ritaglio ruotato");
        }
        $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)) ?: 'png';
        $format = $ext === 'png' ? 'png' : 'jpg';
        // Nessuna dimensione forzata: il ritaglio finale mantiene sempre il
        // vero rapporto d'aspetto di $rect (vedi ImageRotateCrop::rotateAndCrop),
        // altrimenti risulterebbe visibilmente distorto su aree non quadrate.
        $croppedBytes = ImageRotateCrop::rotateAndCrop($bytes, $format, $fetchedBboxActual, $rect, $rotation);
        $newRelative = 'raw/' . bin2hex(random_bytes(8)) . '.' . $ext;
        file_put_contents($storageRoot . '/' . $newRelative, $croppedBytes);
        $size = getimagesizefromstring($croppedBytes);
        @unlink($absPath);
        return ['relative_path' => $newRelative, 'width' => $size[0], 'height' => $size[1]];
    }
}
