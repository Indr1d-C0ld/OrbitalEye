# Changelog

Registro delle modifiche sincronizzate dal deployment live a questo repo.
Ogni voce elenca i file toccati e cosa/perché è cambiato — stesso dettaglio
riportato nel messaggio del commit corrispondente.

## 2026-08-27 (2) — Allineamento manuale a punti di controllo

Nuovo fallback assistito per l'allineamento tra due riprese, per i casi in
cui il motore automatico (ORB+ECC) non trova corrispondenze affidabili —
tipicamente tra fonti visivamente molto diverse (es. Esri World Imagery vs
Sentinel Hub). Resta l'Automatico come modalità di default.

- **[webapp/schema.sql](webapp/schema.sql)**
  Nuova tabella `manual_control_points` (capture_a_id, capture_b_id,
  points_json): i punti di controllo sono legati alla *coppia* di riprese,
  non al singolo confronto, così restano riutilizzabili.

- **[python-service/app/core/registration.py](python-service/app/core/registration.py)**
  Nuova `register_with_points()`: calcola la trasformazione da punti di
  controllo indicati manualmente invece che da feature matching — 3 punti →
  affine, 4+ → omografia con RANSAC. Nessuna rifinitura ECC successiva
  (i punti indicati sono presi come riferimento definitivo, per non
  reintrodurre lo stesso rischio di convergenza sbagliata che ha reso
  necessario l'intervento manuale).

- **[python-service/app/routers/analysis.py](python-service/app/routers/analysis.py)**
  `CompareRequest` accetta `control_points` (ha sempre la precedenza su
  `align` se presenti, >=3). Nuovo endpoint `/analysis/register_manual`:
  anteprima rapida (immagine allineata + blend 50/50 con A) per giudicare la
  qualità dei punti prima di lanciare un confronto completo.

- **[webapp/src/ManualControlPoints.php](webapp/src/ManualControlPoints.php)** (nuovo)
  Modello per leggere/salvare/cancellare i punti di controllo di una coppia
  di riprese.

- **[webapp/public/api/control_points.php](webapp/public/api/control_points.php)** (nuovo)
  GET/POST/DELETE dei punti di controllo per una coppia capture_a/capture_b.

- **[webapp/public/api/register_manual_preview.php](webapp/public/api/register_manual_preview.php)** (nuovo)
  Inoltra al servizio Python la richiesta di anteprima allineamento manuale.

- **[webapp/public/api/compare.php](webapp/public/api/compare.php)**
  Nuovo parametro `align_mode` ('auto'/'manual'): in modalità manuale, carica
  i punti salvati per la coppia e li inoltra al servizio Python al posto del
  motore automatico (errore chiaro lato server se non ce ne sono almeno 3).

- **[webapp/public/study.php](webapp/public/study.php)**
  Toggle Automatico/Manuale nel pannello di configurazione confronto; nuovo
  pannello `#control-points-panel` con le due riprese affiancate, controlli
  di zoom e anteprima prima/dopo allineamento.

- **[webapp/public/assets/js/study.js](webapp/public/assets/js/study.js)**
  Nuova IIFE `setupControlPointsEditor()`: piazzamento punti (click
  alternato A→B con marker numerati colorati), zoom Adatta/100%/200%/400%,
  annulla/cancella, anteprima, salvataggio; `state.alignMode` incluso nel
  payload di `run-compare-btn`; `refreshSelectionUI()` ora aggiorna anche lo
  stato "N punti salvati" per la coppia selezionata.

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
