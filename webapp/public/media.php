<?php
require __DIR__ . '/../src/bootstrap.php';
Auth::requireLogin();

$relative = $_GET['path'] ?? '';
$relative = ltrim(str_replace('\\', '/', $relative), '/');

if ($relative === '' || strpos($relative, '..') !== false) {
    http_response_code(400);
    exit('Percorso non valido');
}

$root = realpath(Config::storageRoot());
$full = realpath(Config::storageRoot() . '/' . $relative);

if ($full === false || $root === false || strncmp($full, $root, strlen($root)) !== 0) {
    http_response_code(404);
    exit('File non trovato');
}

$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
$mime = [
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
][$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . filesize($full));
readfile($full);
