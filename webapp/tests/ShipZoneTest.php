<?php
// Classi navali dalle misure dello scafo, categorie dei veicoli, zone di
// conteggio dello storico dell'area.

function sz_capture(array $bbox = [10.0, 45.0, 10.01, 45.008]): int
{
    return Capture::create(test_study(), 'Zone', 'esri', '2025-01-01', 'raw/inesistente.jpg', 1000, 800, ['bbox' => $bbox]);
}

test('navi: classi compatibili con lo scafo, la portaerei con il ponte di volo', function () {
    // Due Type 45 misurati a Portsmouth (152,4 × 21,2 m).
    $names = array_column(ShipCatalog::match(155.3, 21.5, 0.3, 'hull', 5), 'name');
    assert_true(in_array('Type 45', $names, true), 'Type 45 non fra i primi 5: ' . implode(', ', $names));
    // Il Ticonderoga (172,8 × 16,8) è più lungo di 17 m: escluso.
    assert_true(!in_array('Ticonderoga', $names, true));
    // Portaerei Ford misurata 340,7 × 80,8 m: si confronta il ponte di volo (78 m).
    $carrier = ShipCatalog::match(340.7, 80.8, 0.27, 'hull', 3);
    assert_true(in_array('Gerald R. Ford', array_column($carrier, 'name'), true), 'Ford non fra i primi 3');
    // Larghezza poco attendibile (scafo "media"): conta con una tolleranza
    // larga. Una larghezza sbagliata del 25% non esclude il Type 45; una da
    // sottomarino (11 m) sì.
    $len = array_column(ShipCatalog::match(155.3, 26.5, 0.3, 'hull_length', 15), 'name');
    assert_true(in_array('Type 45', $len, true), 'Type 45 escluso con larghezza indicativa: ' . implode(', ', $len));
    $sub = array_column(ShipCatalog::match(155.3, 11.0, 0.3, 'hull_length', 15), 'name');
    assert_true(!in_array('Type 45', $sub, true), 'Type 45 compatibile con una larghezza da sottomarino');
    assert_same([], ShipCatalog::match(null, 20.0, 0.3));
    // Ogni riga della tabella cita la voce e la revisione di Wikipedia.
    foreach (ShipCatalog::types() as $t) {
        assert_true(!empty($t['wiki']) && !empty($t['revision']), 'riga senza fonte: ' . $t['name']);
    }
    $names = ShipCatalog::names();
    assert_same(count($names), count(array_unique($names)));
});

test('navi: classe confermata solo se in tabella', function () {
    $cap = sz_capture();
    $poly = [[0.4, 0.4], [0.5, 0.4], [0.5, 0.42], [0.4, 0.42]];
    DetectionReview::save($cap, ['verdict' => 'confirmed', 'class' => 'ship', 'type_name' => 'Type 45', 'polygon' => $poly]);
    assert_same('Type 45', DetectionReview::forCapture($cap)[0]['type_name']);
    assert_throws(InvalidArgumentException::class, fn() => DetectionReview::save($cap, ['verdict' => 'confirmed', 'class' => 'ship', 'type_name' => 'Nave inventata', 'polygon' => $poly]));
    assert_throws(InvalidArgumentException::class, fn() => DetectionReview::save($cap, ['verdict' => 'confirmed', 'class' => 'plane', 'type_name' => 'Type 45', 'polygon' => $poly]));
    assert_true(isset(DetectionReview::typesByClass()['ship']));
});

test('veicoli: classe di lunghezza dal riquadro, solo su riprese dettagliate', function () {
    assert_same('piccolo', DetectionRunner::vehicleSize(5.0, 0.3)['key']);
    assert_same('medio', DetectionRunner::vehicleSize(8.0, 0.3)['key']);
    assert_same('grande', DetectionRunner::vehicleSize(13.0, 0.5)['key']);
    assert_same('molto grande', DetectionRunner::vehicleSize(18.0, 0.5)['key']);
    assert_same(null, DetectionRunner::vehicleSize(13.0, 1.0));
    assert_same(null, DetectionRunner::vehicleSize(13.0, null));
});

