<?php
require __DIR__ . '/../src/bootstrap.php';
Auth::requireLogin();

$captureId = (int) ($_GET['id'] ?? 0);
$capture = $captureId ? Capture::find($captureId) : null;
if (!$capture) {
    http_response_code(404);
    exit('Ripresa non trovata');
}
$study = Study::find((int) $capture['study_id']);
if (!$study) {
    http_response_code(404);
    exit('Studio non trovato');
}

// Scala reale (metri/pixel) per il calcolo delle misurazioni: preferisce
// mpp_x/mpp_y già risolti/ereditati (vedi Capture::resolveMpp — riprese
// derivate: salvate/migliorate/ritagliate) o la bbox geografica propria
// della ripresa (Sentinel Hub/Esri la salvano al momento del download).
// Solo se nessuna delle due è disponibile ricade sulla bbox GENERICA dello
// studio: approssimazione ragionevole solo per una ripresa scaricata
// direttamente per quell'area, MAI usata per una derivata (non c'è
// garanzia corrisponda ancora alle sue dimensioni pixel effettive — bug
// reale corretto qui: prima ogni ripresa derivata ricadeva su questo
// fallback, producendo misure sbagliate, spesso di molto per un ritaglio).
// Assente del tutto solo per caricamenti manuali mai derivati o riprese
// scaricate prima che il download salvasse la bbox: in quel caso lo
// strumento di misura chiede una calibrazione manuale.
$captureMeta = json_decode($capture['meta_json'] ?? '', true);
$measureMpp = Capture::resolveMpp($capture);
$measureBbox = null;
if (!$measureMpp && !empty($study['bbox_json'])) {
    $measureBbox = json_decode($study['bbox_json'], true);
}
// Bbox geografica "grezza" (indipendente dalla risoluzione della scala):
// serve lato client alla stima altezza da ombra (centro area -> lat/lon).
// Preferisce quella propria della ripresa, poi quella dello studio.
$geoBbox = (is_array($captureMeta) && !empty($captureMeta['bbox'])) ? array_map('floatval', $captureMeta['bbox']) : null;
if (!$geoBbox && !empty($study['bbox_json'])) {
    $geoBbox = array_map('floatval', json_decode($study['bbox_json'], true));
}

// Didascalia di default per la condivisione (Telegram/X): dati generici
// non sensibili, MAI coordinate esatte — se l'analista le vuole includere
// le aggiunge lui a mano, editando il campo prima dell'invio.
// L'etichetta della ripresa è già descrittiva di suo (es. "Sentinel-2
// 2026-06-01 → 2026-08-30" o "Esri World Imagery — scaricata il..."):
// aggiungere anche fonte/data qui la rendeva ridondante o, per Esri (data
// di acquisizione reale non nota), esplicitamente vuota ("data non
// disponibile") in coda a una frase che la data la conteneva già.
$shareDefaultCaption = ($capture['label'] ?: ('Ripresa #' . $capture['id'])) . ' — OrbitalEye';

$pageTitle = 'Analisi — ' . ($capture['label'] ?: ('Ripresa #' . $capture['id']));
$activeNav = 'dashboard';
require __DIR__ . '/partials/head.php';
require __DIR__ . '/partials/nav.php';
?>

<div class="panel">
  <div class="hint">
    <a href="study.php?id=<?= (int)$study['id'] ?>">← Torna a «<?= e($study['title']) ?>»</a>
  </div>
  <h2>🔬 Analisi ripresa singola</h2>
  <div class="hint">
    <?= e($capture['label'] ?: ('Ripresa #' . $capture['id'])) ?>
    &nbsp;·&nbsp; <?= format_date_it($capture['capture_date']) ?> · <?= e($capture['source']) ?>
  </div>
</div>

