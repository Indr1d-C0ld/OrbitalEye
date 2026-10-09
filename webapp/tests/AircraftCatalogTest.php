<?php
// Identificazione dei velivoli dalle dimensioni (vedi AircraftCatalog).

$top = fn(array $r) => $r[0]['name'] ?? null;

test('misure di un P-8 Poseidon: primo il P-8', function () use ($top) {
    assert_same('P-8 Poseidon', $top(AircraftCatalog::match(37.6, 39.5, 1.1, 'measure', 'ala_fissa')));
});

test('Tornado con le ali a freccia riconosciuto dall\'apertura minima', function () {
    $r = AircraftCatalog::match(8.7, 16.7, 0.5, 'measure', 'ala_fissa', 3);
    assert_same('Tornado', $r[0]['name']);
    assert_same('ali a freccia', $r[0]['note']);
});

test('elicottero dal diametro del rotore', function () use ($top) {
    $name = $top(AircraftCatalog::match(16.3, 19.7, 0.3, 'measure', 'elicottero'));
    assert_true(in_array($name, ['MH-60R Seahawk', 'SH-60 Seahawk', 'UH-60 Black Hawk'], true), "ottenuto $name");
});

test('il filtro sulla forma delle ali separa jet e turboelica', function () {
    foreach (AircraftCatalog::match(33, 35.5, 1.0, 'measure', 'ala_fissa', 10, 'dritta') as $c) {
        assert_same('dritta', $c['wing']);
    }
});

test('il filtro sulle ali non si applica agli elicotteri', function () {
    assert_true(count(AircraftCatalog::match(16.3, 19.7, 0.3, 'measure', 'elicottero', 5, 'freccia')) > 0);
});

test('riquadro automatico: un tipo più grande del riquadro non è compatibile', function () {
    foreach (AircraftCatalog::match(20, 20, 0.5, 'box', 'ala_fissa', 50) as $c) {
        assert_true($c['span_m'] <= 23 || ($c['span_min_m'] ?? 99) <= 23, $c['name'] . ' troppo grande');
    }
});

test('etichette: riconoscimento per parola intera', function () {
    $cases = [
        'UH-60' => 'UH-60 Black Hawk', 'F-22' => 'F-22 Raptor', 'AH-64D' => 'AH-64 Apache',
        'An-124' => 'An-124 Ruslan', 'P-8 Poseidon?' => 'P-8 Poseidon', 'aereo · P-8?' => 'P-8 Poseidon',
        'Su-22' => null, 'aereo' => null, '' => null,
    ];
    foreach ($cases as $label => $expected) {
        assert_same($expected, AircraftCatalog::typeForLabel($label), "etichetta \"$label\"");
    }
});

test('la tabella dei tipi è completa', function () {
    foreach (AircraftCatalog::types() as $t) {
        if ($t['category'] === 'elicottero') {
            assert_true(!empty($t['rotor_m']), $t['name'] . ' senza rotore');
        } else {
            assert_true(!empty($t['span_m']) && !empty($t['length_m']), $t['name'] . ' senza dimensioni');
        }
        assert_true(!empty($t['wing']), $t['name'] . ' senza forma delle ali');
    }
});
