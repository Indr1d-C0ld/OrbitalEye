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

        const key = 'oe_panel_collapsed:' + location.pathname + ':'
            + (h2.textContent.trim().replace(/\s+/g, '_').slice(0, 60) || idx);

        h2.classList.add('panel-toggle');
        const icon = document.createElement('span');
        icon.className = 'panel-toggle-icon';
        icon.setAttribute('aria-hidden', 'true');
        h2.appendChild(icon);

        function setCollapsed(collapsed) {
            panel.classList.toggle('collapsed', collapsed);
            icon.textContent = collapsed ? '▸' : '▾';
        }
        setCollapsed(localStorage.getItem(key) === '1');

        h2.addEventListener('click', (e) => {
            // Non intercetta il click sul tooltip informativo (?) dentro
            // l'intestazione: quello deve solo mostrare la spiegazione.
            if (e.target.closest('.info-tip')) return;
            const collapsed = !panel.classList.contains('collapsed');
            setCollapsed(collapsed);
            localStorage.setItem(key, collapsed ? '1' : '0');
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
