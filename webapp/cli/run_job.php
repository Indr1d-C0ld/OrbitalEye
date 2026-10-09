<?php
/**
 * Esegue un lavoro in background (vedi src/Job.php): avviato dal webserver,
 * uno per lavoro, con l'id come unico argomento. Non va lanciato a mano se
 * non per diagnosi: un lavoro già preso in carico non viene rieseguito.
 *
 * Uso: php run_job.php ID
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../src/bootstrap.php';

$id = (int) ($argv[1] ?? 0);
if ($id <= 0) {
    fwrite(STDERR, "Uso: php run_job.php ID\n");
    exit(1);
}
// Un lavoro può durare minuti (scaricamenti, rilevamento con oggetti piccoli).
set_time_limit(0);
Job::execute($id);
