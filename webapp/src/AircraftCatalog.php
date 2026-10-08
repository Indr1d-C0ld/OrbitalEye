<?php

/**
 * Identificazione di un velivolo dalle sue dimensioni misurate sulla ripresa.
 *
 * Tabella locale (aircraft_types.json, nessuna richiesta esterna): 166 tipi
 * militari e civili frequenti nelle basi — caccia, addestratori,
 * bombardieri, trasporti e aerocisterne, sorveglianza, executive, linea,
 * droni, elicotteri — con apertura alare, lunghezza e, per gli elicotteri,
 * diametro del rotore. I valori vengono dalle schede tecniche delle voci
 * Wikipedia di ciascun tipo (campo "wiki"); quelli marcati "manuale" sono
 * stati inseriti a mano dove la voce non ha una scheda standard (es. varianti
 * di linea) e indicano la variante nel nome. Per i velivoli a geometria
 * variabile c'è anche l'apertura con ali a freccia (span_min_m), la
 * configurazione tipica a terra. "wing" è la forma delle ali (freccia,
 * dritta, delta, geometria variabile, tutt'ala, rotore), assegnata a mano.
 *
 * La compatibilità tiene conto dell'errore di misura: un'incertezza
 * assoluta legata alla risoluzione (gli estremi di una misura si
 * posizionano a ±1–2 pixel) e una relativa (varianti, ripresa non
 * perfettamente verticale). I lati di un riquadro del rilevamento
 * automatico sono invece un limite superiore: vedi BOX_CENTER.
 */
final class AircraftCatalog
{
    private static ?array $types = null;

    /** Incertezza in pixel per estremo di una misura fatta a mano / di un riquadro automatico. */
    private const PX_MEASURE = 1.5;
    private const PX_BOX = 2.5;
    private const REL_MEASURE = 0.04;
    /**
     * Riquadro automatico: il velivolo ci sta dentro, ma il riquadro include
     * margine e spesso l'ombra (su Sigonella un'apertura vera di ~33 m dava
     * un lato di 43,7 m). Le dimensioni attese sono quindi intorno all'87%
     * del lato, con tolleranza ampia verso il basso e stretta verso l'alto:
     * un tipo più grande del riquadro non può esserci dentro.
     */
    private const BOX_CENTER = 0.87;
    private const BOX_REL_BELOW = 0.12;
    private const BOX_REL_ABOVE = 0.07;
    /** Lunghezza degli elicotteri: nelle schede a volte con i rotori, a volte senza. */
    private const REL_HELI_LENGTH = 0.18;
    /** Oltre questa distanza normalizzata (≈ 3 deviazioni standard) un tipo non è compatibile. */
    private const MAX_DISTANCE = 3.0;

    public static function types(): array
    {
        if (self::$types === null) {
            $json = json_decode((string) @file_get_contents(__DIR__ . '/aircraft_types.json'), true);
            self::$types = is_array($json) ? $json : [];
        }
        return self::$types;
    }

    public static function categories(): array
    {
        return array_values(array_unique(array_column(self::types(), 'category')));
    }

    /** Forme d'ala degli aerei (non elicotteri) presenti in tabella. */
    public static function wings(): array
    {
        return array_values(array_diff(array_unique(array_column(self::types(), 'wing')), ['rotore', 'rotori basculanti']));
    }

