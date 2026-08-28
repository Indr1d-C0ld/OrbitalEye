<?php
require __DIR__ . '/../src/bootstrap.php';
Auth::requireLogin();

$studyId = (int) ($_GET['id'] ?? 0);
$study = $studyId ? Study::find($studyId) : null;
if (!$study) {
    http_response_code(404);
    exit('Studio non trovato');
}

$captures = Capture::forStudy($studyId);
$comparisons = Comparison::forStudy($studyId);
$defaults = AppSettings::all();

$pageTitle = $study['title'];
$activeNav = 'dashboard';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/nav.php';
?>

<div class="panel">
  <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:10px;">
    <div>
      <h2><?= e($study['title']) ?></h2>
      <div class="hint">
        <?= e($study['area_name'] ?: 'Nessuna area specificata') ?>
        <?php if ($study['bbox_json']): $bb = json_decode($study['bbox_json'], true); ?>
          &nbsp;·&nbsp; bbox: <?= implode(', ', array_map(fn($v) => round($v, 5), $bb)) ?>
        <?php endif; ?>
      </div>
      <?php if ($study['notes']): ?><p style="color:var(--text-secondary); max-width:700px;"><?= nl2br(e($study['notes'])) ?></p><?php endif; ?>
    </div>
    <button class="btn btn-danger btn-sm" onclick="deleteEntity('study', <?= (int)$study['id'] ?>, 'index.php')">Elimina studio</button>
  </div>
</div>

