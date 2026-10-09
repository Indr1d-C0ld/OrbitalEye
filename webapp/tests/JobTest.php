<?php
// Lavori in background: esecuzione, errori, processi spariti.

function test_insert_job(string $type, array $params, string $status = 'queued', string $created = "datetime('now')"): int
{
    $db = Database::get();
    $db->prepare("INSERT INTO jobs (type, study_id, params_json, status, created_at) VALUES (:t, :s, :p, :st, $created)")
        ->execute([':t' => $type, ':s' => test_study(), ':p' => json_encode($params), ':st' => $status]);
    return (int) $db->lastInsertId();
}

test('un lavoro che fallisce registra l\'errore', function () {
    $id = test_insert_job('detect', ['capture_id' => 999999]);
    Job::execute($id);
    $job = Job::find($id);
    assert_same('error', $job['status']);
    assert_same('Ripresa non trovata', $job['error']);
});

test('un lavoro già preso in carico non riparte', function () {
    $id = test_insert_job('detect', ['capture_id' => 999999]);
    Job::execute($id);
    $before = Job::find($id);
    Job::execute($id); // seconda volta: nessun effetto
    assert_same($before['finished_at'], Job::find($id)['finished_at']);
});

test('lavoro in esecuzione con processo sparito: segnato come interrotto', function () {
    $id = test_insert_job('compare', [], 'running');
    // PID quasi certamente inesistente.
    Database::get()->exec("UPDATE jobs SET pid = 4194000 WHERE id = $id");
    $job = Job::find($id);
    assert_same('error', $job['status']);
    assert_true(strpos($job['error'], 'interrotto') !== false);
});

test('lavoro mai partito da oltre due minuti: segnato come interrotto', function () {
    $id = test_insert_job('compare', [], 'queued', "datetime('now', '-5 minutes')");
    assert_same('error', Job::find($id)['status']);
    $fresh = test_insert_job('compare', []);
    assert_same('queued', Job::find($fresh)['status']);
});

test('PID riassegnato a un altro processo: il lavoro non resta in corso', function () {
    $id = test_insert_job('compare', [], 'running');
    // Il processo dei test esiste, ma non è cli/run_job.php per questo lavoro.
    Database::get()->exec('UPDATE jobs SET pid = ' . getmypid() . " WHERE id = $id");
    assert_same('error', Job::find($id)['status']);
});

test('lavoro in esecuzione da troppo tempo: segnato come interrotto', function () {
    $old = test_insert_job('compare', [], 'running');
    Database::get()->exec("UPDATE jobs SET started_at = datetime('now', '-30 minutes') WHERE id = $old");
    $job = Job::find($old);
    assert_same('error', $job['status']);
    assert_true(strpos($job['error'], 'minuti') !== false);
    // Eseguito dentro la richiesta (nessun PID) e appena partito: in corso.
    $fresh = test_insert_job('compare', [], 'running');
    Database::get()->exec("UPDATE jobs SET started_at = datetime('now') WHERE id = $fresh");
    assert_same('running', Job::find($fresh)['status']);
});

test('un lavoro finito mentre lo si controlla resta finito', function () {
    $id = test_insert_job('compare', [], 'running');
    Database::get()->exec("UPDATE jobs SET pid = 4194000 WHERE id = $id");
    $stale = Database::get()->query("SELECT * FROM jobs WHERE id = $id")->fetch();
    // Il processo scrive l'esito e termina tra la lettura e il controllo.
    Database::get()->exec("UPDATE jobs SET status = 'done', result_json = '{\"ok\":1}' WHERE id = $id");
    $check = new ReflectionMethod(Job::class, 'checkAlive');
    assert_same('done', $check->invoke(null, $stale)['status']);
    assert_same('done', Job::find($id)['status']);
});

test('tipi di lavoro previsti', function () {
    assert_same(['fetch', 'compare', 'detect', 'detail'], Job::types());
});
