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

    /**
     * Elimina lo studio e tutto ciò che vi appartiene.
     *
     * Le righe figlie (captures, comparisons, annotations, ...) se ne vanno
     * da sole per via dei vincoli ON DELETE CASCADE dello schema — ma quel
     * meccanismo vive dentro SQLite e non può eseguire codice PHP: i file su
     * disco (riprese in raw/processed e output dei confronti in results/)
     * restavano quindi orfani per sempre, invisibili all'applicazione.
     * Qui i file vengono rimossi PRIMA della DELETE, finché le righe che li
     * referenziano esistono ancora ed è possibile sapere quali siano.
     */
    public static function delete(int $id): void
    {
        $pdo = Database::get();

        $comparisons = $pdo->prepare('SELECT result_paths_json FROM comparisons WHERE study_id = :id');
        $comparisons->execute([':id' => $id]);
        foreach ($comparisons->fetchAll() as $row) {
            Comparison::deleteResultFiles($row['result_paths_json']);
        }

        // Tutte le riprese dello studio spariscono insieme: si passano i
        // rispettivi id come "da ignorare" così un file condiviso fra due
        // riprese dello STESSO studio viene comunque rimosso, mentre resta
        // protetto se a referenziarlo è una ripresa di un altro studio.
        $captures = $pdo->prepare('SELECT id, relative_path, meta_json FROM captures WHERE study_id = :id');
        $captures->execute([':id' => $id]);
        $rows = $captures->fetchAll();
        $allIds = array_map(fn($r) => (int) $r['id'], $rows);
        foreach ($rows as $row) {
            Capture::deleteOwnedFiles($row, $allIds);
        }

        $stmt = $pdo->prepare('DELETE FROM studies WHERE id = :id');
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
