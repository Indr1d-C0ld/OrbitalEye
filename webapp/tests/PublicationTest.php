<?php
// Composizione delle immagini da pubblicare e coda di revisione.

function test_jpeg(int $w, int $h): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 90, 120, 80));
    ob_start();
    imagejpeg($img, null, 85);
    return ob_get_clean();
}

test('scheda: larghezza minima, fascia in basso, JPEG', function () {
    if (!PublicationComposer::available()) {
        echo "       (saltato: GD/FreeType o font DejaVu non disponibili)\n";
        return;
    }
    [$bytes, $mime] = PublicationComposer::card(test_jpeg(400, 300), ['title' => 'Prova', 'line' => 'Immagine del 01/01/2024', 'credit' => '© Esri', 'mpp_x' => 1.0, 'north_deg' => -20]);
    $size = getimagesizefromstring($bytes);
    assert_same('image/jpeg', $mime);
    assert_same(1000, $size[0]);
    assert_true($size[1] > 750, 'manca la fascia sotto l\'immagine');
});

test('scheda: ritaglio stretto e alto non viene ingrandito a dismisura', function () {
    if (!PublicationComposer::available()) {
        return;
    }
    [$bytes] = PublicationComposer::card(test_jpeg(40, 900), ['title' => str_repeat('parola_lunghissima_', 8)]);
    $size = getimagesizefromstring($bytes);
    assert_true($size[0] + $size[1] <= 10000, 'oltre il limite di Telegram');
    assert_true($size[1] / $size[0] <= 20, 'proporzioni oltre 20:1');
});

test('scheda affiancata Prima/Dopo', function () {
    if (!PublicationComposer::available()) {
        return;
    }
    [$bytes] = PublicationComposer::pair(test_jpeg(600, 600), test_jpeg(600, 600), ['label_a' => 'PRIMA', 'label_b' => 'DOPO']);
    assert_same(1206, getimagesizefromstring($bytes)[0]);
});

test('immagine troppo grande rifiutata con un messaggio', function () {
    if (!PublicationComposer::available()) {
        return;
    }
    // Solo l'intestazione conta: un PNG 8000×6000 a colore unico è piccolo.
    $img = imagecreatetruecolor(8000, 6000);
    ob_start();
    imagepng($img, null, 9);
    $png = ob_get_clean();
    assert_throws(RuntimeException::class, fn() => PublicationComposer::card($png, []), 'troppo grande');
});

test('lunghezza delle didascalie come la conta Telegram', function () {
    assert_same(5, TelegramClient::textLength('ciaoè'));
    assert_same(2, TelegramClient::textLength('🛰'));
});

test('coda di revisione: presa in carico una sola volta, scarto con eliminazione dei file', function () {
    $id = Publication::enqueue([
        'study_id' => test_study(), 'kind' => 'study', 'ref_id' => test_study(), 'summary' => 'Prova',
        'caption' => 'Didascalia', 'format' => 'card',
        'media' => [[test_jpeg(10, 10), 'a.jpg', 'image/jpeg']],
        'document' => [test_jpeg(10, 10), 'a_doc.jpg', 'image/jpeg'],
    ]);
    $pub = Publication::find($id);
    $path = Config::storageRoot() . '/' . $pub['media'][0]['path'];
    assert_true(is_file($path));
    assert_same($pub['media'][0]['path'], $pub['document']['path'], 'stesso contenuto, un solo file');
    assert_true(Publication::claim($id));
    assert_true(!Publication::claim($id), 'seconda presa in carico');
    Publication::release($id);
    assert_true(Publication::claim($id), 'dopo il rilascio');
    Publication::markRejected($id);
    assert_same('rejected', Publication::find($id)['status']);
    assert_true(!is_file($path), 'file non eliminato');
});

test('presa in carico rimasta a metà: ripresa dopo dieci minuti', function () {
    $id = Publication::enqueue([
        'study_id' => test_study(), 'kind' => 'study', 'ref_id' => null, 'summary' => '', 'caption' => '', 'format' => 'none',
        'media' => [[test_jpeg(10, 10), 'b.jpg', 'image/jpeg']], 'document' => null,
    ]);
    assert_true(Publication::claim($id));
    Database::get()->exec("UPDATE publications SET decided_at = datetime('now', '-11 minutes') WHERE id = $id");
    assert_true(Publication::claim($id));
});

// ---- Firma OrbitalEye e riquadro dei dati tecnici ----

function pd_rows_map(array $rows): array
{
    $out = [];
    foreach ($rows as [$label, $value]) {
        $out[$label] = $value;
    }
    return $out;
}

