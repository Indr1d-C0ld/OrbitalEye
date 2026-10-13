<?php
// Libreria visiva: vettori, somiglianza, suggerimenti, riferimenti dalle verifiche.

/** Vettore int8 in base64 come lo produce embed.py, da una direzione "base" più rumore. */
function vl_vec(int $seed, int $noiseSeed = 0, float $noise = 0.0): string
{
    mt_srand($seed);
    $v = [];
    for ($k = 0; $k < 384; $k++) {
        $v[] = mt_rand(-100, 100);
    }
    if ($noise > 0) {
        mt_srand($noiseSeed);
        foreach ($v as &$x) {
            $x += (int) round(mt_rand(-100, 100) * $noise);
        }
        unset($x);
    }
    return base64_encode(pack('c*', ...array_map(fn($x) => max(-127, min(127, $x)), $v)));
}

function vl_ref(int $id, int $capture, string $type, int $seed, int $noiseSeed): array
{
    return ['id' => $id, 'capture_id' => $capture, 'type' => $type, 'vectors' => [VisualLibrary::decode(vl_vec($seed, $noiseSeed, 0.3))],
        'label' => "Ripresa $capture", 'study' => 'Prova', 'date' => '2025-01-01', 'acquisition' => "acq:$capture"];
}

test('libreria: somiglianza massima fra gli orientamenti, vettori non validi ignorati', function () {
    $a = VisualLibrary::decode(vl_vec(1));
    $b = VisualLibrary::decode(vl_vec(2));
    assert_near(1.0, VisualLibrary::similarity([$a], [$a]), 1e-9);
    // Il secondo orientamento della query coincide con il riferimento.
    assert_near(1.0, VisualLibrary::similarity([$b, $a], [$a]), 1e-9);
    assert_true(VisualLibrary::similarity([$a], [$b]) < 0.5, 'vettori diversi troppo simili');
    assert_same(null, VisualLibrary::decode('non base64!!'));
    assert_same(null, VisualLibrary::decode(['x']));
});

test('libreria: suggerimenti per tipo, il più simile prima, esclusioni', function () {
    $refs = [vl_ref(1, 10, 'A400M Atlas', 1, 11), vl_ref(2, 11, 'A400M Atlas', 1, 12), vl_ref(3, 10, 'C-17 Globemaster III', 2, 13)];
    $q = [VisualLibrary::decode(vl_vec(1, 14, 0.3))];
    $s = VisualLibrary::suggest($q, $refs);
    assert_same(['A400M Atlas', 'C-17 Globemaster III'], array_column($s, 'type'));
    assert_true($s[0]['similarity'] > $s[1]['similarity']);
    // Escludendo entrambi i riferimenti A400M resta solo il C-17.
    assert_same(['C-17 Globemaster III'], array_column(VisualLibrary::suggest($q, $refs, [1 => true, 2 => true]), 'type'));
});

test('libreria: valutazione solo con riferimenti di altre riprese', function () {
    $refs = [
        vl_ref(1, 10, 'A400M Atlas', 1, 21), vl_ref(2, 11, 'A400M Atlas', 1, 22),
        vl_ref(3, 10, 'C-17 Globemaster III', 2, 23), vl_ref(4, 12, 'C-17 Globemaster III', 2, 24),
        vl_ref(5, 10, 'Airbus A330-200', 3, 25),   // unico del suo tipo: non conta
    ];
    $s = VisualLibrary::leaveOneOut($refs);
    assert_same(4, $s['n']);
    assert_same(4, $s['top1']);
});

test('libreria: una verifica con il tipo diventa un riferimento, con i vettori del rilevamento', function () {
    $cap = Capture::create(test_study(), 'Libreria', 'esri', '2025-01-01', 'raw/inesistente.jpg', 1000, 1000, ['bbox' => [10.0, 45.0, 10.005, 45.004]]);
    $poly = [[0.4, 0.4], [0.45, 0.4], [0.45, 0.45], [0.4, 0.45]];
    $obj = ['n' => 1, 'class' => 'plane', 'label' => 'aereo', 'confidence' => 0.9, 'center' => [0.425, 0.425], 'polygon' => $poly];
    Detection::save($cap, test_study(), 'yolo11s-obb', 0.25, false, 0.4, ['objects' => [$obj]], ['plane' => 1], [1 => [vl_vec(5), vl_vec(6)]]);
    $id = DetectionReview::save($cap, ['verdict' => 'confirmed', 'class' => 'plane', 'type_name' => 'A400M Atlas', 'polygon' => $poly]);
    $refs = array_values(array_filter(VisualLibrary::references(true), fn($r) => $r['id'] === $id));
    assert_same(1, count($refs));
    assert_same(2, count($refs[0]['vectors']));
    // Il rilevamento mandato alla pagina: niente vettori, e l'oggetto non
    // suggerisce se stesso.
    $client = Detection::forClient(Detection::forCapture($cap));
    assert_true(!isset($client['embeddings']), 'i vettori non devono arrivare alla pagina');
    $own = array_filter($client['result']['objects'][0]['visual'] ?? [], fn($v) => $v['capture_id'] === $cap && $v['type'] === 'A400M Atlas' && $v['similarity'] > 0.999);
    assert_same([], array_values($own));
    // Senza tipo non è più un riferimento.
    DetectionReview::save($cap, ['verdict' => 'confirmed', 'class' => 'plane', 'type_name' => '', 'polygon' => $poly]);
    assert_same([], array_values(array_filter(VisualLibrary::references(true), fn($r) => $r['id'] === $id)));
});

