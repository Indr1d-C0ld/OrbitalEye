<?php

final class ComparisonException extends RuntimeException
{
    public int $httpStatus;

    public function __construct(string $message, int $httpStatus = 400)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }
}

/**
 * Esecuzione di un confronto fra due riprese (prima in api/compare.php):
 * validazione, filtri pre-analisi, allineamento, chiamata al servizio di
 * analisi, salvataggio. Usata dalla richiesta diretta e dai lavori in
 * background (Job), con lo stesso risultato.
 *
 * Allineamento (align_mode):
 *  - 'auto' (predefinito): dalle coordinate geografiche se entrambe le
 *    riprese sono georiferite — il metodo più robusto fra fonti ed epoche
 *    diverse, rifinito con ECC — altrimenti dalle immagini (ORB + ECC);
 *  - 'features': sempre dalle immagini;
 *  - 'manual': dai punti di controllo salvati per la coppia.
 */
final class ComparisonRunner
{
    /**
     * Metodi di confronto (diff_method), con l'etichetta per l'interfaccia.
     * 'auto' non arriva mai al servizio di analisi: diventa 'robust' o
     * 'ssim' secondo la risoluzione (vedi resolveDiffMethod).
     */
    public const DIFF_METHODS = [
        'auto' => 'Automatico (consigliato)',
        'robust' => 'Robusto: oggetti e colori a 2 m',
        'ssim' => 'SSIM: struttura pixel per pixel',
        'absdiff' => 'Differenza assoluta (veloce)',
    ];

    /**
     * Sotto questa risoluzione (m/pixel, asse più grossolano) 'auto' usa il
     * confronto robusto. Lo SSIM sotto il metro segna come cambiata quasi
     * tutta l'area fra riprese di epoche diverse (94-99% su Sigonella e
     * Ghedi) e basta 1,5 m di disallineamento della STESSA foto per passare
     * dallo 0% al 95%; a 10 m (Sentinel-2) resta il metodo adatto.
     */
    public const ROBUST_BELOW_MPP = 1.5;

    /** Scala del confronto robusto, in metri (vedi diff.py). */
    public const ROBUST_SCALE_M = 2.0;

