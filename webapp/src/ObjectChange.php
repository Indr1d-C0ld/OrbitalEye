<?php

/**
 * Confronto per oggetti fra due riprese: quali velivoli, navi e veicoli
 * trovati dal rilevamento automatico (vedi Detection, DetectionRunner) sono
 * comparsi, spariti o rimasti fra la ripresa A e la ripresa B.
 *
 * Il confronto pixel per pixel risponde a "dove è cambiata l'immagine";
 * questo risponde alla domanda che conta su una base: "3 aerei comparsi, 2
 * spariti". Non risente di ombre, colori o trama del terreno: dipende solo
 * da cosa il rilevatore riconosce in ciascuna ripresa.
 *
 * Gli oggetti si confrontano nel sistema della ripresa A, in metri: quelli
 * di B vi sono riportati con la stessa trasformazione con cui il confronto
 * ha allineato le immagini (b_to_a, vedi frac_homography in
 * registration.py), che corregge anche lo scarto fra le georeferenze di
 * fonti diverse (spesso 5-15 m, quanto un'auto). Senza allineamento si
 * ripiega sulle coordinate geografiche (Capture::fracToLonLat). Le due
 * riprese possono avere aree, rotazioni e risoluzioni diverse. Due oggetti sono "lo stesso" (rimasto) se sono della stessa
 * categoria, entro una distanza che tiene conto degli scarti di georeferenza
 * fra fonti, e di dimensioni compatibili. Un velivolo spostato di qualche
 * decina di metri risulta quindi sparito da un posto e comparso in un altro,
 * e uno diverso nella stessa piazzola risulta sostituito (sparito + comparso)
 * se le dimensioni non tornano.
 */
final class ObjectChange
{
    /** Classi confrontate (oggetti che si spostano), con la categoria di abbinamento. */
    public const CLASSES = [
        'plane' => 'plane',
        'helicopter' => 'helicopter',
        'ship' => 'ship',
        // Il rilevatore distingue veicoli grandi e piccoli in modo instabile
        // fra due immagini dello stesso mezzo: si abbinano fra loro.
        'large vehicle' => 'vehicle',
        'small vehicle' => 'vehicle',
    ];

    /** Distanza minima entro cui due oggetti possono essere lo stesso (scarti di georeferenza fra fonti). */
    public const MIN_MATCH_M = 10.0;

    /** Oltre il raggio minimo, la distanza ammessa è questa frazione della lunghezza dell'oggetto. */
    public const MATCH_LENGTH_FRACTION = 0.5;

    /** Rapporto massimo fra le lunghezze di due oggetti abbinati. */
    public const MAX_SIZE_RATIO = 1.6;

    /** Margine (frazione) entro il bordo dell'altra ripresa perché un oggetto conti come "nell'area comune". */
    private const EDGE_MARGIN = 0.01;

