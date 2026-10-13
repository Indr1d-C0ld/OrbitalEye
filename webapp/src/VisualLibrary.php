<?php

/**
 * Libreria visiva: i velivoli identificati dall'analista (verifiche con il
 * tipo confermato, vedi DetectionReview) sono i riferimenti; per ogni aereo o
 * elicottero rilevato si cercano i più simili, e il loro tipo è un'ipotesi
 * che non dipende dalle dimensioni. Separa tipi di misure quasi identiche
 * (A400M e B-1 ad ali aperte, A330 e 787) che la tabella dei tipi
 * (AircraftCatalog) non può distinguere.
 *
 * I vettori vengono dal servizio di analisi (python-service/app/core/
 * embed.py, DINOv2 in locale): uno per orientamento, perché il muso può
 * stare da una parte o dall'altra. La somiglianza fra due oggetti è il
 * massimo prodotto scalare fra i loro vettori (coseno: 1 = identici).
 *
 * Su 24 aerei di 6 tipi il più simile preso da un'altra ripresa era dello
 * stesso tipo in 20 casi su 20; la pagina "Qualità del rilevamento" misura
 * la libreria sui riferimenti veri (leaveOneOut).
 */
final class VisualLibrary
{
    /** Tipi proposti per oggetto. */
    public const SUGGESTIONS = 3;

    /**
     * Riferimenti usati per tipo, i più recenti: il costo del confronto cresce
     * con i riferimenti, e qualche decina per tipo basta a rappresentarlo.
     */
    public const MAX_PER_TYPE = 20;

    /**
     * Distacco minimo (in coseno) fra il tipo più simile e il secondo perché
     * il suggerimento sia "chiaro" (★ sul candidato per misure, niente
     * "incerto"). Su Brize Norton i tipi giusti staccavano il secondo di
     * 0,07-0,18; un tipo mai identificato (un C-17 confrontato con A330 e
     * A400M) di 0,04.
     */
    public const CLEAR_MARGIN = 0.05;

    private static ?array $cache = null;
    private static ?string $versionCache = null;

    /**
     * Riferimenti: verifiche confermate con tipo e vettori.
     *
     * @return array<int,array{id:int, capture_id:int, type:string, vectors:array, label:string, study:string, date:?string}>
     */
    public static function references(bool $refresh = false): array
    {
        if (self::$cache !== null && !$refresh) {
            return self::$cache;
        }
        $rows = Database::get()->query(
            "SELECT r.id, r.capture_id, r.type_name, r.embedding_json, s.title AS study
               FROM detection_reviews r JOIN studies s ON s.id = r.study_id
              WHERE r.verdict = 'confirmed' AND r.type_name IS NOT NULL AND r.embedding_json IS NOT NULL
              ORDER BY r.id DESC"
        )->fetchAll();
        $out = [];
        $perType = [];
        $captureInfo = [];
        foreach ($rows as $row) {
            if (($perType[$row['type_name']] ?? 0) >= self::MAX_PER_TYPE) {
                continue;
            }
            $vectors = array_values(array_filter(array_map([self::class, 'decode'], json_decode($row['embedding_json'], true) ?: [])));
            if (!$vectors) {
                continue;
            }
            $perType[$row['type_name']] = ($perType[$row['type_name']] ?? 0) + 1;
            $cid = (int) $row['capture_id'];
            $captureInfo[$cid] ??= self::captureInfo($cid);
            $out[] = ['id' => (int) $row['id'], 'capture_id' => $cid, 'type' => $row['type_name'], 'vectors' => $vectors,
                'study' => $row['study']] + $captureInfo[$cid];
        }
        return self::$cache = $out;
    }

