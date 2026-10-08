<?php
/**
 * Avviso mostrato accanto ai pulsanti di condivisione quando l'immagine
 * proviene da Esri World Imagery. I termini d'uso delle immagini statiche
 * Esri elencano usi consentiti (personale, interno, rapporti per clienti,
 * materiale promozionale proprio) e chiedono un permesso preventivo per
 * tutti gli altri: la pubblicazione su canali pubblici non vi rientra
 * chiaramente. Non si blocca nulla — la scelta resta dell'analista — ma
 * l'informazione deve essere davanti agli occhi nel momento in cui si
 * pubblica, non solo nella documentazione.
 *
 * Richiede: $esriNoticeVisible (bool).
 */
if (empty($esriNoticeVisible)) {
    return;
}
?>
<div class="alert alert-warning esri-share-notice">
  <strong>Immagine Esri World Imagery.</strong>
  I termini d'uso Esri consentono queste immagini per uso personale o interno, in rapporti per clienti e in
  materiale promozionale proprio; per ogni altro uso — come la pubblicazione su canali pubblici —
  chiedono un permesso preventivo. L'attribuzione richiesta è già nella didascalia e, se lasci attiva
  l'opzione, sull'immagine.
  <a href="<?= e(ImageryAttribution::ESRI_TERMS_URL) ?>" target="_blank" rel="noopener noreferrer">Termini d'uso ↗</a>
  · <a href="<?= e(ImageryAttribution::ESRI_PERMISSION_URL) ?>" target="_blank" rel="noopener noreferrer">Richiesta di permesso ↗</a>
</div>
