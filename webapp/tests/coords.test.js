// Riconoscimento delle coordinate (public/assets/js/coords.js).
// Uso: node --test webapp/tests/
const test = require('node:test');
const assert = require('node:assert');
const C = require('../public/assets/js/coords.js');

const near = (actual, expected, tol, msg) => assert.ok(Math.abs(actual - expected) <= tol, `${msg}: ${actual} invece di ${expected}`);

function point(text, lat, lon, tol = 1e-4) {
  const r = C.parsePoint(text);
  assert.ok(r.ok, `${text}: ${r.error}`);
  near(r.lat, lat, tol, `${text} lat`);
  near(r.lon, lon, tol, `${text} lon`);
  return r;
}

test('gradi decimali, virgola decimale, emisferi e segni', () => {
  for (const t of ['37.4077, 14.9129', '37,4077, 14,9129', '37,4077 14,9129', '37.4077 14.9129', '37.4077;14.9129',
    '37,4077,14,9129', 'N37.4077 E14.9129', '37.4077N 14.9129E', '14.9129E 37.4077N', 'lat: 37.4077 lon: 14.9129']) {
    point(t, 37.4077, 14.9129);
  }
  point('-33.8688, 151.2093', -33.8688, 151.2093);
  point('33.8688S 151.2093E', -33.8688, 151.2093);
  point('40.4461 N, 79.9822 O', 40.4461, -79.9822);
});

test('gradi, primi, secondi e gradi con primi decimali', () => {
  for (const t of [`37°24'27.6"N 14°54'46.4"E`, 'N 37° 24′ 27.6″, E 14° 54′ 46.4″', '37 24 27.6 N 14 54 46.4 E', `37º24'27.6''N, 14º54'46.4''E`]) {
    point(t, 37.407667, 14.912889);
  }
  point(`37°24.46'N 14°54.7733'E`, 37.407667, 14.912889);
  point(`40°26'46"N 79°58'56"W`, 40.446111, -79.982222);
  assert.ok(!C.parsePoint(`37°75'N 14°54'E`).ok, 'primi oltre 60');
});

test('UTM e MGRS (punti di riferimento noti)', () => {
  // Washington Monument: 38.8895 N, 77.0352 W.
  const m = point('18SUJ2348306479', 38.8895, -77.0352, 2e-4);
  assert.strictEqual(m.format, 'MGRS');
  point('18S UJ 23483 06479', 38.8895, -77.0352, 2e-4);
  // Torre Eiffel: 48.8583 N, 2.2945 E, in MGRS e in UTM.
  point('31U DQ 48251 11932', 48.8583, 2.2945, 2e-4);
  point('31U 448252 5411933', 48.8583, 2.2945, 2e-4);
  // "S" come fascia 32–40° nord, non come emisfero sud.
  const s = point('33S 495000 4140000', 37.4068, 14.9435, 1e-3);
  assert.strictEqual(s.format, 'UTM');
  // Emisfero sud con la sola lettera S: Sydney.
  point('56S 334369 6250948', -33.8688, 151.2093, 1e-3);
  assert.ok(!C.parsePoint('61S 495000 4140000').ok, 'zona inesistente');
});

test('link delle mappe', () => {
  point('https://www.google.com/maps/place/X/@37.4011,14.9223,15z/data=!3m1!4b1!4m6!3m5!1s0x0:0x0!8m2!3d37.4077!4d14.9129', 37.4077, 14.9129);
  point('https://www.google.com/maps/@37.4011,14.9223,15z', 37.4011, 14.9223);
  point('https://maps.google.com/?q=37.4077,14.9129', 37.4077, 14.9129);
  point('https://www.openstreetmap.org/?mlat=37.4077&mlon=14.9129#map=15/37.40/14.91', 37.4077, 14.9129);
  point('https://www.openstreetmap.org/#map=15/37.4077/14.9129', 37.4077, 14.9129);
  point('geo:37.4077,14.9129', 37.4077, 14.9129);
  assert.ok(!C.parsePoint('https://example.com/nulla').ok);
});

test('ordine lon, lat quando i valori lo dicono; errori chiari', () => {
  const r = point('151.2093, -33.8688', -33.8688, 151.2093);
  assert.ok(r.note.includes('longitudine'));
  assert.match(C.parsePoint('37.4077').error, /seconda coordinata/);
  assert.match(C.parsePoint('95, 200').error, /fuori range/);
  assert.match(C.parsePoint('37.4N 14.9N').error, /due latitudini/);
});

test('campi singoli: valore, coppia come angolo, asse sbagliato', () => {
  assert.deepStrictEqual(C.parseAxis('14.9129', 'lon'), { ok: true, value: 14.9129 });
  near(C.parseAxis(`37°24'27.6"N`, 'lat').value, 37.407667, 1e-5, 'DMS');
  near(C.parseAxis('14,9129', 'lon').value, 14.9129, 1e-9, 'virgola');
  near(C.parseAxis('W 79.98', 'lon').value, -79.98, 1e-9, 'ovest');
  assert.match(C.parseAxis(`14°54'46"E`, 'lat').error, /longitudine/);
  assert.match(C.parseAxis('91', 'lat').error, /fuori range/);
  const p = C.parseAxis('37.4, 14.9', 'lat');
  assert.deepStrictEqual(p.point, { lat: 37.4, lon: 14.9 });
  assert.ok(C.parseAxis('33S VB 12345 67890', 'lon').point, 'MGRS in un campo');
});

test('area centrata in un punto', () => {
  const b = C.boxAround(37.4077, 14.9129, 1000, 2000);
  near((b[0] + b[2]) / 2, 14.9129, 1e-9, 'centro lon');
  near((b[1] + b[3]) / 2, 37.4077, 1e-9, 'centro lat');
  const size = C.boxSize(b);
  near(size.width, 1000, 2, 'larghezza');
  near(size.height, 2000, 1, 'altezza');
});