    /**
     * 'auto' → metodo effettivo per la scala della ripresa A. Il confronto
     * robusto lavora a celle di ROBUST_SCALE_M metri: senza una scala nota
     * (o con una scala nulla nei metadati) non saprebbe quanto mediare, e
     * si usa lo SSIM anche se lo si è scelto a mano.
     */
    public static function resolveDiffMethod(string $requested, ?array $mpp): string
    {
        $known = $mpp && ($mpp['mpp_x'] ?? 0) > 0 && ($mpp['mpp_y'] ?? 0) > 0;
        if ($requested === 'auto') {
            return $known && max($mpp['mpp_x'], $mpp['mpp_y']) < self::ROBUST_BELOW_MPP ? 'robust' : 'ssim';
        }
        if ($requested === 'robust' && !$known) {
            return 'ssim';
        }
        return isset(self::DIFF_METHODS[$requested]) ? $requested : 'ssim';
    }
    /**
     * @param callable|null $progress fn(int $percent, string $message)
     * @return array risposta come quella di api/compare.php
     */
    public static function run(array $body, ?callable $progress = null): array
    {
        $progress = $progress ?? fn() => null;
        $studyId = (int) ($body['study_id'] ?? 0);
        $captureAId = (int) ($body['capture_a_id'] ?? 0);
        $captureBId = (int) ($body['capture_b_id'] ?? 0);

        $study = $studyId ? Study::find($studyId) : null;
        $captureA = $captureAId ? Capture::find($captureAId) : null;
        $captureB = $captureBId ? Capture::find($captureBId) : null;
        if (!$study || !$captureA || !$captureB) {
            throw new ComparisonException('Studio o riprese non trovati', 404);
        }
        if ((int) $captureA['study_id'] !== $studyId || (int) $captureB['study_id'] !== $studyId) {
            throw new ComparisonException('Le riprese non appartengono a questo studio');
        }

        $enhanceSteps = self::enhanceSteps($body['enhance'] ?? []);
        $alignMode = in_array($body['align_mode'] ?? 'auto', ['auto', 'features', 'manual'], true) ? ($body['align_mode'] ?? 'auto') : 'auto';
        $align = !empty($body['align']);

        // Allineamento manuale: i punti di controllo salvati per questa coppia.
        $controlPoints = [];
        if ($alignMode === 'manual') {
            $controlPoints = ManualControlPoints::getPoints($captureAId, $captureBId);
            if (count($controlPoints) < 3) {
                throw new ComparisonException('Servono almeno 3 punti di controllo salvati per questa coppia di riprese per usare l\'allineamento manuale');
            }
        }
        $geoPoints = ($align && $alignMode === 'auto') ? self::geoControlPoints($captureA, $captureB) : null;

        // Scala reale (metri/pixel) per esprimere le aree delle regioni
        // cambiate in m²: si prende dalla ripresa A (il diff avviene alla sua
        // risoluzione, B viene allineata su A) — vedi Capture::resolveMpp.
        $mpp = Capture::resolveMpp($captureA);
        if (!$mpp && ($mppB = Capture::resolveMpp($captureB))
            && (int) $captureA['width'] > 0 && (int) $captureA['height'] > 0
        ) {
            // Solo B ha una scala nota: va riportata sulla griglia di A,
            // perché B viene ridimensionata alle dimensioni di A prima del
            // confronto.
            $mpp = [
                'mpp_x' => $mppB['mpp_x'] * (int) $captureB['width'] / (int) $captureA['width'],
                'mpp_y' => $mppB['mpp_y'] * (int) $captureB['height'] / (int) $captureA['height'],
            ];
        }

        $requestedMethod = is_string($body['diff_method'] ?? null) && isset(self::DIFF_METHODS[$body['diff_method']])
            ? $body['diff_method'] : 'auto';
        $payload = [
            'capture_a_path' => $captureA['relative_path'],
            'capture_b_path' => $captureB['relative_path'],
            'mpp_x' => $mpp['mpp_x'] ?? null,
            'mpp_y' => $mpp['mpp_y'] ?? null,
            'align' => $align,
            'diff_method' => self::resolveDiffMethod($requestedMethod, $mpp ?: null),
            'diff_method_requested' => $requestedMethod,
            'analysis_scale_m' => self::ROBUST_SCALE_M,
            'threshold' => (int) ($body['threshold'] ?? 30),
            'use_otsu' => !empty($body['use_otsu']),
            'morph_kernel' => (int) ($body['morph_kernel'] ?? 3),
            'open_iterations' => (int) ($body['open_iterations'] ?? 1),
            'close_iterations' => (int) ($body['close_iterations'] ?? 2),
            'min_blob_area' => (int) ($body['min_blob_area'] ?? 40),
            'overlay_alpha' => (float) ($body['overlay_alpha'] ?? 0.35),
            'enhance_a' => $enhanceSteps,
            'enhance_b' => $enhanceSteps,
            'control_points' => $controlPoints,
            'geo_points' => $geoPoints ?? [],
        ];

        $progress(15, $geoPoints ? 'Allineamento dalle coordinate e confronto…' : 'Allineamento e confronto…');
        try {
            $result = (new PythonServiceClient())->post('/analysis/compare', $payload, 240);
        } catch (PythonServiceException $e) {
            throw new ComparisonException($e->getMessage(), 502);
        }

        // Confronto per oggetti (vedi ObjectChange): facoltativo, e un suo
        // problema non deve far perdere il confronto per pixel già fatto.
        // Solo se chiesto: la pagina lo chiede di serie, un chiamante
        // diretto di api/compare.php non si ritrova minuti di rilevamento.
        $objects = null;
        if (!empty($body['objects'])) {
            $objects = self::objectChange($captureA, $captureB, $result['registration']['b_to_a'] ?? null, $progress);
        }

        $progress(90, 'Salvataggio…');
        $comparisonId = Comparison::create(
            $studyId,
            $captureAId,
            $captureBId,
            trim($body['title'] ?? '') ?: null,
            $payload,
            $result['stats'],
            $result['regions'],
            $result['paths'],
            $result['registration'],
            $objects
        );
        Study::touch($studyId);

        return [
            'comparison_id' => $comparisonId,
            'stats' => $result['stats'],
            'regions' => $result['regions'],
            'registration' => $result['registration'],
            'objects' => $objects,
            // Parametri effettivi, per gli avvisi sull'affidabilità (study.js).
            'params' => [
                'diff_method' => $payload['diff_method'],
                'diff_method_requested' => $payload['diff_method_requested'],
                'analysis_scale_m' => $payload['analysis_scale_m'],
                'mpp_x' => $payload['mpp_x'],
                'mpp_y' => $payload['mpp_y'],
            ],
            'urls' => [
                'enhanced_a' => isset($result['paths']['enhanced_a']) ? storage_url($result['paths']['enhanced_a']) : null,
                'aligned_b' => storage_url($result['paths']['aligned_b']),
                'mask' => storage_url($result['paths']['mask']),
                'overlay' => storage_url($result['paths']['overlay']),
                'heatmap' => storage_url($result['paths']['heatmap']),
                'edges' => storage_url($result['paths']['edges']),
            ],
        ];
    }

