<?php

/**
 * Archivio storico di Esri World Imagery (Wayback): le versioni del mosaico
 * pubblicate dal 2014, circa una al mese, ciascuna con il proprio servizio
 * di metadati.
 *
 * Quasi tutte le versioni successive non cambiano nulla su una data area:
 * il mosaico si aggiorna a zone. Per trovare quelle in cui l'immagine è
 * davvero diversa si usa il "tilemap" del servizio, che per un tassello e
 * una versione indica in quale versione quel tassello è stato pubblicato
 * l'ultima volta (lo stesso metodo dell'applicazione Wayback di Esri): si
 * parte dalla versione più recente e si salta ogni volta a quella che
 * precede la versione indicata. Su Sigonella 197 versioni si riducono a
 * 19 immagini diverse, dal 2014 a oggi.
 *
 * Lo scaricamento delle immagini di una versione è nel servizio di analisi
 * (core/wayback_client.py); qui solo elenco e date.
 */
final class EsriWayback
{
    private const TILEMAP_URL = 'https://wayback.maptiles.arcgis.com/arcgis/rest/services/World_Imagery/MapServer/tilemap/%d/%d/%d/%d';
    private const CATALOG_CACHE_KEY = '_esri_wayback_catalog';
    private const VERSIONS_CACHE_PREFIX = '_esri_wayback_versions:';
    private const DATES_CACHE_PREFIX = '_esri_wayback_dates:';
    private const TIMEOUT = 15;

    /** Livello di zoom a cui si cercano i cambiamenti: ~1 m/pixel, il più
     * fine disponibile anche nelle versioni più vecchie (che spesso non
     * arrivano al 18–19). Un'immagine nuova a risoluzione più alta cambia
     * comunque anche i tasselli di questo livello. */
    private const CHANGE_ZOOM = 17;

    /**
     * Tutte le versioni, dalla più recente: numero, data di pubblicazione,
     * servizio di metadati. Cache di un giorno (ne esce una al mese).
     *
     * @return array<int,array{release:int,date:string,metadata_url:string}>
     */
    public static function releases(): array
    {
        $cached = json_decode((string) AppSettings::get(self::CATALOG_CACHE_KEY), true);
        if (is_array($cached) && ($cached['fetched_at'] ?? 0) > time() - 86400 && !empty($cached['releases'])) {
            return $cached['releases'];
        }
        $config = self::getJson(EsriImageryMetadata::WAYBACK_CONFIG);
        $releases = [];
        foreach ($config as $num => $r) {
            // Anche le versioni senza servizio di metadati: il tilemap può
            // indicarle come origine di un tassello, e senza di loro la
            // catena di versioni si spezzerebbe (la data reale di quelle
            // versioni resta "non disponibile").
            if (preg_match('/(\d{4}-\d{2}-\d{2})/', (string) ($r['itemTitle'] ?? ''), $m)) {
                $releases[] = ['release' => (int) $num, 'date' => $m[1], 'metadata_url' => rtrim((string) ($r['metadataLayerUrl'] ?? ''), '/')];
            }
        }
        if (!$releases) {
            throw new EsriImageryMetadataException('Elenco delle versioni Wayback vuoto o non leggibile.');
        }
        usort($releases, fn($a, $b) => strcmp($b['date'], $a['date']));
        AppSettings::set(self::CATALOG_CACHE_KEY, json_encode(['fetched_at' => time(), 'releases' => $releases]));
        return $releases;
    }

    public static function release(int $num): ?array
    {
        foreach (self::releases() as $r) {
            if ($r['release'] === $num) {
                return $r;
            }
        }
        return null;
    }