    /**
     * @param array $captureA, $captureB righe di captures
     * @param array $detA, $detB rilevamenti (Detection::forCapture) delle due riprese
     * @param array|null $bToA omografia 3×3 fra frazioni di B e di A
     *        (registration.b_to_a del confronto), null se le immagini non
     *        sono state allineate
     * @return array{available:bool, reason?:string, confidence?:float, counts?:array, objects?:array, notes?:array, positions?:string}
     */
    public static function compare(array $captureA, array $captureB, array $detA, array $detB, ?array $bToA = null): array
    {
        $geoA = Capture::resolveGeoRef($captureA);
        $geoB = Capture::resolveGeoRef($captureB);
        $aToB = $bToA ? self::invert3($bToA) : null;
        if (!$aToB) {
            $bToA = null;
        }
        if (!$geoA || (!$bToA && !$geoB)) {
            return ['available' => false, 'reason' => 'Servono riprese georiferite: senza coordinate non si può sapere dove si trovano gli oggetti dell\'una nell\'altra.'];
        }
        // Da frazioni di B a frazioni di A, e viceversa: con l'allineamento
        // delle immagini se c'è, altrimenti con le coordinate.
        $bToAFn = $bToA
            ? fn(float $x, float $y): array => self::apply3($bToA, $x, $y)
            : fn(float $x, float $y): array => Capture::lonLatToFrac($geoA, ...Capture::fracToLonLat($geoB, $x, $y));
        $aToBFn = $aToB
            ? fn(float $x, float $y): array => self::apply3($aToB, $x, $y)
            : fn(float $x, float $y): array => Capture::lonLatToFrac($geoB, ...Capture::fracToLonLat($geoA, $x, $y));
        $identity = fn(float $x, float $y): array => [$x, $y];

        // Stessa soglia di confidenza per le due riprese: con soglie diverse
        // gli oggetti incerti risulterebbero comparsi o spariti solo perché
        // tenuti da una parte e scartati dall'altra.
        $confidence = max((float) $detA['confidence'], (float) $detB['confidence']);
        $notes = [];
        if ((bool) ($detA['small_objects'] ?? false) !== (bool) ($detB['small_objects'] ?? false)) {
            $notes[] = 'Rilevamenti eseguiti con impostazioni diverse ("oggetti piccoli" solo su una delle due riprese): gli oggetti piccoli possono risultare comparsi o spariti per questo.';
        }

        if (!$bToA) {
            $notes[] = 'Posizioni dalle sole coordinate (immagini non allineate): fra fonti diverse possono differire di qualche metro, e oggetti vicini fra loro possono risultare comparsi e spariti.';
        }
        // Tutto nel sistema di A: A così com'è, B riportata su A.
        $objsA = self::prepare($detA, $identity, $aToBFn, $geoA, $confidence);
        $objsB = self::prepare($detB, $bToAFn, $identity, $geoA, $confidence);

        // Abbinamento: coppie compatibili dalla più vicina, ciascun oggetto
        // usato una volta sola.
        $pairs = [];
        foreach ($objsA as $i => $a) {
            if (!$a['common']) {
                continue;
            }
            foreach ($objsB as $j => $b) {
                if (!$b['common'] || $a['group'] !== $b['group']) {
                    continue;
                }
                $dist = self::distanceM($a['lonlat'], $b['lonlat']);
                $lenA = $a['size_m'][0] ?? null;
                $lenB = $b['size_m'][0] ?? null;
                $radius = max(self::MIN_MATCH_M, self::MATCH_LENGTH_FRACTION * max($lenA ?? 0, $lenB ?? 0));
                if ($dist > $radius) {
                    continue;
                }
                if ($lenA && $lenB && max($lenA, $lenB) / max(0.1, min($lenA, $lenB)) > self::MAX_SIZE_RATIO) {
                    continue;
                }
                $pairs[] = [$dist, $i, $j];
            }
        }
        usort($pairs, fn($p, $q) => $p[0] <=> $q[0]);
        $matchedA = $matchedB = [];
        $objects = [];
        foreach ($pairs as [$dist, $i, $j]) {
            if (isset($matchedA[$i]) || isset($matchedB[$j])) {
                continue;
            }
            $matchedA[$i] = $matchedB[$j] = true;
            $objects[] = self::entry('unchanged', $objsA[$i], $objsB[$j], round($dist, 1));
        }
        foreach ($objsA as $i => $a) {
            if ($a['common'] && !isset($matchedA[$i])) {
                $objects[] = self::entry('disappeared', $a, null, null);
            }
        }
        foreach ($objsB as $j => $b) {
            if ($b['common'] && !isset($matchedB[$j])) {
                $objects[] = self::entry('appeared', null, $b, null);
            }
        }

        // Prima i cambiamenti, poi per classe e da sinistra in alto.
        $order = ['appeared' => 0, 'disappeared' => 1, 'unchanged' => 2];
        usort($objects, fn($p, $q) => [$order[$p['status']], array_search($p['class'], array_keys(self::CLASSES), true), $p['center'][1], $p['center'][0]]
            <=> [$order[$q['status']], array_search($q['class'], array_keys(self::CLASSES), true), $q['center'][1], $q['center'][0]]);
        foreach ($objects as $k => &$o) {
            $o['n'] = $k + 1;
        }
        unset($o);

        $counts = [];
        foreach ($objects as $o) {
            $counts[$o['class']] ??= ['appeared' => 0, 'disappeared' => 0, 'unchanged' => 0];
            $counts[$o['class']][$o['status']]++;
        }
        $outside = count(array_filter($objsA, fn($o) => !$o['common'])) + count(array_filter($objsB, fn($o) => !$o['common']));
        if ($outside) {
            $notes[] = $outside . ' oggett' . ($outside === 1 ? 'o' : 'i') . ' fuori dall\'area ripresa in entrambe le date, non confrontat' . ($outside === 1 ? 'o' : 'i') . '.';
        }

        $result = [
            'available' => true,
            'confidence' => $confidence,
            'counts' => $counts,
            'objects' => $objects,
            'notes' => $notes,
            'positions' => $bToA ? 'alignment' : 'coordinates',
        ];
        $result['summary'] = self::summary($result);
        return $result;
    }

    /** Riassunto per didascalie e report: "aerei: 3 comparsi, 2 spariti, 5 rimasti". */
    public static function summary(array $result): string
    {
        if (empty($result['available'])) {
            return '';
        }
        $parts = [];
        foreach (self::CLASSES as $class => $_) {
            $c = $result['counts'][$class] ?? null;
            if (!$c) {
                continue;
            }
            $parts[] = (Detection::HISTORY_CLASSES[$class] ?? $class) . ': '
                . $c['appeared'] . ' compars' . ($c['appeared'] === 1 ? 'o' : 'i') . ', '
                . $c['disappeared'] . ' sparit' . ($c['disappeared'] === 1 ? 'o' : 'i') . ', '
                . $c['unchanged'] . ' rimast' . ($c['unchanged'] === 1 ? 'o' : 'i');
        }
        return $parts ? implode('; ', $parts) : 'nessun velivolo, nave o veicolo rilevato nell\'area comune';
    }