    /**
     * Punti di controllo dalle coordinate: una griglia 5×5 di punti di A,
     * portati in lon/lat e poi nei pixel di B. Null se una delle due riprese
     * non è georiferita o se meno di 4 punti cadono dentro B (aree diverse).
     */
    public static function geoControlPoints(array $a, array $b): ?array
    {
        $ga = Capture::resolveGeoRef($a);
        $gb = Capture::resolveGeoRef($b);
        if (!$ga || !$gb || empty($a['width']) || empty($b['width'])) {
            return null;
        }
        $points = [];
        $rows = $cols = [];
        foreach ([0.1, 0.3, 0.5, 0.7, 0.9] as $fy) {
            foreach ([0.1, 0.3, 0.5, 0.7, 0.9] as $fx) {
                [$lon, $lat] = Capture::fracToLonLat($ga, $fx, $fy);
                [$bx, $by] = Capture::lonLatToFrac($gb, $lon, $lat);
                if ($bx < 0 || $bx > 1 || $by < 0 || $by > 1) {
                    continue;
                }
                $rows[(string) $fy] = $cols[(string) $fx] = true;
                $points[] = [
                    'ax' => $fx * (int) $a['width'], 'ay' => $fy * (int) $a['height'],
                    'bx' => $bx * (int) $b['width'], 'by' => $by * (int) $b['height'],
                ];
            }
        }
        // Punti tutti su una riga o una colonna (B sovrapposta ad A solo in
        // una striscia) non bastano: si allinea con le immagini.
        return count($points) >= 4 && count($rows) >= 2 && count($cols) >= 2 ? $points : null;
    }

