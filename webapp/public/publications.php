<?php
require __DIR__ . '/../src/bootstrap.php';
Auth::requireLogin();

/**
 * Pubblicazioni: la coda di revisione (vedi Publication) — ogni contenuto
 * con le immagini esattamente come usciranno, la didascalia modificabile e
 * i pulsanti per pubblicarlo sul canale o scartarlo — e il registro di
 * tutto ciò che è stato reso pubblico (tabella shares).
 */
$publications = Publication::recent(60);
$shares = Share::recent(100);
$settings = AppSettings::all();
$reviewConfigured = trim((string) ($settings['telegram_review_chat_id'] ?? '')) !== '';
$kindLabel = ['capture' => 'Ripresa', 'comparison' => 'Confronto', 'study' => 'Riepilogo di studio'];
$formatLabel = ['none' => 'solo immagine', 'strip' => 'striscia data e fonte', 'card' => 'scheda', 'pair' => 'Prima e Dopo affiancate'];
$statusLabel = ['pending' => ['in attesa', 'badge-amber'], 'sending' => ['in invio', 'badge-amber'], 'published' => ['pubblicata', 'badge-green'], 'rejected' => ['scartata', 'badge-magenta']];

$pageTitle = 'Pubblicazioni';
$activeNav = 'publications';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/nav.php';
?>

<div class="panel">
  <h2>📤 Coda di revisione</h2>
  <div class="hint" style="margin-bottom:12px;">
    <?php if ($reviewConfigured): ?>
      Le condivisioni Telegram inviate con "Passa dalla revisione" arrivano prima alla chat di revisione e restano qui finché non le approvi — e solo allora partono, identiche, verso il canale — o le scarti.
    <?php else: ?>
      Nessuna chat di revisione configurata: le condivisioni Telegram partono direttamente verso il canale. Per farle passare prima da una chat privata (un gruppo con chi deve approvare), impostala in <a href="settings.php">Impostazioni → Telegram</a>.
    <?php endif; ?>
  </div>
  <?php if (!$publications): ?>
    <div class="empty-state">Nessuna pubblicazione in coda.</div>
  <?php endif; ?>
  <?php foreach ($publications as $p):
      $st = $statusLabel[$p['status']] ?? [$p['status'], ''];
      $open = in_array($p['status'], ['pending', 'sending'], true); ?>
    <div class="pub-item" id="pub-<?= (int)$p['id'] ?>" data-id="<?= (int)$p['id'] ?>" style="border-top:1px solid var(--line); padding:12px 0;">
      <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:baseline;">
        <div>
          <strong>#<?= (int)$p['id'] ?> · <?= e($kindLabel[$p['kind']] ?? $p['kind']) ?></strong>
          <span class="hint"> — <?= e($p['summary'] ?? '') ?><?= $p['study_title'] ? ' · ' . e($p['study_title']) : '' ?> · <?= e($formatLabel[$p['format']] ?? $p['format']) ?></span>
        </div>
        <div><span class="badge <?= $st[1] ?>"><?= e($st[0]) ?></span> <span class="hint"><?= format_datetime_it($p['created_at']) ?><?= $p['decided_at'] ? ' → ' . format_datetime_it($p['decided_at']) : '' ?></span></div>
      </div>
      <?php if ($p['media']): ?>
        <div class="tag-row" style="margin:8px 0; align-items:flex-start;">
          <?php foreach ($p['media'] as $m): ?>
            <a href="<?= e(storage_url($m['path'])) ?>" target="_blank" rel="noopener" title="Apri a piena dimensione"><img src="<?= e(storage_url($m['path'])) ?>" alt="" style="max-height:220px; max-width:100%; border:1px solid var(--line-bright); border-radius:3px;"></a>
          <?php endforeach; ?>
        </div>
        <?php if ($p['document']): ?><div class="hint">+ file a piena risoluzione: <?= e($p['document']['name']) ?></div><?php endif; ?>
      <?php endif; ?>
      <?php if ($open): ?>
        <textarea class="pub-caption" rows="4" style="width:100%; margin-top:6px;" maxlength="<?= TelegramClient::CAPTION_LIMIT ?>"><?= e($p['caption'] ?? '') ?></textarea>
        <div class="tag-row" style="margin-top:6px;">
          <button type="button" class="btn btn-primary btn-sm pub-approve">✅ Approva e pubblica sul canale</button>
          <button type="button" class="btn btn-danger btn-sm pub-reject">✖ Scarta</button>
          <span class="hint pub-status"><?= $p['error'] ? '⚠ ' . e($p['error']) : '' ?></span>
        </div>
      <?php else: ?>
        <div style="white-space:pre-wrap; margin-top:6px;" class="hint"><?= e($p['caption'] ?? '') ?></div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<div class="panel">
  <h2>Registro delle condivisioni</h2>
  <div class="hint" style="margin-bottom:10px;">Tutto ciò che è stato reso pubblico, e dove: invii al canale Telegram (anche dopo approvazione) e aperture della finestra di composizione X.</div>
  <?php if (!$shares): ?>
    <div class="empty-state">Nessuna condivisione registrata.</div>
  <?php else: ?>
    <div class="table-responsive">
    <table>
      <thead><tr><th>Data</th><th>Dove</th><th>Cosa</th><th>Studio</th><th>Didascalia</th></tr></thead>
      <tbody>
        <?php foreach ($shares as $s): ?>
          <tr>
            <td class="hint" style="white-space:nowrap;"><?= format_datetime_it($s['created_at']) ?></td>
            <td><?= $s['platform'] === 'telegram' ? 'Telegram' : 'X' ?></td>
            <td><?= e($kindLabel[$s['kind']] ?? $s['kind']) ?><?= $s['ref_id'] ? ' #' . (int)$s['ref_id'] : '' ?></td>
            <td><?= $s['study_id'] ? '<a href="study.php?id=' . (int)$s['study_id'] . '">' . e($s['study_title'] ?? ('#' . $s['study_id'])) . '</a>' : '—' ?></td>
            <td class="hint" style="max-width:420px; white-space:pre-wrap;"><?= e(mb_strimwidth((string) $s['caption'], 0, 220, '…')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<script>
document.querySelectorAll('.pub-item').forEach((item) => {
  const id = parseInt(item.dataset.id, 10);
  const status = item.querySelector('.pub-status');
  const buttons = item.querySelectorAll('.pub-approve, .pub-reject');
  async function decide(action) {
    const caption = item.querySelector('.pub-caption').value;
    if (action === 'approve' && !confirm('Pubblicare sul canale la pubblicazione #' + id + '? Diventerà pubblica.')) return;
    if (action === 'reject' && !confirm('Scartare la pubblicazione #' + id + '? Le immagini preparate vengono eliminate.')) return;
    buttons.forEach((b) => (b.disabled = true));
    status.textContent = action === 'approve' ? 'Pubblicazione in corso…' : 'Scarto…';
    try {
      const res = await fetch('api/publications.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, id, caption }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Errore');
      location.reload();
    } catch (err) {
      status.textContent = 'Errore: ' + err.message;
      buttons.forEach((b) => (b.disabled = false));
    }
  }
  const approve = item.querySelector('.pub-approve');
  if (approve) approve.addEventListener('click', () => decide('approve'));
  const reject = item.querySelector('.pub-reject');
  if (reject) reject.addEventListener('click', () => decide('reject'));
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
