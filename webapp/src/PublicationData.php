<?php

/**
 * Righe del riquadro dei dati tecnici di una pubblicazione (opzione
 * "Riquadro dati tecnici", vedi PublicationBuilder e
 * PublicationComposer::decorate): da dove viene l'immagine (fonte, sensore,
 * acquisizione), dove si trova (centro e box dell'area mostrata, estensione,
 * orientamento) e cosa le è stato fatto (ritaglio, regolazioni, filtri,
 * rilevamento automatico, confronto) — quello che un altro analista chiede
 * per valutare e ritrovare l'immagine.
 *
 * Spento di serie: a differenza della scheda, il riquadro dice dove si trova
 * ciò che si pubblica. Coordinate WGS84 in gradi decimali (latitudine,
 * longitudine) con il punto, come si incollano in una mappa.
 */
final class PublicationData
{
    /** Filtri del servizio di analisi (python-service/app/core/enhance.py). */
    private const FILTERS = [
        'white_balance' => 'bilanciamento del bianco',
        'denoise' => 'riduzione del rumore',
        'clahe' => 'contrasto locale (CLAHE)',
        'histogram_equalization' => 'equalizzazione dell\'istogramma',
        'edge_detect' => 'contorni',
        'desaturate' => 'desaturazione',
        'gamma' => 'gamma',
        'sharpen' => 'nitidezza',
    ];
    /** Regolazioni della copia di lavoro (analyze.js): nome, valore neutro, intervallo. */
    private const ADJUST = [
        'brightness' => ['luminosità', 100, 0, 400],
        'contrast' => ['contrasto', 100, 0, 400],
        'saturate' => ['saturazione', 100, 0, 400],
        'gamma' => ['gamma', 1.0, 0.1, 5],
        'sharpen' => ['nitidezza', 0, 0, 10],
    ];
    /** Allineamento dei confronti (come ALIGN_LABELS in study.js). */
    private const ALIGN = [
        'geo' => 'dalle coordinate',
        'geo+ecc' => 'dalle coordinate, rifinito sulle immagini',
        'orb+ecc' => 'dalle immagini, con rifinitura',
        'orb' => 'dalle immagini',
        'ecc-affine' => 'dalle immagini (correlazione)',
        'manual-affine' => 'punti di controllo manuali',
        'manual-homography' => 'punti di controllo manuali',
        'none' => 'non riuscito',
        'skipped' => 'disattivato',
    ];
    private const DIFF = ['robust' => 'robusto', 'ssim' => 'SSIM', 'absdiff' => 'differenza assoluta'];
    private const VIEWS = [
        'overlay' => 'cambiamenti sovrapposti', 'heatmap' => 'mappa di calore', 'mask' => 'maschera dei cambiamenti',
        'edges' => 'contorni', 'enhanced_a' => 'ripresa Prima', 'aligned_b' => 'ripresa Dopo allineata',
    ];