    /**
     * Oggetti comparsi, spariti e rimasti fra le due riprese. Usa i
     * rilevamenti già fatti e lancia quelli che mancano (soglia predefinita).
     * Se non si può fare, lo dice invece di fallire.
     */
    private static function objectChange(array $captureA, array $captureB, ?array $bToA, callable $progress): array
    {
        if (!Capture::resolveGeoRef($captureA) || (!$bToA && !Capture::resolveGeoRef($captureB))) {
            return ['available' => false, 'reason' => 'Servono riprese georiferite: senza coordinate non si può sapere dove si trovano gli oggetti dell\'una nell\'altra.'];
        }
        foreach ([$captureA, $captureB] as $c) {
            $mpp = Detection::resolveMpp($c);
            if (!$mpp || ($mpp['mpp_x'] + $mpp['mpp_y']) / 2 > Detection::MAX_MPP) {
                return ['available' => false, 'reason' => 'Il rilevamento degli oggetti richiede riprese più dettagliate di ' . str_replace('.', ',', (string) Detection::MAX_MPP) . ' m/pixel con scala nota (non Sentinel).'];
            }
        }
        $dets = [];
        foreach ([['A', $captureA, 60], ['B', $captureB, 75]] as [$name, $c, $pct]) {
            $det = Detection::forCapture((int) $c['id']);
            if (!$det) {
                $progress($pct, "Rilevamento degli oggetti nella ripresa $name…");
                // Il rilevatore serve una richiesta alla volta e risponde
                // "occupato" dopo 20 s di attesa: un rilevamento lanciato
                // in parallelo non deve far perdere il confronto per oggetti.
                for ($attempt = 1; ; $attempt++) {
                    try {
                        $det = DetectionRunner::run(['capture_id' => (int) $c['id']]);
                        break;
                    } catch (Throwable $e) {
                        if ($attempt < 3 && str_contains($e->getMessage(), 'in corso')) {
                            $progress($pct, "Rilevatore occupato, nuovo tentativo per la ripresa $name…");
                            sleep(5);
                            continue;
                        }
                        return ['available' => false, 'reason' => "Rilevamento nella ripresa $name non riuscito: " . $e->getMessage()];
                    }
                }
                if (!$det) {
                    return ['available' => false, 'reason' => "Rilevamento nella ripresa $name non riuscito."];
                }
            }
            $dets[] = $det;
        }
        $progress(88, 'Confronto degli oggetti…');
        try {
            return ObjectChange::compare($captureA, $captureB, $dets[0], $dets[1], $bToA);
        } catch (Throwable $e) {
            // Il confronto per pixel è già fatto (e i suoi file scritti):
            // non va perso per un rilevamento salvato in forma inattesa.
            error_log('OrbitalEye: confronto per oggetti non riuscito: ' . $e->getMessage());
            return ['available' => false, 'reason' => 'Confronto degli oggetti non riuscito: ' . $e->getMessage()];
        }
    }

    private static function enhanceSteps(array $opts): array
    {
        $steps = [];
        if (!empty($opts['white_balance'])) {
            $steps[] = ['filter' => 'white_balance', 'params' => new stdClass()];
        }
        if (!empty($opts['denoise'])) {
            $steps[] = ['filter' => 'denoise', 'params' => [
                'method' => $opts['denoise_method'] ?? 'gaussian',
                'strength' => (int) ($opts['denoise_strength'] ?? 3),
            ]];
        }
        if (!empty($opts['clahe'])) {
            $steps[] = ['filter' => 'clahe', 'params' => [
                'clip_limit' => (float) ($opts['clahe_clip'] ?? 2.0),
                'tile_grid_size' => (int) ($opts['clahe_grid'] ?? 8),
            ]];
        }
        if (!empty($opts['hist_eq'])) {
            $steps[] = ['filter' => 'histogram_equalization', 'params' => new stdClass()];
        }
        if (!empty($opts['gamma_enabled'])) {
            $steps[] = ['filter' => 'gamma', 'params' => ['gamma' => (float) ($opts['gamma'] ?? 1.0)]];
        }
        if (!empty($opts['sharpen'])) {
            $steps[] = ['filter' => 'sharpen', 'params' => ['amount' => (float) ($opts['sharpen_amount'] ?? 1.0)]];
        }
        if (!empty($opts['desaturate'])) {
            $steps[] = ['filter' => 'desaturate', 'params' => ['amount' => (float) ($opts['desaturate_amount'] ?? 1.0)]];
        }
        return $steps;
    }
}
