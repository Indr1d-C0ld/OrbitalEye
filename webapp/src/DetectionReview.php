<?php

/**
 * Verifica dei rilevamenti da parte dell'analista (migrations/003): per ogni
 * oggetto, confermato con la classe vera e, per i velivoli, il tipo, oppure
 * scartato; più gli oggetti che il rilevatore non ha trovato. Vedi
 * DetectionQuality per le misure che se ne ricavano.
 *
 * Una verifica è legata a una POSIZIONE della ripresa (centro e raggio in
 * frazioni), non al numero dell'oggetto: rieseguendo il rilevamento gli
 * oggetti si rinumerano e i riquadri si spostano di qualche pixel, ma la
 * verifica resta sullo stesso oggetto.
 */
final class DetectionReview
{
    public const VERDICTS = ['confirmed', 'rejected'];

    /** Classi che si possono indicare come vere (quelle del rilevatore che contano). */
    public const CLASSES = [
        'plane' => 'aereo',
        'helicopter' => 'elicottero',
        'ship' => 'nave',
        'large vehicle' => 'veicolo grande',
        'small vehicle' => 'veicolo piccolo',
        'other' => 'altro',
    ];

    /**
     * Tipi ammessi per classe: un tipo si conferma solo nell'ambito della sua
     * classe (un aereo con un tipo d'aereo, non una nave con "C-17"). Per gli
     * aerei tutti i velivoli tranne gli elicotteri; per gli elicotteri anche
     * i convertiplani, che il rilevatore può vedere come elicotteri. Le altre
     * classi per ora non hanno una tabella dei tipi.
     */
    public static function typesFor(?string $class): array
    {
        if ($class !== 'plane' && $class !== 'helicopter') {
            return [];
        }
        $out = [];
        foreach (AircraftCatalog::types() as $t) {
            $heli = in_array($t['category'], ['elicottero', 'convertiplano'], true);
            if ($class === 'helicopter' ? $heli : $t['category'] !== 'elicottero') {
                $out[] = $t['name'];
            }
        }
        return $out;
    }

    /** Tipi ammessi per ogni classe che ne ha (per la pagina). */
    public static function typesByClass(): array
    {
        $out = [];
        foreach (array_keys(self::CLASSES) as $c) {
            if ($types = self::typesFor($c)) {
                $out[$c] = $types;
            }
        }
        return $out;
    }

    /** Raggio minimo (frazione del lato maggiore) entro cui due posizioni sono lo stesso oggetto. */
    private const MIN_RADIUS = 0.004;
    /** Rapporto massimo fra i raggi di due posizioni dello stesso oggetto. */
    private const MAX_RADIUS_RATIO = 2.5;

