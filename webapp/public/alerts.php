<?php
require __DIR__ . '/../src/bootstrap.php';
Auth::requireLogin();

// Solo via POST: con un semplice link (GET), un qualunque altro sito poteva
// far segnare tutti gli alert come letti — il cookie di sessione SameSite=Lax
// viaggia anche sulle navigazioni GET da altri siti — nascondendo proprio
// una rilevazione di cambiamento. Le richieste POST da altri siti invece
// non portano il cookie.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mark_all_read'] ?? '') === '1') {
    Alert::markAllRead();
    header('Location: alerts.php');
    exit;
}

$alerts = Alert::recent(200);

$pageTitle = 'Alert';
$activeNav = 'alerts';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/nav.php';
?>

<div class="panel">
  <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
    <div class="hint">Notifiche generate dagli scaricamenti automatici pianificati (attivabili dalla pagina di uno studio, sezioni Sentinel Hub/Esri): appena arriva una ripresa diversa dalla precedente, compare qui. Le riprese identiche alla precedente vengono scartate automaticamente, senza generare alert.</div>
    <?php if (!empty($alerts)): ?>
      <form method="post" style="margin:0;"><input type="hidden" name="mark_all_read" value="1"><button type="submit" class="btn btn-sm">✓ Segna tutti come letti</button></form>
    <?php endif; ?>
  </div>
</div>

<?php if (empty($alerts)): ?>
  <div class="panel">
    <div class="empty-state">
      <div class="glyph">🔔</div>
      <div>Nessun alert.</div>
      <div class="hint">Attiva uno scaricamento automatico pianificato dalla pagina di uno studio (sezione Sentinel Hub o Esri) per iniziare a ricevere notifiche quando arriva una ripresa diversa dalla precedente.</div>
    </div>
  </div>
<?php else: ?>
  <div class="panel">
    <div class="table-responsive">
    <table>
      <thead>
        <tr><th></th><th>Studio</th><th>Messaggio</th><th>Quando</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($alerts as $a): ?>
          <tr data-alert-id="<?= (int)$a['id'] ?>">
            <td><?= $a['is_read'] ? '' : '<span class="status-dot bad" title="Non letto"></span>' ?></td>
            <td><a href="study.php?id=<?= (int)$a['study_id'] ?>"><?= e($a['study_name']) ?></a></td>
            <td>
              <?= e($a['message']) ?>
              <?php if ($a['capture_relative_path']): ?>
                <a href="analyze_capture.php?id=<?= (int)$a['capture_id'] ?>" style="margin-left:8px;">🔬 Apri ripresa</a>
              <?php endif; ?>
            </td>
            <td class="hint"><?= format_datetime_it($a['created_at']) ?></td>
            <td>
              <?php if (!$a['is_read']): ?>
                <button type="button" class="btn btn-sm mark-read-btn" data-id="<?= (int)$a['id'] ?>">Segna letto</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
<?php endif; ?>

<script>
document.querySelectorAll('.mark-read-btn').forEach((btn) => {
  btn.addEventListener('click', async () => {
    const id = parseInt(btn.dataset.id, 10);
    await fetch('api/alerts.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'mark_read', id }),
    });
    window.location.reload();
  });
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
