<?php
// Provenienza delle immagini: date, attribuzioni, stessa acquisizione.

$esri = fn(string $date, string $sensor, string $provider) => [
    'id' => random_int(1000, 9999), 'source' => 'esri', 'capture_date' => $date,
    'meta_json' => json_encode(['esri_imagery' => ['acquisitions' => [[
        'date' => $date, 'sensor' => $sensor, 'sensor_name' => EsriImageryMetadata::sensorName($sensor),
        'resolution_m' => 0.31, 'provider' => $provider, 'coverage' => 1.0,
    ]], 'dominant_date' => $date, 'date_min' => $date, 'date_max' => $date]]),
];

test('Esri: data reale, sensore e frase legale', function () use ($esri) {
    $i = ImageryAttribution::forCapture($esri('2024-01-27', 'WV03', 'Vantor'));
    assert_same('2024-01-27', $i['date']);
    assert_true($i['is_esri']);
    assert_true(strpos($i['caption_line'], 'intellectual property of Esri') !== false);
    assert_true(strpos($i['detail'], 'WorldView-3') !== false);
});

test('due riprese Esri: un solo "© Esri" e una sola frase legale', function () use ($esri) {
    $a = ImageryAttribution::forCapture($esri('2011-06-18', 'UC-G', 'Microsoft'));
    $b = ImageryAttribution::forCapture($esri('2024-01-27', 'WV03', 'Vantor'));
    $credit = ImageryAttribution::publicationCredit($a, $b);
    assert_same(0, strpos($credit, '© Esri, Microsoft, Vantor'));
    assert_same(1, substr_count($credit, 'intellectual property'));
});

test('codici sensore per banda tradotti (WV03_VNIR)', function () {
    assert_same('WorldView-3', EsriImageryMetadata::sensorName('WV03_VNIR'));
    assert_same('UltraCam G (aerea)', EsriImageryMetadata::sensorName('UC-G'));
});

test('stessa acquisizione Esri = stessa chiave; date diverse = chiavi diverse', function () use ($esri) {
    $k1 = ImageryAttribution::forCapture($esri('2024-01-27', 'WV03', 'Vantor'))['acquisition_key'];
    $k2 = ImageryAttribution::forCapture($esri('2024-01-27', 'WV03', 'Maxar'))['acquisition_key'];
    $k3 = ImageryAttribution::forCapture($esri('2022-02-18', 'WV03', 'Maxar'))['acquisition_key'];
    assert_same($k1, $k2, 'fornitore rinominato, stessa foto');
    assert_true($k1 !== $k3);
});

test('Sentinel-1: geometria di vista e attribuzione Copernicus', function () {
    $c = ['id' => 5, 'source' => 'sentinel1', 'capture_date' => '2026-10-06', 'meta_json' => json_encode(['s1_pass' => [
        'datetime' => '2026-10-06T16:55:40Z', 'platform' => 'Sentinel-1C', 'orbit_state' => 'ascending', 'relative_orbit' => 44,
    ]])];
    $i = ImageryAttribution::forCapture($c);
    assert_same('orbita 44 ascendente', $i['view_geometry']);
    assert_same('Contains modified Copernicus Sentinel data 2026', $i['credit']);
    assert_same('s1:2026-10-06T16:55:40Z', $i['acquisition_key']);
});

test('caricamento manuale senza fonte: nessuna chiave di acquisizione', function () {
    $i = ImageryAttribution::forCapture(['id' => 6, 'source' => 'upload', 'capture_date' => '2024-01-01', 'meta_json' => '{}']);
    assert_same(null, $i['acquisition_key']);
});