<div class="panel" id="an-preview-panel">
  <h2>👁 Anteprima</h2>
  <div class="stage-toolbar">
    <div class="mode-toggle" id="an-mode-toggle">
      <button type="button" class="mode-btn active" data-mode="pan" title="Trascina per spostare la vista (su entrambi i riquadri, sincronizzati)">✋ Sposta</button>
      <button type="button" class="mode-btn" data-mode="annotate" title="Trascina sulla copia di lavoro per disegnare un'annotazione">✎ Annota</button>
      <button type="button" class="mode-btn" data-mode="measure" title="Trascina sulla copia di lavoro per misurare una distanza reale sul terreno">📏 Misura</button>
      <button type="button" class="mode-btn" data-mode="crop" title="Trascina sulla copia di lavoro per ritagliare un frammento da usare in una ricerca inversa per immagini">🔍 Ritaglia</button>
      <button type="button" class="mode-btn" data-mode="overlay" title="Trascina il corpo per spostarla, gli angoli (cerchi) per ridimensionarla, i quadratini a metà lato per inclinarla, il cerchietto in alto per ruotarla, il cerchietto lungo la guida in basso per l'opacità">🖼 Sovrapponi</button>
    </div>
    <div class="zoom-controls">
      <button type="button" class="btn btn-sm" id="an-undo-btn" disabled title="Annulla l'ultima azione (annotazione, misurazione o filtro avanzato). Scorciatoia: Ctrl+Z">↶ Annulla</button>
      <button type="button" class="btn btn-sm" id="an-zoom-out" title="Riduci zoom">−</button>
      <span class="zoom-level" id="an-zoom-level">100%</span>
      <button type="button" class="btn btn-sm" id="an-zoom-in" title="Aumenta zoom">+</button>
      <button type="button" class="btn btn-sm" id="an-zoom-reset" title="Ripristina zoom e posizione">Reset</button>
    </div>
  </div>
  <div class="hint" style="margin-bottom:10px;">Rotellina del mouse per zoomare (i due riquadri restano sincronizzati sulla stessa area, per confrontare a colpo d'occhio originale e copia di lavoro). Modalità <strong>Sposta</strong>: trascina per spostarti quando sei ingrandito. Modalità <strong>Annota</strong>: trascina per disegnarne una nuova, trascina un angolo o l'interno di una già esistente per ridimensionarla/spostarla. Modalità <strong>Misura</strong>: trascina per disegnarne una nuova, trascina un estremo di una già esistente per aggiustarla. Modalità <strong>Ritaglia</strong>: trascina per selezionare un frammento da usare in una ricerca inversa per immagini. Modalità <strong>Sovrapponi</strong>: agisci direttamente sull'immagine sovrapposta — trascina il corpo per spostarla, gli angoli (cerchi) per ridimensionarla, i quadratini a metà lato per inclinarla, il cerchietto in alto per ruotarla, il cerchietto sulla guida in basso per l'opacità (gli slider dedicati più sotto restano per il controllo fine, tutti sincronizzati con le maniglie). <strong>↶ Annulla</strong> (o Ctrl+Z) disfa l'ultima azione.</div>
  <div class="tag-row" style="margin-bottom:10px; align-items:center;">
    <label style="display:flex; align-items:center; gap:6px; margin:0; font-size:12px; color:var(--text-secondary);">
      Colore annotazioni <input type="color" id="an-annotate-color" value="#00fff2" title="Colore delle prossime annotazioni disegnate (quelle già esistenti si ricolorano dalla lista Annotazioni più sotto)">
    </label>
    <label style="display:flex; align-items:center; gap:6px; margin:0; font-size:12px; color:var(--text-secondary);">
      Forma
      <select id="an-annotate-shape" title="Forma delle prossime annotazioni. Rettangolo: trascina. Polilinea/Poligono: clicca per aggiungere i vertici, poi 'Termina forma' (o Invio); Esc annulla.">
        <option value="rect">Rettangolo</option>
        <option value="polyline">Polilinea</option>
        <option value="polygon">Poligono</option>
      </select>
    </label>
    <button type="button" class="btn btn-sm" id="an-annotate-finish-shape" style="display:none;">✓ Termina forma</button>
    <label style="display:flex; align-items:center; gap:6px; margin:0; font-size:12px; color:var(--text-secondary);">
      Colore misurazioni <input type="color" id="an-measure-color" value="#ffb020" title="Colore delle prossime misurazioni disegnate (quelle già esistenti si ricolorano dalla lista Misurazioni più sotto)">
    </label>
    <label style="display:flex; align-items:center; gap:6px; margin:0; font-size:12px; color:var(--text-secondary);">
      Dimensione maniglie <input type="range" id="an-handle-size" min="2" max="12" value="5" style="width:80px;" title="Raggio delle maniglie trascinabili di annotazioni e misurazioni: riducilo se ti risultano troppo ingombranti, aumentalo se fai fatica a centrarle.">
    </label>
    <span class="hint" style="margin:0;">Utile per far risaltare meglio i segni a seconda dello sfondo della ripresa.</span>
  </div>

  <div class="grid grid-2">
    <div>
      <h3>Originale</h3>
      <div class="viewer-stage" id="an-stage-left">
        <div class="zoom-viewport" id="an-viewport-left">
          <div class="zoom-content" id="an-content-left">
            <img id="an-img-left" src="<?= e(storage_url($capture['relative_path'])) ?>" alt="">
          </div>
        </div>
      </div>
    </div>
    <div>
      <h3>Copia di lavoro <span class="info-tip" tabindex="0" data-tip="Gli slider qui sotto agiscono in tempo reale, in locale nel browser, senza mai toccare l'originale: nessuna elaborazione lato server finché non premi 'Salva come nuova ripresa'.">?</span></h3>
      <div class="viewer-stage" id="an-stage-right">
        <div class="zoom-viewport" id="an-viewport-right">
          <div class="zoom-content" id="an-content-right">
            <img id="an-img-right" src="<?= e(storage_url($capture['relative_path'])) ?>" alt="">
            <img id="an-overlay-img" src="" alt="" style="display:none; position:absolute; pointer-events:none; transform-origin:center center;">
            <canvas id="an-annotate-canvas"></canvas>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="floating-preview" id="an-floating-preview">
  <button type="button" class="close-btn" id="an-floating-preview-close" title="Nascondi la mini-anteprima" aria-label="Nascondi">✕</button>
  <div class="floating-preview-header" id="an-floating-preview-drag" title="Trascina per spostare — trascina l'angolo in basso a destra per ridimensionare">
    <span class="grip">⠿</span> Anteprima
  </div>
  <div class="floating-preview-body">
    <img id="an-floating-preview-img" alt="Copia di lavoro" title="Torna all'anteprima completa">
  </div>
</div>

<div class="panel">
  <h2>Sovrapposizione immagine <span class="info-tip" tabindex="0" data-tip="Carica una tua immagine (una mappa, un diagramma, un'altra foto) e sovrapponila alla copia di lavoro per confrontarla visivamente con la ripresa satellitare: resta solo nel browser finché non premi 'Salva come nuova ripresa', che la incorpora definitivamente nel file salvato.">?</span></h2>
  <div class="hint" style="margin-bottom:10px;">Caricando un'immagine si passa automaticamente a modalità <strong>🖼 Sovrapponi</strong> (pulsante in alto, sopra l'anteprima): lì agisci <strong>direttamente sull'immagine</strong> — trascina il corpo per spostarla, gli angoli (cerchi) per ridimensionarla, i quadratini a metà lato per inclinarla (skew), il cerchietto in alto per ruotarla, il cerchietto sulla guida in basso per l'opacità. Gli slider qui sotto restano per il controllo fine, sempre sincronizzati con le maniglie.</div>
  <div class="tag-row" style="margin-bottom:10px; align-items:center;">
    <input type="file" id="an-overlay-file" accept="image/png,image/jpeg,image/webp" style="max-width:260px;">
    <button type="button" class="btn btn-sm" id="an-overlay-remove-btn">🗑 Rimuovi sovrapposizione</button>
    <button type="button" class="btn btn-sm" id="an-overlay-reset-btn">↺ Reset trasformazioni</button>
  </div>
  <div class="grid grid-2">
    <div>
      <div class="field">
        <label>Scala <span class="val" id="an-val-overlay-scale" style="margin-left:auto;">100%</span></label>
        <input type="range" id="an-overlay-scale" min="10" max="300" value="100">
      </div>
      <div class="field">
        <label>Rotazione <span class="info-tip" tabindex="0" data-tip="Ruota l'immagine sovrapposta attorno al proprio centro.">?</span><span class="val" id="an-val-overlay-rotation" style="margin-left:auto;">0°</span></label>
        <input type="range" id="an-overlay-rotation" min="-180" max="180" value="0">
      </div>
    </div>
    <div>
      <div class="field">
        <label>Inclinazione orizzontale <span class="info-tip" tabindex="0" data-tip="Inclina (shear) l'immagine sovrapposta lungo l'asse orizzontale: utile per correggere una prospettiva leggermente obliqua rispetto alla ripresa satellitare.">?</span><span class="val" id="an-val-overlay-skewx" style="margin-left:auto;">0°</span></label>
        <input type="range" id="an-overlay-skewx" min="-45" max="45" value="0">
      </div>
      <div class="field">
        <label>Inclinazione verticale <span class="val" id="an-val-overlay-skewy" style="margin-left:auto;">0°</span></label>
        <input type="range" id="an-overlay-skewy" min="-45" max="45" value="0">
      </div>
      <div class="field">
        <label>Opacità <span class="val" id="an-val-overlay-opacity" style="margin-left:auto;">70%</span></label>
        <input type="range" id="an-overlay-opacity" min="0" max="100" value="70">
      </div>
    </div>
  </div>
  <div class="checkbox-row field" style="margin-top:10px;">
    <input type="checkbox" id="an-overlay-chromakey-enabled">
    <label style="margin:0;">Trasparenza per colore (chroma key) <span class="info-tip" tabindex="0" data-tip="Rende trasparente il colore scelto sull'immagine sovrapposta (es. lo sfondo bianco di una mappa/diagramma), per vedere solo i tratti utili sopra la ripresa satellitare. La tolleranza allarga la gamma di colori simili resi trasparenti, con una sfumatura ai bordi per evitare un contorno netto e frastagliato.">?</span></label>
  </div>
  <div class="grid grid-2" id="an-overlay-chromakey-fields" style="display:none;">
    <div class="field">
      <label>Colore da rendere trasparente</label>
      <input type="color" id="an-overlay-chromakey-color" value="#ffffff">
    </div>
    <div class="field">
      <label>Tolleranza <span class="val" id="an-val-overlay-chromakey-tolerance" style="margin-left:auto;">20</span></label>
      <input type="range" id="an-overlay-chromakey-tolerance" min="0" max="100" value="20">
    </div>
  </div>
  <span class="hint" id="an-overlay-status"></span>
