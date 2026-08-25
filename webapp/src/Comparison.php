<?php

final class Comparison
{
    public static function forStudy(int $studyId): array
    {
        $stmt = Database::get()->prepare(
            'SELECT * FROM comparisons WHERE study_id = :id ORDER BY created_at DESC'
        );
        $stmt->execute([':id' => $studyId]);
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM comparisons WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(
        int $studyId,
        int $captureAId,
        int $captureBId,
        ?string $title,
        array $params,
        array $stats,
        array $regions,
        array $resultPaths,
        array $registration
    ): int {
        $stmt = Database::get()->prepare(
            'INSERT INTO comparisons
                (study_id, capture_a_id, capture_b_id, title, params_json, stats_json, regions_json, result_paths_json, registration_json)
             VALUES (:sid, :a, :b, :title, :params, :stats, :regions, :paths, :reg)'
        );
        $stmt->execute([
            ':sid' => $studyId,
            ':a' => $captureAId,
            ':b' => $captureBId,
            ':title' => $title,
            ':params' => json_encode($params),
            ':stats' => json_encode($stats),
            ':regions' => json_encode($regions),
            ':paths' => json_encode($resultPaths),
            ':reg' => json_encode($registration),
        ]);
        return (int) Database::get()->lastInsertId();
    }

    public static function saveToLibrary(int $id, bool $saved = true, ?string $title = null): void
    {
        if ($title !== null) {
            $stmt = Database::get()->prepare(
                'UPDATE comparisons SET is_saved_to_library = :s, title = :t WHERE id = :id'
            );
            $stmt->execute([':s' => $saved ? 1 : 0, ':t' => $title !== '' ? $title : null, ':id' => $id]);
            return;
        }
        $stmt = Database::get()->prepare('UPDATE comparisons SET is_saved_to_library = :s WHERE id = :id');
        $stmt->execute([':s' => $saved ? 1 : 0, ':id' => $id]);
    }

    public static function library(?string $search = null): array
    {
        $pdo = Database::get();
        $sql = "SELECT c.*, s.title AS study_title, s.area_name AS study_area
                FROM comparisons c JOIN studies s ON s.id = c.study_id
                WHERE c.is_saved_to_library = 1";
        if ($search) {
            $sql .= " AND (c.title LIKE :s OR s.title LIKE :s OR s.area_name LIKE :s)";
        }
        $sql .= " ORDER BY c.created_at DESC";
        $stmt = $pdo->prepare($sql);
        if ($search) {
            $stmt->execute([':s' => '%' . $search . '%']);
        } else {
            $stmt->execute();
        }
        return $stmt->fetchAll();
    }

    public static function delete(int $id): void
    {
        $comparison = self::find($id);
        if ($comparison) {
            self::deleteResultFiles($comparison['result_paths_json']);
        }
        $stmt = Database::get()->prepare('DELETE FROM comparisons WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /** Rimuove dal disco gli output di analisi (overlay, heatmap, maschera,
     * immagine allineata) associati a un confronto. Va chiamata prima di
     * eliminare la riga, anche quando l'eliminazione del confronto avviene
     * in cascata insieme a una delle sue riprese (vedi Capture::delete). */
    public static function deleteResultFiles(?string $resultPathsJson): void
    {
        if (!$resultPathsJson) {
            return;
        }
        $paths = json_decode($resultPathsJson, true);
        if (!is_array($paths)) {
            return;
        }
        $root = Config::storageRoot();
        $resultDir = null;
        foreach ($paths as $relative) {
            $full = $root . '/' . $relative;
            if (is_file($full)) {
                @unlink($full);
            }
            if ($resultDir === null) {
                $resultDir = dirname($full);
            }
        }
        if ($resultDir && is_dir($resultDir)) {
            @rmdir($resultDir); // rimuove la cartella results/<id>/ solo se ormai vuota
        }
    }
}
