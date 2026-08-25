<?php
require __DIR__ . '/../src/bootstrap.php';
Auth::requireLogin();

$studies = Study::all();

$pdo = Database::get();
$totalCaptures = (int) $pdo->query('SELECT COUNT(*) c FROM captures')->fetch()['c'];
$totalComparisons = (int) $pdo->query('SELECT COUNT(*) c FROM comparisons')->fetch()['c'];
$totalLibrary = (int) $pdo->query('SELECT COUNT(*) c FROM comparisons WHERE is_saved_to_library = 1')->fetch()['c'];
$totalAnnotations = (int) $pdo->query('SELECT COUNT(*) c FROM annotations')->fetch()['c'];

$pageTitle = 'Dashboard Operativa';
$activeNav = 'dashboard';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/nav.php';
?>

<div class="grid grid-4">
  <div class="stat-tile"><div class="value"><?= count($studies) ?></div><div class="label">Studi attivi</div></div>
  <div class="stat-tile"><div class="value"><?= $totalCaptures ?></div><div class="label">Riprese archiviate</div></div>
  <div class="stat-tile"><div class="value"><?= $totalComparisons ?></div><div class="label">Confronti eseguiti</div></div>
  <div class="stat-tile"><div class="value"><?= $totalAnnotations ?></div><div class="label">Annotazioni</div></div>
</div>

<div class="panel">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
    <h2 style="margin:0;">Studi recenti</h2>
    <a class="btn btn-primary" href="new_study.php">+ Nuovo studio</a>
  </div>

  <?php if (empty($studies)): ?>
    <div class="empty-state">
      <div class="glyph">◈</div>
      <div>Nessuno studio ancora creato.</div>
      <div class="hint">Crea il primo studio per iniziare a confrontare riprese satellitari di un&rsquo;area.</div>
    </div>
  <?php else: ?>
    <div class="table-responsive">
    <table>
      <thead>
        <tr><th>Titolo</th><th>Area</th><th>Creato</th><th>Aggiornato</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($studies as $s): $c = Study::counts((int)$s['id']); ?>
          <tr>
            <td><a href="study.php?id=<?= (int)$s['id'] ?>"><?= e($s['title']) ?></a></td>
            <td><?= e($s['area_name'] ?: '—') ?></td>
            <td><?= e($s['created_at']) ?></td>
            <td><?= e($s['updated_at']) ?></td>
            <td>
              <span class="badge badge-cyan"><?= $c['captures'] ?> riprese</span>
              <span class="badge badge-amber"><?= $c['comparisons'] ?> confronti</span>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