    /**
     * Etichetta, data reale dell'immagine e "acquisizione" di una ripresa:
     * copie, ritagli e riscaricamenti della stessa foto hanno la stessa
     * (ImageryAttribution::forCapture), e nella valutazione non devono
     * contare come riprese diverse.
     */
    private static function captureInfo(int $captureId): array
    {
        $c = Capture::find($captureId);
        if (!$c) {
            return ['label' => 'Ripresa #' . $captureId, 'date' => null, 'acquisition' => 'cap:' . $captureId];
        }
        $info = ImageryAttribution::forCapture($c);
        return [
            'label' => $c['label'] ?: ('Ripresa #' . $captureId),
            'date' => $info['date'] ?? ($c['capture_date'] ?: null),
            'acquisition' => $info['acquisition_key'] ?? ('cap:' . $captureId),
        ];
    }

    /**
     * Versione della libreria: cambia quando si aggiunge, toglie o modifica
     * un riferimento. Le cache (suggerimenti per rilevamento, valutazione)
     * valgono finché non cambia.
     */
    public static function version(bool $refresh = false): string
    {
        if (self::$versionCache !== null && !$refresh) {
            return self::$versionCache;
        }
        $row = Database::get()->query(
            "SELECT COUNT(*) AS n, MAX(id) AS m, MAX(updated_at) AS u FROM detection_reviews
              WHERE verdict = 'confirmed' AND type_name IS NOT NULL AND embedding_json IS NOT NULL"
        )->fetch();
        // updated_at cambia anche quando cambiano i vettori (refreshEmbeddings).
        return self::$versionCache = md5(implode('|', [$row['n'], $row['m'], $row['u'], self::MAX_PER_TYPE]));
    }

    /** Vettore int8 in base64 (embed.py) → numeri normalizzati, o null. */
    public static function decode($b64): ?array
    {
        if (!is_string($b64) || ($raw = base64_decode($b64, true)) === false || $raw === '') {
            return null;
        }
        $v = array_values(unpack('c*', $raw));
        $norm = sqrt(array_sum(array_map(fn($x) => $x * $x, $v)));
        return $norm > 0 ? array_map(fn($x) => $x / $norm, $v) : null;
    }

    /** Somiglianza fra due oggetti: il massimo coseno fra i loro orientamenti. */
    public static function similarity(array $a, array $b): float
    {
        $best = -1.0;
        foreach ($a as $va) {
            foreach ($b as $vb) {
                $n = min(count($va), count($vb));
                $dot = 0.0;
                for ($k = 0; $k < $n; $k++) {
                    $dot += $va[$k] * $vb[$k];
                }
                $best = max($best, $dot);
            }
        }
        return $best;
    }

    /**
     * Tipi più simili: per ogni tipo il riferimento più vicino, dal più
     * simile.
     *
     * @param array $vectors vettori dell'oggetto (già decodificati)
     * @param array<int,bool> $excludeIds riferimenti da non usare (l'oggetto stesso)
     * @return array<int,array{type:string, similarity:float, capture_id:int, label:string, study:string, date:?string}>
     */
    public static function suggest(array $vectors, array $references, array $excludeIds = [], int $limit = self::SUGGESTIONS): array
    {
        $byType = [];
        foreach ($references as $ref) {
            if (isset($excludeIds[$ref['id']])) {
                continue;
            }
            $sim = self::similarity($vectors, $ref['vectors']);
            if (!isset($byType[$ref['type']]) || $sim > $byType[$ref['type']]['similarity']) {
                $byType[$ref['type']] = ['type' => $ref['type'], 'similarity' => round($sim, 3), 'capture_id' => $ref['capture_id'],
                    'label' => $ref['label'], 'study' => $ref['study'], 'date' => $ref['date']];
            }
        }
        usort($byType, fn($x, $y) => $y['similarity'] <=> $x['similarity']);
        return array_slice($byType, 0, $limit);
    }