    /**
     * Tipi compatibili con le dimensioni misurate.
     *
     * @param float|null $span   apertura alare (o diametro del rotore), m
     * @param float|null $length lunghezza, m
     * @param float|null $mpp    metri/pixel della ripresa (incertezza di risoluzione)
     * @param string     $mode   'measure' (misure fatte a mano, dimensioni ordinate) o
     *                           'box' (lati del riquadro di un rilevamento: non si sa quale sia l'apertura)
     * @param string|null $filter categoria, oppure 'ala_fissa' / 'elicottero'
     * @param string|null $wing   forma delle ali (freccia, dritta, delta, geometria variabile, tutt'ala)
     * @return array<int,array> dal più compatibile, al massimo $limit
     */
    public static function match(?float $span, ?float $length, ?float $mpp, string $mode = 'measure', ?string $filter = null, int $limit = 10, ?string $wing = null): array
    {
        if (!$span && !$length) {
            return [];
        }
        $box = $mode === 'box';
        $px = ($box ? self::PX_BOX : self::PX_MEASURE) * max(0.0, (float) $mpp);
        $rel = self::REL_MEASURE;
        $sigma = fn(float $v, float $relative) => sqrt($px * $px + ($relative * $v) ** 2) ?: 0.01;
        // Scarto normalizzato fra una dimensione misurata e quella di riferimento.
        $z = function (float $measured, float $ref, float $relative) use ($box, $px, $sigma): float {
            if (!$box) {
                return ($measured - $ref) / $sigma($ref, $relative);
            }
            $center = self::BOX_CENTER * $measured;
            $r = $ref > $center ? self::BOX_REL_ABOVE : max(self::BOX_REL_BELOW, $relative);
            return ($ref - $center) / (sqrt($px * $px + ($r * $measured) ** 2) ?: 0.01);
        };

        $out = [];
        foreach (self::types() as $t) {
            $isHeli = $t['category'] === 'elicottero';
            if ($filter === 'ala_fissa' && $isHeli) {
                continue;
            }
            if ($filter === 'elicottero' && !$isHeli) {
                continue;
            }
            if ($filter && !in_array($filter, ['ala_fissa', 'elicottero'], true) && $t['category'] !== $filter) {
                continue;
            }
            // Forma delle ali, visibile sulla ripresa: il criterio che separa
            // tipi con dimensioni simili (un jet e un turboelica di 35 m).
            if ($wing && !$isHeli && ($t['wing'] ?? '') !== $wing) {
                continue;
            }

            // Dimensioni di riferimento: [larghezza, lunghezza, nota]. Per la
            // larghezza di un elicottero si usa il rotore; per la geometria
            // variabile si prova anche ad ali a freccia.
            $refs = [];
            if ($isHeli) {
                if (empty($t['rotor_m'])) {
                    continue;
                }
                $refs[] = [(float) $t['rotor_m'], isset($t['length_m']) ? (float) $t['length_m'] : null, ''];
            } else {
                if (empty($t['span_m'])) {
                    continue;
                }
                $refs[] = [(float) $t['span_m'], isset($t['length_m']) ? (float) $t['length_m'] : null, ''];
                if (!empty($t['span_min_m'])) {
                    $refs[] = [(float) $t['span_min_m'], isset($t['length_m']) ? (float) $t['length_m'] : null, 'ali a freccia'];
                }
            }
            $lenRel = $isHeli ? max($rel, self::REL_HELI_LENGTH) : $rel;

            $best = null;
            foreach ($refs as [$refW, $refL, $note]) {
                // Riquadro automatico: i due lati possono essere apertura e
                // lunghezza in un ordine o nell'altro.
                $pairs = $box && $span && $length ? [[$span, $length], [$length, $span]] : [[$span, $length]];
                foreach ($pairs as [$w, $l]) {
                    $sum = 0.0;
                    $n = 0;
                    if ($w) {
                        $sum += $z($w, $refW, $rel) ** 2;
                        $n++;
                    }
                    if ($l && $refL) {
                        $sum += $z($l, $refL, $lenRel) ** 2;
                        $n++;
                    }
                    if ($n === 0) {
                        continue;
                    }
                    $d = sqrt($sum / $n) * ($n === 2 ? 1.0 : 1.15); // una sola dimensione: meno informativa
                    if ($best === null || $d < $best['distance']) {
                        $best = ['distance' => $d, 'note' => $note, 'w' => $w, 'l' => $l, 'refW' => $refW, 'refL' => $refL];
                    }
                }
            }
            if ($best === null || $best['distance'] > self::MAX_DISTANCE) {
                continue;
            }
            $out[] = [
                'name' => $t['name'],
                'maker' => $t['maker'] ?? '',
                'category' => $t['category'],
                'wing' => $t['wing'] ?? null,
                'span_m' => $t['span_m'] ?? null,
                'span_min_m' => $t['span_min_m'] ?? null,
                'length_m' => $t['length_m'] ?? null,
                'rotor_m' => $t['rotor_m'] ?? null,
                'source' => $t['source'] ?? '',
                'wiki_url' => 'https://en.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $t['wiki'] ?? $t['name'])),
                'compatibility' => round(exp(-($best['distance'] ** 2) / 2), 3),
                'distance' => round($best['distance'], 3),
                'note' => $best['note'],
                'measured_as' => ['width' => $best['w'] ? round($best['w'], 2) : null, 'length' => $best['l'] ? round($best['l'], 2) : null],
            ];
        }
        usort($out, fn($a, $b) => $a['distance'] <=> $b['distance']);
        return array_slice($out, 0, $limit);
    }

    /** Nome completo di un tipo ("Lockheed Martin F-35 Lightning II"). */
    public static function fullName(array $type): string
    {
        return trim(($type['maker'] ?? '') . ' ' . $type['name']);
    }

    /**
     * Un'etichetta (di annotazione) corrisponde a un tipo della tabella? Usato
     * dallo storico dell'area per contare i tipi identificati.
     */
    public static function typeForLabel(?string $label): ?string
    {
        $label = mb_strtolower(trim((string) $label));
        if ($label === '') {
            return null;
        }
        $bestName = null;
        $bestLen = 0;
        foreach (self::types() as $t) {
            // Nome completo ("p-8 poseidon") o sigla iniziale con cifre
            // ("p-8", "c-130j-30"), come parola intera: "uh-60" non deve
            // valere "H-6", né "f-22" valere "F-2".
            $keys = [mb_strtolower($t['name'])];
            $first = explode(' ', $keys[0])[0];
            if ($first !== $keys[0] && preg_match('/\d/', $first)) {
                $keys[] = $first;
            }
            foreach ($keys as $k) {
                // Ammessa una lettera di variante finale ("ah-64d", "f-16c").
                $re = '/(?<![\p{L}\p{N}-])' . preg_quote($k, '/') . '(?!\p{N}|\p{L}{2}|-\p{N})/u';
                if (mb_strlen($k) > $bestLen && preg_match($re, $label)) {
                    $bestName = $t['name'];
                    $bestLen = mb_strlen($k);
                }
            }
        }
        return $bestName;
    }
}
