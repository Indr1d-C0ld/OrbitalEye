<?php

final class Annotation
{
    public static function forStudy(int $studyId): array
    {
        $stmt = Database::get()->prepare(
            'SELECT * FROM annotations WHERE study_id = :id ORDER BY created_at DESC'
        );
        $stmt->execute([':id' => $studyId]);
        return $stmt->fetchAll();
    }

    public static function forTarget(int $studyId, string $targetImage): array
    {
        $stmt = Database::get()->prepare(
            'SELECT * FROM annotations WHERE study_id = :id AND target_image = :t ORDER BY created_at ASC'
        );
        $stmt->execute([':id' => $studyId, ':t' => $targetImage]);
        return $stmt->fetchAll();
    }

    /** Tutte le annotazioni legate a un confronto, su qualunque vista
     * (overlay/heatmap/maschera) sia stata usata per disegnarle. */
    public static function forComparison(int $comparisonId): array
    {
        $stmt = Database::get()->prepare(
            'SELECT * FROM annotations WHERE comparison_id = :id ORDER BY created_at ASC'
        );
        $stmt->execute([':id' => $comparisonId]);
        return $stmt->fetchAll();
    }

    public static function create(
        int $studyId,
        ?int $captureId,
        ?int $comparisonId,
        string $targetImage,
        string $shapeType,
        array $coords,
        string $color,
        ?string $label,
        ?string $notes
    ): int {
        $stmt = Database::get()->prepare(
            'INSERT INTO annotations
                (study_id, capture_id, comparison_id, target_image, shape_type, coords_json, color, label, notes)
             VALUES (:sid, :cap, :cmp, :target, :shape, :coords, :color, :label, :notes)'
        );
        $stmt->execute([
            ':sid' => $studyId,
            ':cap' => $captureId,
            ':cmp' => $comparisonId,
            ':target' => $targetImage,
            ':shape' => $shapeType,
            ':coords' => json_encode($coords),
            ':color' => $color,
            ':label' => $label,
            ':notes' => $notes,
        ]);
        return (int) Database::get()->lastInsertId();
    }

    public static function delete(int $id): void
    {
        $stmt = Database::get()->prepare('DELETE FROM annotations WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