test('libreria: un rilevamento rieseguito senza vettori non cancella il riferimento', function () {
    $cap = Capture::create(test_study(), 'Libreria 2', 'esri', '2025-01-01', 'raw/inesistente.jpg', 1000, 1000, ['bbox' => [10.0, 45.0, 10.005, 45.004]]);
    $poly = [[0.2, 0.2], [0.25, 0.2], [0.25, 0.25], [0.2, 0.25]];
    $obj = ['n' => 1, 'class' => 'plane', 'label' => 'aereo', 'confidence' => 0.9, 'center' => [0.225, 0.225], 'polygon' => $poly];
    Detection::save($cap, test_study(), 'yolo11s-obb', 0.25, false, 0.4, ['objects' => [$obj]], ['plane' => 1], [1 => [vl_vec(7)]]);
    $id = DetectionReview::save($cap, ['verdict' => 'confirmed', 'class' => 'plane', 'type_name' => 'C-17 Globemaster III', 'polygon' => $poly]);
    // Nuovo rilevamento senza vettori (modello assente, tempo esaurito).
    Detection::save($cap, test_study(), 'yolo11s-obb', 0.25, false, 0.4, ['objects' => [$obj]], ['plane' => 1], null);
    DetectionReview::refreshEmbeddings($cap);
    assert_same(1, count(array_filter(VisualLibrary::references(true), fn($r) => $r['id'] === $id)));
});

test('libreria: suggerimenti in cache finché la libreria non cambia, "chiaro" solo con distacco', function () {
    $mk = function (string $label, string $vec, string $type) {
        $cap = Capture::create(test_study(), $label, 'esri', '2025-01-01', 'raw/inesistente.jpg', 1000, 1000, ['bbox' => [10.0, 45.0, 10.005, 45.004]]);
        $poly = [[0.6, 0.6], [0.65, 0.6], [0.65, 0.65], [0.6, 0.65]];
        Detection::save($cap, test_study(), 'yolo11s-obb', 0.25, false, 0.4,
            ['objects' => [['n' => 1, 'class' => 'plane', 'label' => 'aereo', 'confidence' => 0.9, 'center' => [0.625, 0.625], 'polygon' => $poly]]],
            ['plane' => 1], [1 => [$vec]]);
        if ($type !== '') {
            DetectionReview::save($cap, ['verdict' => 'confirmed', 'class' => 'plane', 'type_name' => $type, 'polygon' => $poly]);
        }
        return $cap;
    };
    $mk('Rif A', vl_vec(40, 41, 0.2), 'Airbus A330-200');
    $query = $mk('Query', vl_vec(40, 42, 0.2), '');
    // Le cache statiche durano una richiesta: qui, fra un passo e l'altro, vanno rinfrescate.
    VisualLibrary::references(true);
    VisualLibrary::version(true);
    $first = Detection::forClient(Detection::forCapture($query))['result']['objects'][0]['visual'];
    assert_same('Airbus A330-200', $first[0]['type']);
    $row = Detection::forCapture($query);
    assert_true(is_array($row['visual_cache']), 'suggerimenti non salvati in cache');
    // Un riferimento nuovo, di un tipo molto diverso: la libreria cambia e i
    // suggerimenti si ricalcolano; il primo tipo stacca il secondo.
    $mk('Rif B', vl_vec(50), 'B-52 Stratofortress');
    VisualLibrary::references(true);
    VisualLibrary::version(true);
    $second = Detection::forClient(Detection::forCapture($query))['result']['objects'][0]['visual'];
    assert_same('Airbus A330-200', $second[0]['type']);
    assert_true(in_array('B-52 Stratofortress', array_column($second, 'type'), true), 'cache non ricalcolata');
    assert_true($row['visual_cache']['key'] !== Detection::forCapture($query)['visual_cache']['key']);
    assert_true($second[0]['clear'], 'il primo tipo stacca il secondo: dovrebbe essere chiaro');
});
