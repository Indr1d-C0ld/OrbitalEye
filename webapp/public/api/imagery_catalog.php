<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();
// Ricerche che durano secondi: la sessione (che PHP tiene bloccata per
// tutta la richiesta) si chiude subito, così le date delle versioni
// storiche si caricano in parallelo e il resto dell'interfaccia non resta
// in attesa.
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(['error' => 'Metodo non consentito'], 405);
}

/**
 * Elenco di ciò che si può scaricare su un'area, prima di scaricarlo:
 *  - action=sentinel2: passaggi Sentinel-2 con nuvole e copertura sull'area;
 *  - action=sentinel1: passaggi Sentinel-1 con direzione e orbita;
 *  - action=wayback: versioni storiche Esri con un'immagine diversa;
 *  - action=wayback_imagery: data reale dell'immagine di una versione.
 */
$body = json_body();
$action = $body['action'] ?? '';

$bbox = $body['bbox'] ?? null;
if (!is_array($bbox) || count($bbox) !== 4 || !array_reduce($bbox, fn($ok, $v) => $ok && is_numeric($v), true)) {
    respond_json(['error' => 'Area mancante o non valida: disegnala sulla mappa o scrivi le coordinate.'], 400);
}
$bbox = array_map('floatval', $bbox);
[$minLon, $minLat, $maxLon, $maxLat] = $bbox;
if ($minLon >= $maxLon || $minLat >= $maxLat || $minLon < -180 || $maxLon > 180 || $minLat < -90 || $maxLat > 90) {
    respond_json(['error' => 'Area non valida (controlla che i minimi siano minori dei massimi).'], 400);
}

try {
    if ($action === 'sentinel2' || $action === 'sentinel1') {
        $from = (string) ($body['date_from'] ?? '');
        $to = (string) ($body['date_to'] ?? '');
        if ($to > date('Y-m-d')) {
            $to = date('Y-m-d');
        }
        $result = $action === 'sentinel1' ? ImageryCatalog::sentinel1($bbox, $from, $to) : ImageryCatalog::sentinel2($bbox, $from, $to);
        respond_json($result);
    }

    if ($action === 'wayback') {
        $versions = EsriWayback::versionsAt($bbox);
        respond_json(['versions' => $versions]);
    }

    if ($action === 'wayback_imagery') {
        $release = (int) ($body['release'] ?? 0);
        $width = max(64, min((int) ($body['width'] ?? 1024), 2500));
        $height = max(64, min((int) ($body['height'] ?? 1024), 2500));
        // Stessa scala che avrà la ripresa: la bbox viene allargata al
        // rapporto d'aspetto dell'immagine (vedi esri_client.py), quindi i
        // gradi per pixel sono uguali sui due assi.
        $degPerPx = max(($maxLon - $minLon) / $width, ($maxLat - $minLat) / $height);
        $mpp = $degPerPx * 111320 * cos(deg2rad(($minLat + $maxLat) / 2));
        $imagery = EsriWayback::imageryFor($release, $bbox, $mpp);
        $info = ImageryAttribution::forCapture([
            'id' => 0, 'source' => 'esri', 'capture_date' => null,
            'meta_json' => json_encode(['esri_imagery' => $imagery]),
        ]);
        respond_json([
            'release' => $release,
            'dominant_date' => $imagery['dominant_date'],
            'date_min' => $imagery['date_min'],
            'date_max' => $imagery['date_max'],
            'label' => $info['date_label'],
            'detail' => $info['detail'],
            // Versioni consecutive dell'archivio contengono spesso la stessa
            // acquisizione (solo rielaborata o col fornitore rinominato):
            // stessa impronta = stessa immagine.
            'signature' => EsriImageryMetadata::signature($imagery),
        ]);
    }
} catch (EsriImageryMetadataException $e) {
    respond_json(['error' => $e->getMessage()], 502);
} catch (RuntimeException $e) {
    respond_json(['error' => $e->getMessage()], 502);
}

respond_json(['error' => 'Azione non valida'], 400);
