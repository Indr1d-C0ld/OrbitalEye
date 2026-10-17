/**
 * Riconoscimento delle coordinate scritte o incollate dall'analista nei
 * campi dell'area (map-picker.js), in qualunque formato comune:
 *  - gradi decimali, anche con la virgola: 37.4077 · 37,4077 · -14.91
 *  - gradi, minuti, secondi: 37°24'27.6"N · 37 24 27.6 N · N 37° 24′ 27″
 *  - gradi e minuti decimali: 37°24.46'N · 37 24.46 N
 *  - emisfero con N/S/E/W (O = ovest) prima o dopo, oppure il segno
 *  - coppie lat, lon (o lon, lat se l'emisfero o i valori lo dicono)
 *  - UTM: 33S 495000 4140000 · 33N 495000E 4140000N
 *  - MGRS/USNG: 33SVB1234567890 · 33S VB 12345 67890 (centro del quadrato)
 *  - link di Google Maps, OpenStreetMap, Bing, Apple Maps e geo:
 *
 * Tutto nel browser, nessuna richiesta esterna. Le funzioni restituiscono
 * { ok: true, ... } oppure { ok: false, error: 'messaggio' }.
 *
 * In node (test): module.exports = OrbitalCoords.
 */
const OrbitalCoords = (() => {
  // ---------------- Coordinate singole e coppie ----------------

  /** Simboli di gradi/minuti/secondi uniformati, maiuscole, spazi semplici. */
  function normalize(text) {
    return String(text)
      .replace(/[   ]/g, ' ')
      .replace(/[º˚ᵒ]/g, '°')
      .replace(/''|[″”“]/g, '"')
      .replace(/[′’‘´`]/g, "'")
      .replace(/[−–—]/g, '-')
      .toUpperCase()
      .replace(/\b(LATITUDINE|LATITUDE|LONGITUDINE|LONGITUDE|LAT|LONG|LON|LNG)\b\s*[:=]?/g, ' ')
      .trim();
  }

  /**
   * Virgole decimali: con un punto nel testo le virgole separano; senza,
   * "37,4077 14,9129" e "37,4077, 14,9129" hanno virgole decimali. In una
   * coppia, una sola virgola fra due interi ("37,14") separa.
   */
  function decimalCommas(s, expectPair) {
    if (s.includes('.')) return s;
    const between = (s.match(/\d,\d/g) || []).length;
    if (expectPair && between === 1 && !/\s/.test(s)) return s; // "37,14": due interi
    if (between === 3 && !/\s/.test(s)) {
      // "37,4077,14,9129": decimale, separatore, decimale
      let n = 0;
      return s.replace(/(\d),(\d)/g, (m, a, b) => (++n === 2 ? `${a} ${b}` : `${a}.${b}`));
    }
    return s.replace(/(\d),(\d)/g, '$1.$2');
  }

  const HEMI = { N: 'lat', S: 'lat', E: 'lon', W: 'lon', O: 'lon' };

  /**
   * Coordinate (una o più) in un testo già normalizzato, senza UTM/MGRS.
   * @return {Array<{value:number, axis:'lat'|'lon'|null}>|{error:string}}
   */
  function tokens(s) {
    const re = /(\d+(?:\.\d+)?)|([NSEWO])(?![A-Z])|([°'"])|([-+])|([,;/|])|(\s+)|(.)/g;
    const out = [];
    let cur = null;
    let pendingSign = 1;
    let pendingHemi = null;
    const close = () => {
      if (!cur) return;
      out.push(cur);
      cur = null;
    };
    let m;
    while ((m = re.exec(s))) {
      const [, num, hemi, unit, sign, sep, space, other] = m;
      if (other) return { error: `carattere non riconosciuto: "${other}"` };
      if (space) continue;
      if (sep) { close(); continue; }
      if (sign) {
        close();
        pendingSign = sign === '-' ? -1 : 1;
        continue;
      }
      if (hemi) {
        if (cur && !cur.hemi && !cur.prefixHemi) {
          cur.hemi = hemi; // suffisso: 37.4N
          close();
        } else {
          close();
          pendingHemi = hemi; // prefisso: N37.4
        }
        continue;
      }
      if (unit) {
        // Gradi, minuti, secondi si leggono in ordine; i secondi chiudono.
        if (!cur) return { error: `simbolo ${unit} senza numero` };
        if (unit === '"') cur.done = true;
        continue;
      }
      // Numero: nuova coordinata se la corrente è completa (tre valori,
      // secondi indicati, oppure un decimale, che chiude sempre).
      const isDec = num.includes('.');
      if (cur && (cur.done || cur.parts.length >= 3 || cur.lastDecimal)) close();
      if (!cur) {
        cur = { parts: [], sign: pendingSign, prefixHemi: pendingHemi, hemi: null, lastDecimal: false, done: false };
        pendingSign = 1;
        pendingHemi = null;
      }
      cur.parts.push(parseFloat(num));
      cur.lastDecimal = isDec;
    }
    close();
    if (pendingHemi) {
      // "37.4 N" già chiuso, emisfero rimasto senza numero: è un suffisso.
      const last = out[out.length - 1];
      if (last && !last.hemi && !last.prefixHemi) last.hemi = pendingHemi;
      else return { error: `"${pendingHemi}" senza un valore` };
    }
    const coords = [];
    for (const c of out) {
      const [d, mi = 0, se = 0] = c.parts;
      if (c.parts.length > 1 && (mi >= 60 || se >= 60)) return { error: 'minuti e secondi devono essere minori di 60' };
      if (c.parts.length > 1 && !Number.isInteger(d)) return { error: 'gradi decimali seguiti da minuti' };
      const h = c.hemi || c.prefixHemi;
      let v = d + mi / 60 + se / 3600;
      if (c.sign < 0 || h === 'S' || h === 'W' || h === 'O') v = -v;
      coords.push({ value: v, axis: h ? HEMI[h] : null });
    }
    return coords;
  }

  function inRange(lat, lon) {
    return Math.abs(lat) <= 90 && Math.abs(lon) <= 180;
  }

  /** Due coordinate in [lat, lon], secondo gli emisferi o i valori. */
  function assignPair(a, b) {
    let lat, lon, note = '';
    if (a.axis && b.axis && a.axis === b.axis) return { ok: false, error: `due ${a.axis === 'lat' ? 'latitudini' : 'longitudini'}` };
    if (a.axis === 'lon' || b.axis === 'lat') {
      [lat, lon] = [b.value, a.value];
    } else if (!a.axis && !b.axis && Math.abs(a.value) > 90 && Math.abs(b.value) <= 90) {
      [lat, lon] = [b.value, a.value];
      note = 'letta come longitudine, latitudine (il primo valore supera 90)';
    } else {
      [lat, lon] = [a.value, b.value];
    }
    if (!inRange(lat, lon)) return { ok: false, error: 'fuori range: latitudine fra -90 e 90, longitudine fra -180 e 180' };
    return { ok: true, lat, lon, note };
  }

  // ---------------- UTM e MGRS ----------------

  const A = 6378137;
  const F = 1 / 298.257223563;
  const E2 = F * (2 - F);
  const EP2 = E2 / (1 - E2);
  const K0 = 0.9996;
  const BANDS = 'CDEFGHJKLMNPQRSTUVWX';

  /** UTM (WGS84) in gradi: formule di Snyder, errore sotto il centimetro nella zona. */
  function utmToLatLon(zone, north, easting, northing) {
    const x = easting - 500000;
    const y = north ? northing : northing - 10000000;
    const mu = y / K0 / (A * (1 - E2 / 4 - 3 * E2 * E2 / 64 - 5 * E2 ** 3 / 256));
    const e1 = (1 - Math.sqrt(1 - E2)) / (1 + Math.sqrt(1 - E2));
    const p1 = mu + (3 * e1 / 2 - 27 * e1 ** 3 / 32) * Math.sin(2 * mu)
      + (21 * e1 * e1 / 16 - 55 * e1 ** 4 / 32) * Math.sin(4 * mu)
      + (151 * e1 ** 3 / 96) * Math.sin(6 * mu)
      + (1097 * e1 ** 4 / 512) * Math.sin(8 * mu);
    const sin = Math.sin(p1), cos = Math.cos(p1), tan = Math.tan(p1);
    const n1 = A / Math.sqrt(1 - E2 * sin * sin);
    const t1 = tan * tan;
    const c1 = EP2 * cos * cos;
    const r1 = A * (1 - E2) / (1 - E2 * sin * sin) ** 1.5;
    const d = x / (n1 * K0);
    const lat = p1 - (n1 * tan / r1) * (d * d / 2
      - (5 + 3 * t1 + 10 * c1 - 4 * c1 * c1 - 9 * EP2) * d ** 4 / 24
      + (61 + 90 * t1 + 298 * c1 + 45 * t1 * t1 - 252 * EP2 - 3 * c1 * c1) * d ** 6 / 720);
    const lon = (d - (1 + 2 * t1 + c1) * d ** 3 / 6
      + (5 - 2 * c1 + 28 * t1 - 3 * c1 * c1 + 8 * EP2 + 24 * t1 * t1) * d ** 5 / 120) / cos;
    return { lat: lat * 180 / Math.PI, lon: (zone - 1) * 6 - 180 + 3 + lon * 180 / Math.PI };
  }

  /** Il punto cade nella fascia di latitudine della lettera (C–X)? */
  function inBand(lat, band) {
    const i = BANDS.indexOf(band);
    const south = -80 + 8 * i;
    const north = band === 'X' ? 84 : south + 8;
    return lat >= south - 0.5 && lat <= north + 0.5;
  }

  function parseUtm(s) {
    const m = s.match(/^(\d{1,2})\s*([C-HJ-NP-X])\s+(\d{1,7}(?:\.\d+)?)\s*(?:M?E)?\s*[,;]?\s+(\d{1,8}(?:\.\d+)?)\s*(?:M?N)?$/);
    if (!m) return null;
    const zone = parseInt(m[1], 10);
    if (zone < 1 || zone > 60) return { ok: false, error: 'zona UTM fra 1 e 60' };
    const letter = m[2];
    const e = parseFloat(m[3]), n = parseFloat(m[4]);
    if (e < 100000 || e > 900000) return { ok: false, error: 'est UTM fuori dalla zona (100000–900000 m)' };
    // La lettera è la fascia di latitudine (C–X) o, nella notazione più
    // semplice, l'emisfero (N/S): S è anche la fascia 32–40° nord, si
    // sceglie quella in cui il punto cade davvero.
    const tries = letter === 'N' ? [true] : letter === 'S' ? [true, false] : [BANDS.indexOf(letter) >= BANDS.indexOf('N')];
    for (const north of tries) {
      const p = utmToLatLon(zone, north, e, n);
      const ok = letter === 'N' ? p.lat >= 0 : (letter === 'S' && !north) ? p.lat < 0 : inBand(p.lat, letter);
      if (ok && inRange(p.lat, p.lon)) return { ok: true, lat: p.lat, lon: p.lon, format: 'UTM' };
    }
    return { ok: false, error: 'coordinate UTM incoerenti con la zona o la fascia' };
  }

  const COLS = ['ABCDEFGH', 'JKLMNPQR', 'STUVWXYZ'];
  const ROWS = 'ABCDEFGHJKLMNPQRSTUV';

  function parseMgrs(s) {
    const compact = s.replace(/\s+/g, '');
    const m = compact.match(/^(\d{1,2})([C-HJ-NP-X])([A-HJ-NP-Z])([A-HJ-NP-V])(\d*)$/);
    if (!m) return null;
    const zone = parseInt(m[1], 10);
    const band = m[2];
    const digits = m[5];
    if (zone < 1 || zone > 60) return { ok: false, error: 'zona MGRS fra 1 e 60' };
    if (digits.length % 2 || digits.length > 10) return { ok: false, error: 'MGRS: servono cifre pari (est e nord con la stessa precisione)' };
    const set = ((zone - 1) % 6) + 1;
    const col = COLS[(set - 1) % 3].indexOf(m[3]);
    if (col < 0) return { ok: false, error: `MGRS: colonna "${m[3]}" non valida per la zona ${zone}` };
    const rowIdx = ROWS.indexOf(m[4]);
    const row = ((rowIdx - (set % 2 === 0 ? 5 : 0)) % 20 + 20) % 20;
    const prec = digits.length / 2;
    const unit = 10 ** (5 - prec);
    // Centro del quadrato indicato (MGRS dà l'angolo sud-ovest).
    const e = (col + 1) * 100000 + (prec ? parseInt(digits.slice(0, prec), 10) * unit : 0) + unit / 2;
    const n0 = row * 100000 + (prec ? parseInt(digits.slice(prec), 10) * unit : 0) + unit / 2;
    const north = BANDS.indexOf(band) >= BANDS.indexOf('N');
    // Il nord MGRS si ripete ogni 2000 km: quello che cade nella fascia.
    for (let k = 0; k < 10; k++) {
      const p = utmToLatLon(zone, north, e, n0 + k * 2000000);
      if (inBand(p.lat, band)) return { ok: true, lat: p.lat, lon: p.lon, format: 'MGRS' };
    }
    return { ok: false, error: 'MGRS: quadrato incoerente con la fascia di latitudine' };
  }

  // ---------------- Link delle mappe ----------------

  function parseUrl(raw) {
    const s = String(raw).trim();
    if (!/^(https?:\/\/|geo:)/i.test(s)) return null;
    let text = s;
    try { text = decodeURIComponent(s); } catch (e) { /* lasciato com'è */ }
    const num = '(-?\\d{1,3}(?:\\.\\d+)?)';
    const patterns = [
      [new RegExp(`!3d${num}!4d${num}`), 1, 2], // Google: il luogo segnato
      [new RegExp(`[?&](?:q|query|ll|sll|center|destination|daddr)=${num},\\s*${num}`, 'i'), 1, 2],
      [new RegExp(`@${num},${num}`), 1, 2], // Google: centro della vista
      [new RegExp(`mlat=${num}&mlon=${num}`, 'i'), 1, 2], // OpenStreetMap: segnaposto
      [new RegExp(`#map=\\d+(?:\\.\\d+)?/${num}/${num}`), 1, 2], // OpenStreetMap: vista
      [new RegExp(`[?&]cp=${num}~${num}`, 'i'), 1, 2], // Bing
      [new RegExp(`^geo:${num},${num}`, 'i'), 1, 2],
    ];
    for (const [re, li, lo] of patterns) {
      const m = text.match(re);
      if (m) {
        const lat = parseFloat(m[li]), lon = parseFloat(m[lo]);
        if (inRange(lat, lon)) return { ok: true, lat, lon, format: 'link' };
      }
    }
    return { ok: false, error: 'nessuna coordinata riconosciuta nel link (per Google Maps usa il link della barra degli indirizzi o di un luogo segnato)' };
  }

  // ---------------- Funzioni pubbliche ----------------

  /**
   * Un punto (latitudine e longitudine) da un testo qualsiasi.
   * @return {{ok:true, lat:number, lon:number, format:string, note:string}|{ok:false, error:string}}
   */
  function parsePoint(text) {
    if (!String(text).trim()) return { ok: false, error: 'vuoto' };
    const url = parseUrl(text);
    if (url) return url.ok ? { ...url, note: '' } : url;
    const s = normalize(text);
    const grid = parseMgrs(s) || parseUtm(s);
    if (grid) return grid.ok ? { ...grid, note: grid.format === 'MGRS' ? 'centro del quadrato MGRS' : '' } : grid;
    const t = tokens(decimalCommas(s, true));
    if (t.error) return { ok: false, error: t.error };
    if (t.length !== 2) {
      return { ok: false, error: t.length === 1 ? 'serve anche la seconda coordinata (latitudine e longitudine)' : 'formato non riconosciuto' };
    }
    const r = assignPair(t[0], t[1]);
    return r.ok ? { ...r, format: 'coordinate' } : r;
  }

  /**
   * Il valore di un singolo campo (latitudine o longitudine). Se il testo
   * è un punto intero (coppia, UTM, MGRS, link) lo restituisce come punto.
   * @param {'lat'|'lon'} axis
   * @return {{ok:true, value:number}|{ok:true, point:{lat:number, lon:number}, note:string}|{ok:false, error:string}}
   */
  function parseAxis(text, axis) {
    const raw = String(text).trim();
    if (!raw) return { ok: false, error: 'vuoto' };
    if (/^-?\d+(\.\d+)?$/.test(raw)) {
      const v = parseFloat(raw);
      return Math.abs(v) <= (axis === 'lat' ? 90 : 180) ? { ok: true, value: v } : { ok: false, error: rangeMsg(axis) };
    }
    const s = normalize(raw);
    const whole = /^(https?:|geo:)/i.test(raw) || parseMgrs(s) || parseUtm(s);
    if (whole) {
      const p = parsePoint(raw);
      return p.ok ? { ok: true, point: { lat: p.lat, lon: p.lon }, note: p.note } : p;
    }
    const t = tokens(decimalCommas(s, false));
    if (t.error) return { ok: false, error: t.error };
    if (t.length === 2) {
      const r = assignPair(t[0], t[1]);
      return r.ok ? { ok: true, point: { lat: r.lat, lon: r.lon }, note: r.note } : r;
    }
    if (t.length !== 1) return { ok: false, error: 'formato non riconosciuto' };
    const c = t[0];
    if (c.axis && c.axis !== axis) {
      return { ok: false, error: axis === 'lat' ? 'è una longitudine (E/W): va in un campo Lon' : 'è una latitudine (N/S): va in un campo Lat' };
    }
    return Math.abs(c.value) <= (axis === 'lat' ? 90 : 180) ? { ok: true, value: c.value } : { ok: false, error: rangeMsg(axis) };
  }

  function rangeMsg(axis) {
    return axis === 'lat' ? 'latitudine fuori range (-90…90)' : 'longitudine fuori range (-180…180)';
  }

  /** "37.40767° N, 14.91289° E" */
  function format(lat, lon) {
    return `${Math.abs(lat).toFixed(5)}° ${lat < 0 ? 'S' : 'N'}, ${Math.abs(lon).toFixed(5)}° ${lon < 0 ? 'W' : 'E'}`;
  }

  /**
   * Rettangolo di base di lato larghezza × altezza (metri) centrato nel
   * punto: [minLon, minLat, maxLon, maxLat].
   */
  function boxAround(lat, lon, widthM, heightM) {
    const mLat = 111320;
    const mLon = 111320 * Math.cos(lat * Math.PI / 180) || 1e-6;
    const dLat = heightM / 2 / mLat;
    const dLon = widthM / 2 / mLon;
    return [lon - dLon, Math.max(-90, lat - dLat), lon + dLon, Math.min(90, lat + dLat)];
  }

  /** Larghezza e altezza in metri di un rettangolo [minLon, minLat, maxLon, maxLat]. */
  function boxSize(b) {
    const lat = (b[1] + b[3]) / 2;
    return { width: (b[2] - b[0]) * 111320 * Math.cos(lat * Math.PI / 180), height: (b[3] - b[1]) * 111320 };
  }

  return { parsePoint, parseAxis, format, boxAround, boxSize, utmToLatLon };
})();

if (typeof module !== 'undefined') module.exports = OrbitalCoords;
