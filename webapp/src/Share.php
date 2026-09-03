<?php

/**
 * Registro delle condivisioni esterne (vedi schema.sql): tiene traccia di
 * cosa è stato pubblicato, quando e verso dove — un analista che condivide
 * materiale d'analisi deve poter sapere in seguito cosa ha reso pubblico.
 */
final class Share
{
    public static function create(?int $studyId, string $kind, ?int $refId, string $platform, string $caption): int
    {
        $stmt = Database::get()->prepare(
            'INSERT INTO shares (study_id, kind, ref_id, platform, caption) VALUES (:sid, :kind, :ref, :plat, :cap)'
        );
        $stmt->execute([
            ':sid' => $studyId,
            ':kind' => $kind,
            ':ref' => $refId,
            ':plat' => $platform,
            ':cap' => $caption,
        ]);
        return (int) Database::get()->lastInsertId();
    }

    public static function recent(int $limit = 100): array
    {
        $stmt = Database::get()->prepare(
            'SELECT sh.*, s.title AS study_title
             FROM shares sh
             LEFT JOIN studies s ON s.id = sh.study_id
             ORDER BY sh.created_at DESC, sh.id DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