    /**
     * Salva (o aggiorna, se c'è già una verifica nella stessa posizione) la
     * verifica di un oggetto.
     *
     * @param array $data verdict, class, type_name, polygon [[x,y]...] in
     *                    frazioni, source ('detection' | 'manual')
     * @return int id
     */
    public static function save(int $captureId, array $data): int
    {
        $capture = Capture::find($captureId);
        if (!$capture) {
            throw new InvalidArgumentException('Ripresa non trovata');
        }
        $verdict = $data['verdict'] ?? '';
        if (!is_string($verdict) || !in_array($verdict, self::VERDICTS, true)) {
            throw new InvalidArgumentException('Esito della verifica non valido');
        }
        $class = $data['class'] ?? null;
        if ($verdict === 'confirmed' && (!is_string($class) || !isset(self::CLASSES[$class]))) {
            throw new InvalidArgumentException('Classe non valida');
        }
        $typeIn = $data['type_name'] ?? '';
        if (!is_string($typeIn) && $typeIn !== null) {
            throw new InvalidArgumentException('Tipo non valido');
        }
        $type = trim((string) $typeIn);
        if ($type !== '' && $verdict === 'confirmed') {
            if (!in_array($type, array_column(AircraftCatalog::types(), 'name'), true)) {
                throw new InvalidArgumentException('Tipo non presente nella tabella dei velivoli');
            }
            if (!in_array($type, self::typesFor($class), true)) {
                throw new InvalidArgumentException('Tipo non valido per la classe "' . (self::CLASSES[$class] ?? $class) . '"');
            }
        }
        $polygon = self::polygon($data['polygon'] ?? null);
        if (!$polygon) {
            throw new InvalidArgumentException('Posizione dell\'oggetto non valida');
        }
        [$cx, $cy, $radius] = self::circle($polygon);
        $source = ($data['source'] ?? 'detection') === 'manual' ? 'manual' : 'detection';

        $existing = self::near($captureId, $cx, $cy, $radius);
        $db = Database::get();
        $values = [
            ':v' => $verdict,
            ':c' => $verdict === 'confirmed' ? $class : null,
            ':t' => $verdict === 'confirmed' && $type !== '' ? $type : null,
            ':x' => $cx, ':y' => $cy, ':r' => $radius,
            ':p' => json_encode($polygon),
            ':s' => $source,
        ];
        if ($existing) {
            $db->prepare("UPDATE detection_reviews SET verdict = :v, class = :c, type_name = :t, center_x = :x, center_y = :y, radius = :r,
                    polygon_json = :p, source = :s, updated_at = datetime('now') WHERE id = :id")
                ->execute($values + [':id' => $existing['id']]);
            $id = (int) $existing['id'];
        } else {
            $db->prepare('INSERT INTO detection_reviews (capture_id, study_id, verdict, class, type_name, center_x, center_y, radius, polygon_json, source)
                    VALUES (:cap, :st, :v, :c, :t, :x, :y, :r, :p, :s)')
                ->execute($values + [':cap' => $captureId, ':st' => (int) $capture['study_id']]);
            $id = (int) $db->lastInsertId();
        }
        self::refreshEmbeddings($captureId);
        return $id;
    }

    /**
     * Vettori della libreria visiva (VisualLibrary) per le verifiche con il
     * tipo confermato di una ripresa: quelli dell'oggetto del rilevamento
     * corrispondente; nessuno per le altre verifiche, o se l'oggetto non c'è
     * più. Da richiamare dopo ogni verifica e ogni nuovo rilevamento.
     */
    public static function refreshEmbeddings(int $captureId): void
    {
        $reviews = self::forCapture($captureId);
        if (!$reviews) {
            return;
        }
        $det = Detection::forCapture($captureId);
        $objects = $det['result']['objects'] ?? [];
        $vectors = $det['embeddings'] ?? [];
        $byReview = array_flip(self::match($objects, $reviews));
        // updated_at cambia con i vettori: la versione della libreria visiva
        // (e le sue cache) ne dipende.
        $set = Database::get()->prepare("UPDATE detection_reviews SET embedding_json = :e, updated_at = datetime('now')
            WHERE id = :id AND embedding_json IS NOT :e");
        foreach ($reviews as $j => $r) {
            if ($r['verdict'] !== 'confirmed' || empty($r['type_name'])) {
                // Non è (più) un riferimento.
                $set->execute([':e' => null, ':id' => $r['id']]);
                continue;
            }
            $n = isset($byReview[$j]) ? ($objects[$byReview[$j]]['n'] ?? null) : null;
            // Solo se il rilevamento attuale ha i vettori di quell'oggetto: un
            // rilevamento rieseguito senza (modello assente, tempo esaurito,
            // oggetto non più trovato) non deve cancellare un riferimento.
            if ($n !== null && !empty($vectors[$n])) {
                $set->execute([':e' => json_encode($vectors[$n]), ':id' => $r['id']]);
            }
        }
    }

    public static function delete(int $id): void
    {
        Database::get()->prepare('DELETE FROM detection_reviews WHERE id = :id')->execute([':id' => $id]);
    }

    /** @return array<int,array> verifiche della ripresa, con il poligono decodificato */
    public static function forCapture(int $captureId): array
    {
        $stmt = Database::get()->prepare('SELECT * FROM detection_reviews WHERE capture_id = :c ORDER BY id');
        $stmt->execute([':c' => $captureId]);
        return array_map([self::class, 'decode'], $stmt->fetchAll());
    }

    /** Riprese con almeno una verifica: id => verifiche. */
    public static function all(): array
    {
        $out = [];
        foreach (Database::get()->query('SELECT * FROM detection_reviews ORDER BY capture_id, id')->fetchAll() as $row) {
            $out[(int) $row['capture_id']][] = self::decode($row);
        }
        return $out;
    }

    public static function isComplete(int $captureId): bool
    {
        $stmt = Database::get()->prepare('SELECT complete FROM capture_reviews WHERE capture_id = :c');
        $stmt->execute([':c' => $captureId]);
        return (bool) $stmt->fetchColumn();
    }

    /** @return array<int,bool> riprese verificate per intero */
    public static function completeCaptures(): array
    {
        $out = [];
        foreach (Database::get()->query('SELECT capture_id FROM capture_reviews WHERE complete = 1')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $out[(int) $id] = true;
        }
        return $out;
    }

    public static function setComplete(int $captureId, bool $complete): void
    {
        Database::get()->prepare("INSERT INTO capture_reviews (capture_id, complete, updated_at) VALUES (:c, :v, datetime('now'))
                ON CONFLICT(capture_id) DO UPDATE SET complete = excluded.complete, updated_at = excluded.updated_at")
            ->execute([':c' => $captureId, ':v' => $complete ? 1 : 0]);
    }

    /**
     * Abbina oggetti di un rilevamento e verifiche: ciascuna verifica va al
     * più vicino oggetto entro il raggio di entrambi, uno a uno.
     *
     * @param array $objects oggetti (center, polygon in frazioni)
     * @return array<int,int> indice dell'oggetto => indice della verifica
     */
    public static function match(array $objects, array $reviews): array
    {
        $pairs = [];
        foreach ($objects as $i => $o) {
            $poly = self::polygon($o['polygon'] ?? null);
            if (!$poly) {
                continue;
            }
            [$ox, $oy, $or] = self::circle($poly);
            foreach ($reviews as $j => $r) {
                $d = self::sameObject($ox, $oy, $or, $r);
                if ($d !== null) {
                    $pairs[] = [$d, $i, $j];
                }
            }
        }
        usort($pairs, fn($a, $b) => $a[0] <=> $b[0]);
        $out = $usedR = [];
        foreach ($pairs as [$_d, $i, $j]) {
            if (!isset($out[$i]) && !isset($usedR[$j])) {
                $out[$i] = $j;
                $usedR[$j] = true;
            }
        }
        return $out;
    }

    /**
     * Distanza fra una posizione e una verifica se sono lo stesso oggetto,
     * altrimenti null: raggi confrontabili (un veicolo segnato sotto l'ala
     * non è l'aereo) e centri entro il 60% del raggio minore.
     */
    private static function sameObject(float $x, float $y, float $radius, array $review): ?float
    {
        $rr = (float) $review['radius'];
        if (max($radius, $rr) > self::MAX_RADIUS_RATIO * min($radius, $rr)) {
            return null;
        }
        $d = hypot($x - (float) $review['center_x'], $y - (float) $review['center_y']);
        return $d <= 0.6 * min($radius, $rr) ? $d : null;
    }

    /**
     * Poligono valido (punti di due numeri, nell'immagine o poco fuori) o
     * null. I punti fuori dall'immagine (un riquadro ruotato al bordo di un
     * ritaglio piccolo) vengono riportati sul bordo.
     */
    private static function polygon($p): ?array
    {
        if (!is_array($p)) {
            return null;
        }
        $out = [];
        foreach ($p as $pt) {
            if (!is_array($pt) || count($pt) < 2 || !is_numeric($pt[0] ?? null) || !is_numeric($pt[1] ?? null)) {
                return null;
            }
            $x = (float) $pt[0];
            $y = (float) $pt[1];
            if (!is_finite($x) || !is_finite($y) || $x < -1 || $x > 2 || $y < -1 || $y > 2) {
                return null;
            }
            $out[] = [round(max(0.0, min(1.0, $x)), 6), round(max(0.0, min(1.0, $y)), 6)];
        }
        return $out ?: null;
    }

    /** Centro e raggio (metà del lato maggiore del rettangolo che lo contiene). */
    private static function circle(array $polygon): array
    {
        $xs = array_column($polygon, 0);
        $ys = array_column($polygon, 1);
        return [
            (min($xs) + max($xs)) / 2,
            (min($ys) + max($ys)) / 2,
            max(self::MIN_RADIUS, max(max($xs) - min($xs), max($ys) - min($ys)) / 2),
        ];
    }

    /** La verifica dello stesso oggetto più vicina, se c'è. */
    private static function near(int $captureId, float $x, float $y, float $radius): ?array
    {
        $best = null;
        $bestD = INF;
        foreach (self::forCapture($captureId) as $r) {
            $d = self::sameObject($x, $y, $radius, $r);
            if ($d !== null && $d < $bestD) {
                $best = $r;
                $bestD = $d;
            }
        }
        return $best;
    }

    private static function decode(array $row): array
    {
        $row['polygon'] = json_decode($row['polygon_json'] ?? 'null', true) ?: [];
        // I vettori restano sul server (vedi VisualLibrary::references).
        $row['has_embedding'] = !empty($row['embedding_json']);
        unset($row['polygon_json'], $row['embedding_json']);
        return $row;
    }
}
