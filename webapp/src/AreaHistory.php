<?php

/**
 * Storico di un'area: per ogni ripresa di uno studio, nell'ordine della data
 * REALE dell'immagine (non del download, vedi ImageryAttribution), quanti
 * aerei, elicotteri, navi e veicoli ha trovato il rilevamento automatico e
 * quali tipi di velivolo sono stati identificati nelle annotazioni (etichette
 * che corrispondono a un tipo della tabella, vedi AircraftCatalog).
 *
 * Usato dal pannello "Storico dell'area" (study.php) e dal suo export CSV
 * (api/area_history.php).
 */
final class AreaHistory
{
    public static function forStudy(int $studyId): array
    {
        $detections = Detection::forStudy($studyId);

        $typesByCapture = [];
        foreach (Annotation::forStudy($studyId) as $a) {
            if (($a['shape_type'] ?? '') === 'measure' || empty($a['capture_id'])) {
                continue;
            }
            $type = AircraftCatalog::typeForLabel($a['label'] ?? '');
            if ($type !== null) {
                $typesByCapture[(int) $a['capture_id']][$type] = ($typesByCapture[(int) $a['capture_id']][$type] ?? 0) + 1;
            }
        }

        $rows = [];
        foreach (Capture::forStudy($studyId) as $c) {
            $id = (int) $c['id'];
            $info = ImageryAttribution::forCapture($c);
            $mpp = Detection::resolveMpp($c);
            $mppMean = $mpp ? ($mpp['mpp_x'] + $mpp['mpp_y']) / 2 : null;
            $det = $detections[$id] ?? null;
            $types = $typesByCapture[$id] ?? [];
            arsort($types);
            $rows[] = [
                'capture_id' => $id,
                'label' => $c['label'] ?: ('Ripresa #' . $id),
                'date' => $info['date'] ?: ($c['capture_date'] ?: null),
                'source' => Capture::sourceLabel($c),
                'mpp' => $mppMean !== null ? round($mppMean, 2) : null,
                'detectable' => $mppMean === null || $mppMean <= Detection::MAX_MPP,
                'detected_at' => $det['created_at'] ?? null,
                'confidence' => $det ? (float) $det['confidence'] : null,
                'counts' => $det ? array_map(fn($k) => (int) ($det['counts'][$k] ?? 0), array_combine(array_keys(Detection::HISTORY_CLASSES), array_keys(Detection::HISTORY_CLASSES))) : null,
                'types' => $types,
            ];
        }
        usort($rows, fn($a, $b) => [$a['date'] ?? '9999', $a['capture_id']] <=> [$b['date'] ?? '9999', $b['capture_id']]);
        return $rows;
    }

    /** CSV (separatore ";" e virgola decimale, per i fogli di calcolo italiani). */
    public static function csv(array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel riconosce l'UTF-8
        fputcsv($out, array_merge(['data_immagine', 'ripresa', 'fonte', 'm_per_pixel', 'rilevamento_del', 'confidenza_min'],
            array_values(Detection::HISTORY_CLASSES), ['tipi_identificati']), ';', '"', '');
        // Celle che un foglio di calcolo interpreterebbe come formula
        // (etichette scritte dall'utente che iniziano con = + - @).
        $cell = fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'" . $v : $v;
        foreach ($rows as $r) {
            $types = implode(', ', array_map(fn($t, $n) => $n > 1 ? "$t ×$n" : $t, array_keys($r['types']), $r['types']));
            fputcsv($out, array_merge(
                [$r['date'] ?? '', $cell($r['label']), $cell($r['source']),
                    $r['mpp'] !== null ? str_replace('.', ',', (string) $r['mpp']) : '',
                    // SQLite registra in UTC: nel file l'ora locale, come nell'interfaccia.
                    $r['detected_at'] ? date('Y-m-d H:i', strtotime($r['detected_at'] . ' UTC')) : '', $r['confidence'] !== null ? str_replace('.', ',', (string) $r['confidence']) : ''],
                $r['counts'] !== null ? array_values($r['counts']) : array_fill(0, count(Detection::HISTORY_CLASSES), ''),
                [$cell($types)]
            ), ';', '"', '');
        }
        rewind($out);
        return stream_get_contents($out);
    }
}
