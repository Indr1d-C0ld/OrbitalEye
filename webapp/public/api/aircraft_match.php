<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();
session_write_close();

/**
 * Tipi di velivolo compatibili con le misure fatte a mano (vedi
 * AircraftCatalog). GET span, length (metri; almeno uno), mpp (metri/pixel
 * della ripresa, per l'incertezza), filter (categoria, 'ala_fissa' o
 * 'elicottero'). Tutto locale: nessuna richiesta esterna.
 */
$num = function (string $k): ?float {
    $v = $_GET[$k] ?? '';
    $v = is_string($v) ? str_replace(',', '.', trim($v)) : '';
    return is_numeric($v) && (float) $v > 0 ? (float) $v : null;
};
$span = $num('span');
$length = $num('length');
if ($span === null && $length === null) {
    respond_json(['error' => 'Indica almeno una misura (apertura alare / rotore o lunghezza).'], 400);
}
if (($span !== null && $span > 120) || ($length !== null && $length > 120)) {
    respond_json(['error' => 'Misura fuori scala: nessun velivolo supera i 90 m.'], 400);
}
$filter = (string) ($_GET['filter'] ?? '');
$allowed = array_merge(['ala_fissa', 'elicottero'], AircraftCatalog::categories());
$wing = (string) ($_GET['wing'] ?? '');
respond_json([
    'candidates' => AircraftCatalog::match($span, $length, $num('mpp'), 'measure', in_array($filter, $allowed, true) ? $filter : null, 12,
        in_array($wing, AircraftCatalog::wings(), true) ? $wing : null),
    'types' => count(AircraftCatalog::types()),
]);
