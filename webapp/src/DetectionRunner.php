<?php

final class DetectionException extends RuntimeException
{
    public int $httpStatus;

    public function __construct(string $message, int $httpStatus = 400)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }
}

/**
 * Esecuzione di un rilevamento automatico su una ripresa (prima in
 * api/detect.php): scala, chiamata al servizio di analisi, misure in metri
 * dei riquadri, tipi di velivolo compatibili, salvataggio. Usata dalla
 * richiesta diretta e dai lavori in background (Job).
 */
final class DetectionRunner
{
    /** Navi più corte di così: niente classi navali (barche, rimorchiatori). */
    private const SHIP_MIN_M = 20.0;

    /**
     * Categorie dei veicoli per lunghezza, dal lato lungo del riquadro
     * (che include un po' di margine e d'ombra: ~0,9). Soglie da tarare
     * sulle verifiche; a 0,3–0,5 m/pixel un'auto è 9–15 pixel, quindi il
     * tipo esatto non è riconoscibile, la classe di lunghezza sì.
     */
    public const VEHICLE_SIZES = [
        [0.0, 6.0, 'piccolo', 'auto o furgone'],
        [6.0, 8.5, 'medio', 'furgone grande, minibus o mezzo leggero'],
        [8.5, 14.0, 'grande', 'camion, autobus o mezzo pesante'],
        [14.0, INF, 'molto grande', 'autoarticolato o rimorchio lungo'],
    ];
    /** Oltre questa risoluzione le categorie dei veicoli non si danno. */
    public const VEHICLE_MAX_MPP = 0.6;

    /** Categoria di un veicolo dalla lunghezza del riquadro, o null se la ripresa è troppo grossolana. */
    public static function vehicleSize(float $boxLength, ?float $mpp): ?array
    {
        if ($mpp === null || $mpp > self::VEHICLE_MAX_MPP || $boxLength <= 0) {
            return null;
        }
        $len = $boxLength * 0.9;
        foreach (self::VEHICLE_SIZES as [$from, $to, $key, $label]) {
            if ($len >= $from && $len < $to) {
                return ['key' => $key, 'label' => $label, 'length_m' => round($len, 1)];
            }
        }
        return null;
    }