<div class="grid grid-2">
  <div class="panel">
    <h2>Acquisizione riprese</h2>
    <div class="viewer-tabs">
      <button type="button" class="tab-btn active" data-tab="tab-upload">Carica manualmente</button>
      <button type="button" class="tab-btn" data-tab="tab-sentinelhub">Sentinel Hub</button>
      <button type="button" class="tab-btn" data-tab="tab-esri">Esri World Imagery</button>
    </div>

    <div id="tab-upload" class="tab-pane">
      <form id="upload-form">
        <input type="hidden" name="study_id" value="<?= (int)$studyId ?>">
        <div class="field">
          <label>File immagine (PNG / JPEG / WEBP)</label>
          <input type="file" name="image" accept="image/png,image/jpeg,image/webp" required>
        </div>
        <div class="grid grid-2">
          <div class="field">
            <label>Etichetta</label>
            <input type="text" name="label" placeholder="es. Prima — Gen 2024">
          </div>
          <div class="field">
            <label>Data ripresa</label>
            <input type="date" name="capture_date">
          </div>
        </div>
        <button class="btn btn-primary" type="submit">Carica</button>
        <span class="hint" id="upload-status"></span>
      </form>
    </div>

    <div id="tab-sentinelhub" class="tab-pane" style="display:none;">
      <form id="sentinelhub-form" class="fetch-form" data-source="sentinelhub">
        <input type="hidden" name="study_id" value="<?= (int)$studyId ?>">
        <input type="hidden" name="source" value="sentinelhub">
        <div class="field">
          <label>Area di interesse <span class="info-tip" tabindex="0" data-tip="Disegna un rettangolo trascinando sulla mappa (o scrivi le coordinate a mano nei campi sotto). Passa a Satellite se vuoi riconoscere visivamente l'area prima di selezionarla.">?</span></label>
          <div class="stage-toolbar">
            <div class="mode-toggle">
              <button type="button" class="mode-btn active" id="sh-map-draw" title="Trascina per disegnare l'area">✎ Disegna area</button>
              <button type="button" class="mode-btn" id="sh-map-pan" title="Trascina per spostare la mappa">✋ Sposta mappa</button>
            </div>
            <div class="mode-toggle">
              <button type="button" class="mode-btn active" id="sh-map-osm" title="Mappa stradale (OpenStreetMap)">🗺 Mappa</button>
              <button type="button" class="mode-btn" id="sh-map-sat" title="Vista satellitare (solo per riconoscimento visivo)">🛰 Satellite</button>
            </div>
          </div>
          <div id="sh-map-picker" style="height:320px; border:1px solid var(--line-bright); border-radius:3px; margin-bottom:14px;"></div>
        </div>

        <div class="grid grid-4">
          <div class="field"><label>Min Lon</label><input type="text" id="sh-min-lon" name="min_lon" value="<?= $study['bbox_json'] ? e((string)json_decode($study['bbox_json'],true)[0]) : '' ?>" required></div>
          <div class="field"><label>Min Lat</label><input type="text" id="sh-min-lat" name="min_lat" value="<?= $study['bbox_json'] ? e((string)json_decode($study['bbox_json'],true)[1]) : '' ?>" required></div>
          <div class="field"><label>Max Lon</label><input type="text" id="sh-max-lon" name="max_lon" value="<?= $study['bbox_json'] ? e((string)json_decode($study['bbox_json'],true)[2]) : '' ?>" required></div>
          <div class="field"><label>Max Lat</label><input type="text" id="sh-max-lat" name="max_lat" value="<?= $study['bbox_json'] ? e((string)json_decode($study['bbox_json'],true)[3]) : '' ?>" required></div>
        </div>
        <div class="grid grid-2">
          <div class="field"><label>Da data</label><input type="date" name="date_from" value="<?= e(date('Y-m-d', strtotime('-90 days'))) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
          <div class="field"><label>A data</label><input type="date" name="date_to" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
        </div>
        <div class="field">
          <label>Copertura nuvolosa max (%)</label>
          <input type="number" name="max_cloud_coverage" value="20" min="0" max="100">
        </div>
        <button class="btn btn-primary" type="submit">Scarica composito Sentinel-2</button>
        <span class="hint fetch-status"></span>
      </form>
      <div class="hint" style="margin-top:10px;">Copernicus/Sentinel-2: risoluzione ~10m/pixel, intervallo di date storico selezionabile. Richiede credenziali configurate in <a href="settings.php">Impostazioni</a>.</div>
    </div>

    <div id="tab-esri" class="tab-pane" style="display:none;">
      <form id="esri-form" class="fetch-form" data-source="esri">
        <input type="hidden" name="study_id" value="<?= (int)$studyId ?>">
        <input type="hidden" name="source" value="esri">
        <div class="field">
          <label>Area di interesse <span class="info-tip" tabindex="0" data-tip="Disegna un rettangolo trascinando sulla mappa (o scrivi le coordinate a mano nei campi sotto). Passa a Satellite se vuoi riconoscere visivamente l'area prima di selezionarla.">?</span></label>
          <div class="stage-toolbar">
            <div class="mode-toggle">
              <button type="button" class="mode-btn active" id="esri-map-draw" title="Trascina per disegnare l'area">✎ Disegna area</button>
              <button type="button" class="mode-btn" id="esri-map-pan" title="Trascina per spostare la mappa">✋ Sposta mappa</button>
            </div>
            <div class="mode-toggle">
              <button type="button" class="mode-btn active" id="esri-map-osm" title="Mappa stradale (OpenStreetMap)">🗺 Mappa</button>
              <button type="button" class="mode-btn" id="esri-map-sat" title="Vista satellitare (solo per riconoscimento visivo)">🛰 Satellite</button>
            </div>
          </div>
          <div id="esri-map-picker" style="height:320px; border:1px solid var(--line-bright); border-radius:3px; margin-bottom:14px;"></div>
        </div>

        <div class="grid grid-4">
          <div class="field"><label>Min Lon</label><input type="text" id="esri-min-lon" name="min_lon" value="<?= $study['bbox_json'] ? e((string)json_decode($study['bbox_json'],true)[0]) : '' ?>" required></div>
          <div class="field"><label>Min Lat</label><input type="text" id="esri-min-lat" name="min_lat" value="<?= $study['bbox_json'] ? e((string)json_decode($study['bbox_json'],true)[1]) : '' ?>" required></div>
          <div class="field"><label>Max Lon</label><input type="text" id="esri-max-lon" name="max_lon" value="<?= $study['bbox_json'] ? e((string)json_decode($study['bbox_json'],true)[2]) : '' ?>" required></div>
          <div class="field"><label>Max Lat</label><input type="text" id="esri-max-lat" name="max_lat" value="<?= $study['bbox_json'] ? e((string)json_decode($study['bbox_json'],true)[3]) : '' ?>" required></div>
        </div>
        <button class="btn btn-primary" type="submit">Scarica da Esri World Imagery</button>
        <span class="hint fetch-status"></span>
      </form>
      <div class="hint" style="margin-top:10px;">Esri World Imagery: risoluzione spesso più alta (sub-metrica in molte aree, varia per zona), ma solo il composito "più recente disponibile" — nessuna scelta di data. Funziona anche senza API key per uso leggero (impostabile in <a href="settings.php">Impostazioni</a> per uso sostenuto).</div>
    </div>
  </div>

  <div class="panel">
    <h2>Riprese in archivio (<?= count($captures) ?>)</h2>
    <div class="hint" style="margin-bottom:10px;">Seleziona due riprese: prima <span class="badge badge-cyan">A · PRIMA</span> poi <span class="badge badge-amber">B · DOPO</span>.</div>
    <div class="thumb-grid" id="capture-grid">
      <?php foreach ($captures as $c):
          $linked = Capture::linkedComparisons((int) $c['id']);
          $confirmMsg = 'Eliminare questa ripresa? L\'operazione non è reversibile.';
          if ($linked) {
              $confirmMsg .= ' Verranno eliminati anche i ' . count($linked) . ' confronto/i che la usano'
                  . (array_filter($linked, fn($l) => $l['is_saved_to_library']) ? ' (inclusi alcuni salvati in libreria)' : '') . '.';
          }
          $cMeta = json_decode($c['meta_json'] ?? '', true);
          $hasNir = is_array($cMeta) && !empty($cMeta['nir_relative_path']);
      ?>
        <div class="thumb-card" data-capture-id="<?= (int)$c['id'] ?>">
          <img src="<?= e(storage_url($c['relative_path'])) ?>" alt="">
          <div class="meta">
            <div class="lbl"><?= e($c['label'] ?: ('Ripresa #' . $c['id'])) ?></div>
            <div><?= format_date_it($c['capture_date']) ?> · <?= e($c['source']) ?></div>
            <div style="display:flex; gap:4px; margin-top:6px;">
              <a class="btn btn-sm" style="flex:1; text-align:center;" href="export_capture.php?id=<?= (int)$c['id'] ?>" onclick="event.stopPropagation();" title="Scarica il file immagine originale">⬇ Scarica</a>
              <button type="button" class="btn btn-sm btn-danger" style="flex:1;"
                onclick="event.stopPropagation(); deleteEntity('capture', <?= (int)$c['id'] ?>, null, <?= e(json_encode($confirmMsg)) ?>)">
                Elimina
              </button>
            </div>
            <div style="display:flex; gap:4px; margin-top:4px;">
              <button type="button" class="btn btn-sm" style="flex:1;"
                onclick="event.stopPropagation(); openEnhancePanel(<?= (int)$c['id'] ?>, <?= e(json_encode(storage_url($c['relative_path']))) ?>, <?= e(json_encode($c['label'] ?: ('Ripresa #' . $c['id']))) ?>)"
                title="Applica filtri di miglioramento (elaborati lato server) a questa sola ripresa, senza eseguire un confronto">
                ✨ Migliora
              </button>
              <a class="btn btn-sm" style="flex:1; text-align:center;" href="analyze_capture.php?id=<?= (int)$c['id'] ?>" onclick="event.stopPropagation();"
                title="Apri la vista di analisi dedicata: zoom/panning approfondito, regolazioni in tempo reale (luminosità, contrasto, saturazione, nitidezza) e annotazioni, su questa sola ripresa.">
                🔬 Analizza
              </a>
            </div>
            <?php if ($hasNir): ?>
              <div style="display:flex; gap:4px; margin-top:4px;">
                <button type="button" class="btn btn-sm" style="flex:1;"
                  onclick="event.stopPropagation(); openSpectralPanel(<?= (int)$c['id'] ?>, 'ndvi', <?= e(json_encode($c['label'] ?: ('Ripresa #' . $c['id']))) ?>)"
                  title="NDVI: evidenzia la vegetazione (verde = densa/in salute, giallo/bruno = scarsa o assente) a partire dalla banda infrarossa scaricata con questa ripresa.">
                  🌿 NDVI
                </button>
                <button type="button" class="btn btn-sm" style="flex:1;"
                  onclick="event.stopPropagation(); openSpectralPanel(<?= (int)$c['id'] ?>, 'false_color_ir', <?= e(json_encode($c['label'] ?: ('Ripresa #' . $c['id']))) ?>)"
                  title="Falso colore infrarosso: la vegetazione viva appare in rosso acceso, utile per distinguere vegetazione reale da superfici solo apparentemente verdi (o viceversa).">
                  🌈 Falso colore IR
                </button>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if (empty($captures)): ?>
        <div class="empty-state" style="grid-column: 1/-1;">Nessuna ripresa ancora caricata.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="panel" id="enhance-panel" style="display:none;">
  <h2>Miglioramento immagine singola</h2>
  <div class="hint" style="margin-bottom:12px;">Applica filtri di enhancing a <strong id="enhance-target-label"></strong> senza eseguire un confronto — utile per pulire/valutare una ripresa da sola, o per ottenerne una versione migliorata da scaricare o riusare in un confronto.</div>
  <div class="grid grid-2">
    <div>
      <div class="checkbox-row field"><input type="checkbox" id="eh-wb"><label style="margin:0;">Bilanciamento del bianco <span class="info-tip" tabindex="0" data-tip="Corregge eventuali dominanti di colore (es. dovute a condizioni atmosferiche o al sensore) tramite l'algoritmo gray-world, rendendo i colori dell'immagine più naturali e bilanciati.">?</span></label></div>
      <div class="checkbox-row field"><input type="checkbox" id="eh-denoise"><label style="margin:0;">Riduzione rumore <span class="info-tip" tabindex="0" data-tip="Attenua il rumore fotografico/di compressione, utile per pulire una ripresa rumorosa prima di esportarla o riusarla in un confronto.">?</span></label></div>
      <div class="grid grid-2" style="margin-bottom:4px;">
        <select id="eh-denoise-method">
          <option value="gaussian">Gaussiano</option>
          <option value="median">Mediano</option>
          <option value="bilateral">Bilaterale</option>
          <option value="nlmeans">Non-local means</option>
        </select>
        <input type="range" id="eh-denoise-strength" min="1" max="10" value="3">
      </div>
      <div class="hint" style="margin-bottom:12px;">Gaussiano: sfocatura morbida generica. Mediano: efficace contro il rumore isolato tipo "sale e pepe". Bilaterale: riduce il rumore preservando meglio i bordi netti. Non-local means: il più efficace, anche il più lento. Lo slider a destra ne regola l'intensità (valori alti = più filtro, rischio di perdere dettagli reali).</div>
      <div class="checkbox-row field"><input type="checkbox" id="eh-clahe"><label style="margin:0;">CLAHE (contrasto adattivo) <span class="info-tip" tabindex="0" data-tip="Migliora il contrasto locale dell'immagine in modo adattivo, utile su riprese con foschia o forte variazione di illuminazione tra zone diverse della stessa immagine.">?</span></label></div>
      <div class="checkbox-row field"><input type="checkbox" id="eh-hist-eq"><label style="margin:0;">Equalizzazione istogramma <span class="info-tip" tabindex="0" data-tip="Ridistribuisce l'intera gamma tonale dell'immagine per massimizzare il contrasto globale. Alternativa più semplice e uniforme al CLAHE: usa questa se il CLAHE introduce aloni innaturali, il CLAHE se invece serve un miglioramento più localizzato. Di norma non servono entrambe insieme.">?</span></label></div>
    </div>
    <div>
      <div class="checkbox-row field"><input type="checkbox" id="eh-gamma-enabled"><label style="margin:0;">Correzione gamma <span class="info-tip" tabindex="0" data-tip="Schiarisce o scurisce l'immagine in modo non lineare. Valori sopra 1 schiariscono le zone scure, valori sotto 1 le scuriscono ulteriormente. Utile su riprese sovra/sotto-esposte.">?</span></label></div>
      <div class="field">
        <label>Gamma <span class="val" id="eh-val-gamma" style="margin-left:auto;">1.0</span></label>
        <input type="range" id="eh-gamma" min="0.2" max="3" step="0.1" value="1.0">
      </div>
      <div class="checkbox-row field"><input type="checkbox" id="eh-sharpen"><label style="margin:0;">Sharpening <span class="info-tip" tabindex="0" data-tip="Accentua i bordi e i dettagli fini dell'immagine, utile per rendere più leggibili i contorni di strutture in riprese leggermente sfocate.">?</span></label></div>
      <div class="field">
        <label>Intensità sharpen <span class="info-tip" tabindex="0" data-tip="Quanto applicare l'accentuazione dei bordi: valori alti possono introdurre aloni artificiali attorno ai contorni.">?</span><span class="val" id="eh-val-sharpen" style="margin-left:auto;">1.0</span></label>
        <input type="range" id="eh-sharpen-amount" min="0" max="3" step="0.1" value="1.0">
      </div>
      <div class="checkbox-row field"><input type="checkbox" id="eh-desaturate"><label style="margin:0;">Desaturazione B/N <span class="info-tip" tabindex="0" data-tip="Riduce gradualmente la saturazione del colore verso il bianco e nero. Utile per concentrarsi su bordi/texture/ombre (i cambiamenti strutturali reali) invece che su variazioni di colore dovute a stagione, angolo solare o sensore diverso, che possono distrarre o confondere la lettura.">?</span></label></div>
      <div class="field">
        <label>Intensità desaturazione <span class="val" id="eh-val-desaturate" style="margin-left:auto;">1.0</span></label>
        <input type="range" id="eh-desaturate-amount" min="0" max="1" step="0.05" value="1.0">
        <div class="hint">0 = colore originale invariato, 1 = bianco e nero completo. Valori intermedi attenuano le dominanti cromatiche senza eliminarle del tutto.</div>
      </div>
    </div>
  </div>
  <button class="btn btn-primary" type="button" id="enhance-apply-btn" title="Applica i filtri selezionati e genera un'anteprima prima/dopo, senza salvare nulla in archivio.">▶ Applica e anteprima</button>
  <button class="btn btn-sm" type="button" id="enhance-close-btn" title="Chiude il pannello senza salvare l'anteprima generata.">✕ Chiudi</button>
  <span class="hint" id="enhance-status"></span>

  <div class="grid grid-2" id="enhance-preview-row" style="display:none; margin-top:16px;">
    <div>
      <h3>Originale</h3>
      <div class="viewer-stage"><img id="enhance-img-before" style="width:100%; display:block;" alt=""></div>
    </div>
    <div>
      <h3>Con filtri applicati</h3>
      <div class="viewer-stage"><img id="enhance-img-after" style="width:100%; display:block;" alt=""></div>
      <div style="display:flex; gap:8px; margin-top:10px; flex-wrap:wrap;">
        <input type="text" id="enhance-save-label" placeholder="Etichetta (opzionale)" style="flex:1; min-width:160px;">
        <button class="btn btn-primary btn-sm" type="button" id="enhance-save-btn" title="Salva l'anteprima come nuova ripresa permanente in archivio, riusabile in qualunque confronto futuro.">💾 Salva come nuova ripresa</button>
      </div>
    </div>
  </div>
