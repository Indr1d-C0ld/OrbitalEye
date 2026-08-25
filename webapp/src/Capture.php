<?php

final class Capture
{
    public static function forStudy(int $studyId): array
    {
        $stmt = Database::get()->prepare(
            'SELECT * FROM captures WHERE study_id = :id ORDER BY capture_date ASC, created_at ASC'
        );
        $stmt->execute([':id' => $studyId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM captures WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(
        int $studyId,
        ?string $label,
        string $source,
        ?string $captureDate,
        string $relativePath,
        ?int $width,
        ?int $height,
        ?array $meta = null
    ): int {
        $stmt = Database::get()->prepare(
            'INSERT INTO captures (study_id, label, source, capture_date, relative_path, width, height, meta_json)
             VALUES (:sid, :label, :source, :date, :path, :w, :h, :meta)'
        );
        $stmt->execute([
            ':sid' => $studyId,
            ':label' => $label,
            ':source' => $source,
            ':date' => $captureDate,
            ':path' => $relativePath,
            ':w' => $width,
            ':h' => $height,
            ':meta' => $meta ? json_encode($meta) : null,
        ]);
        return (int) Database::get()->lastInsertId();
    }

    /** Restituisce i confronti (id, result_paths_json) che verranno
     * eliminati in cascata insieme a questa ripresa (FK ON DELETE CASCADE su
     * capture_a_id/capture_b_id), inclusi quelli salvati in libreria. */
    public static function linkedComparisons(int $id): array
    {
        $stmt = Database::get()->prepare(
            'SELECT id, title, is_saved_to_library, result_paths_json FROM comparisons
             WHERE capture_a_id = :id OR capture_b_id = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetchAll();
    }

    public static function delete(int $id): void
    {
        $capture = self::find($id);
        if (!$capture) {
            return;
        }

        // I confronti che usano questa ripresa verranno eliminati in cascata
        // dal DB (FK ON DELETE CASCADE): puliamo prima i loro file di output
        // su disco, altrimenti resterebbero orfani in storage/results/.
        foreach (self::linkedComparisons($id) as $cmp) {
            Comparison::deleteResultFiles($cmp['result_paths_json']);
        }

        $path = Config::storageRoot() . '/' . $capture['relative_path'];
        if (is_file($path)) {
            @unlink($path);
        }

        $stmt = Database::get()->prepare('DELETE FROM captures WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