/** Ripresa Esri di prova, 1000×800 su ~0,9 × 0,9 km. */
function pd_capture(): int
{
    return Capture::create(test_study(), 'Base', 'esri', '2024-01-27', 'raw/inesistente.jpg', 1000, 800, [
        'bbox' => [14.90, 37.40, 14.91, 37.408],
        'fetched_at' => '2026-10-14T22:59:14+02:00',
        'wayback' => ['release_date' => '2024-03-07'],
        'esri_imagery' => ['acquisitions' => [['date' => '2024-01-27', 'sensor' => 'WV03', 'resolution_m' => 0.31,
            'provider' => 'Vantor', 'product' => 'Vivid Advanced', 'coverage' => 1]],
            'date_min' => '2024-01-27', 'date_max' => '2024-01-27'],
    ]);
}

test('firma e riquadro: fascia sotto l\'immagine, che resta intatta', function () {
    if (!PublicationComposer::available()) {
        return;
    }
    $rows = [['Fonte', 'Esri World Imagery'], ['Centro (lat, lon)', '37.40400, 14.90500'], ['Acquisizione', '27/01/2024']];
    [$bytes, $mime] = PublicationComposer::decorate(test_jpeg(400, 300), 'image/jpeg', $rows, true);
    $size = getimagesizefromstring($bytes);
    assert_same('image/jpeg', $mime);
    // Più stretta della larghezza minima: al centro di una fascia larga 1000.
    assert_same(1000, $size[0]);
    assert_true($size[1] > 360, 'manca il riquadro');
    $img = imagecreatefromstring($bytes);
    $c = imagecolorsforindex($img, imagecolorat($img, 500, 150));
    assert_near(90, $c['red'], 6, 'immagine alterata');
    // Solo la firma: stessa larghezza, una riga in più.
    [$bytes] = PublicationComposer::decorate(test_jpeg(1200, 600), 'image/jpeg', [], true);
    $size = getimagesizefromstring($bytes);
    assert_same(1200, $size[0]);
    assert_true($size[1] > 600 && $size[1] < 660, 'fascia della firma: ' . $size[1]);
    // Nulla da aggiungere: gli stessi byte.
    $orig = test_jpeg(300, 200);
    assert_same($orig, PublicationComposer::decorate($orig, 'image/jpeg', [], false)[0]);
    // "Solo immagine": la firma nell'angolo, dimensioni invariate.
    assert_same([800, 500], array_slice(getimagesizefromstring(PublicationComposer::cornerTag(test_jpeg(800, 500))[0]), 0, 2));
});

test('riquadro: provenienza, area del ritaglio, elaborazioni e oggetti nel ritaglio', function () {
    $cap = pd_capture();
    $obj = fn(string $cls, float $x, float $y) => ['class' => $cls, 'center' => [$x, $y], 'polygon' => []];
    Detection::save($cap, test_study(), 'yolo11m-obb', 0.25, false, 0.9, ['objects' => [
        $obj('plane', 0.1, 0.1), $obj('plane', 0.2, 0.3), $obj('ship', 0.4, 0.2), $obj('plane', 0.8, 0.8), $obj('storage tank', 0.1, 0.2),
    ]], []);
    $crop = PublicationData::crop(['crop_x' => '0', 'crop_y' => '0', 'crop_w' => '0.5', 'crop_h' => '0.5']);
    $proc = PublicationData::processing(json_encode(['adjust' => ['brightness' => 100, 'contrast' => 130, 'gamma' => 1],
        'steps' => ['clahe', '<script>', 'rm -rf'], 'layer' => true, 'overlay' => false]));
    $r = pd_rows_map(PublicationData::forCapture(Capture::find($cap), $crop, $proc));
    assert_same('Esri Wayback (versione del 07/03/2024)', $r['Fonte']);
    assert_same('WorldView-3 · Vantor · Vivid Advanced', $r['Sensore']);
    assert_same('27/01/2024', $r['Acquisizione']);
    assert_same('14/10/2026', $r['Scaricata']);
    // Centro e box del solo quarto in alto a sinistra.
    assert_same('37.40600, 14.90250', $r['Centro (lat, lon)']);
    assert_same('37.40400, 14.90000 · 37.40800, 14.90500', $r['Box (SO · NE)']);
    assert_true(str_contains($r['Risoluzione'], '0,31 m (sensore)'), $r['Risoluzione']);
    assert_true(preg_match('/^\d{3} m × \d{3} m$/u', $r['Estensione']) === 1, $r['Estensione']);
    assert_same('ritaglio, contrasto locale (CLAHE), contrasto 130%, annotazioni e misure dell\'analista', $r['Elaborazione']);
    assert_same('2 aerei, 1 nave · automatico, YOLO11m-OBB, soglia 0,25, da verificare', $r['Rilevamento']);
    assert_true(!isset($r['Orientamento']), 'ripresa non ruotata');
    // Immagine intera, senza regolazioni.
    $full = pd_rows_map(PublicationData::forCapture(Capture::find($cap), null, PublicationData::processing(null)));
    assert_same('nessuna', $full['Elaborazione']);
    assert_true(str_starts_with($full['Rilevamento'], '3 aerei, 1 nave'), $full['Rilevamento']);
    // Ritaglio fuori dall'immagine: ignorato.
    assert_same(null, PublicationData::crop(['crop_x' => '0.8', 'crop_y' => '0', 'crop_w' => '0.5', 'crop_h' => '0.5']));
});

