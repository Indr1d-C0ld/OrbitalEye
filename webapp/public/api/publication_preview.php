<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();
session_write_close();

/**
 * L'immagine principale di una pubblicazione, composta nel formato scelto
 * esattamente come partirebbe (stessi campi di api/share.php, vedi
 * PublicationBuilder): per l'anteprima e per la copia negli appunti da
 * incollare su X. Nessun invio, nessun registro.
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(['error' => 'Metodo non consentito'], 405);
}
$params = $_POST;
$params['album'] = '0';
$params['document'] = '0';
try {
    $built = PublicationBuilder::build($params, $_FILES['image'] ?? null);
} catch (PublicationException $e) {
    respond_json(['error' => $e->getMessage()], $e->httpStatus);
} catch (RuntimeException $e) {
    respond_json(['error' => $e->getMessage()], 400);
}
[$bytes, $name, $mime] = $built['media'][0];
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $name . '"');
header('Cache-Control: no-store');
echo $bytes;