</div>

<div class="panel">
  <h2>Regolazioni in tempo reale</h2>
  <div class="grid grid-2">
    <div>
      <div class="field">
        <label>Luminosità <span class="info-tip" tabindex="0" data-tip="Schiarisce o scurisce l'intera immagine in modo uniforme. Utile su riprese sovra/sotto-esposte per far emergere dettagli in ombra o troppo chiari.">?</span><span class="val" id="an-val-brightness" style="margin-left:auto;">100%</span></label>
        <input type="range" id="an-brightness" min="30" max="250" value="100">
      </div>
      <div class="field">
        <label>Contrasto <span class="info-tip" tabindex="0" data-tip="Accentua o riduce la differenza tra zone chiare e scure. Utile per far risaltare strutture su sfondi poco differenziati (es. terreno uniforme, foschia).">?</span><span class="val" id="an-val-contrast" style="margin-left:auto;">100%</span></label>
        <input type="range" id="an-contrast" min="30" max="250" value="100">
      </div>
    </div>
    <div>
      <div class="field">
        <label>Saturazione <span class="info-tip" tabindex="0" data-tip="A 0% desatura fino al bianco e nero completo: aiuta a concentrarsi su bordi/texture/ombre (i dettagli strutturali) invece che sul colore. Sopra 100% accentua l'intensità dei colori.">?</span><span class="val" id="an-val-saturate" style="margin-left:auto;">100%</span></label>
        <input type="range" id="an-saturate" min="0" max="200" value="100">
      </div>
      <div class="field">
        <label>Nitidezza <span class="info-tip" tabindex="0" data-tip="Accentua i bordi e i dettagli fini. Utile per rendere più leggibili i contorni di strutture in riprese leggermente sfocate. Valori alti possono introdurre aloni artificiali.">?</span><span class="val" id="an-val-sharpen" style="margin-left:auto;">0</span></label>
        <input type="range" id="an-sharpen" min="0" max="100" value="0">
      </div>
      <div class="field">
        <label>Gamma <span class="info-tip" tabindex="0" data-tip="Schiarisce o scurisce l'immagine in modo non lineare. Valori sopra 1 schiariscono le zone scure, valori sotto 1 le scuriscono ulteriormente. Utile su riprese sovra/sotto-esposte.">?</span><span class="val" id="an-val-gamma" style="margin-left:auto;">1.0</span></label>
        <input type="range" id="an-gamma" min="0.2" max="3" step="0.1" value="1.0">
      </div>
    </div>
  </div>
  <div class="checkbox-row field" style="margin-top:6px;">
    <input type="checkbox" id="an-include-overlay-layer">
    <label style="margin:0;">Includi annotazioni/misurazioni/scala <span class="info-tip" tabindex="0" data-tip="Incorpora nei pixel il livello con annotazioni, misurazioni ed eventuale barra di scala (quello che vedi ora sulla copia di lavoro), così viaggia insieme all'immagine anche fuori dallo strumento (salvataggio, Telegram, X). Sempre disattivato di default: una scelta esplicita, mai automatica — non include mai le maniglie di modifica.">?</span></label>
  </div>
  <div class="tag-row" style="margin-top:6px; align-items:center;">
    <button type="button" class="btn btn-sm" id="an-reset-btn">↺ Reset regolazioni</button>
    <input type="text" id="an-save-label" placeholder="Etichetta (opzionale)" style="flex:1; min-width:160px;">
    <button type="button" class="btn btn-primary btn-sm" id="an-save-btn" title="Salva la copia di lavoro (con le regolazioni correnti applicate ai pixel) come nuova ripresa permanente in archivio.">💾 Salva come nuova ripresa</button>
  </div>
  <span class="hint" id="an-status"></span>
