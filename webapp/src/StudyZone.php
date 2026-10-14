<?php

/**
 * Zone di conteggio di uno studio (migrations/006_study_zones.sql): poligoni
 * geografici in cui lo storico dell'area (AreaHistory) conta, ripresa per
 * ripresa, gli oggetti rilevati — gli aerei su un piazzale, le navi a una
 * banchina, i mezzi in un deposito. A 0,3–0,5 m/pixel il tipo di un veicolo
 * non si riconosce, ma quanti sono e come cambiano nel tempo sì.
 *
 * Una zona nasce da un'annotazione (rettangolo o poligono) su una ripresa
 * georiferita: i suoi vertici diventano longitudine e latitudine, e la zona
 * vale per tutte le riprese dello studio, anche di altre fonti, risoluzioni
 * o rotazioni. Un oggetto è nella zona se il centro del suo riquadro lo è.
 */
final class StudyZone
{
    public const MAX_NAME = 80;

    public static function forStudy(int $studyId): array
    {
        $stmt = Database::get()->prepare('SELECT * FROM study_zones WHERE study_id = :s ORDER BY id');
        $stmt->execute([':s' => $studyId]);
        return array_map([self::class, 'decode'], $stmt->fetchAll());
    }

    public static function find(int $id): ?array
    {
        $stmt = Database::get()->prepare('SELECT * FROM study_zones WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ? self::decode($row) : null;
    }

    private static function decode(array $row): array
    {
        // Vertici non validi scartati: una zona con meno di tre resta senza
        // poligono e non conta nulla (vedi count).
        $poly = array_values(array_filter(json_decode((string) $row['polygon_json'], true) ?: [],
            fn($p) => is_array($p) && count($p) === 2 && is_numeric($p[0]) && is_numeric($p[1])));
        return ['id' => (int) $row['id'], 'study_id' => (int) $row['study_id'], 'name' => $row['name'],
            'polygon' => count($poly) >= 3 ? array_map(fn($p) => [(float) $p[0], (float) $p[1]], $poly) : [], 'created_at' => $row['created_at']];
    }

    /**
     * Zona dai vertici di un'annotazione. @return int id
     * @throws InvalidArgumentException annotazione non adatta o ripresa senza georeferenza
     */
    public static function fromAnnotation(int $annotationId, ?string $name = null): int
    {
        $stmt = Database::get()->prepare('SELECT * FROM annotations WHERE id = :id');
        $stmt->execute([':id' => $annotationId]);
        $a = $stmt->fetch();
        if (!$a) {
            throw new InvalidArgumentException('Annotazione non trovata');
        }
        $capture = !empty($a['capture_id']) ? Capture::find((int) $a['capture_id']) : null;
        if ($capture && (int) $capture['study_id'] !== (int) $a['study_id']) {
            throw new InvalidArgumentException('Annotazione e ripresa di studi diversi');
        }
        $geo = $capture ? Capture::resolveGeoRef($capture) : null;
        if (!$geo) {
            throw new InvalidArgumentException('Serve un\'annotazione su una ripresa georiferita: senza coordinate la zona non si può riportare sulle altre riprese.');
        }
        $points = self::annotationPoints($a['shape_type'] ?? 'rect', json_decode($a['coords_json'], true) ?: []);
        if (count($points) < 3) {
            throw new InvalidArgumentException('Una zona si crea da un rettangolo o da un poligono.');
        }
        $polygon = array_map(function ($p) use ($geo) {
            [$lon, $lat] = Capture::fracToLonLat($geo, (float) $p[0], (float) $p[1]);
            return [round($lon, 7), round($lat, 7)];
        }, $points);
        $name = trim((string) ($name ?? '')) ?: (trim((string) ($a['label'] ?? '')) ?: 'Zona');
        return self::create((int) $a['study_id'], $name, $polygon);
    }

    /** @param array $polygon [[lon, lat], ...] */
    public static function create(int $studyId, string $name, array $polygon): int
    {
        if (!Study::find($studyId)) {
            throw new InvalidArgumentException('Studio non trovato');
        }
        $name = mb_substr(trim($name), 0, self::MAX_NAME);
        if ($name === '') {
            throw new InvalidArgumentException('Nome della zona mancante');
        }
        $clean = [];
        foreach ($polygon as $p) {
            if (!is_array($p) || count($p) !== 2 || !is_numeric($p[0]) || !is_numeric($p[1])
                || abs((float) $p[0]) > 180 || abs((float) $p[1]) > 90) {
                throw new InvalidArgumentException('Vertici della zona non validi');
            }
            $clean[] = [(float) $p[0], (float) $p[1]];
        }
        if (count($clean) < 3) {
            throw new InvalidArgumentException('Una zona ha almeno tre vertici');
        }
        $db = Database::get();
        // La stessa area già usata come zona (▦ premuto due volte): quella.
        $json = json_encode($clean);
        $stmt = $db->prepare('SELECT id FROM study_zones WHERE study_id = :s AND polygon_json = :p');
        $stmt->execute([':s' => $studyId, ':p' => $json]);
        if ($existing = $stmt->fetchColumn()) {
            return (int) $existing;
        }
        $db->prepare('INSERT INTO study_zones (study_id, name, polygon_json) VALUES (:s, :n, :p)')
            ->execute([':s' => $studyId, ':n' => $name, ':p' => $json]);
        return (int) $db->lastInsertId();
    }

    public static function delete(int $id): void
    {
        Database::get()->prepare('DELETE FROM study_zones WHERE id = :id')->execute([':id' => $id]);
    }

    /** Vertici in frazioni dell'immagine di un'annotazione (rettangolo o poligono). */
    public static function annotationPoints(string $shape, array $coords): array
    {
        if ($shape === 'polygon') {
            return array_values(array_filter($coords['points'] ?? [], fn($p) => is_array($p) && count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1])));
        }
        if ($shape === 'rect' && isset($coords['x'], $coords['y'], $coords['w'], $coords['h'])) {
            [$x, $y, $w, $h] = array_map('floatval', [$coords['x'], $coords['y'], $coords['w'], $coords['h']]);
            return [[$x, $y], [$x + $w, $y], [$x + $w, $y + $h], [$x, $y + $h]];
        }
        return [];
    }

    /** Il punto è nel poligono? (raggio orizzontale; poligoni piccoli: gradi come piano) */
    public static function contains(array $polygon, float $lon, float $lat): bool
    {
        $in = false;
        $n = count($polygon);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $polygon[$i];
            [$xj, $yj] = $polygon[$j];
            if (($yi > $lat) !== ($yj > $lat) && $lon < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi) {
                $in = !$in;
            }
        }
        return $in;
    }

    /** Tolleranza (frazioni dell'immagine) per i vertici disegnati sul bordo. */
    private const EDGE_EPS = 1e-4;

    /**
     * Quanto della zona copre la ripresa: 'full' (tutti i vertici dentro
     * l'immagine), 'partial', 'none'. Con copertura parziale i conteggi
     * valgono per la sola parte ripresa. Parziale anche senza vertici dentro,
     * se la zona contiene un angolo dell'immagine o ne attraversa un bordo
     * (una banchina lunga e stretta che passa sopra una ripresa piccola).
     */
    public static function coverage(array $zone, array $geo): string
    {
        $poly = $zone['polygon'] ?? [];
        if (count($poly) < 3) {
            return 'none';
        }
        $frac = array_map(fn($p) => Capture::lonLatToFrac($geo, (float) $p[0], (float) $p[1]), $poly);
        $e = self::EDGE_EPS;
        $inside = count(array_filter($frac, fn($f) => $f[0] >= -$e && $f[0] <= 1 + $e && $f[1] >= -$e && $f[1] <= 1 + $e));
        if ($inside === count($frac)) {
            return 'full';
        }
        if ($inside > 0) {
            return 'partial';
        }
        $corners = [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0], [0.0, 1.0]];
        foreach ($corners as $c) {
            if (self::contains($frac, $c[0], $c[1])) {
                return 'partial';
            }
        }
        $n = count($frac);
        for ($i = 0; $i < $n; $i++) {
            $p = $frac[$i];
            $q = $frac[($i + 1) % $n];
            for ($k = 0; $k < 4; $k++) {
                if (self::segmentsCross($p, $q, $corners[$k], $corners[($k + 1) % 4])) {
                    return 'partial';
                }
            }
        }
        return 'none';
    }

    private static function segmentsCross(array $p, array $q, array $r, array $t): bool
    {
        $o = fn($a, $b, $c) => ($b[0] - $a[0]) * ($c[1] - $a[1]) - ($b[1] - $a[1]) * ($c[0] - $a[0]);
        return ($o($p, $q, $r) > 0) !== ($o($p, $q, $t) > 0) && ($o($r, $t, $p) > 0) !== ($o($r, $t, $q) > 0);
    }

    /**
     * Oggetti di un rilevamento per zona e per classe (Detection::HISTORY_CLASSES),
     * con la copertura della zona (vedi coverage). null se la ripresa non ha
     * georeferenza.
     *
     * @return array<int,array{counts: array<string,int>|null, coverage: string}>|null
     */
    public static function count(array $zones, array $capture, array $detection): ?array
    {
        $geo = Capture::resolveGeoRef($capture);
        if (!$geo) {
            return null;
        }
        $out = [];
        $active = [];
        foreach ($zones as $z) {
            $cov = self::coverage($z, $geo);
            // Una zona del tutto fuori dalla ripresa non ha conteggi (non zero:
            // non si sa).
            $out[$z['id']] = ['counts' => $cov === 'none' ? null : array_fill_keys(array_keys(Detection::HISTORY_CLASSES), 0), 'coverage' => $cov];
            if ($cov !== 'none') {
                $active[] = $z;
            }
        }
        $zones = $active;
        foreach ($detection['result']['objects'] ?? [] as $o) {
            $cls = $o['class'] ?? '';
            if (!isset(Detection::HISTORY_CLASSES[$cls]) || !isset($o['center'][0], $o['center'][1])) {
                continue;
            }
            [$lon, $lat] = Capture::fracToLonLat($geo, (float) $o['center'][0], (float) $o['center'][1]);
            foreach ($zones as $z) {
                if (self::contains($z['polygon'], $lon, $lat)) {
                    $out[$z['id']]['counts'][$cls]++;
                }
            }
        }
        return $out;
    }
}
