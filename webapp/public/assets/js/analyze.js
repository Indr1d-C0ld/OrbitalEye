(function () {
  const CFG = window.ORBITALEYE_ANALYZE;
  if (!CFG) return;

  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

  const TARGET_KEY = 'capture' + CFG.captureId + '_analyze';

  // ---------- Zoom/pan sincronizzato tra i due riquadri ----------
  // A differenza del visualizzatore dei confronti (un solo riquadro), qui i
  // due pannelli (originale a sinistra, copia di lavoro a destra) condividono
  // UN SOLO stato di zoom/pan: zoomare o spostarsi in uno dei due sposta
  // identicamente anche l'altro, per confrontare a colpo d'occhio lo stesso
  // dettaglio prima/dopo le regolazioni.
  const MIN_SCALE = 1, MAX_SCALE = 10, STEP = 1.25;
  let scale = 1, tx = 0, ty = 0;
  // Sposta di default (non Annota): appena apri la pagina vuoi prima
  // esplorare/zoomare, annotare/misurare/ritagliare/sovrapporre sono azioni
  // volontarie successive.
  let mode = 'pan';

  const viewportLeft = $('#an-viewport-left');
  const viewportRight = $('#an-viewport-right');
  const contentLeft = $('#an-content-left');
  const contentRight = $('#an-content-right');
  const imgLeft = $('#an-img-left');
  const imgRight = $('#an-img-right');

  function applyTransform() {
    const t = `translate(${tx}px, ${ty}px) scale(${scale})`;
    contentLeft.style.transform = t;
    contentRight.style.transform = t;
    $('#an-zoom-level').textContent = Math.round(scale * 100) + '%';
  }

  function clampPan(viewport) {
    const vw = viewport.clientWidth, vh = viewport.clientHeight;
    const cw = vw * scale, ch = vh * scale;
    tx = Math.max(Math.min(0, vw - cw), Math.min(0, tx));
    ty = Math.max(Math.min(0, vh - ch), Math.min(0, ty));
  }

  function zoomAt(viewport, cx, cy, factor) {
    const newScale = Math.max(MIN_SCALE, Math.min(MAX_SCALE, scale * factor));
    if (newScale === scale) return;
    const contentX = (cx - tx) / scale;
    const contentY = (cy - ty) / scale;
    tx = cx - contentX * newScale;
    ty = cy - contentY * newScale;
    scale = newScale;
    clampPan(viewport);
    applyTransform();
    resizeAnnotateCanvas();
  }

  [viewportLeft, viewportRight].forEach((viewport) => {
    viewport.addEventListener('wheel', (e) => {
      e.preventDefault();
      const rect = viewport.getBoundingClientRect();
      zoomAt(viewport, e.clientX - rect.left, e.clientY - rect.top, e.deltaY < 0 ? STEP : 1 / STEP);
    }, { passive: false });
  });

  $('#an-zoom-in').addEventListener('click', () => zoomAt(viewportRight, viewportRight.clientWidth / 2, viewportRight.clientHeight / 2, STEP));
  $('#an-zoom-out').addEventListener('click', () => zoomAt(viewportRight, viewportRight.clientWidth / 2, viewportRight.clientHeight / 2, 1 / STEP));
  $('#an-zoom-reset').addEventListener('click', () => {
    scale = 1; tx = 0; ty = 0;
    applyTransform();
    resizeAnnotateCanvas();
  });

  // ---------- Pan a trascinamento (mouse + touch), attivo solo in modalità Sposta ----------
  function setupPan(viewport) {
    let panning = false, startX = 0, startY = 0, startTx = 0, startTy = 0;
    viewport.addEventListener('mousedown', (e) => {
      if (mode !== 'pan' || scale <= 1.001) return;
      panning = true;
      startX = e.clientX; startY = e.clientY;
      startTx = tx; startTy = ty;
      viewport.classList.add('panning');
    });
    window.addEventListener('mousemove', (e) => {
      if (!panning) return;
      tx = startTx + (e.clientX - startX);
      ty = startTy + (e.clientY - startY);
      clampPan(viewport);
      applyTransform();
      // La barra di scala live è ancorata all'area visibile: durante il pan
      // va ridisegnata per restare nell'angolo, il resto del canvas overlay
      // si sposta già col transform CSS.
      if (showScaleBar) redrawAnnotations();
    });
    window.addEventListener('mouseup', () => {
      if (!panning) return;
      panning = false;
      viewport.classList.remove('panning');
      resizeAnnotateCanvas();
    });

    viewport.addEventListener('touchstart', (e) => {
      if (e.touches.length !== 1 || mode !== 'pan' || scale <= 1.001) return;
      panning = true;
      const t = e.touches[0];
      startX = t.clientX; startY = t.clientY;
      startTx = tx; startTy = ty;
    }, { passive: true });
    viewport.addEventListener('touchmove', (e) => {
      if (!panning || e.touches.length !== 1) return;
      e.preventDefault();
      const t = e.touches[0];
      tx = startTx + (t.clientX - startX);
      ty = startTy + (t.clientY - startY);
      clampPan(viewport);
      applyTransform();
      if (showScaleBar) redrawAnnotations();
    }, { passive: false });
    viewport.addEventListener('touchend', () => {
      if (!panning) return;
      panning = false;
      resizeAnnotateCanvas();
    });

    // Pinch-to-zoom (due dita), equivalente touch della rotellina.
    let pinchStartDist = null, pinchStartScale = 1;
    const dist = (a, b) => Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY);
    viewport.addEventListener('touchstart', (e) => {
      if (e.touches.length === 2) { pinchStartDist = dist(e.touches[0], e.touches[1]); pinchStartScale = scale; }
    }, { passive: true });
    viewport.addEventListener('touchmove', (e) => {
      if (e.touches.length !== 2 || !pinchStartDist) return;
      e.preventDefault();
      const rect = viewport.getBoundingClientRect();
      const mid = { x: (e.touches[0].clientX + e.touches[1].clientX) / 2 - rect.left, y: (e.touches[0].clientY + e.touches[1].clientY) / 2 - rect.top };
      const targetScale = Math.max(MIN_SCALE, Math.min(MAX_SCALE, pinchStartScale * (dist(e.touches[0], e.touches[1]) / pinchStartDist)));
      zoomAt(viewport, mid.x, mid.y, targetScale / scale);
    }, { passive: false });
    viewport.addEventListener('touchend', (e) => { if (e.touches.length < 2) pinchStartDist = null; });
  }
  setupPan(viewportLeft);
  setupPan(viewportRight);

  $$('#an-mode-toggle .mode-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      mode = btn.dataset.mode;
      $$('#an-mode-toggle .mode-btn').forEach((b) => b.classList.toggle('active', b === btn));
      viewportLeft.classList.toggle('mode-pan', mode === 'pan');
      viewportRight.classList.toggle('mode-pan', mode === 'pan');
      // Le maniglie (annotazioni/misure/overlay) dipendono da `mode` per
      // decidere se disegnarsi: al cambio modalità vanno ridisegnate subito,
      // altrimenti restano quelle (assenti o presenti) della modalità
      // precedente fino al prossimo evento che tocchi il canvas.
      if (mode !== 'annotate' && typeof cancelPolyDraft === 'function') cancelPolyDraft();
      redrawAnnotations();
      renderOverlay();
    });
  });
  viewportLeft.classList.toggle('mode-pan', mode === 'pan');
  viewportRight.classList.toggle('mode-pan', mode === 'pan');

  // ---------- Regolazioni in tempo reale (CSS + filtro SVG, zero rete) ----------
  // Luminosità/contrasto/saturazione: funzioni filter() native del browser,
  // istantanee. Nitidezza: nessuna funzione CSS nativa esiste, quindi si usa
  // un filtro SVG feConvolveMatrix (kernel di sharpening) il cui kernelMatrix
  // viene ricalcolato ad ogni movimento dello slider — anch'esso applicato
  // dal browser in tempo reale, senza alcuna chiamata al servizio di analisi.
  const svgNS = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(svgNS, 'svg');
  svg.setAttribute('width', '0');
  svg.setAttribute('height', '0');
  svg.style.position = 'absolute';

  const filterEl = document.createElementNS(svgNS, 'filter');
  filterEl.setAttribute('id', 'an-sharpen-filter');
  const convolve = document.createElementNS(svgNS, 'feConvolveMatrix');
  convolve.setAttribute('order', '3');
  convolve.setAttribute('divisor', '1');
  convolve.setAttribute('bias', '0');
  convolve.setAttribute('edgeMode', 'duplicate');
  convolve.setAttribute('preserveAlpha', 'true');
  filterEl.appendChild(convolve);
  svg.appendChild(filterEl);

  // Gamma: anche qui nessuna funzione CSS filter() nativa esiste, ma SVG ha
  // esattamente feComponentTransfer type="gamma" per questo (stessa formula
  // usata server-side in enhance.py: C' = C^(1/gamma)), quindi anche questo
  // resta un aggiustamento istantaneo lato browser, nessuna chiamata al
  // servizio di analisi.
  const gammaFilterEl = document.createElementNS(svgNS, 'filter');
  gammaFilterEl.setAttribute('id', 'an-gamma-filter');
  const gammaTransfer = document.createElementNS(svgNS, 'feComponentTransfer');
  const gammaFuncs = ['feFuncR', 'feFuncG', 'feFuncB'].map((tag) => {
    const fn = document.createElementNS(svgNS, tag);
    fn.setAttribute('type', 'gamma');
    fn.setAttribute('amplitude', '1');
    fn.setAttribute('offset', '0');
    gammaTransfer.appendChild(fn);
    return fn;
  });
  gammaFilterEl.appendChild(gammaTransfer);
  svg.appendChild(gammaFilterEl);

  document.body.appendChild(svg);

  function sharpenKernel(amount) {
    // amount: 0-100 dallo slider -> k: 0-2 (0 = nessun effetto).
    const k = (amount / 100) * 2;
    return `0 ${-k} 0 ${-k} ${1 + 4 * k} ${-k} 0 ${-k} 0`;
  }

  const adjust = { brightness: 100, contrast: 100, saturate: 100, sharpen: 0, gamma: 1.0 };

  function applyLiveFilters() {
    let f = `brightness(${adjust.brightness}%) contrast(${adjust.contrast}%) saturate(${adjust.saturate}%)`;
    if (adjust.gamma !== 1.0) {
      const exponent = 1 / adjust.gamma;
      gammaFuncs.forEach((fn) => fn.setAttribute('exponent', exponent));
      f += ' url(#an-gamma-filter)';
    }
    if (adjust.sharpen > 0) {
      convolve.setAttribute('kernelMatrix', sharpenKernel(adjust.sharpen));
      f += ' url(#an-sharpen-filter)';
    }
    imgRight.style.filter = f;
  }

  const SLIDER_MAP = [
    ['an-brightness', 'an-val-brightness', 'brightness', (v) => v + '%'],
    ['an-contrast', 'an-val-contrast', 'contrast', (v) => v + '%'],
    ['an-saturate', 'an-val-saturate', 'saturate', (v) => v + '%'],
    ['an-sharpen', 'an-val-sharpen', 'sharpen', (v) => v],
    ['an-gamma', 'an-val-gamma', 'gamma', (v) => v.toFixed(1)],
  ];
  SLIDER_MAP.forEach(([inputId, outId, key, fmt]) => {
    const input = $('#' + inputId);
    const out = $('#' + outId);
    input.addEventListener('input', () => {
      adjust[key] = parseFloat(input.value);
      out.textContent = fmt(adjust[key]);
      applyLiveFilters();
    });
  });

  function resetSliders() {
    adjust.brightness = 100; adjust.contrast = 100; adjust.saturate = 100; adjust.sharpen = 0; adjust.gamma = 1.0;
    SLIDER_MAP.forEach(([inputId, outId, key, fmt]) => {
      $('#' + inputId).value = adjust[key];
      $('#' + outId).textContent = fmt(adjust[key]);
    });
    applyLiveFilters();
  }
  $('#an-reset-btn').addEventListener('click', resetSliders);

  // ---------- Salva come nuova ripresa ----------
  // Riproduce via canvas, pixel per pixel e alla risoluzione originale, le
  // stesse formule usate dai filtri CSS/SVG live (stesso ordine di
  // applicazione: luminosità -> contrasto -> saturazione -> gamma ->
  // nitidezza), così il file salvato corrisponde esattamente a quanto visto
  // in anteprima. Se sono stati applicati filtri avanzati (server-side),
  // imgRight punta già al risultato elaborato: questa funzione parte sempre
  // dall'immagine correntemente caricata, qualunque essa sia.
  // Applica le regolazioni correnti (adjust.*) direttamente sui pixel di un
  // canvas già disegnato — riusata sia per il salvataggio dell'intera copia
  // di lavoro (renderAdjustedCanvas) sia per il ritaglio per la ricerca
  // inversa (renderCropFromSelection), così il frammento ritagliato riflette
  // le stesse regolazioni visibili in anteprima, non l'immagine "nuda".
  function applyPixelAdjustments(ctx, w, h) {
    const imageData = ctx.getImageData(0, 0, w, h);
    const data = imageData.data;

    const b = adjust.brightness / 100;
    const c = adjust.contrast / 100;
    const s = adjust.saturate / 100;
    const invGamma = 1 / adjust.gamma;
    // Matrice di saturazione preservante la luminanza (stessa usata dal
    // filtro SVG/CSS saturate() nativo dei browser).
    const sm = [
      0.213 + 0.787 * s, 0.715 - 0.715 * s, 0.072 - 0.072 * s,
      0.213 - 0.213 * s, 0.715 + 0.285 * s, 0.072 - 0.072 * s,
      0.213 - 0.213 * s, 0.715 - 0.715 * s, 0.072 + 0.928 * s,
    ];
    // LUT gamma (0-255 -> 0-255): identica alla formula server-side in
    // enhance.py gamma_correction().
    const gammaLut = new Float64Array(256);
    for (let v = 0; v < 256; v++) gammaLut[v] = Math.pow(v / 255, invGamma) * 255;

    for (let i = 0; i < data.length; i += 4) {
      let r = data[i], g = data[i + 1], bl = data[i + 2];
      r *= b; g *= b; bl *= b; // luminosità
      r = (r - 128) * c + 128; g = (g - 128) * c + 128; bl = (bl - 128) * c + 128; // contrasto
      const r2 = sm[0] * r + sm[1] * g + sm[2] * bl;
      const g2 = sm[3] * r + sm[4] * g + sm[5] * bl;
      const b2 = sm[6] * r + sm[7] * g + sm[8] * bl; // saturazione
      const ri = Math.max(0, Math.min(255, Math.round(r2)));
      const gi = Math.max(0, Math.min(255, Math.round(g2)));
      const bi = Math.max(0, Math.min(255, Math.round(b2)));
      data[i] = adjust.gamma !== 1.0 ? gammaLut[ri] : ri; // gamma
      data[i + 1] = adjust.gamma !== 1.0 ? gammaLut[gi] : gi;
      data[i + 2] = adjust.gamma !== 1.0 ? gammaLut[bi] : bi;
    }

    if (adjust.sharpen > 0) {
      const k = (adjust.sharpen / 100) * 2;
      const kernel = [0, -k, 0, -k, 1 + 4 * k, -k, 0, -k, 0];
      const src = new Uint8ClampedArray(data); // copia pre-convoluzione
      for (let y = 0; y < h; y++) {
        for (let x = 0; x < w; x++) {
          for (let ch = 0; ch < 3; ch++) {
            let sum = 0, ki = 0;
            for (let ky = -1; ky <= 1; ky++) {
              for (let kx = -1; kx <= 1; kx++, ki++) {
                const sx = Math.min(w - 1, Math.max(0, x + kx));
                const sy = Math.min(h - 1, Math.max(0, y + ky));
                sum += src[(sy * w + sx) * 4 + ch] * kernel[ki];
              }
            }
            data[(y * w + x) * 4 + ch] = Math.max(0, Math.min(255, sum));
          }
        }
      }
    }

    ctx.putImageData(imageData, 0, 0);
  }

  function renderAdjustedCanvas() {
    const canvas = document.createElement('canvas');
    canvas.width = imgRight.naturalWidth;
    canvas.height = imgRight.naturalHeight;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(imgRight, 0, 0);
    applyPixelAdjustments(ctx, canvas.width, canvas.height);
    // La sovrapposizione (se caricata) va sopra, fuori dalle regolazioni
    // pixel della ripresa base: è un riferimento esterno, non fa parte
    // della ripresa satellitare e non va storto da luminosità/contrasto/ecc.
    drawOverlayOnto(ctx, canvas.width, canvas.height);
    // Annotazioni/misurazioni/scala: livello aggiuntivo, incorporato SOLO se
    // l'analista lo sceglie esplicitamente (checkbox "Includi annotazioni/
    // misurazioni/scala") — mai per default, per non far trapelare segni di
    // lavoro in un salvataggio/condivisione senza accorgersene. Niente
    // maniglie di modifica: non sono contenuto, solo un aiuto mentre si
    // lavora dal vivo.
    const includeOverlayLayerEl = $('#an-include-overlay-layer');
    if (includeOverlayLayerEl && includeOverlayLayerEl.checked) {
      drawOverlayLayer(ctx, canvas.width, canvas.height, false);
    }
    return canvas;
  }

  $('#an-save-btn').addEventListener('click', () => {
    const status = $('#an-status');
    status.textContent = 'Rendering in corso...';
    const canvas = renderAdjustedCanvas();
    canvas.toBlob(async (blob) => {
      if (!blob) { status.textContent = 'Errore: impossibile generare l\'immagine.'; return; }
      status.textContent = 'Salvataggio in corso...';
      const form = new FormData();
      form.append('study_id', CFG.studyId);
      form.append('image', blob, 'analisi_' + CFG.captureId + '.png');
      form.append('label', $('#an-save-label').value || ('Analisi di ripresa #' + CFG.captureId));
      // Eredita la scala reale (metri/pixel) di questa ripresa: nessuna
      // regolazione qui la modifica (stesse dimensioni pixel), ma senza
      // indicare la sorgente la nuova ripresa non avrebbe alcuna scala
      // propria — vedi Capture::resolveMpp lato server.
      form.append('source_capture_id', CFG.captureId);
      try {
        const res = await fetch('api/upload_capture.php', { method: 'POST', body: form });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Errore');
        status.textContent = 'Salvata come nuova ripresa. Torno allo studio...';
        setTimeout(() => { window.location.href = 'study.php?id=' + CFG.studyId; }, 700);
      } catch (err) {
        status.textContent = 'Errore: ' + err.message;
      }
    }, 'image/png');
  });

  // ---------- Filtri avanzati (elaborati dal servizio di analisi) ----------
  // A differenza delle regolazioni sopra, questi (bilanciamento del bianco,
  // riduzione rumore, CLAHE, equalizzazione istogramma, contorni) richiedono
  // statistiche sull'intera immagine o algoritmi troppo pesanti per un vero
  // tempo reale in JS: si riusa lo stesso endpoint/algoritmo di "Migliora",
  // per garantire lo stesso risultato ovunque nella piattaforma. Il risultato
  // diventa la nuova base su cui le regolazioni in tempo reale continuano ad
  // agire (imgRight viene semplicemente ripuntata al file elaborato).
  function buildAdvancedSteps() {
    const steps = [];
    if ($('#an-wb').checked) steps.push({ filter: 'white_balance', params: {} });
    if ($('#an-denoise').checked) {
      steps.push({
        filter: 'denoise',
        params: { method: $('#an-denoise-method').value, strength: parseInt($('#an-denoise-strength').value, 10) },
      });
    }
    if ($('#an-clahe').checked) {
      steps.push({
        filter: 'clahe',
        params: { clip_limit: parseFloat($('#an-clahe-clip').value), tile_grid_size: parseInt($('#an-clahe-grid').value, 10) },
      });
    }
    if ($('#an-hist-eq').checked) steps.push({ filter: 'histogram_equalization', params: {} });
    if ($('#an-edge').checked) {
      steps.push({
        filter: 'edge_detect',
        params: { low: parseInt($('#an-edge-low').value, 10), high: parseInt($('#an-edge-high').value, 10) },
      });
    }
    if ($('#an-desaturate').checked) steps.push({ filter: 'desaturate', params: { amount: parseFloat($('#an-desaturate-amount').value) } });
    return steps;
  }

  // Aggiornamento del valore mostrato accanto agli slider dei filtri
  // avanzati (stesso schema minimale già usato per le regolazioni in tempo
  // reale, qui applicato ai parametri prima nascosti di CLAHE/Canny/
  // desaturazione).
  const ADVANCED_SLIDER_DEFAULTS = [
    ['an-clahe-clip', 'an-val-clahe-clip', '2.0'],
    ['an-clahe-grid', 'an-val-clahe-grid', '8'],
    ['an-edge-low', 'an-val-edge-low', '50'],
    ['an-edge-high', 'an-val-edge-high', '150'],
    ['an-desaturate-amount', 'an-val-desaturate', '1.0'],
  ];
  ADVANCED_SLIDER_DEFAULTS.forEach(([inputId, outId]) => {
    const input = $('#' + inputId);
    const out = $('#' + outId);
    if (input && out) input.addEventListener('input', () => (out.textContent = input.value));
  });

  // Riporta solo i VALORI degli slider ai default (senza deselezionare i
  // filtri già spuntati né toccare l'immagine): utile dopo aver provato
  // parametri estremi e voler ripartire da un punto noto, senza il "reset
  // totale" più drastico di "Ripristina originale" qui sotto.
  $('#an-advanced-values-reset-btn').addEventListener('click', () => {
    ADVANCED_SLIDER_DEFAULTS.forEach(([inputId, outId, def]) => {
      const input = $('#' + inputId);
      const out = $('#' + outId);
      if (input) input.value = def;
      if (out) out.textContent = def;
    });
  });

  $('#an-advanced-apply-btn').addEventListener('click', async () => {
    const steps = buildAdvancedSteps();
    const status = $('#an-advanced-status');
    if (!steps.length) {
      status.textContent = 'Seleziona almeno un filtro.';
      return;
    }
    const prevSrc = imgRight.src; // per l'undo: potrebbe già essere un risultato di un'applicazione precedente
    status.textContent = 'Elaborazione in corso...';
    try {
      const res = await fetch('api/enhance_capture.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ capture_id: CFG.captureId, steps, preview: true }),
      });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Errore');
      imgRight.src = data.url + '&_=' + Date.now();
      status.textContent = 'Filtri applicati: ora sono la nuova base per le regolazioni in tempo reale sopra.';
      pushUndo(() => {
        imgRight.src = prevSrc;
        status.textContent = 'Filtri avanzati annullati.';
      });
    } catch (err) {
      status.textContent = 'Errore: ' + err.message;
    }
  });

  $('#an-advanced-reset-btn').addEventListener('click', () => {
    const prevSrc = imgRight.src;
    const prevAdjust = { ...adjust };
    const prevChecks = {};
    ['an-wb', 'an-denoise', 'an-clahe', 'an-hist-eq', 'an-edge', 'an-desaturate'].forEach((id) => { prevChecks[id] = $('#' + id).checked; });

    imgRight.src = CFG.imageUrl;
    resetSliders();
    ['an-wb', 'an-denoise', 'an-clahe', 'an-hist-eq', 'an-edge', 'an-desaturate'].forEach((id) => { $('#' + id).checked = false; });
    $('#an-advanced-status').textContent = 'Ripristinata la ripresa originale.';

    pushUndo(() => {
      imgRight.src = prevSrc;
      Object.assign(adjust, prevAdjust);
      SLIDER_MAP.forEach(([inputId, outId, key, fmt]) => {
        $('#' + inputId).value = adjust[key];
        $('#' + outId).textContent = fmt(adjust[key]);
      });
      applyLiveFilters();
      Object.entries(prevChecks).forEach(([id, checked]) => { $('#' + id).checked = checked; });
      $('#an-advanced-status').textContent = 'Ripristino annullato.';
    });
  });

  // ---------- Annotazioni (sulla copia di lavoro, a destra) ----------
  let annotations = [];
  // Dichiarati qui (non più giù insieme al resto della logica di scala/
  // misurazione) perché redrawAnnotations() li referenzia e può essere
  // invocata già in modo sincrono più sotto (resizeAnnotateCanvas se
  // l'immagine è già in cache) — dichiararli più in basso darebbe un errore
  // "cannot access before initialization" (temporal dead zone).
  let mppX = null, mppY = null, scaleSource = null; // scaleSource: 'geo' | 'manual' | null
  let showScaleBar = false; // barra grafica della scala, in un angolo, come su una cartina
  const measurements = [];
  const canvas = $('#an-annotate-canvas');

  // ---------- Colore corrente per nuove annotazioni/misurazioni ----------
  // Scelto prima di disegnare (come in un editor grafico), per far risaltare
  // meglio i segni a seconda dello sfondo della ripresa. Gli elementi già
  // esistenti si ricolorano singolarmente dallo swatch nella loro lista, non
  // cambiano retroattivamente quando si sceglie un nuovo colore qui.
  let currentAnnotateColor = '#00fff2';
  let currentMeasureColor = '#ffb020';
  const annotateColorInput = $('#an-annotate-color');
  const measureColorInput = $('#an-measure-color');
  if (annotateColorInput) annotateColorInput.addEventListener('input', () => { currentAnnotateColor = annotateColorInput.value; });
  if (measureColorInput) measureColorInput.addEventListener('input', () => { currentMeasureColor = measureColorInput.value; });

  // Forma dell'annotazione in corso ('rect' = trascina; 'polyline'/'polygon'
  // = clic per aggiungere vertici, poi "Termina forma"/Invio, Esc annulla).
  const annotateShapeInput = $('#an-annotate-shape');
  let annotateShape = annotateShapeInput ? annotateShapeInput.value : 'rect';
  let polyDraft = null; // { shape, points: [[cx,cy],...] } in pixel canvas durante il disegno
  if (annotateShapeInput) {
    annotateShapeInput.addEventListener('change', () => {
      annotateShape = annotateShapeInput.value;
      cancelPolyDraft();
    });
  }
  function cancelPolyDraft() {
    polyDraft = null;
    const btn = $('#an-annotate-finish-shape');
    if (btn) btn.style.display = 'none';
    redrawAnnotations();
  }
  async function finishPolyDraft() {
    if (!polyDraft) return;
    const min = polyDraft.shape === 'polygon' ? 3 : 2;
    if (polyDraft.points.length < min) { cancelPolyDraft(); return; }
    const coords = { points: polyDraft.points.map(([cx, cy]) => [cx / canvas.width, cy / canvas.height]) };
    const shape = polyDraft.shape;
    const color = currentAnnotateColor;
    polyDraft = null;
    const btn = $('#an-annotate-finish-shape');
    if (btn) btn.style.display = 'none';
    const id = await createAnnotationServer(coords, color, null, null, shape);
    const a = { id, shape_type: shape, coords, color, label: null, notes: null };
    annotations.push(a);
    redrawAnnotations();
    renderAnnotationList();
    pushUndo(async () => {
      await deleteAnnotationServer(a.id);
      const idx = annotations.indexOf(a);
      if (idx !== -1) annotations.splice(idx, 1);
      redrawAnnotations();
      renderAnnotationList();
    });
  }
  const finishShapeBtn = $('#an-annotate-finish-shape');
  if (finishShapeBtn) finishShapeBtn.addEventListener('click', finishPolyDraft);
  document.addEventListener('keydown', (e) => {
    if (!polyDraft) return;
    if (e.key === 'Enter') { e.preventDefault(); finishPolyDraft(); }
    else if (e.key === 'Escape') { e.preventDefault(); cancelPolyDraft(); }
  });

  // Stato sovrapposizione immagine (vedi sezione dedicata più sotto per il
  // resto della logica): dichiarato qui, non più giù, per lo stesso motivo
  // di annotations/measurements sopra — resizeAnnotateCanvas() lo referenzia
  // (tramite renderOverlay) e può scattare in modo sincrono già durante il
  // caricamento della pagina.
  const overlayImgEl = $('#an-overlay-img');
  const overlay = {
    img: null,
    loaded: false,
    cx: 0.5, cy: 0.5, // centro, frazione di canvas.width/height
    baseWFrac: 0.3, baseHFrac: 0.3, // dimensione "1x" calcolata al caricamento dal rapporto d'aspetto reale
    scale: 1.0,
    rotation: 0, // gradi
    skewX: 0, skewY: 0, // gradi
    opacity: 0.7,
    // Trasparenza selettiva per colore ("chroma key"): utile quando l'immagine
    // sovrapposta è una mappa/diagramma con uno sfondo a tinta unita (bianco,
    // verde...) che si vuole far scomparire per vedere solo i tratti/markup
    // utili sopra la ripresa satellitare. displaySource è la versione
    // effettivamente disegnata (con la chiave colore applicata se attiva):
    // sia l'anteprima live (overlayImgEl) sia il disegno finale in
    // drawOverlayOnto() la usano, per restare sempre pixel-coerenti.
    chromaKeyEnabled: false,
    chromaKeyColor: '#ffffff',
    chromaKeyTolerance: 20, // 0-100
    displaySource: null,
  };

  // Rende trasparenti i pixel dell'immagine sorgente entro una certa
  // distanza euclidea RGB dal colore scelto, con un margine di sfumatura
  // (1.4x la soglia) per evitare un bordo netto "a scaletta" attorno
  // all'area resa trasparente. Richiede un'immagine same-origin (qui sempre
  // vera: l'overlay viene sempre da un file locale via
  // URL.createObjectURL, mai da un URL remoto) altrimenti getImageData
  // fallirebbe per canvas "tainted".
  function applyChromaKey(sourceImg, hexColor, tolerancePercent) {
    const w = sourceImg.naturalWidth || sourceImg.width;
    const h = sourceImg.naturalHeight || sourceImg.height;
    const canvas = document.createElement('canvas');
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(sourceImg, 0, 0, w, h);
    const imageData = ctx.getImageData(0, 0, w, h);
    const data = imageData.data;
    const r0 = parseInt(hexColor.slice(1, 3), 16);
    const g0 = parseInt(hexColor.slice(3, 5), 16);
    const b0 = parseInt(hexColor.slice(5, 7), 16);
    const maxDist = Math.sqrt(3 * 255 * 255);
    const threshold = (tolerancePercent / 100) * maxDist;
    const featherEnd = threshold * 1.4;
    for (let i = 0; i < data.length; i += 4) {
      const dr = data[i] - r0, dg = data[i + 1] - g0, db = data[i + 2] - b0;
      const dist = Math.sqrt(dr * dr + dg * dg + db * db);
      if (dist <= threshold) {
        data[i + 3] = 0;
      } else if (dist < featherEnd) {
        data[i + 3] = Math.round(data[i + 3] * ((dist - threshold) / (featherEnd - threshold)));
      }
    }
    ctx.putImageData(imageData, 0, 0);
    return canvas;
  }

  // Ricalcola overlay.displaySource (con o senza chiave colore applicata) e
  // aggiorna l'anteprima live: va richiamata al caricamento di una nuova
  // immagine e ogni volta che i controlli della chiave colore cambiano.
  // Non va richiamata per scala/rotazione/inclinazione/opacità, che non
  // toccano i pixel e restano gestite via CSS transform su overlayImgEl.
  function refreshOverlayDisplay() {
    if (!overlay.loaded) return;
    overlay.displaySource = overlay.chromaKeyEnabled
      ? applyChromaKey(overlay.img, overlay.chromaKeyColor, overlay.chromaKeyTolerance)
      : overlay.img;
    overlayImgEl.src = overlay.displaySource === overlay.img ? overlay.img.src : overlay.displaySource.toDataURL();
  }

  // ---------- Undo globale ----------
  // Pila di azioni "strutturali" (creare/eliminare/spostare un'annotazione o
  // una misurazione, applicare filtri avanzati): ogni voce sa da sola come
  // disfarsi. Le regolazioni in tempo reale restano fuori (hanno già un loro
  // "Reset regolazioni" dedicato, e includerle intaserebbe la pila ad ogni
  // singolo movimento di uno slider).
  const undoStack = [];
  function pushUndo(undoFn) {
    undoStack.push(undoFn);
    const btn = $('#an-undo-btn');
    if (btn) btn.disabled = false;
  }
  async function performUndo() {
    const fn = undoStack.pop();
    if (!fn) return;
    await fn();
    const btn = $('#an-undo-btn');
    if (btn) btn.disabled = undoStack.length === 0;
  }
  const undoBtn = $('#an-undo-btn');
  if (undoBtn) undoBtn.addEventListener('click', performUndo);
  window.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') {
      e.preventDefault();
      performUndo();
    }
  });

  function resizeAnnotateCanvas() {
    canvas.width = imgRight.clientWidth;
    canvas.height = imgRight.clientHeight;
    redrawAnnotations();
    renderOverlay();
  }
  window.addEventListener('resize', resizeAnnotateCanvas);
  imgRight.addEventListener('load', resizeAnnotateCanvas);
  imgLeft.addEventListener('load', () => { resizeAnnotateCanvas(); computeGeoScale(); });
  if (imgRight.complete) resizeAnnotateCanvas();
  if (imgLeft.complete) computeGeoScale();

  // Misurazioni <-> righe annotations (shape_type 'measure'): internamente
  // le misurazioni restano in pixel canvas assoluti (tutto il disegno/hit-
  // test/drag li usa così), la conversione a/da frazioni avviene solo al
  // confine con il server, per renderle indipendenti dalla risoluzione
  // come le annotazioni.
  function measureCoordsFrac(m) {
    return {
      x1: m.x1 / canvas.width, y1: m.y1 / canvas.height,
      x2: m.x2 / canvas.width, y2: m.y2 / canvas.height,
    };
  }
  function measureFromRow(row) {
    const c = row.coords || {};
    const m = {
      id: row.id,
      x1: (c.x1 || 0) * canvas.width, y1: (c.y1 || 0) * canvas.height,
      x2: (c.x2 || 0) * canvas.width, y2: (c.y2 || 0) * canvas.height,
      color: row.color || '#ffb020',
      label: row.label || '',
      distanceM: null,
    };
    const { distanceM } = pixelDistance(m.x1, m.y1, m.x2, m.y2);
    m.distanceM = distanceM;
    return m;
  }

  async function loadAnnotations() {
    const res = await fetch(`api/annotations.php?study_id=${CFG.studyId}&target_image=${encodeURIComponent(TARGET_KEY)}`);
    const data = await res.json();
    const rows = data.annotations || [];
    // Stessa tabella per annotazioni (rect/polyline/polygon) e misurazioni
    // persistenti (shape_type 'measure'): si smistano al caricamento.
    annotations = rows.filter((r) => r.shape_type !== 'measure');
    measurements.length = 0;
    rows.filter((r) => r.shape_type === 'measure').forEach((r) => measurements.push(measureFromRow(r)));
    redrawAnnotations();
    renderAnnotationList();
    renderMeasurementList();
  }

  // Nonostante il nome (storico, condiviso con study.js), ridisegna sia le
  // annotazioni persistenti sia le misurazioni correnti: condividono lo
  // stesso canvas overlay sulla copia di lavoro.
  // Raggio delle maniglie: regolabile dallo slider "Dimensione maniglie"
  // nella toolbar (non più fisso) — quel che va bene su un monitor grande
  // può risultare ingombrante su uno piccolo, o viceversa scomodo al tocco.
  let HANDLE_R = 5;
  const handleSizeInput = $('#an-handle-size');
  if (handleSizeInput) {
    HANDLE_R = parseFloat(handleSizeInput.value) || HANDLE_R;
    handleSizeInput.addEventListener('input', () => {
      HANDLE_R = parseFloat(handleSizeInput.value);
      redrawAnnotations();
    });
  }

  // Disegna annotazioni + misurazioni + barra di scala su un contesto
  // qualunque risoluzione (targetW/targetH): la vista live (canvas.width/
  // height, coordinate "CSS") e l'incorporazione in un salvataggio/
  // condivisione (risoluzione nativa) usano la STESSA funzione, così
  // quello che si vede è sempre esattamente quello che finisce nel file —
  // stesso principio già seguito per regolazioni/sovrapposizione immagine.
  // k: fattore di scala dalla risoluzione CSS di riferimento a targetW/H,
  // usato per spessori/font (le annotazioni sono già in coordinate
  // frazionarie, le misurazioni no: coordinate assolute in spazio CSS).
  // includeHandles: false per un'esportazione (le maniglie di modifica non
  // sono contenuto, solo un aiuto visivo mentre si lavora dal vivo).
  function drawOverlayLayer(ctx, targetW, targetH, includeHandles) {
    const k = targetW / canvas.width;
    annotations.forEach((a) => {
      const col = a.color || '#00fff2';
      // ----- Polilinea / poligono -----
      if ((a.shape_type === 'polyline' || a.shape_type === 'polygon') && a.coords && Array.isArray(a.coords.points)) {
        const pts = a.coords.points.map(([fx, fy]) => [fx * targetW, fy * targetH]);
        if (pts.length < 2) return;
        ctx.strokeStyle = col;
        ctx.lineWidth = 2 * k;
        ctx.shadowColor = col;
        ctx.shadowBlur = 6 * k;
        ctx.beginPath();
        ctx.moveTo(pts[0][0], pts[0][1]);
        for (let i = 1; i < pts.length; i++) ctx.lineTo(pts[i][0], pts[i][1]);
        if (a.shape_type === 'polygon') ctx.closePath();
        ctx.stroke();
        ctx.shadowBlur = 0;
        if (a.label) {
          ctx.font = (11 * k) + 'px "Share Tech Mono", monospace';
          ctx.fillStyle = col;
          ctx.fillText(a.label, pts[0][0] + 3 * k, pts[0][1] - 4 * k);
        }
        if (includeHandles && mode === 'annotate') {
          ctx.fillStyle = col;
          pts.forEach(([hx, hy]) => {
            ctx.beginPath(); ctx.arc(hx, hy, HANDLE_R * k, 0, Math.PI * 2); ctx.fill();
          });
        }
        return;
      }
      // ----- Rettangolo -----
      const c = a.coords;
      const rx = c.x * targetW, ry = c.y * targetH, rw = c.w * targetW, rh = c.h * targetH;
      ctx.strokeStyle = col;
      ctx.lineWidth = 2 * k;
      ctx.shadowColor = col;
      ctx.shadowBlur = 6 * k;
      ctx.strokeRect(rx, ry, rw, rh);
      if (a.label) {
        ctx.font = (11 * k) + 'px "Share Tech Mono", monospace';
        ctx.fillStyle = col;
        ctx.shadowBlur = 0;
        ctx.fillText(a.label, rx + 3 * k, ry - 4 * k);
      }
      // Maniglie d'angolo: visibili solo in modalità Annota, per far capire
      // che un'annotazione già esistente si può ridimensionare/spostare
      // trascinando, non solo cancellare dalla lista sotto.
      if (includeHandles && mode === 'annotate') {
        ctx.shadowBlur = 0;
        ctx.fillStyle = col;
        [[rx, ry], [rx + rw, ry], [rx, ry + rh], [rx + rw, ry + rh]].forEach(([hx, hy]) => {
          ctx.beginPath();
          ctx.arc(hx, hy, HANDLE_R * k, 0, Math.PI * 2);
          ctx.fill();
        });
      }
    });
    // Poligono/polilinea in fase di disegno (vertici cliccati finora).
    if (includeHandles && polyDraft && polyDraft.points.length) {
      ctx.strokeStyle = currentAnnotateColor;
      ctx.fillStyle = currentAnnotateColor;
      ctx.lineWidth = 2 * k;
      ctx.setLineDash([4 * k, 3 * k]);
      ctx.beginPath();
      ctx.moveTo(polyDraft.points[0][0] * k, polyDraft.points[0][1] * k);
      for (let i = 1; i < polyDraft.points.length; i++) ctx.lineTo(polyDraft.points[i][0] * k, polyDraft.points[i][1] * k);
      if (polyDraft.shape === 'polygon' && polyDraft.points.length > 2) ctx.closePath();
      ctx.stroke();
      ctx.setLineDash([]);
      polyDraft.points.forEach(([hx, hy]) => {
        ctx.beginPath(); ctx.arc(hx * k, hy * k, HANDLE_R * k, 0, Math.PI * 2); ctx.fill();
      });
    }
    measurements.forEach((m) => {
      const x1 = m.x1 * k, y1 = m.y1 * k, x2 = m.x2 * k, y2 = m.y2 * k;
      const mColor = m.color || '#ffb020';
      ctx.strokeStyle = mColor;
      ctx.lineWidth = 2 * k;
      ctx.shadowColor = mColor;
      ctx.shadowBlur = 4 * k;
      ctx.beginPath();
      ctx.moveTo(x1, y1);
      ctx.lineTo(x2, y2);
      ctx.stroke();
      ctx.shadowBlur = 0;
      ctx.font = (12 * k) + 'px "Share Tech Mono", monospace';
      ctx.fillStyle = mColor;
      const mLabelText = m.label ? `${m.label} — ${formatDistance(m.distanceM)}` : formatDistance(m.distanceM);
      ctx.fillText(mLabelText, (x1 + x2) / 2 + 6 * k, (y1 + y2) / 2 - 6 * k);
      // Maniglie agli estremi: visibili solo in modalità Misura, trascinabili
      // per aggiustare la linea senza doverla cancellare e ridisegnare.
      if (includeHandles && mode === 'measure') {
        ctx.fillStyle = mColor;
        [[x1, y1], [x2, y2]].forEach(([hx, hy]) => {
          ctx.beginPath();
          ctx.arc(hx, hy, HANDLE_R * k, 0, Math.PI * 2);
          ctx.fill();
        });
      }
    });
    // includeHandles distingue perfettamente vista live (true) da
    // incorporazione in un export (false): la barra di scala lo riusa come
    // flag "live" per decidere se adattarsi allo zoom o restare a distanza
    // fissa sull'immagine intera.
    drawScaleBar(ctx, targetW, targetH, includeHandles);
  }

  function redrawAnnotations() {
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    drawOverlayLayer(ctx, canvas.width, canvas.height, true);
  }

  // ---------- Barra di scala (come su una cartina) ----------
  // Arrotonda una distanza a un valore "leggibile" (1/2/5 × potenza di 10),
  // stessa convenzione delle barre di scala di qualunque software GIS.
  function niceScaleDistance(targetMeters) {
    if (!targetMeters || !isFinite(targetMeters) || targetMeters <= 0) return 1;
    const magnitude = Math.pow(10, Math.floor(Math.log10(targetMeters)));
    const residual = targetMeters / magnitude;
    let nice;
    if (residual < 1.5) nice = 1;
    else if (residual < 3.5) nice = 2;
    else if (residual < 7.5) nice = 5;
    else nice = 10;
    return nice * magnitude;
  }

  // Disegnata nello stesso canvas di annotazioni/misurazioni (coordinate
  // "CSS", cioè canvas.width/height = imgRight.clientWidth/Height).
  //
  // live=true (vista a schermo): adatta la distanza rappresentata allo zoom
  // corrente — la barra rappresenta sempre ~20% dell'AREA VISIBILE (quindi
  // "10 m" da vicino, "200 m" da lontano) e resta ancorata in basso a
  // sinistra del riquadro visibile anche quando si è ingranditi e spostati.
  // Spessori/font divisi per lo zoom, così a schermo restano costanti.
  //
  // live=false (incorporazione in un salvataggio/condivisione): nessuno
  // zoom, distanza fissa ~20% della larghezza dell'immagine, ancorata in
  // basso a sinistra dell'immagine intera; spessori scalati col fattore k
  // verso la risoluzione nativa (WYSIWYG rispetto alla vista).
  function drawScaleBar(ctx, targetW, targetH, live) {
    if (!showScaleBar || !mppX || !imgRight.naturalWidth || !targetW) return;

    let metersPerPx, niceMeters, x0, y0, sc;
    if (live) {
      sc = scale || 1;
      metersPerPx = mppX * (imgRight.naturalWidth / canvas.width); // per pixel canvas
      const visibleWidthM = (canvas.width / sc) * metersPerPx;
      niceMeters = niceScaleDistance(visibleWidthM * 0.2);
      const xVisMin = -tx / sc, yVisMax = (canvas.height - ty) / sc;
      x0 = xVisMin + 14 / sc;
      y0 = yVisMax - 30 / sc;
    } else {
      sc = canvas.width / targetW; // divisore comune: k = 1/sc
      metersPerPx = mppX * (imgRight.naturalWidth / targetW);
      niceMeters = niceScaleDistance(metersPerPx * targetW * 0.2);
      x0 = targetW * 0.03;
      y0 = targetH - targetH * 0.05;
    }
    const barPx = niceMeters / metersPerPx;
    if (!isFinite(barPx) || barPx <= 0) return;
    const x1 = x0 + barPx;
    const u = 1 / sc; // unità "1 pixel a schermo/nativo" espressa in pixel canvas
    const tickH = 6 * u;
    const label = niceMeters >= 1000 ? (niceMeters / 1000) + ' km' : niceMeters + ' m';

    ctx.save();
    ctx.strokeStyle = '#ffffff';
    ctx.fillStyle = '#ffffff';
    ctx.lineWidth = 2 * u;
    ctx.shadowColor = 'rgba(0,0,0,0.85)';
    ctx.shadowBlur = 3 * u;
    ctx.beginPath();
    ctx.moveTo(x0, y0);
    ctx.lineTo(x1, y0);
    ctx.stroke();
    [x0, x1].forEach((x) => {
      ctx.beginPath();
      ctx.moveTo(x, y0 - tickH / 2);
      ctx.lineTo(x, y0 + tickH / 2);
      ctx.stroke();
    });
    ctx.font = (12 * u) + 'px "Share Tech Mono", monospace';
    ctx.textAlign = 'center';
    ctx.fillText(label, (x0 + x1) / 2, y0 - tickH - 4 * u);
    ctx.restore();
  }

  // ---------- Chiamate server per le annotazioni (create/update/delete) ----------
  async function createAnnotationServer(coords, color, label, notes, shapeType) {
    const res = await fetch('api/annotations.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ study_id: CFG.studyId, capture_id: CFG.captureId, target_image: TARGET_KEY, shape_type: shapeType || 'rect', coords, color, label, notes }),
    });
    const data = await res.json();
    return data.id;
  }
  async function updateAnnotationServer(id, coords, label, notes, color) {
    // color: passalo solo quando vuoi davvero cambiarlo (es. ricolorazione).
    // Omesso (undefined), il PUT lato server lascia il colore invariato —
    // così spostamento/ridimensionamento/modifica testo non lo alterano mai
    // per sbaglio.
    const body = { id, coords, label, notes };
    if (color !== undefined) body.color = color;
    await fetch('api/annotations.php', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
  }
  async function deleteAnnotationServer(id) {
    await fetch('api/annotations.php?id=' + id, { method: 'DELETE' });
  }

  async function removeAnnotation(a) {
    if (!confirm('Eliminare questa annotazione?')) return;
    await deleteAnnotationServer(a.id);
    const idx = annotations.indexOf(a);
    if (idx !== -1) annotations.splice(idx, 1);
    redrawAnnotations();
    renderAnnotationList();
    pushUndo(async () => {
      a.id = await createAnnotationServer(a.coords, a.color, a.label, a.notes);
      annotations.push(a);
      redrawAnnotations();
      renderAnnotationList();
    });
  }

  async function editAnnotationText(a) {
    const newLabel = prompt('Etichetta annotazione:', a.label || '');
    if (newLabel === null) return;
    const newNotes = prompt('Note (opzionale):', a.notes || '') ?? a.notes;
    const prevLabel = a.label, prevNotes = a.notes;
    await updateAnnotationServer(a.id, a.coords, newLabel, newNotes);
    a.label = newLabel;
    a.notes = newNotes;
    redrawAnnotations();
    renderAnnotationList();
    pushUndo(async () => {
      await updateAnnotationServer(a.id, a.coords, prevLabel, prevNotes);
      a.label = prevLabel;
      a.notes = prevNotes;
      redrawAnnotations();
      renderAnnotationList();
    });
  }

  async function recolorAnnotation(a, newColor) {
    const prevColor = a.color;
    if (newColor === prevColor) return;
    await updateAnnotationServer(a.id, a.coords, a.label, a.notes, newColor);
    a.color = newColor;
    redrawAnnotations();
    pushUndo(async () => {
      await updateAnnotationServer(a.id, a.coords, a.label, a.notes, prevColor);
      a.color = prevColor;
      redrawAnnotations();
      renderAnnotationList();
    });
  }

  function renderAnnotationList() {
    const container = $('#an-annotation-list');
    if (!annotations.length) {
      container.innerHTML = '<div class="hint">Nessuna annotazione su questa ripresa.</div>';
      return;
    }
    container.innerHTML = '';
    annotations.forEach((a) => {
      const div = document.createElement('div');
      div.className = 'region-item';
      const left = document.createElement('span');
      left.textContent = (a.label ? a.label : 'senza etichetta') + (a.notes ? ' — ' + a.notes : '');
      const right = document.createElement('span');
      right.style.display = 'flex';
      right.style.alignItems = 'center';
      right.style.gap = '8px';
      const dot = document.createElement('input');
      dot.type = 'color';
      dot.value = a.color || '#00fff2';
      dot.title = 'Cambia il colore di questa annotazione';
      dot.style.width = '24px';
      dot.style.height = '24px';
      dot.style.padding = '0';
      dot.style.border = 'none';
      dot.style.background = 'none';
      dot.addEventListener('input', () => recolorAnnotation(a, dot.value));
      const edit = document.createElement('button');
      edit.type = 'button';
      edit.className = 'btn btn-sm';
      edit.textContent = '✎';
      edit.title = 'Modifica etichetta/note (per spostarla o ridimensionarla, trascina le sue maniglie in modalità Annota)';
      edit.addEventListener('click', () => editAnnotationText(a));
      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'btn btn-sm btn-danger';
      del.textContent = '✕';
      del.title = 'Elimina annotazione';
      del.addEventListener('click', () => removeAnnotation(a));
      right.appendChild(dot);
      right.appendChild(edit);
      right.appendChild(del);
      div.appendChild(left);
      div.appendChild(right);
      container.appendChild(div);
    });
  }

  function toCanvasCoords(e) {
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    return [(e.clientX - rect.left) * scaleX, (e.clientY - rect.top) * scaleY];
  }

  // ---------- Scala e misurazione distanze ----------
  // meters-per-pixel calcolato separatamente per X e Y (non assunto uguale):
  // la bbox scaricata potrebbe avere un rapporto d'aspetto diverso da
  // larghezza/altezza dell'immagine richiesta, quindi i "pixel" non sono
  // necessariamente quadrati sul terreno. (mppX/mppY/scaleSource/measurements
  // dichiarati più in alto, vedi commento vicino a "let annotations".)
  function haversineMeters(lat1, lon1, lat2, lon2) {
    const R = 6371000;
    const toRad = (d) => (d * Math.PI) / 180;
    const dLat = toRad(lat2 - lat1);
    const dLon = toRad(lon2 - lon1);
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * Math.sin(dLon / 2) ** 2;
    return 2 * R * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  }

  function computeGeoScale() {
    // Scala già risolta/ereditata (riprese derivate: salvate/migliorate/
    // ritagliate — vedi Capture::resolveMpp lato PHP): preferita alla bbox,
    // evita di doverla ricalcolare qui e soprattutto evita che si ricada
    // sulla bbox generica dello studio passata come fallback quando manca
    // sia questa sia la bbox propria (bug corretto: prima ogni ripresa
    // derivata non aveva scala propria e finiva su quel fallback, sbagliato
    // per le sue dimensioni pixel effettive).
    if (CFG.mppX && CFG.mppY) {
      mppX = CFG.mppX;
      mppY = CFG.mppY;
      scaleSource = 'geo';
      updateScaleStatus();
      return;
    }
    if (!CFG.bbox || !imgLeft.naturalWidth) return;
    const [minLon, minLat, maxLon, maxLat] = CFG.bbox;
    const centerLat = (minLat + maxLat) / 2;
    const centerLon = (minLon + maxLon) / 2;
    const widthM = haversineMeters(centerLat, minLon, centerLat, maxLon);
    const heightM = haversineMeters(minLat, centerLon, maxLat, centerLon);
    mppX = widthM / imgLeft.naturalWidth;
    mppY = heightM / imgLeft.naturalHeight;
    scaleSource = 'geo';
    updateScaleStatus();
  }

  function updateScaleStatus() {
    const el = $('#an-scale-status');
    if (!el) return;
    if (scaleSource === 'geo') {
      el.textContent = `Scala da coordinate geografiche: ~${mppX.toFixed(2)} × ${mppY.toFixed(2)} m/pixel.`;
    } else if (scaleSource === 'manual') {
      el.textContent = `Scala da calibrazione manuale: ~${mppX.toFixed(2)} m/pixel (uniforme). Ricarica la pagina per ricalibrare.`;
    } else {
      el.textContent = 'Nessuna scala geografica nota per questa ripresa: la prima misurazione chiederà una lunghezza reale nota per calibrare la scala.';
    }
  }
  updateScaleStatus();

  function formatDistance(m) {
    return m >= 1000 ? (m / 1000).toFixed(2) + ' km' : m.toFixed(1) + ' m';
  }

  function renderMeasurementList() {
    const container = $('#an-measurement-list');
    if (!measurements.length) {
      container.innerHTML = '<div class="hint">Nessuna misurazione. Passa a modalità Misura e trascina sulla copia di lavoro.</div>';
      return;
    }
    container.innerHTML = '';
    measurements.forEach((m, i) => {
      const div = document.createElement('div');
      div.className = 'region-item';
      const left = document.createElement('span');
      left.textContent = `#${i + 1} — ${formatDistance(m.distanceM)}`;
      const labelInput = document.createElement('input');
      labelInput.type = 'text';
      labelInput.value = m.label || '';
      labelInput.placeholder = 'etichetta (opz.), es. "apertura alare"';
      labelInput.title = 'Etichetta di questa misurazione: appare anche sulla ripresa, accanto alla distanza.';
      labelInput.style.flex = '1';
      labelInput.style.minWidth = '140px';
      labelInput.addEventListener('click', (e) => e.stopPropagation()); // non selezionare/deselezionare la misurazione mentre si scrive
      labelInput.addEventListener('focus', () => { labelInput.dataset.prevValue = m.label || ''; });
      labelInput.addEventListener('change', () => {
        const prevLabel = labelInput.dataset.prevValue || '';
        m.label = labelInput.value;
        redrawAnnotations();
        if (m.id) updateAnnotationServer(m.id, measureCoordsFrac(m), m.label || null, null);
        pushUndo(() => {
          m.label = prevLabel;
          labelInput.value = prevLabel;
          redrawAnnotations();
          if (m.id) updateAnnotationServer(m.id, measureCoordsFrac(m), prevLabel || null, null);
        });
      });
      const right = document.createElement('span');
      right.style.display = 'flex';
      right.style.alignItems = 'center';
      right.style.gap = '8px';
      const swatch = document.createElement('input');
      swatch.type = 'color';
      swatch.value = m.color || '#ffb020';
      swatch.title = 'Cambia il colore di questa misurazione';
      swatch.style.width = '24px';
      swatch.style.height = '24px';
      swatch.style.padding = '0';
      swatch.style.border = 'none';
      swatch.style.background = 'none';
      swatch.addEventListener('input', () => {
        const prevColor = m.color;
        m.color = swatch.value;
        redrawAnnotations();
        if (m.id) updateAnnotationServer(m.id, measureCoordsFrac(m), m.label || null, null, m.color);
        pushUndo(() => {
          m.color = prevColor;
          redrawAnnotations();
          renderMeasurementList();
          if (m.id) updateAnnotationServer(m.id, measureCoordsFrac(m), m.label || null, null, prevColor);
        });
      });
      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'btn btn-sm btn-danger';
      del.textContent = '✕';
      del.title = 'Rimuovi misurazione';
      del.addEventListener('click', async () => {
        measurements.splice(i, 1);
        redrawAnnotations();
        renderMeasurementList();
        if (m.id) await deleteAnnotationServer(m.id);
        pushUndo(async () => {
          measurements.push(m);
          if (m.id) m.id = await createAnnotationServer(measureCoordsFrac(m), m.color, m.label || null, null, 'measure');
          redrawAnnotations();
          renderMeasurementList();
        });
      });
      right.appendChild(swatch);
      right.appendChild(del);
      div.appendChild(left);
      div.appendChild(labelInput);
      div.appendChild(right);
      container.appendChild(div);
    });
  }

  $('#an-measure-clear-btn').addEventListener('click', async () => {
    if (measurements.length && !confirm('Cancellare tutte le misurazioni? Vengono rimosse anche dall\'archivio.')) return;
    const ids = measurements.filter((m) => m.id).map((m) => m.id);
    measurements.length = 0;
    redrawAnnotations();
    renderMeasurementList();
    for (const id of ids) await deleteAnnotationServer(id);
  });

  $('#an-show-scale-bar').addEventListener('change', (e) => {
    showScaleBar = e.target.checked;
    redrawAnnotations();
  });

  // ---------- Stima altezza da ombra ----------
  // Elevazione solare (gradi sull'orizzonte) per lat/lon e istante UTC.
  // Algoritmo solare semplificato (NOAA), precisione ~0.5° — più che
  // sufficiente vista l'incertezza intrinseca nel tracciare un'ombra.
  function solarElevationDeg(lat, lon, date) {
    const rad = Math.PI / 180;
    const n = date.getTime() / 86400000 + 2440587.5 - 2451545.0; // giorni da J2000
    const L = (280.460 + 0.9856474 * n) % 360;
    const g = ((357.528 + 0.9856003 * n) % 360) * rad;
    const lambda = (L + 1.915 * Math.sin(g) + 0.020 * Math.sin(2 * g)) * rad;
    const eps = (23.439 - 0.0000004 * n) * rad;
    const decl = Math.asin(Math.sin(eps) * Math.sin(lambda));
    const ra = Math.atan2(Math.cos(eps) * Math.sin(lambda), Math.cos(lambda));
    const gmst = ((18.697374558 + 24.06570982441908 * n) % 24 + 24) % 24;
    const ha = (gmst * 15 + lon) * rad - ra;
    const latR = lat * rad;
    const sinAlt = Math.sin(latR) * Math.sin(decl) + Math.cos(latR) * Math.cos(decl) * Math.cos(ha);
    return Math.asin(Math.max(-1, Math.min(1, sinAlt))) / rad;
  }

  let shadowMeasureArmed = false;
  const shadowDateEl = $('#an-shadow-date');
  const shadowTimeEl = $('#an-shadow-time');
  const shadowElevEl = $('#an-shadow-elev');
  const shadowStatusEl = $('#an-shadow-status');

  function captureCenterLatLon() {
    const bb = CFG.geoBbox || CFG.bbox;
    if (!bb) return null;
    const [minLon, minLat, maxLon, maxLat] = bb;
    return [(minLat + maxLat) / 2, (minLon + maxLon) / 2];
  }
  function currentShadowElevation() {
    const ll = captureCenterLatLon();
    if (!ll || !shadowDateEl.value) return null;
    const dt = new Date(shadowDateEl.value + 'T' + (shadowTimeEl.value || '00:00') + ':00Z');
    if (isNaN(dt.getTime())) return null;
    return solarElevationDeg(ll[0], ll[1], dt);
  }
  function refreshShadowElevLabel() {
    const el = currentShadowElevation();
    shadowElevEl.textContent = el === null ? '' : ('sole ' + el.toFixed(1) + '°');
  }
  if (shadowDateEl) {
    if (CFG.captureDate && /^\d{4}-\d{2}-\d{2}/.test(CFG.captureDate)) shadowDateEl.value = CFG.captureDate.slice(0, 10);
    [shadowDateEl, shadowTimeEl].forEach((el) => el.addEventListener('input', refreshShadowElevLabel));
    refreshShadowElevLabel();
    $('#an-shadow-measure-btn').addEventListener('click', () => {
      if (!captureCenterLatLon()) { shadowStatusEl.textContent = 'Nessuna posizione geografica nota per questa ripresa.'; return; }
      const el = currentShadowElevation();
      if (el === null || el < 3) { shadowStatusEl.textContent = 'Sole troppo basso o data/ora non valide: l\'ombra non dà una stima affidabile.'; return; }
      shadowMeasureArmed = true;
      const measBtn = $('#an-mode-toggle .mode-btn[data-mode="measure"]');
      if (measBtn && mode !== 'measure') measBtn.click();
      shadowStatusEl.textContent = 'Traccia la lunghezza dell\'ombra sulla copia di lavoro (dalla base dell\'oggetto alla punta dell\'ombra).';
    });
  }

  function pixelDistance(x1, y1, x2, y2) {
    // Converte lo spostamento in pixel canvas in pixel dell'immagine
    // originale (naturalWidth/Height), poi in metri reali per asse.
    const natW = imgLeft.naturalWidth, natH = imgLeft.naturalHeight;
    const dxPx = ((x2 - x1) / canvas.width) * natW;
    const dyPx = ((y2 - y1) / canvas.height) * natH;
    if (mppX && mppY) {
      // Se la ripresa è stata scaricata ruotata (vedi ImageRotateCrop.php),
      // gli assi pixel dell'immagine non sono più allineati a lon/lat: "un
      // passo a destra" nell'immagine corrisponde sul terreno a una
      // direzione ruotata di CFG.rotation rispetto a est, non più pura
      // longitudine. mppX/mppY restano validi solo nel sistema LOCALE
      // (allineato a lon/lat, quello di prima della rotazione): riportiamo
      // lo spostamento lì (ruotandolo indietro di CFG.rotation, inversa
      // esatta della trasformazione applicata in fase di ritaglio) prima di
      // applicare le due scale per asse — altrimenti la distanza calcolata
      // è sistematicamente sbagliata, tanto più quanto più l'angolo si
      // avvicina a 45°/135° (verificato: senza questa correzione l'errore
      // può arrivare a un fattore ~mppX/mppY, non un semplice offset).
      let localDxPx = dxPx, localDyPx = dyPx;
      if (CFG.rotation) {
        const rad = (CFG.rotation * Math.PI) / 180;
        localDxPx = dxPx * Math.cos(rad) - dyPx * Math.sin(rad);
        localDyPx = dxPx * Math.sin(rad) + dyPx * Math.cos(rad);
      }
      const dxM = localDxPx * mppX, dyM = localDyPx * mppY;
      return { distanceM: Math.sqrt(dxM * dxM + dyM * dyM), dxPx, dyPx };
    }
    return { distanceM: null, dxPx, dyPx };
  }

  // ---------- Ritaglio per ricerca inversa per immagini ----------
  let lastCropBlobUrl = null;
  let lastCropBlob = null; // frammento "pulito", per anteprima e come base del livello opzionale
  let lastCropCanvas = null; // riferimento al canvas pulito, per ricomporre al volo col livello
  let lastCropNativeRect = null; // {sx,sy,sw,sh} in pixel nativi, per ritagliare la stessa area dal livello

  function renderCropFromSelection(x, y, w, h) {
    const natW = imgRight.naturalWidth, natH = imgRight.naturalHeight;
    const sx = (x / canvas.width) * natW;
    const sy = (y / canvas.height) * natH;
    const sw = (w / canvas.width) * natW;
    const sh = (h / canvas.height) * natH;
    lastCropNativeRect = { sx, sy, sw, sh };

    const cropCanvas = document.createElement('canvas');
    cropCanvas.width = Math.max(1, Math.round(sw));
    cropCanvas.height = Math.max(1, Math.round(sh));
    const ctx = cropCanvas.getContext('2d');
    // Ritaglia dalla copia di lavoro così com'è mostrata (base server-side +
    // regolazioni live "cotte" dentro, stessa logica di renderAdjustedCanvas
    // ma limitata al solo rettangolo selezionato).
    ctx.drawImage(imgRight, sx, sy, sw, sh, 0, 0, cropCanvas.width, cropCanvas.height);
    applyPixelAdjustments(ctx, cropCanvas.width, cropCanvas.height);
    lastCropCanvas = cropCanvas;

    cropCanvas.toBlob((blob) => {
      if (!blob) return;
      if (lastCropBlobUrl) URL.revokeObjectURL(lastCropBlobUrl);
      lastCropBlobUrl = URL.createObjectURL(blob);
      lastCropBlob = blob;
      $('#an-crop-preview').src = lastCropBlobUrl;
      $('#an-crop-result').style.display = '';
      // Un nuovo ritaglio selezionato invalida gli stati di salvataggio/invio
      // del ritaglio precedente: niente conferme "fantasma" per un frammento
      // che non è più quello mostrato in anteprima.
      const saveStatus = $('#an-crop-save-status');
      const shareStatus = $('#an-crop-share-status');
      if (saveStatus) saveStatus.textContent = '';
      if (shareStatus) shareStatus.textContent = '';
    }, 'image/png');
  }

  // Ricompone al volo il frammento col livello annotazioni/misurazioni/
  // scala, SOLO se la checkbox dedicata è spuntata (mai per default):
  // disegna il livello a piena risoluzione nativa (stessa area/dimensioni
  // dell'intera copia di lavoro) e ne ritaglia la stessa area del
  // frammento, cioè lo stesso rettangolo sorgente già usato per il
  // frammento "pulito" — così i due restano sempre perfettamente allineati.
  // Nessuna maniglia di modifica: non è contenuto, solo un aiuto dal vivo.
  function buildCropBlob() {
    return new Promise((resolve) => {
      if (!lastCropCanvas) { resolve(null); return; }
      const includeEl = $('#an-crop-include-overlay-layer');
      if (!includeEl || !includeEl.checked || !lastCropNativeRect) {
        lastCropCanvas.toBlob(resolve, 'image/png');
        return;
      }
      const { sx, sy, sw, sh } = lastCropNativeRect;
      const fullLayer = document.createElement('canvas');
      fullLayer.width = imgRight.naturalWidth;
      fullLayer.height = imgRight.naturalHeight;
      drawOverlayLayer(fullLayer.getContext('2d'), fullLayer.width, fullLayer.height, false);
      const finalCanvas = document.createElement('canvas');
      finalCanvas.width = lastCropCanvas.width;
      finalCanvas.height = lastCropCanvas.height;
      const fctx = finalCanvas.getContext('2d');
      fctx.drawImage(lastCropCanvas, 0, 0);
      fctx.drawImage(fullLayer, sx, sy, sw, sh, 0, 0, finalCanvas.width, finalCanvas.height);
      finalCanvas.toBlob(resolve, 'image/png');
    });
  }

  // Aggiorna anche l'anteprima del frammento quando si spunta/sspunta la
  // checkbox, così quello che si vede combacia sempre con quello che le
  // azioni sotto (copia/scarica/salva/condividi) useranno davvero.
  const cropIncludeOverlayEl = $('#an-crop-include-overlay-layer');
  if (cropIncludeOverlayEl) {
    cropIncludeOverlayEl.addEventListener('change', async () => {
      if (!lastCropCanvas) return;
      const blob = await buildCropBlob();
      if (!blob) return;
      if (lastCropBlobUrl) URL.revokeObjectURL(lastCropBlobUrl);
      lastCropBlobUrl = URL.createObjectURL(blob);
      lastCropBlob = blob;
      $('#an-crop-preview').src = lastCropBlobUrl;
    });
  }

  $('#an-crop-download-btn').addEventListener('click', async () => {
    const blob = await buildCropBlob();
    if (!blob) return;
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'frammento_ripresa' + CFG.captureId + '.png';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  });

  // Copia il frammento negli appunti, così su Google Lens (confermato
  // dall'uso reale) basta Ctrl+V nella pagina invece di cercare il file
  // appena scaricato — se per qualche motivo non funzionasse resta
  // comunque disponibile "Scarica frammento" come sempre. Richiede un
  // contesto sicuro (HTTPS o localhost): su HTTP semplice l'API non esiste
  // proprio.
  $('#an-crop-copy-btn').addEventListener('click', async () => {
    const status = $('#an-crop-copy-status');
    if (!lastCropBlob) return;
    if (!window.isSecureContext || !navigator.clipboard || !navigator.clipboard.write || typeof ClipboardItem === 'undefined') {
      status.textContent = 'Copia negli appunti non disponibile in questo browser/connessione (serve HTTPS): usa "Scarica frammento".';
      return;
    }
    try {
      const blob = await buildCropBlob();
      if (!blob) { status.textContent = 'Errore: impossibile generare l\'immagine.'; return; }
      await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
      status.textContent = 'Copiato. Ora vai su Google Lens e premi Ctrl+V.';
    } catch (err) {
      status.textContent = 'Copia non riuscita (' + err.message + '): usa "Scarica frammento".';
    }
  });

  // Apre solo la pagina di Google Lens: l'invio del file resta sempre un
  // gesto manuale ed esplicito dell'analista (incolla/trascina il
  // frammento), mai automatico — importante quando si maneggiano riprese
  // potenzialmente sensibili. Unico motore supportato per scelta
  // dell'analista dopo uso reale: il più efficace per il riconoscimento,
  // con incolla da appunti confermato funzionante (gli altri motori
  // provati in precedenza sono stati rimossi).
  $('#an-crop-open-lens').addEventListener('click', () => window.open('https://lens.google.com/', '_blank'));

  // Stesso identico percorso di Google Lens, seconda destinazione a scelta
  // dell'analista: apre una chat Claude vuota, il frammento va incollato lì
  // (stesso "Copia negli appunti" qui sopra, nessuna chiamata API/backend
  // dedicata) e accompagnato da una domanda scritta a mano. Nessun invio
  // automatico, nessuna chiave API coinvolta: è solo un'apertura di pagina,
  // esattamente come per Lens.
  $('#an-crop-open-claude').addEventListener('click', () => window.open('https://claude.ai/new', '_blank'));

  // Stesso identico percorso di Lens/Claude, ulteriori destinazioni a scelta
  // dell'analista: aprono solo la pagina, l'incolla resta un gesto manuale
  // (nessuna chiamata API/backend dedicata per nessuna di queste due).
  $('#an-crop-open-chatgpt').addEventListener('click', () => window.open('https://chatgpt.com/', '_blank'));
  $('#an-crop-open-deepseek').addEventListener('click', () => window.open('https://chat.deepseek.com/', '_blank'));

  // ---------- Salva e condividi il ritaglio ----------
  // Stessa infrastruttura già in uso per la ripresa intera: api/upload_capture.php
  // (identico a "Salva come nuova ripresa") e api/share.php (identico al
  // pannello "Condividi" più sotto), qui applicati al solo frammento
  // ritagliato invece che alla copia di lavoro intera. Il ritaglio NON deve
  // essere salvato prima di poter essere condiviso: sono due azioni indipendenti,
  // esattamente come per la ricerca inversa qui sopra.
  const cropSaveBtn = $('#an-crop-save-btn');
  const cropShareTelegramBtn = $('#an-crop-share-telegram-btn');
  const cropShareTwitterBtn = $('#an-crop-share-twitter-btn');

  cropSaveBtn.addEventListener('click', async () => {
    if (cropSaveBtn.disabled) return; // evita salvataggi duplicati su doppio click/tap
    if (!lastCropBlob) return;
    const status = $('#an-crop-save-status');
    cropSaveBtn.disabled = true;
    status.textContent = 'Salvataggio in corso...';
    try {
      const blob = await buildCropBlob();
      if (!blob) throw new Error('impossibile generare l\'immagine');
      const form = new FormData();
      form.append('study_id', CFG.studyId);
      form.append('image', blob, 'ritaglio_ripresa' + CFG.captureId + '.png');
      form.append('label', $('#an-crop-save-label').value || ('Ritaglio di ripresa #' + CFG.captureId));
      // Eredita la scala reale (metri/pixel) della ripresa sorgente: il
      // ritaglio è estratto 1:1 senza ridimensionamento (vedi
      // renderCropFromSelection), quindi la stessa densità di pixel resta
      // valida identica. Senza questo il ritaglio non aveva alcuna scala
      // propria e la vista di analisi ricadeva sulla bbox generica dello
      // studio — sbagliata di molto per un'area così più piccola (bug
      // segnalato e corretto: vedi Capture::resolveMpp lato server).
      form.append('source_capture_id', CFG.captureId);
      const res = await fetch('api/upload_capture.php', { method: 'POST', body: form });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Errore');
      status.innerHTML = 'Ritaglio salvato come nuova ripresa. <a href="study.php?id=' + CFG.studyId + '">Vai allo studio →</a>';
    } catch (err) {
      status.textContent = 'Errore: ' + err.message;
    } finally {
      cropSaveBtn.disabled = false;
    }
  });

  cropShareTelegramBtn.addEventListener('click', async () => {
    if (cropShareTelegramBtn.disabled) return; // evita invii duplicati su doppio click/tap
    if (!lastCropBlob) return;
    const status = $('#an-crop-share-status');
    cropShareTelegramBtn.disabled = true;
    status.textContent = 'Invio in corso...';
    try {
      const blob = await buildCropBlob();
      if (!blob) throw new Error('impossibile generare l\'immagine');
      const form = new FormData();
      form.append('platform', 'telegram');
      form.append('kind', 'capture');
      form.append('ref_id', CFG.captureId);
      form.append('study_id', CFG.studyId);
      form.append('caption', $('#an-crop-share-caption').value);
      form.append('image', blob, 'ritaglio_ripresa' + CFG.captureId + '.png');
      const res = await fetch('api/share.php', { method: 'POST', body: form });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Errore');
      status.textContent = 'Ritaglio inviato su Telegram.';
    } catch (err) {
      status.textContent = 'Errore: ' + err.message;
    } finally {
      cropShareTelegramBtn.disabled = false;
    }
  });

  cropShareTwitterBtn.addEventListener('click', () => {
    // Stesso schema del pannello "Condividi" qui sotto: l'intent di X non
    // supporta l'allegato di un'immagine via URL, solo testo — l'analista
    // incolla/trascina lui il ritaglio (pulsante "Copia negli appunti" qui
    // sopra, già usato per Lens/Claude). L'invio resta un gesto manuale.
    const text = encodeURIComponent($('#an-crop-share-caption').value);
    window.open('https://twitter.com/intent/tweet?text=' + text, '_blank');
    const form = new FormData();
    form.append('platform', 'twitter');
    form.append('kind', 'capture');
    form.append('ref_id', CFG.captureId);
    form.append('study_id', CFG.studyId);
    form.append('caption', $('#an-crop-share-caption').value);
    fetch('api/share.php', { method: 'POST', body: form }).catch(() => {});
  });

  // ---------- Condividi (Telegram / X) ----------
  // Condivide la copia di lavoro CON le regolazioni correnti applicate
  // (stesso renderAdjustedCanvas() di "Salva come nuova ripresa", mai
  // l'immagine "nuda"). Anteprima/didascalia sono sempre modificabili
  // prima dell'invio — mai una pubblicazione automatica, coerente con lo
  // stesso principio già seguito per la ricerca inversa per immagini.
  const shareCaptionEl = $('#an-share-caption');
  const shareStatusEl = $('#an-share-status');

  function shareCanvasBlob() {
    return new Promise((resolve) => renderAdjustedCanvas().toBlob(resolve, 'image/jpeg', 0.92));
  }

  const shareTelegramBtn = $('#an-share-telegram-btn');
  const shareCopyBtn = $('#an-share-copy-btn');

  shareTelegramBtn.addEventListener('click', async () => {
    if (shareTelegramBtn.disabled) return; // evita invii duplicati su doppio click/tap
    shareTelegramBtn.disabled = true;
    shareStatusEl.textContent = 'Preparazione immagine e invio in corso...';
    try {
      const blob = await shareCanvasBlob();
      if (!blob) { shareStatusEl.textContent = 'Errore: impossibile generare l\'immagine.'; return; }
      const form = new FormData();
      form.append('platform', 'telegram');
      form.append('kind', 'capture');
      form.append('ref_id', CFG.captureId);
      form.append('study_id', CFG.studyId);
      form.append('caption', shareCaptionEl.value);
      form.append('image', blob, 'ripresa_' + CFG.captureId + '.jpg');
      const res = await fetch('api/share.php', { method: 'POST', body: form });
      const data = await res.json();
      if (!res.ok) throw new Error(data.error || 'Errore');
      shareStatusEl.textContent = 'Inviato su Telegram.';
    } catch (err) {
      shareStatusEl.textContent = 'Errore: ' + err.message;
    } finally {
      shareTelegramBtn.disabled = false;
    }
  });

  shareCopyBtn.addEventListener('click', async () => {
    if (!window.isSecureContext || !navigator.clipboard || !navigator.clipboard.write || typeof ClipboardItem === 'undefined') {
      shareStatusEl.textContent = 'Copia negli appunti non disponibile in questo browser/connessione (serve HTTPS).';
      return;
    }
    if (shareCopyBtn.disabled) return;
    shareCopyBtn.disabled = true;
    try {
      const blob = await shareCanvasBlob();
      if (!blob) { shareStatusEl.textContent = 'Errore: impossibile generare l\'immagine.'; return; }
      await navigator.clipboard.write([new ClipboardItem({ 'image/jpeg': blob })]);
      shareStatusEl.textContent = 'Copiato. Ora vai su X e incolla con Ctrl+V.';
    } catch (err) {
      shareStatusEl.textContent = 'Copia non riuscita: ' + err.message;
    } finally {
      shareCopyBtn.disabled = false;
    }
  });

  $('#an-share-twitter-btn').addEventListener('click', () => {
    // L'intent di composizione X non supporta l'allegato di un'immagine via
    // URL, solo testo: l'analista incolla/trascina lui l'immagine (vedi
    // pulsante "Copia negli appunti" qui sopra) — l'invio a un servizio
    // esterno resta un gesto manuale ed esplicito, mai automatico.
    const text = encodeURIComponent(shareCaptionEl.value);
    window.open('https://twitter.com/intent/tweet?text=' + text, '_blank');
    const form = new FormData();
    form.append('platform', 'twitter');
    form.append('kind', 'capture');
    form.append('ref_id', CFG.captureId);
    form.append('study_id', CFG.studyId);
    form.append('caption', shareCaptionEl.value);
    fetch('api/share.php', { method: 'POST', body: form }).catch(() => {}); // solo registro, un fallimento qui non blocca l'analista
  });

  // ---------- Sovrapposizione di un'immagine propria ----------
  // Resta interamente lato browser (nessun upload al servizio, nessuna
  // persistenza) finché non si preme "Salva come nuova ripresa": a quel
  // punto viene incorporata definitivamente nel file salvato (vedi
  // drawOverlayOnto, chiamata da renderAdjustedCanvas). Geometria salvata
  // come frazioni di canvas.width/height (stessa convenzione delle
  // annotazioni), così resta valida a qualunque livello di zoom senza
  // bisogno di ricalcoli. (overlayImgEl/overlay dichiarati più in alto, vedi
  // commento vicino a "let annotations" — resizeAnnotateCanvas() li
  // referenzia e può scattare in modo sincrono già durante il caricamento.)

  function renderOverlay() {
    if (!overlay.loaded) { overlayImgEl.style.display = 'none'; return; }
    const w = overlay.baseWFrac * overlay.scale * canvas.width;
    const h = overlay.baseHFrac * overlay.scale * canvas.height;
    const left = overlay.cx * canvas.width - w / 2;
    const top = overlay.cy * canvas.height - h / 2;
    overlayImgEl.style.display = '';
    overlayImgEl.style.width = w + 'px';
    overlayImgEl.style.height = h + 'px';
    overlayImgEl.style.left = left + 'px';
    overlayImgEl.style.top = top + 'px';
    overlayImgEl.style.transform = `rotate(${overlay.rotation}deg) skew(${overlay.skewX}deg, ${overlay.skewY}deg)`;
    overlayImgEl.style.opacity = overlay.opacity;
    if (mode === 'overlay') {
      // Le maniglie si disegnano sul canvas condiviso con annotazioni/
      // misurazioni: senza ripulirlo prima, ogni chiamata a renderOverlay()
      // (una per ogni mossa di trascinamento o scatto di slider) le
      // accumulava sopra le precedenti — il "ghosting" segnalato.
      // redrawAnnotations() fa il clear + ridisegna il resto del livello,
      // poi le maniglie vanno sopra, pulite.
      redrawAnnotations();
      drawOverlayHandles();
    }
  }

  const OVERLAY_ROTATE_HANDLE_OFFSET = 28; // px canvas, oltre l'angolo in alto
  const OVERLAY_OPACITY_HANDLE_OFFSET = 28; // px canvas, oltre il lato in basso

  // Riporta un punto canvas nel sistema "pre-rotazione" relativo al centro
  // dell'overlay (inverte solo la rotazione, non lo skew): usato dalle
  // maniglie di skew/opacità per ricavare quanto ha "shearato"/scorso il
  // trascinamento lungo gli assi propri dell'immagine.
  function canvasToOverlayUnrotated(px, py) {
    const dx = px - overlay.cx * canvas.width;
    const dy = py - overlay.cy * canvas.height;
    const r = (-overlay.rotation * Math.PI) / 180;
    return [dx * Math.cos(r) - dy * Math.sin(r), dx * Math.sin(r) + dy * Math.cos(r)];
  }

  // Trasforma un punto "locale" (relativo al centro dell'overlay, in pixel
  // canvas, PRIMA di scala/skew/rotazione) nelle coordinate canvas correnti
  // — stessa composizione skew-poi-rotazione di renderOverlay()/
  // drawOverlayOnto(), così le maniglie disegnate seguono esattamente la
  // forma visibile qualunque sia la trasformazione corrente.
  function overlayLocalToCanvas(lx, ly) {
    const skewXRad = (overlay.skewX * Math.PI) / 180;
    const skewYRad = (overlay.skewY * Math.PI) / 180;
    const skewedX = lx + Math.tan(skewXRad) * ly;
    const skewedY = Math.tan(skewYRad) * lx + ly;
    const rotRad = (overlay.rotation * Math.PI) / 180;
    const rotX = skewedX * Math.cos(rotRad) - skewedY * Math.sin(rotRad);
    const rotY = skewedX * Math.sin(rotRad) + skewedY * Math.cos(rotRad);
    return [overlay.cx * canvas.width + rotX, overlay.cy * canvas.height + rotY];
  }

  function overlayHalfExtents() {
    return [
      (overlay.baseWFrac * overlay.scale * canvas.width) / 2,
      (overlay.baseHFrac * overlay.scale * canvas.height) / 2,
    ];
  }

  // Angoli (per il ridimensionamento) + maniglia di rotazione, in coordinate
  // canvas correnti — usata sia per disegnarle sia per il loro hit-test.
  function overlayHandlePoints() {
    const [hw, hh] = overlayHalfExtents();
    const corners = {
      nw: overlayLocalToCanvas(-hw, -hh),
      ne: overlayLocalToCanvas(hw, -hh),
      sw: overlayLocalToCanvas(-hw, hh),
      se: overlayLocalToCanvas(hw, hh),
    };
    // Metà dei lati: trascinandole si inclina (skew) l'immagine lungo il
    // proprio asse — lati alto/basso = inclinazione orizzontale (skewX),
    // lati sinistro/destro = inclinazione verticale (skewY).
    const edges = {
      n: overlayLocalToCanvas(0, -hh),
      s: overlayLocalToCanvas(0, hh),
      w: overlayLocalToCanvas(-hw, 0),
      e: overlayLocalToCanvas(hw, 0),
    };
    const rotate = overlayLocalToCanvas(0, -hh - OVERLAY_ROTATE_HANDLE_OFFSET);
    // Maniglia opacità: piccola guida sotto il lato inferiore, la posizione
    // lungo la guida (da sinistra a destra) È il valore di opacità 0→1.
    const opacityTrackL = overlayLocalToCanvas(-hw, hh + OVERLAY_OPACITY_HANDLE_OFFSET);
    const opacityTrackR = overlayLocalToCanvas(hw, hh + OVERLAY_OPACITY_HANDLE_OFFSET);
    const opacity = overlayLocalToCanvas(-hw + overlay.opacity * 2 * hw, hh + OVERLAY_OPACITY_HANDLE_OFFSET);
    return { corners, edges, rotate, opacityTrackL, opacityTrackR, opacity };
  }

  function drawOverlayHandles() {
    const ctx = canvas.getContext('2d');
    const { corners, edges, rotate, opacityTrackL, opacityTrackR, opacity } = overlayHandlePoints();
    const topMid = overlayLocalToCanvas(0, -overlayHalfExtents()[1]);

    ctx.save();
    ctx.strokeStyle = '#00fff2';
    ctx.setLineDash([3, 3]);
    ctx.lineWidth = 1;
    // Contorno della selezione (i 4 lati), per far capire subito cosa si sta
    // manipolando quando si passa a modalità Sovrapponi.
    ctx.beginPath();
    ctx.moveTo(...corners.nw); ctx.lineTo(...corners.ne); ctx.lineTo(...corners.se); ctx.lineTo(...corners.sw); ctx.closePath();
    ctx.stroke();
    ctx.setLineDash([]);
    // Linea guida dal centro-alto alla maniglia di rotazione.
    ctx.beginPath();
    ctx.moveTo(...topMid); ctx.lineTo(...rotate);
    ctx.stroke();
    // Guida dell'opacità sotto il lato inferiore.
    ctx.beginPath();
    ctx.moveTo(...opacityTrackL); ctx.lineTo(...opacityTrackR);
    ctx.stroke();

    // Angoli: cerchi pieni (ridimensiona).
    ctx.fillStyle = '#00fff2';
    Object.values(corners).forEach(([hx, hy]) => {
      ctx.beginPath(); ctx.arc(hx, hy, HANDLE_R, 0, Math.PI * 2); ctx.fill();
    });
    // Metà lati: quadratini (inclina/skew), per distinguerli dagli angoli.
    Object.values(edges).forEach(([hx, hy]) => {
      ctx.fillRect(hx - HANDLE_R, hy - HANDLE_R, HANDLE_R * 2, HANDLE_R * 2);
    });
    // Rotazione: cerchio vuoto sopra.
    ctx.beginPath();
    ctx.arc(rotate[0], rotate[1], HANDLE_R, 0, Math.PI * 2);
    ctx.fillStyle = '#0a0a0a';
    ctx.fill();
    ctx.lineWidth = 2;
    ctx.stroke();
    // Opacità: cerchio pieno che scorre lungo la guida in basso.
    ctx.beginPath();
    ctx.arc(opacity[0], opacity[1], HANDLE_R, 0, Math.PI * 2);
    ctx.fillStyle = '#00fff2';
    ctx.fill();
    ctx.strokeStyle = '#0a0a0a';
    ctx.lineWidth = 1;
    ctx.stroke();
    ctx.restore();
  }

  function hitOverlayHandle(x, y) {
    if (!overlay.loaded || mode !== 'overlay') return null;
    const { corners, edges, rotate, opacity } = overlayHandlePoints();
    if (Math.hypot(x - rotate[0], y - rotate[1]) <= handleHitR()) return { type: 'rotate' };
    if (Math.hypot(x - opacity[0], y - opacity[1]) <= handleHitR()) return { type: 'opacity' };
    for (const [name, [hx, hy]] of Object.entries(corners)) {
      if (Math.hypot(x - hx, y - hy) <= handleHitR()) return { type: 'resize-corner', corner: name };
    }
    for (const [name, [hx, hy]] of Object.entries(edges)) {
      if (Math.hypot(x - hx, y - hy) <= handleHitR()) return { type: 'skew-edge', edge: name };
    }
    return null;
  }

  $('#an-overlay-file').addEventListener('change', (e) => {
    const file = e.target.files && e.target.files[0];
    if (!file) return;
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
      overlay.img = img;
      overlay.loaded = true;
      // Dimensione di partenza comoda: il lato maggiore occupa ~40% del
      // riquadro, rapporto d'aspetto reale dell'immagine caricata preservato.
      // baseWFrac/baseHFrac sono frazioni di canvas.width/canvas.height
      // RISPETTIVAMENTE (non dello stesso lato): su un canvas non quadrato
      // (es. un ritaglio rettangolare, ancora più comune da quando esiste
      // la rotazione dell'area — vedi ImageRotateCrop.php) moltiplicarle
      // ciascuna per la propria dimensione del canvas altera il rapporto
      // d'aspetto voluto di un fattore canvas.width/canvas.height se non lo
      // si compensa qui: da cui la sovrapposizione risultava distorta su
      // ogni ripresa non quadrata, non solo su quelle ruotate.
      const aspect = img.naturalWidth / img.naturalHeight;
      const canvasAspect = canvas.width / canvas.height;
      const relativeAspect = aspect / canvasAspect;
      if (relativeAspect >= 1) { overlay.baseWFrac = 0.4; overlay.baseHFrac = 0.4 / relativeAspect; }
      else { overlay.baseHFrac = 0.4; overlay.baseWFrac = 0.4 * relativeAspect; }
      overlay.cx = 0.5; overlay.cy = 0.5;
      refreshOverlayDisplay();
      renderOverlay();
      // Passa subito a modalità Sovrapponi: appena caricata un'immagine si
      // vuole quasi sempre posizionarla, e le maniglie di trascinamento/
      // ridimensionamento/rotazione compaiono solo in questa modalità.
      // Riusa lo stesso click del pulsante, così stato e classe "active"
      // restano coerenti.
      const overlayModeBtn = $('#an-mode-toggle .mode-btn[data-mode="overlay"]');
      if (overlayModeBtn && mode !== 'overlay') overlayModeBtn.click();
      $('#an-overlay-status').textContent = 'Immagine caricata (modalità Sovrapponi attiva): trascina il corpo per spostarla, gli angoli per ridimensionarla, il cerchietto in alto per ruotarla.';
    };
    img.src = url;
  });

  // toStored: converte il valore grezzo dello slider (quello mostrato in
  // etichetta) nell'unità usata internamente da overlay.* (scale/opacity
  // sono frazioni 0-x, rotazione/inclinazione restano in gradi as-is).
  const OVERLAY_SLIDER_MAP = [
    ['an-overlay-scale', 'an-val-overlay-scale', 'scale', (raw) => raw / 100, (raw) => Math.round(raw) + '%'],
    ['an-overlay-rotation', 'an-val-overlay-rotation', 'rotation', (raw) => raw, (raw) => Math.round(raw) + '°'],
    ['an-overlay-skewx', 'an-val-overlay-skewx', 'skewX', (raw) => raw, (raw) => Math.round(raw) + '°'],
    ['an-overlay-skewy', 'an-val-overlay-skewy', 'skewY', (raw) => raw, (raw) => Math.round(raw) + '°'],
    ['an-overlay-opacity', 'an-val-overlay-opacity', 'opacity', (raw) => raw / 100, (raw) => Math.round(raw) + '%'],
  ];
  OVERLAY_SLIDER_MAP.forEach(([inputId, outId, key, toStored, fmt]) => {
    const input = $('#' + inputId);
    const out = $('#' + outId);
    input.addEventListener('input', () => {
      const raw = parseFloat(input.value);
      overlay[key] = toStored(raw);
      out.textContent = fmt(raw);
      renderOverlay();
    });
  });

  // Aggiorna slider + etichetta a partire da overlay[key] già impostato
  // direttamente da un trascinamento maniglia (scala/rotazione/skew/
  // opacità), così maniglie e slider restano sempre coerenti tra loro. La
  // conversione stored->raw è l'inversa di toStored: identità per rotazione
  // e skew (gradi), ×100 per scala e opacità (frazione -> percentuale).
  function syncOverlaySliderFromStored(key) {
    const entry = OVERLAY_SLIDER_MAP.find((e) => e[2] === key);
    if (!entry) return;
    const [inputId, outId, , , fmt] = entry;
    const raw = (key === 'scale' || key === 'opacity') ? overlay[key] * 100 : overlay[key];
    $('#' + inputId).value = raw;
    $('#' + outId).textContent = fmt(raw);
  }

  // ---------- Chroma key (trasparenza per colore) ----------
  const chromaKeyEnabledEl = $('#an-overlay-chromakey-enabled');
  const chromaKeyFieldsEl = $('#an-overlay-chromakey-fields');
  const chromaKeyColorEl = $('#an-overlay-chromakey-color');
  const chromaKeyToleranceEl = $('#an-overlay-chromakey-tolerance');
  const chromaKeyToleranceValEl = $('#an-val-overlay-chromakey-tolerance');

  chromaKeyEnabledEl.addEventListener('change', () => {
    overlay.chromaKeyEnabled = chromaKeyEnabledEl.checked;
    chromaKeyFieldsEl.style.display = overlay.chromaKeyEnabled ? '' : 'none';
    refreshOverlayDisplay();
  });
  chromaKeyColorEl.addEventListener('input', () => {
    overlay.chromaKeyColor = chromaKeyColorEl.value;
    if (overlay.chromaKeyEnabled) refreshOverlayDisplay();
  });
  chromaKeyToleranceEl.addEventListener('input', () => {
    overlay.chromaKeyTolerance = parseInt(chromaKeyToleranceEl.value, 10);
    chromaKeyToleranceValEl.textContent = chromaKeyToleranceEl.value;
    if (overlay.chromaKeyEnabled) refreshOverlayDisplay();
  });

  $('#an-overlay-reset-btn').addEventListener('click', () => {
    overlay.cx = 0.5; overlay.cy = 0.5; overlay.scale = 1.0; overlay.rotation = 0; overlay.skewX = 0; overlay.skewY = 0; overlay.opacity = 0.7;
    $('#an-overlay-scale').value = 100; $('#an-val-overlay-scale').textContent = '100%';
    $('#an-overlay-rotation').value = 0; $('#an-val-overlay-rotation').textContent = '0°';
    $('#an-overlay-skewx').value = 0; $('#an-val-overlay-skewx').textContent = '0°';
    $('#an-overlay-skewy').value = 0; $('#an-val-overlay-skewy').textContent = '0°';
    $('#an-overlay-opacity').value = 70; $('#an-val-overlay-opacity').textContent = '70%';
    renderOverlay();
    $('#an-overlay-status').textContent = 'Trasformazioni azzerate (posizione, scala, rotazione, inclinazione, opacità).';
  });

  $('#an-overlay-remove-btn').addEventListener('click', () => {
    if (!overlay.loaded) return;
    const prev = { ...overlay, img: overlay.img };
    overlay.loaded = false;
    renderOverlay();
    $('#an-overlay-status').textContent = 'Sovrapposizione rimossa.';
    pushUndo(() => {
      Object.assign(overlay, prev);
      overlay.loaded = true;
      renderOverlay();
      $('#an-overlay-status').textContent = 'Rimozione sovrapposizione annullata.';
    });
  });

  // Disegna l'immagine sovrapposta (posizione/scala/rotazione/inclinazione/
  // opacità correnti) su un canvas di destinazione qualunque risoluzione:
  // le coordinate sono frazioni, quindi si adattano automaticamente sia
  // all'anteprima a schermo sia al canvas a piena risoluzione del salvataggio.
  function drawOverlayOnto(ctx, targetW, targetH) {
    if (!overlay.loaded) return;
    const w = overlay.baseWFrac * overlay.scale * targetW;
    const h = overlay.baseHFrac * overlay.scale * targetH;
    const cx = overlay.cx * targetW, cy = overlay.cy * targetH;
    ctx.save();
    ctx.globalAlpha = overlay.opacity;
    ctx.translate(cx, cy);
    ctx.rotate((overlay.rotation * Math.PI) / 180);
    // Stessa matrice della funzione CSS skew(skewX, skewY): shear lungo X in
    // base a Y (tan(skewY)) e lungo Y in base a X (tan(skewX)).
    ctx.transform(1, Math.tan((overlay.skewY * Math.PI) / 180), Math.tan((overlay.skewX * Math.PI) / 180), 1, 0, 0);
    ctx.drawImage(overlay.displaySource || overlay.img, -w / 2, -h / 2, w, h);
    ctx.restore();
  }

  // ---------- Hit-test delle maniglie (angoli annotazioni, estremi misure) ----------
  // Funzione (non costante) perché HANDLE_R ora cambia dinamicamente con lo
  // slider "Dimensione maniglie": l'area cliccabile deve restare sempre un
  // po' più larga del solo disegno, comoda al dito/mouse a qualunque taglia.
  function handleHitR() { return Math.max(8, HANDLE_R * 3); }
  const OPPOSITE_CORNER = { tl: 'br', tr: 'bl', bl: 'tr', br: 'tl' };

  function cornerPoint(rx, ry, rw, rh, name) {
    if (name === 'tl') return [rx, ry];
    if (name === 'tr') return [rx + rw, ry];
    if (name === 'bl') return [rx, ry + rh];
    return [rx + rw, ry + rh];
  }

  const isPoly = (a) => a.shape_type === 'polyline' || a.shape_type === 'polygon';

  function hitAnnotationHandle(x, y) {
    for (const a of annotations) {
      if (isPoly(a)) continue;
      const c = a.coords;
      const rx = c.x * canvas.width, ry = c.y * canvas.height, rw = c.w * canvas.width, rh = c.h * canvas.height;
      for (const name of ['tl', 'tr', 'bl', 'br']) {
        const [hx, hy] = cornerPoint(rx, ry, rw, rh, name);
        if (Math.hypot(x - hx, y - hy) <= handleHitR()) {
          const [fx, fy] = cornerPoint(rx, ry, rw, rh, OPPOSITE_CORNER[name]);
          return { ann: a, fixedX: fx, fixedY: fy };
        }
      }
    }
    return null;
  }

  function hitAnnotationBody(x, y) {
    for (const a of annotations) {
      if (isPoly(a)) continue;
      const c = a.coords;
      const rx = c.x * canvas.width, ry = c.y * canvas.height, rw = c.w * canvas.width, rh = c.h * canvas.height;
      if (x >= rx && x <= rx + rw && y >= ry && y <= ry + rh) return a;
    }
    return null;
  }

  // Vertice di una polilinea/poligono esistente sotto il cursore (in
  // modalità Annota), per poterlo trascinare.
  function hitPolyVertex(x, y) {
    for (const a of annotations) {
      if (!isPoly(a) || !a.coords || !Array.isArray(a.coords.points)) continue;
      for (let i = 0; i < a.coords.points.length; i++) {
        const [fx, fy] = a.coords.points[i];
        if (Math.hypot(x - fx * canvas.width, y - fy * canvas.height) <= handleHitR()) return { ann: a, idx: i };
      }
    }
    return null;
  }

  function hitMeasureHandle(x, y) {
    for (const m of measurements) {
      if (Math.hypot(x - m.x1, y - m.y1) <= handleHitR()) return { m, end: 1 };
      if (Math.hypot(x - m.x2, y - m.y2) <= handleHitR()) return { m, end: 2 };
    }
    return null;
  }

  (function setupDrawing() {
    let startX = 0, startY = 0;
    let dragState = null; // vedi handleStart per le forme possibili

    function previewRect(x, y) {
      redrawAnnotations();
      const ctx = canvas.getContext('2d');
      ctx.strokeStyle = '#ffb020';
      ctx.lineWidth = 2;
      ctx.setLineDash([4, 3]);
      ctx.strokeRect(Math.min(startX, x), Math.min(startY, y), Math.abs(x - startX), Math.abs(y - startY));
      ctx.setLineDash([]);
    }

    function previewLine(x1, y1, x2, y2) {
      redrawAnnotations();
      const ctx = canvas.getContext('2d');
      ctx.strokeStyle = currentMeasureColor;
      ctx.lineWidth = 2;
      ctx.setLineDash([4, 3]);
      ctx.beginPath();
      ctx.moveTo(x1, y1);
      ctx.lineTo(x2, y2);
      ctx.stroke();
      ctx.setLineDash([]);
      const { distanceM } = pixelDistance(x1, y1, x2, y2);
      if (distanceM !== null) {
        ctx.font = '12px "Share Tech Mono", monospace';
        ctx.fillStyle = currentMeasureColor;
        ctx.fillText(formatDistance(distanceM), (x1 + x2) / 2 + 6, (y1 + y2) / 2 - 6);
      }
    }

    async function finishAnnotate(x, y) {
      const w = Math.abs(x - startX), h = Math.abs(y - startY);
      const rx = Math.min(startX, x), ry = Math.min(startY, y);
      if (w < 6 || h < 6) { redrawAnnotations(); return; }

      const label = prompt('Etichetta annotazione (es. "Nuova struttura"):', '');
      if (label === null) { redrawAnnotations(); return; }
      const notes = prompt('Note (opzionale):', '') || '';

      const coords = { x: rx / canvas.width, y: ry / canvas.height, w: w / canvas.width, h: h / canvas.height };
      const id = await createAnnotationServer(coords, currentAnnotateColor, label, notes);
      const a = { id, coords, color: currentAnnotateColor, label, notes };
      annotations.push(a);
      redrawAnnotations();
      renderAnnotationList();
      pushUndo(async () => {
        await deleteAnnotationServer(a.id);
        const idx = annotations.indexOf(a);
        if (idx !== -1) annotations.splice(idx, 1);
        redrawAnnotations();
        renderAnnotationList();
      });
    }

    function finishMeasure(x, y) {
      const pixelLen = Math.hypot(x - startX, y - startY);
      if (pixelLen < 6) { redrawAnnotations(); return; }

      if (!mppX || !mppY) {
        const known = prompt('Nessuna scala geografica nota per questa ripresa. Indica la lunghezza reale (in metri) di questa linea per calibrare la scala:', '');
        const knownM = parseFloat(known);
        if (!known || isNaN(knownM) || knownM <= 0) { redrawAnnotations(); return; }
        const { dxPx, dyPx } = pixelDistance(startX, startY, x, y);
        const pixelLenNat = Math.hypot(dxPx, dyPx);
        mppX = mppY = knownM / pixelLenNat;
        scaleSource = 'manual';
        updateScaleStatus();
      }

      const { distanceM } = pixelDistance(startX, startY, x, y);
      let label = '';
      // Modalità ombra→altezza: la linea appena tracciata è la lunghezza
      // dell'ombra; altezza ≈ ombra × tan(elevazione solare).
      if (shadowMeasureArmed) {
        shadowMeasureArmed = false;
        const el = currentShadowElevation();
        if (el !== null && el >= 3 && distanceM) {
          const heightM = distanceM * Math.tan(el * Math.PI / 180);
          label = `altezza ≈ ${heightM.toFixed(1)} m (ombra ${formatDistance(distanceM)}, sole ${el.toFixed(1)}°)`;
          if (shadowStatusEl) shadowStatusEl.textContent = `Altezza stimata ≈ ${heightM.toFixed(1)} m.`;
        }
      }
      const m = { x1: startX, y1: startY, x2: x, y2: y, distanceM, color: currentMeasureColor, label };
      measurements.push(m);
      redrawAnnotations();
      renderMeasurementList();
      // Persistite come le annotazioni (riga annotations shape_type
      // 'measure'): sopravvivono al ricaricamento della pagina.
      createAnnotationServer(measureCoordsFrac(m), m.color, m.label || null, null, 'measure').then((id) => { m.id = id; });
      pushUndo(async () => {
        const idx = measurements.indexOf(m);
        if (idx !== -1) measurements.splice(idx, 1);
        if (m.id) await deleteAnnotationServer(m.id);
        redrawAnnotations();
        renderMeasurementList();
      });
    }

    function finishCrop(x, y) {
      const w = Math.abs(x - startX), h = Math.abs(y - startY);
      const rx = Math.min(startX, x), ry = Math.min(startY, y);
      redrawAnnotations();
      if (w < 10 || h < 10) return;
      renderCropFromSelection(rx, ry, w, h);
    }

    function handleStart(x, y) {
      if (mode === 'annotate') {
        // Trascinamento di un vertice di una polilinea/poligono già esistente.
        const vHit = hitPolyVertex(x, y);
        if (vHit) {
          dragState = { type: 'drag-poly-vertex', ann: vHit.ann, idx: vHit.idx, prevCoords: { points: vHit.ann.coords.points.map((p) => p.slice()) } };
          return;
        }
        // Disegno polilinea/poligono: ogni clic aggiunge un vertice.
        if (annotateShape === 'polyline' || annotateShape === 'polygon') {
          if (!polyDraft) polyDraft = { shape: annotateShape, points: [] };
          polyDraft.points.push([x, y]);
          const btn = $('#an-annotate-finish-shape');
          if (btn) btn.style.display = '';
          redrawAnnotations();
          dragState = null;
          return;
        }
        const handleHit = hitAnnotationHandle(x, y);
        if (handleHit) {
          dragState = { type: 'resize', ann: handleHit.ann, fixedX: handleHit.fixedX, fixedY: handleHit.fixedY, prevCoords: { ...handleHit.ann.coords } };
          return;
        }
        const bodyHit = hitAnnotationBody(x, y);
        if (bodyHit) {
          const c = bodyHit.coords;
          dragState = {
            type: 'move', ann: bodyHit, prevCoords: { ...c },
            grabDX: x - c.x * canvas.width, grabDY: y - c.y * canvas.height,
          };
          return;
        }
        dragState = { type: 'draw-annotate' };
        startX = x; startY = y;
      } else if (mode === 'measure') {
        const handleHit = hitMeasureHandle(x, y);
        if (handleHit) {
          dragState = { type: 'drag-endpoint', m: handleHit.m, end: handleHit.end, prev: { x1: handleHit.m.x1, y1: handleHit.m.y1, x2: handleHit.m.x2, y2: handleHit.m.y2, distanceM: handleHit.m.distanceM } };
          return;
        }
        dragState = { type: 'draw-measure' };
        startX = x; startY = y;
      } else if (mode === 'crop') {
        dragState = { type: 'draw-crop' };
        startX = x; startY = y;
      } else if (mode === 'overlay') {
        if (!overlay.loaded) { dragState = null; return; }
        const overlayHandleHit = hitOverlayHandle(x, y);
        if (overlayHandleHit && overlayHandleHit.type === 'rotate') {
          dragState = { type: 'rotate-overlay' };
          return;
        }
        if (overlayHandleHit && overlayHandleHit.type === 'resize-corner') {
          const hw0 = (overlay.baseWFrac * canvas.width) / 2;
          const hh0 = (overlay.baseHFrac * canvas.height) / 2;
          dragState = { type: 'resize-overlay', baseDiag: Math.hypot(hw0, hh0) };
          return;
        }
        if (overlayHandleHit && overlayHandleHit.type === 'skew-edge') {
          dragState = { type: 'skew-overlay', edge: overlayHandleHit.edge };
          return;
        }
        if (overlayHandleHit && overlayHandleHit.type === 'opacity') {
          dragState = { type: 'opacity-overlay' };
          return;
        }
        dragState = {
          type: 'move-overlay',
          grabDX: x - overlay.cx * canvas.width,
          grabDY: y - overlay.cy * canvas.height,
        };
      } else {
        dragState = null;
      }
    }

    function handleMove(x, y) {
      if (!dragState) return;
      if (dragState.type === 'draw-annotate' || dragState.type === 'draw-crop') {
        previewRect(x, y);
      } else if (dragState.type === 'draw-measure') {
        previewLine(startX, startY, x, y);
      } else if (dragState.type === 'resize') {
        const rx = Math.min(dragState.fixedX, x), ry = Math.min(dragState.fixedY, y);
        const rw = Math.abs(x - dragState.fixedX), rh = Math.abs(y - dragState.fixedY);
        dragState.ann.coords = { x: rx / canvas.width, y: ry / canvas.height, w: rw / canvas.width, h: rh / canvas.height };
        redrawAnnotations();
      } else if (dragState.type === 'move') {
        const c = dragState.prevCoords;
        const newRx = x - dragState.grabDX, newRy = y - dragState.grabDY;
        dragState.ann.coords = { x: newRx / canvas.width, y: newRy / canvas.height, w: c.w, h: c.h };
        redrawAnnotations();
      } else if (dragState.type === 'drag-poly-vertex') {
        dragState.ann.coords.points[dragState.idx] = [x / canvas.width, y / canvas.height];
        redrawAnnotations();
      } else if (dragState.type === 'drag-endpoint') {
        const m = dragState.m;
        if (dragState.end === 1) { m.x1 = x; m.y1 = y; } else { m.x2 = x; m.y2 = y; }
        const { distanceM } = pixelDistance(m.x1, m.y1, m.x2, m.y2);
        m.distanceM = distanceM;
        redrawAnnotations();
      } else if (dragState.type === 'move-overlay') {
        overlay.cx = (x - dragState.grabDX) / canvas.width;
        overlay.cy = (y - dragState.grabDY) / canvas.height;
        renderOverlay();
      } else if (dragState.type === 'resize-overlay') {
        const cx = overlay.cx * canvas.width, cy = overlay.cy * canvas.height;
        const rotRad = (overlay.rotation * Math.PI) / 180;
        const dx = x - cx, dy = y - cy;
        // Riporta il punto nel sistema locale non ruotato (inverte la
        // rotazione corrente); lo skew viene ignorato qui come
        // approssimazione accettata, per mantenere il ridimensionamento
        // un'operazione uniforme e prevedibile dal centro.
        const lx = dx * Math.cos(-rotRad) - dy * Math.sin(-rotRad);
        const ly = dx * Math.sin(-rotRad) + dy * Math.cos(-rotRad);
        const newScale = Math.hypot(lx, ly) / dragState.baseDiag;
        overlay.scale = Math.max(0.1, Math.min(3, newScale));
        syncOverlaySliderFromStored('scale');
        renderOverlay();
      } else if (dragState.type === 'rotate-overlay') {
        const cx = overlay.cx * canvas.width, cy = overlay.cy * canvas.height;
        let deg = (Math.atan2(y - cy, x - cx) * 180) / Math.PI + 90;
        if (deg > 180) deg -= 360;
        if (deg < -180) deg += 360;
        overlay.rotation = deg;
        syncOverlaySliderFromStored('rotation');
        renderOverlay();
      } else if (dragState.type === 'skew-overlay') {
        // Punto trascinato nel sistema pre-rotazione, relativo al centro.
        // CSS skew(ax,ay): x += y*tan(ax), y += x*tan(ay). Per il lato alto
        // (y locale = -hh) lo scostamento orizzontale del punto vale
        // -hh*tan(skewX); per il lato basso +hh*tan(skewX); simmetrico per
        // i lati sinistro/destro con skewY. Angolo limitato a ±45° come gli
        // slider.
        const [lx, ly] = canvasToOverlayUnrotated(x, y);
        const [hw, hh] = overlayHalfExtents();
        const clampDeg = (d) => Math.max(-45, Math.min(45, d));
        if (dragState.edge === 'n' || dragState.edge === 's') {
          const s = dragState.edge === 'n' ? -1 : 1;
          overlay.skewX = clampDeg((Math.atan2(s * lx, hh) * 180) / Math.PI);
          syncOverlaySliderFromStored('skewX');
        } else {
          const s = dragState.edge === 'w' ? -1 : 1;
          overlay.skewY = clampDeg((Math.atan2(s * ly, hw) * 180) / Math.PI);
          syncOverlaySliderFromStored('skewY');
        }
        renderOverlay();
      } else if (dragState.type === 'opacity-overlay') {
        // Posizione lungo il lato inferiore (da sinistra = 0 a destra = 1),
        // nel sistema pre-rotazione.
        const [lx] = canvasToOverlayUnrotated(x, y);
        const [hw] = overlayHalfExtents();
        overlay.opacity = Math.max(0, Math.min(1, (lx + hw) / (2 * hw)));
        syncOverlaySliderFromStored('opacity');
        renderOverlay();
      }
    }

    async function handleEnd(x, y) {
      if (!dragState) return;
      const state = dragState;
      dragState = null;

      if (state.type === 'draw-annotate') { await finishAnnotate(x, y); return; }
      if (state.type === 'draw-measure') { finishMeasure(x, y); return; }
      if (state.type === 'draw-crop') { finishCrop(x, y); return; }

      if (state.type === 'resize' || state.type === 'move' || state.type === 'drag-poly-vertex') {
        const a = state.ann;
        const newCoords = state.type === 'drag-poly-vertex'
          ? { points: a.coords.points.map((p) => p.slice()) }
          : { ...a.coords };
        const prevCoords = state.prevCoords;
        await updateAnnotationServer(a.id, newCoords, a.label, a.notes);
        redrawAnnotations();
        renderAnnotationList();
        pushUndo(async () => {
          a.coords = prevCoords;
          await updateAnnotationServer(a.id, prevCoords, a.label, a.notes);
          redrawAnnotations();
          renderAnnotationList();
        });
      } else if (state.type === 'drag-endpoint') {
        const m = state.m;
        renderMeasurementList();
        if (m.id) await updateAnnotationServer(m.id, measureCoordsFrac(m), m.label || null, null);
        pushUndo(async () => {
          Object.assign(m, state.prev);
          if (m.id) await updateAnnotationServer(m.id, measureCoordsFrac(m), m.label || null, null);
          redrawAnnotations();
          renderMeasurementList();
        });
      } else {
        redrawAnnotations();
        // redrawAnnotations() pulisce l'intero canvas overlay (stesso
        // canvas usato dalle maniglie di overlay); in modalità Sovrapponi
        // vanno quindi rimesse a schermo, altrimenti spariscono a ogni fine
        // trascinamento (move/resize/rotate) fino al prossimo slider.
        if (mode === 'overlay') renderOverlay();
      }
    }

    function canInteract() {
      return mode === 'annotate' || mode === 'measure' || mode === 'crop' || mode === 'overlay';
    }

    canvas.addEventListener('mousedown', (e) => {
      if (!canInteract()) return;
      const [x, y] = toCanvasCoords(e);
      handleStart(x, y);
    });
    canvas.addEventListener('mousemove', (e) => {
      if (!dragState) return;
      const [x, y] = toCanvasCoords(e);
      handleMove(x, y);
    });
    window.addEventListener('mouseup', (e) => {
      if (!dragState) return;
      const [x, y] = toCanvasCoords(e);
      handleEnd(x, y);
    });

    canvas.addEventListener('touchstart', (e) => {
      if (!canInteract() || e.touches.length !== 1) return;
      e.preventDefault();
      const [x, y] = toCanvasCoords(e.touches[0]);
      handleStart(x, y);
    }, { passive: false });
    canvas.addEventListener('touchmove', (e) => {
      if (!dragState || e.touches.length !== 1) return;
      e.preventDefault();
      const [x, y] = toCanvasCoords(e.touches[0]);
      handleMove(x, y);
    }, { passive: false });
    canvas.addEventListener('touchend', (e) => {
      if (!dragState) return;
      const t = e.changedTouches[0];
      const [x, y] = toCanvasCoords(t);
      handleEnd(x, y);
    });
  })();

  // ---------- Mini-anteprima flottante ----------
  // Il pannello "Anteprima" in cima alla pagina copriva tutto quando era
  // "sticky" per l'intero scroll (bug corretto rimuovendolo): questa è la
  // sostituzione sicura, una piccola miniatura in basso a destra che
  // compare SOLO quando l'anteprima vera è fuori vista (IntersectionObserver
  // sul pannello, nessun costo per-scroll), rispecchia lo stesso src e gli
  // stessi filtri CSS live di imgRight (MutationObserver su src/style: così
  // resta sincronizzata da qualunque punto del codice la aggiorni, senza
  // dover intercettare ogni singolo punto che tocca imgRight).
  (function setupFloatingPreview() {
    const floating = $('#an-floating-preview');
    const floatingImg = $('#an-floating-preview-img');
    const previewPanel = $('#an-preview-panel');
    const closeBtn = $('#an-floating-preview-close');
    const dragHandle = $('#an-floating-preview-drag');
    if (!floating || !floatingImg || !previewPanel || !closeBtn || !dragHandle) return;

    let dismissed = false;

    function syncImage() {
      floatingImg.src = imgRight.src;
      floatingImg.style.filter = imgRight.style.filter || '';
    }
    syncImage();
    new MutationObserver(syncImage).observe(imgRight, { attributes: true, attributeFilter: ['src', 'style'] });

    const observer = new IntersectionObserver(([entry]) => {
      if (entry.isIntersecting) {
        floating.classList.remove('visible');
        dismissed = false; // torna disponibile per la prossima volta che scorri via
      } else if (!dismissed) {
        floating.classList.add('visible');
      }
    }, { threshold: 0 });
    observer.observe(previewPanel);

    floatingImg.addEventListener('click', () => {
      previewPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    closeBtn.addEventListener('click', () => {
      dismissed = true;
      floating.classList.remove('visible');
    });

    // ---------- Posizione/dimensione: trascinabile e ridimensionabile ----------
    // Ricordate per pagina (una mini-anteprima diversa per ogni ripresa
    // avrebbe poco senso condividere la stessa posizione), sopravvivono al
    // ricaricamento. Il ridimensionamento è quello nativo del browser (CSS
    // resize:both sull'angolo in basso a destra, vedi style.css): qui basta
    // osservarlo con un ResizeObserver per salvare la nuova dimensione.
    const posKey = 'oe_floating_preview_pos:' + location.pathname;

    function applyStoredGeometry() {
      try {
        const raw = localStorage.getItem(posKey);
        if (!raw) return;
        const g = JSON.parse(raw);
        if (typeof g.left === 'number' && typeof g.top === 'number') {
          floating.style.left = g.left + 'px';
          floating.style.top = g.top + 'px';
          floating.style.right = 'auto';
          floating.style.bottom = 'auto';
        }
        if (typeof g.width === 'number') floating.style.width = g.width + 'px';
        if (typeof g.height === 'number') floating.style.height = g.height + 'px';
      } catch (e) { /* geometria salvata corrotta: ignora, restano i default CSS */ }
    }
    applyStoredGeometry();

    function saveGeometry() {
      const rect = floating.getBoundingClientRect();
      localStorage.setItem(posKey, JSON.stringify({
        left: rect.left, top: rect.top, width: rect.width, height: rect.height,
      }));
    }

    // Trascinamento dalla sola intestazione (non dall'immagine, che apre
    // l'anteprima completa al click): converte subito right/bottom in
    // left/top espliciti, altrimenti trascinare non avrebbe alcun effetto
    // visibile finché l'elemento resta ancorato con right/bottom.
    let dragOffsetX = 0, dragOffsetY = 0, dragging = false;
    dragHandle.addEventListener('pointerdown', (e) => {
      dragging = true;
      const rect = floating.getBoundingClientRect();
      floating.style.left = rect.left + 'px';
      floating.style.top = rect.top + 'px';
      floating.style.right = 'auto';
      floating.style.bottom = 'auto';
      dragOffsetX = e.clientX - rect.left;
      dragOffsetY = e.clientY - rect.top;
      dragHandle.setPointerCapture(e.pointerId);
      e.preventDefault();
    });
    dragHandle.addEventListener('pointermove', (e) => {
      if (!dragging) return;
      const maxLeft = window.innerWidth - floating.offsetWidth;
      const maxTop = window.innerHeight - floating.offsetHeight;
      floating.style.left = Math.min(Math.max(0, e.clientX - dragOffsetX), Math.max(0, maxLeft)) + 'px';
      floating.style.top = Math.min(Math.max(0, e.clientY - dragOffsetY), Math.max(0, maxTop)) + 'px';
    });
    dragHandle.addEventListener('pointerup', (e) => {
      if (!dragging) return;
      dragging = false;
      dragHandle.releasePointerCapture(e.pointerId);
      saveGeometry();
    });

    // Ridimensionamento nativo (resize:both): niente evento dedicato del
    // browser per "fine ridimensionamento", un ResizeObserver con un piccolo
    // debounce fa lo stesso lavoro in modo affidabile.
    let resizeSaveTimer = null;
    new ResizeObserver(() => {
      if (!floating.classList.contains('visible')) return; // ignora il resize "silenzioso" a display:none
      clearTimeout(resizeSaveTimer);
      resizeSaveTimer = setTimeout(saveGeometry, 300);
    }).observe(floating);
  })();

  applyTransform();
  applyLiveFilters();
  loadAnnotations();
})();