    /**
     * Aggiunge a ogni aereo o elicottero del rilevamento i tipi più simili
     * ("visual"), escluso il riferimento che è l'oggetto stesso (la sua
     * verifica). Senza riferimenti o senza vettori non aggiunge nulla.
     */
    public static function annotate(array $det): array
    {
        $vectors = $det['embeddings'] ?? [];
        if (!$vectors || empty($det['result']['objects'])) {
            return $det;
        }
        // Calcolati una volta per versione della libreria (e per verifiche
        // della ripresa, che decidono quale riferimento è l'oggetto stesso).
        $reviews = DetectionReview::forCapture((int) $det['capture_id']);
        $key = self::version() . ':' . md5(json_encode(array_map(fn($r) => [$r['id'], $r['updated_at']], $reviews)));
        $cache = $det['visual_cache'] ?? null;
        if (!is_array($cache) || ($cache['key'] ?? '') !== $key) {
            $refs = self::references();
            $own = DetectionReview::match($det['result']['objects'], $reviews);
            $byN = [];
            foreach ($det['result']['objects'] as $i => $o) {
                $vs = array_values(array_filter(array_map([self::class, 'decode'], $vectors[$o['n'] ?? -1] ?? [])));
                if (!$vs || !$refs) {
                    continue;
                }
                $exclude = isset($own[$i]) ? [(int) $reviews[$own[$i]]['id'] => true] : [];
                $list = self::suggest($vs, $refs, $exclude);
                // Chiaro solo se il primo tipo stacca il secondo; con un solo
                // tipo in libreria non c'è termine di paragone.
                $clear = count($list) >= 2 && $list[0]['similarity'] - $list[1]['similarity'] >= self::CLEAR_MARGIN;
                foreach ($list as $k => &$v) {
                    $v['clear'] = $k === 0 && $clear;
                }
                unset($v);
                $byN[$o['n']] = $list;
            }
            $cache = ['key' => $key, 'objects' => $byN];
            if (!empty($det['id'])) {
                Detection::storeVisual((int) $det['id'], $cache);
            }
        }
        foreach ($det['result']['objects'] as &$o) {
            if (isset($cache['objects'][$o['n'] ?? -1])) {
                $o['visual'] = $cache['objects'][$o['n']];
            }
        }
        unset($o);
        return $det;
    }

    /**
     * Quanto è giusta la libreria sui riferimenti stessi: per ciascuno, i
     * tipi suggeriti dagli ALTRI riferimenti di riprese diverse (quelli della
     * stessa ripresa, stesso sole e stessa immagine, la farebbero sembrare
     * migliore di quanto è). Conta solo i riferimenti di tipi presenti anche
     * altrove.
     *
     * Riprese "diverse" vuol dire foto diverse: copie, ritagli e
     * riscaricamenti della stessa acquisizione contano come la stessa.
     *
     * @return array{n:int, top1:int, top3:int, absent:int}
     */
    public static function leaveOneOut(array $references): array
    {
        $stats = ['n' => 0, 'top1' => 0, 'top3' => 0, 'absent' => 0];
        foreach ($references as $ref) {
            $others = array_values(array_filter($references, fn($r) => $r['acquisition'] !== $ref['acquisition']));
            if (!array_filter($others, fn($r) => $r['type'] === $ref['type'])) {
                continue;
            }
            $types = array_column(self::suggest($ref['vectors'], $others, [], 3), 'type');
            $rank = array_search($ref['type'], $types, true);
            $stats['n']++;
            if ($rank === false) {
                $stats['absent']++;
                continue;
            }
            if ($rank === 0) {
                $stats['top1']++;
            }
            $stats['top3']++;
        }
        return $stats;
    }

    /** leaveOneOut sulla libreria attuale, ricalcolata solo quando la libreria cambia. */
    public static function evaluation(): array
    {
        $version = self::version();
        $cached = json_decode((string) AppSettings::get('_visual_library_eval'), true);
        if (is_array($cached) && ($cached['version'] ?? '') === $version && isset($cached['stats'])) {
            return $cached['stats'];
        }
        $stats = self::leaveOneOut(self::references());
        AppSettings::set('_visual_library_eval', json_encode(['version' => $version, 'stats' => $stats]));
        return $stats;
    }
}
