<?php

final class EsriImageryMetadataException extends RuntimeException
{
}

/**
 * Data, sensore e risoluzione REALI delle immagini Esri World Imagery che
 * coprono un'area, letti dai livelli di metadati pubblici del servizio
 * ufficiale (stesso MapServer da cui si scaricano le riprese, operazione
 * `query`, nessuna chiave richiesta).
 *
 * Perché serve: World Imagery è un mosaico di acquisizioni di epoche
 * diverse, aggiornato di rado. Senza questi metadati una ripresa veniva
 * datata col giorno del download — su Grosseto l'immagine "attuale" è del
 * maggio 2022, su Sigonella del gennaio 2024 — e ciò che si pubblicava
 * suggeriva una situazione molto più recente di quella fotografata.
 *
 * I livelli di metadati sono uno per scala di visualizzazione ("30cm
 * Resolution Metadata", "4.8m Resolution Metadata", ...): a ogni scala il
 * mosaico mostra fonti diverse, quindi si interroga il livello che
 * corrisponde alla risoluzione della ripresa. Un'area può contenere più
 * acquisizioni: per ciascuna si calcola la quota di area coperta.
 */
final class EsriImageryMetadata
{
    public const SERVICE = 'https://services.arcgisonline.com/arcgis/rest/services/World_Imagery/MapServer';

    /** Attribuzione ufficiale del servizio (campo copyrightText), usata se
     * il servizio non è raggiungibile al momento della lettura. */
    public const DEFAULT_ATTRIBUTION = 'Source: Esri, Vantor, Earthstar Geographics, and the GIS User Community';

    private const TIMEOUT = 15;
    private const LAYERS_CACHE_KEY = '_esri_metadata_layers';
    private const LAYERS_CACHE_TTL = 7 * 86400;

    /** Pixel per metro a 96 dpi: converte metri/pixel nella scala di mappa
     * usata da minScale/maxScale dei livelli. */
    private const SCALE_PER_MPP = 3779.5;

    /** Quota minima di area perché un'acquisizione venga considerata: scarta
     * i bordi sottilissimi di poligoni vicini. */
    private const MIN_COVERAGE = 0.005;

    private const SENSOR_NAMES = [
        'WV01' => 'WorldView-1', 'WV02' => 'WorldView-2', 'WV03' => 'WorldView-3', 'WV04' => 'WorldView-4',
        'GE01' => 'GeoEye-1', 'QB02' => 'QuickBird-2', 'IK02' => 'IKONOS',
        'LG01' => 'WorldView Legion', 'LG02' => 'WorldView Legion', 'LG03' => 'WorldView Legion',
        'LG04' => 'WorldView Legion', 'LG05' => 'WorldView Legion', 'LG06' => 'WorldView Legion',
    ];

    public static function sensorName(?string $code): string
    {
        $code = trim((string) $code);
        return self::SENSOR_NAMES[$code] ?? $code;
    }

