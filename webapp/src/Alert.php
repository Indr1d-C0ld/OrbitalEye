<?php

/**
 * Notifiche "nuova ripresa diversa dalla precedente rilevata" generate dallo
 * scaricamento automatico (vedi ScheduledDownload.php + cli/run_scheduled_downloads.php).
 * Puramente interne alla piattaforma (nessun invio email, per scelta
 * esplicita): badge in barra laterale (vedi partials/nav.php) + pagina
 * dedicata (alerts.php).
 */
final class Alert
{
    public static function create(int $studyId, ?int $captureId, ?int $scheduleId, string $message): int
    {
        $stmt = Database::get()->prepare(
            'INSERT INTO alerts (study_id, capture_id, schedule_id, message) VALUES (:sid, :cap, :sched, :msg)'
        );
        $stmt->execute([':sid' => $studyId, ':cap' => $captureId, ':sched' => $scheduleId, ':msg' => $message]);
        return (int) Database::get()->lastInsertId();
    }

    public static function unreadCount(): int
    {
        return (int) Database::get()->query('SELECT COUNT(*) AS c FROM alerts WHERE is_read = 0')->fetch()['c'];
    }

    /** Più recenti prima, con qualche informazione della ripresa/studio già unita
     * (evita N query separate per popolare la pagina/lista alert). */
    public static function recent(int $limit = 100): array
    {
        $stmt = Database::get()->prepare(
            'SELECT a.*, s.title AS study_name, c.relative_path AS capture_relative_path
             FROM alerts a
             JOIN studies s ON s.id = a.study_id
             LEFT JOIN captures c ON c.id = a.capture_id
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function markRead(int $id): void
    {
        $stmt = Database::get()->prepare('UPDATE alerts SET is_read = 1 WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public static function markAllRead(): void
    {
        Database::get()->exec('UPDATE alerts SET is_read = 1 WHERE is_read = 0');
    }
}