</div>

<div class="panel" id="spectral-panel" style="display:none;">
  <h2 id="spectral-title">Indice spettrale</h2>
  <div class="hint" style="margin-bottom:12px;" id="spectral-hint"></div>
  <div class="grid grid-2">
    <div>
      <h3>Vero colore</h3>
      <div class="viewer-stage"><img id="spectral-img-before" style="width:100%; display:block;" alt=""></div>
    </div>
    <div>
      <h3 id="spectral-result-title">Risultato</h3>
      <div class="viewer-stage"><img id="spectral-img-after" style="width:100%; display:block;" alt=""></div>
      <div style="display:flex; gap:8px; margin-top:10px; flex-wrap:wrap;">
        <input type="text" id="spectral-save-label" placeholder="Etichetta (opzionale)" style="flex:1; min-width:160px;">
        <button class="btn btn-primary btn-sm" type="button" id="spectral-save-btn" title="Salva il risultato come nuova ripresa permanente in archivio.">💾 Salva come nuova ripresa</button>
      </div>
    </div>
  </div>
  <button class="btn btn-sm" type="button" id="spectral-close-btn" style="margin-top:10px;">✕ Chiudi</button>
  <span class="hint" id="spectral-status"></span>
</div>

<div class="panel" id="control-points-panel" style="display:none;">
  <h2>Editor punti di controllo (allineamento manuale)</h2>
  <div class="hint" style="margin-bottom:12px;">
    Clicca un punto su <strong>A</strong> (un dettaglio riconoscibile: un incrocio, un angolo di edificio, ecc.), poi clicca lo <strong>stesso identico punto reale</strong> su B: la coppia si evidenzia con lo stesso numero e colore su entrambe le immagini. Servono almeno <strong>3 punti</strong> (4+ tollerano qualche imprecisione di click grazie al filtro RANSAC). Usa zoom e trascinamento per posizionare i punti con precisione.
  </div>
  <div class="tag-row" style="margin-bottom:12px;">
    <button type="button" class="btn btn-sm cp-mode-btn active" data-mode="point">✎ Punto <span class="info-tip" tabindex="0" data-tip="Clic su un'immagine posiziona un punto di controllo. Passa a Sposta per trascinare la vista senza rischiare di piazzare punti per sbaglio.">?</span></button>
    <button type="button" class="btn btn-sm cp-mode-btn" data-mode="pan">✋ Sposta</button>
  </div>
  <div class="grid grid-2">
    <div style="min-width:0;">
      <h3>A — riferimento</h3>
      <div class="tag-row" style="margin-bottom:6px;">
        <button type="button" class="btn btn-sm cp-zoom-btn" data-target="a" data-zoom="fit">Adatta</button>
        <button type="button" class="btn btn-sm cp-zoom-btn" data-target="a" data-zoom="1">100%</button>
        <button type="button" class="btn btn-sm cp-zoom-btn" data-target="a" data-zoom="2">200%</button>
        <button type="button" class="btn btn-sm cp-zoom-btn" data-target="a" data-zoom="4">400%</button>
      </div>
      <div class="field" style="margin-bottom:6px;">
        <label style="margin:0;">Zoom <span class="val" id="cp-zoom-val-a" style="margin-left:auto;">100%</span></label>
        <input type="range" id="cp-zoom-slider-a" min="10" max="800" step="1" value="100">
      </div>
      <div class="cp-scroll" id="cp-scroll-a" style="overflow:auto; max-height:420px; border:1px solid var(--border-color, #333); position:relative;">
        <div class="cp-wrap" id="cp-wrap-a" style="position:relative; display:inline-block; line-height:0;">
          <img id="cp-img-a" src="" alt="" style="display:block; max-width:none;">
        </div>
      </div>
    </div>
    <div style="min-width:0;">
      <h3>B — da allineare</h3>
      <div class="tag-row" style="margin-bottom:6px;">
        <button type="button" class="btn btn-sm cp-zoom-btn" data-target="b" data-zoom="fit">Adatta</button>
        <button type="button" class="btn btn-sm cp-zoom-btn" data-target="b" data-zoom="1">100%</button>
        <button type="button" class="btn btn-sm cp-zoom-btn" data-target="b" data-zoom="2">200%</button>
        <button type="button" class="btn btn-sm cp-zoom-btn" data-target="b" data-zoom="4">400%</button>
      </div>
      <div class="field" style="margin-bottom:6px;">
        <label style="margin:0;">Zoom <span class="val" id="cp-zoom-val-b" style="margin-left:auto;">100%</span></label>
        <input type="range" id="cp-zoom-slider-b" min="10" max="800" step="1" value="100">
      </div>
      <div class="cp-scroll" id="cp-scroll-b" style="overflow:auto; max-height:420px; border:1px solid var(--border-color, #333); position:relative;">
        <div class="cp-wrap" id="cp-wrap-b" style="position:relative; display:inline-block; line-height:0;">
          <img id="cp-img-b" src="" alt="" style="display:block; max-width:none;">
        </div>
      </div>
    </div>
  </div>
  <div class="tag-row" style="margin-top:10px;">
    <button type="button" class="btn btn-sm" id="cp-undo-btn">↶ Annulla ultimo punto</button>
    <button type="button" class="btn btn-sm" id="cp-clear-btn">🗑 Cancella tutti</button>
    <button type="button" class="btn btn-sm" id="cp-preview-btn">👁 Anteprima allineamento</button>
    <button type="button" class="btn btn-primary btn-sm" id="cp-save-btn">💾 Salva punti</button>
    <button type="button" class="btn btn-sm" id="cp-close-btn">✕ Chiudi</button>
  </div>
  <div class="hint" id="cp-status" style="margin-top:6px;"></div>
  <div class="grid grid-2" id="cp-preview-row" style="display:none; margin-top:16px;">
    <div>
      <h3>B allineata su A</h3>
      <div class="viewer-stage"><img id="cp-preview-aligned" style="width:100%; display:block;" alt=""></div>
    </div>
    <div>
      <h3>Sovrapposizione (blend 50/50)<span class="info-tip" tabindex="0" data-tip="A e B allineata mescolate al 50%: se l'allineamento è buono i dettagli invarianti (edifici, strade) appaiono nitidi; un disallineamento residuo si vede come uno sdoppiamento/fantasma dei contorni.">?</span></h3>
      <div class="viewer-stage"><img id="cp-preview-blend" style="width:100%; display:block;" alt=""></div>
    </div>
  </div>
