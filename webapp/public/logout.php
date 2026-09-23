<?php
require __DIR__ . '/../src/bootstrap.php';
// Solo via POST (vedi il modulo in partials/nav.php): un link GET permetteva
// a qualunque altro sito di disconnettere l'analista.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
Auth::logout();
header('Location: login.php');
