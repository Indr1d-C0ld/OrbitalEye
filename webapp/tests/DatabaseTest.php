<?php
// Schema e migrazioni su un database nuovo (quello temporaneo dei test).

test('database nuovo: schema e tutte le migrazioni applicate', function () {
    $db = Database::get();
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['studies', 'captures', 'detections', 'publications', 'jobs', 'schema_migrations'] as $t) {
        assert_true(in_array($t, $tables, true), "manca la tabella $t");
    }
    $applied = $db->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(PDO::FETCH_COLUMN);
    $files = array_map(fn($f) => basename($f, '.sql'), glob(__DIR__ . '/../migrations/[0-9][0-9][0-9]_*.sql'));
    sort($files);
    assert_same($files, $applied);
});

test('le migrazioni hanno nomi numerati univoci', function () {
    $numbers = array_map(fn($f) => substr(basename($f), 0, 3), glob(__DIR__ . '/../migrations/*.sql'));
    assert_same(count($numbers), count(array_unique($numbers)));
    foreach (glob(__DIR__ . '/../migrations/*.sql') as $f) {
        assert_true((bool) preg_match('/^\d{3}_[a-z0-9_]+\.sql$/', basename($f)), basename($f) . ': nome non valido');
    }
});

test('migrazioni confrontate per nome: una registrata ma non più presente non ne nasconde una nuova', function () {
    $db = Database::get();
    // Come se 001 non fosse ancora applicata e al suo posto ci fosse una
    // migrazione registrata e poi rinominata: stesso numero di righe e file.
    $db->exec("DELETE FROM schema_migrations WHERE version = '001_jobs'");
    $db->exec("INSERT INTO schema_migrations (version) VALUES ('000_rinominata')");
    (new ReflectionMethod(Database::class, 'migrate'))->invoke(null);
    $applied = $db->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    assert_true(in_array('001_jobs', $applied, true), '001_jobs non riapplicata');
    $db->exec("DELETE FROM schema_migrations WHERE version = '000_rinominata'");
});

// Dati di prova condivisi dagli altri test.
function test_study(): int
{
    static $id = null;
    if ($id === null) {
        $id = Study::create('Studio di prova', 'Area', null, '');
    }
    return $id;
}
