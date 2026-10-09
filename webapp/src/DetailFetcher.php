<?php

/**
 * "Scarica quest'area in dettaglio": da una ripresa Esri d'insieme (un'area
 * grande scaricata a pochi metri per pixel), l'area selezionata col
 * ritaglio viene riscaricata alla risoluzione nativa delle immagini Esri
 * (letta dai metadati, vedi EsriImageryMetadata: spesso 0,3–0,5 m), con la
 * stessa rotazione e — per una versione storica — la stessa versione
 * dell'archivio Wayback. Il risultato è una ripresa nuova dello studio.
 */
final class DetailFetcher
{
    /** Lato massimo, come per ogni scaricamento (vedi CaptureFetcher). */
    private const MAX_SIDE = 2500;

    /**
     * @param array $p capture_id, crop {x, y, w, h} in frazioni della ripresa
     * @param callable|null $progress fn(int $percent, string $message)
     */
    public static function run(array $p, ?callable $progress = null): array
    {
        $progress = $progress ?? fn() => null;
        $capture = !empty($p['capture_id']) ? Capture::find((int) $p['capture_id']) : null;
        if (!$capture) {
            throw new CaptureFetchException('Ripresa non trovata', 404);
        }
        $crop = $p['crop'] ?? null;
        foreach (['x', 'y', 'w', 'h'] as $k) {
            if (!is_array($crop) || !isset($crop[$k]) || !is_numeric($crop[$k])) {
                throw new CaptureFetchException('Area da scaricare non valida');
            }
        }
        $crop = array_map('floatval', $crop);
        if ($crop['w'] <= 0 || $crop['h'] <= 0 || $crop['x'] < -0.001 || $crop['y'] < -0.001
            || $crop['x'] + $crop['w'] > 1.001 || $crop['y'] + $crop['h'] > 1.001) {
            throw new CaptureFetchException('Area da scaricare fuori dalla ripresa');
        }

        $origin = self::esriOrigin($capture);
        if (!$origin) {
            throw new CaptureFetchException('Il dettaglio si può scaricare solo da riprese Esri georiferite (le immagini Sentinel sono già alla loro risoluzione massima).');
        }
        $geo = Capture::geoMetaForDerived($capture, $crop);
        if (empty($geo['bbox'])) {
            throw new CaptureFetchException('Questa ripresa non ha coordinate: non si può sapere quale area riscaricare.');
        }
        $meta = $origin['meta'];
        $native = (float) ($meta['esri_imagery']['acquisitions'][0]['resolution_m'] ?? 0);
        if ($native <= 0) {
            $native = 0.5; // metadati assenti: valore tipico delle immagini sub-metriche
        }

        // Pixel alla risoluzione nativa. Esri esporta in gradi (pixel
        // "quadrati" in longitudine/latitudine) e adatta la bbox al rapporto
        // larghezza/altezza IN GRADI (vedi _adjust_bbox_to_aspect in
        // esri_client.py): le dimensioni si chiedono quindi in proporzione a
        // dLon/dLat — con un rapporto in metri Esri allargherebbe l'area in
        // altezza (del 41% a 45° di latitudine) e il dettaglio peggiorerebbe.
        // La densità è quella che porta alla risoluzione nativa l'asse più
        // grossolano (nord-sud: un grado di latitudine è il più lungo).
        [$minLon, $minLat, $maxLon, $maxLat] = $geo['bbox'];
        $lat = deg2rad(($minLat + $maxLat) / 2);
        $widthM = ($maxLon - $minLon) * 111320 * cos($lat);
        $heightM = ($maxLat - $minLat) * 111320;
        [$width, $height] = self::fetchSize($geo['bbox'], $native);

        $progress(5, sprintf('Area di %s × %s m, risoluzione nativa %s m/pixel…', self::m($widthM), self::m($heightM), self::num($native)));
        $params = [
            'study_id' => (int) $capture['study_id'],
            'source' => 'esri',
            'bbox' => $geo['bbox'],
            'rotation' => (float) ($geo['rotation'] ?? 0),
            'width' => $width,
            'height' => $height,
        ];
        if (!empty($meta['wayback']['release'])) {
            $params['wayback_release'] = (int) $meta['wayback']['release'];
        }
        $result = CaptureFetcher::fetchAndSave($params, null, $progress);
        // Risoluzione OTTENUTA, non quella chiesta: il ritaglio ruotato ha un
        // suo tetto di pixel (ImageRotateCrop::scaledFetchSize) ed Esri può
        // ripiegare su una risoluzione ridotta (esri_client.py).
        $saved = Capture::find((int) $result['capture_id']);
        $mpp = $saved ? Capture::resolveMpp($saved) : null;
        $effective = $mpp ? max($mpp['mpp_x'], $mpp['mpp_y']) : $heightM / $height;
        if (abs((float) ($geo['rotation'] ?? 0)) >= 0.01) {
            // Il ritaglio ruotato ricampiona a pixel quadrati in metri sul
            // passo dell'asse più fine, la longitudine (vedi
            // ImageRotateCrop::rotateAndCrop): in latitudine è un
            // ingrandimento, e il dettaglio vero è quello dei pixel scaricati.
            $effective /= ImageRotateCrop::lonScale(($minLat + $maxLat) / 2);
        }
        $result['meters_per_pixel'] = round($effective, 3);
        $result['native_meters_per_pixel'] = $native;
        $result['limited'] = $effective > $native * 1.1;
        return $result;
    }

    /** Pixel da chiedere per $bbox alla risoluzione $native (m/pixel). @return array{int, int} */
    public static function fetchSize(array $bbox, float $native): array
    {
        $pxPerDeg = 111320 / $native;
        $wantW = ($bbox[2] - $bbox[0]) * $pxPerDeg;
        $wantH = ($bbox[3] - $bbox[1]) * $pxPerDeg;
        $scale = min(1.0, self::MAX_SIDE / max($wantW, $wantH));
        return [max(64, (int) round($wantW * $scale)), max(64, (int) round($wantH * $scale))];
    }

    /** La ripresa Esri d'origine (lei stessa o la sorgente di una copia/ritaglio). */
    private static function esriOrigin(array $capture): ?array
    {
        $seen = [];
        while ($capture && !isset($seen[(int) $capture['id']])) {
            $seen[(int) $capture['id']] = true;
            $meta = json_decode($capture['meta_json'] ?? '', true) ?: [];
            if (($capture['source'] ?? '') === 'esri' || !empty($meta['esri_imagery'])) {
                return ['capture' => $capture, 'meta' => $meta];
            }
            if (empty($meta['source_capture_id'])) {
                return null;
            }
            $capture = Capture::find((int) $meta['source_capture_id']);
        }
        return null;
    }

    private static function num(float $v): string
    {
        return str_replace('.', ',', (string) round($v, 2));
    }

    private static function m(float $meters): string
    {
        return $meters >= 1000 ? str_replace('.', ',', (string) round($meters / 1000, 2)) . ' km' : (string) round($meters);
    }
}
