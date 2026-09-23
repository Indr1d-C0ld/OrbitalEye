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

    /**
     * Riferimento geografico di una ripresa: ['bbox' => [minLon,minLat,
     * maxLon,maxLat], 'rotation' => gradi, 'rotation_model' => ...], oppure
     * null se non è noto.
     *
     * Per una ripresa derivata creata prima che il riferimento venisse
     * copiato nei suoi metadati si risale la catena source_capture_id fino a
     * una ripresa che lo possiede. Non si ricade MAI sulla bbox dello studio:
     * per una copia migliorata o un ritaglio quella bbox non descrive l'area
     * dei pixel, e gli export KML/GeoJSON finivano spostati anche di
     * chilometri (vedi api/export_geo.php).
     */
    public static function resolveGeoRef(array $capture): ?array
    {
        $seen = [];
        while ($capture && !isset($seen[(int) $capture['id']])) {
            $seen[(int) $capture['id']] = true;
            $meta = json_decode($capture['meta_json'] ?? '', true);
            if (!is_array($meta)) {
                return null;
            }
            if (!empty($meta['bbox']) && is_array($meta['bbox']) && count($meta['bbox']) === 4) {
                return [
                    'bbox' => array_map('floatval', $meta['bbox']),
                    'rotation' => (float) ($meta['rotation'] ?? 0),
                    'rotation_model' => $meta['rotation_model'] ?? null,
                ];
            }
            if (empty($meta['source_capture_id'])) {
                return null;
            }
            // Solo le derivate "a immagine intera" condividono la griglia della
            // sorgente: si riconoscono dalle dimensioni identiche. Un vecchio
            // ritaglio (dimensioni diverse, nessuna bbox propria) non è
            // georeferenziabile e viene lasciato tale, invece di ereditare per
            // errore l'area dell'intera sorgente.
            $source = self::find((int) $meta['source_capture_id']);
            if (!$source || (int) $source['width'] !== (int) $capture['width']
                || (int) $source['height'] !== (int) $capture['height']) {
                return null;
            }
            $capture = $source;
        }
        return null;
    }

    /**
     * Metadati geografici da assegnare a una ripresa derivata da $source:
     * la stessa area (copie a immagine intera) o, se $crop è indicato, l'area
     * esatta del ritaglio.
     *
     * @param array|null $crop Ritaglio in frazioni dell'immagine sorgente:
     *   ['x' => ..., 'y' => ..., 'w' => ..., 'h' => ...], origine in alto a sinistra.
     */
    public static function geoMetaForDerived(array $source, ?array $crop = null): array
    {
        $geo = self::resolveGeoRef($source);
        if (!$geo) {
            return [];
        }
        $meta = [];
        if ($geo['rotation'] && abs($geo['rotation']) >= 0.01) {
            $meta['rotation'] = $geo['rotation'];
            if ($geo['rotation_model']) {
                $meta['rotation_model'] = $geo['rotation_model'];
            }
        }
        if (!$crop) {
            $meta['bbox'] = $geo['bbox'];
            return $meta;
        }
        // Rettangolo "di base" del ritaglio: centrato nel centro del
        // ritaglio, con la stessa rotazione della sorgente e lati pari a
        // quelli del ritaglio (nel sistema ruotato della sorgente).
        [$fx, $fy, $fw, $fh] = [$crop['x'], $crop['y'], $crop['w'], $crop['h']];
        [$cLon, $cLat] = self::fracToLonLat($geo, $fx + $fw / 2, $fy + $fh / 2);
        [$minLon, $minLat, $maxLon, $maxLat] = $geo['bbox'];
        $halfLon = ($maxLon - $minLon) * $fw / 2;
        $halfLat = ($maxLat - $minLat) * $fh / 2;
        $meta['bbox'] = [$cLon - $halfLon, $cLat - $halfLat, $cLon + $halfLon, $cLat + $halfLat];
        $meta['crop'] = ['x' => $fx, 'y' => $fy, 'w' => $fw, 'h' => $fh];
        return $meta;
    }

    /**
     * Converte un punto dell'immagine (frazioni 0..1, origine in alto a
     * sinistra) in [lon, lat], dato il riferimento di resolveGeoRef().
     *
     * Ripresa non ruotata: interpolazione lineare sulla bbox (le immagini
     * sono equirettangolari). Ripresa ruotata: il punto viene riportato nel
     * sistema del rettangolo di base e ruotato attorno al suo centro, nello
     * stesso spazio in cui è stata ruotata l'immagine — metrico per le
     * riprese attuali (vedi ImageRotateCrop), dei gradi per quelle più
     * vecchie. In entrambi i casi il risultato è esatto, non approssimato.
     */
    public static function fracToLonLat(array $geo, float $fx, float $fy): array
    {
        [$minLon, $minLat, $maxLon, $maxLat] = $geo['bbox'];
        $rotation = (float) ($geo['rotation'] ?? 0);
        if (abs($rotation) < 0.01) {
            return [$minLon + $fx * ($maxLon - $minLon), $maxLat - $fy * ($maxLat - $minLat)];
        }
        $cLon = ($minLon + $maxLon) / 2;
        $cLat = ($minLat + $maxLat) / 2;
        $k = ($geo['rotation_model'] ?? null) === ImageRotateCrop::ROTATION_MODEL
            ? ImageRotateCrop::lonScale($cLat)
            : 1.0;
        // Coordinate "schermo" (x verso est, y verso sud) nel sistema del
        // rettangolo di base, in unità di latitudine.
        $ex = ($fx - 0.5) * ($maxLon - $minLon) * $k;
        $ey = ($fy - 0.5) * ($maxLat - $minLat);
        $t = deg2rad($rotation); // positivo = orario, come sulla mappa
        $x = $ex * cos($t) - $ey * sin($t);
        $y = $ex * sin($t) + $ey * cos($t);
        return [$cLon + $x / $k, $cLat - $y];
    }

    /**
     * Tutti i file su disco che appartengono a una ripresa: l'immagine
     * principale e, per le riprese Sentinel Hub, la coppia Rosso+NIR
     * scaricata a parte (usata da NDVI/NDWI/falso colore IR). Quest'ultima
     * viveva solo dentro meta_json e non veniva mai eliminata: ogni
     * cancellazione di una ripresa Sentinel lasciava indietro il suo file NIR.
     *
     * @param array $capture Riga della tabella captures.
     * @return string[] Percorsi relativi allo storage root.
     */
    public static function ownedFiles(array $capture): array
    {
        $files = [];
        if (!empty($capture['relative_path'])) {
            $files[] = $capture['relative_path'];
        }
        $meta = json_decode($capture['meta_json'] ?? '', true);
        if (is_array($meta) && !empty($meta['nir_relative_path'])) {
            $files[] = $meta['nir_relative_path'];
        }
        return $files;
    }

    /**
     * Elimina dal disco i file di una ripresa, saltando quelli ancora
     * referenziati da un'altra riga (lo stesso file processed/ può essere
     * stato salvato come più riprese: vedi api/save_enhanced_capture.php).
     * Senza questo controllo, eliminarne una lasciava le altre a puntare a
     * un file inesistente.
     *
     * @param int[] $ignoreCaptureIds Righe da non considerare come
     *   "referenti" perché sono esse stesse in corso di eliminazione.
     */
    public static function deleteOwnedFiles(array $capture, array $ignoreCaptureIds = []): void
    {
        $root = Config::storageRoot();
        $ignore = array_map('intval', $ignoreCaptureIds);
        $ignore[] = (int) $capture['id'];
        $placeholders = implode(',', array_fill(0, count($ignore), '?'));

        foreach (self::ownedFiles($capture) as $relative) {
            $stmt = Database::get()->prepare(
                "SELECT COUNT(*) FROM captures
                 WHERE relative_path = ? AND id NOT IN ($placeholders)"
            );
            $stmt->execute(array_merge([$relative], $ignore));
            if ((int) $stmt->fetchColumn() > 0) {
                continue; // ancora in uso da un'altra ripresa
            }
            $full = $root . '/' . $relative;
            if (is_file($full)) {
                @unlink($full);
            }
        }
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

        self::deleteOwnedFiles($capture);

        $stmt = Database::get()->prepare('DELETE FROM captures WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }
}