</div>

<div class="panel">
  <h2>Filtri avanzati <span class="info-tip" tabindex="0" data-tip="A differenza delle regolazioni sopra (istantanee, calcolate nel browser), questi filtri richiedono di analizzare l'intera immagine (istogramma, medie canale, ecc.) e vengono elaborati dal servizio di analisi — stesso identico algoritmo usato in 'Migliora' e nei confronti, per risultati coerenti in tutta la piattaforma. Premi Applica per elaborarli; il risultato diventa la nuova base su cui continuano ad agire le regolazioni in tempo reale sopra.">?</span></h2>
  <div class="grid grid-2">
    <div>
      <div class="checkbox-row field"><input type="checkbox" id="an-wb"><label style="margin:0;">Bilanciamento del bianco <span class="info-tip" tabindex="0" data-tip="Corregge eventuali dominanti di colore (es. dovute a condizioni atmosferiche o al sensore) tramite l'algoritmo gray-world, rendendo i colori dell'immagine più naturali e bilanciati.">?</span></label></div>
      <div class="checkbox-row field"><input type="checkbox" id="an-denoise"><label style="margin:0;">Riduzione rumore <span class="info-tip" tabindex="0" data-tip="Attenua il rumore fotografico/di compressione, utile per pulire una ripresa rumorosa prima di esportarla o riusarla in un confronto.">?</span></label></div>
      <div class="grid grid-2" style="margin-bottom:4px;">
        <select id="an-denoise-method">
          <option value="gaussian">Gaussiano</option>
          <option value="median">Mediano</option>
          <option value="bilateral">Bilaterale</option>
          <option value="nlmeans">Non-local means</option>
        </select>
        <input type="range" id="an-denoise-strength" min="1" max="10" value="3">
      </div>
      <div class="hint" style="margin-bottom:12px;">Gaussiano: sfocatura morbida generica. Mediano: efficace contro il rumore isolato tipo "sale e pepe". Bilaterale: riduce il rumore preservando meglio i bordi netti. Non-local means: il più efficace, anche il più lento.</div>
      <div class="checkbox-row field"><input type="checkbox" id="an-hist-eq"><label style="margin:0;">Equalizzazione istogramma <span class="info-tip" tabindex="0" data-tip="Ridistribuisce l'intera gamma tonale dell'immagine per massimizzare il contrasto globale. Alternativa più semplice e uniforme al CLAHE: usa questa se il CLAHE introduce aloni innaturali, il CLAHE se invece serve un miglioramento più localizzato.">?</span></label></div>
    </div>
    <div>
      <div class="checkbox-row field"><input type="checkbox" id="an-clahe"><label style="margin:0;">CLAHE (contrasto adattivo) <span class="info-tip" tabindex="0" data-tip="Migliora il contrasto locale dell'immagine in modo adattivo, utile su riprese con foschia o forte variazione di illuminazione tra zone diverse della stessa immagine.">?</span></label></div>
      <div class="field">
        <label>Intensità (clip limit) <span class="val" id="an-val-clahe-clip" style="margin-left:auto;">2.0</span></label>
        <input type="range" id="an-clahe-clip" min="1" max="8" step="0.5" value="2.0">
      </div>
      <div class="field" style="margin-bottom:12px;">
        <label>Località (dimensione tile) <span class="val" id="an-val-clahe-grid" style="margin-left:auto;">8</span></label>
        <input type="range" id="an-clahe-grid" min="2" max="16" step="1" value="8">
      </div>
      <div class="checkbox-row field"><input type="checkbox" id="an-edge"><label style="margin:0;">Contorni <span class="info-tip" tabindex="0" data-tip="Sostituisce l'immagine con la mappa dei bordi netti (Canny): utile per isolare il profilo di strutture/edifici dal resto della scena.">?</span></label></div>
      <div class="grid grid-2" style="margin-bottom:12px;">
        <div class="field">
          <label>Soglia bassa <span class="val" id="an-val-edge-low" style="margin-left:auto;">50</span></label>
          <input type="range" id="an-edge-low" min="0" max="255" step="5" value="50">
        </div>
        <div class="field">
          <label>Soglia alta <span class="val" id="an-val-edge-high" style="margin-left:auto;">150</span></label>
          <input type="range" id="an-edge-high" min="0" max="255" step="5" value="150">
        </div>
      </div>
      <div class="checkbox-row field"><input type="checkbox" id="an-desaturate"><label style="margin:0;">Desaturazione B/N <span class="info-tip" tabindex="0" data-tip="Riduce gradualmente la saturazione del colore verso il bianco e nero. Utile per concentrarsi su bordi/texture/ombre (i cambiamenti strutturali reali) invece che su variazioni di colore dovute a stagione, angolo solare o sensore diverso, che possono distrarre o confondere la lettura.">?</span></label></div>
      <div class="field">
        <label>Intensità desaturazione <span class="val" id="an-val-desaturate" style="margin-left:auto;">1.0</span></label>
        <input type="range" id="an-desaturate-amount" min="0" max="1" step="0.05" value="1.0">
      </div>
    </div>
  </div>
  <div class="tag-row" style="margin-top:6px;">
    <button type="button" class="btn btn-primary btn-sm" id="an-advanced-apply-btn">▶ Applica filtri avanzati</button>
    <button type="button" class="btn btn-sm" id="an-advanced-values-reset-btn" title="Riporta gli slider (clip limit, tile, soglie, intensità) ai valori predefiniti, senza deselezionare i filtri né toccare l'immagine.">↺ Reset valori slider</button>
    <button type="button" class="btn btn-sm" id="an-advanced-reset-btn" title="Torna all'immagine originale (annulla anche i filtri avanzati già applicati) e azzera le regolazioni in tempo reale.">🗑 Ripristina originale</button>
  </div>
  <span class="hint" id="an-advanced-status"></span>