test('zone: dall\'annotazione alle coordinate, conteggi e copertura', function () {
    $cap = sz_capture();
    // Rettangolo sulla metà sinistra dell'immagine.
    $ann = Annotation::create(test_study(), $cap, null, 'capture', 'rect', ['x' => 0.0, 'y' => 0.0, 'w' => 0.5, 'h' => 1.0], '#00fff2', 'Piazzale ovest', null);
    $zid = StudyZone::fromAnnotation($ann);
    $zone = StudyZone::find($zid);
    assert_same('Piazzale ovest', $zone['name']);
    assert_near(10.0, $zone['polygon'][0][0], 1e-6);
    assert_near(10.005, $zone['polygon'][1][0], 1e-6);
    assert_true(StudyZone::contains($zone['polygon'], 10.002, 45.004));
    assert_true(!StudyZone::contains($zone['polygon'], 10.007, 45.004));
    $obj = fn(string $cls, float $x) => ['class' => $cls, 'center' => [$x, 0.5], 'polygon' => []];
    $det = ['result' => ['objects' => [$obj('plane', 0.1), $obj('plane', 0.3), $obj('small vehicle', 0.2), $obj('plane', 0.9), $obj('storage tank', 0.1)]]];
    $c = StudyZone::count([$zone], Capture::find($cap), $det)[$zid];
    assert_same('full', $c['coverage']);
    assert_same(2, $c['counts']['plane']);
    assert_same(1, $c['counts']['small vehicle']);
    assert_same(0, $c['counts']['ship']);
    // Un'altra ripresa che copre solo metà della zona, e una che non la copre.
    $half = sz_capture([10.0025, 45.0, 10.0125, 45.008]);
    assert_same('partial', StudyZone::count([$zone], Capture::find($half), $det)[$zid]['coverage']);
    $far = sz_capture([11.0, 46.0, 11.01, 46.008]);
    $none = StudyZone::count([$zone], Capture::find($far), $det)[$zid];
    assert_same('none', $none['coverage']);
    assert_same(null, $none['counts']);
    // Zona più grande della ripresa: nessun vertice dentro, ma la copre.
    $inner = sz_capture([10.001, 45.001, 10.002, 45.002]);
    assert_same('partial', StudyZone::count([$zone], Capture::find($inner), $det)[$zid]['coverage']);
    // Una linea non è una zona; una ripresa senza coordinate nemmeno.
    $line = Annotation::create(test_study(), $cap, null, 'capture', 'polyline', ['points' => [[0.1, 0.1], [0.2, 0.2]]], '#00fff2', 'Linea', null);
    assert_throws(InvalidArgumentException::class, fn() => StudyZone::fromAnnotation($line));
    $nogeo = Capture::create(test_study(), 'Senza coordinate', 'upload', '2025-01-01', 'raw/inesistente.jpg', 1000, 800, []);
    $ann2 = Annotation::create(test_study(), $nogeo, null, 'capture', 'rect', ['x' => 0.1, 'y' => 0.1, 'w' => 0.2, 'h' => 0.2], '#00fff2', 'X', null);
    assert_throws(InvalidArgumentException::class, fn() => StudyZone::fromAnnotation($ann2));
    StudyZone::delete($zid);
    assert_same(null, StudyZone::find($zid));
});

test('zone: colonne nel CSV dello storico, nomi trattati come testo', function () {
    $zone = ['id' => 7, 'name' => '=Banchina', 'polygon' => []];
    $row = ['date' => '2025-01-01', 'label' => 'R', 'source' => 'Esri', 'mpp' => 0.3, 'detected_at' => null, 'confidence' => 0.25,
        'counts' => array_fill_keys(array_keys(Detection::HISTORY_CLASSES), 1), 'types' => [],
        'zones' => [7 => ['counts' => array_fill_keys(array_keys(Detection::HISTORY_CLASSES), 2), 'coverage' => 'partial']]];
    $csv = AreaHistory::csv([$row], [$zone]);
    assert_true(str_contains($csv, "'=Banchina: navi"), 'nome della zona non protetto');
    assert_true(str_contains($csv, ';2;2;2;2;2;parziale'), 'conteggi della zona mancanti: ' . $csv);
});

