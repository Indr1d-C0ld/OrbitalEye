/**
 * Selettore visuale dell'area di interesse (bounding box) basato su
 * Leaflet. Il basemap di navigazione è OpenStreetMap (uso standard,
 * conforme alla policy di utilizzo delle tile OSM: solo visualizzazione
 * interattiva in-browser, nessun download/archiviazione bulk). È
 * disponibile anche un basemap satellitare (Esri World Imagery, live tile
 * "as-is" per il solo riconoscimento visivo dell'area — il download vero e
 * proprio della ripresa avviene poi via l'endpoint ufficiale /export sul
 * servizio Python, non dalle tile qui mostrate).
 *
 * initMapPicker(mapDivId, fields, toggles) ->
 *   fields:  { minLon, minLat, maxLon, maxLat }  id degli <input> da sincronizzare
 *   toggles: { draw, pan, mapView, satView,
 *              rotation, rotationOut, rotationReset }  id dei pulsanti di
 *              modalità/basemap e (opzionali) dello slider di rotazione
 *              dell'area, della sua etichetta e del pulsante di reset
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
function initMapPicker(mapDivId, fields, toggles) {
  const mapDiv = document.getElementById(mapDivId);
  if (!mapDiv || typeof L === 'undefined') return null;

  const map = L.map(mapDivId, { attributionControl: true }).setView([41.9, 12.5], 5);

  const osmLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    maxZoom: 19,
  });
  const satLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
    attribution: 'Tiles &copy; Esri — Source: Esri, Maxar, Earthstar Geographics',
    maxZoom: 19,
  });
  osmLayer.addTo(map);
  let currentBase = 'osm';

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
    const bounds = L.latLngBounds(startLatLng, e.latlng);
    paintRect(bounds);
    boundsToFields(bounds);
  });
  map.on('mouseup', () => { drawing = false; });

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
    const bounds = L.latLngBounds(startLatLng, touchToLatLng(e.touches[0]));
    paintRect(bounds);
    boundsToFields(bounds);
  }, { passive: false });
  mapContainer.addEventListener('touchend', () => { drawing = false; });

  // Digitando/incollando coordinate a mano, la mappa segue e mostra il rettangolo.
  ['minLon', 'minLat', 'maxLon', 'maxLat'].forEach((key) => {
    fieldEl(key).addEventListener('change', () => {
      const bounds = fieldsToBounds();
      if (bounds) paintRect(bounds);
    });
  });

  const initialBounds = fieldsToBounds();
  if (initialBounds) {
    paintRect(initialBounds);
    map.fitBounds(initialBounds, { maxZoom: 14, animate: false });
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