</div>

<div class="panel">
  <h2>Annotazioni</h2>
  <div id="an-annotation-list"><div class="hint">Nessuna annotazione su questa ripresa.</div></div>
  <div class="tag-row" style="margin-top:8px;">
    <span class="hint" style="margin:0;">Esporta annotazioni + misurazioni georeferenziate:</span>
    <a class="btn btn-sm" href="api/export_geo.php?capture_id=<?= (int)$capture['id'] ?>&format=kml" title="Layer KML per Google Earth (poligoni/linee con etichette e colori)">⬇ KML</a>
    <a class="btn btn-sm" href="api/export_geo.php?capture_id=<?= (int)$capture['id'] ?>&format=geojson" title="GeoJSON per QGIS e altri strumenti GIS">⬇ GeoJSON</a>
  </div>
</div>

<div class="panel">
  <h2>Misurazioni <span class="info-tip" tabindex="0" data-tip="Stima calcolata dalle coordinate geografiche dell'area scaricata (o da una calibrazione manuale se non disponibili): assume una ripresa verticale (nadir) senza rilievo significativo — per oggetti alti o riprese oblique, la misura reale sul terreno può differire da quella apparente nell'immagine. Le misurazioni non vengono salvate: servono per la lettura immediata durante l'analisi.">?</span></h2>
  <div class="hint" id="an-scale-status" style="margin-bottom:8px;"></div>
  <div class="checkbox-row field">
    <input type="checkbox" id="an-show-scale-bar">
    <label style="margin:0;">Mostra scala in un angolo <span class="info-tip" tabindex="0" data-tip="Sovrappone alla copia di lavoro una barra graduata (come su una cartina), tarata sulla scala reale di questa ripresa/ritaglio, per farsi subito un'idea delle proporzioni. Solo a video: non viene salvata né condivisa insieme all'immagine.">?</span></label>
  </div>
  <div id="an-measurement-list"><div class="hint">Nessuna misurazione. Passa a modalità Misura e trascina sulla copia di lavoro.</div></div>
  <div class="tag-row" style="margin-top:6px;">
    <button type="button" class="btn btn-sm" id="an-measure-clear-btn">🗑 Cancella tutte</button>
  </div>

  <div style="margin-top:14px; padding-top:12px; border-top:1px solid var(--line);">
    <h3>Stima altezza da ombra <span class="info-tip" tabindex="0" data-tip="Tecnica IMINT: altezza ≈ lunghezza dell'ombra × tan(elevazione solare). Serve la data/ora di acquisizione (UTC) e la posizione (presa dal centro dell'area). Per Esri l'ora reale non è nota: inseriscila tu se puoi, altrimenti la stima è indicativa. Assume terreno pianeggiante e ombra proiettata su piano orizzontale.">?</span></h3>
    <div class="grid grid-2" style="margin-bottom:8px;">
      <div class="field">
        <label>Data acquisizione (UTC)</label>
        <input type="date" id="an-shadow-date">
      </div>
      <div class="field">
        <label>Ora acquisizione (UTC) <span id="an-shadow-elev" class="val" style="margin-left:auto;"></span></label>
        <input type="time" id="an-shadow-time" value="10:00">
      </div>
    </div>
    <button type="button" class="btn btn-primary btn-sm" id="an-shadow-measure-btn">📐 Misura un'ombra</button>
    <span class="hint" id="an-shadow-status"></span>
  </div>
