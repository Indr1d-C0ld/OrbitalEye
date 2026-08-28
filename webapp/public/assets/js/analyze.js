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
  let mode = 'annotate';

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
  function renderAdjustedCanvas() {
    const canvas = document.createElement('canvas');
    canvas.width = imgRight.naturalWidth;
    canvas.height = imgRight.naturalHeight;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(imgRight, 0, 0);
    const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
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
      const w = canvas.width, h = canvas.height;
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
    } catch (err) {
      status.textContent = 'Errore: ' + err.message;
    }
  });

  $('#an-advanced-reset-btn').addEventListener('click', () => {
    imgRight.src = CFG.imageUrl;
    resetSliders();
    ['an-wb', 'an-denoise', 'an-clahe', 'an-hist-eq', 'an-edge'].forEach((id) => { $('#' + id).checked = false; });
    $('#an-advanced-status').textContent = 'Ripristinata la ripresa originale.';
  });

  // ---------- Annotazioni (sulla copia di lavoro, a destra) ----------
  let annotations = [];
  const canvas = $('#an-annotate-canvas');

  function resizeAnnotateCanvas() {
    canvas.width = imgRight.clientWidth;
    canvas.height = imgRight.clientHeight;
    redrawAnnotations();
  }
  window.addEventListener('resize', resizeAnnotateCanvas);
  imgRight.addEventListener('load', resizeAnnotateCanvas);
  imgLeft.addEventListener('load', resizeAnnotateCanvas);
  if (imgRight.complete) resizeAnnotateCanvas();

  async function loadAnnotations() {
    const res = await fetch(`api/annotations.php?study_id=${CFG.studyId}&target_image=${encodeURIComponent(TARGET_KEY)}`);
    const data = await res.json();
    annotations = data.annotations || [];
    redrawAnnotations();
    renderAnnotationList();
  }

  function redrawAnnotations() {
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    annotations.forEach((a) => {
      const c = a.coords;
      ctx.strokeStyle = a.color || '#00fff2';
      ctx.lineWidth = 2;
      ctx.shadowColor = a.color || '#00fff2';
      ctx.shadowBlur = 6;
      ctx.strokeRect(c.x * canvas.width, c.y * canvas.height, c.w * canvas.width, c.h * canvas.height);
      if (a.label) {
        ctx.font = '11px "Share Tech Mono", monospace';
        ctx.fillStyle = a.color || '#00fff2';
        ctx.shadowBlur = 0;
        ctx.fillText(a.label, c.x * canvas.width + 3, c.y * canvas.height - 4);
      }
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
      const dot = document.createElement('span');
      dot.style.color = a.color || '#00fff2';
      dot.textContent = '■';
      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'btn btn-sm btn-danger';
      del.textContent = '✕';
      del.title = 'Elimina annotazione';
      del.addEventListener('click', () => deleteEntity('annotation', a.id, 'analyze_capture.php?id=' + CFG.captureId, 'Eliminare questa annotazione?'));
      right.appendChild(dot);
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

  (function setupDrawing() {
    let drawing = false, startX = 0, startY = 0;

    function previewRect(x, y) {
      redrawAnnotations();
      const ctx = canvas.getContext('2d');
      ctx.strokeStyle = '#ffb020';
      ctx.lineWidth = 2;
      ctx.setLineDash([4, 3]);
      ctx.strokeRect(Math.min(startX, x), Math.min(startY, y), Math.abs(x - startX), Math.abs(y - startY));
      ctx.setLineDash([]);
    }

    async function finishDrawing(rawX, rawY) {
      const endX = Math.max(0, Math.min(canvas.width, rawX));
      const endY = Math.max(0, Math.min(canvas.height, rawY));
      const x = Math.min(startX, endX), y = Math.min(startY, endY);
      const w = Math.abs(endX - startX), h = Math.abs(endY - startY);
      if (w < 6 || h < 6) { redrawAnnotations(); return; }

      const label = prompt('Etichetta annotazione (es. "Nuova struttura"):', '');
      if (label === null) { redrawAnnotations(); return; }
      const notes = prompt('Note (opzionale):', '') || '';

      const coords = { x: x / canvas.width, y: y / canvas.height, w: w / canvas.width, h: h / canvas.height };
      const res = await fetch('api/annotations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          study_id: CFG.studyId,
          capture_id: CFG.captureId,
          target_image: TARGET_KEY,
          shape_type: 'rect',
          coords,
          color: '#00fff2',
          label,
          notes,
        }),
      });
      const data = await res.json();
      annotations.push({ id: data.id, coords, color: '#00fff2', label, notes });
      redrawAnnotations();
      renderAnnotationList();
    }

    canvas.addEventListener('mousedown', (e) => {
      if (mode !== 'annotate') return;
      const [x, y] = toCanvasCoords(e);
      startX = x; startY = y; drawing = true;
    });
    canvas.addEventListener('mousemove', (e) => {
      if (!drawing) return;
      const [x, y] = toCanvasCoords(e);
      previewRect(x, y);
    });
    window.addEventListener('mouseup', (e) => {
      if (!drawing) return;
      drawing = false;
      const [x, y] = toCanvasCoords(e);
      finishDrawing(x, y);
    });

    canvas.addEventListener('touchstart', (e) => {
      if (mode !== 'annotate' || e.touches.length !== 1) return;
      e.preventDefault();
      const [x, y] = toCanvasCoords(e.touches[0]);
      startX = x; startY = y; drawing = true;
    }, { passive: false });
    canvas.addEventListener('touchmove', (e) => {
      if (!drawing || e.touches.length !== 1) return;
      e.preventDefault();
      const [x, y] = toCanvasCoords(e.touches[0]);
      previewRect(x, y);
    }, { passive: false });
    canvas.addEventListener('touchend', (e) => {
      if (!drawing) return;
      drawing = false;
      const t = e.changedTouches[0];
      const [x, y] = toCanvasCoords(t);
      finishDrawing(x, y);
    });
  })();

  applyTransform();
  applyLiveFilters();
  loadAnnotations();
})();
