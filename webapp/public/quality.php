<?php
require __DIR__ . '/../src/bootstrap.php';
Auth::requireLogin();

/**
 * Qualità del rilevamento automatico, misurata sulle verifiche dell'analista
 * (vedi DetectionQuality e DetectionReview).
 */
$q = DetectionQuality::compute();
$q['identification']['libreria'] = VisualLibrary::evaluation();
$captureInfo = [];
foreach ($q['captures'] as $c) {
    $cap = Capture::find($c['capture_id']);
    if ($cap) {
        $study = Study::find((int) $cap['study_id']);
        $captureInfo[$c['capture_id']] = ['label' => $cap['label'] ?: ('Ripresa #' . $cap['id']), 'study' => $study['title'] ?? ''];
    }
}
$pct = fn($v) => $v === null ? '—' : round($v * 100) . '%';
$share = fn($n, $tot) => $tot ? round($n / $tot * 100) . '%' : '—';

$pageTitle = 'Qualità del rilevamento';
$activeNav = 'quality';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/nav.php';
?>

<div class="panel">
  <div class="hint">Quanto sbaglia il rilevamento automatico, misurato sulle tue verifiche (✓ / ✗ / ✎ nel pannello del rilevamento di ogni ripresa, 🎯 per gli oggetti non trovati). Si ricalcola sull'ultimo rilevamento di ogni ripresa con il codice attuale: un miglioramento del rilevamento o della tabella dei tipi si vede subito sugli stessi oggetti. <strong>Precisione</strong>: degli oggetti trovati e verificati, quanti erano giusti. <strong>Richiamo</strong>: degli oggetti veri, quanti sono stati trovati — solo sulle riprese segnate come "verifica completa" (<?= (int) $q['complete_captures'] ?> finora).</div>
</div>

<?php if (!$q['captures']): ?>
  <div class="panel">
    <div class="empty-state">
      <div class="glyph">🎯</div>
      <div>Nessuna verifica ancora.</div>
      <div class="hint">Apri una ripresa con un rilevamento (Analizza), conferma o scarta gli oggetti trovati e indica il tipo dei velivoli che riconosci: le misure compariranno qui.</div>
    </div>
  </div>
