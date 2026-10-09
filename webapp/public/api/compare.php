<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(['error' => 'Metodo non consentito'], 405);
}

// La logica sta in ComparisonRunner, condivisa con i lavori in background
// (vedi api/jobs.php, che la pagina studio usa per non restare bloccata).
try {
    respond_json(ComparisonRunner::run(json_body()));
} catch (ComparisonException $e) {
    respond_json(['error' => $e->getMessage()], $e->httpStatus);
}
