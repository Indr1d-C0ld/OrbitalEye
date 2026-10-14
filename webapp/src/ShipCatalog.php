<?php

/**
 * Classi navali compatibili con le misure dello scafo (python-service/app/
 * core/hull.py), come AircraftCatalog per i velivoli.
 *
 * Tabella locale (ship_types.json, nessuna richiesta esterna): classi di
 * navi militari e ausiliarie in servizio (o ritirate di recente, come dice
 * la nota della riga) nelle principali marine, con
 * lunghezza fuori tutto e larghezza massima dalle schede (infobox) delle voci
 * della Wikipedia in inglese, testi CC BY-SA 4.0: ogni riga cita la voce e la
 * revisione letta. Per portaerei e navi d'assalto c'è anche la larghezza del
 * ponte di volo, dove la voce la riporta: dall'alto si vede quella, non la
 * larghezza al galleggiamento.
 *
 * La lunghezza è la misura forte (lo scafo si separa bene dall'acqua lungo
 * l'asse); la larghezza è più incerta (banchine, navi affiancate, ponti che
 * sporgono) e pesa meno. Con la larghezza poco attendibile (qualità
 * "media" dello scafo) questa conta con una tolleranza molto larga: separa
 * un sottomarino (~10–13 m) da una fregata (~20 m), non due fregate.
 *
 * Sulle riprese di prova una portaerei classe Ford a Norfolk (340,7 × 80,8
 * m) ha come prima classe "Gerald R. Ford"; navi di ~155 m hanno molte
 * classi compatibili (Arleigh Burke, Type 45, Type 052C...): le misure
 * riducono i candidati a un gruppo, l'identificazione resta dell'analista.
 */
final class ShipCatalog
{
    private static ?array $types = null;

    /** Incertezza in pixel per estremo della misura dello scafo / di un riquadro. */
    private const PX_HULL = 2.0;
    private const PX_BOX = 3.0;
    /** Incertezze relative: lunghezza, larghezza (varianti, sovrastrutture, ripresa obliqua). */
    private const REL_LENGTH = 0.05;
    private const REL_BEAM = 0.15;
    /** Larghezza indicativa (scafo di qualità "media"): conta poco, ma separa un sottomarino da una fregata. */
    private const REL_BEAM_LOOSE = 0.35;
    /**
     * Riquadro del rilevamento: sulle prove (Norfolk, Portsmouth, Taranto) la
     * lunghezza dello scafo era fra 0,99 e 1,03 del lato lungo del riquadro;
     * tolleranza ampia, perché il riquadro della passata per gli oggetti
     * grandi può essere più lungo del 20%.
     */
    private const BOX_CENTER = 1.0;
    private const REL_BOX = 0.12;
    /** Oltre questa distanza normalizzata (≈ 3 deviazioni standard) una classe non è compatibile. */
    private const MAX_DISTANCE = 3.0;

    public static function types(): array
    {
        if (self::$types === null) {
            $json = json_decode((string) @file_get_contents(__DIR__ . '/ship_types.json'), true);
            self::$types = is_array($json['types'] ?? null) ? $json['types'] : [];
        }
        return self::$types;
    }

    public static function names(): array
    {
        return array_column(self::types(), 'name');
    }

    /**
     * Classi compatibili, dalla più vicina.
     *
     * @param string $mode 'hull' (lunghezza e larghezza dello scafo), 'hull_length'
     *                     (larghezza poco attendibile: conta con una tolleranza molto
     *                     larga) o 'box' (lati del riquadro)
     * @return array<int,array{name:string, category:string, navies:array, length_m:float, beam_m:?float, deck_width_m:?float, compatibility:float, distance:float, wiki_url:string}>
     */
    public static function match(?float $length, ?float $beam, ?float $mpp, string $mode = 'hull', int $limit = 10): array
    {
        if (!$length || $length <= 0) {
            return [];
        }
        $box = $mode === 'box';
        $px = ($box ? self::PX_BOX : self::PX_HULL) * max(0.0, (float) $mpp);
        $useBeam = $beam && $beam > 0;
        $relBeam = $mode === 'hull_length' ? self::REL_BEAM_LOOSE : self::REL_BEAM;
        $out = [];
        foreach (self::types() as $t) {
            if (empty($t['length_m'])) {
                continue;
            }
            $refL = (float) $t['length_m'];
            // Larghezza visibile dall'alto: il ponte di volo, se più largo.
            $refB = max((float) ($t['beam_m'] ?? 0), (float) ($t['deck_width_m'] ?? 0)) ?: null;
            if ($box) {
                $zL = ($refL - self::BOX_CENTER * $length) / (sqrt($px ** 2 + (self::REL_BOX * $length) ** 2) ?: 0.01);
                $zB = $useBeam && $refB ? ($refB - self::BOX_CENTER * $beam) / (sqrt($px ** 2 + (self::REL_BOX * 1.5 * $beam) ** 2) ?: 0.01) : null;
            } else {
                $zL = ($length - $refL) / (sqrt($px ** 2 + (self::REL_LENGTH * $refL) ** 2) ?: 0.01);
                $zB = $useBeam && $refB ? ($beam - $refB) / (sqrt($px ** 2 + ($relBeam * $refB) ** 2) ?: 0.01) : null;
            }
            $d = $zB === null ? abs($zL) * 1.15 : sqrt(($zL ** 2 + $zB ** 2) / 2);
            // Una larghezza perfetta non deve salvare una lunghezza molto
            // diversa: la lunghezza ha anche un limite suo.
            if ($d > self::MAX_DISTANCE || abs($zL) > self::MAX_DISTANCE) {
                continue;
            }
            $out[] = [
                'name' => $t['name'],
                'category' => $t['category'],
                'navies' => $t['navies'] ?? [],
                'length_m' => $refL,
                'beam_m' => isset($t['beam_m']) ? (float) $t['beam_m'] : null,
                'deck_width_m' => isset($t['deck_width_m']) ? (float) $t['deck_width_m'] : null,
                'compatibility' => round(exp(-($d ** 2) / 2), 3),
                'distance' => round($d, 3),
                'wiki_url' => 'https://en.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $t['wiki'] ?? $t['name'])),
            ];
        }
        usort($out, fn($a, $b) => $a['distance'] <=> $b['distance']);
        return array_slice($out, 0, $limit);
    }
}
