<?php

/**
 * Scaricamento automatico ricorrente per una data area/fonte di uno studio
 * (vedi schema.sql, cli/run_scheduled_downloads.php eseguito da cron, e
 * Alert.php per le notifiche generate quando arriva una ripresa diversa
 * dalla precedente).
 */
final class ScheduledDownload
{
    public static function forStudy(int $studyId): array
    {
        $stmt = Database::get()->prepare(
            'SELECT * FROM scheduled_downloads WHERE study_id = :id ORDER BY created_at DESC'
        );
        $stmt->execute([':id' => $studyId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM scheduled_downloads WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Tutte le pianificazioni attive la cui prossima esecuzione è dovuta
     * (mai eseguite, oppure last_run_at + interval_days <= adesso). Usata
     * dal cron: interroga una volta sola, invece di ricalcolare "è dovuta?"
     * riga per riga in PHP, per restare corretta anche con last_run_at in
     * fusi orari/formati leggermente diversi (tutto in SQLite, stesso motore
     * di confronto date usato per created_at/last_run_at). */
    public static function due(): array
    {
        $stmt = Database::get()->query(
            "SELECT * FROM scheduled_downloads
             WHERE is_active = 1
               AND (last_run_at IS NULL OR datetime(last_run_at, '+' || interval_days || ' days') <= datetime('now'))
             ORDER BY id ASC"
        );
        return $stmt->fetchAll();
    }

    public static function create(int $studyId, string $source, array $params, int $intervalDays, float $duplicateThreshold): int
    {
        $stmt = Database::get()->prepare(
            'INSERT INTO scheduled_downloads (study_id, source, params_json, interval_days, duplicate_threshold)
             VALUES (:sid, :source, :params, :interval, :threshold)'
        );
        $stmt->execute([
            ':sid' => $studyId,
            ':source' => $source,
            ':params' => json_encode($params),
            ':interval' => max(1, $intervalDays),
            ':threshold' => $duplicateThreshold,
        ]);
        return (int) Database::get()->lastInsertId();
    }

    public static function setActive(int $id, bool $active): void
    {
        $stmt = Database::get()->prepare('UPDATE scheduled_downloads SET is_active = :a WHERE id = :id');
        $stmt->execute([':a' => $active ? 1 : 0, ':id' => $id]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::get()->prepare('DELETE FROM scheduled_downloads WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /** Registra l'esito di un'esecuzione (chiamato dal cron dopo ogni tentativo). */
    public static function recordRun(int $id, string $result, ?int $captureId, ?string $error = null): void
    {
        $stmt = Database::get()->prepare(
            'UPDATE scheduled_downloads
             SET last_run_at = datetime(\'now\'), last_result = :result, last_capture_id = :cap, last_error = :err
             WHERE id = :id'
        );
        $stmt->execute([
            ':result' => $result,
            ':cap' => $captureId,
            ':err' => $error,
            ':id' => $id,
        ]);
    }
}