</div>

<div class="panel">
  <h2>Ricerca inversa e analisi per immagini <span class="info-tip" tabindex="0" data-tip="Copia o scarica il frammento, poi apri Google Lens (riconoscimento visivo) e/o un assistente AI (Claude, ChatGPT, DeepSeek) per un'analisi/interpretazione, e incollalo/trascinalo tu: l'invio a un servizio esterno resta sempre un gesto esplicito e manuale, mai automatico — importante quando si maneggiano riprese potenzialmente sensibili.">?</span></h2>
  <div class="hint" style="margin-bottom:10px;">Passa a modalità Ritaglia e trascina sulla copia di lavoro per selezionare un frammento.</div>
  <div id="an-crop-result" style="display:none;">
    <div class="checkbox-row field" style="margin-bottom:10px;">
      <input type="checkbox" id="an-crop-include-overlay-layer">
      <label style="margin:0;">Includi annotazioni/misurazioni/scala nel frammento <span class="info-tip" tabindex="0" data-tip="Incorpora nei pixel del frammento il livello con annotazioni, misurazioni ed eventuale barra di scala, allineato all'area ritagliata. Vale per tutte le azioni qui sotto (copia, scarica, apri motori/assistenti, salva, condividi). Sempre disattivato di default: una scelta esplicita — non include mai le maniglie di modifica.">?</span></label>
    </div>
    <div class="grid grid-2">
      <div>
        <h3>Frammento ritagliato</h3>
        <div class="viewer-stage"><img id="an-crop-preview" style="width:100%; display:block;" alt=""></div>
      </div>
      <div>
        <h3>Passi</h3>
        <ol style="color:var(--text-secondary); font-size:13px; padding-left:18px; line-height:1.7;">
          <li>Copia il frammento negli appunti (o scaricalo, se preferisci).</li>
          <li>Apri Google Lens per un riconoscimento visivo, e/o Claude/ChatGPT/DeepSeek per un'analisi/interpretazione descrittiva — sono tutti indipendenti, anche più di uno sullo stesso frammento.</li>
          <li>Incolla con Ctrl+V — o trascina il file scaricato se l'incolla non fosse supportato dal sito aperto. Sugli assistenti AI aggiungi poi una domanda, es. "Cosa riconosci in questa immagine satellitare? Stime dimensionali se possibile."</li>
        </ol>
        <div class="tag-row">
          <button type="button" class="btn btn-primary btn-sm" id="an-crop-copy-btn">📋 Copia negli appunti</button>
          <button type="button" class="btn btn-sm" id="an-crop-download-btn">⬇ Scarica frammento</button>
        </div>
        <span class="hint" id="an-crop-copy-status"></span>
        <div class="tag-row" style="margin-top:8px;">
          <button type="button" class="btn btn-sm btn-primary" id="an-crop-open-lens">🔍 Apri Google Lens ↗</button>
          <button type="button" class="btn btn-sm btn-primary" id="an-crop-open-claude">🤖 Apri Claude ↗</button>
          <button type="button" class="btn btn-sm btn-primary" id="an-crop-open-chatgpt">💬 Apri ChatGPT ↗</button>
          <button type="button" class="btn btn-sm btn-primary" id="an-crop-open-deepseek">🐋 Apri DeepSeek ↗</button>
        </div>
      </div>
    </div>
    <hr style="margin:18px 0; border:none; border-top:1px solid var(--border-color, #333);">
    <div class="grid grid-2">
      <div>
        <h3>Salva ritaglio <span class="info-tip" tabindex="0" data-tip="Salva il frammento (con le regolazioni correnti già applicate) come nuova ripresa permanente in archivio, associata a questo studio — riusabile in confronti futuri come qualunque altra ripresa.">?</span></h3>
        <div class="field">
          <label>Etichetta</label>
          <input type="text" id="an-crop-save-label" style="width:100%;" value="<?= e('Ritaglio di ' . ($capture['label'] ?: ('ripresa #' . $capture['id']))) ?>">
        </div>
        <div class="tag-row">
          <button type="button" class="btn btn-primary btn-sm" id="an-crop-save-btn">💾 Salva ritaglio come nuova ripresa</button>
        </div>
        <span class="hint" id="an-crop-save-status"></span>
      </div>
      <div>
        <h3>Condividi ritaglio <span class="info-tip" tabindex="0" data-tip="Invia il frammento su Telegram, oppure apri la finestra di composizione X — per incollare l'immagine su X usa 'Copia negli appunti' qui sopra. Nessuna pubblicazione automatica, il ritaglio non deve essere salvato prima per poter essere condiviso.">?</span></h3>
        <div class="field">
          <label>Didascalia</label>
          <textarea id="an-crop-share-caption" rows="2" style="width:100%;"><?= e('Ritaglio da ' . ($capture['label'] ?: ('ripresa #' . $capture['id'])) . ' — OrbitalEye') ?></textarea>
        </div>
        <div class="tag-row">
          <button type="button" class="btn btn-primary btn-sm" id="an-crop-share-telegram-btn">📤 Invia su Telegram</button>
          <button type="button" class="btn btn-sm" id="an-crop-share-twitter-btn">🐦 Apri su X</button>
        </div>
        <span class="hint" id="an-crop-share-status"></span>
      </div>
    </div>
  </div>
