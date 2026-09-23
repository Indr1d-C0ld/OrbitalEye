<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();

// Esporta annotazioni e misurazioni di una ripresa come layer georeferenziato
// (KML per Google Earth, GeoJSON per QGIS). Converte le coordinate frazionarie
// dell'immagine in lon/lat usando la bbox geografica della ripresa (o, in
// mancanza, quella dello studio). Valido per riprese NON ruotate in fase di
// scaricamento: per quelle ruotate (meta.rotation != 0) gli assi pixel non
// sono allineati a lon/lat e la conversione lineare qui sotto è approssimata.

$captureId = (int) ($_GET['capture_id'] ?? 0);
$format = strtolower($_GET['format'] ?? 'kml');
if (!in_array($format, ['kml', 'geojson'], true)) {
    respond_json(['error' => 'Formato non valido (kml|geojson)'], 400);
}
$capture = $captureId ? Capture::find($captureId) : null;
if (!$capture) {
    respond_json(['error' => 'Ripresa non trovata'], 404);
}
$study = Study::find((int) $capture['study_id']);

// Riferimento geografico della ripresa (bbox, rotazione) — anche per le
// riprese derivate, risalendo alla sorgente (vedi Capture::resolveGeoRef).
// La conversione è esatta anche per le riprese ruotate: i punti vengono
// riportati nel sistema del rettangolo di base e ruotati come l'immagine.
$geo = Capture::resolveGeoRef($capture);
$warning = null;
if (!$geo) {
    $meta = json_decode($capture['meta_json'] ?? '', true);
    $isDerived = is_array($meta) && !empty($meta['source_capture_id']);
    // Unico ripiego ammesso: una ripresa originale caricata a mano, per la
    // quale l'area dello studio è l'unica indicazione disponibile. Mai per
    // una derivata (ritaglio o copia): lì l'area dello studio è sicuramente
    // sbagliata.
    if (!$isDerived && $study && !empty($study['bbox_json'])) {
        $geo = ['bbox' => array_map('floatval', json_decode($study['bbox_json'], true)), 'rotation' => 0.0, 'rotation_model' => null];
        $warning = 'Ripresa caricata manualmente: georeferenziazione basata sull\'area dello studio, approssimata.';
    }
}
if (!$geo || count($geo['bbox']) !== 4) {
    respond_json(['error' => 'Nessun riferimento geografico noto per questa ripresa: impossibile georeferenziare.'], 400);
}

// fx,fy in [0,1] con origine in alto a sinistra -> lon/lat.
$toLonLat = fn(float $fx, float $fy): array => Capture::fracToLonLat($geo, $fx, $fy);

$targetKey = 'capture' . $captureId . '_analyze';
$rows = Annotation::forTarget((int) $capture['study_id'], $targetKey);

// Costruisce la lista di feature: [ tipo, [ [lon,lat], ... ], nome, descr ]
$features = [];
foreach ($rows as $r) {
    $coords = json_decode($r['coords_json'], true);
    if (!is_array($coords)) {
        continue;
    }
    $name = $r['label'] ?: ($r['shape_type'] . ' #' . $r['id']);
    $desc = $r['notes'] ?: '';
    if ($r['shape_type'] === 'rect') {
        $x = $coords['x'] ?? 0;
        $y = $coords['y'] ?? 0;
        $w = $coords['w'] ?? 0;
        $h = $coords['h'] ?? 0;
        $ring = [
            $toLonLat($x, $y), $toLonLat($x + $w, $y),
            $toLonLat($x + $w, $y + $h), $toLonLat($x, $y + $h), $toLonLat($x, $y),
        ];
        $features[] = ['polygon', $ring, $name, $desc, $r['color']];
    } elseif ($r['shape_type'] === 'polygon' || $r['shape_type'] === 'polyline') {
        $pts = array_map(fn($p) => $toLonLat((float) $p[0], (float) $p[1]), $coords['points'] ?? []);
        if (count($pts) < 2) {
            continue;
        }
        if ($r['shape_type'] === 'polygon') {
            $pts[] = $pts[0]; // chiude l'anello
            $features[] = ['polygon', $pts, $name, $desc, $r['color']];
        } else {
            $features[] = ['linestring', $pts, $name, $desc, $r['color']];
        }
    } elseif ($r['shape_type'] === 'measure') {
        $a = $toLonLat((float) ($coords['x1'] ?? 0), (float) ($coords['y1'] ?? 0));
        $b = $toLonLat((float) ($coords['x2'] ?? 0), (float) ($coords['y2'] ?? 0));
        $features[] = ['linestring', [$a, $b], $name ?: ('misura #' . $r['id']), $desc, $r['color']];
    }
}

