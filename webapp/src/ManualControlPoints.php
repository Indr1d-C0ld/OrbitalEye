<?php

/**
 * Punti di controllo per l'allineamento manuale di una coppia di riprese
 * (fallback assistito quando il motore automatico ORB+ECC non trova
 * corrispondenze affidabili — vedi python-service/app/core/registration.py
 * register_with_points). Legati alla coppia capture_a_id/capture_b_id, non
 * al singolo confronto: restano disponibili e modificabili per qualunque
 * confronto futuro sulle stesse due immagini.
 */
final class ManualControlPoints
{
    public static function find(int $captureAId, int $captureBId): ?array
    {
        $stmt = Database::get()->prepare(
            'SELECT * FROM manual_control_points WHERE capture_a_id = :a AND capture_b_id = :b'
        );
        $stmt->execute([':a' => $captureAId, ':b' => $captureBId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Ritorna i punti come array PHP (decodificati), mai la riga grezza. */
    public static function getPoints(int $captureAId, int $captureBId): array
    {
        $row = self::find($captureAId, $captureBId);
        if (!$row) {
            return [];
        }
        $points = json_decode($row['points_json'], true);
        return is_array($points) ? $points : [];
    }

    /** Upsert: crea o sovrascrive i punti per questa coppia di riprese. */
    public static function save(int $captureAId, int $captureBId, array $points): void
    {
        $existing = self::find($captureAId, $captureBId);
        $json = json_encode(array_values($points));
        if ($existing) {
            $stmt = Database::get()->prepare(
                "UPDATE manual_control_points SET points_json = :p, updated_at = datetime('now')
                 WHERE capture_a_id = :a AND capture_b_id = :b"
            );
        } else {
            $stmt = Database::get()->prepare(
                'INSERT INTO manual_control_points (capture_a_id, capture_b_id, points_json)
                 VALUES (:a, :b, :p)'
            );
        }
        $stmt->execute([':a' => $captureAId, ':b' => $captureBId, ':p' => $json]);
    }

    public static function delete(int $captureAId, int $captureBId): void
    {
        $stmt = Database::get()->prepare(
            'DELETE FROM manual_control_points WHERE capture_a_id = :a AND capture_b_id = :b'
        );
        $stmt->execute([':a' => $captureAId, ':b' => $captureBId]);
    }
}
