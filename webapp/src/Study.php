<?php

final class Study
{
    public static function all(?string $search = null): array
    {
        $pdo = Database::get();
        if ($search) {
            $stmt = $pdo->prepare(
                "SELECT * FROM studies WHERE title LIKE :s OR area_name LIKE :s OR notes LIKE :s ORDER BY updated_at DESC"
            );
            $stmt->execute([':s' => '%' . $search . '%']);
        } else {
            $stmt = $pdo->query('SELECT * FROM studies ORDER BY updated_at DESC');
        }
        return $stmt->fetchAll();
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM studies WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(string $title, ?string $areaName, ?array $bbox, ?string $notes): int
    {
        $stmt = Database::get()->prepare(
            'INSERT INTO studies (title, area_name, bbox_json, notes) VALUES (:t, :a, :b, :n)'
        );
        $stmt->execute([
            ':t' => $title,
            ':a' => $areaName,
            ':b' => $bbox ? json_encode($bbox) : null,
            ':n' => $notes,
        ]);
        return (int) Database::get()->lastInsertId();
    }

    public static function touch(int $id): void
    {
        $stmt = Database::get()->prepare("UPDATE studies SET updated_at = datetime('now') WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    public static function delete(int $id): void
    {
        $stmt = Database::get()->prepare('DELETE FROM studies WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public static function counts(int $id): array
    {
        $pdo = Database::get();
        $captures = $pdo->prepare('SELECT COUNT(*) c FROM captures WHERE study_id = :id');
        $captures->execute([':id' => $id]);
        $comparisons = $pdo->prepare('SELECT COUNT(*) c FROM comparisons WHERE study_id = :id');
        $comparisons->execute([':id' => $id]);
        $annotations = $pdo->prepare('SELECT COUNT(*) c FROM annotations WHERE study_id = :id');
        $annotations->execute([':id' => $id]);
        return [
            'captures' => (int) $captures->fetch()['c'],
            'comparisons' => (int) $comparisons->fetch()['c'],
            'annotations' => (int) $annotations->fetch()['c'],
        ];
    }
}
