<?php
/**
 * Test automatici del webapp, senza dipendenze esterne.
 *
 * Uso: php webapp/tests/run.php [filtro]
 *
 * Girano su un database e uno storage temporanei (nessun config.php
 * necessario, nessun servizio esterno contattato): ogni file *Test.php
 * registra i propri casi con test('nome', fn() => ...). Esce con codice 1
 * se un caso fallisce — è quello che usa la CI del repository.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
error_reporting(E_ALL);
$tmp = sys_get_temp_dir() . '/orbitaleye-tests-' . getmypid();
foreach (['storage/raw', 'storage/processed', 'storage/results', 'storage/publications', 'data'] as $d) {
    @mkdir("$tmp/$d", 0777, true);
}
// Prima del bootstrap: la sessione PHP da riga di comando non serve.
ini_set('session.save_path', $tmp);
define('ORBITALEYE_TESTS', true);
require __DIR__ . '/../src/bootstrap.php';
Config::set([
    'storage_root' => "$tmp/storage",
    'db_path' => "$tmp/data/test.sqlite",
    // Porta chiusa: nessun test deve contattare il servizio di analisi.
    'python_service_url' => 'http://127.0.0.1:9',
    'python_service_key' => 'test',
    'app_name' => 'TEST',
]);

final class AssertionFailed extends Exception
{
}

$tests = [];
function test(string $name, callable $fn): void
{
    $GLOBALS['tests'][] = [$name, $fn];
}
function assert_same($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(($msg ? "$msg: " : '') . 'atteso ' . var_export($expected, true) . ', ottenuto ' . var_export($actual, true));
    }
}
function assert_true($cond, string $msg = 'condizione falsa'): void
{
    if (!$cond) {
        throw new AssertionFailed($msg);
    }
}
function assert_near(float $expected, float $actual, float $tol, string $msg = ''): void
{
    if (abs($expected - $actual) > $tol) {
        throw new AssertionFailed(($msg ? "$msg: " : '') . "atteso $expected ± $tol, ottenuto $actual");
    }
}
function assert_throws(string $class, callable $fn, string $contains = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!$e instanceof $class) {
            throw new AssertionFailed("attesa $class, lanciata " . get_class($e) . ': ' . $e->getMessage());
        }
        if ($contains !== '' && strpos($e->getMessage(), $contains) === false) {
            throw new AssertionFailed("messaggio senza \"$contains\": " . $e->getMessage());
        }
        return;
    }
    throw new AssertionFailed("attesa eccezione $class, nessuna lanciata");
}

$filter = $argv[1] ?? '';
foreach (glob(__DIR__ . '/*Test.php') as $file) {
    require $file;
}
$failed = 0;
$run = 0;
foreach ($tests as [$name, $fn]) {
    if ($filter !== '' && stripos($name, $filter) === false) {
        continue;
    }
    $run++;
    try {
        $fn();
        echo "  ok   $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  FAIL $name\n       " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}
echo "\n$run test, " . ($run - $failed) . " superati, $failed falliti\n";
exec('rm -rf ' . escapeshellarg($tmp));
// Nessun test eseguito (filtro sbagliato, file non trovati) è un errore, non un successo.
exit($failed || $run === 0 ? 1 : 0);
