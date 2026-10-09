<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();
// Un rilevamento dura da pochi secondi a un paio di minuti: la sessione si
// chiude subito, così il resto dell'interfaccia non resta bloccato.
session_write_close();

/**
 * Rilevamento automatico di oggetti su una ripresa (vedi Detection.php e
 * python-service/app/core/detect.py).
 *  GET  ?capture_id=N           ultimo rilevamento della ripresa
 *  POST {capture_id, confidence, small_objects}  esegue e salva
 * Per ogni aereo/elicottero con scala nota: misure del riquadro in metri e
 * tipi compatibili dalla tabella locale (AircraftCatalog).
 */
$method = $_SERVER['REQUEST_METHOD'];
$body = $method === 'POST' ? json_body() : $_GET;
$captureId = (int) ($body['capture_id'] ?? 0);
$capture = $captureId ? Capture::find($captureId) : null;
if (!$capture) {
    respond_json(['error' => 'Ripresa non trovata'], 404);
}

if ($method === 'GET') {
    respond_json(['detection' => Detection::forCapture($captureId)]);
}
if ($method !== 'POST') {
    respond_json(['error' => 'Metodo non consentito'], 405);
}

// La logica sta in DetectionRunner, condivisa con i lavori in background.
try {
    respond_json(['detection' => DetectionRunner::run($body)]);
} catch (DetectionException $e) {
    respond_json(['error' => $e->getMessage()], $e->httpStatus);
}

