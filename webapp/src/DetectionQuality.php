<?php

/**
 * Quanto sbaglia il rilevamento, misurato sulle verifiche dell'analista
 * (DetectionReview): per classe, quanti oggetti trovati erano giusti
 * (precisione) e, sulle riprese verificate per intero, quanti oggetti veri
 * sono stati trovati (richiamo); per i velivoli con il tipo confermato, in
 * che posizione compare il tipo vero fra i candidati, dalla sagoma e dal
 * riquadro (vedi AircraftCatalog::match).
 *
 * Si calcola sull'ULTIMO rilevamento di ogni ripresa con il codice attuale:
 * un miglioramento del rilevatore o della tabella dei tipi si vede subito
 * sugli stessi oggetti verificati.
 */
final class DetectionQuality
{
    /** Gruppi di classi: veicoli grandi e piccoli contano insieme (il rilevatore li confonde). */
    public const GROUPS = [
        'plane' => 'aerei',
        'helicopter' => 'elicotteri',
        'ship' => 'navi',
        'vehicle' => 'veicoli',
        'other' => 'altro',
    ];

    /** Fasce di confidenza del rilevatore, per scegliere la soglia. */
    public const CONFIDENCE_BANDS = [[0.0, 0.4], [0.4, 0.6], [0.6, 0.8], [0.8, 1.01]];

    public static function group(?string $class): string
    {
        return match ($class) {
            'plane', 'helicopter', 'ship' => $class,
            'large vehicle', 'small vehicle' => 'vehicle',
            default => 'other',
        };
    }

    /**
     * @param array<int,array>|null $reviewsByCapture per i test; di norma DetectionReview::all()
     * @param array<int,array>|null $detections       per i test: capture_id => rilevamento
     * @param array<int,bool>|null  $complete          per i test: riprese verificate per intero
     */
    public static function compute(?array $reviewsByCapture = null, ?array $detections = null, ?array $complete = null): array
    {
        $reviewsByCapture ??= DetectionReview::all();
        $complete ??= DetectionReview::completeCaptures();
        $classes = [];
        foreach (array_keys(self::GROUPS) as $g) {
            // tp_complete: i giusti sulle sole riprese verificate per
            // intero, le uniche su cui si contano anche gli sfuggiti.
            $classes[$g] = ['tp' => 0, 'fp' => 0, 'fn' => 0, 'wrong_class' => 0, 'tp_complete' => 0];
        }
        $bands = array_map(fn($b) => ['from' => $b[0], 'to' => min(1.0, $b[1]), 'tp' => 0, 'fp' => 0], self::CONFIDENCE_BANDS);
        $ident = ['sagoma' => self::rankStats(), 'riquadro' => self::rankStats(), 'mar20' => self::rankStats()];
        // Indizio MAR20 con ★ (TypeClassifier): quante volte è stato dato
        // su un velivolo identificato e quante era giusto. Conta anche gli
        // aerei di tipi che MAR20 non conosce: sono loro il rischio.
        $mar20Star = ['n' => 0, 'right' => 0];
        $captures = [];

        foreach ($reviewsByCapture as $captureId => $reviews) {
            $det = $detections !== null ? ($detections[$captureId] ?? null) : Detection::forCapture((int) $captureId);
            $objects = $det['result']['objects'] ?? [];
            $mpp = $det['result']['mpp'] ?? null;
            $mppMean = $mpp ? ((float) $mpp['mpp_x'] + (float) $mpp['mpp_y']) / 2 : null;
            $isComplete = !empty($complete[$captureId]);
            $matched = DetectionReview::match($objects, $reviews);
            $row = ['capture_id' => (int) $captureId, 'complete' => $isComplete, 'reviews' => count($reviews),
                'objects' => count($objects), 'unreviewed' => 0, 'missed' => 0];

            foreach ($objects as $i => $o) {
                $g = self::group($o['class'] ?? null);
                if (!isset($matched[$i])) {
                    $row['unreviewed']++;
                    continue;
                }
                $r = $reviews[$matched[$i]];
                $band = self::band((float) ($o['confidence'] ?? 0));
                if ($r['verdict'] === 'rejected') {
                    $classes[$g]['fp']++;
                    $bands[$band]['fp']++;
                    continue;
                }
                $trueG = self::group($r['class']);
                if ($trueG !== $g) {
                    // Oggetto vero, classe sbagliata: un errore per la classe
                    // proposta, uno sfuggito per quella vera.
                    $classes[$g]['fp']++;
                    $classes[$g]['wrong_class']++;
                    $bands[$band]['fp']++;
                    if ($isComplete) {
                        $classes[$trueG]['fn']++;
                    }
                    continue;
                }
                $classes[$g]['tp']++;
                if ($isComplete) {
                    $classes[$g]['tp_complete']++;
                }
                $bands[$band]['tp']++;
                if (!empty($r['type_name']) && in_array($g, ['plane', 'helicopter'], true) && $mppMean) {
                    foreach (self::ranks($o, $r['type_name'], $mppMean) as $method => $rank) {
                        self::addRank($ident[$method], $rank);
                    }
                    // Rivalutato con le soglie e la tabella attuali, come le
                    // altre righe; una variante (C-130J per "C-130") conta giusta.
                    $guess = $g === 'plane' ? TypeClassifier::forObject($o, $mppMean) : null;
                    if ($guess) {
                        $names = array_column($guess['types'], 'type');
                        // Posizione solo per i tipi che il classificatore conosce:
                        // gli altri non possono risultare primi.
                        if (TypeClassifier::known($r['type_name'])) {
                            $rank = null;
                            foreach ($names as $k => $n) {
                                if (TypeClassifier::sameType($n, $r['type_name'])) {
                                    $rank = $k + 1;
                                    break;
                                }
                            }
                            self::addRank($ident['mar20'], $rank);
                        }
                        if ($guess['reliable']) {
                            $mar20Star['n']++;
                            $mar20Star['right'] += TypeClassifier::sameType($names[0], $r['type_name']) ? 1 : 0;
                        }
                    }
                }
            }
            // Oggetti veri non trovati dal rilevatore (o non più, dopo una
            // nuova esecuzione): contano solo sulle riprese verificate per intero.
            $used = array_flip($matched);
            foreach ($reviews as $j => $r) {
                if ($r['verdict'] === 'confirmed' && !isset($used[$j])) {
                    $row['missed']++;
                    if ($isComplete) {
                        $classes[self::group($r['class'])]['fn']++;
                    }
                }
            }
            $captures[] = $row;
        }

        foreach ($classes as &$c) {
            $c['precision'] = $c['tp'] + $c['fp'] ? round($c['tp'] / ($c['tp'] + $c['fp']), 3) : null;
            $c['recall'] = $c['tp_complete'] + $c['fn'] ? round($c['tp_complete'] / ($c['tp_complete'] + $c['fn']), 3) : null;
        }
        unset($c);
        foreach ($bands as &$b) {
            $b['precision'] = $b['tp'] + $b['fp'] ? round($b['tp'] / ($b['tp'] + $b['fp']), 3) : null;
        }
        unset($b);
        return ['classes' => $classes, 'bands' => $bands, 'identification' => $ident, 'mar20_star' => $mar20Star, 'captures' => $captures,
            'complete_captures' => count(array_filter($captures, fn($c) => $c['complete']))];
    }

