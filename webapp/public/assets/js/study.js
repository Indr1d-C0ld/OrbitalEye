(function () {
  const state = {
    selectedA: null,
    selectedB: null,
    currentComparison: null, // { comparisonId, stats, regions, urls }
    currentView: 'overlay',
    swipeCaptureAUrl: null,
    alignMode: 'auto', // 'auto' | 'manual' — vedi editor punti di controllo
    manualControlPoints: [], // punti già salvati per la coppia A/B selezionata
  };

  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

  // ---------- Tab switching (upload / Sentinel Hub / Esri) ----------
  // Sentinel Hub ed Esri sono due sezioni indipendenti e paritetiche (non
  // una opzione dentro l'altra): ciascuna ha la propria mappa/area e i
  // propri campi, inizializzati la prima volta che la relativa tab viene
  // aperta.
  const mapPickers = {}; // { 'tab-sentinelhub': controller, 'tab-esri': controller }
  const MAP_PICKER_CONFIG = {
    'tab-sentinelhub': {
      div: 'sh-map-picker',
      fields: { minLon: 'sh-min-lon', minLat: 'sh-min-lat', maxLon: 'sh-max-lon', maxLat: 'sh-max-lat' },
      toggles: { draw: 'sh-map-draw', pan: 'sh-map-pan', mapView: 'sh-map-osm', satView: 'sh-map-sat' },
    },
    'tab-esri': {
      div: 'esri-map-picker',
      fields: { minLon: 'esri-min-lon', minLat: 'esri-min-lat', maxLon: 'esri-max-lon', maxLat: 'esri-max-lat' },
      toggles: { draw: 'esri-map-draw', pan: 'esri-map-pan', mapView: 'esri-map-osm', satView: 'esri-map-sat' },
    },
  };
  $$('.tab-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      $$('.tab-btn').forEach((b) => b.classList.remove('active'));
      btn.classList.add('active');
      $$('.tab-pane').forEach((p) => (p.style.display = 'none'));
      $('#' + btn.dataset.tab).style.display = '';

      const cfg = MAP_PICKER_CONFIG[btn.dataset.tab];
      if (cfg && typeof initMapPicker === 'function') {
        if (!mapPickers[btn.dataset.tab]) {
          mapPickers[btn.dataset.tab] = initMapPicker(cfg.div, cfg.fields, cfg.toggles);
        } else {
          mapPickers[btn.dataset.tab].invalidateSize();
        }
      }
    });
  });

  // ---------- Capture selection ----------
  function refreshSelectionUI() {
    $$('.thumb-card').forEach((card) => {
      const id = parseInt(card.dataset.captureId, 10);
      card.classList.remove('selected');
      let badge = card.querySelector('.sel-badge');
      if (badge) badge.remove();
      let text = null;
      if (id === state.selectedA) text = 'A · PRIMA';
      if (id === state.selectedB) text = 'B · DOPO';
      if (text) {
        card.classList.add('selected');
        const meta = card.querySelector('.meta');
        const b = document.createElement('div');
        b.className = 'badge badge-cyan sel-badge';
        b.style.marginTop = '4px';
        b.textContent = text;
        meta.appendChild(b);
      }
    });
    $('#compare-panel').style.display = state.selectedA && state.selectedB ? '' : 'none';

    // Se la selezione corrente non è più la stessa coppia di riprese del
    // risultato mostrato, nascondi il pannello invece di lasciarlo "congelato"
    // su un confronto ormai non corrispondente a ciò che è selezionato adesso.
    if (
      state.currentComparison &&
      (state.currentComparison.captureAId !== state.selectedA || state.currentComparison.captureBId !== state.selectedB)
    ) {
      $('#results-panel').style.display = 'none';
      state.currentComparison = null;
    }

    if (typeof window.refreshManualAlignStatus === 'function') {
      window.refreshManualAlignStatus();
    }
  }

  $$('.thumb-card').forEach((card) => {
    card.addEventListener('click', () => {
      const id = parseInt(card.dataset.captureId, 10);
      if (state.selectedA === id) { state.selectedA = null; }
      else if (state.selectedB === id) { state.selectedB = null; }
      else if (!state.selectedA) { state.selectedA = id; }
      else if (!state.selectedB) { state.selectedB = id; }
      else { state.selectedA = id; state.selectedB = null; }
      refreshSelectionUI();
    });
  });

  // ---------- Upload form ----------
  const uploadForm = $('#upload-form');
  if (uploadForm) {
    uploadForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      const status = $('#upload-status');
      status.textContent = 'Caricamento in corso...';
      try {
        const res = await fetch('api/upload_capture.php', { method: 'POST', body: new FormData(uploadForm) });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Errore');
        status.textContent = 'Caricato. Ricarico la pagina...';
        setTimeout(() => window.location.reload(), 400);
      } catch (err) {
        status.textContent = 'Errore: ' + err.message;
      }
    });
  }

  // ---------- Fetch (Sentinel Hub / Esri) — stessa logica per entrambe le
  // sezioni, ciascuna con il proprio form e i propri campi ----------
  $$('.fetch-form').forEach((form) => {
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const status = form.querySelector('.fetch-status');
      status.textContent = 'Richiesta al servizio di analisi in corso (può richiedere qualche secondo)...';
      const fd = new FormData(form);
      const payload = {
        study_id: parseInt(fd.get('study_id'), 10),
        source: fd.get('source'),
        bbox: [fd.get('min_lon'), fd.get('min_lat'), fd.get('max_lon'), fd.get('max_lat')],
      };
      if (fd.has('date_from')) payload.date_from = fd.get('date_from');
      if (fd.has('date_to')) payload.date_to = fd.get('date_to');
      if (fd.has('max_cloud_coverage')) payload.max_cloud_coverage = fd.get('max_cloud_coverage');

      try {
        const res = await fetch('api/fetch_capture.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Errore');
        status.textContent = 'Ripresa scaricata. Ricarico la pagina...';
        setTimeout(() => window.location.reload(), 400);
      } catch (err) {
        status.textContent = 'Errore: ' + err.message;
      }
    });
  });

  // ---------- Range input live values ----------
  const rangeBindings = [
    ['opt-threshold', 'val-threshold'],
    ['opt-minarea', 'val-minarea'],
    ['opt-morph', 'val-morph'],
    ['opt-alpha', 'val-alpha'],
    ['opt-gamma', 'val-gamma'],
    ['opt-sharpen-amount', 'val-sharpen'],
    ['opt-desaturate-amount', 'val-desaturate'],
  ];
  rangeBindings.forEach(([inputId, outId]) => {
    const input = $('#' + inputId);
    const out = $('#' + outId);
    if (input && out) input.addEventListener('input', () => (out.textContent = input.value));
  });

  // ---------- Preset di sensibilità ----------
  $$('.sensitivity-preset').forEach((btn) => {
    btn.addEventListener('click', () => {
      const map = [
        ['opt-threshold', 'val-threshold', btn.dataset.threshold],
        ['opt-minarea', 'val-minarea', btn.dataset.minarea],
        ['opt-morph', 'val-morph', btn.dataset.morph],
      ];
      map.forEach(([inputId, outId, value]) => {
        const input = $('#' + inputId);
        const out = $('#' + outId);
        input.value = value;
        out.textContent = value;
      });
      $('#opt-otsu').checked = false;
    });
  });

  // ---------- Run comparison ----------
  const runBtn = $('#run-compare-btn');
  if (runBtn) {
    runBtn.addEventListener('click', async () => {
      if (!state.selectedA || !state.selectedB) return;
      if (state.alignMode === 'manual' && state.manualControlPoints.length < 3) {
        $('#compare-status').textContent = 'Servono almeno 3 punti di controllo salvati per usare l\'allineamento manuale su questa coppia di riprese.';
        return;
      }
      const status = $('#compare-status');
      runBtn.disabled = true;
      status.textContent = 'Elaborazione in corso: allineamento, calcolo differenze, filtri...';

      const payload = {
        study_id: window.ORBITALEYE.studyId,
        capture_a_id: state.selectedA,
        capture_b_id: state.selectedB,
        align: $('#opt-align').checked,
        align_mode: state.alignMode,
        diff_method: $('#opt-diff-method').value,
        threshold: $('#opt-threshold').value,
        use_otsu: $('#opt-otsu').checked,
        morph_kernel: $('#opt-morph').value,
        open_iterations: 1,
        close_iterations: 2,
        min_blob_area: $('#opt-minarea').value,
        overlay_alpha: $('#opt-alpha').value,
        title: $('#result-title') ? $('#result-title').value : '',
        enhance: {
          white_balance: $('#opt-wb').checked,
          denoise: $('#opt-denoise').checked,
          denoise_method: $('#opt-denoise-method').value,
          denoise_strength: $('#opt-denoise-strength').value,
          clahe: $('#opt-clahe').checked,
          hist_eq: $('#opt-hist-eq').checked,
          gamma_enabled: $('#opt-gamma-enabled').checked,
          gamma: $('#opt-gamma').value,
          sharpen: $('#opt-sharpen').checked,
          sharpen_amount: $('#opt-sharpen-amount').value,
          desaturate: $('#opt-desaturate').checked,
          desaturate_amount: $('#opt-desaturate-amount').value,
        },
      };

      try {
        const res = await fetch('api/compare.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Errore');
        status.textContent = 'Completato.';
        renderResult({
          comparisonId: data.comparison_id,
          captureAId: state.selectedA,
          captureBId: state.selectedB,
          stats: data.stats,
          regions: data.regions,
          urls: data.urls,
        });
      } catch (err) {
        status.textContent = 'Errore: ' + err.message;
      } finally {
        runBtn.disabled = false;
      }
    });
  }

  // ---------- Load a past comparison from history ----------
  window.loadComparison = function (comparisonId) {
    const cmp = window.ORBITALEYE.comparisons.find((c) => parseInt(c.id, 10) === comparisonId);
    if (!cmp) return;
    const urls = {};
    Object.keys(cmp.result_paths).forEach((k) => {
      urls[k] = window.ORBITALEYE.mediaBase + encodeURIComponent(cmp.result_paths[k]);
    });
    // Fondamentale: senza questo, "Originale A/B" e lo swipe prima/dopo non
    // sanno più quali riprese sono state usate (restano su null), quindi
    // falliscono silenziosamente o mostrano la stessa immagine due volte.
    state.selectedA = parseInt(cmp.capture_a_id, 10);
    state.selectedB = parseInt(cmp.capture_b_id, 10);
    renderResult({
      comparisonId: cmp.id,
      captureAId: state.selectedA,
      captureBId: state.selectedB,
      stats: cmp.stats,
      regions: cmp.regions,
      urls,
    });
    refreshSelectionUI();
    $('#result-title').value = cmp.title || '';
    $('#results-panel').scrollIntoView({ behavior: 'smooth' });
  };

  function renderResult(result) {
    state.currentComparison = result;
    $('#results-panel').style.display = '';
    const exportBtn = $('#export-comparison-btn');
    if (exportBtn) exportBtn.href = 'export_comparison.php?id=' + result.comparisonId;

    const s = result.stats;
    $('#result-stats').innerHTML = `
      <div class="stat-tile"><div class="value">${(s.changed_ratio * 100).toFixed(2)}%</div><div class="label">Superficie variata</div></div>
      <div class="stat-tile"><div class="value">${s.num_regions}</div><div class="label">Regioni rilevate</div></div>
      <div class="stat-tile"><div class="value">${s.largest_region_area}</div><div class="label">Area regione max (px²)</div></div>
      <div class="stat-tile"><div class="value">${s.changed_pixels}</div><div class="label">Pixel variati</div></div>
    `;

    renderRegionList(result.regions);
    setView('overlay');
  }

  function renderRegionList(regions) {
    const list = $('#region-list');
    list.innerHTML = '';
    regions.forEach((r, i) => {
      const div = document.createElement('div');
      div.className = 'region-item';
      div.dataset.regionIndex = i;
      div.innerHTML = `
        <span>#${i + 1} — ${r.w}×${r.h}px <span style="color:var(--text-muted);">(${r.area}px²)</span></span>
        <span style="display:flex; gap:4px; flex-wrap:wrap;">
          <button type="button" class="btn btn-sm" data-jump-original="a" data-region="${i}" title="Vai alla ripresa A originale (senza filtri/overlay), zoomata su questa regione">📷 A</button>
          <button type="button" class="btn btn-sm" data-jump-original="b" data-region="${i}" title="Vai alla ripresa B originale (senza filtri/overlay), zoomata su questa regione">📷 B</button>
          <button type="button" class="btn btn-sm" data-annotate-region="${i}" title="Crea un'annotazione da questa regione rilevata">✎ Annota</button>
        </span>`;
      div.addEventListener('click', (e) => {
        if (e.target.closest('button')) return;
        selectRegion(i);
      });
      div.querySelectorAll('[data-jump-original]').forEach((btn) => {
        btn.addEventListener('click', (e) => {
          e.stopPropagation();
          jumpToOriginal(i, btn.dataset.jumpOriginal);
        });
      });
      div.querySelector('[data-annotate-region]').addEventListener('click', (e) => {
        e.stopPropagation();
        quickAnnotateRegion(i);
      });
      list.appendChild(div);
    });
  }

  // Aspetta che l'immagine dello stage principale sia effettivamente
  // caricata prima di procedere (serve quando selectRegion deve prima
  // uscire dalla vista swipe, che non mostra le regioni).
  function whenStageReady(callback) {
    const img = $('#stage-img');
    if (state.currentView === 'swipe') {
      setView('overlay');
    }
    if (img.naturalWidth) {
      callback();
      return;
    }
    const check = () => {
      if (img.naturalWidth) callback();
      else setTimeout(check, 30);
    };
    check();
  }

  function highlightRegionListItem(index) {
    $$('.region-item').forEach((el) => el.classList.toggle('selected', Number(el.dataset.regionIndex) === index));
    const active = $(`.region-item[data-region-index="${index}"]`);
    if (active) active.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function selectRegion(index) {
    if (!state.currentComparison) return;
    const region = state.currentComparison.regions[index];
    if (!region) return;
    whenStageReady(() => {
      flashRegion(region);
      const img = $('#stage-img');
      if (activeZoom && activeZoom.focusRegion) {
        activeZoom.focusRegion(region.x, region.y, region.w, region.h, img.naturalWidth, img.naturalHeight);
      }
      highlightRegionListItem(index);
    });
  }

  // Scorciatoia: salta direttamente alla ripresa originale (A o B, senza
  // overlay/heatmap/filtri) già zoomata sulla regione scelta — utile per
  // verificare a colpo d'occhio cosa mostra davvero la foto in quel punto,
  // senza l'evidenziazione grafica del confronto.
  function jumpToOriginal(index, which) {
    if (!state.currentComparison) return;
    const region = state.currentComparison.regions[index];
    if (!region) return;
    setView(which === 'a' ? 'original-a' : 'original-b');
    whenStageReady(() => {
      flashRegion(region);
      const img = $('#stage-img');
      if (activeZoom && activeZoom.focusRegion) {
        activeZoom.focusRegion(region.x, region.y, region.w, region.h, img.naturalWidth, img.naturalHeight);
      }
      highlightRegionListItem(index);
    });
  }

  async function quickAnnotateRegion(index) {
    if (!state.currentComparison) return;
    const region = state.currentComparison.regions[index];
    if (!region) return;

    whenStageReady(async () => {
      const img = $('#stage-img');
      const label = prompt(`Etichetta annotazione per la regione #${index + 1}:`, '');
      if (label === null) return;
      const notes = prompt('Note (opzionale):', '') || '';

      const coords = {
        x: region.x / img.naturalWidth,
        y: region.y / img.naturalHeight,
        w: region.w / img.naturalWidth,
        h: region.h / img.naturalHeight,
      };
      const color = '#ffb020';
      const res = await fetch('api/annotations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          study_id: window.ORBITALEYE.studyId,
          comparison_id: state.currentComparison.comparisonId,
          target_image: targetKey(),
          shape_type: 'rect',
          coords,
          color,
          label,
          notes,
        }),
      });
      const data = await res.json();
      annotations.push({ id: data.id, coords, color, label, notes });
      redrawAnnotations();
      renderAnnotationList();
      highlightRegionListItem(index);
    });
  }

  // ---------- Zoom & pan ----------
  // Un'unica utility riusata sia per lo stage principale (overlay/heatmap/
  // maschera, dove convive con il tool di annotazione tramite un toggle di
  // modalità) sia per lo swipe prima/dopo (dove offriamo solo lo zoom con
  // rotellina/pulsanti, senza drag-to-pan, per non entrare in conflitto con
  // il drag che regola la posizione dello slider di confronto).
  function createZoomPan(viewport, content, opts) {
    opts = opts || {};
    const MIN_SCALE = 1, MAX_SCALE = 8, STEP = 1.25;
    let scale = 1, tx = 0, ty = 0;
    let mode = opts.mode || 'pan';

    function apply() {
      content.style.transform = `translate(${tx}px, ${ty}px) scale(${scale})`;
      if (opts.onChange) opts.onChange(scale);
    }

    function clampPan() {
      const vw = viewport.clientWidth, vh = viewport.clientHeight;
      const cw = vw * scale, ch = vh * scale;
      tx = Math.max(Math.min(0, vw - cw), Math.min(0, tx));
      ty = Math.max(Math.min(0, vh - ch), Math.min(0, ty));
    }

    function zoomAt(cx, cy, factor) {
      const newScale = Math.max(MIN_SCALE, Math.min(MAX_SCALE, scale * factor));
      if (newScale === scale) return;
      const contentX = (cx - tx) / scale;
      const contentY = (cy - ty) / scale;
      tx = cx - contentX * newScale;
      ty = cy - contentY * newScale;
      scale = newScale;
      clampPan();
      apply();
    }

    viewport.addEventListener('wheel', (e) => {
      if (!state.currentComparison) return;
      e.preventDefault();
      const rect = viewport.getBoundingClientRect();
      zoomAt(e.clientX - rect.left, e.clientY - rect.top, e.deltaY < 0 ? STEP : 1 / STEP);
    }, { passive: false });

    if (opts.enablePan !== false) {
      let panning = false, panStartX = 0, panStartY = 0, startTx = 0, startTy = 0;
      viewport.addEventListener('mousedown', (e) => {
        if (mode !== 'pan' || scale <= 1.001) return;
        if (opts.excludeSelector && e.target.closest(opts.excludeSelector)) return;
        panning = true;
        panStartX = e.clientX; panStartY = e.clientY;
        startTx = tx; startTy = ty;
        viewport.classList.add('panning');
      });
      window.addEventListener('mousemove', (e) => {
        if (!panning) return;
        tx = startTx + (e.clientX - panStartX);
        ty = startTy + (e.clientY - panStartY);
        clampPan();
        apply();
      });
      window.addEventListener('mouseup', () => {
        if (!panning) return;
        panning = false;
        viewport.classList.remove('panning');
      });

      // Touch: un dito trascina (solo in modalità "pan"), stesso codice del mouse.
      let touchPanning = false, touchStartX = 0, touchStartY = 0, touchStartTx = 0, touchStartTy = 0;
      viewport.addEventListener('touchstart', (e) => {
        if (e.touches.length !== 1 || mode !== 'pan' || scale <= 1.001) return;
        if (opts.excludeSelector && e.target.closest(opts.excludeSelector)) return;
        touchPanning = true;
        const t = e.touches[0];
        touchStartX = t.clientX; touchStartY = t.clientY;
        touchStartTx = tx; touchStartTy = ty;
        viewport.classList.add('panning');
      }, { passive: true });
      viewport.addEventListener('touchmove', (e) => {
        if (!touchPanning || e.touches.length !== 1) return;
        e.preventDefault();
        const t = e.touches[0];
        tx = touchStartTx + (t.clientX - touchStartX);
        ty = touchStartTy + (t.clientY - touchStartY);
        clampPan();
        apply();
      }, { passive: false });
      viewport.addEventListener('touchend', () => {
        if (!touchPanning) return;
        touchPanning = false;
        viewport.classList.remove('panning');
      });
    }

    // Pinch-to-zoom (due dita): attivo sempre, indipendentemente dalla
    // modalità — equivalente touch della rotellina del mouse.
    let pinchStartDist = null, pinchStartScale = 1;
    function touchDist(t1, t2) {
      return Math.hypot(t1.clientX - t2.clientX, t1.clientY - t2.clientY);
    }
    viewport.addEventListener('touchstart', (e) => {
      if (e.touches.length === 2) {
        pinchStartDist = touchDist(e.touches[0], e.touches[1]);
        pinchStartScale = scale;
      }
    }, { passive: true });
    viewport.addEventListener('touchmove', (e) => {
      if (e.touches.length !== 2 || !pinchStartDist) return;
      e.preventDefault();
      const rect = viewport.getBoundingClientRect();
      const mid = {
        x: (e.touches[0].clientX + e.touches[1].clientX) / 2 - rect.left,
        y: (e.touches[0].clientY + e.touches[1].clientY) / 2 - rect.top,
      };
      const targetScale = Math.max(MIN_SCALE, Math.min(MAX_SCALE, pinchStartScale * (touchDist(e.touches[0], e.touches[1]) / pinchStartDist)));
      zoomAt(mid.x, mid.y, targetScale / scale);
    }, { passive: false });
    viewport.addEventListener('touchend', (e) => {
      if (e.touches.length < 2) pinchStartDist = null;
    });

    return {
      zoomIn() { const r = viewport.getBoundingClientRect(); zoomAt(r.width / 2, r.height / 2, STEP); },
      zoomOut() { const r = viewport.getBoundingClientRect(); zoomAt(r.width / 2, r.height / 2, 1 / STEP); },
      reset() { scale = 1; tx = 0; ty = 0; apply(); },
      setMode(m) { mode = m; viewport.classList.toggle('mode-pan', m === 'pan'); },
      getScale() { return scale; },
      // Centra e ingrandisce lo stage su una regione data in coordinate
      // pixel dell'immagine originale (px, py, pw, ph in natW×natH) — usato
      // per lo "zoom automatico" quando si seleziona una regione rilevata,
      // dalla lista o direttamente cliccando il suo riquadro sull'immagine.
      focusRegion(px, py, pw, ph, natW, natH) {
        if (!natW || !natH) return;
        const vw = viewport.clientWidth, vh = viewport.clientHeight;
        const PAD = 2.2; // margine attorno alla regione, in multipli della sua dimensione
        const regionContentX = (px / natW) * vw;
        const regionContentY = (py / natH) * vh;
        const regionContentW = Math.max(1, (pw / natW) * vw);
        const regionContentH = Math.max(1, (ph / natH) * vh);
        const centerX = regionContentX + regionContentW / 2;
        const centerY = regionContentY + regionContentH / 2;
        const targetScale = Math.max(MIN_SCALE, Math.min(MAX_SCALE, Math.min(vw / (regionContentW * PAD), vh / (regionContentH * PAD))));
        scale = targetScale;
        tx = vw / 2 - centerX * scale;
        ty = vh / 2 - centerY * scale;
        clampPan();
        apply();
      },
    };
  }

  const stageZoom = createZoomPan($('#stage-viewport'), $('#stage-content'), {
    mode: 'annotate',
    onChange: (s) => { $('#zoom-level').textContent = Math.round(s * 100) + '%'; },
  });
  const swipeZoom = createZoomPan($('#swipe-viewport'), $('#swipe-content'), {
    enablePan: false,
    onChange: (s) => { $('#zoom-level').textContent = Math.round(s * 100) + '%'; },
  });
  let activeZoom = stageZoom;
  let stageMode = 'annotate';

  $$('#mode-toggle .mode-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      stageMode = btn.dataset.mode;
      stageZoom.setMode(stageMode);
      $$('#mode-toggle .mode-btn').forEach((b) => b.classList.toggle('active', b === btn));
    });
  });
  $('#zoom-in').addEventListener('click', () => activeZoom.zoomIn());
  $('#zoom-out').addEventListener('click', () => activeZoom.zoomOut());
  $('#zoom-reset').addEventListener('click', () => activeZoom.reset());

  // ---------- View switching (overlay / heatmap / swipe / mask) ----------
  $$('.view-tab-btn').forEach((btn) => {
    btn.addEventListener('click', () => setView(btn.dataset.view));
  });

  function setView(view) {
    if (!state.currentComparison) return;
    state.currentView = view;
    $$('.view-tab-btn').forEach((b) => b.classList.toggle('active', b.dataset.view === view));

    const single = $('#stage-single');
    const swipe = $('#stage-swipe');

    // "Originale A" mostra enhanced_a: la ripresa A così come è stata
    // effettivamente usata nel calcolo (con gli stessi filtri di enhancement
    // pre-analisi applicati anche a B), non il file grezzo — altrimenti,
    // confrontandola con "Originale B" (che riflette sempre enhance_b),
    // sembrerebbe che l'enhancement agisca solo su una delle due riprese.
    // Fallback alla ripresa grezza solo per i confronti salvati prima
    // dell'introduzione di enhanced_a, che non ce l'hanno.
    function captureAUrl() {
      if (state.currentComparison && state.currentComparison.urls.enhanced_a) {
        return state.currentComparison.urls.enhanced_a;
      }
      const captureA = window.ORBITALEYE.captures.find((c) => c.id === state.selectedA);
      return captureA ? window.ORBITALEYE.mediaBase + encodeURIComponent(captureA.relative_path) : null;
    }

    if (view === 'swipe') {
      single.style.display = 'none';
      swipe.style.display = '';
      const beforeUrl = captureAUrl() || state.currentComparison.urls.aligned_b;
      $('#swipe-before').src = beforeUrl;
      $('#swipe-after').src = state.currentComparison.urls.aligned_b;
      initSwipe();
      activeZoom = swipeZoom;
      $('#mode-toggle').style.display = 'none';
      $('#stage-hint').textContent = 'Rotellina del mouse o pulsanti +/− per zoomare. Trascina il cursore ↔ per spostare la linea di confronto prima/dopo.';
    } else if (view === 'original-a' || view === 'original-b') {
      // Ripresa satellitare così come usata nel confronto: stessi filtri di
      // enhancement pre-analisi applicati (per A) e stessa correzione
      // geometrica di allineamento (per B), nello stesso sistema di
      // coordinate delle regioni rilevate — non il file grezzo non filtrato.
      single.style.display = '';
      swipe.style.display = 'none';
      let url = null;
      if (view === 'original-a') {
        url = captureAUrl();
      } else {
        url = state.currentComparison.urls.aligned_b;
      }
      if (!url) {
        alert('Immagine originale non disponibile per questo confronto.');
        setView('overlay');
        return;
      }
      $('#stage-img').src = url;
      $('#stage-img').onload = () => {
        resizeCanvas();
        loadAnnotations();
      };
      activeZoom = stageZoom;
      $('#mode-toggle').style.display = '';
      $('#stage-hint').innerHTML = 'Ripresa satellitare originale, senza overlay/heatmap/filtri di enhancing. Rotellina del mouse per zoomare, trascina per spostarti. Clicca (senza trascinare) dentro un riquadro numerato per ingrandirlo automaticamente.';
    } else {
      single.style.display = '';
      swipe.style.display = 'none';
      const url = state.currentComparison.urls[view];
      if (!url) {
        // Confronti salvati prima dell'introduzione di questa vista (es.
        // "Contorni") non hanno l'immagine corrispondente: avvisa invece di
        // tornare in silenzio sull'overlay, che da fuori sembra un bottone
        // rotto piuttosto che un limite dei dati salvati in precedenza.
        const labels = { overlay: 'Overlay differenze', heatmap: 'Heatmap', mask: 'Maschera', edges: 'Contorni' };
        alert(`Questo confronto è stato salvato prima che la vista "${labels[view] || view}" fosse disponibile, quindi non è mai stata generata per lui. Riesegui il confronto (stessi parametri o nuovi) per ottenerla.`);
        setView('overlay');
        return;
      }
      $('#stage-img').src = url;
      $('#stage-img').onload = () => {
        resizeCanvas();
        loadAnnotations();
      };
      activeZoom = stageZoom;
      $('#mode-toggle').style.display = '';
      $('#stage-hint').innerHTML = 'Modalità <strong>Annota</strong>: trascina per disegnare un’annotazione. Modalità <strong>Sposta</strong>: trascina per spostare l’immagine quando sei ingrandito. Rotellina del mouse per zoomare (o i pulsanti +/−). Clicca (senza trascinare) dentro un riquadro numerato per ingrandirlo automaticamente.';
    }
    activeZoom.reset();
  }

  function flashRegion(region) {
    if (state.currentView === 'swipe') return;
    const img = $('#stage-img');
    if (!img.naturalWidth) return;
    const canvas = $('#annotate-canvas');
    const ctx = canvas.getContext('2d');
    redrawAnnotations();
    const scaleX = canvas.width / img.naturalWidth;
    const scaleY = canvas.height / img.naturalHeight;
    ctx.strokeStyle = '#ff2b6d';
    ctx.lineWidth = 3;
    ctx.shadowColor = '#ff2b6d';
    ctx.shadowBlur = 10;
    ctx.strokeRect(region.x * scaleX, region.y * scaleY, region.w * scaleX, region.h * scaleY);
  }

  // ---------- Swipe slider ----------
  function initSwipe() {
    const wrap = $('#stage-swipe');
    const afterWrap = $('#swipe-after-wrap');
    const handle = $('#swipe-handle');

    function setPct(pct) {
      pct = Math.max(0, Math.min(100, pct));
      afterWrap.style.width = pct + '%';
      handle.style.left = pct + '%';
      const w = wrap.clientWidth;
      $('#swipe-after').style.width = w + 'px';
    }
    setPct(50);

    let dragging = false;
    handle.addEventListener('mousedown', () => (dragging = true));
    window.addEventListener('mouseup', () => (dragging = false));
    window.addEventListener('mousemove', (e) => {
      if (!dragging) return;
      const rect = wrap.getBoundingClientRect();
      setPct(((e.clientX - rect.left) / rect.width) * 100);
    });
    wrap.addEventListener('click', (e) => {
      const rect = wrap.getBoundingClientRect();
      setPct(((e.clientX - rect.left) / rect.width) * 100);
    });

    // Touch: trascina la maniglia con un dito (equivalente del drag mouse).
    handle.addEventListener('touchstart', (e) => {
      dragging = true;
      e.preventDefault();
    }, { passive: false });
    window.addEventListener('touchend', () => (dragging = false));
    window.addEventListener('touchmove', (e) => {
      if (!dragging || e.touches.length !== 1) return;
      e.preventDefault();
      const rect = wrap.getBoundingClientRect();
      setPct(((e.touches[0].clientX - rect.left) / rect.width) * 100);
    }, { passive: false });
  }

  // ---------- Save to library ----------
  const saveBtn = $('#save-library-btn');
  if (saveBtn) {
    saveBtn.addEventListener('click', async () => {
      if (!state.currentComparison) return;
      const titleInput = $('#result-title');
      const comparisonId = state.currentComparison.comparisonId;
      const res = await fetch('api/save_comparison.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          comparison_id: comparisonId,
          saved: true,
          title: titleInput ? titleInput.value : '',
        }),
      });
      if (!res.ok) {
        alert('Salvataggio in libreria fallito.');
        return;
      }
      saveBtn.textContent = '✔ Salvato, ricarico...';
      // La tabella "Storico confronti dello studio" è generata da PHP al
      // caricamento pagina: ricarichiamo (riaprendo lo stesso confronto via
      // ?comparison=) così titolo aggiornato e stato "libreria" sono visibili
      // subito, invece di restare nella vista non aggiornata di questa pagina.
      window.location.href = 'study.php?id=' + window.ORBITALEYE.studyId + '&comparison=' + comparisonId;
    });
  }

  // ---------- Annotation canvas ----------
  let annotations = [];

  function targetKey() {
    if (!state.currentComparison) return null;
    return 'cmp' + state.currentComparison.comparisonId + '_' + state.currentView;
  }

  function resizeCanvas() {
    const img = $('#stage-img');
    const canvas = $('#annotate-canvas');
    canvas.width = img.clientWidth;
    canvas.height = img.clientHeight;
    redrawAnnotations();
  }
  window.addEventListener('resize', () => { if (state.currentView !== 'swipe') resizeCanvas(); });

  async function loadAnnotations() {
    if (!state.currentComparison) return;
    const key = targetKey();
    const res = await fetch(`api/annotations.php?study_id=${window.ORBITALEYE.studyId}&target_image=${encodeURIComponent(key)}`);
    const data = await res.json();
    annotations = data.annotations || [];
    redrawAnnotations();
    renderAnnotationList();
  }

  function redrawAnnotations() {
    const canvas = $('#annotate-canvas');
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
    const container = $('#annotation-list');
    if (!container) return;
    if (!annotations.length) {
      container.innerHTML = '<div class="hint">Nessuna annotazione su questa vista.</div>';
      return;
    }
    container.innerHTML = '';
    annotations.forEach((a) => {
      const div = document.createElement('div');
      div.className = 'region-item';
      div.innerHTML = `<span>${a.label ? a.label : 'senza etichetta'}${a.notes ? ' — ' + a.notes : ''}</span><span style="color:${a.color}">■</span>`;
      container.appendChild(div);
    });
  }

  // Converte le coordinate del mouse (pixel sullo schermo) in coordinate
  // interne del canvas: necessario perché quando lo stage è ingrandito via
  // zoom (transform CSS) le dimensioni "a schermo" del canvas non coincidono
  // più con la sua risoluzione di disegno interna (canvas.width/height).
  function toCanvasCoords(e, canvas) {
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    return [(e.clientX - rect.left) * scaleX, (e.clientY - rect.top) * scaleY];
  }

  (function setupDrawing() {
    const canvas = $('#annotate-canvas');
    if (!canvas) return;
    let drawing = false;
    let startX = 0, startY = 0;

    function canStartDrawing() {
      return !(state.currentView === 'swipe' || !state.currentComparison || stageMode !== 'annotate');
    }

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
          study_id: window.ORBITALEYE.studyId,
          comparison_id: state.currentComparison.comparisonId,
          target_image: targetKey(),
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
      if (!canStartDrawing()) return;
      const [x, y] = toCanvasCoords(e, canvas);
      startX = x;
      startY = y;
      drawing = true;
    });
    canvas.addEventListener('mousemove', (e) => {
      if (!drawing) return;
      const [x, y] = toCanvasCoords(e, canvas);
      previewRect(x, y);
    });
    window.addEventListener('mouseup', (e) => {
      if (!drawing) return;
      drawing = false;
      const [rawX, rawY] = toCanvasCoords(e, canvas);
      finishDrawing(rawX, rawY);
    });

    // Touch: stessa logica del mouse, un dito solo (il pinch a due dita è
    // gestito dallo zoom e non deve avviare un disegno).
    canvas.addEventListener('touchstart', (e) => {
      if (!canStartDrawing() || e.touches.length !== 1) return;
      e.preventDefault();
      const [x, y] = toCanvasCoords(e.touches[0], canvas);
      startX = x;
      startY = y;
      drawing = true;
    }, { passive: false });
    canvas.addEventListener('touchmove', (e) => {
      if (!drawing || e.touches.length !== 1) return;
      e.preventDefault();
      const [x, y] = toCanvasCoords(e.touches[0], canvas);
      previewRect(x, y);
    }, { passive: false });
    canvas.addEventListener('touchend', (e) => {
      if (!drawing) return;
      drawing = false;
      const t = e.changedTouches[0];
      const [rawX, rawY] = toCanvasCoords(t, canvas);
      finishDrawing(rawX, rawY);
    });
  })();

  // ---------- Tap/click su un box rilevato per selezionarlo ----------
  // Funziona in qualunque modalità (Annota o Sposta): un tocco/click senza
  // trascinamento apprezzabile dentro i confini di una regione la seleziona
  // (zoom automatico + evidenziazione), mentre un vero trascinamento (per
  // disegnare un'annotazione o spostare la vista) viene ignorato qui.
  (function setupRegionTap() {
    const canvas = $('#annotate-canvas');
    if (!canvas) return;
    let startClientX = 0, startClientY = 0;
    const TAP_THRESHOLD = 6;

    function hitTestRegion(clientX, clientY) {
      if (!state.currentComparison || state.currentView === 'swipe') return null;
      const img = $('#stage-img');
      if (!img.naturalWidth) return null;
      const rect = canvas.getBoundingClientRect();
      const natX = ((clientX - rect.left) / rect.width) * img.naturalWidth;
      const natY = ((clientY - rect.top) / rect.height) * img.naturalHeight;
      const regions = state.currentComparison.regions;
      for (let i = 0; i < regions.length; i++) {
        const r = regions[i];
        if (natX >= r.x && natX <= r.x + r.w && natY >= r.y && natY <= r.y + r.h) return i;
      }
      return null;
    }

    canvas.addEventListener('mousedown', (e) => {
      startClientX = e.clientX;
      startClientY = e.clientY;
    });
    canvas.addEventListener('click', (e) => {
      if (Math.hypot(e.clientX - startClientX, e.clientY - startClientY) > TAP_THRESHOLD) return;
      const idx = hitTestRegion(e.clientX, e.clientY);
      if (idx !== null) selectRegion(idx);
    });
    canvas.addEventListener('touchstart', (e) => {
      if (e.touches.length !== 1) return;
      startClientX = e.touches[0].clientX;
      startClientY = e.touches[0].clientY;
    }, { passive: true });
    canvas.addEventListener('touchend', (e) => {
      if (e.changedTouches.length !== 1 || e.touches.length !== 0) return;
      const t = e.changedTouches[0];
      if (Math.hypot(t.clientX - startClientX, t.clientY - startClientY) > TAP_THRESHOLD) return;
      const idx = hitTestRegion(t.clientX, t.clientY);
      if (idx !== null) selectRegion(idx);
    });
  })();

  // ---------- Miglioramento immagine singola (nessun confronto) ----------
  (function setupEnhancePanel() {
    const panel = $('#enhance-panel');
    if (!panel) return;

    let currentCaptureId = null;
    let currentOriginalUrl = null;
    let lastPreviewPath = null;
    let lastSteps = null;

    window.openEnhancePanel = function (captureId, imgUrl, label) {
      currentCaptureId = captureId;
      currentOriginalUrl = imgUrl;
      lastPreviewPath = null;
      $('#enhance-target-label').textContent = label || ('ripresa #' + captureId);
      $('#enhance-preview-row').style.display = 'none';
      $('#enhance-status').textContent = '';
      panel.style.display = '';
      panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    $('#enhance-close-btn').addEventListener('click', () => {
      panel.style.display = 'none';
    });

    const ehRangeBindings = [
      ['eh-gamma', 'eh-val-gamma'],
      ['eh-sharpen-amount', 'eh-val-sharpen'],
      ['eh-desaturate-amount', 'eh-val-desaturate'],
    ];
    ehRangeBindings.forEach(([inputId, outId]) => {
      const input = $('#' + inputId);
      const out = $('#' + outId);
      if (input && out) input.addEventListener('input', () => (out.textContent = input.value));
    });

    function buildEnhanceSteps() {
      return {
        white_balance: $('#eh-wb').checked,
        denoise: $('#eh-denoise').checked,
        denoise_method: $('#eh-denoise-method').value,
        denoise_strength: $('#eh-denoise-strength').value,
        clahe: $('#eh-clahe').checked,
        hist_eq: $('#eh-hist-eq').checked,
        gamma_enabled: $('#eh-gamma-enabled').checked,
        gamma: $('#eh-gamma').value,
        sharpen: $('#eh-sharpen').checked,
        sharpen_amount: $('#eh-sharpen-amount').value,
        desaturate: $('#eh-desaturate').checked,
        desaturate_amount: $('#eh-desaturate-amount').value,
      };
    }

    // Stessa logica di build_enhance_steps lato server (api/compare.php),
    // riprodotta qui perché l'endpoint /analysis/enhance del servizio Python
    // si aspetta la lista già pronta, non l'oggetto con le sole spunte.
    function stepsToPipeline(opts) {
      const steps = [];
      if (opts.white_balance) steps.push({ filter: 'white_balance', params: {} });
      if (opts.denoise) steps.push({ filter: 'denoise', params: { method: opts.denoise_method, strength: parseInt(opts.denoise_strength, 10) } });
      if (opts.clahe) steps.push({ filter: 'clahe', params: {} });
      if (opts.hist_eq) steps.push({ filter: 'histogram_equalization', params: {} });
      if (opts.gamma_enabled) steps.push({ filter: 'gamma', params: { gamma: parseFloat(opts.gamma) } });
      if (opts.sharpen) steps.push({ filter: 'sharpen', params: { amount: parseFloat(opts.sharpen_amount) } });
      if (opts.desaturate) steps.push({ filter: 'desaturate', params: { amount: parseFloat(opts.desaturate_amount) } });
      return steps;
    }

    $('#enhance-apply-btn').addEventListener('click', async () => {
      if (!currentCaptureId) return;
      const status = $('#enhance-status');
      const opts = buildEnhanceSteps();
      const steps = stepsToPipeline(opts);
      if (!steps.length) {
        status.textContent = 'Seleziona almeno un filtro.';
        return;
      }
      status.textContent = 'Elaborazione in corso...';
      try {
        const res = await fetch('api/enhance_capture.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ capture_id: currentCaptureId, steps, preview: true }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Errore');
        lastPreviewPath = data.relative_path;
        lastSteps = steps;
        $('#enhance-img-before').src = currentOriginalUrl;
        $('#enhance-img-after').src = data.url + '&_=' + Date.now(); // evita cache su rielaborazioni successive
        $('#enhance-preview-row').style.display = '';
        status.textContent = 'Anteprima generata. Se ti convince, salvala come nuova ripresa.';
      } catch (err) {
        status.textContent = 'Errore: ' + err.message;
      }
    });

    $('#enhance-save-btn').addEventListener('click', async () => {
      if (!lastPreviewPath) return;
      const status = $('#enhance-status');
      status.textContent = 'Salvataggio in corso...';
      try {
        const res = await fetch('api/save_enhanced_capture.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            source_capture_id: currentCaptureId,
            relative_path: lastPreviewPath,
            label: $('#enhance-save-label').value,
            steps: lastSteps,
          }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Errore');
        status.textContent = 'Salvata come nuova ripresa. Ricarico la pagina...';
        setTimeout(() => window.location.reload(), 500);
      } catch (err) {
        status.textContent = 'Errore: ' + err.message;
      }
    });
  })();

  // ---------- Indici spettrali (NDVI / falso colore infrarosso) ----------
  // Disponibili solo per riprese Sentinel Hub scaricate con la banda NIR in
  // coppia (vedi fetch_red_nir lato servizio Python); i pulsanti sulla scheda
  // ripresa compaiono solo quando meta_json.nir_relative_path è presente.
  (function setupSpectralPanel() {
    const panel = $('#spectral-panel');
    if (!panel) return;

    const TITLES = { ndvi: 'NDVI (vigore vegetazione)', false_color_ir: 'Falso colore infrarosso' };
    const HINTS = {
      ndvi: 'Verde = vegetazione densa/in salute, giallo/bruno = vegetazione scarsa o assente (suolo nudo, superfici artificiali, acqua).',
      false_color_ir: 'La vegetazione viva appare in rosso acceso (riflette molto il vicino infrarosso): utile per distinguere vegetazione reale da superfici solo apparentemente verdi, o viceversa.',
    };

    window.openSpectralPanel = async function (captureId, mode, label) {
      const capture = window.ORBITALEYE.captures.find((c) => c.id === captureId);
      $('#spectral-title').textContent = TITLES[mode] || 'Indice spettrale';
      $('#spectral-hint').textContent = HINTS[mode] || '';
      $('#spectral-result-title').textContent = (TITLES[mode] || 'Risultato') + ' — ' + (label || ('ripresa #' + captureId));
      $('#spectral-img-before').src = capture ? window.ORBITALEYE.mediaBase + encodeURIComponent(capture.relative_path) : '';
      $('#spectral-img-after').src = '';
      $('#spectral-save-label').value = '';
      $('#spectral-status').textContent = 'Calcolo in corso...';
      panel.style.display = '';
      panel.scrollIntoView({ behavior: 'smooth', block: 'start' });

      panel.dataset.captureId = captureId;
      panel.dataset.mode = mode;
      panel.dataset.relativePath = '';

      try {
        const res = await fetch('api/spectral_view.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ capture_id: captureId, mode }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Errore');
        $('#spectral-img-after').src = data.url + '&_=' + Date.now();
        panel.dataset.relativePath = data.relative_path;
        $('#spectral-status').textContent = 'Fatto.';
      } catch (err) {
        $('#spectral-status').textContent = 'Errore: ' + err.message;
      }
    };

    $('#spectral-close-btn').addEventListener('click', () => { panel.style.display = 'none'; });

    $('#spectral-save-btn').addEventListener('click', async () => {
      const relativePath = panel.dataset.relativePath;
      const captureId = parseInt(panel.dataset.captureId, 10);
      if (!relativePath || !captureId) return;
      const status = $('#spectral-status');
      status.textContent = 'Salvataggio in corso...';
      try {
        const res = await fetch('api/save_enhanced_capture.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            source_capture_id: captureId,
            relative_path: relativePath,
            label: $('#spectral-save-label').value || (TITLES[panel.dataset.mode] || 'Indice spettrale'),
            steps: [{ filter: panel.dataset.mode, params: {} }],
          }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Errore');
        status.textContent = 'Salvata come nuova ripresa. Ricarico la pagina...';
        setTimeout(() => window.location.reload(), 500);
      } catch (err) {
        status.textContent = 'Errore: ' + err.message;
      }
    });
  })();

  // ---------- Editor punti di controllo (allineamento manuale) ----------
  // Fallback assistito per quando il motore automatico (ORB+ECC) non trova
  // corrispondenze affidabili tra le due riprese, tipicamente tra fonti
  // visivamente molto diverse (es. Esri World Imagery vs Sentinel Hub).
  // I punti sono legati alla COPPIA di riprese selezionata, non al singolo
  // confronto: restano salvati e riutilizzabili finché non li modifichi.
  (function setupControlPointsEditor() {
    const panel = $('#control-points-panel');
    if (!panel) return;

    const POINT_COLORS = ['#00fff2', '#ff5f5f', '#ffd23f', '#7cff5f', '#c77dff', '#5fb8ff', '#ff8fd8', '#ffab5f'];

    let points = []; // [{ax, ay, bx, by}] in pixel immagine originale
    let draft = null; // {ax, ay} in attesa del punto corrispondente su B
    let natA = { w: 0, h: 0 };
    let natB = { w: 0, h: 0 };
    let cpMode = 'point'; // 'point' (clic piazza un punto) | 'pan' (trascina per spostare la vista)

    $$('.cp-mode-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        cpMode = btn.dataset.mode;
        $$('.cp-mode-btn').forEach((b) => b.classList.toggle('active', b === btn));
        ['a', 'b'].forEach((side) => {
          $('#cp-scroll-' + side).style.cursor = cpMode === 'pan' ? 'grab' : '';
        });
      });
    });

    // ---------- Pan a trascinamento (mouse + touch) sui contenitori scroll ----------
    // Attivo solo in modalità "Sposta": in modalità "Punto" lo scroll nativo
    // (barre di scorrimento / touch-scroll) resta comunque disponibile, ma il
    // trascinamento esplicito qui evita di dover cercare le scrollbar quando
    // si è ingranditi molto per posizionare un punto con precisione.
    function setupPan(side) {
      const scroll = $('#cp-scroll-' + side);
      let dragging = false;
      let startX = 0, startY = 0, startLeft = 0, startTop = 0;

      scroll.addEventListener('mousedown', (e) => {
        if (cpMode !== 'pan') return;
        dragging = true;
        startX = e.clientX; startY = e.clientY;
        startLeft = scroll.scrollLeft; startTop = scroll.scrollTop;
        scroll.style.cursor = 'grabbing';
        e.preventDefault();
      });
      window.addEventListener('mousemove', (e) => {
        if (!dragging) return;
        scroll.scrollLeft = startLeft - (e.clientX - startX);
        scroll.scrollTop = startTop - (e.clientY - startY);
      });
      window.addEventListener('mouseup', () => {
        if (!dragging) return;
        dragging = false;
        scroll.style.cursor = 'grab';
      });

      scroll.addEventListener('touchstart', (e) => {
        if (cpMode !== 'pan' || e.touches.length !== 1) return;
        dragging = true;
        const t = e.touches[0];
        startX = t.clientX; startY = t.clientY;
        startLeft = scroll.scrollLeft; startTop = scroll.scrollTop;
      }, { passive: true });
      scroll.addEventListener('touchmove', (e) => {
        if (!dragging || e.touches.length !== 1) return;
        e.preventDefault();
        const t = e.touches[0];
        scroll.scrollLeft = startLeft - (t.clientX - startX);
        scroll.scrollTop = startTop - (t.clientY - startY);
      }, { passive: false });
      scroll.addEventListener('touchend', () => { dragging = false; });
    }
    setupPan('a');
    setupPan('b');

    const alignModeBtns = $$('.align-mode-btn');
    const manualBlock = $('#manual-align-block');
    const alignStatus = $('#manual-align-status');
    const alignRow = $('#opt-align-row');

    alignModeBtns.forEach((btn) => {
      btn.addEventListener('click', () => {
        state.alignMode = btn.dataset.mode;
        alignModeBtns.forEach((b) => b.classList.toggle('active', b === btn));
        if (alignRow) alignRow.style.display = state.alignMode === 'manual' ? 'none' : '';
        manualBlock.style.display = state.alignMode === 'manual' ? '' : 'none';
        if (state.alignMode === 'manual') loadPointsForPair();
      });
    });

    // Chiamato da refreshSelectionUI() ad ogni cambio di selezione A/B, così
    // lo stato mostrato resta coerente con la coppia effettivamente scelta
    // (i punti sono legati alla coppia, non al confronto).
    window.refreshManualAlignStatus = function () {
      if (!state.selectedA || !state.selectedB) {
        state.manualControlPoints = [];
        updateStatusText();
        return;
      }
      loadPointsForPair();
    };

    async function loadPointsForPair() {
      if (!state.selectedA || !state.selectedB) return;
      try {
        const res = await fetch(`api/control_points.php?capture_a_id=${state.selectedA}&capture_b_id=${state.selectedB}`);
        const data = await res.json();
        state.manualControlPoints = Array.isArray(data.points) ? data.points : [];
      } catch (e) {
        state.manualControlPoints = [];
      }
      updateStatusText();
    }

    function updateStatusText() {
      if (!alignStatus) return;
      const n = state.manualControlPoints.length;
      if (n === 0) {
        alignStatus.textContent = 'Nessun punto salvato per questa coppia di riprese.';
      } else {
        alignStatus.textContent = n + (n === 1 ? ' punto di controllo salvato' : ' punti di controllo salvati') + ' per questa coppia.';
      }
    }

    function captureUrl(id) {
      const c = window.ORBITALEYE.captures.find((cap) => cap.id === id);
      return c ? window.ORBITALEYE.mediaBase + encodeURIComponent(c.relative_path) : null;
    }

    const openBtn = $('#open-control-points-btn');
    if (openBtn) {
      openBtn.addEventListener('click', () => {
        if (!state.selectedA || !state.selectedB) return;
        openEditor();
      });
    }

    function openEditor() {
      points = (state.manualControlPoints || []).map((p) => ({ ax: p.ax, ay: p.ay, bx: p.bx, by: p.by }));
      draft = null;
      natA = { w: 0, h: 0 };
      natB = { w: 0, h: 0 };
      cpMode = 'point';
      $$('.cp-mode-btn').forEach((b) => b.classList.toggle('active', b.dataset.mode === 'point'));
      ['a', 'b'].forEach((side) => { $('#cp-scroll-' + side).style.cursor = ''; });
      $('#cp-preview-row').style.display = 'none';
      $('#cp-status').textContent = '';

      const imgA = $('#cp-img-a');
      const imgB = $('#cp-img-b');
      imgA.onload = () => { natA = { w: imgA.naturalWidth, h: imgA.naturalHeight }; setZoom('a', 'fit'); };
      imgB.onload = () => { natB = { w: imgB.naturalWidth, h: imgB.naturalHeight }; setZoom('b', 'fit'); };
      imgA.src = captureUrl(state.selectedA);
      imgB.src = captureUrl(state.selectedB);

      panel.style.display = '';
      panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    const closeBtn = $('#cp-close-btn');
    if (closeBtn) closeBtn.addEventListener('click', () => { panel.style.display = 'none'; });

    $$('.cp-zoom-btn').forEach((btn) => {
      btn.addEventListener('click', () => setZoom(btn.dataset.target, btn.dataset.zoom));
    });

    ['a', 'b'].forEach((side) => {
      const slider = $('#cp-zoom-slider-' + side);
      if (!slider) return;
      slider.addEventListener('input', () => setZoom(side, parseInt(slider.value, 10) / 100));
    });

    function setZoom(target, spec) {
      const img = $('#cp-img-' + target);
      const scroll = $('#cp-scroll-' + target);
      const nat = target === 'a' ? natA : natB;
      if (!nat.w) return;
      const factor = spec === 'fit' ? (Math.min(1, scroll.clientWidth / nat.w) || 1) : parseFloat(spec);
      img.style.width = Math.round(nat.w * factor) + 'px';
      img.style.height = Math.round(nat.h * factor) + 'px';
      $$('.cp-zoom-btn[data-target="' + target + '"]').forEach((b) => b.classList.toggle('active', b.dataset.zoom === spec));
      // Slider e pulsanti restano sincronizzati indipendentemente da quale dei
      // due ha innescato il cambio di zoom (slider trascinato o preset cliccato).
      const slider = $('#cp-zoom-slider-' + target);
      const label = $('#cp-zoom-val-' + target);
      const pct = Math.round(factor * 100);
      if (slider) slider.value = Math.min(800, Math.max(10, pct));
      if (label) label.textContent = pct + '%';
      renderMarkers();
    }

    function clientToNatural(img, nat, clientX, clientY) {
      const rect = img.getBoundingClientRect();
      const xFrac = (clientX - rect.left) / rect.width;
      const yFrac = (clientY - rect.top) / rect.height;
      return { x: xFrac * nat.w, y: yFrac * nat.h };
    }

    function pairColor(index) {
      return POINT_COLORS[index % POINT_COLORS.length];
    }

    function addMarker(wrap, leftPct, topPct, label, color, dashed) {
      const m = document.createElement('div');
      m.className = 'cp-marker';
      m.style.cssText = 'position:absolute; left:' + leftPct + '%; top:' + topPct + '%; width:22px; height:22px; '
        + 'margin:-11px 0 0 -11px; border-radius:50%; border:2px ' + (dashed ? 'dashed' : 'solid') + ' ' + color + '; '
        + 'background:rgba(0,0,0,0.5); color:' + color + '; font-size:11px; font-weight:bold; display:flex; '
        + 'align-items:center; justify-content:center; pointer-events:none; z-index:5;';
      m.textContent = label;
      wrap.appendChild(m);
    }

    function renderMarkers() {
      ['a', 'b'].forEach((side) => {
        const wrap = $('#cp-wrap-' + side);
        wrap.querySelectorAll('.cp-marker').forEach((m) => m.remove());
        const nat = side === 'a' ? natA : natB;
        if (!nat.w) return;
        points.forEach((p, i) => {
          const x = side === 'a' ? p.ax : p.bx;
          const y = side === 'a' ? p.ay : p.by;
          addMarker(wrap, (x / nat.w) * 100, (y / nat.h) * 100, i + 1, pairColor(i));
        });
        if (draft && side === 'a') {
          addMarker(wrap, (draft.ax / nat.w) * 100, (draft.ay / nat.h) * 100, '…', '#ffffff', true);
        }
      });
    }

    $('#cp-img-a').addEventListener('click', (e) => {
      if (!natA.w || cpMode !== 'point') return;
      const p = clientToNatural(e.target, natA, e.clientX, e.clientY);
      draft = { ax: p.x, ay: p.y };
      $('#cp-status').textContent = 'Punto impostato su A — ora clicca il punto corrispondente su B.';
      renderMarkers();
    });

    $('#cp-img-b').addEventListener('click', (e) => {
      if (!natB.w || cpMode !== 'point') return;
      if (!draft) {
        $('#cp-status').textContent = 'Clicca prima il punto su A, poi quello corrispondente qui su B.';
        return;
      }
      const p = clientToNatural(e.target, natB, e.clientX, e.clientY);
      points.push({ ax: draft.ax, ay: draft.ay, bx: p.x, by: p.y });
      draft = null;
      $('#cp-status').textContent = 'Punto ' + points.length + ' aggiunto. Clicca su A per aggiungerne un altro (minimo 3 per salvare).';
      renderMarkers();
    });

    $('#cp-undo-btn').addEventListener('click', () => {
      if (draft) {
        draft = null;
        $('#cp-status').textContent = 'Punto in sospeso su A annullato.';
      } else {
        points.pop();
        $('#cp-status').textContent = 'Ultima coppia di punti annullata.';
      }
      renderMarkers();
    });

    $('#cp-clear-btn').addEventListener('click', () => {
      points = [];
      draft = null;
      renderMarkers();
      $('#cp-status').textContent = 'Tutti i punti cancellati (non ancora salvato: chiudi senza salvare per annullare).';
    });

    $('#cp-preview-btn').addEventListener('click', async () => {
      if (points.length < 3) {
        $('#cp-status').textContent = "Servono almeno 3 punti per generare un'anteprima.";
        return;
      }
      $('#cp-status').textContent = 'Calcolo anteprima allineamento...';
      try {
        const res = await fetch('api/register_manual_preview.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ capture_a_id: state.selectedA, capture_b_id: state.selectedB, points }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Errore');
        $('#cp-preview-aligned').src = data.urls.aligned_b + '&_=' + Date.now();
        $('#cp-preview-blend').src = data.urls.blend + '&_=' + Date.now();
        $('#cp-preview-row').style.display = '';
        $('#cp-status').textContent = 'Anteprima generata (' + data.method + '). Se i contorni nel blend sono nitidi senza sdoppiamenti, l\'allineamento è buono.';
      } catch (err) {
        $('#cp-status').textContent = 'Errore: ' + err.message;
      }
    });

    $('#cp-save-btn').addEventListener('click', async () => {
      if (points.length < 3) {
        $('#cp-status').textContent = 'Servono almeno 3 punti per salvare.';
        return;
      }
      $('#cp-status').textContent = 'Salvataggio in corso...';
      try {
        const res = await fetch('api/control_points.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ capture_a_id: state.selectedA, capture_b_id: state.selectedB, points }),
        });
        const data = await res.json();
        if (!res.ok) throw new Error(data.error || 'Errore');
        state.manualControlPoints = points.slice();
        updateStatusText();
        $('#cp-status').textContent = 'Salvati ' + data.count + ' punti di controllo per questa coppia di riprese.';
      } catch (err) {
        $('#cp-status').textContent = 'Errore: ' + err.message;
      }
    });
  })();

  refreshSelectionUI();

  const urlParams = new URLSearchParams(window.location.search);
  const cmpParam = urlParams.get('comparison');
  if (cmpParam) {
    window.loadComparison(parseInt(cmpParam, 10));
  }
})();
