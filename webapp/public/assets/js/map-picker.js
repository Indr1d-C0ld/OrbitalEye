/**
 * Selettore visuale dell'area di interesse (bounding box) basato su
 * Leaflet. Basemap di navigazione: Esri World Street Map — stessa scelta
 * già fatta in milair_ita/flight_anom (i tile OSM anonimi vanno spesso in
 * rate-limit/blocco anti-abuso su uso non banale, e i tile CARTO anonimi
 * richiedono ormai una API key; Esri World Street Map non serve chiave per
 * uso leggero come questo). Nota: l'URL Esri usa l'ordine {z}/{y}/{x}, non
 * {z}/{x}/{y} come lo schema XYZ standard. È disponibile anche un basemap
 * satellitare (Esri World Imagery, live tile "as-is" per il solo
 * riconoscimento visivo dell'area — il download vero e proprio della
 * ripresa avviene poi via l'endpoint ufficiale /export sul servizio
 * Python, non dalle tile qui mostrate).
 *
 * initMapPicker(mapDivId, fields, toggles) ->
 *   fields:  { minLon, minLat, maxLon, maxLat }  id degli <input> da sincronizzare
 *   toggles: { draw, pan, mapView, satView,
 *              rotation, rotationOut, rotationReset }  id dei pulsanti di
 *              modalità/basemap e (opzionali) dello slider di rotazione
 *              dell'area, della sua etichetta e del pulsante di reset;
 *              defaultSizeKm: lato dell'area creata da un solo punto quando
 *              non ce n'è ancora una (predefinito 2 km)
 *
 * Coordinate in qualunque formato (coords.js): ogni campo accetta gradi
 * decimali, gradi-primi-secondi, UTM, MGRS, link delle mappe… e viene
 * riscritto in gradi decimali; incollata in un campo, una coppia (o un
 * punto UTM/MGRS) riempie l'angolo — Min o Max — di quel campo. Sotto i
 * campi, "Centro": un solo punto che diventa il centro dell'area, con le
 * dimensioni di quella attuale (o il lato indicato); i quattro campi min/max
 * restano quelli usati per il download.
 *
 * Rotazione dell'area: i 4 campi lon/lat continuano a rappresentare il
 * rettangolo "di base" così come disegnato (non ruotato) — è il contratto
 * già usato da tutto il resto della piattaforma (validazione server,
 * calcolo scala, ecc.) e non va toccato. La rotazione è un valore in più,
 * puramente visuale qui sulla mappa (fa vedere all'utente esattamente quale
 * area verrà ritagliata), inviato al server in un campo separato: è lì
 * (fetch_capture.php + ImageRotateCrop.php) che viene effettivamente
 * applicata scaricando un'area di raccolta più ampia e ritagliando poi la
 * ripresa ruotata.
 */
/** Form → normalizzatore dei campi dell'area (vedi normalizeAreaFields). */
const MAP_PICKER_FORMS = new Map();

/**
 * Riscrive in gradi decimali i campi dell'area di un form, prima di usarli
 * (download, catalogo, pianificazioni). @return {string|null} errore
 */
function normalizeAreaFields(form) {
  const fn = MAP_PICKER_FORMS.get(form);
  return fn ? fn() : null;
}

