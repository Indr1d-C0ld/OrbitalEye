<?php
// Verifica dei rilevamenti e misure di qualità; tipi dalla sagoma.

function dq_capture(): int
{
    return Capture::create(test_study(), 'Verifica', 'esri', '2025-01-01', 'raw/inesistente.jpg', 1000, 1000,
        ['bbox' => [10.0, 45.0, 10.005, 45.004]]);
}

function dq_square(float $x, float $y, float $half = 0.02): array
{
    return [[$x - $half, $y - $half], [$x + $half, $y - $half], [$x + $half, $y + $half], [$x - $half, $y + $half]];
}

/** Oggetto di un rilevamento, come lo salva DetectionRunner. */
function dq_object(string $class, float $x, float $y, float $conf = 0.9, ?array $sil = null, ?array $box = null): array
{
    return ['class' => $class, 'confidence' => $conf, 'center' => [$x, $y], 'polygon' => dq_square($x, $y),
        'silhouette' => $sil, 'size_m' => $box];
}

test('tipi dalla sagoma: apertura e lunghezza al loro posto, il tipo vero fra i primi', function () {
    // Misure dalla sagoma di un A400M (42,4 × 45,1) con il 3% d'errore.
    $names = array_column(AircraftCatalog::match(41.2, 44.0, 0.4, 'silhouette', 'ala_fissa', 5), 'name');
    assert_true(in_array('A400M Atlas', $names, true), 'A400M non fra i primi 5: ' . implode(', ', $names));
    // Un C-17 (51,8 × 53,0) dalla sagoma; col riquadro (più grande del 20%) non compare.
    $sil = array_column(AircraftCatalog::match(52.4, 54.5, 0.4, 'silhouette', 'ala_fissa', 3), 'name');
    assert_true(in_array('C-17 Globemaster III', $sil, true), 'C-17 non fra i primi 3: ' . implode(', ', $sil));
    $box = array_column(AircraftCatalog::match(63.9, 55.4, 0.4, 'box', 'ala_fissa', 3), 'name');
    assert_true(!in_array('C-17 Globemaster III', $box, true), 'col riquadro il C-17 non dovrebbe essere fra i primi 3');
});

test('verifica: salvata per posizione, aggiornata sullo stesso oggetto, eliminata', function () {
    $cap = dq_capture();
    $id = DetectionReview::save($cap, ['verdict' => 'confirmed', 'class' => 'plane', 'type_name' => 'C-17 Globemaster III', 'polygon' => dq_square(0.3, 0.3)]);
    // Stesso oggetto (riquadro spostato di poco, come dopo un nuovo rilevamento): si aggiorna.
    $id2 = DetectionReview::save($cap, ['verdict' => 'rejected', 'polygon' => dq_square(0.305, 0.302)]);
    assert_same($id, $id2);
    $rows = DetectionReview::forCapture($cap);
    assert_same(1, count($rows));
    assert_same('rejected', $rows[0]['verdict']);
    assert_same(null, $rows[0]['type_name']);
    // Un altro oggetto, lontano: una seconda verifica.
    DetectionReview::save($cap, ['verdict' => 'confirmed', 'class' => 'ship', 'polygon' => dq_square(0.7, 0.7), 'source' => 'manual']);
    assert_same(2, count(DetectionReview::forCapture($cap)));
    DetectionReview::delete($id);
    assert_same(1, count(DetectionReview::forCapture($cap)));
    DetectionReview::setComplete($cap, true);
    assert_true(DetectionReview::isComplete($cap));
    DetectionReview::setComplete($cap, false);
    assert_true(!DetectionReview::isComplete($cap));
});

test('verifica: dati non validi rifiutati', function () {
    $cap = dq_capture();
    assert_throws(InvalidArgumentException::class, fn() => DetectionReview::save($cap, ['verdict' => 'forse', 'polygon' => dq_square(0.5, 0.5)]));
    assert_throws(InvalidArgumentException::class, fn() => DetectionReview::save($cap, ['verdict' => 'confirmed', 'class' => 'carro armato', 'polygon' => dq_square(0.5, 0.5)]));
    assert_throws(InvalidArgumentException::class, fn() => DetectionReview::save($cap, ['verdict' => 'confirmed', 'class' => 'plane', 'type_name' => 'Aereo inventato', 'polygon' => dq_square(0.5, 0.5)]));
    assert_throws(InvalidArgumentException::class, fn() => DetectionReview::save($cap, ['verdict' => 'rejected', 'polygon' => [[5, 5]]]));
});

