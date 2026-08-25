<?php
require __DIR__ . '/../src/bootstrap.php';
Auth::requireLogin();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $areaName = trim($_POST['area_name'] ?? '') ?: null;
    $notes = trim($_POST['notes'] ?? '') ?: null;

    $bbox = null;
    $minLon = $_POST['min_lon'] ?? '';
    $minLat = $_POST['min_lat'] ?? '';
    $maxLon = $_POST['max_lon'] ?? '';
    $maxLat = $_POST['max_lat'] ?? '';
    if ($minLon !== '' && $minLat !== '' && $maxLon !== '' && $maxLat !== '') {
        $bbox = [(float)$minLon, (float)$minLat, (float)$maxLon, (float)$maxLat];
    }

    if ($title === '') {
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
      <input type="text" name="title" placeholder="es. Sito industriale — Settore 7" required autofocus>
    </div>
    <div class="field">
      <label>Nome / descrizione area</label>
      <input type="text" name="area_name" placeholder="es. Zona costiera nord, Regione X">
    </div>
    <div class="field">
      <label>Bounding box geografica (opzionale, per fetch automatico)</label>
      <div class="stage-toolbar">
        <div class="mode-toggle">
          <button type="button" class="mode-btn active" id="ns-map-draw" title="Trascina per disegnare l'area">✎ Disegna area</button>
          <button type="button" class="mode-btn" id="ns-map-pan" title="Trascina per spostare la mappa">✋ Sposta mappa</button>
        </div>
        <div class="mode-toggle">
          <button type="button" class="mode-btn active" id="ns-map-osm" title="Mappa stradale (OpenStreetMap)">🗺 Mappa</button>
          <button type="button" class="mode-btn" id="ns-map-sat" title="Vista satellitare (solo per riconoscimento visivo)">🛰 Satellite</button>
        </div>
      </div>
      <div id="ns-map-picker" style="height:320px; border:1px solid var(--line-bright); border-radius:3px; margin-bottom:14px;"></div>
      <div class="grid grid-4">
        <input type="text" id="ns-min-lon" name="min_lon" placeholder="min lon">
        <input type="text" id="ns-min-lat" name="min_lat" placeholder="min lat">
        <input type="text" id="ns-max-lon" name="max_lon" placeholder="max lon">
        <input type="text" id="ns-max-lat" name="max_lat" placeholder="max lat">
      </div>
      <div class="hint">Disegna l'area sulla mappa (o scrivi le coordinate EPSG:4326/WGS84 a mano). Se non la imposti ora potrai comunque caricare immagini manualmente.</div>
    </div>
    <div class="field">
      <label>Note operative</label>
      <textarea name="notes" rows="4" placeholder="Contesto, obiettivo dell'analisi, riferimenti..."></textarea>
    </div>
    <button class="btn btn-primary" type="submit">Crea studio</button>
  </form>
</div>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="assets/js/map-picker.js?v=<?= @filemtime(__DIR__ . '/assets/js/map-picker.js') ?: time() ?>"></script>
<script>
initMapPicker(
  'ns-map-picker',
  { minLon: 'ns-min-lon', minLat: 'ns-min-lat', maxLon: 'ns-max-lon', maxLat: 'ns-max-lat' },
  { draw: 'ns-map-draw', pan: 'ns-map-pan', mapView: 'ns-map-osm', satView: 'ns-map-sat' }
);
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
