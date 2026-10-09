<?php

/**
 * Lavori in background con avanzamento (vedi migrations/001_jobs.sql).
 *
 * Scaricamenti, confronti e rilevamenti duravano fino a qualche minuto
 * dentro una sola richiesta: la pagina restava ferma senza dire a che punto
 * fosse, e cambiando pagina non si sapeva più se il lavoro fosse finito.
 * Ora la richiesta registra il lavoro e avvia un processo PHP separato
 * (cli/run_job.php) che lo esegue e aggiorna avanzamento e messaggio; la
 * pagina li legge (api/jobs.php) e, se la si riapre, ritrova i lavori in
 * corso dello studio.
 *
 * Nessun demone né coda esterna: un processo per lavoro, avviato dal
 * webserver. Se l'avvio in background non è possibile (exec disabilitato)
 * il lavoro viene eseguito dentro la richiesta, come prima.
 */
final class Job
{
    /** Tipi di lavoro: nome => funzione che lo esegue, fn(array $params, callable $progress): mixed. */
    private static function handlers(): array
    {
        return [
            'fetch' => fn(array $p, callable $progress) => CaptureFetcher::fetchAndSave($p, null, $progress),
            'compare' => fn(array $p, callable $progress) => ComparisonRunner::run($p, $progress),
            'detect' => fn(array $p, callable $progress) => ['detection' => DetectionRunner::run($p, $progress)],
            'detail' => fn(array $p, callable $progress) => DetailFetcher::run($p, $progress),
        ];
    }

    public static function types(): array
    {
        return array_keys(self::handlers());
    }

    /** Registra il lavoro e lo avvia. @return int id */
    public static function start(string $type, ?int $studyId, array $params): int
    {
        if (!isset(self::handlers()[$type])) {
            throw new InvalidArgumentException('Tipo di lavoro non valido');
        }
        $db = Database::get();
        $db->prepare('INSERT INTO jobs (type, study_id, params_json, message) VALUES (:t, :s, :p, :m)')
            ->execute([':t' => $type, ':s' => $studyId, ':p' => json_encode($params), ':m' => 'In coda…']);
        $id = (int) $db->lastInsertId();

        if (!self::spawn($id)) {
            @set_time_limit(0);
            self::execute($id);
        }
        return $id;
    }

    /**
     * Avvia cli/run_job.php staccato dalla richiesta. Con setsid il processo
     * ha una sessione propria: non riceve i segnali rivolti al gruppo del
     * processo Apache che lo ha lanciato (un riavvio "graceful" non lo
     * tocca). L'uscita d'errore va in jobs.log accanto al database, per
     * capire perché un processo non è partito. @return bool avviato
     */
    private static function spawn(int $id): bool
    {
        $php = PHP_BINDIR . '/php';
        $script = realpath(__DIR__ . '/../cli/run_job.php');
        if (!function_exists('exec') || !is_executable($php) || !$script) {
            return false;
        }
        $log = dirname(Config::get()['db_path']) . '/jobs.log';
        $cmd = sprintf('%s %s %d < /dev/null >> %s 2>&1 & echo $!',
            is_executable('/usr/bin/setsid') ? '/usr/bin/setsid ' . escapeshellarg($php) : escapeshellarg($php),
            escapeshellarg($script), $id, escapeshellarg($log));
        $out = [];
        @exec($cmd, $out, $code);
        // Il codice d'uscita di una riga che termina con & è sempre 0:
        // conta il PID restituito, e che il processo esista ancora un
        // istante dopo (o abbia già preso in carico il lavoro).
        $pid = (int) trim($out[0] ?? '');
        if ($code !== 0 || $pid <= 0) {
            return false;
        }
        // Il processo può essere ancora la shell che sta per avviare PHP:
        // si riprova per un secondo al massimo prima di concludere che non
        // è partito.
        for ($i = 0; $i < 10; $i++) {
            usleep(100000);
            $job = self::row($id);
            if (!$job) {
                return false;
            }
            if ($job['status'] !== 'queued' || self::processAlive($pid, $id)) {
                return true;
            }
            if (!is_dir("/proc/$pid") && !(function_exists('posix_kill') && posix_kill($pid, 0))) {
                break; // il processo non esiste più
            }
        }
        return (self::row($id)['status'] ?? 'queued') !== 'queued';
    }

