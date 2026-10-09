// ---------- Menu mobile (hamburger + drawer) ----------
(function () {
    const btn = document.getElementById('hamburger-btn');
    const sidebar = document.getElementById('app-sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    if (!btn || !sidebar || !backdrop) return;

    function setOpen(open) {
        sidebar.classList.toggle('open', open);
        backdrop.classList.toggle('open', open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        document.body.style.overflow = open ? 'hidden' : '';
    }

    btn.addEventListener('click', () => setOpen(!sidebar.classList.contains('open')));
    backdrop.addEventListener('click', () => setOpen(false));
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') setOpen(false);
    });
    // Chiude il drawer quando si sceglie una voce di menu (la navigazione
    // ricarica comunque la pagina, ma evita il "salto visivo" del drawer
    // ancora aperto nel frattempo).
    sidebar.querySelectorAll('.nav a').forEach((a) => a.addEventListener('click', () => setOpen(false)));
})();

// ---------- Pannelli collassabili ----------
// Ogni .panel il cui primo figlio è un h2 diventa collassabile cliccando
// l'intestazione (tranne quelli con data-no-collapse, es. il pannello di
// solo titolo in cima alla pagina). Applicato genericamente qui, non nel
// markup di ogni singola pagina, così vale ovunque senza dover toccare
// pannello per pannello. Stato ricordato per pagina+pannello (chiave
// derivata dal testo dell'intestazione) in localStorage: di default tutti
// i pannelli restano aperti come oggi, si ricorda solo la scelta di
// chiuderne uno.
(function () {
    const panels = document.querySelectorAll('.panel');
    panels.forEach((panel, idx) => {
        if (panel.dataset.noCollapse !== undefined) return;
        const h2 = panel.firstElementChild;
        if (!h2 || h2.tagName !== 'H2') return;

        // Chiave dal testo dell'intestazione SENZA i contatori (es. "Riprese in
        // archivio (5)") e senza il "?" dei suggerimenti: prima la scelta di
        // chiudere il pannello andava persa appena cambiava il numero.
        const headingText = Array.from(h2.childNodes)
            .filter((n) => !(n.nodeType === 1 && n.classList.contains('info-tip')))
            .map((n) => n.textContent).join('')
            .replace(/\(\s*\d+\s*\)/g, '').trim();
        const key = 'oe_panel_collapsed:' + location.pathname + ':'
            + (headingText.replace(/\s+/g, '_').slice(0, 60) || idx);

        h2.classList.add('panel-toggle');
        const icon = document.createElement('span');
        icon.className = 'panel-toggle-icon';
        icon.setAttribute('aria-hidden', 'true');
        h2.appendChild(icon);

        function setCollapsed(collapsed) {
            panel.classList.toggle('collapsed', collapsed);
            icon.textContent = collapsed ? '▸' : '▾';
        }
        // localStorage può non essere disponibile (dati del sito bloccati,
        // finestra privata restrittiva): prima l'eccezione interrompeva la
        // configurazione e i pannelli successivi restavano senza comando.
        let stored = null;
        try { stored = localStorage.getItem(key); } catch (e) { /* non ricordato */ }
        setCollapsed(stored === '1');

        h2.addEventListener('click', (e) => {
            // Non intercetta il click sul tooltip informativo (?) dentro
            // l'intestazione: quello deve solo mostrare la spiegazione.
            if (e.target.closest('.info-tip')) return;
            const collapsed = !panel.classList.contains('collapsed');
            setCollapsed(collapsed);
            try { localStorage.setItem(key, collapsed ? '1' : '0'); } catch (e) { /* non ricordato */ }
        });
    });
})();

async function deleteEntity(type, id, redirectTo, confirmMessage) {
    if (!confirm(confirmMessage || 'Confermi l\'eliminazione? L\'operazione non è reversibile.')) return;
    const res = await fetch('api/delete.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ type, id }),
    });
    if (!res.ok) {
        alert('Eliminazione fallita.');
        return;
    }
    if (redirectTo) {
        window.location.href = redirectTo;
    } else {
        window.location.reload();
    }
}

// Conferma prima di PUBBLICARE (Telegram, X) un'immagine Esri: i termini
// d'uso Esri chiedono un permesso preventivo per gli usi diversi da quelli
// elencati, e la pubblicazione su canali pubblici non vi rientra
// chiaramente. Una volta per pagina; la scelta resta dell'analista. Lo
// stesso avviso è sempre visibile accanto ai pulsanti.
let esriPublishConfirmed = false;
function confirmEsriPublishing(isEsri) {
    if (!isEsri || esriPublishConfirmed) return true;
    const ok = confirm(
        'Questa immagine proviene da Esri World Imagery.\n\n'
        + 'I termini d\'uso Esri consentono uso personale o interno, rapporti per clienti e '
        + 'materiale promozionale proprio; per ogni altro uso, come la pubblicazione su canali '
        + 'pubblici, chiedono un permesso preventivo.\n\n'
        + 'Procedere comunque con la pubblicazione?'
    );
    esriPublishConfirmed = ok;
    return ok;
}