</div>

<div class="panel">
  <h2>Condividi <span class="info-tip" tabindex="0" data-tip="Invia la copia di lavoro (con le regolazioni correnti già applicate) su Telegram, oppure apri la finestra di composizione X e incolla/trascina l'immagine a mano. Anteprima e didascalia sono sempre modificabili prima dell'invio: nessuna pubblicazione automatica.">?</span></h2>
  <div class="field">
    <label>Didascalia</label>
    <textarea id="an-share-caption" rows="2" style="width:100%;"><?= e($shareDefaultCaption) ?></textarea>
  </div>
  <div class="tag-row">
    <button type="button" class="btn btn-primary btn-sm" id="an-share-telegram-btn">📤 Invia su Telegram</button>
    <button type="button" class="btn btn-sm" id="an-share-copy-btn">📋 Copia immagine negli appunti</button>
    <button type="button" class="btn btn-sm" id="an-share-twitter-btn">🐦 Apri su X</button>
  </div>
  <span class="hint" id="an-share-status"></span>
</div>

<script>
window.ORBITALEYE_ANALYZE = {
  studyId: <?= (int)$study['id'] ?>,
  captureId: <?= (int)$capture['id'] ?>,
  imageUrl: <?= json_encode(storage_url($capture['relative_path'])) ?>,
  bbox: <?= $measureBbox ? json_encode(array_map('floatval', $measureBbox)) : 'null' ?>,
  // Scala reale già risolta (metri/pixel, per asse) se disponibile —
  // preferita alla bbox qui sopra quando presente (vedi Capture::resolveMpp
  // lato PHP e computeGeoScale() in analyze.js).
  mppX: <?= $measureMpp ? json_encode($measureMpp['mpp_x']) : 'null' ?>,
  mppY: <?= $measureMpp ? json_encode($measureMpp['mpp_y']) : 'null' ?>,
  // Data di acquisizione (per la stima altezza da ombra: pre-compila il
  // campo data; l'ora resta da inserire, non nota per Esri).
  captureDate: <?= json_encode($capture['capture_date'] ?? null) ?>,
  // Bbox geografica grezza (per centro area -> lat/lon nella stima ombra).
  geoBbox: <?= $geoBbox ? json_encode(array_map('floatval', $geoBbox)) : 'null' ?>,
  // Angolo (gradi) applicato in fase di scaricamento se l'area era stata
  // ruotata (vedi ImageRotateCrop.php): gli assi pixel di questa immagine
  // non sono allineati a lon/lat come al solito, serve per calcolare
  // correttamente le distanze reali — vedi pixelDistance() in analyze.js.
  rotation: <?= json_encode(is_array($captureMeta) ? (float)($captureMeta['rotation'] ?? 0) : 0.0) ?>,
};
</script>
<script src="assets/js/analyze.js?v=<?= @filemtime(__DIR__ . '/assets/js/analyze.js') ?: time() ?>"></script>

<?php require __DIR__ . '/partials/footer.php'; ?>
