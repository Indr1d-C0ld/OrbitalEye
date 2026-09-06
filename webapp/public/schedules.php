<?php
require __DIR__ . '/../src/bootstrap.php';
Auth::requireLogin();

$schedules = ScheduledDownload::allWithStudy();

$pageTitle = 'Pianificazioni';
$activeNav = 'schedules';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/nav.php';

$sourceLabel = ['sentinelhub' => 'Sentinel Hub', 'esri' => 'Esri World Imagery'];
$resultLabel = ['new' => '✓ nuova ripresa', 'duplicate' => '= scartata (duplicato)', 'error' => '✕ errore'];
?>

<div class="panel">
  <div class="hint">Riepilogo di tutti gli scaricamenti automatici pianificati, di tutti gli studi — stesso controllo (sospendi/riattiva/elimina) disponibile qui che nella sezione scaricamento di ogni singolo studio, ma senza doverli aprire uno per uno per vedere se è tutto a posto.</div>
</div>

<?php if (empty($schedules)): ?>
  <div class="panel">
    <div class="empty-state">
      <div class="glyph">⏱</div>
      <div>Nessuna pianificazione attiva.</div>
      <div class="hint">Attiva uno scaricamento automatico pianificato dalla pagina di uno studio (sezione Sentinel Hub o Esri) per iniziare.</div>
    </div>
  </div>
<?php else: ?>
  <div class="panel">
    <div class="table-responsive">
    <table>
      <thead>
        <tr><th></th><th>Studio</th><th>Fonte</th><th>Cadenza</th><th>Soglia duplicati</th><th>Ultimo controllo</th><th>Esito</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($schedules as $s): ?>
          <tr data-schedule-id="<?= (int)$s['id'] ?>">
            <td><span class="status-dot <?= $s['is_active'] ? ($s['last_result'] === 'error' ? 'bad' : 'ok') : '' ?>" title="<?= $s['is_active'] ? 'Attiva' : 'Sospesa' ?>"></span></td>
            <td><a href="study.php?id=<?= (int)$s['study_id'] ?>"><?= e($s['study_title']) ?></a></td>
            <td><?= e($sourceLabel[$s['source']] ?? $s['source']) ?></td>
            <td>ogni <?= (int)$s['interval_days'] ?> giorni</td>
            <td><?= e(number_format((float)$s['duplicate_threshold'] * 100, 2)) ?>%</td>
            <td class="hint"><?= $s['last_run_at'] ? format_datetime_it($s['last_run_at']) : 'mai eseguita' ?></td>
            <td>
              <?= e($resultLabel[$s['last_result']] ?? 'in attesa del primo controllo') ?>
              <?php if ($s['last_result'] === 'error' && $s['last_error']): ?>
                <span class="info-tip" tabindex="0" data-tip="<?= e($s['last_error']) ?>">?</span>
              <?php endif; ?>
              <?php if (!$s['is_active']): ?><br><span class="hint">sospesa</span><?php endif; ?>
            </td>
            <td style="white-space:nowrap;">
              <button type="button" class="btn btn-sm sched-toggle-btn" data-id="<?= (int)$s['id'] ?>"><?= $s['is_active'] ? 'Sospendi' : 'Riattiva' ?></button>
              <button type="button" class="btn btn-sm btn-danger sched-delete-btn" data-id="<?= (int)$s['id'] ?>">Elimina</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
<?php endif; ?>

<script>
// Stessa API già usata nella sezione scaricamento di ogni studio
// (assets/js/study.js) — qui il riepilogo è globale, quindi dopo ogni
// azione si ricarica semplicemente la pagina (stesso schema di alerts.php),
// niente re-render parziale: sono azioni rare, non serve altro.
document.querySelectorAll('.sched-toggle-btn').forEach((btn) => {
  btn.addEventListener('click', async () => {
    btn.disabled = true;
    try {
      await fetch('api/schedule_download.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'toggle', id: parseInt(btn.dataset.id, 10) }),
      });
      window.location.reload();
    } catch (err) { btn.disabled = false; }
  });
});
document.querySelectorAll('.sched-delete-btn').forEach((btn) => {
  btn.addEventListener('click', async () => {
    if (!confirm('Eliminare questa pianificazione? Le riprese già scaricate restano, solo il controllo automatico si ferma.')) return;
    btn.disabled = true;
    try {
      await fetch('api/schedule_download.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id: parseInt(btn.dataset.id, 10) }),
      });
      window.location.reload();
    } catch (err) { btn.disabled = false; }
  });
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