    /**
     * Posizione del tipo vero fra i candidati, con le misure della sagoma
     * (se attendibile) e con i lati del riquadro. null = non compatibile
     * (fuori dalla lista).
     *
     * @return array<string,int|null>
     */
    public static function ranks(array $object, string $type, float $mppMean): array
    {
        $filter = ($object['class'] ?? '') === 'helicopter' ? 'elicottero' : 'ala_fissa';
        $out = [];
        $sil = $object['silhouette'] ?? null;
        if ($sil && ($sil['quality'] ?? '') === 'ok' && !empty($sil['span_m']) && !empty($sil['length_m'])) {
            $out['sagoma'] = self::rankOf(AircraftCatalog::match((float) $sil['span_m'], (float) $sil['length_m'], $mppMean, 'silhouette', $filter, 200), $type);
        }
        if (!empty($object['size_m'])) {
            $out['riquadro'] = self::rankOf(AircraftCatalog::match((float) $object['size_m'][0], (float) $object['size_m'][1], $mppMean, 'box', $filter, 200), $type);
        }
        return $out;
    }

    private static function rankOf(array $candidates, string $type): ?int
    {
        foreach ($candidates as $i => $c) {
            if ($c['name'] === $type) {
                return $i + 1;
            }
        }
        return null;
    }

    private static function rankStats(): array
    {
        return ['n' => 0, 'top1' => 0, 'top3' => 0, 'absent' => 0];
    }

    private static function addRank(array &$stats, ?int $rank): void
    {
        $stats['n']++;
        if ($rank === null) {
            $stats['absent']++;
            return;
        }
        if ($rank === 1) {
            $stats['top1']++;
        }
        if ($rank <= 3) {
            $stats['top3']++;
        }
    }

    private static function band(float $confidence): int
    {
        foreach (self::CONFIDENCE_BANDS as $i => [$from, $to]) {
            if ($confidence >= $from && $confidence < $to) {
                return $i;
            }
        }
        return count(self::CONFIDENCE_BANDS) - 1;
    }
}
