<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(['error' => 'Metodo non consentito'], 405);
}

$body = json_body();
$id = (int) ($body['comparison_id'] ?? 0);
$comparison = $id ? Comparison::find($id) : null;
if (!$comparison) {
    respond_json(['error' => 'Confronto non trovato'], 404);
}

$title = array_key_exists('title', $body) ? trim((string) $body['title']) : null;
Comparison::saveToLibrary($id, !isset($body['saved']) || !empty($body['saved']), $title);

respond_json(['ok' => true]);