    /**
     * Acquisizioni che coprono $bbox alla risoluzione $mpp (metri/pixel).
     *
     * @param float[] $bbox [minLon, minLat, maxLon, maxLat]
     * @return array{service:string, layer:string, queried_at:string, attribution:string,
     *   acquisitions:array<int,array>, dominant_date:string, date_min:string, date_max:string}
     * @throws EsriImageryMetadataException
     */
    public static function query(array $bbox, float $mpp, ?string $serviceUrl = null): array
    {
        $serviceUrl = $serviceUrl ?: self::SERVICE;
        [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', $bbox);
        if (!($maxLon > $minLon && $maxLat > $minLat) || $mpp <= 0) {
            throw new EsriImageryMetadataException('Area o scala non valida per la lettura dei metadati.');
        }

        $service = self::serviceInfo($serviceUrl);
        $candidates = self::layersFrom($service['layers'], $mpp);
        $width = $maxLon - $minLon;

        $lastError = null;
        foreach ($candidates as $layer) {
            // Il servizio restituisce occasionalmente errori spuri ("Layer not
            // found" su un livello che un istante dopo risponde): un secondo
            // tentativo, poi si prova il livello successivo invece di fallire.
            $features = null;
            for ($attempt = 0; $attempt < 2 && $features === null; $attempt++) {
                try {
                    $features = self::fetchFeatures($serviceUrl, $layer['id'], [$minLon, $minLat, $maxLon, $maxLat], $width / 400);
                } catch (EsriImageryMetadataException $e) {
                    $lastError = $e;
                    usleep(400000);
                }
            }
            if (!$features) {
                // Nessuna immagine nativa a questa scala (ripresa più fine della
                // sorgente, quindi ingrandita): si risale al livello successivo.
                continue;
            }
            $acquisitions = self::summarize($features, [$minLon, $minLat, $maxLon, $maxLat]);
            if (!$acquisitions) {
                continue;
            }
            $dates = array_column($acquisitions, 'date');
            return [
                'service' => 'Esri World Imagery',
                'layer' => $layer['name'],
                'queried_at' => gmdate('c'),
                'attribution' => $service['attribution'],
                'acquisitions' => $acquisitions,
                'dominant_date' => $acquisitions[0]['date'],
                'date_min' => min($dates),
                'date_max' => max($dates),
            ];
        }
        throw $lastError ?? new EsriImageryMetadataException('Nessun metadato di acquisizione disponibile per quest\'area.');
    }

    /**
     * Come query(), ma per il mosaico com'era il giorno $isoDate (il download
     * di una ripresa già archiviata). Si interrogano le due istantanee
     * Wayback che racchiudono quella data (o l'ultima istantanea e il
     * servizio corrente, se il download è più recente dell'ultima): se
     * concordano la data è certa; se no, l'area è stata aggiornata proprio a
     * cavallo del download e non si può sapere quale delle due versioni sia
     * stata scaricata — il risultato lo dichiara (certain = false) e riporta
     * l'alternativa.
     */
    public static function queryAsOf(array $bbox, float $mpp, string $isoDate): array
    {
        $around = self::waybackAround($isoDate);
        if (!$around['before']) {
            // Download anteriore a ogni istantanea: resta solo il servizio corrente.
            return self::query($bbox, $mpp) + ['certain' => false, 'as_of' => $isoDate];
        }
        $before = self::query($bbox, $mpp, $around['before']['url']);
        $after = self::query($bbox, $mpp, $around['after']['url'] ?? null);
        $signature = function (array $r): string {
            $keys = array_map(fn($a) => $a['date'] . '|' . $a['sensor'], $r['acquisitions']);
            sort($keys);
            return implode(',', $keys);
        };

        $result = $before;
        $result['as_of'] = $isoDate;
        $result['bracket'] = [$around['before']['date'], $around['after']['date'] ?? 'corrente'];
        $result['certain'] = $signature($before) === $signature($after);
        if (!$result['certain']) {
            $result['alternative'] = [
                'dominant_date' => $after['dominant_date'],
                'date_min' => $after['date_min'],
                'date_max' => $after['date_max'],
            ];
        }
        return $result;
    }

    public const WAYBACK_CONFIG = 'https://s3-us-west-2.amazonaws.com/config.maptiles.arcgis.com/waybackconfig.json';

    /**
     * Servizi di metadati delle due versioni storiche del mosaico (archivio
     * Wayback, una istantanea circa al mese) che racchiudono una data: il
     * mosaico in vigore quel giorno è compreso fra le due. Serve a datare
     * riprese scaricate in passato, perché i metadati del servizio corrente
     * descrivono il mosaico di oggi, che nel frattempo può essere cambiato.
     *
     * @return array{before:?array{url:string,date:string}, after:?array{url:string,date:string}}
     */
    public static function waybackAround(string $isoDate): array
    {
        $cacheKey = '_esri_wayback_releases';
        $cached = json_decode((string) AppSettings::get($cacheKey), true);
        if (!is_array($cached) || ($cached['fetched_at'] ?? 0) < time() - 86400 || empty($cached['releases'])) {
            $config = self::getJson(self::WAYBACK_CONFIG);
            $releases = [];
            foreach ($config as $r) {
                if (!empty($r['metadataLayerUrl']) && preg_match('/(\d{4}-\d{2}-\d{2})/', (string) ($r['itemTitle'] ?? ''), $m)) {
                    $releases[] = ['date' => $m[1], 'url' => rtrim((string) $r['metadataLayerUrl'], '/')];
                }
            }
            usort($releases, fn($a, $b) => strcmp($a['date'], $b['date']));
            $cached = ['fetched_at' => time(), 'releases' => $releases];
            AppSettings::set($cacheKey, json_encode($cached));
        }
        $before = null;
        $after = null;
        foreach ($cached['releases'] as $r) {
            if ($r['date'] <= $isoDate) {
                $before = $r;
            } elseif ($after === null) {
                $after = $r;
            }
        }
        return ['before' => $before, 'after' => $after];
    }

    /** Livelli di metadati (dalla scala della ripresa verso quelle più
     * grossolane) e attribuzione ufficiale, con cache settimanale. */
    private static function serviceInfo(string $serviceUrl): array
    {
        $cacheKey = self::LAYERS_CACHE_KEY . ':' . md5($serviceUrl);
        $cached = json_decode((string) AppSettings::get($cacheKey), true);
        if (is_array($cached) && ($cached['fetched_at'] ?? 0) > time() - self::LAYERS_CACHE_TTL && !empty($cached['layers'])) {
            return $cached;
        }
        $json = self::getJson($serviceUrl . '?f=json');
        $layers = [];
        foreach ($json['layers'] ?? [] as $l) {
            if (isset($l['id'], $l['name']) && str_contains((string) $l['name'], 'Resolution Metadata')) {
                $layers[] = [
                    'id' => (int) $l['id'],
                    'name' => (string) $l['name'],
                    'minScale' => (float) ($l['minScale'] ?? 0),
                    'maxScale' => (float) ($l['maxScale'] ?? 0),
                ];
            }
        }
        if (!$layers) {
            throw new EsriImageryMetadataException('Il servizio Esri non espone livelli di metadati riconoscibili.');
        }
        // Dal più fine (scala minore) al più grossolano.
        usort($layers, fn($a, $b) => $a['maxScale'] <=> $b['maxScale']);
        $info = [
            'fetched_at' => time(),
            'layers' => $layers,
            'attribution' => trim((string) ($json['copyrightText'] ?? '')) ?: self::DEFAULT_ATTRIBUTION,
        ];
        AppSettings::set($cacheKey, json_encode($info));
        return $info;
    }

    private static function layersFrom(array $layers, float $mpp): array
    {
        $scale = $mpp * self::SCALE_PER_MPP;
        foreach ($layers as $i => $l) {
            $inRange = $scale > $l['maxScale'] && ($l['minScale'] == 0 || $scale <= $l['minScale']);
            if ($inRange) {
                return array_slice($layers, $i);
            }
        }
        // Più fine del livello più fine: si parte da quello.
        return $layers;
    }

    private static function fetchFeatures(string $serviceUrl, int $layerId, array $bbox, float $simplify): array
    {
        $params = http_build_query([
            'geometry' => implode(',', $bbox),
            'geometryType' => 'esriGeometryEnvelope',
            'inSR' => 4326,
            'outSR' => 4326,
            'spatialRel' => 'esriSpatialRelIntersects',
            'outFields' => 'SRC_DATE,SRC_RES,SRC_DESC,NICE_NAME,NICE_DESC,ReleaseName',
            'returnGeometry' => 'true',
            'maxAllowableOffset' => sprintf('%.8F', max($simplify, 1e-7)),
            'geometryPrecision' => 7,
            'f' => 'json',
        ]);
        $json = self::getJson($serviceUrl . '/' . $layerId . '/query?' . $params);
        if (isset($json['error'])) {
            throw new EsriImageryMetadataException('Il servizio Esri ha rifiutato la richiesta di metadati: ' . ($json['error']['message'] ?? 'errore'));
        }
        return $json['features'] ?? [];
    }

    /** Raggruppa i poligoni per acquisizione e calcola la quota di area
     * coperta da ciascuna (poligoni ritagliati sull'area della ripresa). */
    private static function summarize(array $features, array $bbox): array
    {
        [$minX, $minY, $maxX, $maxY] = $bbox;
        $boxArea = ($maxX - $minX) * ($maxY - $minY);
        $groups = [];
        foreach ($features as $f) {
            $a = $f['attributes'] ?? [];
            $date = self::parseDate($a['SRC_DATE'] ?? null);
            if ($date === null) {
                continue;
            }
            $area = 0.0;
            foreach ($f['geometry']['rings'] ?? [] as $ring) {
                $area += self::signedArea(self::clipRing($ring, $minX, $minY, $maxX, $maxY));
            }
            $coverage = $boxArea > 0 ? abs($area) / $boxArea : 0.0;
            $sensor = trim((string) ($a['SRC_DESC'] ?? ''));
            $res = isset($a['SRC_RES']) ? round((float) $a['SRC_RES'], 2) : null;
            $key = $date . '|' . $sensor . '|' . $res;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'date' => $date,
                    'sensor' => $sensor,
                    'sensor_name' => self::sensorName($sensor),
                    'resolution_m' => $res,
                    'provider' => trim((string) ($a['NICE_DESC'] ?? '')),
                    'product' => trim((string) ($a['NICE_NAME'] ?? '')),
                    'release' => trim((string) ($a['ReleaseName'] ?? '')),
                    'coverage' => 0.0,
                ];
            }
            $groups[$key]['coverage'] += $coverage;
        }
        $result = array_values(array_filter($groups, fn($g) => $g['coverage'] >= self::MIN_COVERAGE));
        if (!$result && $groups) {
            // Area molto piccola dentro un solo poligono semplificato: la
            // copertura calcolata può risultare nulla, ma l'acquisizione c'è.
            $result = array_values($groups);
        }
        foreach ($result as &$g) {
            $g['coverage'] = round(min(1.0, $g['coverage']), 3);
        }
        unset($g);
        usort($result, fn($a, $b) => $b['coverage'] <=> $a['coverage']);
        return $result;
    }

    private static function parseDate($value): ?string
    {
        $s = preg_replace('/\D/', '', (string) $value);
        if (strlen($s) !== 8) {
            return null;
        }
        $y = (int) substr($s, 0, 4);
        $m = (int) substr($s, 4, 2);
        $d = (int) substr($s, 6, 2);
        return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }

    /** Ritaglio Sutherland–Hodgman di un anello sul rettangolo dell'area:
     * il rettangolo è convesso, quindi il risultato è corretto anche per
     * anelli concavi ai fini del calcolo dell'area. */
    private static function clipRing(array $ring, float $minX, float $minY, float $maxX, float $maxY): array
    {
        $edges = [
            fn($p) => $p[0] >= $minX, fn($p) => $p[0] <= $maxX,
            fn($p) => $p[1] >= $minY, fn($p) => $p[1] <= $maxY,
        ];
        $cuts = [
            fn($a, $b) => self::cutX($a, $b, $minX), fn($a, $b) => self::cutX($a, $b, $maxX),
            fn($a, $b) => self::cutY($a, $b, $minY), fn($a, $b) => self::cutY($a, $b, $maxY),
        ];
        $poly = $ring;
        for ($e = 0; $e < 4 && $poly; $e++) {
            $inside = $edges[$e];
            $out = [];
            $n = count($poly);
            for ($i = 0; $i < $n; $i++) {
                $cur = $poly[$i];
                $prev = $poly[($i + $n - 1) % $n];
                if ($inside($cur)) {
                    if (!$inside($prev)) {
                        $out[] = $cuts[$e]($prev, $cur);
                    }
                    $out[] = $cur;
                } elseif ($inside($prev)) {
                    $out[] = $cuts[$e]($prev, $cur);
                }
            }
            $poly = $out;
        }
        return $poly;
    }

    private static function cutX(array $a, array $b, float $x): array
    {
        $t = ($x - $a[0]) / (($b[0] - $a[0]) ?: 1e-12);
        return [$x, $a[1] + $t * ($b[1] - $a[1])];
    }

    private static function cutY(array $a, array $b, float $y): array
    {
        $t = ($y - $a[1]) / (($b[1] - $a[1]) ?: 1e-12);
        return [$a[0] + $t * ($b[0] - $a[0]), $y];
    }

    private static function signedArea(array $poly): float
    {
        $n = count($poly);
        if ($n < 3) {
            return 0.0;
        }
        $s = 0.0;
        for ($i = 0; $i < $n; $i++) {
            [$x1, $y1] = $poly[$i];
            [$x2, $y2] = $poly[($i + 1) % $n];
            $s += $x1 * $y2 - $x2 * $y1;
        }
        return $s / 2;
    }

    private static function getJson(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => 'OrbitalEye (self-hosted imagery analysis)',
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            throw new EsriImageryMetadataException("Servizio metadati Esri non raggiungibile: $err");
        }
        $json = json_decode($body, true);
        if ($status >= 400 || !is_array($json)) {
            throw new EsriImageryMetadataException("Risposta non valida dal servizio metadati Esri ($status).");
        }
        return $json;
    }
}