test('qualità: posizione della classe vera fra quelle compatibili con lo scafo', function () {
    $poly = [[0.4, 0.4], [0.6, 0.4], [0.6, 0.43], [0.4, 0.43]];
    $det = ['result' => ['objects' => [['class' => 'ship', 'confidence' => 0.9, 'center' => [0.5, 0.415], 'polygon' => $poly,
        'size_m' => [158.0, 24.0], 'hull' => ['quality' => 'ok', 'length_m' => 152.0, 'beam_m' => 21.0]]], 'mpp' => ['mpp_x' => 0.3, 'mpp_y' => 0.3]]];
    $rev = ['verdict' => 'confirmed', 'class' => 'ship', 'type_name' => 'Type 45', 'center_x' => 0.5, 'center_y' => 0.415, 'radius' => 0.1,
        'source' => 'detection', 'polygon' => $poly];
    $q = DetectionQuality::compute([31 => [$rev]], [31 => $det], []);
    assert_same(1, $q['identification']['scafo']['n']);
    assert_same(1, $q['identification']['scafo']['top3']);
});

test('zone: banchina lunga che attraversa la ripresa, vertici sul bordo, doppioni', function () {
    $cap = sz_capture();
    $geo = Capture::resolveGeoRef(Capture::find($cap));
    // Striscia sottile che attraversa tutta la ripresa da ovest a est,
    // senza vertici dentro e lontana dal centro.
    $strip = ['id' => 1, 'polygon' => [[9.99, 45.001], [10.02, 45.001], [10.02, 45.0015], [9.99, 45.0015]]];
    assert_same('partial', StudyZone::coverage($strip, $geo));
    // Vertici esattamente sul bordo della ripresa (dopo gli arrotondamenti): intera.
    $ann = Annotation::create(test_study(), $cap, null, 'capture', 'rect', ['x' => 0.0, 'y' => 0.0, 'w' => 1.0, 'h' => 1.0], '#00fff2', 'Tutta', null);
    $zid = StudyZone::fromAnnotation($ann);
    assert_same('full', StudyZone::coverage(StudyZone::find($zid), $geo));
    // ▦ premuto due volte: la stessa zona.
    assert_same($zid, StudyZone::fromAnnotation($ann));
    // Poligono rovinato nel database: nessun conteggio, nessun avviso.
    Database::get()->prepare('UPDATE study_zones SET polygon_json = :p WHERE id = :id')->execute([':p' => '[[1,2],"x"]', ':id' => $zid]);
    $zone = StudyZone::find($zid);
    assert_same([], $zone['polygon']);
    assert_same('none', StudyZone::coverage($zone, $geo));
});

test('navi: una larghezza perfetta non salva una lunghezza molto diversa', function () {
    // 125 m con la larghezza esatta del Type 45 (152,4 × 21,2): escluso.
    assert_true(!in_array('Type 45', array_column(ShipCatalog::match(125.0, 21.2, 0.3, 'hull', 50), 'name'), true));
});

test('verifica: messaggio chiaro per un tipo su una classe che non ne ha', function () {
    $cap = sz_capture();
    try {
        DetectionReview::save($cap, ['verdict' => 'confirmed', 'class' => 'small vehicle', 'type_name' => 'T-72', 'polygon' => [[0.1, 0.1], [0.11, 0.1], [0.11, 0.11], [0.1, 0.11]]]);
        assert_true(false, 'doveva essere rifiutata');
    } catch (InvalidArgumentException $e) {
        assert_same('Nessun tipo per la classe "veicolo piccolo"', $e->getMessage());
    }
});

