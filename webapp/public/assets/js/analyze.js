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
    if ($('#an-clahe').checked) steps.push({ filter: 'clahe', params: {} });
    if ($('#an-hist-eq').checked) steps.push({ filter: 'histogram_equalization', params: {} });
    if ($('#an-edge').checked) steps.push({ filter: 'edge_detect', params: {} });
    return steps;
  }

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
    ['an-wb', 'an-denoise', 'an-clahe', 'an-hist-eq', 'an-edge'].forEach((id) => { prevChecks[id] = $('#' + id).checked; });

    imgRight.src = CFG.imageUrl;
    resetSliders();
    ['an-wb', 'an-denoise', 'an-clahe', 'an-hist-eq', 'an-edge'].forEach((id) => { $('#' + id).checked = false; });
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
  };

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

  async function loadAnnotations() {
    const res = await fetch(`api/annotations.php?study_id=${CFG.studyId}&target_image=${encodeURIComponent(TARGET_KEY)}`);
    const data = await res.json();
    annotations = data.annotations || [];
    redrawAnnotations();
    renderAnnotationList();
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

  function redrawAnnotations() {
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    annotations.forEach((a) => {
      const c = a.coords;
      const rx = c.x * canvas.width, ry = c.y * canvas.height, rw = c.w * canvas.width, rh = c.h * canvas.height;
      ctx.strokeStyle = a.color || '#00fff2';
      ctx.lineWidth = 2;
      ctx.shadowColor = a.color || '#00fff2';
      ctx.shadowBlur = 6;
      ctx.strokeRect(rx, ry, rw, rh);
      if (a.label) {
        ctx.font = '11px "Share Tech Mono", monospace';
        ctx.fillStyle = a.color || '#00fff2';
        ctx.shadowBlur = 0;
        ctx.fillText(a.label, rx + 3, ry - 4);
      }
      // Maniglie d'angolo: visibili solo in modalità Annota, per far capire
      // che un'annotazione già esistente si può ridimensionare/spostare
      // trascinando, non solo cancellare dalla lista sotto.
      if (mode === 'annotate') {
        ctx.shadowBlur = 0;
        ctx.fillStyle = a.color || '#00fff2';
        [[rx, ry], [rx + rw, ry], [rx, ry + rh], [rx + rw, ry + rh]].forEach(([hx, hy]) => {
          ctx.beginPath();
          ctx.arc(hx, hy, HANDLE_R, 0, Math.PI * 2);
          ctx.fill();
        });
      }
    });
    measurements.forEach((m) => {
      const mColor = m.color || '#ffb020';
      ctx.strokeStyle = mColor;
      ctx.lineWidth = 2;
      ctx.shadowColor = mColor;
      ctx.shadowBlur = 4;
      ctx.beginPath();
      ctx.moveTo(m.x1, m.y1);
      ctx.lineTo(m.x2, m.y2);
      ctx.stroke();
      ctx.shadowBlur = 0;
      ctx.font = '12px "Share Tech Mono", monospace';
      ctx.fillStyle = mColor;
      ctx.fillText(formatDistance(m.distanceM), (m.x1 + m.x2) / 2 + 6, (m.y1 + m.y2) / 2 - 6);
      // Maniglie agli estremi: visibili solo in modalità Misura, trascinabili
      // per aggiustare la linea senza doverla cancellare e ridisegnare.
      if (mode === 'measure') {
        ctx.fillStyle = mColor;
        [[m.x1, m.y1], [m.x2, m.y2]].forEach(([hx, hy]) => {
          ctx.beginPath();
          ctx.arc(hx, hy, HANDLE_R, 0, Math.PI * 2);
          ctx.fill();
        });
      }
    });
  }

  // ---------- Chiamate server per le annotazioni (create/update/delete) ----------
  async function createAnnotationServer(coords, color, label, notes) {
    const res = await fetch('api/annotations.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ study_id: CFG.studyId, capture_id: CFG.captureId, target_image: TARGET_KEY, shape_type: 'rect', coords, color, label, notes }),
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
        pushUndo(() => {
          m.color = prevColor;
          redrawAnnotations();
          renderMeasurementList();
        });
      });
      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'btn btn-sm btn-danger';
      del.textContent = '✕';
      del.title = 'Rimuovi misurazione';
      del.addEventListener('click', () => {
        measurements.splice(i, 1);
        redrawAnnotations();
        renderMeasurementList();
        pushUndo(() => {
          measurements.push(m);
          redrawAnnotations();
          renderMeasurementList();
        });
      });
      right.appendChild(swatch);
      right.appendChild(del);
      div.appendChild(left);
      div.appendChild(right);
      container.appendChild(div);
    });
  }

  $('#an-measure-clear-btn').addEventListener('click', () => {
    measurements.length = 0;
    redrawAnnotations();
    renderMeasurementList();
  });

  function pixelDistance(x1, y1, x2, y2) {
    // Converte lo spostamento in pixel canvas in pixel dell'immagine
    // originale (naturalWidth/Height), poi in metri reali per asse.
    const natW = imgLeft.naturalWidth, natH = imgLeft.naturalHeight;
    const dxPx = ((x2 - x1) / canvas.width) * natW;
    const dyPx = ((y2 - y1) / canvas.height) * natH;
    if (mppX && mppY) {
      const dxM = dxPx * mppX, dyM = dyPx * mppY;
      return { distanceM: Math.sqrt(dxM * dxM + dyM * dyM), dxPx, dyPx };
    }
    return { distanceM: null, dxPx, dyPx };
  }

  // ---------- Ritaglio per ricerca inversa per immagini ----------
  let lastCropBlobUrl = null;

  function renderCropFromSelection(x, y, w, h) {
    const natW = imgRight.naturalWidth, natH = imgRight.naturalHeight;
    const sx = (x / canvas.width) * natW;
    const sy = (y / canvas.height) * natH;
    const sw = (w / canvas.width) * natW;
    const sh = (h / canvas.height) * natH;

    const cropCanvas = document.createElement('canvas');
    cropCanvas.width = Math.max(1, Math.round(sw));
    cropCanvas.height = Math.max(1, Math.round(sh));
    const ctx = cropCanvas.getContext('2d');
    // Ritaglia dalla copia di lavoro così com'è mostrata (base server-side +
    // regolazioni live "cotte" dentro, stessa logica di renderAdjustedCanvas
    // ma limitata al solo rettangolo selezionato).
    ctx.drawImage(imgRight, sx, sy, sw, sh, 0, 0, cropCanvas.width, cropCanvas.height);
    applyPixelAdjustments(ctx, cropCanvas.width, cropCanvas.height);

    cropCanvas.toBlob((blob) => {
      if (!blob) return;
      if (lastCropBlobUrl) URL.revokeObjectURL(lastCropBlobUrl);
      lastCropBlobUrl = URL.createObjectURL(blob);
      $('#an-crop-preview').src = lastCropBlobUrl;
      $('#an-crop-result').style.display = '';
    }, 'image/png');
  }

  $('#an-crop-download-btn').addEventListener('click', () => {
    if (!lastCropBlobUrl) return;
    const a = document.createElement('a');
    a.href = lastCropBlobUrl;
    a.download = 'frammento_ripresa' + CFG.captureId + '.png';
    document.body.appendChild(a);
    a.click();
    a.remove();
  });
  // Apre solo la pagina del motore: l'invio del file resta sempre un gesto
  // manuale ed esplicito dell'analista (trascina il frammento appena
  // scaricato), mai automatico — importante quando si maneggiano riprese
  // potenzialmente sensibili.
  $('#an-crop-open-lens').addEventListener('click', () => window.open('https://lens.google.com/', '_blank'));
  $('#an-crop-open-yandex').addEventListener('click', () => window.open('https://yandex.com/images/', '_blank'));
  $('#an-crop-open-bing').addEventListener('click', () => window.open('https://www.bing.com/visualsearch', '_blank'));
  $('#an-crop-open-tineye').addEventListener('click', () => window.open('https://tineye.com/', '_blank'));

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
    if (mode === 'overlay') drawOverlayHandles();
  }

  const OVERLAY_ROTATE_HANDLE_OFFSET = 28; // px canvas, oltre l'angolo in alto

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
    const rotate = overlayLocalToCanvas(0, -hh - OVERLAY_ROTATE_HANDLE_OFFSET);
    return { corners, rotate };
  }

  function drawOverlayHandles() {
    const ctx = canvas.getContext('2d');
    const { corners, rotate } = overlayHandlePoints();
    const center = [overlay.cx * canvas.width, overlay.cy * canvas.height];
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

    ctx.fillStyle = '#00fff2';
    Object.values(corners).forEach(([hx, hy]) => {
      ctx.beginPath(); ctx.arc(hx, hy, HANDLE_R, 0, Math.PI * 2); ctx.fill();
    });
    // Maniglia di rotazione: cerchio vuoto, per distinguerla a colpo
    // d'occhio dalle maniglie d'angolo (piene, resize).
    ctx.beginPath();
    ctx.arc(rotate[0], rotate[1], HANDLE_R, 0, Math.PI * 2);
    ctx.fillStyle = '#0a0a0a';
    ctx.fill();
    ctx.lineWidth = 2;
    ctx.stroke();
    ctx.restore();

    void center; // riferimento già incluso in overlayLocalToCanvas, tenuto per chiarezza
  }

  function hitOverlayHandle(x, y) {
    if (!overlay.loaded || mode !== 'overlay') return null;
    const { corners, rotate } = overlayHandlePoints();
    if (Math.hypot(x - rotate[0], y - rotate[1]) <= handleHitR()) return { type: 'rotate' };
    for (const [name, [hx, hy]] of Object.entries(corners)) {
      if (Math.hypot(x - hx, y - hy) <= handleHitR()) return { type: 'resize-corner', corner: name };
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
      const aspect = img.naturalWidth / img.naturalHeight;
      if (aspect >= 1) { overlay.baseWFrac = 0.4; overlay.baseHFrac = 0.4 / aspect; }
      else { overlay.baseHFrac = 0.4; overlay.baseWFrac = 0.4 * aspect; }
      overlay.cx = 0.5; overlay.cy = 0.5;
      overlayImgEl.src = url;
      renderOverlay();
      $('#an-overlay-status').textContent = 'Immagine caricata: passa a modalità Sovrapponi per trascinarla, usa gli slider per il resto.';
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
  // direttamente da un trascinamento maniglia (resize/rotazione), così
  // maniglie e slider restano sempre coerenti tra loro. Funziona per
  // scale/rotation (le uniche chiavi pilotabili da maniglia) perché per
  // entrambe la conversione raw->stored è invertibile in modo semplice.
  function syncOverlaySliderFromStored(key) {
    const entry = OVERLAY_SLIDER_MAP.find((e) => e[2] === key);
    if (!entry) return;
    const [inputId, outId, , , fmt] = entry;
    const raw = key === 'scale' ? overlay.scale * 100 : overlay[key];
    $('#' + inputId).value = raw;
    $('#' + outId).textContent = fmt(raw);
  }

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
    ctx.drawImage(overlay.img, -w / 2, -h / 2, w, h);
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

  function hitAnnotationHandle(x, y) {
    for (const a of annotations) {
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
      const c = a.coords;
      const rx = c.x * canvas.width, ry = c.y * canvas.height, rw = c.w * canvas.width, rh = c.h * canvas.height;
      if (x >= rx && x <= rx + rw && y >= ry && y <= ry + rh) return a;
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
      const m = { x1: startX, y1: startY, x2: x, y2: y, distanceM, color: currentMeasureColor };
      measurements.push(m);
      redrawAnnotations();
      renderMeasurementList();
      pushUndo(() => {
        const idx = measurements.indexOf(m);
        if (idx !== -1) measurements.splice(idx, 1);
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
      }
    }

    async function handleEnd(x, y) {
      if (!dragState) return;
      const state = dragState;
      dragState = null;

      if (state.type === 'draw-annotate') { await finishAnnotate(x, y); return; }
      if (state.type === 'draw-measure') { finishMeasure(x, y); return; }
      if (state.type === 'draw-crop') { finishCrop(x, y); return; }

      if (state.type === 'resize' || state.type === 'move') {
        const a = state.ann;
        const newCoords = { ...a.coords };
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
        const newState = { x1: m.x1, y1: m.y1, x2: m.x2, y2: m.y2, distanceM: m.distanceM };
        renderMeasurementList();
        pushUndo(() => {
          Object.assign(m, state.prev);
          redrawAnnotations();
          renderMeasurementList();
        });
        void newState; // valore già scritto in m durante il trascinamento
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

  applyTransform();
  applyLiveFilters();
  loadAnnotations();
})();