<?php else: ?>
  <div class="panel">
    <h2>Per classe</h2>
    <div class="table-responsive">
    <table>
      <thead><tr><th>Classe</th><th>Giusti</th><th>Falsi</th><th>di cui classe sbagliata</th><th>Sfuggiti</th><th>Precisione</th><th>Richiamo</th></tr></thead>
      <tbody>
        <?php foreach (DetectionQuality::GROUPS as $g => $label): $c = $q['classes'][$g]; if (!$c['tp'] && !$c['fp'] && !$c['fn']) continue; ?>
          <tr>
            <td><?= e($label) ?></td><td><?= (int) $c['tp'] ?></td><td><?= (int) $c['fp'] ?></td><td><?= (int) $c['wrong_class'] ?></td>
            <td><?= (int) $c['fn'] ?></td><td><?= e($pct($c['precision'])) ?></td><td><?= e($pct($c['recall'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <div class="panel">
    <h2>Per confidenza del rilevatore</h2>
    <div class="hint" style="margin-bottom:8px;">Precisione degli oggetti verificati per fascia di confidenza: aiuta a scegliere la soglia ("Confidenza minima") oltre la quale fidarsi.</div>
    <div class="table-responsive">
    <table>
      <thead><tr><th>Confidenza</th><th>Giusti</th><th>Falsi</th><th>Precisione</th></tr></thead>
      <tbody>
        <?php foreach ($q['bands'] as $b): if (!$b['tp'] && !$b['fp']) continue; ?>
          <tr><td><?= round($b['from'] * 100) ?>–<?= round($b['to'] * 100) ?>%</td><td><?= (int) $b['tp'] ?></td><td><?= (int) $b['fp'] ?></td><td><?= e($pct($b['precision'])) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <div class="panel">
    <h2>Identificazione dei velivoli</h2>
    <div class="hint" style="margin-bottom:8px;">Per i velivoli confermati con il tipo: quante volte il tipo vero è il primo dei compatibili, fra i primi tre, o non compare. <strong>Sagoma</strong>: apertura e lunghezza misurate sul contorno dell'aereo, con l'ombra tolta. <strong>Riquadro</strong>: i lati del riquadro del rilevamento, che includono margine e ombra (il metodo precedente). Lo stesso aereo conta in entrambe le righe se ha entrambe le misure. <strong>Libreria visiva</strong>: per ogni velivolo identificato, il tipo dei più simili fra quelli identificati in <em>altre</em> riprese (somiglianza dell'immagine, non delle misure); "tipo vero assente" vuol dire che non è fra i primi tre. Conta solo i tipi identificati in almeno due riprese. <strong>Classificatore MAR20</strong>: il tipo vero fra i tre più probabili dei 20 militari di MAR20, per aspetto; conta solo i velivoli di quei 20 tipi (o loro varianti, come il C-130J per il C-130). Gli aerei di altri tipi contano nella riga sotto la tabella. <strong>Scafo</strong>: per le navi confermate con la classe, la sua posizione fra le classi compatibili con lunghezza e larghezza dello scafo misurato.</div>
    <div class="table-responsive">
    <table>
      <thead><tr><th>Metodo</th><th>Velivoli</th><th>Primo</th><th>Fra i primi 3</th><th>Tipo vero assente</th></tr></thead>
      <tbody>
        <?php foreach (['sagoma' => 'Sagoma', 'riquadro' => 'Riquadro', 'libreria' => 'Libreria visiva', 'mar20' => 'Classificatore MAR20', 'scafo' => 'Scafo (navi)'] as $k => $label): $s = $q['identification'][$k]; ?>
          <tr><td><?= e($label) ?></td><td><?= (int) $s['n'] ?></td><td><?= (int) $s['top1'] ?> (<?= e($share($s['top1'], $s['n'])) ?>)</td>
            <td><?= (int) $s['top3'] ?> (<?= e($share($s['top3'], $s['n'])) ?>)</td><td><?= (int) $s['absent'] ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php $star = $q['mar20_star']; ?>
    <div class="hint" style="margin-top:8px;">★ MAR20 (tipo molto probabile e misure compatibili) data su <?= (int) $star['n'] ?> <?= $star['n'] === 1 ? 'velivolo identificato' : 'velivoli identificati' ?><?= $star['n'] ? ', giusta ' . (int) $star['right'] . ($star['right'] === 1 ? ' volta' : ' volte') . ' (' . e($share($star['right'], $star['n'])) . ')' : '' ?>. Gli errori qui sono soprattutto aerei di tipi che MAR20 non conosce scambiati per uno dei suoi 20.</div>
  </div>

  <div class="panel">
    <h2>Riprese verificate</h2>
    <div class="table-responsive">
    <table>
      <thead><tr><th>Ripresa</th><th>Studio</th><th>Verifiche</th><th>Oggetti rilevati</th><th>Non verificati</th><th>Veri non trovati</th><th>Completa</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($q['captures'] as $c): $info = $captureInfo[$c['capture_id']] ?? ['label' => '#' . $c['capture_id'], 'study' => '']; ?>
          <tr>
            <td><?= e($info['label']) ?></td><td><?= e($info['study']) ?></td><td><?= (int) $c['reviews'] ?></td><td><?= (int) $c['objects'] ?></td>
            <td><?= (int) $c['unreviewed'] ?></td><td><?= (int) $c['missed'] ?></td><td><?= $c['complete'] ? '✓' : '—' ?></td>
            <td><a class="btn btn-sm" href="analyze_capture.php?id=<?= (int) $c['capture_id'] ?>">Apri</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