    /**
     * Oggetti confrontabili di un rilevamento, nel sistema di A: centro e
     * poligono in frazioni di A, posizione geografica (dal sistema di A, per
     * le distanze in metri) e se cadono anche nell'altra ripresa.
     *
     * @param callable $toA frazioni della ripresa del rilevamento → frazioni di A
     * @param callable $toOther frazioni di A → frazioni dell'altra ripresa
     */
    private static function prepare(array $det, callable $toA, callable $toOther, array $geoA, float $confidence): array
    {
        $out = [];
        $point = fn($p) => is_array($p) && count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1]);
        foreach ($det['result']['objects'] ?? [] as $o) {
            if (!is_array($o) || !isset(self::CLASSES[$o['class'] ?? '']) || (float) ($o['confidence'] ?? 0) < $confidence
                || !$point($o['center'] ?? null)) {
                continue;
            }
            [$cx, $cy] = $toA((float) $o['center'][0], (float) $o['center'][1]);
            [$ox, $oy] = $toOther($cx, $cy);
            $polygon = [];
            foreach (is_array($o['polygon'] ?? null) ? $o['polygon'] : [] as $p) {
                if ($point($p)) {
                    [$px, $py] = $toA((float) $p[0], (float) $p[1]);
                    $polygon[] = [round($px, 6), round($py, 6)];
                }
            }
            $out[] = [
                'class' => $o['class'],
                'label' => $o['label'] ?? $o['class'],
                'group' => self::CLASSES[$o['class']],
                'confidence' => (float) $o['confidence'],
                'size_m' => $o['size_m'] ?? null,
                'candidate' => $o['candidates'][0]['name'] ?? null,
                'n' => $o['n'] ?? null,
                'lonlat' => Capture::fracToLonLat($geoA, $cx, $cy),
                'common' => $ox >= -self::EDGE_MARGIN && $ox <= 1 + self::EDGE_MARGIN && $oy >= -self::EDGE_MARGIN && $oy <= 1 + self::EDGE_MARGIN,
                'center' => [round($cx, 6), round($cy, 6)],
                'polygon' => $polygon,
            ];
        }
        return $out;
    }

    /** @return float[] punto trasformato da un'omografia 3×3 */
    private static function apply3(array $m, float $x, float $y): array
    {
        $w = $m[2][0] * $x + $m[2][1] * $y + $m[2][2];
        if (abs($w) < 1e-12) {
            return [NAN, NAN];
        }
        return [($m[0][0] * $x + $m[0][1] * $y + $m[0][2]) / $w, ($m[1][0] * $x + $m[1][1] * $y + $m[1][2]) / $w];
    }

    /** Inversa di una matrice 3×3, null se non valida o singolare. */
    private static function invert3(array $m): ?array
    {
        for ($i = 0; $i < 3; $i++) {
            for ($j = 0; $j < 3; $j++) {
                if (!isset($m[$i][$j]) || !is_numeric($m[$i][$j])) {
                    return null;
                }
            }
        }
        [[$a, $b, $c], [$d, $e, $f], [$g, $h, $i]] = $m;
        $det = $a * ($e * $i - $f * $h) - $b * ($d * $i - $f * $g) + $c * ($d * $h - $e * $g);
        if (abs($det) < 1e-12) {
            return null;
        }
        return [
            [($e * $i - $f * $h) / $det, ($c * $h - $b * $i) / $det, ($b * $f - $c * $e) / $det],
            [($f * $g - $d * $i) / $det, ($a * $i - $c * $g) / $det, ($c * $d - $a * $f) / $det],
            [($d * $h - $e * $g) / $det, ($b * $g - $a * $h) / $det, ($a * $e - $b * $d) / $det],
        ];
    }

    private static function entry(string $status, ?array $a, ?array $b, ?float $distance): array
    {
        $main = $b ?? $a;
        return [
            'status' => $status,
            'class' => $main['class'],
            'label' => $main['label'],
            // Posizione nel sistema di A: nella ripresa B per ciò che c'è
            // in B, nella A per ciò che è sparito.
            'center' => $main['center'],
            // Il contorno solo per ciò che è cambiato: i rimasti (centinaia di
            // veicoli in un parcheggio) appesantirebbero ogni pagina dello
            // studio, che incorpora tutti i confronti; per loro basta il centro.
            'polygon' => $status === 'unchanged' ? [] : $main['polygon'],
            'size_m' => $main['size_m'],
            'candidate' => $main['candidate'],
            'confidence' => $main['confidence'],
            'a_n' => $a['n'] ?? null,
            'b_n' => $b['n'] ?? null,
            'distance_m' => $distance,
        ];
    }

    private static function distanceM(array $p, array $q): float
    {
        $dx = ($q[0] - $p[0]) * 111320 * cos(deg2rad(($p[1] + $q[1]) / 2));
        $dy = ($q[1] - $p[1]) * 111320;
        return hypot($dx, $dy);
    }
}
