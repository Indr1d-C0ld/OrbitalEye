# Changelog

Registro delle modifiche sincronizzate dal deployment live a questo repo.
Ogni voce elenca i file toccati e cosa/perché è cambiato — stesso dettaglio
riportato nel messaggio del commit corrispondente.

## 2026-08-27

- **[python-service/app/core/registration.py](python-service/app/core/registration.py)**
  Allineamento cross-fonte (es. Esri World Imagery vs Sentinel Hub) migliorato:
  - `_clahe_normalize()` (nuova): normalizza il contrasto locale prima del
    feature matching ORB e prima dell'ECC — senza, fonti con bilanciamento
    colore/contrasto molto diversi facevano fallire il matching e degradare
    al fallback più debole.
  - Fallback ECC diretto (quando ORB non trova un'omografia affidabile):
    passa da moto euclideo (`MOTION_EUCLIDEAN`, solo rotazione+traslazione) a
    moto affine (`MOTION_AFFINE`, +scala/shear) — corregge anche le
    differenze di scala/inclinazione tra riprese di fonti diverse.
  Causa dell'allineamento "storto" segnalato tra riprese Esri e Sentinel.

- **[webapp/public/assets/js/study.js](webapp/public/assets/js/study.js)**
  - `refreshSelectionUI()`: nasconde il pannello risultati e invalida
    `state.currentComparison` quando la selezione A/B corrente non
    corrisponde più al confronto mostrato (restava "congelato" sullo stato
    precedente).
  - `window.loadComparison()`: ripristina `state.selectedA`/`state.selectedB`
    dalle riprese usate nel confronto salvato, così aprire un confronto dalla
    libreria seleziona subito le riprese giuste invece di lasciarle vuote.
  - Nuovo blocco `setupEnhancePanel()`: logica del pannello "✨ Migliora"
    (miglioramento di una singola ripresa senza eseguire un confronto) —
    anteprima via `api/enhance_capture.php` (`preview:true`), salvataggio via
    `api/save_enhanced_capture.php`.

- **[webapp/public/study.php](webapp/public/study.php)**
  Nuovo pannello `#enhance-panel` (filtri di enhancing standalone) e pulsante
  "✨ Migliora" su ogni scheda ripresa in archivio.

- **[webapp/public/api/enhance_capture.php](webapp/public/api/enhance_capture.php)**
  - Aggiunta modalità `preview` (elabora e ritorna il risultato senza creare
    subito una ripresa permanente in DB).
  - Fix: i filtri senza parametri (bilanciamento del bianco, CLAHE,
    equalizzazione istogramma) venivano inviati al servizio Python come
    `"params": []` invece di `"params": {}` (quirk di `json_decode`/
    `json_encode` di PHP su array vuoti), causando un 422 dal servizio di
    analisi. Corretto forzando `stdClass()` sui parametri vuoti prima
    dell'inoltro — stessa correzione già presente in `api/compare.php`.

- **[webapp/public/api/save_enhanced_capture.php](webapp/public/api/save_enhanced_capture.php)** (nuovo)
  Salva un'anteprima già generata da `enhance_capture.php` come ripresa
  permanente, senza rielaborare l'immagine.
