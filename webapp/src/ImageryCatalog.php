<?php

/**
 * Passaggi dei satelliti Copernicus su un'area (catalogo del servizio di
 * analisi, vedi python-service/app/core/sentinelhub_client.py) e scelta del
 * passaggio da scaricare.
 *
 * Ogni ripresa Sentinel corrisponde a UN passaggio, con data e ora certe:
 * il vecchio mosaico "meno nuvoloso di un intervallo" mescolava giorni
 * diversi senza dire quali, e la data della ripresa era quella di fine
 * intervallo.
 */
final class ImageryCatalog
{
    private const TIMEOUT = 120;

    /** Copertura minima dell'area perché un passaggio sia scelto in
     * automatico: ai bordi della fascia ripresa un passaggio può coprire
     * solo una parte dell'area (il resto resterebbe trasparente). */
    public const MIN_AUTO_COVERAGE = 0.95;

    /** @return array{passes:array<int,array>, stats_error:?string} */
    public static function sentinel2(array $bbox, string $dateFrom, string $dateTo): array
    {
        return self::search('/fetch/catalog/sentinel2', $bbox, $dateFrom, $dateTo);
    }

    /** @return array{passes:array<int,array>, stats_error:?string} */
    public static function sentinel1(array $bbox, string $dateFrom, string $dateTo): array
    {
        return self::search('/fetch/catalog/sentinel1', $bbox, $dateFrom, $dateTo);
    }

    /**
     * Il passaggio Sentinel-2 più recente con nuvole sull'area non oltre
     * $maxCloud (0..1) e area coperta. Se il servizio statistiche non ha
     * risposto si ripiega sulla nuvolosità dell'intero tassello.
     *
     * @param string|null $after Solo passaggi successivi a questa data/ora ISO.
     */
    public static function bestSentinel2(array $passes, float $maxCloud, ?string $after = null): ?array
    {
        foreach ($passes as $p) { // già dal più recente
            if ($after !== null && strcmp($p['datetime'], $after) <= 0) {
                continue;
            }
            $cloud = $p['aoi_cloud'] ?? $p['scene_cloud'] ?? null;
            $coverage = $p['aoi_coverage'] ?? null;
            if ($cloud !== null && $cloud <= $maxCloud && ($coverage === null || $coverage >= self::MIN_AUTO_COVERAGE)) {
                return $p;
            }
        }
        return null;
    }

    /**
     * Il passaggio Sentinel-1 più recente, se richiesto della stessa orbita
     * relativa: le riprese radar si confrontano bene solo a parità di
     * geometria di vista.
     */
    public static function bestSentinel1(array $passes, ?int $relativeOrbit = null, ?string $after = null): ?array
    {
        foreach ($passes as $p) {
            if ($after !== null && strcmp($p['datetime'], $after) <= 0) {
                continue;
            }
            if ($relativeOrbit !== null && (int) ($p['relative_orbit'] ?? -1) !== $relativeOrbit) {
                continue;
            }
            return $p;
        }
        return null;
    }

    /** Il passaggio con quella data/ora esatta (dal catalogo del giorno). */
    public static function findPass(string $source, array $bbox, string $datetime): ?array
    {
        $day = substr($datetime, 0, 10);
        $result = $source === 'sentinel1' ? self::sentinel1($bbox, $day, $day) : self::sentinel2($bbox, $day, $day);
        foreach ($result['passes'] as $p) {
            if ($p['datetime'] === $datetime) {
                return $p;
            }
        }
        return null;
    }

    /** "08/10/2026 10:00 UTC" */
    public static function label(string $isoDatetime): string
    {
        $t = strtotime($isoDatetime);
        return $t ? gmdate('d/m/Y H:i', $t) . ' UTC' : $isoDatetime;
    }

    private static function search(string $path, array $bbox, string $dateFrom, string $dateTo): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            throw new RuntimeException('Date non valide (formato AAAA-MM-GG).');
        }
        try {
            $result = (new PythonServiceClient())->post($path, [
                'bbox' => array_map('floatval', $bbox),
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ], self::TIMEOUT);
        } catch (PythonServiceException $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }
        return [
            'passes' => is_array($result['passes'] ?? null) ? $result['passes'] : [],
            'stats_error' => $result['stats_error'] ?? null,
        ];
    }
}
