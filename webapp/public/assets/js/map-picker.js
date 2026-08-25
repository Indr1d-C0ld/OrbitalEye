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
 *   toggles: { draw, pan, mapView, satView }      id dei pulsanti di modalità/basemap
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
  let drawMode = true;

  function fieldEl(key) { return document.getElementById(fields[key]); }

  function paintRect(bounds) {
    if (rectLayer) map.removeLayer(rectLayer);
    rectLayer = L.rectangle(bounds, { color: '#00fff2', weight: 2, fillOpacity: 0.08 }).addTo(map);
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

  setDrawMode(true);

  // Leaflet ha bisogno di conoscere le dimensioni reali del contenitore:
  // se il tab/pannello era nascosto al momento dell'init, ricalcola dopo che
  // diventa visibile.
  setTimeout(() => map.invalidateSize(), 150);

  return { map, setDrawMode, setBasemap, invalidateSize: () => map.invalidateSize() };
}