test('riquadro di un confronto: date, allineamento, metodo, variazione, ruotata', function () {
    $sid = test_study();
    $a = pd_capture();
    $b = Capture::create($sid, 'Dopo', 'sentinelhub', '2026-10-01', 'raw/x.jpg', 1000, 800, [
        'bbox' => [14.90, 37.40, 14.91, 37.408], 'rotation' => 30, 'rotation_model' => ImageRotateCrop::ROTATION_MODEL,
        's2_pass' => ['datetime' => '2026-10-01T09:50:31Z', 'platform' => 'Sentinel-2B', 'relative_orbit' => 79, 'aoi_cloud' => 0.02],
    ]);
    $cmp = ['params_json' => json_encode(['diff_method' => 'robust', 'analysis_scale_m' => 2, 'enhance_a' => [['filter' => 'clahe', 'params' => []]]]),
        'stats_json' => json_encode(['changed_ratio' => 0.0321, 'changed_area_m2' => 12400.4]),
        'registration_json' => json_encode(['method' => 'geo+ecc']), 'objects_json' => null];
    $r = pd_rows_map(PublicationData::forComparison($cmp, Capture::find($a), Capture::find($b), 'heatmap'));
    assert_same('27/01/2024 · WorldView-3 · Esri Wayback (versione del 07/03/2024)', $r['Prima']);
    assert_same('01/10/2026 09:50 UTC · Sentinel-2B · Copernicus Sentinel-2', $r['Dopo']);
    assert_same('mappa di calore', $r['Vista']);
    assert_same('dalle coordinate, rifinito sulle immagini', $r['Allineamento']);
    assert_same('robusto, celle di 2,0 m', $r['Confronto']);
    assert_same('3,2% dell\'area · 12.400 m²', $r['Variazione']);
    assert_same('contrasto locale (CLAHE)', $r['Pre-elaborazione']);
    assert_true(isset($r['Centro (lat, lon)'], $r['Scala']));
    // Provenienza Sentinel-2 e orientamento di una ripresa ruotata.
    $s = pd_rows_map(PublicationData::forCapture(Capture::find($b), null, PublicationData::processing(null)));
    assert_same('relativa 79', $s['Orbita']);
    assert_same('2% sull\'area', $s['Nuvole']);
    assert_same('Sentinel-2B · MSI L2A', $s['Sensore']);
    assert_same('nord a 330,0° dall\'alto, senso orario', $s['Orientamento']);
});

test('riquadro: valori delle regolazioni limitati, solo voci note', function () {
    $p = PublicationData::processing('{"adjust":{"brightness":99999,"contrast":"x","nota":5},"steps":"clahe","layer":1}');
    assert_same(['brightness' => 400.0], $p['adjust']);
    assert_same([], $p['steps']);
    assert_true($p['layer']);
    assert_same(['adjust' => [], 'steps' => [], 'layer' => false, 'overlay' => false], PublicationData::processing('non json'));
});

test('pubblicazione: firma attiva se non indicata, riquadro solo se chiesto', function () {
    if (!PublicationComposer::available()) {
        return;
    }
    $cap = pd_capture();
    $file = tempnam(sys_get_temp_dir(), 'oe');
    file_put_contents($file, $orig = test_jpeg(1000, 800));
    $upload = ['error' => UPLOAD_ERR_OK, 'size' => filesize($file), 'tmp_name' => $file];
    $build = fn(array $extra) => PublicationBuilder::build(['kind' => 'capture', 'ref_id' => $cap, 'format' => 'none'] + $extra, $upload)['media'][0][0];
    $signed = $build([]);
    assert_true($signed !== $orig, 'firma assente di serie');
    assert_same([1000, 800], array_slice(getimagesizefromstring($signed), 0, 2));
    assert_same($orig, $build(['signature' => '0']));
    $withData = getimagesizefromstring($build(['signature' => '0', 'telemetry' => '1']));
    assert_same(1000, $withData[0]);
    assert_true($withData[1] > 900, 'riquadro dei dati tecnici assente');
    unlink($file);
});