</div>

<div class="panel" id="compare-panel" style="display:none;">
  <h2>Configurazione confronto</h2>
  <div class="grid grid-2">
    <div>
      <h3>Rilevamento differenze</h3>
      <div class="field">
        <label>Preset di sensibilità <span class="info-tip" tabindex="0" data-tip="Imposta in un click soglia, area minima blob e kernel morfologico su una combinazione bilanciata. Puoi comunque affinare ogni valore manualmente dopo aver scelto un preset.">?</span></label>
        <div class="tag-row">
          <button type="button" class="btn btn-sm sensitivity-preset" data-threshold="60" data-minarea="300" data-morph="5">Bassa (solo cambi marcati)</button>
          <button type="button" class="btn btn-sm sensitivity-preset" data-threshold="30" data-minarea="40" data-morph="3">Media (bilanciata)</button>
          <button type="button" class="btn btn-sm sensitivity-preset" data-threshold="15" data-minarea="10" data-morph="1">Alta (cambi sottili)</button>
        </div>
      </div>
      <div class="field">
        <label>Metodo diff <span class="info-tip" tabindex="0" data-tip="SSIM confronta la struttura locale delle due immagini: più robusto a variazioni di luce/contrasto tra le riprese, si concentra sui cambi strutturali reali (es. nuovi edifici). Differenza assoluta confronta i pixel direttamente: più veloce ma più sensibile a variazioni di illuminazione/colore non legate a cambiamenti reali.">?</span></label>
        <select id="opt-diff-method">
          <option value="ssim" <?= $defaults['default_diff_method']==='ssim'?'selected':'' ?>>SSIM (robusto a luce/contrasto)</option>
          <option value="absdiff" <?= $defaults['default_diff_method']==='absdiff'?'selected':'' ?>>Differenza assoluta (veloce)</option>
        </select>
      </div>
      <div class="field">
        <label>Soglia threshold <span class="info-tip" tabindex="0" data-tip="Valore (0-255) sopra il quale una differenza tra le due immagini viene considerata un cambiamento reale. Più alta = meno falsi positivi ma rischio di perdere cambi sottili. Più bassa = più sensibile ma più rumore.">?</span><span class="val" id="val-threshold" style="margin-left:auto;"><?= e($defaults['default_threshold']) ?></span></label>
        <input type="range" id="opt-threshold" min="1" max="255" value="<?= e($defaults['default_threshold']) ?>">
        <div class="hint">Più alta = meno sensibile (meno falsi positivi, rischio di perdere cambi sottili).</div>
      </div>
      <div class="checkbox-row field">
        <input type="checkbox" id="opt-otsu">
        <label style="margin:0;">Soglia automatica (Otsu) — ignora lo slider sopra <span class="info-tip" tabindex="0" data-tip="Calcola automaticamente la soglia migliore analizzando la distribuzione delle differenze nell'immagine, invece di usare il valore fisso impostato sopra. Utile quando non sai quale soglia impostare.">?</span></label>
      </div>
      <div class="field">
        <label>Area minima blob (px²) <span class="info-tip" tabindex="0" data-tip="Scarta le zone di cambiamento più piccole di questo numero di pixel². Aumentala per eliminare rumore fotografico e micro-variazioni; abbassala se rischi di perdere piccoli dettagli rilevanti.">?</span><span class="val" id="val-minarea" style="margin-left:auto;"><?= e($defaults['default_min_blob_area']) ?></span></label>
        <input type="range" id="opt-minarea" min="0" max="2000" step="10" value="<?= e($defaults['default_min_blob_area']) ?>">
        <div class="hint">Scarta le regioni di cambiamento più piccole di questa area (riduce falsi positivi da rumore).</div>
      </div>
      <div class="field">
        <label>Pulizia morfologica (kernel) <span class="info-tip" tabindex="0" data-tip="Dimensione del filtro usato per ripulire la maschera delle differenze: rimuove i puntini isolati (rumore) e richiude piccoli buchi dentro le aree di cambiamento reale, per ottenere regioni più compatte e leggibili.">?</span><span class="val" id="val-morph" style="margin-left:auto;"><?= e($defaults['default_morph_kernel']) ?></span></label>
        <input type="range" id="opt-morph" min="1" max="15" step="2" value="<?= e($defaults['default_morph_kernel']) ?>">
      </div>
      <div class="field">
        <label>Opacità overlay <span class="info-tip" tabindex="0" data-tip="Trasparenza del colore usato per evidenziare le zone di cambiamento nell'immagine overlay. Valori più alti rendono l'evidenziazione più marcata.">?</span><span class="val" id="val-alpha" style="margin-left:auto;"><?= e($defaults['default_overlay_alpha']) ?></span></label>
        <input type="range" id="opt-alpha" min="0.05" max="0.9" step="0.05" value="<?= e($defaults['default_overlay_alpha']) ?>">
      </div>
      <div class="checkbox-row field" id="opt-align-row">
        <input type="checkbox" id="opt-align" checked>
        <label style="margin:0;">Allinea automaticamente le due riprese prima del confronto <span class="info-tip" tabindex="0" data-tip="Corregge piccoli disallineamenti tra le due riprese (rotazione, traslazione, scala) prima di confrontarle, tramite riconoscimento automatico dei punti in comune (motore ORB+ECC). Consigliato quasi sempre: senza allineamento, anche un piccolo scostamento nell'inquadratura genera falsi cambiamenti lungo tutti i bordi degli oggetti.">?</span></label>
      </div>
      <div class="field">
        <label>Modalità di allineamento <span class="info-tip" tabindex="0" data-tip="Automatico: il motore (ORB+ECC) individua da solo i punti in comune tra le due riprese. Manuale: indichi tu stesso, a mano, coppie di punti corrispondenti nella stessa area reale — usalo quando il motore automatico non allinea correttamente (tipico tra fonti molto diverse tra loro, es. Esri World Imagery vs Sentinel Hub).">?</span></label>
        <div class="tag-row">
          <button type="button" class="btn btn-sm align-mode-btn active" data-mode="auto">Automatico</button>
          <button type="button" class="btn btn-sm align-mode-btn" data-mode="manual">Manuale (punti di controllo)</button>
        </div>
        <div id="manual-align-block" style="display:none; margin-top:8px;">
          <div class="hint" id="manual-align-status">Nessun punto salvato per questa coppia di riprese.</div>
          <button type="button" class="btn btn-sm" id="open-control-points-btn" style="margin-top:6px;">✏️ Apri editor punti di controllo</button>
        </div>
      </div>
    </div>
    <div>
      <h3>Enhancement pre-analisi (applicato a entrambe le riprese)</h3>
      <div class="checkbox-row field"><input type="checkbox" id="opt-wb"><label style="margin:0;">Bilanciamento del bianco (gray-world) <span class="info-tip" tabindex="0" data-tip="Corregge eventuali dominanti di colore causate da condizioni atmosferiche o sensori diversi tra le due riprese, per renderle più comparabili prima del confronto.">?</span></label></div>
      <div class="checkbox-row field"><input type="checkbox" id="opt-denoise"><label style="margin:0;">Riduzione rumore <span class="info-tip" tabindex="0" data-tip="Attenua il rumore fotografico/di compressione prima del confronto, riducendo i falsi positivi causati da variazioni casuali di singoli pixel piuttosto che da cambiamenti reali.">?</span></label></div>
      <div class="grid grid-2" style="margin-bottom:4px;">
        <select id="opt-denoise-method">
          <option value="gaussian">Gaussiano</option>
          <option value="median">Mediano</option>
          <option value="bilateral">Bilaterale</option>
          <option value="nlmeans">Non-local means</option>
        </select>
        <input type="range" id="opt-denoise-strength" min="1" max="10" value="3">
      </div>
      <div class="hint" style="margin-bottom:12px;">Gaussiano: sfocatura morbida generica. Mediano: efficace contro il rumore isolato tipo "sale e pepe". Bilaterale: riduce il rumore preservando meglio i bordi netti. Non-local means: il più efficace, anche il più lento. Lo slider a destra ne regola l'intensità (valori alti = più filtro, rischio di perdere dettagli reali).</div>
      <div class="checkbox-row field"><input type="checkbox" id="opt-clahe"><label style="margin:0;">CLAHE (contrasto adattivo) <span class="info-tip" tabindex="0" data-tip="Migliora il contrasto locale dell'immagine in modo adattivo, utile su riprese con foschia o forte variazione di illuminazione tra zone diverse della stessa immagine.">?</span></label></div>
      <div class="checkbox-row field"><input type="checkbox" id="opt-hist-eq"><label style="margin:0;">Equalizzazione istogramma <span class="info-tip" tabindex="0" data-tip="Ridistribuisce l'intera gamma tonale dell'immagine per massimizzare il contrasto globale. Alternativa più semplice e uniforme al CLAHE: usa questa se il CLAHE introduce aloni innaturali, il CLAHE se invece serve un miglioramento più localizzato. Di norma non servono entrambe insieme.">?</span></label></div>
      <div class="checkbox-row field"><input type="checkbox" id="opt-gamma-enabled"><label style="margin:0;">Correzione gamma <span class="info-tip" tabindex="0" data-tip="Schiarisce o scurisce l'immagine in modo non lineare. Valori sopra 1 schiariscono le zone scure, valori sotto 1 le scuriscono ulteriormente. Utile su riprese sovra/sotto-esposte.">?</span></label></div>
      <div class="field">
        <label>Gamma <span class="val" id="val-gamma" style="margin-left:auto;">1.0</span></label>
        <input type="range" id="opt-gamma" min="0.2" max="3" step="0.1" value="1.0">
      </div>
      <div class="checkbox-row field"><input type="checkbox" id="opt-sharpen"><label style="margin:0;">Sharpening <span class="info-tip" tabindex="0" data-tip="Accentua i bordi e i dettagli fini dell'immagine, utile per rendere più leggibili i contorni di strutture in riprese leggermente sfocate.">?</span></label></div>
      <div class="field">
        <label>Intensità sharpen <span class="info-tip" tabindex="0" data-tip="Quanto applicare l'accentuazione dei bordi: valori alti possono introdurre aloni artificiali attorno ai contorni.">?</span><span class="val" id="val-sharpen" style="margin-left:auto;">1.0</span></label>
        <input type="range" id="opt-sharpen-amount" min="0" max="3" step="0.1" value="1.0">
      </div>
      <div class="checkbox-row field"><input type="checkbox" id="opt-desaturate"><label style="margin:0;">Desaturazione B/N <span class="info-tip" tabindex="0" data-tip="Riduce gradualmente la saturazione del colore verso il bianco e nero su entrambe le riprese prima del confronto. Utile per concentrarsi su bordi/texture/ombre (i cambiamenti strutturali reali) invece che su variazioni di colore dovute a stagione, angolo solare o sensore diverso tra le due riprese, che possono generare falsi positivi cromatici.">?</span></label></div>
      <div class="field">
        <label>Intensità desaturazione <span class="val" id="val-desaturate" style="margin-left:auto;">1.0</span></label>
        <input type="range" id="opt-desaturate-amount" min="0" max="1" step="0.05" value="1.0">
        <div class="hint">0 = colore originale invariato, 1 = bianco e nero completo.</div>
      </div>
    </div>
  </div>
  <button class="btn btn-primary" id="run-compare-btn" style="margin-top:10px;">▶ Esegui confronto</button>
  <span class="hint" id="compare-status"></span>