    /** Esegue un lavoro in coda (dal processo di cli/run_job.php). */
    public static function execute(int $id): void
    {
        $db = Database::get();
        // Presa in carico atomica: un lavoro parte una volta sola.
        $stmt = $db->prepare("UPDATE jobs SET status = 'running', pid = :pid, started_at = datetime('now'), message = :m WHERE id = :id AND status = 'queued'");
        // Il PID solo per il processo dedicato: eseguito dentro la richiesta
        // (ripiego) il processo è quello del webserver, che non va confuso
        // con un lavoro (vedi processAlive); vale allora solo MAX_RUNNING.
        $stmt->execute([':pid' => PHP_SAPI === 'cli' ? getmypid() : null, ':m' => 'Avvio…', ':id' => $id]);
        if ($stmt->rowCount() !== 1) {
            return;
        }
        $job = self::decode(self::row($id));
        $handler = self::handlers()[$job['type']] ?? null;
        $progress = function (int $percent, string $message) use ($id): void {
            Database::get()->prepare('UPDATE jobs SET progress = :p, message = :m WHERE id = :id')
                ->execute([':p' => max(0, min(100, $percent)), ':m' => $message, ':id' => $id]);
        };
        try {
            if (!$handler) {
                throw new RuntimeException('Tipo di lavoro sconosciuto');
            }
            $result = $handler($job['params'], $progress);
            // Solo se ancora "in esecuzione": un lavoro già dichiarato
            // scaduto (MAX_RUNNING) resta tale per chi lo ha visto fallire.
            $db->prepare("UPDATE jobs SET status = 'done', progress = 100, message = 'Completato.', result_json = :r, finished_at = datetime('now') WHERE id = :id AND status = 'running'")
                ->execute([':r' => json_encode($result), ':id' => $id]);
        } catch (Throwable $e) {
            $db->prepare("UPDATE jobs SET status = 'error', error = :e, message = 'Non riuscito.', finished_at = datetime('now') WHERE id = :id AND status = 'running'")
                ->execute([':e' => $e->getMessage(), ':id' => $id]);
        }
    }

    public static function find(int $id): ?array
    {
        $row = self::row($id);
        return $row ? self::decode(self::checkAlive($row)) : null;
    }

    private static function row(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Lavori non ancora finiti di uno studio (per riprenderne il segno riaprendo la pagina). */
    public static function activeForStudy(int $studyId): array
    {
        $stmt = Database::get()->prepare("SELECT * FROM jobs WHERE study_id = :s AND status IN ('queued', 'running') ORDER BY id");
        $stmt->execute([':s' => $studyId]);
        return array_values(array_filter(array_map(
            fn($r) => self::decode(self::checkAlive($r)),
            $stmt->fetchAll()
        ), fn($j) => in_array($j['status'], ['queued', 'running'], true)));
    }

    /**
     * Pulizia: lavori conclusi da più di un giorno (il risultato vero —
     * ripresa, confronto, rilevamento — è già salvato altrove).
     */
    public static function purgeOld(): int
    {
        return Database::get()->exec("DELETE FROM jobs WHERE status IN ('done', 'error') AND finished_at < datetime('now', '-1 day')");
    }

    /** Durata massima di un lavoro: oltre, è bloccato (un servizio che non risponde). */
    private const MAX_RUNNING = 1200;

    /**
     * Un lavoro "in esecuzione" il cui processo non esiste più (server
     * riavviato, processo interrotto) non finirebbe mai: va segnato come
     * interrotto. Così anche un lavoro in esecuzione da oltre MAX_RUNNING
     * secondi e uno rimasto in coda per oltre due minuti (processo mai
     * partito).
     */
    private static function checkAlive(array $row): array
    {
        $error = null;
        if ($row['status'] === 'running') {
            if (!empty($row['pid']) && !self::processAlive((int) $row['pid'], (int) $row['id'])) {
                $error = 'Lavoro interrotto (processo terminato prima della fine): riprova.';
            } elseif (!empty($row['started_at']) && strtotime($row['started_at'] . ' UTC') < time() - self::MAX_RUNNING) {
                $error = 'Lavoro interrotto: durava da oltre ' . intdiv(self::MAX_RUNNING, 60) . ' minuti. Riprova.';
            }
        } elseif ($row['status'] === 'queued' && strtotime($row['created_at'] . ' UTC') < time() - 120) {
            $error = 'Lavoro mai partito: riprova.';
        }
        if ($error === null) {
            return $row;
        }
        $stmt = Database::get()->prepare("UPDATE jobs SET status = 'error', error = :e, message = 'Non riuscito.', finished_at = datetime('now') WHERE id = :id AND status = :st");
        $stmt->execute([':e' => $error, ':id' => $row['id'], ':st' => $row['status']]);
        // Nessuna riga cambiata: il lavoro è finito nel frattempo (il
        // processo è terminato DOPO aver scritto l'esito). Vale quello.
        return $stmt->rowCount() === 1 ? self::row((int) $row['id']) : (self::row((int) $row['id']) ?? $row);
    }

    /**
     * Il processo $pid è ancora il lavoro $id? Il solo PID non basta: dopo
     * la fine del lavoro il sistema può riassegnarlo a un altro processo, e
     * il lavoro risulterebbe in corso per sempre. Dove /proc è leggibile si
     * controlla la riga di comando.
     */
    private static function processAlive(int $pid, int $id): bool
    {
        $cmdline = @file_get_contents("/proc/$pid/cmdline");
        if ($cmdline !== false) {
            $args = explode("\0", rtrim($cmdline, "\0"));
            return in_array((string) $id, $args, true)
                && (bool) array_filter($args, fn($a) => str_ends_with($a, 'run_job.php'));
        }
        if (function_exists('posix_kill')) {
            // Segnale 0: solo verifica. EPERM vuol dire che esiste ma è di
            // un altro utente.
            return posix_kill($pid, 0) || posix_get_last_error() === 1;
        }
        return false;
    }

    private static function decode(array $row): array
    {
        $row['params'] = json_decode($row['params_json'] ?? '[]', true) ?: [];
        $row['result'] = $row['result_json'] !== null ? json_decode($row['result_json'], true) : null;
        unset($row['params_json'], $row['result_json']);
        return $row;
    }
}
