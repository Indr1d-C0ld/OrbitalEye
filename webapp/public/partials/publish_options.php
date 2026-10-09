<?php
/**
 * Opzioni di pubblicazione di un blocco "Condividi" (vedi
 * PublicationBuilder, api/share.php, publishOptions() in common.js).
 *
 * @var string $publishPrefix  prefisso degli id ('an', 'an-crop', 'cmp', 'study')
 * @var string $publishKind    'capture' | 'comparison'
 * @var string $publishTitle   titolo predefinito della scheda
 */
$publishReview = trim((string) (AppSettings::all()['telegram_review_chat_id'] ?? '')) !== '';
$pp = e($publishPrefix);
?>
<div class="publish-options" data-prefix="<?= $pp ?>" style="margin:8px 0;">
  <div class="grid grid-2" style="margin-bottom:4px;">
    <div class="field" style="margin-bottom:6px;">
      <label>Formato <span class="info-tip" tabindex="0" data-tip="Scheda: l'immagine con una fascia in basso che riporta titolo, data reale e sensore, barra di scala, freccia del nord e attribuzione richiesta dalla fonte — lo stesso formato per Telegram, per la copia da incollare su X e per l'anteprima. Striscia: solo data e fonte in sovrimpressione. Nessuna coordinata viene mai aggiunta.">?</span></label>
      <select id="<?= $pp ?>-publish-format">
        <option value="card">Scheda di pubblicazione</option>
        <?php if ($publishKind === 'comparison'): ?><option value="pair">Scheda con Prima e Dopo affiancate</option><?php endif; ?>
        <option value="strip">Immagine con striscia data e fonte</option>
        <option value="none">Solo immagine</option>
      </select>
    </div>
    <div class="field publish-title-field" style="margin-bottom:6px;">
      <label>Titolo della scheda</label>
      <input type="text" id="<?= $pp ?>-publish-title" value="<?= e($publishTitle) ?>" maxlength="120">
    </div>
  </div>
  <div class="tag-row" style="gap:14px;">
    <?php if ($publishKind === 'comparison'): ?>
      <label class="checkbox-row" style="margin:0;" title="Su Telegram un album: l'immagine nel formato scelto più, a piena dimensione, le due riprese confrontate, ciascuna con la propria data.">
        <input type="checkbox" id="<?= $pp ?>-publish-album"> Album Telegram con Prima e Dopo
      </label>
    <?php endif; ?>
    <label class="checkbox-row" style="margin:0;" title="Telegram comprime le foto: allega anche la stessa immagine come file, senza perdita di dettaglio.">
      <input type="checkbox" id="<?= $pp ?>-publish-document"> Allega il file a piena risoluzione
    </label>
    <?php if ($publishReview): ?>
      <label class="checkbox-row" style="margin:0;" title="Invia prima alla chat di revisione: si pubblica sul canale solo dopo l'approvazione, dalla pagina Pubblicazioni.">
        <input type="checkbox" id="<?= $pp ?>-publish-review" checked> Passa dalla revisione
      </label>
    <?php endif; ?>
  </div>
</div>