    /**
     * Versioni con un'immagine diversa al centro dell'area, dalla più
     * recente. Cache di un giorno per tassello.
     *
     * @param float[] $bbox [minLon, minLat, maxLon, maxLat]
     * @return array<int,array{release:int,date:string}>
     */
    public static function versionsAt(array $bbox): array
    {
        [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $bbox);
        $z = self::CHANGE_ZOOM;
        [$x, $y] = self::tileXY(($minLon + $maxLon) / 2, ($minLat + $maxLat) / 2, $z);
        $cacheKey = self::VERSIONS_CACHE_PREFIX . "$z/$y/$x";
        $cached = json_decode((string) AppSettings::get($cacheKey), true);
        if (is_array($cached) && ($cached['fetched_at'] ?? 0) > time() - 86400 && isset($cached['versions'])) {
            return $cached['versions'];
        }

        $releases = self::releases();
        $index = [];
        foreach ($releases as $i => $r) {
            $index[$r['release']] = $i;
        }
        $versions = [];
        $i = 0;
        // Tetto di sicurezza: una risposta anomala non deve far ciclare
        // su tutte le ~200 versioni.
        for ($guard = 0; $i < count($releases) && $guard < 80; $guard++) {
            $map = self::getJson(sprintf(self::TILEMAP_URL, $releases[$i]['release'], $z, $y, $x));
            if ((int) (($map['data'] ?? [0])[0] ?? 0) !== 1) {
                break; // nessuna immagine qui in questa versione né nelle precedenti
            }
            $selected = (int) (($map['select'] ?? [])[0] ?? $releases[$i]['release']);
            if (!isset($index[$selected])) {
                // Versione d'origine sconosciuta all'elenco: non si può dire
                // se questa immagine sia diversa dalle altre, quindi non la
                // si elenca come tale e si passa alla versione precedente.
                $i++;
                continue;
            }
            $r = $releases[$index[$selected]];
            $versions[] = ['release' => $r['release'], 'date' => $r['date']];
            $i = $index[$selected] + 1;
        }
        AppSettings::set($cacheKey, json_encode(['fetched_at' => time(), 'versions' => $versions]));
        return $versions;
    }

    /**
     * Acquisizioni (data reale, sensore...) dell'area nella versione
     * indicata, dai metadati di quella versione. Il passato non cambia:
     * cache di 30 giorni.
     */
    public static function imageryFor(int $release, array $bbox, float $mpp): array
    {
        $r = self::release($release);
        if (!$r) {
            throw new EsriImageryMetadataException("Versione Wayback $release non trovata.");
        }
        if ($r['metadata_url'] === '') {
            throw new EsriImageryMetadataException("La versione Wayback $release non ha metadati di acquisizione.");
        }
        $cacheKey = self::DATES_CACHE_PREFIX . $release . ':' . md5(json_encode(array_map(fn($v) => round((float) $v, 6), $bbox)) . '|' . round($mpp, 2));
        $cached = json_decode((string) AppSettings::get($cacheKey), true);
        if (is_array($cached) && ($cached['fetched_at'] ?? 0) > time() - 30 * 86400 && !empty($cached['imagery'])) {
            return $cached['imagery'];
        }
        $imagery = EsriImageryMetadata::query($bbox, $mpp, $r['metadata_url']);
        $imagery['service'] = 'Esri World Imagery Wayback';
        AppSettings::set($cacheKey, json_encode(['fetched_at' => time(), 'imagery' => $imagery]));
        return $imagery;
    }

    /** Tassello Web Mercator che contiene un punto. */
    private static function tileXY(float $lon, float $lat, int $z): array
    {
        $n = 2 ** $z;
        $lat = max(-85.05112878, min(85.05112878, $lat));
        $r = deg2rad($lat);
        $x = (int) floor(($lon + 180) / 360 * $n);
        $y = (int) floor((1 - log(tan($r) + 1 / cos($r)) / M_PI) / 2 * $n);
        return [max(0, min($n - 1, $x)), max(0, min($n - 1, $y))];
    }

    private static function getJson(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'OrbitalEye (self-hosted imagery analysis)',
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            throw new EsriImageryMetadataException("Archivio Wayback non raggiungibile: $err");
        }
        $json = json_decode($body, true);
        if ($status >= 400 || !is_array($json)) {
            throw new EsriImageryMetadataException("Risposta non valida dall'archivio Wayback ($status).");
        }
        return $json;
    }
}
