<?php

/**
 * Pulizia dei file di lavoro rimasti orfani nello storage.
 *
 * Non tutto ciò che il servizio di analisi scrive su disco finisce per
 * essere referenziato da una riga del database, e questo è normale:
 *
 *  - ogni "Applica filtri avanzati" / "Applica e anteprima" produce
 *    un'anteprima in processed/, che diventa una ripresa vera solo se
 *    l'analista preme "Salva come nuova ripresa" (vedi
 *    api/enhance_capture.php, api/save_enhanced_capture.php);
 *  - lo stesso vale per gli indici spettrali (NDVI/NDWI/falso colore IR);
 *  - un confronto eseguito e non salvato lascia la sua cartella results/.
 *
 * Senza una pulizia periodica questi file restano per sempre, invisibili
 * all'applicazione: su questo deployment erano arrivati a costituire la
 * quasi totalità dello spazio occupato dallo storage.
 *
 * La regola è volutamente prudente: si elimina solo ciò che (a) non è
 * referenziato da nessuna riga e (b) è più vecchio di una soglia di grazia,
 * così un'anteprima appena generata e ancora aperta nel browser non sparisce
 * sotto i piedi dell'analista.
 */
final class StorageMaintenance
{
    /** Ore di grazia prima che un file non referenziato sia considerato abbandonato. */
    public const DEFAULT_GRACE_HOURS = 48;

    /**
     * @return array{processed:int, results:int, bytes:int} Quantità rimosse.
     */
    public static function cleanOrphans(int $graceHours = self::DEFAULT_GRACE_HOURS, bool $dryRun = false): array
    {
        $cutoff = time() - max(0, $graceHours) * 3600;
        $root = Config::storageRoot();
        $removed = ['processed' => 0, 'results' => 0, 'bytes' => 0];

        // --- processed/ : anteprime mai salvate come ripresa ---
        $referenced = [];
        foreach (Database::get()->query('SELECT relative_path FROM captures')->fetchAll() as $row) {
            $referenced[$row['relative_path']] = true;
        }

        $processedDir = $root . '/processed';
        foreach (glob($processedDir . '/*') ?: [] as $file) {
            if (!is_file($file) || basename($file) === '.gitkeep') {
                continue;
            }
            if (isset($referenced['processed/' . basename($file)])) {
                continue;
            }
            if (filemtime($file) > $cutoff) {
                continue;
            }
            $removed['bytes'] += (int) filesize($file);
            $removed['processed']++;
            if (!$dryRun) {
                @unlink($file);
            }
        }

        // --- results/ : confronti eseguiti e mai salvati ---
        $usedResultDirs = [];
        foreach (Database::get()->query('SELECT result_paths_json FROM comparisons')->fetchAll() as $row) {
            $paths = json_decode($row['result_paths_json'] ?? '', true);
            if (!is_array($paths)) {
                continue;
            }
            foreach ($paths as $relative) {
                // 'results/<id>/overlay.jpg' -> '<id>'
                $parts = explode('/', (string) $relative);
                if (count($parts) >= 2 && $parts[0] === 'results') {
                    $usedResultDirs[$parts[1]] = true;
                }
            }
        }

        $resultsDir = $root . '/results';
        foreach (glob($resultsDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $name = basename($dir);
            if (isset($usedResultDirs[$name]) || filemtime($dir) > $cutoff) {
                continue;
            }
            foreach (glob($dir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    $removed['bytes'] += (int) filesize($file);
                    if (!$dryRun) {
                        @unlink($file);
                    }
                }
            }
            $removed['results']++;
            if (!$dryRun) {
                @rmdir($dir);
            }
        }

        return $removed;
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 1) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        return round($bytes / 1024, 1) . ' KB';
    }
}
