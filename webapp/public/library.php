<?php
require __DIR__ . '/../src/bootstrap.php';
Auth::requireLogin();

$search = trim($_GET['q'] ?? '');
$items = Comparison::library($search ?: null);

$pageTitle = 'Libreria studi';
$activeNav = 'library';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/nav.php';
?>

<div class="panel">
  <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
    <form method="get" style="display:flex; gap:10px; flex:1; min-width:260px;">
      <input type="search" name="q" placeholder="Cerca per titolo, studio o area..." value="<?= e($search) ?>">
      <button class="btn btn-primary" type="submit">Cerca</button>
    </form>
    <?php if (!empty($items)): ?>
      <div style="display:flex; gap:8px;">
        <button type="button" class="btn btn-sm" id="export-selected-btn" disabled>⬇ Esporta selezionati (<span id="selected-count">0</span>)</button>
        <a class="btn btn-sm btn-primary" href="export_library.php<?= $search ? '?q=' . urlencode($search) : '' ?>">⬇ Esporta tutta la libreria</a>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if (empty($items)): ?>
  <div class="panel">
    <div class="empty-state">
      <div class="glyph">▤</div>
      <div>Nessun confronto salvato in libreria.</div>
      <div class="hint">Dalla pagina di uno studio, esegui un confronto e usa &laquo;Salva in libreria&raquo;.</div>
    </div>
  </div>
<?php else: ?>
  <div class="grid grid-3">
    <?php foreach ($items as $item): $paths = json_decode($item['result_paths_json'], true); $stats = json_decode($item['stats_json'], true); ?>
      <div class="panel">
        <label class="checkbox-row" style="margin-bottom:8px; cursor:pointer;">
          <input type="checkbox" class="export-select" value="<?= (int)$item['id'] ?>">
          <span style="margin:0; text-transform:none; letter-spacing:normal; font-size:11px; color:var(--text-muted);">Seleziona per export multiplo</span>
        </label>
        <img src="<?= e(storage_url($paths['overlay'])) ?>" style="width:100%; border-radius:3px; border:1px solid var(--line); margin-bottom:10px;">
        <h3 style="color:var(--text-primary);"><?= e($item['title'] ?: 'Confronto #' . $item['id']) ?></h3>
        <div class="hint"><?= e($item['study_title']) ?> · <?= e($item['study_area'] ?: '—') ?></div>
        <div style="margin:10px 0;">
          <span class="badge badge-amber"><?= round(($stats['changed_ratio'] ?? 0) * 100, 2) ?>% variazione</span>
          <span class="badge badge-cyan"><?= $stats['num_regions'] ?? 0 ?> regioni</span>
        </div>
        <div class="hint" style="margin-bottom:10px;"><?= e($item['created_at']) ?></div>
        <div style="display:flex; gap:6px; flex-wrap:wrap;">
          <a class="btn btn-sm btn-primary" href="study.php?id=<?= (int)$item['study_id'] ?>&comparison=<?= (int)$item['id'] ?>">Apri</a>
          <a class="btn btn-sm" href="export_comparison.php?id=<?= (int)$item['id'] ?>" title="Scarica ZIP (immagini + report)">⬇ Esporta</a>
          <button class="btn btn-sm btn-danger" onclick="deleteEntity('comparison', <?= (int)$item['id'] ?>)">Rimuovi</button>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<script>
(function () {
  const checkboxes = Array.from(document.querySelectorAll('.export-select'));
  const exportBtn = document.getElementById('export-selected-btn');
  const countEl = document.getElementById('selected-count');
  if (!exportBtn) return;

  function refresh() {
    const selected = checkboxes.filter((c) => c.checked);
    countEl.textContent = selected.length;
    exportBtn.disabled = selected.length === 0;
  }
  checkboxes.forEach((c) => c.addEventListener('change', refresh));

  exportBtn.addEventListener('click', () => {
    const ids = checkboxes.filter((c) => c.checked).map((c) => c.value).join(',');
    if (!ids) return;
    window.location.href = 'export_library.php?ids=' + encodeURIComponent(ids);
  });
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
