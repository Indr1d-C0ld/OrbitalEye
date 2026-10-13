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
            ], $model === 'm' ? 900 : 300);
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