test('qualità: precisione, classe sbagliata, sfuggiti solo sulle riprese complete', function () {
    $mk = fn($v, $class, $x, $y, $type = null, $source = 'detection') => ['verdict' => $v, 'class' => $class, 'type_name' => $type,
        'center_x' => $x, 'center_y' => $y, 'radius' => 0.02, 'source' => $source, 'polygon' => dq_square($x, $y)];
    $det = ['result' => ['objects' => [
        dq_object('plane', 0.2, 0.2, 0.9),        // confermato
        dq_object('plane', 0.4, 0.4, 0.35),       // falso
        dq_object('large vehicle', 0.6, 0.6),     // in realtà un elicottero
        dq_object('small vehicle', 0.8, 0.8),     // non verificato
    ], 'mpp' => ['mpp_x' => 0.4, 'mpp_y' => 0.4]]];
    $reviews = [
        $mk('confirmed', 'plane', 0.2, 0.2),
        $mk('rejected', null, 0.4, 0.4),
        $mk('confirmed', 'helicopter', 0.6, 0.6),
        $mk('confirmed', 'plane', 0.9, 0.1, null, 'manual'),   // sfuggito al rilevatore
    ];
    $partial = DetectionQuality::compute([7 => $reviews], [7 => $det], []);
    assert_same(1, $partial['classes']['plane']['tp']);
    assert_same(1, $partial['classes']['plane']['fp']);
    assert_same(0, $partial['classes']['plane']['fn'], 'senza verifica completa gli sfuggiti non contano');
    assert_same(1, $partial['classes']['vehicle']['wrong_class']);
    assert_same(0.5, $partial['classes']['plane']['precision']);
    assert_same(null, $partial['classes']['plane']['recall'], 'senza riprese complete il richiamo non si può dire');
    assert_same(1, $partial['captures'][0]['unreviewed']);
    assert_same(1, $partial['captures'][0]['missed']);
    // Fasce di confidenza: il falso a 0,35, il giusto a 0,9.
    assert_same(1, $partial['bands'][0]['fp']);
    assert_same(1, $partial['bands'][3]['tp']);

    $full = DetectionQuality::compute([7 => $reviews], [7 => $det], [7 => true]);
    assert_same(1, $full['classes']['plane']['fn']);
    assert_same(1, $full['classes']['helicopter']['fn']);
    assert_same(0.5, $full['classes']['plane']['recall']);
});

test('qualità: posizione del tipo vero dalla sagoma e dal riquadro', function () {
    $sil = ['quality' => 'ok', 'span_m' => 52.4, 'length_m' => 54.5];
    $det = ['result' => ['objects' => [dq_object('plane', 0.5, 0.5, 0.9, $sil, [63.9, 55.4])], 'mpp' => ['mpp_x' => 0.4, 'mpp_y' => 0.4]]];
    $reviews = [['verdict' => 'confirmed', 'class' => 'plane', 'type_name' => 'C-17 Globemaster III', 'center_x' => 0.5, 'center_y' => 0.5,
        'radius' => 0.02, 'source' => 'detection', 'polygon' => dq_square(0.5, 0.5)]];
    $q = DetectionQuality::compute([9 => $reviews], [9 => $det], []);
    assert_same(1, $q['identification']['sagoma']['n']);
    assert_same(1, $q['identification']['sagoma']['top3']);
    assert_same(1, $q['identification']['riquadro']['n']);
    assert_same(0, $q['identification']['riquadro']['top3']);
});

test('alert del cron: nessuna riga per riprese troppo poco dettagliate', function () {
    $coarse = ['id' => 1, 'study_id' => test_study(), 'width' => 100, 'height' => 100, 'meta_json' => json_encode(['bbox' => [10.0, 45.0, 10.1, 45.1]])];
    assert_same(null, ObjectChange::alertLine($coarse, $coarse, null));
});

test('verifica: un veicolo segnato sotto l\'ala non sostituisce la verifica dell\'aereo', function () {
    $cap = dq_capture();
    $plane = DetectionReview::save($cap, ['verdict' => 'confirmed', 'class' => 'plane', 'type_name' => 'A400M Atlas', 'polygon' => dq_square(0.5, 0.5, 0.025)]);
    $vehicle = DetectionReview::save($cap, ['verdict' => 'confirmed', 'class' => 'small vehicle', 'source' => 'manual', 'polygon' => dq_square(0.51, 0.505, 0.003)]);
    assert_true($plane !== $vehicle, 'la verifica dell\'aereo è stata sovrascritta');
    $rows = DetectionReview::forCapture($cap);
    assert_same(['plane', 'small vehicle'], array_column($rows, 'class'));
    // Il rilevamento dell'aereo si abbina alla sua verifica, non a quella del veicolo.
    $m = DetectionReview::match([['polygon' => dq_square(0.501, 0.5, 0.025)]], $rows);
    assert_same('plane', $rows[$m[0]]['class']);
});

test('verifica: riquadro oltre il bordo riportato nell\'immagine, input non testuali rifiutati', function () {
    $cap = dq_capture();
    $id = DetectionReview::save($cap, ['verdict' => 'rejected', 'polygon' => [[-0.15, 0.4], [0.05, 0.4], [0.05, 0.5], [-0.15, 0.5]]]);
    $row = DetectionReview::forCapture($cap)[0];
    assert_same($id, (int) $row['id']);
    assert_same(0.0, (float) $row['polygon'][0][0]);
    assert_throws(InvalidArgumentException::class, fn() => DetectionReview::save($cap, ['verdict' => 'confirmed', 'class' => ['plane'], 'polygon' => dq_square(0.5, 0.5)]));
    assert_throws(InvalidArgumentException::class, fn() => DetectionReview::save($cap, ['verdict' => ['rejected'], 'polygon' => dq_square(0.5, 0.5)]));
});
