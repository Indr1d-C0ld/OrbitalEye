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

    private static function haversineMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthR = 6371000.0;
        $toRad = fn (float $d): float => $d * M_PI / 180;
        $dLat = $toRad($lat2 - $lat1);
        $dLon = $toRad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos($toRad($lat1)) * cos($toRad($lat2)) * sin($dLon / 2) ** 2;
        return 2 * $earthR * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Risolve la scala reale (metri/pixel, per asse) di una ripresa, per
     * poterla ereditare in una ripresa DERIVATA (salvata/migliorata/
     * ritagliata da questa) che mantiene la stessa densità di pixel — nessun
     * ridimensionamento in nessuno di questi passaggi, quindi mpp_x/mpp_y
     * restano validi identici. Bug corretto qui: prima le riprese derivate
     * (upload_capture.php/enhance_capture.php) non ereditavano alcuna
     * informazione di scala, e la vista di analisi ricadeva sulla bbox
     * GENERICA dello studio — corretta solo per la ripresa scaricata
     * originale, sbagliata (spesso di molto, soprattutto per un ritaglio
     * molto più piccolo dell'area intera) per qualunque derivata.
     *
     * Ordine di risoluzione:
     * 1) mpp_x/mpp_y già risolti nel meta (la ripresa è a sua volta una
     *    derivata che li aveva già ereditati).
     * 2) bbox propria nel meta (ripresa scaricata direttamente da Esri/
     *    Sentinel Hub con area nota).
     * Non ricade MAI sulla bbox generica dello studio qui: non c'è garanzia
     * che corrisponda ancora all'area/alle dimensioni pixel effettive di
     * una ripresa derivata (quella logica, quando ha senso, resta solo nel
     * fallback finale usato dalla vista di analisi per riprese originali
     * che non hanno mai avuto una propria bbox).
     */
    public static function resolveMpp(array $capture): ?array
    {
        $meta = json_decode($capture['meta_json'] ?? '', true);
        if (!is_array($meta)) {
            return null;
        }
        if (isset($meta['mpp_x'], $meta['mpp_y'])) {
            return ['mpp_x' => (float) $meta['mpp_x'], 'mpp_y' => (float) $meta['mpp_y']];
        }
        if (!empty($meta['bbox']) && is_array($meta['bbox']) && count($meta['bbox']) === 4
            && !empty($capture['width']) && !empty($capture['height'])
        ) {
            [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $meta['bbox']);
            $centerLat = ($minLat + $maxLat) / 2;
            $centerLon = ($minLon + $maxLon) / 2;
            $widthM = self::haversineMeters($centerLat, $minLon, $centerLat, $maxLon);
            $heightM = self::haversineMeters($minLat, $centerLon, $maxLat, $centerLon);
            return ['mpp_x' => $widthM / (int) $capture['width'], 'mpp_y' => $heightM / (int) $capture['height']];
        }
        return null;
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
