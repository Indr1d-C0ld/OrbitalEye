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