    /** @param callable|null $progress fn(int $percent, string $message) */
    public static function run(array $body, ?callable $progress = null): ?array
    {
        $progress = $progress ?? fn() => null;
        $captureId = (int) ($body['capture_id'] ?? 0);
        $capture = $captureId ? Capture::find($captureId) : null;
        if (!$capture) {
            throw new DetectionException('Ripresa non trovata', 404);
        }

        $mpp = Detection::resolveMpp($capture);
        $mppX = $mpp ? (float) $mpp['mpp_x'] : null;
        $mppY = $mpp ? (float) $mpp['mpp_y'] : null;
        $mppMean = $mpp ? ($mppX + $mppY) / 2 : null;
        if ($mppMean !== null && $mppMean > Detection::MAX_MPP) {
            throw new DetectionException(sprintf(
                'Risoluzione troppo bassa per il rilevamento (%s m/pixel): il modello riconosce oggetti in immagini fra circa 0,1 e 1 m/pixel. Usa una ripresa più dettagliata (Esri, area più piccola).',
                str_replace('.', ',', (string) round($mppMean, 1))
            ), 400);
        }

        $confidence = max(0.05, min(0.95, (float) ($body['confidence'] ?? 0.25)));
        // Modello: "s" veloce (di serie) o "m", con meno frammenti e doppioni
        // ma tre volte più lento (vedi python-service/app/core/detect.py).
        $model = ($body['model'] ?? 's') === 'm' ? 'm' : 's';
        $progress(10, 'Rilevamento in corso sul server…');
        $smallObjects = !empty($body['small_objects']);

        // Il servizio distribuisce il tempo fra rilevamento, sagome e vettori
        // in modo da rispondere entro il timeout, con un minuto di margine.
        $timeout = $model === 'm' ? 900 : 300;
        try {
            $result = (new PythonServiceClient())->post('/analysis/detect', [
                'capture_path' => $capture['relative_path'],
                'mpp' => $mppMean,
                // Per asse: servono alla sagoma dei velivoli (silhouette.py).
                'mpp_x' => $mppX,
                'mpp_y' => $mppY,
                'confidence' => $confidence,
                'small_objects' => $smallObjects,
                'model' => $model,
                'time_budget_s' => $timeout - 60,
            ], $timeout);
        } catch (PythonServiceException $e) {
            throw new DetectionException($e->getMessage(), 502);
        }

        $progress(85, 'Misure e tipi compatibili…');
        $w = max(1, (int) ($result['width'] ?? $capture['width']));
        $h = max(1, (int) ($result['height'] ?? $capture['height']));
        $counts = [];
        $objects = [];
        $embeddings = [];
        foreach ($result['detections'] ?? [] as $i => $d) {
            $counts[$d['class']] = ($counts[$d['class']] ?? 0) + 1;
            $o = [
                'n' => $i + 1,
                'class' => $d['class'],
                'label' => $d['label'],
                'confidence' => $d['confidence'],
                // Coordinate frazionarie (come le annotazioni): indipendenti dalla
                // risoluzione con cui la ripresa viene mostrata.
                'polygon' => array_map(fn($p) => [round($p[0] / $w, 6), round($p[1] / $h, 6)], $d['polygon']),
                'center' => [round($d['cx'] / $w, 6), round($d['cy'] / $h, 6)],
            ];
            if ($mpp) {
                // Lati del riquadro in metri: ogni lato è inclinato di "angle", e la
                // scala può differire fra i due assi dell'immagine.
                $a = (float) $d['angle'];
                $side1 = $d['w'] * hypot($mppX * cos($a), $mppY * sin($a));
                $side2 = $d['h'] * hypot($mppX * sin($a), $mppY * cos($a));
                $o['size_m'] = [round(max($side1, $side2), 1), round(min($side1, $side2), 1)];
                // Sagoma (solo aerei): apertura e lunghezza vere, ciascuna al
                // suo posto, e il contorno da mostrare. Se è attendibile, i
                // tipi compatibili si cercano con quelle; altrimenti con i
                // lati del riquadro, come prima.
                $sil = is_array($d['silhouette'] ?? null) ? $d['silhouette'] : null;
                if ($sil) {
                    $o['silhouette'] = [
                        'quality' => $sil['quality'] ?? 'bassa',
                        'reason' => $sil['reason'] ?? null,
                        'span_m' => $sil['span_m'] ?? null,
                        'length_m' => $sil['length_m'] ?? null,
                        'symmetry' => $sil['symmetry'] ?? null,
                        'outline' => array_map(fn($p) => [round($p[0] / $w, 6), round($p[1] / $h, 6)], $sil['outline'] ?? []),
                    ];
                }
                if (in_array($d['class'], ['plane', 'helicopter'], true)) {
                    $silOk = $sil && ($sil['quality'] ?? '') === 'ok' && !empty($sil['span_m']) && !empty($sil['length_m']);
                    $o['id_from'] = $silOk ? 'sagoma' : 'riquadro';
                    // Tutti i tipi compatibili: i primi cinque come candidati,
                    // le distanze per valutare l'indizio MAR20.
                    $all = $silOk
                        ? AircraftCatalog::match((float) $sil['span_m'], (float) $sil['length_m'], $mppMean, 'silhouette', 'ala_fissa', PHP_INT_MAX)
                        : AircraftCatalog::match($side1, $side2, $mppMean, 'box', $d['class'] === 'helicopter' ? 'elicottero' : 'ala_fissa', PHP_INT_MAX);
                    $matches = array_slice($all, 0, 5);
                    if ($d['class'] === 'plane' && ($guess = TypeClassifier::assess($d['type_guess'] ?? null, $all))) {
                        $o['type_guess'] = $guess;
                    }
                    $o['candidates'] = array_map(fn($c) => [
                        'name' => $c['name'], 'maker' => $c['maker'], 'category' => $c['category'],
                        'compatibility' => $c['compatibility'], 'note' => $c['note'], 'wiki_url' => $c['wiki_url'],
                        'span_m' => $c['span_m'], 'length_m' => $c['length_m'], 'rotor_m' => $c['rotor_m'],
                    ], $matches);
                }
                if ($d['class'] === 'ship') {
                    // Scafo (hull.py): lunghezza e larghezza separate
                    // dall'acqua. Classi navali compatibili con quelle se la
                    // misura è attendibile (con qualità "media" solo la
                    // lunghezza), altrimenti con i lati del riquadro; nessuna
                    // per le barche (sotto i 20 m).
                    $hull = is_array($d['hull'] ?? null) ? $d['hull'] : null;
                    if ($hull) {
                        $o['hull'] = [
                            'quality' => $hull['quality'] ?? 'bassa',
                            'reason' => $hull['reason'] ?? null,
                            'length_m' => $hull['length_m'] ?? null,
                            'beam_m' => $hull['beam_m'] ?? null,
                            'outline' => array_map(fn($p) => [round($p[0] / $w, 6), round($p[1] / $h, 6)], $hull['outline'] ?? []),
                        ];
                    }
                    $hullQ = $hull['quality'] ?? null;
                    if (in_array($hullQ, ['ok', 'media'], true) && !empty($hull['length_m'])) {
                        $o['id_from'] = 'scafo';
                        $ships = ShipCatalog::match((float) $hull['length_m'], isset($hull['beam_m']) ? (float) $hull['beam_m'] : null,
                            $mppMean, $hullQ === 'ok' ? 'hull' : 'hull_length', 5);
                    } elseif (max($side1, $side2) >= self::SHIP_MIN_M) {
                        $o['id_from'] = 'riquadro';
                        $ships = ShipCatalog::match(max($side1, $side2), min($side1, $side2), $mppMean, 'box', 5);
                    } else {
                        $ships = null;
                    }
                    if ($ships !== null) {
                        $o['candidates'] = array_map(fn($c) => [
                            'name' => $c['name'], 'maker' => implode(', ', $c['navies']), 'category' => $c['category'],
                            'compatibility' => $c['compatibility'], 'note' => null, 'wiki_url' => $c['wiki_url'],
                            'length_m' => $c['length_m'], 'beam_m' => $c['beam_m'], 'deck_width_m' => $c['deck_width_m'],
                        ], $ships);
                    }
                }
                if (in_array($d['class'], ['small vehicle', 'large vehicle'], true)) {
                    $o['vehicle_size'] = self::vehicleSize(max($side1, $side2), $mppMean);
                }
            }
            if (!empty($d['embedding']) && is_array($d['embedding'])) {
                $embeddings[$o['n']] = array_values(array_filter($d['embedding'], 'is_string'));
            }
            $objects[] = $o;
        }

        $stored = [
            'objects' => $objects,
            'upscale' => $result['upscale'] ?? 1,
            'tiles' => $result['tiles'] ?? 1,
            'mpp' => $mpp,
            // Spostamento dell'ombra (metri, assi dell'immagine) stimato
            // dalle sagome: uno per ripresa, un solo sole.
            'shadow_m' => $result['shadow_m'] ?? null,
            // Classificatore dei tipi che ha prodotto i type_guess (se c'era).
            'type_model' => $result['type_model'] ?? null,
            // Oggetti grandi presi dalla passata ridotta (vedi detect.py).
            'large_objects' => (int) ($result['large_objects'] ?? 0),
        ];
        // "Oggetti piccoli" registrato solo se l'ingrandimento è stato davvero
        // applicato (non serve sotto 0,5 m/pixel, né senza scala nota).
        Detection::save($captureId, (int) $capture['study_id'], (string) ($result['model'] ?? 'yolo11s-obb'), $confidence,
            $smallObjects && (float) ($result['upscale'] ?? 1) > 1, $mppMean, $stored, $counts, $embeddings ?: null);
        // I tipi già confermati su questa ripresa restano riferimenti della
        // libreria visiva, con i vettori del nuovo rilevamento.
        DetectionReview::refreshEmbeddings($captureId);
        // Grezzo: chi lo manda alla pagina passa da Detection::forClient
        // (niente vettori, suggerimenti della libreria visiva).
        return Detection::forCapture($captureId);
    }
}