// ---------- Pubblicazione (vedi PublicationBuilder.php) ----------
// Le immagini da pubblicare vengono composte sul server nel formato scelto
// (scheda, striscia, solo immagine, Prima/Dopo affiancate): queste funzioni
// leggono le opzioni di un blocco "Condividi" (partials/publish_options.php)
// e chiedono al server l'immagine composta o l'invio.
function publishOptions(prefix) {
  const el = (id) => document.getElementById(prefix + '-publish-' + id);
  return {
    format: el('format') ? el('format').value : 'strip',
    title: el('title') ? el('title').value : '',
    album: !!(el('album') && el('album').checked),
    document: !!(el('document') && el('document').checked),
    review: !!(el('review') && el('review').checked),
  };
}

function appendPublishOptions(form, prefix) {
  const o = publishOptions(prefix);
  form.append('format', o.format);
  form.append('title', o.title);
  form.append('album', o.album ? '1' : '0');
  form.append('document', o.document ? '1' : '0');
  form.append('review', o.review ? '1' : '0');
  return o;
}

// Il titolo serve solo alle schede; l'album solo se non si sceglie "solo
// immagine" per un confronto (resta comunque possibile).
document.querySelectorAll('.publish-options').forEach((box) => {
  const prefix = box.dataset.prefix;
  const format = document.getElementById(prefix + '-publish-format');
  const titleField = box.querySelector('.publish-title-field');
  const sync = () => { if (titleField) titleField.style.visibility = ['card', 'pair'].includes(format.value) ? '' : 'hidden'; };
  format.addEventListener('change', sync);
  sync();
});

// Immagine composta dal server, come partirebbe (api/publication_preview.php).
async function fetchPublicationImage(form) {
  const res = await fetch('api/publication_preview.php', { method: 'POST', body: form });
  if (!res.ok) {
    let msg = 'Anteprima non disponibile';
    try { msg = (await res.json()).error || msg; } catch (e) { /* non JSON */ }
    throw new Error(msg);
  }
  return res.blob();
}

// Anteprima in una nuova scheda: la scheda si apre subito (dentro il clic,
// così il browser non la blocca) e riceve l'immagine appena pronta.
// formOrPromise: il modulo, o la promessa che lo prepara (va passata senza
// attenderla prima, perché la scheda si deve aprire nel clic stesso).
async function openPublicationPreview(formOrPromise, statusEl) {
  const win = window.open('', '_blank');
  if (win) win.document.write('<p style="font-family:sans-serif;color:#888;">Composizione dell’anteprima…</p>');
  try {
    const blob = await fetchPublicationImage(await formOrPromise);
    const url = URL.createObjectURL(blob);
    setTimeout(() => URL.revokeObjectURL(url), 10 * 60 * 1000);
    if (win) {
      win.location.href = url;
      if (statusEl) statusEl.textContent = '';
    } else if (statusEl) {
      // Nuove schede bloccate dal browser: un link da aprire a mano.
      statusEl.textContent = '';
      const a = document.createElement('a');
      a.href = url;
      a.target = '_blank';
      a.rel = 'noopener';
      a.textContent = '👁 Apri l\u2019anteprima';
      statusEl.appendChild(a);
    }
  } catch (err) {
    if (win) win.close();
    if (statusEl) statusEl.textContent = 'Errore: ' + err.message;
  }
}

// Copia negli appunti dell'immagine composta (da incollare su X).
async function copyPublicationImage(formOrPromise, statusEl) {
  if (!window.isSecureContext || !navigator.clipboard || !navigator.clipboard.write || typeof ClipboardItem === 'undefined') {
    Promise.resolve(formOrPromise).catch(() => {}); // il modulo non serve più
    statusEl.textContent = 'Copia negli appunti non disponibile in questo browser/connessione (serve HTTPS).';
    return;
  }
  try {
    // La promessa va passata a ClipboardItem subito, dentro il gesto
    // dell'utente: Safari rifiuta la scrittura se arriva dopo un'attesa.
    const blobPromise = Promise.resolve(formOrPromise).then(fetchPublicationImage).then((b) => (b.type === 'image/png' ? b : convertToPng(b)));
    await navigator.clipboard.write([new ClipboardItem({ 'image/png': blobPromise })]);
    statusEl.textContent = 'Copiato. Ora vai su X e incolla con Ctrl+V.';
  } catch (err) {
    statusEl.textContent = 'Copia non riuscita: ' + err.message;
  }
}

// Gli appunti accettano in modo affidabile solo PNG.
async function convertToPng(blob) {
  const bitmap = await createImageBitmap(blob);
  const c = document.createElement('canvas');
  c.width = bitmap.width;
  c.height = bitmap.height;
  c.getContext('2d').drawImage(bitmap, 0, 0);
  return new Promise((resolve) => c.toBlob(resolve, 'image/png'));
}

// Invio a Telegram (diretto o in revisione). Restituisce il testo di esito.
async function sendPublication(form) {
  form.set('platform', 'telegram');
  const res = await fetch('api/share.php', { method: 'POST', body: form });
  let data;
  try {
    data = await res.json();
  } catch (e) {
    // Risposta non JSON (errore del server): un messaggio leggibile.
    throw new Error('il server non ha completato la richiesta (HTTP ' + res.status + ')');
  }
  if (!res.ok) throw new Error(data.error || 'Errore');
  if (data.queued) {
    return 'Inviata alla chat di revisione (pubblicazione #' + data.queued + '): approvala o scartala dalla pagina Pubblicazioni.'
      + (data.warning ? ' ' + data.warning : '');
  }
  return data.warning || 'Inviato su Telegram.';
}
