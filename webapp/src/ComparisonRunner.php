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

        $payload = [
            'capture_a_path' => $captureA['relative_path'],
            'capture_b_path' => $captureB['relative_path'],
            'mpp_x' => $mpp['mpp_x'] ?? null,
            'mpp_y' => $mpp['mpp_y'] ?? null,
            'align' => $align,
            'diff_method' => $body['diff_method'] ?? 'ssim',
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
            $result['registration']
        );
        Study::touch($studyId);

        return [
            'comparison_id' => $comparisonId,
            'stats' => $result['stats'],
            'regions' => $result['regions'],
            'registration' => $result['registration'],
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