</div>

<div class="panel" id="results-panel" style="display:none;">
  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
    <h2 style="margin:0;">Report differenze</h2>
    <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
      <input type="text" id="result-title" placeholder="Titolo del confronto (opzionale)" style="width:260px; max-width:100%; display:inline-block;">
      <a class="btn btn-sm" id="export-comparison-btn" href="#" title="Scarica uno ZIP con riprese originali, immagini di risultato e report">⬇ Esporta pacchetto</a>
      <button class="btn btn-primary btn-sm" id="save-library-btn">💾 Salva in libreria</button>
    </div>
  </div>

  <div class="grid grid-4" id="result-stats" style="margin:16px 0;"></div>

  <div class="viewer-tabs">
    <button type="button" class="view-tab-btn active" data-view="overlay">Overlay differenze <span class="info-tip" tabindex="0" data-tip="La ripresa 'dopo' con le zone di cambiamento evidenziate in ciano semi-trasparente e delimitate da riquadri numerati (uno per regione rilevata). È la vista principale per capire a colpo d'occhio dove sono i cambiamenti e la loro estensione relativa l'uno rispetto all'altro.">?</span></button>
    <button type="button" class="view-tab-btn" data-view="heatmap">Heatmap <span class="info-tip" tabindex="0" data-tip="Mappa di calore della differenza calcolata pixel per pixel (SSIM o differenza assoluta), prima di applicare la soglia: più il colore tende al bianco/giallo, maggiore è la differenza in quel punto. A differenza dell'overlay (sì/no cambiamento) mostra l'intensità in modo continuo — utile per capire quanto è netto un cambiamento, non solo dove si trova.">?</span></button>
    <button type="button" class="view-tab-btn" data-view="swipe">Prima / Dopo (swipe) <span class="info-tip" tabindex="0" data-tip="Le due riprese originali (A e B riallineata su A) sovrapposte con uno slider trascinabile per confrontarle direttamente. Nessuna elaborazione: solo le due foto reali, una sopra l'altra — utile per un controllo visivo immediato prima di fidarsi dell'analisi automatica.">?</span></button>
    <button type="button" class="view-tab-btn" data-view="mask">Maschera <span class="info-tip" tabindex="0" data-tip="Maschera binaria bianco/nero: bianco dove la differenza supera la soglia impostata ed è sopravvissuta alla pulizia dal rumore, nero altrove. È esattamente l'area che l'algoritmo sta contando come cambiamento — utile per capire concretamente cosa include (o esclude) il calcolo delle statistiche e delle regioni.">?</span></button>
    <button type="button" class="view-tab-btn" data-view="edges">Contorni <span class="info-tip" tabindex="0" data-tip="Rilevamento dei bordi (Canny) sulla ripresa 'dopo': evidenzia il profilo netto di strutture ed elementi. Utile per distinguere il contorno di una nuova costruzione dal rumore diffuso di un cambiamento non strutturale (es. variazione di colore/umidità del terreno), che nell'overlay potrebbe sembrare simile.">?</span></button>
    <button type="button" class="view-tab-btn" data-view="original-a">📷 Originale A <span class="info-tip" tabindex="0" data-tip="La ripresa 'prima' così come effettivamente usata nel calcolo: con gli stessi filtri di enhancement pre-analisi eventualmente selezionati (identici a quelli applicati a B), ma senza overlay/heatmap/altre viste elaborate. Se non hai selezionato alcun filtro, coincide con la foto originale.">?</span></button>
    <button type="button" class="view-tab-btn" data-view="original-b">📷 Originale B <span class="info-tip" tabindex="0" data-tip="La ripresa 'dopo' così come effettivamente usata nel calcolo: riallineata geometricamente su A e con gli stessi filtri di enhancement pre-analisi eventualmente selezionati, ma senza overlay/heatmap/altre viste elaborate.">?</span></button>
  </div>

  <div class="grid grid-2">
    <div>
      <div class="stage-toolbar">
        <div class="mode-toggle" id="mode-toggle">
          <button type="button" class="mode-btn active" data-mode="annotate" title="Trascina per disegnare un'annotazione">✎ Annota</button>
          <button type="button" class="mode-btn" data-mode="pan" title="Trascina per spostare l'immagine ingrandita">✋ Sposta</button>
        </div>
        <div class="zoom-controls">
          <button type="button" class="btn btn-sm" id="zoom-out" title="Riduci zoom">−</button>
          <span class="zoom-level" id="zoom-level">100%</span>
          <button type="button" class="btn btn-sm" id="zoom-in" title="Aumenta zoom">+</button>
          <button type="button" class="btn btn-sm" id="zoom-reset" title="Ripristina zoom e posizione">Reset</button>
        </div>
      </div>

      <div class="viewer-stage" id="stage-single">
        <div class="zoom-viewport" id="stage-viewport">
          <div class="zoom-content" id="stage-content">
            <img id="stage-img" src="" alt="">
            <canvas id="annotate-canvas"></canvas>
          </div>
        </div>
      </div>
      <div class="swipe-wrap" id="stage-swipe" style="display:none;">
        <div class="zoom-viewport" id="swipe-viewport">
          <div class="zoom-content" id="swipe-content">
            <img id="swipe-before" src="" alt="">
            <div class="swipe-after" id="swipe-after-wrap"><img id="swipe-after" src=""></div>
            <div class="swipe-handle" id="swipe-handle"></div>
          </div>
        </div>
      </div>
      <div class="hint" style="margin-top:8px;" id="stage-hint">Modalità <strong>Annota</strong>: trascina per disegnare un&rsquo;annotazione. Modalità <strong>Sposta</strong>: trascina per spostare l'immagine quando sei ingrandito. Rotellina del mouse per zoomare (o i pulsanti +/−).</div>
    </div>
    <div>
      <h3>Regioni di cambiamento rilevate</h3>
      <div class="hint" style="margin-bottom:8px;">Clicca una regione (qui o direttamente il suo riquadro sull'immagine) per ingrandirla automaticamente. "✎ Annota" la trasforma in un'annotazione con un click.</div>
      <div class="region-list" id="region-list"></div>
      <h3 style="margin-top:18px;">Annotazioni</h3>
      <div id="annotation-list"></div>
    </div>
  </div>
</div>

<div class="panel">
  <h2>Storico confronti dello studio</h2>
  <?php if (empty($comparisons)): ?>
    <div class="empty-state">Nessun confronto ancora eseguito per questo studio.</div>
  <?php else: ?>
    <div class="table-responsive">
    <table>
      <thead><tr><th>Data</th><th>Titolo</th><th>Cambiamento</th><th>Regioni</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($comparisons as $cmp): $stats = json_decode($cmp['stats_json'], true); ?>
          <tr>
            <td><?= format_datetime_it($cmp['created_at']) ?></td>
            <td><?= e($cmp['title'] ?: '—') ?> <?= $cmp['is_saved_to_library'] ? '<span class="badge badge-green">libreria</span>' : '' ?></td>
            <td><?= isset($stats['changed_ratio']) ? round($stats['changed_ratio'] * 100, 2) . '%' : '—' ?></td>
            <td><?= $stats['num_regions'] ?? '—' ?></td>
            <td>
              <button class="btn btn-sm" onclick="loadComparison(<?= (int)$cmp['id'] ?>)">Apri</button>
              <a class="btn btn-sm" href="export_comparison.php?id=<?= (int)$cmp['id'] ?>" title="Scarica ZIP (immagini + report)">⬇ Esporta</a>
              <button class="btn btn-sm btn-danger" onclick="deleteEntity('comparison', <?= (int)$cmp['id'] ?>)">Elimina</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<script>
window.ORBITALEYE = {
  studyId: <?= (int)$studyId ?>,
  captures: <?= json_encode($captures) ?>,
  comparisons: <?= json_encode(array_map(function($c) {
      $c['stats'] = json_decode($c['stats_json'], true);
      $c['regions'] = json_decode($c['regions_json'], true);
      $c['result_paths'] = json_decode($c['result_paths_json'], true);
      return $c;
  }, $comparisons)) ?>,
  mediaBase: 'media.php?path='
};
</script>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="assets/js/map-picker.js?v=<?= @filemtime(__DIR__ . '/assets/js/map-picker.js') ?: time() ?>"></script>
<script src="assets/js/study.js?v=<?= @filemtime(__DIR__ . '/assets/js/study.js') ?: time() ?>"></script>

<?php require __DIR__ . '/partials/footer.php'; ?>
