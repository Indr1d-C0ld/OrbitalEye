<?php
require __DIR__ . '/../src/bootstrap.php';
Auth::requireLogin();

// Unico punto da cui i file dello storage raggiungono il browser. Lo
// storage NON è servito da Apache (storage/.htaccess: "Require all
// denied"), quindi qualunque controllo di accesso deve stare qui: un
// readfile() senza restrizioni scavalcherebbe completamente quel diniego.
//
// Due vincoli, entrambi necessari:
//  1) sottocartella in allowlist — servono solo le immagini di lavoro;
//     senza questo vincolo erano raggiungibili anche i file in
//     storage/config/ (credenziali OAuth Sentinel Hub/Esri scritte da
//     AppSettings::sync*CredentialsFile), che non devono mai uscire da qui.
//  2) estensione immagine in allowlist — evita di trasformare questo
//     endpoint in un lettore di file generico per qualunque cosa finisca
//     in quelle cartelle.
const MEDIA_ALLOWED_DIRS = ['raw', 'processed', 'results'];
const MEDIA_ALLOWED_TYPES = [
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
];

$relative = $_GET['path'] ?? '';
$relative = ltrim(str_replace('\\', '/', $relative), '/');

if ($relative === '' || strpos($relative, '..') !== false) {
    http_response_code(400);
    exit('Percorso non valido');
}

$topDir = explode('/', $relative)[0];
if (!in_array($topDir, MEDIA_ALLOWED_DIRS, true)) {
    http_response_code(404);
    exit('File non trovato');
}

$ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
if (!isset(MEDIA_ALLOWED_TYPES[$ext])) {
    http_response_code(404);
    exit('File non trovato');
}

$root = realpath(Config::storageRoot());
$full = realpath(Config::storageRoot() . '/' . $relative);

// Il confronto include lo slash finale: senza, una cartella "sorella" il cui
// nome inizia con quello dello storage root (es. .../storage_backup)
// passerebbe il controllo di prefisso.
if ($full === false || $root === false || strncmp($full, $root . '/', strlen($root) + 1) !== 0) {
    http_response_code(404);
    exit('File non trovato');
}

header('Content-Type: ' . MEDIA_ALLOWED_TYPES[$ext]);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . filesize($full));
readfile($full);