function initMapPicker(mapDivId, fields, toggles) {
  const mapDiv = document.getElementById(mapDivId);
  if (!mapDiv || typeof L === 'undefined') return null;

  const map = L.map(mapDivId, { attributionControl: true }).setView([41.9, 12.5], 5);

  const osmLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}', {
    attribution: 'Tiles &copy; Esri &mdash; Source: Esri, DeLorme, NAVTEQ',
    maxZoom: 19,
  });
  const satLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    attribution: 'Tiles &copy; Esri — Source: Esri, Maxar, Earthstar Geographics',
    maxZoom: 19,
  });
  osmLayer.addTo(map);
  let currentBase = 'osm';

  // ---------- Ricerca luogo (Nominatim/OpenStreetMap) ----------
  // Solo su azione esplicita dell'analista (Invio o clic sulla lente), mai
  // "mentre digiti": Nominatim è gratuito ma con policy d'uso rigida (max
  // 1 richiesta/secondo, solo uso leggero) — un suggerimento live ad ogni
  // carattere la violerebbe. Sposta/inquadra solo la mappa: disegnare
  // l'area resta un gesto separato e volontario come sempre (modalità
  // "Disegna area"), niente rettangolo indovinato automaticamente.
  const SearchControl = L.Control.extend({
    options: { position: 'topright' },
    onAdd() {
      const container = L.DomUtil.create('div', 'map-search-control');
      container.innerHTML =
        '<div class="map-search-row">' +
        '<input type="text" class="map-search-input" placeholder="Cerca un luogo…">' +
        '<button type="button" class="map-search-btn" title="Cerca">🔍</button>' +
        '</div><div class="map-search-results" style="display:none;"></div>';
      L.DomEvent.disableClickPropagation(container);
      L.DomEvent.disableScrollPropagation(container);

      const input = container.querySelector('.map-search-input');
      const results = container.querySelector('.map-search-results');

      function showMessage(text) {
        results.innerHTML = '<div class="map-search-result hint">' + text + '</div>';
        results.style.display = '';
      }

      async function runSearch() {
        const q = input.value.trim();
        if (!q) return;
        showMessage('Ricerca in corso…');
        try {
          const res = await fetch('https://nominatim.openstreetmap.org/search?format=json&limit=6&q=' + encodeURIComponent(q));
          const data = await res.json();
          if (!Array.isArray(data) || !data.length) { showMessage('Nessun risultato.'); return; }
          results.innerHTML = '';
          data.forEach((r) => {
            const item = L.DomUtil.create('div', 'map-search-result', results);
            item.textContent = r.display_name;
            item.title = r.display_name;
            item.addEventListener('click', () => {
              const bb = (r.boundingbox || []).map(Number);
              if (bb.length === 4) {
                map.fitBounds([[bb[0], bb[2]], [bb[1], bb[3]]], { maxZoom: 15 });
              } else {
                map.setView([parseFloat(r.lat), parseFloat(r.lon)], 13);
              }
              results.style.display = 'none';
            });
          });
          const attrib = L.DomUtil.create('div', 'map-search-result hint', results);
          attrib.textContent = 'Dati © contributori OpenStreetMap';
          attrib.style.cursor = 'default';
        } catch (e) {
          showMessage('Ricerca non riuscita.');
        }
      }

      container.querySelector('.map-search-btn').addEventListener('click', runSearch);
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); runSearch(); }
      });
      return container;
    },
  });
  map.addControl(new SearchControl());

  let rectLayer = null;
  let drawing = false;
  let startLatLng = null;
  let drawMode = false;
  let rotationDeg = 0;

  function fieldEl(key) { return document.getElementById(fields[key]); }

  // Calcola i 4 angoli del rettangolo "di base" ruotati di angleDeg attorno
  // al proprio centro, lavorando in coordinate schermo (proiezione Leaflet
  // a uno zoom di riferimento) e non in lon/lat grezze: così la rotazione
  // appare visivamente corretta a qualunque latitudine/zoom, esattamente
  // come l'utente se l'aspetta trascinando lo slider. Convenzione: positivo
  // = orario, stessa di rotate() CSS/canvas già usata per l'overlay in
  // analyze.js (coerenza tra le rotazioni mostrate nella piattaforma).
  function rotatedCorners(bounds, angleDeg) {
    if (!angleDeg) return null;
    const zoom = map.getZoom();
    const nw = map.project(bounds.getNorthWest(), zoom);
    const se = map.project(bounds.getSouthEast(), zoom);
    const center = L.point((nw.x + se.x) / 2, (nw.y + se.y) / 2);
    const rad = (angleDeg * Math.PI) / 180;
    const rotatePoint = (p) => {
      const dx = p.x - center.x, dy = p.y - center.y;
      return L.point(
        center.x + dx * Math.cos(rad) - dy * Math.sin(rad),
        center.y + dx * Math.sin(rad) + dy * Math.cos(rad)
      );
    };
    return [
      L.point(nw.x, nw.y), L.point(se.x, nw.y), L.point(se.x, se.y), L.point(nw.x, se.y),
    ].map((p) => map.unproject(rotatePoint(p), zoom));
  }

  function paintRect(bounds) {
    if (rectLayer) map.removeLayer(rectLayer);
    const corners = rotatedCorners(bounds, rotationDeg);
    rectLayer = corners
      ? L.polygon(corners, { color: '#00fff2', weight: 2, fillOpacity: 0.08 }).addTo(map)
      : L.rectangle(bounds, { color: '#00fff2', weight: 2, fillOpacity: 0.08 }).addTo(map);
  }

  // Leaflet non riporta le longitudini in [-180, 180] quando si disegna su
  // una "copia" del mondo (mappa fatta scorrere oltre il Pacifico): i campi
  // ricevevano valori come 190.12, poi rifiutati al download. Il rettangolo
  // viene spostato di multipli interi di 360° in base al suo centro, così
  // resta intero; uno che attraversa davvero l'antimeridiano non è
  // supportato e verrà segnalato come fuori range dal server.
  function normalizedBounds(a, b) {
    const centerLng = (a.lng + b.lng) / 2;
    const shift = Math.round(centerLng / 360) * 360;
    return L.latLngBounds(L.latLng(a.lat, a.lng - shift), L.latLng(b.lat, b.lng - shift));
  }

  function boundsToFields(bounds) {
    fieldEl('minLon').value = bounds.getWest().toFixed(6);
    fieldEl('minLat').value = bounds.getSouth().toFixed(6);
    fieldEl('maxLon').value = bounds.getEast().toFixed(6);
    fieldEl('maxLat').value = bounds.getNorth().toFixed(6);
  }

  function fieldsToBounds() {
    const minLon = parseFloat(fieldEl('minLon').value);
    const minLat = parseFloat(fieldEl('minLat').value);
    const maxLon = parseFloat(fieldEl('maxLon').value);
    const maxLat = parseFloat(fieldEl('maxLat').value);
    if ([minLon, minLat, maxLon, maxLat].some((v) => isNaN(v))) return null;
    return L.latLngBounds([minLat, minLon], [maxLat, maxLon]);
  }

  function setDrawMode(on) {
    drawMode = on;
    if (on) {
      map.dragging.disable();
      mapDiv.style.cursor = 'crosshair';
    } else {
      map.dragging.enable();
      mapDiv.style.cursor = '';
    }
    const drawBtn = document.getElementById(toggles.draw);
    const panBtn = document.getElementById(toggles.pan);
    if (drawBtn) drawBtn.classList.toggle('active', on);
    if (panBtn) panBtn.classList.toggle('active', !on);
  }

  const drawBtn = document.getElementById(toggles.draw);
  const panBtn = document.getElementById(toggles.pan);
  if (drawBtn) drawBtn.addEventListener('click', () => setDrawMode(true));
  if (panBtn) panBtn.addEventListener('click', () => setDrawMode(false));

  const mapViewBtn = document.getElementById(toggles.mapView);
  const satViewBtn = document.getElementById(toggles.satView);
  function setBasemap(which) {
    if (which === currentBase) return;
    map.removeLayer(currentBase === 'osm' ? osmLayer : satLayer);
    (which === 'osm' ? osmLayer : satLayer).addTo(map);
    currentBase = which;
    if (mapViewBtn) mapViewBtn.classList.toggle('active', which === 'osm');
    if (satViewBtn) satViewBtn.classList.toggle('active', which === 'sat');
  }
  if (mapViewBtn) mapViewBtn.addEventListener('click', () => setBasemap('osm'));
  if (satViewBtn) satViewBtn.addEventListener('click', () => setBasemap('sat'));

  // Rotazione area (opzionale: solo se il chiamante ha passato gli id in
  // toggles). Lo slider di rotazione ha il proprio attributo name="rotation"
  // nell'HTML del form: study.js lo legge da FormData come gli altri campi,
  // qui serve solo per ridisegnare l'anteprima quando cambia.
  const rotationInput = toggles.rotation ? document.getElementById(toggles.rotation) : null;
  const rotationOut = toggles.rotationOut ? document.getElementById(toggles.rotationOut) : null;
  const rotationResetBtn = toggles.rotationReset ? document.getElementById(toggles.rotationReset) : null;
  function repaintFromFields() {
    const b = fieldsToBounds();
    if (b) paintRect(b);
  }
  if (rotationInput) {
    rotationDeg = parseFloat(rotationInput.value) || 0;
    // Il browser può ripristinare il valore dello slider ricaricando la
    // pagina: l'etichetta va allineata subito, altrimenti mostrava "0°"
    // mentre anteprima e download usavano l'angolo ripristinato.
    if (rotationOut) rotationOut.textContent = Math.round(rotationDeg) + '°';
    rotationInput.addEventListener('input', () => {
      rotationDeg = parseFloat(rotationInput.value) || 0;
      if (rotationOut) rotationOut.textContent = Math.round(rotationDeg) + '°';
      repaintFromFields();
    });
  }
  if (rotationResetBtn) {
    rotationResetBtn.addEventListener('click', () => {
      rotationDeg = 0;
      if (rotationInput) rotationInput.value = 0;
      if (rotationOut) rotationOut.textContent = '0°';
      repaintFromFields();
    });
  }

  map.on('mousedown', (e) => {
    if (!drawMode) return;
    drawing = true;
    startLatLng = e.latlng;
  });
  map.on('mousemove', (e) => {
    if (!drawing) return;
    const bounds = normalizedBounds(startLatLng, e.latlng);
    paintRect(bounds);
    boundsToFields(bounds);
  });
  map.on('mouseup', () => {
    if (drawing) afterDraw();
    drawing = false;
  });

  // Touch: un dito disegna l'area (solo in modalità "Disegna"); il pinch a
  // due dita resta gestito nativamente da Leaflet per lo zoom della mappa
  // (qui intercettiamo solo il tocco singolo, ignorando i multi-touch).
  // Ascoltiamo direttamente sul contenitore invece che su map.on(...): gli
  // eventi touch non vengono normalizzati automaticamente da Leaflet come i
  // 'mousedown/mousemove/mouseup' sintetici dei semplici tap.
  const mapContainer = map.getContainer();
  function touchToLatLng(touch) {
    const rect = mapContainer.getBoundingClientRect();
    return map.containerPointToLatLng(L.point(touch.clientX - rect.left, touch.clientY - rect.top));
  }
  mapContainer.addEventListener('touchstart', (e) => {
    if (!drawMode || e.touches.length !== 1) return;
    e.preventDefault();
    drawing = true;
    startLatLng = touchToLatLng(e.touches[0]);
  }, { passive: false });
  mapContainer.addEventListener('touchmove', (e) => {
    if (!drawing || e.touches.length !== 1) return;
    e.preventDefault();
    const bounds = normalizedBounds(startLatLng, touchToLatLng(e.touches[0]));
    paintRect(bounds);
    boundsToFields(bounds);
  }, { passive: false });
  mapContainer.addEventListener('touchend', () => {
    if (drawing) afterDraw();
    drawing = false;
  });

  // Area disegnata: niente più segno del centro scritto, e i campi del
  // centro mostrano quella nuova.
  function afterDraw() {
    if (centerMarker) { map.removeLayer(centerMarker); centerMarker = null; }
    sizeEdited = false;
    ['minLon', 'minLat', 'maxLon', 'maxLat'].forEach((k) => markField(k, false));
    say('');
    syncCenterFromFields();
  }

  // ---------- Coordinate in qualunque formato e centro da un punto ----------
  const AXIS = { minLon: 'lon', minLat: 'lat', maxLon: 'lon', maxLat: 'lat' };
  const LABEL = { minLon: 'Min Lon', minLat: 'Min Lat', maxLon: 'Max Lon', maxLat: 'Max Lat' };
  const grid = fieldEl('minLon').closest('.grid') || fieldEl('minLon').parentNode;
  const centerBox = document.createElement('div');
  centerBox.className = 'field coord-center';
  centerBox.innerHTML =
    '<label>Centro dell\'area (un solo punto) <span class="info-tip" tabindex="0" data-tip="' +
    'Scrivi o incolla un punto: diventa il centro dell\'area, con le dimensioni di quella attuale o con il lato indicato qui accanto. ' +
    'I campi Min/Max qui sopra vengono ricalcolati e restano modificabili. Formati: gradi decimali (anche con la virgola), ' +
    'gradi°primi\'secondi&quot; o gradi e primi decimali, con N/S/E/W (O = ovest) o il segno; coppie lat, lon; UTM (33S 495000 4140000); ' +
    'MGRS (33SVB1234567890, centro del quadrato); link di Google Maps, OpenStreetMap, Bing o geo:. Gli stessi formati valgono nei quattro campi, ' +
    'dove una coppia incollata riempie l\'angolo (Min o Max) del campo.">?</span></label>' +
    '<div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">' +
    '<input type="text" class="coord-center-input" style="flex:1 1 260px; min-width:0;" autocomplete="off" spellcheck="false" ' +
    'placeholder="es. 37.4077, 14.9129 · 37°24\'28&quot;N 14°54\'46&quot;E · 33S VB 12345 67890 · link di Google Maps">' +
    '<label class="hint" style="display:flex; gap:6px; align-items:center; margin:0;" title="Lato dell\'area in km. Lascialo com\'è per tenere le dimensioni dell\'area attuale; cambialo per un\'area quadrata di questo lato.">' +
    'Lato <input type="number" class="coord-center-size" min="0.05" max="500" step="any" style="width:80px;"> km</label>' +
    '<button type="button" class="btn btn-sm coord-center-btn">⌖ Centra</button>' +
    '</div><div class="hint coord-feedback" style="min-height:1.2em;"></div>';
  grid.parentNode.insertBefore(centerBox, grid.nextSibling);
  const centerInput = centerBox.querySelector('.coord-center-input');
  const sizeInput = centerBox.querySelector('.coord-center-size');
  const feedback = centerBox.querySelector('.coord-feedback');
  const defaultSizeKm = toggles.defaultSizeKm || 2;
  let sizeEdited = false;
  let centerMarker = null;

  function say(text, isError) {
    feedback.textContent = text;
    feedback.style.color = isError ? 'var(--danger)' : '';
  }
  function markField(key, bad) {
    fieldEl(key).style.borderColor = bad ? 'var(--danger)' : '';
  }

  // Dimensioni e centro mostrati seguono l'area attuale (disegnata o scritta).
  function syncCenterFromFields() {
    const b = fieldsToBounds();
    if (!b) return;
    const size = OrbitalCoords.boxSize([b.getWest(), b.getSouth(), b.getEast(), b.getNorth()]);
    if (!sizeEdited) sizeInput.value = (size.width / 1000).toFixed(2);
    if (document.activeElement !== centerInput) {
      const c = b.getCenter();
      centerInput.value = c.lat.toFixed(6) + ', ' + c.lng.toFixed(6);
    }
  }
  sizeInput.addEventListener('input', () => { sizeEdited = true; });

  function setCorner(prefix, lat, lon) {
    fieldEl(prefix + 'Lat').value = lat.toFixed(6);
    fieldEl(prefix + 'Lon').value = lon.toFixed(6);
    markField(prefix + 'Lat', false);
    markField(prefix + 'Lon', false);
  }

  /** Un campo in gradi decimali. @return {string|null} errore */
  function normalizeField(key) {
    const el = fieldEl(key);
    const raw = el.value.trim();
    if (!raw) { markField(key, false); return null; }
    const r = OrbitalCoords.parseAxis(raw, AXIS[key]);
    if (!r.ok) {
      markField(key, true);
      return LABEL[key] + ': ' + r.error;
    }
    markField(key, false);
    if (r.point) {
      const prefix = key.slice(0, 3);
      setCorner(prefix, r.point.lat, r.point.lon);
      say((prefix === 'min' ? 'Angolo sud-ovest: ' : 'Angolo nord-est: ') + OrbitalCoords.format(r.point.lat, r.point.lon) + (r.note ? ' (' + r.note + ')' : ''));
      return null;
    }
    const text = String(Math.round(r.value * 1e6) / 1e6);
    if (text !== raw) {
      el.value = text;
      say(LABEL[key] + ': «' + raw + '» → ' + text);
    }
    return null;
  }

  function normalizeAll() {
    let first = null;
    ['minLon', 'minLat', 'maxLon', 'maxLat'].forEach((key) => {
      const err = normalizeField(key);
      if (err && !first) first = err;
    });
    if (first) say(first, true);
    return first;
  }

  function showBounds(fit) {
    const b = fieldsToBounds();
    if (!b) return;
    paintRect(b);
    if (fit) map.fitBounds(b, { maxZoom: 16, padding: [20, 20] });
    syncCenterFromFields();
  }

  // Digitando/incollando coordinate a mano, la mappa segue e mostra il rettangolo.
  ['minLon', 'minLat', 'maxLon', 'maxLat'].forEach((key) => {
    fieldEl(key).addEventListener('change', () => {
      const err = normalizeField(key);
      if (err) { say(err, true); return; }
      if (centerMarker) { map.removeLayer(centerMarker); centerMarker = null; }
      showBounds(false);
    });
  });

  function centerOnPoint() {
    const r = OrbitalCoords.parsePoint(centerInput.value);
    if (!r.ok) {
      say('Centro: ' + (r.error === 'vuoto' ? 'scrivi un punto' : r.error), true);
      centerInput.style.borderColor = 'var(--danger)';
      return;
    }
    centerInput.style.borderColor = '';
    // Dimensioni: il lato indicato (area quadrata), altrimenti quelle
    // dell'area attuale, altrimenti il lato predefinito della sezione.
    const current = fieldsToBounds();
    const side = parseFloat(sizeInput.value);
    let w, h;
    if (sizeEdited && side > 0) {
      w = h = side * 1000;
    } else if (current) {
      ({ width: w, height: h } = OrbitalCoords.boxSize([current.getWest(), current.getSouth(), current.getEast(), current.getNorth()]));
    } else {
      w = h = (side > 0 ? side : defaultSizeKm) * 1000;
    }
    const [minLon, minLat, maxLon, maxLat] = OrbitalCoords.boxAround(r.lat, r.lon, w, h);
    if (minLon < -180 || maxLon > 180) {
      say('Centro: l\'area attraverserebbe l\'antimeridiano (±180°), non supportato.', true);
      return;
    }
    setCorner('min', minLat, minLon);
    setCorner('max', maxLat, maxLon);
    sizeEdited = false;
    if (centerMarker) map.removeLayer(centerMarker);
    centerMarker = L.circleMarker([r.lat, r.lon], { radius: 4, color: '#00fff2', weight: 2, fillOpacity: 1 }).addTo(map);
    centerInput.value = r.lat.toFixed(6) + ', ' + r.lon.toFixed(6);
    showBounds(true);
    say('Centro ' + OrbitalCoords.format(r.lat, r.lon) + (r.format !== 'coordinate' ? ' — da ' + r.format : '') + (r.note ? ' (' + r.note + ')' : '')
      + ' · area ' + (w / 1000).toFixed(2).replace('.', ',') + ' × ' + (h / 1000).toFixed(2).replace('.', ',') + ' km');
  }
  centerBox.querySelector('.coord-center-btn').addEventListener('click', centerOnPoint);
  centerInput.addEventListener('keydown', (e) => {
    // Invio centra, senza inviare il form (che scaricherebbe la ripresa).
    if (e.key === 'Enter') { e.preventDefault(); centerOnPoint(); }
  });
  sizeInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); centerOnPoint(); }
  });

  // Prima di ogni invio i campi vanno in gradi decimali; con un errore il
  // form non parte (nuovo studio) o il messaggio resta visibile (download).
  const form = fieldEl('minLon').form;
  if (form) {
    MAP_PICKER_FORMS.set(form, normalizeAll);
    form.addEventListener('submit', (e) => {
      if (normalizeAll()) {
        e.preventDefault();
        e.stopImmediatePropagation();
      }
    }, true);
  }

  const initialBounds = fieldsToBounds();
  if (initialBounds) {
    paintRect(initialBounds);
    map.fitBounds(initialBounds, { maxZoom: 14, animate: false });
    syncCenterFromFields();
  } else {
    sizeInput.value = defaultSizeKm;
  }

  // Sposta mappa di default (non Disegna area): appena apri la sezione vuoi
  // prima esplorare/inquadrare la mappa, disegnare l'area è un'azione
  // volontaria successiva.
  setDrawMode(false);

  // Leaflet ha bisogno di conoscere le dimensioni reali del contenitore:
  // se il tab/pannello era nascosto al momento dell'init, ricalcola dopo che
  // diventa visibile.
  setTimeout(() => map.invalidateSize(), 150);

  return { map, setDrawMode, setBasemap, invalidateSize: () => map.invalidateSize() };
}
