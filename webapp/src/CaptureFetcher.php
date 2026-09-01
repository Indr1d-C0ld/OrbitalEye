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
 * Logica di scaricamento di una ripresa (Sentinel Hub / Esri), condivisa
 * tra api/fetch_capture.php (richiesta interattiva dall'utente) e
 * cli/run_scheduled_downloads.php (eseguito da cron per gli scaricamenti
 * pianificati — vedi ScheduledDownload.php): stesso identico comportamento
 * nei due casi, un solo posto da mantenere. Vedi anche ImageRotateCrop.php
 * per la rotazione dell'area di interesse.
 */
final class CaptureFetcher
{
    /**
     * @param array $params Stessa forma del body JSON che api/fetch_capture.php
     *   riceveva prima del refactor: study_id, source, bbox, width, height,
     *   rotation, e per sentinelhub anche date_from/date_to/max_cloud_coverage.
     * @return array{capture_id:int, relative_path:string, width:int, height:int}
     * @throws CaptureFetchException Messaggio già pronto per l'utente/il log.
     */
    public static function fetchAndSave(array $params): array
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
        if (!in_array($source, ['sentinelhub', 'esri'], true)) {
            throw new CaptureFetchException('Fonte non valida');
        }
        $width = (int) ($params['width'] ?? 1024);
        $height = (int) ($params['height'] ?? 1024);

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

        if ($source === 'sentinelhub') {
            $dateFrom = $params['date_from'] ?? null;
            $dateTo = $params['date_to'] ?? null;
            if (!$dateFrom || !$dateTo) {
                throw new CaptureFetchException('Intervallo date mancante');
            }
            if (strtotime($dateFrom) === false || strtotime($dateTo) === false) {
                throw new CaptureFetchException('Formato data non valido.');
            }
            if (strtotime($dateFrom) >= strtotime($dateTo)) {
                throw new CaptureFetchException('Intervallo date non valido: "Da data" (' . $dateFrom . ') deve essere precedente a "A data" (' . $dateTo . '). Controlla di non averle invertite.');
            }
            if (strtotime($dateTo) > time()) {
                throw new CaptureFetchException('Intervallo date non valido: "A data" (' . $dateTo . ') è nel futuro — Copernicus non ha ancora immagini per quella data.');
            }

            try {
                $result = $client->post('/fetch/sentinelhub', [
                    'bbox' => array_map('floatval', $fetchBbox),
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'width' => $fetchWidth,
                    'height' => $fetchHeight,
                    'max_cloud_coverage' => (int) ($params['max_cloud_coverage'] ?? 20),
                ]);
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
                    throw new CaptureFetchException('Ripresa scaricata ma ritaglio ruotato fallito: ' . $e->getMessage(), 500, $e);
                }
            }

            $captureId = Capture::create(
                $studyId,
                'Sentinel-2 ' . $dateFrom . ' → ' . $dateTo,
                'sentinelhub',
                $dateTo,
                $result['relative_path'],
                $result['width'],
                $result['height'],
                array_filter([
                    'bbox' => $bbox, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'source' => 'sentinel-2-l2a',
                    'nir_relative_path' => $result['nir_relative_path'] ?? null,
                    'rotation' => abs($rotation) >= 0.01 ? $rotation : null,
                    'fetch_aabb' => abs($rotation) >= 0.01 ? $fetchBbox : null,
                ], fn($v) => $v !== null)
            );
        } else {
            try {
                $result = $client->post('/fetch/esri', [
                    'bbox' => array_map('floatval', $fetchBbox),
                    'width' => $fetchWidth,
                    'height' => $fetchHeight,
                ]);
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
                    throw new CaptureFetchException('Ripresa scaricata ma ritaglio ruotato fallito: ' . $e->getMessage(), 500, $e);
                }
            }

            $captureId = Capture::create(
                $studyId,
                'Esri World Imagery — scaricata il ' . date('d/m/Y'),
                'esri',
                null,
                $result['relative_path'],
                $result['width'],
                $result['height'],
                array_filter([
                    'bbox' => (abs($rotation) >= 0.01) ? $bbox : ($result['bbox'] ?? $bbox),
                    'source' => 'esri-world-imagery', 'fetched_at' => date('c'),
                    'rotation' => abs($rotation) >= 0.01 ? $rotation : null,
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
        $croppedBytes = ImageRotateCrop::rotateAndCrop($bytes, $format, $fetchedBboxActual, $rect, $rotation);
        $newRelative = 'raw/' . bin2hex(random_bytes(8)) . '.' . $ext;
        file_put_contents($storageRoot . '/' . $newRelative, $croppedBytes);
        $size = getimagesizefromstring($croppedBytes);
        @unlink($absPath);
        return ['relative_path' => $newRelative, 'width' => $size[0], 'height' => $size[1]];
    }
}
