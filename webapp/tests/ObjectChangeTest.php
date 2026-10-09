<?php
// Confronto per oggetti: comparsi, spariti, rimasti.

function oc_capture(int $id, array $bbox): array
{
    return ['id' => $id, 'width' => 1000, 'height' => 1000, 'meta_json' => json_encode(['bbox' => $bbox])];
}

/** Oggetto rilevato: centro in frazioni, lunghezza in metri. */
function oc_obj(string $class, float $x, float $y, ?float $length = 30.0, float $conf = 0.8): array
{
    $d = 0.01;
    return [
        'class' => $class, 'label' => $class, 'confidence' => $conf, 'n' => 1,
        'center' => [$x, $y],
        'polygon' => [[$x - $d, $y - $d], [$x + $d, $y - $d], [$x + $d, $y + $d], [$x - $d, $y + $d]],
        'size_m' => $length !== null ? [$length, $length * 0.9] : null,
    ];
}

function oc_det(array $objects, float $conf = 0.25, bool $small = false): array
{
    return ['confidence' => $conf, 'small_objects' => $small, 'result' => ['objects' => $objects]];
}

function oc_statuses(array $r): array
{
    $s = array_map(fn($o) => $o['status'] . ':' . $o['class'], $r['objects']);
    sort($s);
    return $s;
}

// ~787 × 1113 m a 45°: 0,01 di larghezza ≈ 7,9 m.
const OC_BBOX = [10.0, 45.0, 10.01, 45.01];

test('oggetti: rimasto, sparito e comparso', function () {
    $a = oc_capture(1, OC_BBOX);
    $b = oc_capture(2, OC_BBOX);
    $r = ObjectChange::compare($a, $b,
        oc_det([oc_obj('plane', 0.2, 0.2), oc_obj('plane', 0.5, 0.5)]),
        // Stesso aereo spostato di ~5 m (scarto di georeferenza), più uno nuovo.
        oc_det([oc_obj('plane', 0.506, 0.5), oc_obj('plane', 0.8, 0.8)]));
    assert_true($r['available']);
    assert_same(['appeared:plane', 'disappeared:plane', 'unchanged:plane'], oc_statuses($r));
    assert_same(['appeared' => 1, 'disappeared' => 1, 'unchanged' => 1], $r['counts']['plane']);
    // Prima i cambiamenti.
    assert_same('appeared', $r['objects'][0]['status']);
    assert_same('aerei: 1 comparso, 1 sparito, 1 rimasto', ObjectChange::summary($r));
});

test('oggetti: stessa piazzola ma dimensioni diverse = sostituito', function () {
    $r = ObjectChange::compare(oc_capture(1, OC_BBOX), oc_capture(2, OC_BBOX),
        oc_det([oc_obj('plane', 0.5, 0.5, 18.0)]),     // un caccia
        oc_det([oc_obj('plane', 0.5, 0.5, 45.0)]));    // un aereo da trasporto
    assert_same(['appeared:plane', 'disappeared:plane'], oc_statuses($r));
});

test('oggetti: categorie diverse non si abbinano, veicoli grandi e piccoli sì', function () {
    $r = ObjectChange::compare(oc_capture(1, OC_BBOX), oc_capture(2, OC_BBOX),
        oc_det([oc_obj('plane', 0.3, 0.3), oc_obj('large vehicle', 0.6, 0.6, 10.0)]),
        oc_det([oc_obj('helicopter', 0.3, 0.3), oc_obj('small vehicle', 0.6, 0.6, 8.0)]));
    assert_same(['appeared:helicopter', 'disappeared:plane', 'unchanged:small vehicle'], oc_statuses($r));
});

test('oggetti: stessa soglia di confidenza per le due riprese', function () {
    // A rilevata a 0,25, B a 0,5: l'aereo incerto (0,3) di A non conta,
    // altrimenti risulterebbe sparito solo perché B lo ha scartato.
    $r = ObjectChange::compare(oc_capture(1, OC_BBOX), oc_capture(2, OC_BBOX),
        oc_det([oc_obj('plane', 0.3, 0.3, 30.0, 0.3)], 0.25),
        oc_det([], 0.5));
    assert_same(0.5, $r['confidence']);
    assert_same([], $r['objects']);
});

