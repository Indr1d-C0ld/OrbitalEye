<?php
// Geometria delle riprese: coordinate ↔ pixel, scala, ritagli, punti per
// l'allineamento geografico.

test('fracToLonLat e lonLatToFrac sono l\'una l\'inversa dell\'altra (anche ruotate)', function () {
    foreach ([0, 20, -73.5, 180] as $rot) {
        foreach (['metric', null] as $model) {
            $g = ['bbox' => [14.90, 37.40, 14.92, 37.41], 'rotation' => $rot, 'rotation_model' => $model];
            foreach ([[0, 0], [0.3, 0.7], [1, 1], [1.2, -0.1]] as [$fx, $fy]) {
                [$lon, $lat] = Capture::fracToLonLat($g, $fx, $fy);
                [$x, $y] = Capture::lonLatToFrac($g, $lon, $lat);
                assert_near($fx, $x, 1e-9, "rot $rot x");
                assert_near($fy, $y, 1e-9, "rot $rot y");
            }
        }
    }
});

test('resolveMpp ricava metri/pixel dalla bbox per asse', function () {
    // 0,01° di latitudine ≈ 1112 m; di longitudine a 45° ≈ 786 m.
    $mpp = Capture::resolveMpp(['meta_json' => json_encode(['bbox' => [10.0, 44.995, 10.01, 45.005]]), 'width' => 1000, 'height' => 1000]);
    assert_near(0.786, $mpp['mpp_x'], 0.005);
    assert_near(1.112, $mpp['mpp_y'], 0.005);
});

test('punti per l\'allineamento geografico: B spostata di metà area', function () {
    $a = ['id' => 1, 'width' => 1000, 'height' => 1000, 'meta_json' => json_encode(['bbox' => [10.0, 45.0, 10.01, 45.01]])];
    $b = ['id' => 2, 'width' => 500, 'height' => 500, 'meta_json' => json_encode(['bbox' => [10.005, 45.0, 10.015, 45.01]])];
    $pts = ComparisonRunner::geoControlPoints($a, $b);
    assert_true($pts !== null && count($pts) >= 4, 'servono almeno 4 punti');
    foreach ($pts as $p) {
        // Stesso punto: x di B = (x di A − metà larghezza) in pixel di B.
        assert_near(($p['ax'] / 1000 - 0.5) * 500, $p['bx'], 1e-6);
        assert_near($p['ay'] / 1000 * 500, $p['by'], 1e-6);
    }
});

test('nessun allineamento geografico per riprese senza coordinate o disgiunte', function () {
    $a = ['id' => 1, 'width' => 100, 'height' => 100, 'meta_json' => json_encode(['bbox' => [10.0, 45.0, 10.01, 45.01]])];
    $noGeo = ['id' => 2, 'width' => 100, 'height' => 100, 'meta_json' => '{}'];
    $far = ['id' => 3, 'width' => 100, 'height' => 100, 'meta_json' => json_encode(['bbox' => [11.0, 45.0, 11.01, 45.01]])];
    assert_same(null, ComparisonRunner::geoControlPoints($a, $noGeo));
    assert_same(null, ComparisonRunner::geoControlPoints($a, $far));
});

test('nessun allineamento geografico se i punti in comune sono tutti su una riga', function () {
    $a = ['id' => 1, 'width' => 100, 'height' => 100, 'meta_json' => json_encode(['bbox' => [10.0, 45.0, 10.01, 45.01]])];
    // B copre solo una striscia orizzontale al centro di A (la riga a metà altezza).
    $strip = ['id' => 2, 'width' => 100, 'height' => 10, 'meta_json' => json_encode(['bbox' => [9.99, 45.0045, 10.02, 45.0055]])];
    assert_same(null, ComparisonRunner::geoControlPoints($a, $strip));
});

test('dettaglio: pixel in proporzione ai gradi, alla risoluzione nativa', function () {
    // A 45°: 0,01° di longitudine ≈ 787 m, di latitudine ≈ 1113 m.
    [$w, $h] = DetailFetcher::fetchSize([10.0, 45.0, 10.01, 45.01], 0.5);
    assert_same($w, $h, 'stesso numero di pixel per lato su un quadrato in gradi');
    assert_near(2226, $h, 1, 'latitudine alla risoluzione nativa');
    [$w, $h] = DetailFetcher::fetchSize([10.0, 45.0, 10.04, 45.01], 0.5);
    assert_same(2500, $w, 'limite di 2500 pixel per lato');
    assert_near(4.0, $w / $h, 0.01, 'proporzione in gradi conservata anche col limite');
});

test('geoMetaForDerived: il ritaglio di una ripresa ruotata ne conserva la rotazione', function () {
    $src = ['id' => 1, 'width' => 1000, 'height' => 1000, 'meta_json' => json_encode(['bbox' => [10.0, 45.0, 10.02, 45.02], 'rotation' => 30, 'rotation_model' => 'metric'])];
    $m = Capture::geoMetaForDerived($src, ['x' => 0.25, 'y' => 0.25, 'w' => 0.5, 'h' => 0.5]);
    assert_same(30.0, (float) $m['rotation']);
    assert_near(0.01, $m['bbox'][2] - $m['bbox'][0], 1e-9, 'larghezza in gradi');
    // Il centro del ritaglio è il centro della sorgente.
    assert_near(10.01, ($m['bbox'][0] + $m['bbox'][2]) / 2, 1e-9);
});
