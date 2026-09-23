<?php
require __DIR__ . '/../src/bootstrap.php';
Auth::requireLogin();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $areaName = trim($_POST['area_name'] ?? '') ?: null;
    $notes = trim($_POST['notes'] ?? '') ?: null;

    // Area di interesse: facoltativa, ma se indicata deve essere valida. È il
    // ripiego per la scala delle misure, la stima delle ombre e l'export
    // geografico delle riprese caricate a mano: prima veniva salvata senza
    // controlli — una virgola decimale ("10,23" -> 10), un testo (-> 0) o
    // min/max invertiti producevano in silenzio un'area sbagliata.
    $bbox = null;
    $raw = [];
    foreach (['min_lon', 'min_lat', 'max_lon', 'max_lat'] as $k) {
        $raw[$k] = str_replace(',', '.', trim((string) ($_POST[$k] ?? '')));
    }
    $filled = count(array_filter($raw, fn($v) => $v !== ''));
    if ($filled > 0 && $filled < 4) {
        $error = 'Area di interesse incompleta: compila tutte e quattro le coordinate, oppure nessuna.';
    } elseif ($filled === 4) {
        if (array_filter($raw, fn($v) => !is_numeric($v))) {
            $error = 'Coordinate non valide: usa numeri decimali (es. 14.9123).';
        } else {
            [$minLon, $minLat, $maxLon, $maxLat] = array_map('floatval', array_values($raw));
            if ($minLon >= $maxLon || $minLat >= $maxLat) {
                $error = 'Area non valida: "Min Lon" deve essere minore di "Max Lon" e "Min Lat" minore di "Max Lat" (hai forse invertito due valori?).';
            } elseif ($minLon < -180 || $maxLon > 180 || $minLat < -90 || $maxLat > 90) {
                $error = 'Area fuori range: longitudine tra -180 e 180, latitudine tra -90 e 90.';
            } else {
                $bbox = [$minLon, $minLat, $maxLon, $maxLat];
            }
        }
    }

    if ($error) {
        // già impostato dalla validazione dell'area
    } elseif ($title === '') {
        $error = 'Il titolo dello studio è obbligatorio.';
    } else {
        $id = Study::create($title, $areaName, $bbox, $notes);
        header('Location: study.php?id=' . $id);
        exit;
    }
}

$pageTitle = 'Nuovo Studio';
$activeNav = 'new_study';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/nav.php';
?>

<div class="panel" style="max-width:640px;">
  <?php if ($error): ?><div class="alert alert-error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <div class="field">
      <label>Titolo studio *</label>
      <input type="text" name="title" value="<?= e($_POST['title'] ?? '') ?>" placeholder="es. Sito industriale — Settore 7" required autofocus>
    </div>
    <div class="field">
      <label>Nome / descrizione area</label>
      <input type="text" name="area_name" value="<?= e($_POST['area_name'] ?? '') ?>" placeholder="es. Zona costiera nord, Regione X">
    </div>
    <div class="field">
      <label>Bounding box geografica (opzionale, per fetch automatico)</label>
      <div class="stage-toolbar">
        <div class="mode-toggle">
          <button type="button" class="mode-btn active" id="ns-map-draw" title="Trascina per disegnare l'area">✎ Disegna area</button>
          <button type="button" class="mode-btn" id="ns-map-pan" title="Trascina per spostare la mappa">✋ Sposta mappa</button>
        </div>
        <div class="mode-toggle">
          <button type="button" class="mode-btn active" id="ns-map-osm" title="Mappa stradale (Esri World Street Map)">🗺 Mappa</button>
          <button type="button" class="mode-btn" id="ns-map-sat" title="Vista satellitare (solo per riconoscimento visivo)">🛰 Satellite</button>
        </div>
      </div>
      <div id="ns-map-picker" style="height:320px; border:1px solid var(--line-bright); border-radius:3px; margin-bottom:14px;"></div>
      <div class="grid grid-4">
        <input type="text" id="ns-min-lon" name="min_lon" value="<?= e($_POST['min_lon'] ?? '') ?>" placeholder="min lon">
        <input type="text" id="ns-min-lat" name="min_lat" value="<?= e($_POST['min_lat'] ?? '') ?>" placeholder="min lat">
        <input type="text" id="ns-max-lon" name="max_lon" value="<?= e($_POST['max_lon'] ?? '') ?>" placeholder="max lon">
        <input type="text" id="ns-max-lat" name="max_lat" value="<?= e($_POST['max_lat'] ?? '') ?>" placeholder="max lat">
      </div>
      <div class="hint">Disegna l'area sulla mappa (o scrivi le coordinate EPSG:4326/WGS84 a mano). Se non la imposti ora potrai comunque caricare immagini manualmente.</div>
    </div>
    <div class="field">
      <label>Note operative</label>
      <textarea name="notes" rows="4" placeholder="Contesto, obiettivo dell'analisi, riferimenti..."><?= e($_POST['notes'] ?? '') ?></textarea>
    </div>
    <button class="btn btn-primary" type="submit">Crea studio</button>
  </form>
</div>

<!-- Leaflet 1.9.4 servito localmente (assets/leaflet/), non da CDN: ogni
     apertura di questa pagina comunicava altrimenti a unpkg.com IP,
     user-agent e referer dell'analista — incoerente con l'attenzione
     OPSEC seguita ovunque nel resto della piattaforma — e la mappa di
     selezione dell'area smetteva di funzionare se il CDN era
     irraggiungibile. I file sono identici a quelli ufficiali: hash
     SHA-256 verificati contro gli attributi integrity usati prima. -->
<link rel="stylesheet" href="assets/leaflet/leaflet.css">
<script src="assets/leaflet/leaflet.js"></script>
<script src="assets/js/map-picker.js?v=<?= @filemtime(__DIR__ . '/assets/js/map-picker.js') ?: time() ?>"></script>
<script>
initMapPicker(
  'ns-map-picker',
  { minLon: 'ns-min-lon', minLat: 'ns-min-lat', maxLon: 'ns-max-lon', maxLat: 'ns-max-lat' },
  { draw: 'ns-map-draw', pan: 'ns-map-pan', mapView: 'ns-map-osm', satView: 'ns-map-sat' }
);
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
