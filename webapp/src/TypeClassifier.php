<?php

/**
 * Classificatore dei tipi MAR20 (python-service/app/core/typeclass.py): per
 * ogni aereo rilevato il servizio restituisce i tre tipi più probabili fra i
 * 20 di MAR20 (militari, soprattutto statunitensi e russi). Qui si decide
 * quando l'indizio è attendibile.
 *
 * Il classificatore conosce solo quei 20 tipi: un aereo di un altro tipo
 * riceve comunque uno dei 20, a volte con probabilità alta (su Brize Norton
 * A330 come C-5 o E-8, A400M come C-17). La somiglianza agli esempi di
 * MAR20 non li separa; le misure sì (l'A330 ha 60 m di apertura, il C-5
 * 68). L'indizio è quindi attendibile solo se il tipo è molto probabile E
 * le misure sono vicine a quelle del tipo (distanza di AircraftCatalog).
 *
 * Su 42 aerei di riprese Esri reali (Mildenhall, Brize Norton oggi e nel
 * 2021: 13 di tipi MAR20, 29 di altri tipi o frammenti) con queste soglie: 8
 * tipi giusti, nessuno sbagliato, nessun aereo di altro tipo scambiato per
 * uno dei 20. Campione piccolo: è un indizio, non un verdetto.
 *
 * MAR20: W. Yu, G. Cheng et al., "MAR20: A Benchmark for Military Aircraft
 * Recognition in Remote Sensing Images", 2022; licenza CC BY-NC 4.0.
 */
final class TypeClassifier
{
    /** Probabilità minima del primo tipo. */
    public const MIN_P = 0.7;
    /** Distanza massima delle misure da quelle del tipo (AircraftCatalog::match). */
    public const MAX_DISTANCE = 1.5;

    /** I 20 tipi di MAR20, con i nomi della tabella dei velivoli. */
    public const NAMES = ['Su-35', 'C-130 Hercules', 'C-17 Globemaster III', 'C-5 Galaxy', 'F-16 Fighting Falcon', 'Tu-160',
        'E-3 Sentry', 'B-52 Stratofortress', 'P-3 Orion', 'B-1 Lancer', 'E-8 Joint STARS', 'Tu-22M',
        'F-15 Eagle', 'KC-135 Stratotanker', 'F-22 Raptor', 'F/A-18 Hornet', 'Tu-95', 'KC-10 Extender',
        'Su-34', 'Su-24'];

    /**
     * Varianti che MAR20 non separa dal tipo (stessa cellula, stesso
     * aspetto dall'alto): la classe "C-130" comprende anche i C-130J. Le
     * misure si confrontano con la variante più vicina, e una verifica con
     * la variante conta come tipo giusto.
     */
    public const FAMILIES = [
        'C-130 Hercules' => ['C-130J Super Hercules', 'C-130J-30 Super Hercules', 'EC-130H Compass Call'],
        'F-15 Eagle' => ['F-15E Strike Eagle'],
        'F/A-18 Hornet' => ['F/A-18E/F Super Hornet'],
        'KC-135 Stratotanker' => ['RC-135'],
        'Su-35' => ['Su-27', 'Su-30'],
    ];

    /** Il tipo e le sue varianti. */
    public static function members(string $type): array
    {
        return array_merge([$type], self::FAMILIES[$type] ?? []);
    }

    /** Il tipo $actual (della tabella) è il tipo MAR20 $guess o una sua variante? */
    public static function sameType(string $guess, string $actual): bool
    {
        return in_array($actual, self::members($guess), true);
    }

    /** Il tipo $actual è fra quelli che il classificatore conosce? */
    public static function known(string $actual): bool
    {
        foreach (self::NAMES as $n) {
            if (self::sameType($n, $actual)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Tipi proposti dal servizio, con la distanza delle misure da ciascuno
     * (la variante più vicina; null: misure incompatibili o assenti) e
     * l'attendibilità del primo.
     *
     * @param array $guesses [{type, p}] dal servizio
     * @param array $matches tutti i tipi compatibili con le misure (AircraftCatalog::match)
     * @return array{types: array, reliable: bool, reason: ?string}|null
     */
    public static function assess($guesses, array $matches): ?array
    {
        if (!is_array($guesses) || !$guesses) {
            return null;
        }
        $distance = array_column($matches, 'distance', 'name');
        $types = [];
        foreach ($guesses as $g) {
            if (!is_array($g) || !is_string($g['type'] ?? null) || !is_numeric($g['p'] ?? null)) {
                continue;
            }
            $d = array_filter(array_map(fn($n) => $distance[$n] ?? null, self::members($g['type'])), fn($v) => $v !== null);
            $d = $d ? (float) min($d) : null;
            $types[] = ['type' => $g['type'], 'p' => (float) $g['p'], 'distance' => $d,
                // Come per i candidati: 100% = misure identiche a quelle del tipo.
                'compatibility' => $d === null ? null : round(exp(-($d ** 2) / 2), 3)];
        }
        if (!$types) {
            return null;
        }
        $top = $types[0];
        $reason = null;
        if ($top['p'] < self::MIN_P) {
            $reason = 'probabilità bassa';
        } elseif ($top['distance'] === null || $top['distance'] > self::MAX_DISTANCE) {
            $reason = 'misure poco compatibili con il tipo';
        }
        return ['types' => $types, 'reliable' => $reason === null, 'reason' => $reason];
    }

    /**
     * Rivaluta l'indizio salvato con un oggetto rilevato con le soglie e la
     * tabella dei velivoli attuali (le stesse misure di DetectionRunner:
     * sagoma se attendibile, altrimenti lati del riquadro).
     */
    public static function forObject(array $object, ?float $mppMean): ?array
    {
        $types = $object['type_guess']['types'] ?? null;
        if (!is_array($types) || !$types) {
            return null;
        }
        $sil = $object['silhouette'] ?? null;
        $matches = [];
        if ($sil && ($sil['quality'] ?? '') === 'ok' && !empty($sil['span_m']) && !empty($sil['length_m'])) {
            $matches = AircraftCatalog::match((float) $sil['span_m'], (float) $sil['length_m'], $mppMean, 'silhouette', 'ala_fissa', PHP_INT_MAX);
        } elseif (!empty($object['size_m'][0]) && !empty($object['size_m'][1])) {
            $matches = AircraftCatalog::match((float) $object['size_m'][0], (float) $object['size_m'][1], $mppMean, 'box', 'ala_fissa', PHP_INT_MAX);
        }
        return self::assess($types, $matches);
    }
}