test('oggetti: fuori dall\'area comune non sono spariti', function () {
    // B copre solo la metà est di A.
    $b = oc_capture(2, [10.005, 45.0, 10.015, 45.01]);
    $r = ObjectChange::compare(oc_capture(1, OC_BBOX), $b,
        oc_det([oc_obj('plane', 0.2, 0.5), oc_obj('plane', 0.7, 0.5)]),
        oc_det([]));
    assert_same(['disappeared:plane'], oc_statuses($r));
    // Posizione nel sistema di A.
    assert_near(0.7, $r['objects'][0]['center'][0], 1e-6);
    assert_true(str_contains(implode(' ', $r['notes']), '1 oggetto fuori'));
});

test('oggetti: posizione di B riportata nel sistema di A', function () {
    $b = oc_capture(2, [10.005, 45.0, 10.015, 45.01]);
    $r = ObjectChange::compare(oc_capture(1, OC_BBOX), $b, oc_det([]), oc_det([oc_obj('ship', 0.2, 0.5)]));
    // x di B 0,2 → longitudine 10,007 → x di A 0,7.
    assert_near(0.7, $r['objects'][0]['center'][0], 1e-6);
    assert_near(0.5, $r['objects'][0]['center'][1], 1e-6);
});

test('oggetti: senza coordinate non si confronta', function () {
    $noGeo = ['id' => 3, 'width' => 100, 'height' => 100, 'meta_json' => '{}'];
    $r = ObjectChange::compare(oc_capture(1, OC_BBOX), $noGeo, oc_det([]), oc_det([]));
    assert_same(false, $r['available']);
});

test('oggetti: con l\'allineamento delle immagini, uno scarto di georeferenza non separa lo stesso veicolo', function () {
    // Stessa area per le coordinate, ma le immagini di B sono spostate di
    // ~12 m verso est (scarto fra fonti): il veicolo è lo stesso.
    $a = oc_capture(1, OC_BBOX);
    $b = oc_capture(2, OC_BBOX);
    $detA = oc_det([oc_obj('small vehicle', 0.5, 0.5, 5.0)]);
    $detB = oc_det([oc_obj('small vehicle', 0.51525, 0.5, 5.0)]);
    // Solo coordinate: 12 m di distanza, oltre il raggio di 10 m.
    $geo = ObjectChange::compare($a, $b, $detA, $detB);
    assert_same(['appeared:small vehicle', 'disappeared:small vehicle'], oc_statuses($geo));
    assert_same('coordinates', $geo['positions']);
    // Con la trasformazione trovata allineando le immagini (B → A: x − 0,01525).
    $bToA = [[1, 0, -0.01525], [0, 1, 0], [0, 0, 1]];
    $aligned = ObjectChange::compare($a, $b, $detA, $detB, $bToA);
    assert_same(['unchanged:small vehicle'], oc_statuses($aligned));
    assert_same('alignment', $aligned['positions']);
    assert_near(0.5, $aligned['objects'][0]['center'][0], 1e-6);
});

test('oggetti: con l\'allineamento basta che A sia georiferita', function () {
    $noGeo = ['id' => 3, 'width' => 1000, 'height' => 1000, 'meta_json' => '{}'];
    $r = ObjectChange::compare(oc_capture(1, OC_BBOX), $noGeo, oc_det([oc_obj('plane', 0.4, 0.4)]),
        oc_det([oc_obj('plane', 0.4, 0.4)]), [[1, 0, 0], [0, 1, 0], [0, 0, 1]]);
    assert_same(['unchanged:plane'], oc_statuses($r));
});

test('oggetti: rilevamenti malformati ignorati, matrice non valida ignorata', function () {
    $bad = oc_det([
        ['class' => 'plane', 'confidence' => 0.9],                          // senza centro
        ['class' => 'plane', 'confidence' => 0.9, 'center' => 'x'],
        ['class' => 'plane', 'confidence' => 0.9, 'center' => [0.3, 0.3], 'polygon' => 'x'],
        'non un oggetto',
    ]);
    $r = ObjectChange::compare(oc_capture(1, OC_BBOX), oc_capture(2, OC_BBOX), $bad, oc_det([]), [[0, 0, 0], [0, 0, 0], [0, 0, 0]]);
    assert_same(['disappeared:plane'], oc_statuses($r));
    assert_same([], $r['objects'][0]['polygon']);
    assert_same('coordinates', $r['positions']);
});