    /**
     * @param array|null $crop ritaglio in frazioni della ripresa (x, y, w, h), null = immagine intera
     * @param array $processing regolazioni della copia di lavoro (vedi processing())
     * @return array<int,array{0:string,1:string}> [etichetta, valore]
     */
    public static function forCapture(array $capture, ?array $crop, array $processing): array
    {
        $crop = $crop ?? ['x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0];
        $p = ImageryAttribution::provenance($capture);
        $rows = self::sourceRows($p, Capture::resolveMpp($capture));
        $rows = array_merge($rows, self::placeRows($capture, $crop));

        $steps = [];
        if ($crop['w'] < 0.9999 || $crop['h'] < 0.9999) {
            $steps[] = 'ritaglio';
        }
        $steps = array_merge($steps, self::savedProcessing($capture), self::liveProcessing($processing));
        $rows[] = ['Elaborazione', $steps ? implode(', ', array_unique($steps)) : 'nessuna'];

        $det = Detection::forCapture((int) $capture['id']);
        if ($det) {
            $rows[] = ['Rilevamento', self::detectionSummary($det, $crop)];
        }
        $rows[] = ['Composta', gmdate('d/m/Y H:i') . ' UTC'];
        return $rows;
    }

    /**
     * @param string $view vista pubblicata ('pair' per Prima e Dopo affiancate)
     * @return array<int,array{0:string,1:string}>
     */
    public static function forComparison(array $comparison, ?array $capA, ?array $capB, string $view): array
    {
        $rows = [];
        foreach (['Prima' => $capA, 'Dopo' => $capB] as $label => $cap) {
            $rows[] = [$label, $cap ? self::brief(ImageryAttribution::provenance($cap)) : 'ripresa non più disponibile'];
        }
        if ($capA) {
            $mpp = Capture::resolveMpp($capA);
            if ($mpp) {
                $rows[] = ['Scala', self::scale($mpp)];
            }
            $rows = array_merge($rows, self::placeRows($capA, ['x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0]));
        }
        $params = json_decode($comparison['params_json'] ?? '', true) ?: [];
        $stats = json_decode($comparison['stats_json'] ?? '', true) ?: [];
        $reg = json_decode($comparison['registration_json'] ?? '', true) ?: [];
        $rows[] = ['Vista', $view === 'pair' ? 'Prima e Dopo affiancate' : (self::VIEWS[$view] ?? $view)];
        if (!empty($reg['method'])) {
            $rows[] = ['Allineamento', self::ALIGN[$reg['method']] ?? (string) $reg['method']];
        }
        if (!empty($params['diff_method'])) {
            $m = self::DIFF[$params['diff_method']] ?? (string) $params['diff_method'];
            if ($params['diff_method'] === 'robust' && !empty($params['analysis_scale_m'])) {
                $m .= ', celle di ' . self::num((float) $params['analysis_scale_m'], 1) . ' m';
            }
            $rows[] = ['Confronto', $m];
        }
        if (isset($stats['changed_ratio'])) {
            $v = self::num($stats['changed_ratio'] * 100, 1) . '% dell\'area';
            if (isset($stats['changed_area_m2'])) {
                $v .= ' · ' . self::area((float) $stats['changed_area_m2']);
            }
            $rows[] = ['Variazione', $v];
        }
        $pre = self::stepNames($params['enhance_a'] ?? []);
        $rows[] = ['Pre-elaborazione', $pre ? implode(', ', $pre) : 'nessuna'];
        $objects = json_decode($comparison['objects_json'] ?? '', true);
        if (is_array($objects) && !empty($objects['summary'])) {
            $rows[] = ['Oggetti (automatico)', (string) $objects['summary']];
        }
        $rows[] = ['Composta', gmdate('d/m/Y H:i') . ' UTC'];
        return $rows;
    }

    /**
     * Regolazioni della copia di lavoro dichiarate dalla pagina (JSON):
     * solo voci e valori noti, mai testo libero sull'immagine pubblicata.
     *
     * @return array{adjust: array<string,float>, steps: string[], layer: bool, overlay: bool}
     */
    public static function processing(?string $json): array
    {
        $in = json_decode((string) $json, true);
        $in = is_array($in) ? $in : [];
        $adjust = [];
        foreach (self::ADJUST as $key => [, , $min, $max]) {
            if (isset($in['adjust'][$key]) && is_numeric($in['adjust'][$key])) {
                $adjust[$key] = (float) max($min, min($max, (float) $in['adjust'][$key]));
            }
        }
        $steps = array_values(array_filter(is_array($in['steps'] ?? null) ? $in['steps'] : [],
            fn($s) => is_string($s) && isset(self::FILTERS[$s])));
        return ['adjust' => $adjust, 'steps' => $steps, 'layer' => !empty($in['layer']), 'overlay' => !empty($in['overlay'])];
    }

    /** Ritaglio in frazioni dell'immagine dai campi crop_x... della richiesta, null se assente o non valido. */
    public static function crop(array $p): ?array
    {
        if (!isset($p['crop_x'], $p['crop_y'], $p['crop_w'], $p['crop_h'])) {
            return null;
        }
        [$x, $y, $w, $h] = array_map('floatval', [$p['crop_x'], $p['crop_y'], $p['crop_w'], $p['crop_h']]);
        if ($w <= 0 || $h <= 0 || $x < 0 || $y < 0 || $x + $w > 1.0001 || $y + $h > 1.0001) {
            return null;
        }
        return ['x' => $x, 'y' => $y, 'w' => min($w, 1 - $x), 'h' => min($h, 1 - $y)];
    }

    // ------------------------------------------------------------------

    private static function sourceRows(array $p, ?array $mpp): array
    {
        $rows = [['Fonte', $p['source'] ?: 'non nota']];
        if ($p['sensor']) {
            $rows[] = ['Sensore', $p['sensor']];
        }
        $rows[] = ['Acquisizione', $p['acquired'] ?: 'data non nota'];
        $res = [];
        if ($p['resolution_m']) {
            $res[] = self::num($p['resolution_m'], 2) . ' m (sensore)';
        }
        if ($mpp) {
            $res[] = self::scale($mpp) . ' (immagine)';
        }
        if ($res) {
            $rows[] = ['Risoluzione', implode(' · ', $res)];
        }
        if ($p['orbit']) {
            $rows[] = ['Orbita', $p['orbit']];
        }
        if ($p['cloud']) {
            $rows[] = ['Nuvole', $p['cloud']];
        }
        if ($p['fetched']) {
            $rows[] = ['Scaricata', $p['fetched']];
        }
        return $rows;
    }

    /** Centro, box, estensione e orientamento dell'area mostrata. */
    private static function placeRows(array $capture, array $crop): array
    {
        $rows = [];
        $geo = Capture::resolveGeoRef($capture);
        if ($geo) {
            $corners = [];
            foreach ([[0, 0], [1, 0], [1, 1], [0, 1]] as [$i, $j]) {
                $corners[] = Capture::fracToLonLat($geo, $crop['x'] + $i * $crop['w'], $crop['y'] + $j * $crop['h']);
            }
            [$cLon, $cLat] = Capture::fracToLonLat($geo, $crop['x'] + $crop['w'] / 2, $crop['y'] + $crop['h'] / 2);
            $lons = array_column($corners, 0);
            $lats = array_column($corners, 1);
            $rows[] = ['Centro (lat, lon)', self::coord($cLat, $cLon)];
            $rows[] = ['Box (SO · NE)', self::coord(min($lats), min($lons)) . ' · ' . self::coord(max($lats), max($lons))];
        }
        $mpp = Capture::resolveMpp($capture);
        if ($mpp && !empty($capture['width']) && !empty($capture['height'])) {
            $w = $mpp['mpp_x'] * (int) $capture['width'] * $crop['w'];
            $h = $mpp['mpp_y'] * (int) $capture['height'] * $crop['h'];
            $rows[] = ['Estensione', self::length($w) . ' × ' . self::length($h)];
        }
        if ($geo && abs((float) $geo['rotation']) >= 0.01) {
            // Area ruotata di θ in senso orario e salvata raddrizzata: il nord
            // punta a −θ rispetto all'alto (come la freccia della scheda).
            $north = fmod(360 - (float) $geo['rotation'], 360);
            $rows[] = ['Orientamento', 'nord a ' . self::num($north < 0 ? $north + 360 : $north, 1) . '° dall\'alto, senso orario'];
        }
        return $rows;
    }

    /** Filtri delle riprese elaborate e salvate da cui questa deriva. */
    private static function savedProcessing(array $capture): array
    {
        $out = [];
        $seen = [];
        $current = $capture;
        while ($current && !isset($seen[(int) $current['id']])) {
            $seen[(int) $current['id']] = true;
            $meta = json_decode($current['meta_json'] ?? '', true) ?: [];
            if (!empty($meta['steps']) && is_array($meta['steps'])) {
                $out = array_merge(self::stepNames($meta['steps']), $out);
            }
            if (!empty($meta['crop'])) {
                $out[] = 'ritaglio';
            }
            $current = !empty($meta['source_capture_id']) ? Capture::find((int) $meta['source_capture_id']) : null;
        }
        return $out;
    }

    private static function liveProcessing(array $p): array
    {
        $out = [];
        foreach ($p['steps'] ?? [] as $s) {
            $out[] = self::FILTERS[$s];
        }
        foreach ($p['adjust'] ?? [] as $key => $v) {
            [$name, $neutral] = self::ADJUST[$key];
            if (abs($v - $neutral) < 1e-6) {
                continue;
            }
            $out[] = $name . ' ' . ($key === 'gamma' ? self::num($v, 2) : ($key === 'sharpen' ? self::num($v, 1) : self::num($v, 0) . '%'));
        }
        if (!empty($p['overlay'])) {
            $out[] = 'immagine esterna sovrapposta';
        }
        if (!empty($p['layer'])) {
            $out[] = 'annotazioni e misure dell\'analista';
        }
        return $out;
    }

    /** @param array $steps [{filter, params}] */
    private static function stepNames(array $steps): array
    {
        $out = [];
        foreach ($steps as $s) {
            $f = is_array($s) ? ($s['filter'] ?? '') : '';
            if (isset(self::FILTERS[$f])) {
                $out[] = self::FILTERS[$f];
            }
        }
        return $out;
    }

    /** Oggetti dell'ultimo rilevamento con il centro nell'area mostrata. */
    private static function detectionSummary(array $det, array $crop): string
    {
        $counts = array_fill_keys(array_keys(Detection::HISTORY_CLASSES), 0);
        foreach ($det['result']['objects'] ?? [] as $o) {
            $cls = $o['class'] ?? '';
            [$x, $y] = [(float) ($o['center'][0] ?? -1), (float) ($o['center'][1] ?? -1)];
            if (isset($counts[$cls]) && $x >= $crop['x'] && $x <= $crop['x'] + $crop['w'] && $y >= $crop['y'] && $y <= $crop['y'] + $crop['h']) {
                $counts[$cls]++;
            }
        }
        $parts = [];
        foreach (array_filter($counts) as $cls => $n) {
            $parts[] = $n . ' ' . self::className($cls, $n);
        }
        // yolo11m-obb → YOLO11m-OBB
        $model = preg_replace_callback('/^yolo(\d+)([a-z])(-obb)?$/i',
            fn($m) => 'YOLO' . $m[1] . strtolower($m[2]) . (!empty($m[3]) ? '-OBB' : ''), (string) $det['model']);
        return ($parts ? implode(', ', $parts) : 'nessun velivolo, nave o veicolo')
            . ' · automatico, ' . $model . ', soglia ' . self::num((float) $det['confidence'], 2) . ', da verificare';
    }

    private static function className(string $cls, int $n): string
    {
        $one = ['plane' => 'aereo', 'helicopter' => 'elicottero', 'ship' => 'nave', 'large vehicle' => 'veicolo grande', 'small vehicle' => 'veicolo piccolo'];
        return $n === 1 ? $one[$cls] : Detection::HISTORY_CLASSES[$cls];
    }

    /** Una provenienza in una riga: data, sensore, fonte. */
    private static function brief(array $p): string
    {
        $sensor = $p['sensor'] ? explode(' · ', $p['sensor'])[0] : null;
        return implode(' · ', array_filter([$p['acquired'] ?: 'data non nota', $sensor, $p['source']]));
    }

    /** Metri per pixel; i due assi se differiscono di oltre il 5%. */
    private static function scale(array $mpp): string
    {
        [$x, $y] = [(float) $mpp['mpp_x'], (float) $mpp['mpp_y']];
        return (abs($x - $y) > 0.05 * max($x, $y) ? self::num($x, 2) . ' × ' . self::num($y, 2) : self::num(($x + $y) / 2, 2)) . ' m/pixel';
    }

    private static function coord(float $lat, float $lon): string
    {
        return sprintf('%.5f, %.5f', $lat, $lon);
    }

    private static function length(float $m): string
    {
        return $m >= 1000 ? self::num($m / 1000, 2) . ' km' : self::num($m, 0) . ' m';
    }

    private static function area(float $m2): string
    {
        return $m2 >= 1e6 ? self::num($m2 / 1e6, 2) . ' km²' : number_format($m2, 0, ',', '.') . ' m²';
    }

    private static function num(float $v, int $decimals): string
    {
        return number_format($v, $decimals, ',', '.');
    }
}
