<?php

/**
 * Rilevamenti automatici di oggetti su una ripresa (vedi schema.sql,
 * api/detect.php, python-service/app/core/detect.py). Per ripresa si tiene
 * solo l'ultima esecuzione: rieseguire con un'altra soglia la sostituisce.
 */
final class Detection
{
    /** Risoluzione oltre la quale il modello non ha senso (addestrato fra
     * ~0,1 e ~1 m/pixel): a 10 m/pixel un aereo è qualche pixel. */
    public const MAX_MPP = 2.0;

    /** Classi che contano nello storico, nell'ordine di visualizzazione. */
    public const HISTORY_CLASSES = [
        'plane' => 'aerei',
        'helicopter' => 'elicotteri',
        'ship' => 'navi',
        'large vehicle' => 'veicoli grandi',
        'small vehicle' => 'veicoli piccoli',
    ];

    /**
     * Metri/pixel per il rilevamento: quelli della ripresa (o ereditati) e,
     * solo per una ripresa originale senza riferimento proprio, quelli
     * dell'area dello studio — stessa regola della vista di analisi. Usata da
     * api/detect.php, dallo storico e dalla vista di analisi, perché tutti
     * decidano allo stesso modo se una ripresa è adatta.
     *
     * @return array{mpp_x:float, mpp_y:float}|null
     */
    public static function resolveMpp(array $capture): ?array
    {
        $mpp = Capture::resolveMpp($capture);
        $meta = json_decode($capture['meta_json'] ?? '', true) ?: [];
        if (!$mpp && empty($meta['source_capture_id'])) {
            $study = Study::find((int) $capture['study_id']);
            $bbox = $study && $study['bbox_json'] ? json_decode($study['bbox_json'], true) : null;
            if (is_array($bbox) && count($bbox) === 4) {
                $mpp = Capture::resolveMpp(['meta_json' => json_encode(['bbox' => $bbox]), 'width' => $capture['width'], 'height' => $capture['height']]);
            }
        }
        return $mpp;
    }

    public static function save(int $captureId, int $studyId, string $model, float $confidence, bool $smallObjects, ?float $mpp, array $result, array $counts): int
    {
        $db = Database::get();
        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM detections WHERE capture_id = :c')->execute([':c' => $captureId]);
            $stmt = $db->prepare(
                'INSERT INTO detections (capture_id, study_id, model, confidence, small_objects, mpp, result_json, counts_json)
                 VALUES (:c, :s, :m, :conf, :small, :mpp, :r, :counts)'
            );
            $stmt->execute([
                ':c' => $captureId, ':s' => $studyId, ':m' => $model, ':conf' => $confidence,
                ':small' => $smallObjects ? 1 : 0, ':mpp' => $mpp,
                ':r' => json_encode($result), ':counts' => json_encode($counts),
            ]);
            $id = (int) $db->lastInsertId();
            $db->commit();
            return $id;
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    public static function forCapture(int $captureId): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM detections WHERE capture_id = :c ORDER BY id DESC LIMIT 1');
        $stmt->execute([':c' => $captureId]);
        $row = $stmt->fetch();
        return $row ? self::decode($row) : null;
    }

    /** @return array<int,array> ultima esecuzione per ripresa, indicizzata per capture_id */
    public static function forStudy(int $studyId): array
    {
        $stmt = Database::get()->prepare('SELECT * FROM detections WHERE study_id = :s ORDER BY id');
        $stmt->execute([':s' => $studyId]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['capture_id']] = self::decode($row);
        }
        return $out;
    }

    private static function decode(array $row): array
    {
        $row['result'] = json_decode($row['result_json'], true) ?: [];
        $row['counts'] = json_decode($row['counts_json'], true) ?: [];
        unset($row['result_json'], $row['counts_json']);
        return $row;
    }
}
