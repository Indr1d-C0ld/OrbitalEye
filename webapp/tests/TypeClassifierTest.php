<?php
// Indizio del classificatore MAR20: attendibile solo se molto probabile e
// con misure compatibili; statistiche nella pagina della qualità.

function tc_square(float $x, float $y, float $half = 0.02): array
{
    return [[$x - $half, $y - $half], [$x + $half, $y - $half], [$x + $half, $y + $half], [$x - $half, $y + $half]];
}

test('MAR20: ★ solo con probabilità alta e misure vicine al tipo', function () {
    // C-17 misurato dalla sagoma (51,8 × 53,0 veri).
    $matches = AircraftCatalog::match(52.4, 54.5, 0.4, 'silhouette', 'ala_fissa', PHP_INT_MAX);
    $g = TypeClassifier::assess([['type' => 'C-17 Globemaster III', 'p' => 0.95], ['type' => 'C-5 Galaxy', 'p' => 0.03]], $matches);
    assert_true($g['reliable'], 'C-17 probabile e compatibile: dovrebbe essere attendibile');
    assert_true($g['types'][0]['distance'] !== null && $g['types'][0]['distance'] <= TypeClassifier::MAX_DISTANCE);
    // Probabilità bassa.
    $g = TypeClassifier::assess([['type' => 'C-17 Globemaster III', 'p' => 0.55]], $matches);
    assert_true(!$g['reliable']);
    assert_same('probabilità bassa', $g['reason']);
    // Un A330 (misurato 60,2 × 62,6) che il classificatore prende per un C-5
    // (67,9 × 75,3): le misure lo escludono.
    $a330 = AircraftCatalog::match(60.2, 62.6, 0.3, 'silhouette', 'ala_fissa', PHP_INT_MAX);
    $g = TypeClassifier::assess([['type' => 'C-5 Galaxy', 'p' => 0.94]], $a330);
    assert_true(!$g['reliable'], 'un A330 non deve ricevere la ★ come C-5');
    assert_same('misure poco compatibili con il tipo', $g['reason']);
});

test('MAR20: proposte non valide ignorate', function () {
    assert_same(null, TypeClassifier::assess(null, []));
    assert_same(null, TypeClassifier::assess([], []));
    assert_same(null, TypeClassifier::assess([['type' => 5, 'p' => 'x'], 'testo'], []));
    $g = TypeClassifier::assess([['type' => 'Tu-95', 'p' => 0.9]], []);
    assert_same(null, $g['types'][0]['distance']);
    assert_true(!$g['reliable']);
});

test('MAR20: varianti dello stesso tipo (C-130J per "C-130"), nomi presenti nella tabella', function () {
    $catalog = array_column(AircraftCatalog::types(), 'name');
    foreach (TypeClassifier::NAMES as $n) {
        foreach (TypeClassifier::members($n) as $m) {
            assert_true(in_array($m, $catalog, true), "$m non è nella tabella dei velivoli");
        }
    }
    assert_same(20, count(TypeClassifier::NAMES));
    assert_true(TypeClassifier::sameType('C-130 Hercules', 'C-130J-30 Super Hercules'));
    assert_true(!TypeClassifier::sameType('C-130 Hercules', 'A400M Atlas'));
    assert_true(TypeClassifier::known('F-15E Strike Eagle'));
    assert_true(!TypeClassifier::known('Airbus A330-200'));
    // Un C-130J-30 (40,4 × 34,4): la distanza è quella della variante più vicina.
    $m = AircraftCatalog::match(40.4, 34.2, 0.3, 'silhouette', 'ala_fissa', PHP_INT_MAX);
    $g = TypeClassifier::assess([['type' => 'C-130 Hercules', 'p' => 0.9]], $m);
    $byName = array_column($m, 'distance', 'name');
    assert_near($byName['C-130J-30 Super Hercules'], $g['types'][0]['distance'], 1e-9);
    assert_true($g['reliable']);
    assert_true($g['types'][0]['compatibility'] > 0.3);
});

test('qualità: indizi MAR20 rivalutati, varianti giuste, tipi sconosciuti solo nelle ★', function () {
    $obj = fn(float $x, array $sil, string $type, float $p) => ['class' => 'plane', 'confidence' => 0.9, 'center' => [$x, 0.5],
        'polygon' => tc_square($x, 0.5), 'size_m' => [$sil[0] * 1.2, $sil[1] * 1.1],
        'silhouette' => ['quality' => 'ok', 'span_m' => $sil[0], 'length_m' => $sil[1]],
        // "reliable" salvato falso: deve contare la rivalutazione con le soglie attuali.
        'type_guess' => ['types' => [['type' => $type, 'p' => $p, 'distance' => null]], 'reliable' => false, 'reason' => 'vecchio']];
    $det = ['result' => ['objects' => [
        $obj(0.2, [52.4, 54.5], 'C-17 Globemaster III', 0.95),   // C-17 vero: ★ giusta
        $obj(0.5, [67.5, 74.0], 'C-5 Galaxy', 0.9),              // l'analista dice A330: ★ sbagliata
        $obj(0.8, [40.4, 29.9], 'C-130 Hercules', 0.92),         // C-130J vero: ★ giusta (variante)
    ], 'mpp' => ['mpp_x' => 0.3, 'mpp_y' => 0.3]]];
    $rev = fn(float $x, string $type) => ['verdict' => 'confirmed', 'class' => 'plane', 'type_name' => $type, 'center_x' => $x, 'center_y' => 0.5,
        'radius' => 0.02, 'source' => 'detection', 'polygon' => tc_square($x, 0.5)];
    $q = DetectionQuality::compute([11 => [$rev(0.2, 'C-17 Globemaster III'), $rev(0.5, 'Airbus A330-200'), $rev(0.8, 'C-130J Super Hercules')]], [11 => $det], []);
    // Posizione: solo i due tipi che MAR20 conosce.
    assert_same(2, $q['identification']['mar20']['n']);
    assert_same(2, $q['identification']['mar20']['top1']);
    assert_same(['n' => 3, 'right' => 2], $q['mar20_star']);
});