$slugBase = 'orbitaleye_' . preg_replace('/[^a-z0-9]+/i', '_', $capture['label'] ?: ('ripresa' . $captureId));

if ($format === 'geojson') {
    $geoFeatures = [];
    foreach ($features as [$type, $pts, $name, $desc, $color]) {
        $geom = $type === 'polygon'
            ? ['type' => 'Polygon', 'coordinates' => [$pts]]
            : ['type' => 'LineString', 'coordinates' => $pts];
        $geoFeatures[] = [
            'type' => 'Feature',
            'geometry' => $geom,
            'properties' => array_filter([
                'name' => $name,
                'description' => $desc,
                'stroke' => $color,
            ], fn($v) => $v !== '' && $v !== null),
        ];
    }
    $doc = ['type' => 'FeatureCollection', 'features' => $geoFeatures];
    if ($warning) {
        $doc['properties'] = ['avviso' => $warning];
    }
    header('Content-Type: application/geo+json');
    header('Content-Disposition: attachment; filename="' . $slugBase . '.geojson"');
    echo json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// KML
$esc = fn($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
$kmlColor = function (?string $hex): string {
    // #rrggbb -> aabbggrr (KML) con alpha piena
    $hex = ltrim((string) $hex, '#');
    if (strlen($hex) !== 6) {
        return 'ff00f2ff';
    }
    return 'ff' . substr($hex, 4, 2) . substr($hex, 2, 2) . substr($hex, 0, 2);
};

$placemarks = '';
$styleIds = [];
foreach ($features as $idx => [$type, $pts, $name, $desc, $color]) {
    $sid = 's' . $idx;
    $styleIds[$sid] = $kmlColor($color);
    $coordStr = implode(' ', array_map(fn($p) => $p[0] . ',' . $p[1] . ',0', $pts));
    $geom = $type === 'polygon'
        ? "<Polygon><outerBoundaryIs><LinearRing><coordinates>$coordStr</coordinates></LinearRing></outerBoundaryIs></Polygon>"
        : "<LineString><coordinates>$coordStr</coordinates></LineString>";
    $placemarks .= "<Placemark><name>{$esc($name)}</name>"
        . ($desc !== '' ? "<description>{$esc($desc)}</description>" : '')
        . "<styleUrl>#$sid</styleUrl>$geom</Placemark>\n";
}
$styles = '';
foreach ($styleIds as $sid => $abgr) {
    $styles .= "<Style id=\"$sid\"><LineStyle><color>$abgr</color><width>2</width></LineStyle>"
        . "<PolyStyle><color>33" . substr($abgr, 2) . "</color></PolyStyle></Style>\n";
}

header('Content-Type: application/vnd.google-earth.kml+xml');
header('Content-Disposition: attachment; filename="' . $slugBase . '.kml"');
echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
    . "<kml xmlns=\"http://www.opengis.net/kml/2.2\"><Document>"
    . "<name>{$esc($capture['label'] ?: ('Ripresa #' . $captureId))} — OrbitalEye</name>"
    . ($warning ? "<description>Attenzione: {$esc($warning)}</description>" : '')
    . $styles . $placemarks
    . "</Document></kml>";
