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
