# Changelog

Registro delle modifiche sincronizzate dal deployment live a questo repo.
Ogni voce elenca i file toccati e cosa/perché è cambiato — stesso dettaglio
riportato nel messaggio del commit corrispondente.

## 2026-08-29 (2) — Sovrapposizione di un'immagine propria + dimensione maniglie regolabile

- **[webapp/public/analyze_capture.php](webapp/public/analyze_capture.php)**
  - Nuova modalità toolbar "🖼 Sovrapponi" (trascina per riposizionare
    l'immagine caricata) e nuovo pannello "Sovrapposizione immagine":
    input file, slider Scala/Rotazione/Inclinazione orizzontale/Inclinazione
    verticale/Opacità, pulsanti Rimuovi e Reset trasformazioni.
  - Nuovo `<img id="an-overlay-img">` dentro `#an-content-right` (zooma/pan
    insieme al resto, essendo figlio dello stesso `.zoom-content`).
  - Nuovo slider "Dimensione maniglie" nella toolbar: il raggio delle
    maniglie di annotazioni/misurazioni era fisso (e già cambiato una volta
    su segnalazione contrastante — comodo per alcuni, ingombrante per altri),
    ora regolabile in tempo reale.

- **[webapp/public/assets/js/analyze.js](webapp/public/assets/js/analyze.js)**
  - Stato `overlay` (centro, dimensione base dal rapporto d'aspetto reale,
    scala, rotazione, inclinazione X/Y, opacità) espresso come frazioni di
    `canvas.width/height` — resta valido a qualunque zoom senza ricalcoli,
    stessa convenzione delle annotazioni.
  - `renderOverlay()` applica posizione/trasformazione via CSS
    (`transform: rotate() skew()`) per un'anteprima istantanea, zero rete.
  - Nuova modalità di interazione `'overlay'` nel dispatcher esistente
    (drag per riposizionare, stesso meccanismo di annotate/measure/crop).
  - `drawOverlayOnto()`: replica la stessa trasformazione via Canvas 2D
    (`ctx.transform` con la matrice equivalente a CSS `skew()`), chiamata da
    `renderAdjustedCanvas()` così "Salva come nuova ripresa" incorpora
    definitivamente la sovrapposizione nel file — mai inviata al servizio
    prima di quel momento, resta lato browser.
  - `HANDLE_R` da costante a variabile pilotata dallo slider dedicato;
    `HANDLE_HIT_R` diventato `handleHitR()` (funzione, con minimo di 8px
    garantito anche a maniglie molto piccole) invece di un valore
    precalcolato una sola volta.
  - Bug corretto durante l'implementazione: `overlay` referenziato da
    `resizeAnnotateCanvas()` prima di essere dichiarato (temporal dead
    zone) — spostata la dichiarazione dello stato più in alto, stesso
    trattamento già applicato in precedenza a `measurements`.

## 2026-08-29 — Colore personalizzabile per annotazioni/misurazioni + undo mancante sui filtri avanzati

Completamento dell'editor di "Analisi ripresa singola": durante un giro di
verifica è emerso che undo, spostamento/ridimensionamento delle annotazioni
e modifica del loro testo erano già stati implementati (nel lavoro del
2026-08-28) ma non ancora testati né documentati — verificati ora con
successo end-to-end. Quanto mancava davvero:

- **[webapp/src/Annotation.php](webapp/src/Annotation.php)**
  `update()` accetta ora un parametro `$color` opzionale (default `null` =
  colore invariato): permette di ricolorare un'annotazione esistente senza
  toccarne posizione/testo.

- **[webapp/public/api/annotations.php](webapp/public/api/annotations.php)**
  Il branch `PUT` inoltra `color` (se presente nel body) a `Annotation::update()`.

- **[webapp/public/analyze_capture.php](webapp/public/analyze_capture.php)**
  Due selettori colore nella toolbar ("Colore annotazioni"/"Colore
  misurazioni") per le prossime forme disegnate.

- **[webapp/public/assets/js/analyze.js](webapp/public/assets/js/analyze.js)**
  - `currentAnnotateColor`/`currentMeasureColor`: usati da `finishAnnotate`/
    `finishMeasure` invece dei colori fissi precedenti; l'anteprima durante
    il disegno di una misura riflette già il colore scelto.
  - Swatch colore (`<input type="color">`) per riga in entrambe le liste
    Annotazioni/Misurazioni, per ricolorare singolarmente un elemento già
    esistente — con voce di undo per ciascuna ricolorazione.
  - Undo aggiunto anche per "Applica filtri avanzati" (ripristina l'immagine
    precedente) e "Ripristina originale" (ripristina anche slider e
    checkbox precedenti, non solo l'immagine) — mancava nonostante il
    tooltip della toolbar lo dichiarasse già.
  - `HANDLE_R`/`HANDLE_HIT_R` aumentati (5→7px, 2.5×→3× di area cliccabile)
    per rendere le maniglie di trascinamento più comode da centrare col mouse.

## 2026-08-28 — Nuova modalità "Analisi ripresa singola"

Nuova pagina dedicata per analizzare una ripresa da sola, senza doverla
confrontare con un'altra né passare dal roundtrip server del pannello
"Migliora": due riquadri affiancati (originale/copia di lavoro) con
zoom/panning sincronizzato, regolazioni istantanee (luminosità, contrasto,
saturazione, nitidezza, gamma) calcolate nel browser, un secondo livello di
filtri avanzati che riusa il motore server già esistente per gli algoritmi
che richiedono statistiche sull'intera immagine, annotazioni, e salvataggio
del risultato come nuova ripresa permanente.

- **[webapp/public/analyze_capture.php](webapp/public/analyze_capture.php)** (nuovo)
  Pagina dedicata (`?id=<capture_id>`): due `.viewer-stage` (Originale /
  Copia di lavoro), toolbar Sposta/Annota + controlli zoom, 5 slider di
  regolazione in tempo reale, pannello "Filtri avanzati" (bilanciamento del
  bianco, riduzione rumore, CLAHE, equalizzazione istogramma, contorni) con
  pulsanti Applica/Ripristina originale, lista annotazioni.

- **[webapp/public/assets/js/analyze.js](webapp/public/assets/js/analyze.js)** (nuovo)
  - Zoom/pan **sincronizzato** tra i due riquadri (stato scale/tx/ty
    condiviso, non due controller indipendenti): rotellina, drag in
    modalità Sposta, pinch-to-zoom touch.
  - Regolazioni istantanee via filtri nativi del browser: `brightness()`,
    `contrast()`, `saturate()` CSS; nitidezza e gamma via filtri SVG
    dinamici (`feConvolveMatrix` per un kernel di sharpening, `feComponentTransfer
    type="gamma"`) il cui stato si ricalcola ad ogni movimento slider —
    nessuna chiamata di rete per queste cinque regolazioni.
  - Filtri avanzati: POST a `api/enhance_capture.php` (stesso endpoint di
    "Migliora", `preview:true`) — il risultato ripunta l'`<img>` della copia
    di lavoro, su cui le regolazioni in tempo reale continuano ad agire.
  - "Salva come nuova ripresa": ridisegna via canvas, alla risoluzione
    originale, le stesse formule dei filtri live (luminosità → contrasto →
    saturazione → gamma → nitidezza, stesso ordine della catena CSS) e
    carica il PNG risultante tramite `api/upload_capture.php` (riusato
    così com'è, nessun nuovo endpoint necessario).
  - Annotazioni: stesso sistema (`api/annotations.php`) già usato altrove,
    con `target_image` dedicato (`capture<id>_analyze`) per non collidere
    con le annotazioni di eventuali confronti sulla stessa ripresa.

- **[webapp/public/study.php](webapp/public/study.php)**
  Nuovo pulsante "🔬 Analizza" su ogni scheda ripresa in archivio, accanto a
  "✨ Migliora".

## 2026-08-27 (5) — Fix: l'enhancement pre-analisi su A non era mai visibile

L'enhancement pre-analisi (denoise, desaturazione, ecc.) veniva applicato
correttamente a **entrambe** le riprese per il calcolo del confronto, ma solo
la versione elaborata di B veniva salvata su disco — "Originale A" mostrava
sempre il file grezzo non filtrato, dando l'impressione (falsa) che i filtri
agissero solo su B.

- **[python-service/app/routers/analysis.py](python-service/app/routers/analysis.py)**
  `/analysis/compare` salva ora anche `enhanced_a.jpg` (la ripresa A con gli
  stessi filtri di enhance_a usati nel calcolo) e la include nella risposta
  (`paths.enhanced_a`).

- **[webapp/public/api/compare.php](webapp/public/api/compare.php)**
  Include `enhanced_a` (se presente) negli `urls` restituiti al frontend.

- **[webapp/public/assets/js/study.js](webapp/public/assets/js/study.js)**
  Nuova `captureAUrl()`: "Originale A" e lo swipe "prima/dopo" usano ora
  `urls.enhanced_a` invece del file grezzo, con fallback automatico alla
  ripresa originale per i confronti salvati prima di questa correzione
  (che non hanno `enhanced_a`).

- **[webapp/public/study.php](webapp/public/study.php)**
  Tooltip di "Originale A/B" corretti: dicevano erroneamente "nessun filtro
  di elaborazione applicato per l'analisi" anche quando l'enhancement
  pre-analisi era attivo.

## 2026-08-27 (4) — Desaturazione B/N e indici spettrali NDVI/falso colore IR

Due nuovi strumenti di analisi: un filtro di desaturazione universale (utile
per concentrarsi su bordi/texture invece che su variazioni di colore) e il
supporto NDVI/falso colore infrarosso per le riprese Sentinel Hub, che ora
scaricano anche la banda NIR in coppia con il vero colore.

- **[python-service/app/core/enhance.py](python-service/app/core/enhance.py)**
  Nuovo filtro `desaturate(img, amount)`: sfuma gradualmente verso il
  bianco e nero (0=originale, 1=B/N completo), registrato in
  `FILTER_REGISTRY`. Disponibile ovunque (qualunque fonte), perché lavora
  solo sui pixel RGB già scaricati.

- **[python-service/app/core/sentinelhub_client.py](python-service/app/core/sentinelhub_client.py)**
  Refactor: `_process_request()` condivide la logica di chiamata al Process
  API tra `fetch_true_color()` (invariato) e la nuova `fetch_red_nir()`, che
  scarica la coppia Rosso (B04) + vicino infrarosso (B08) per la stessa
  area/periodo, codificata come PNG con lo stesso schema del vero colore.

- **[python-service/app/routers/fetch.py](python-service/app/routers/fetch.py)**
  `/fetch/sentinelhub` scarica ora anche la coppia Rosso+NIR in aggiunta al
  vero colore; un fallimento del fetch NIR (es. banda momentaneamente non
  disponibile) non blocca il download del vero colore, resta solo assente
  `nir_relative_path` nella risposta.

- **[python-service/app/core/spectral.py](python-service/app/core/spectral.py)** (nuovo)
  `compute_ndvi()` (NDVI = (NIR-Rosso)/(NIR+Rosso) da scala di grigi),
  `colorize_ndvi()` (palette diverging bruno→giallo→verde), `false_color_ir()`
  (composito R=NIR, G=Rosso, B=Verde della ripresa vero-colore).

- **[python-service/app/routers/analysis.py](python-service/app/routers/analysis.py)**
  Nuovo endpoint `/analysis/spectral_view` (`true_color_path`,
  `nir_red_path`, `mode`: 'ndvi'/'false_color_ir') che genera l'immagine
  risultato a partire dalla coppia Rosso+NIR scaricata.

- **[webapp/public/api/fetch_capture.php](webapp/public/api/fetch_capture.php)**
  Salva `nir_relative_path` (se presente) in `meta_json` della Capture
  Sentinel Hub creata: abilita i pulsanti NDVI/falso colore IR sulla scheda.

- **[webapp/public/api/spectral_view.php](webapp/public/api/spectral_view.php)** (nuovo)
  Verifica che la ripresa abbia la banda NIR (altrimenti errore chiaro) e
  inoltra la richiesta al servizio Python.

- **[webapp/public/api/compare.php](webapp/public/api/compare.php)**
  `build_enhance_steps()` include ora anche `desaturate`/`desaturate_amount`.

- **[webapp/public/study.php](webapp/public/study.php)**
  Controllo desaturazione (checkbox + slider) sia nel pannello "Migliora"
  sia nell'"Enhancement pre-analisi" del confronto; pulsanti "🌿 NDVI" e
  "🌈 Falso colore IR" sulle schede ripresa Sentinel Hub con banda NIR
  disponibile; nuovo pannello `#spectral-panel` per anteprima e salvataggio.

- **[webapp/public/assets/js/study.js](webapp/public/assets/js/study.js)**
  Bindings desaturate in `buildEnhanceSteps()`/`stepsToPipeline()` e nel
  payload di `run-compare-btn`; nuova `setupSpectralPanel()` con
  `window.openSpectralPanel()` (fetch, anteprima, salvataggio come nuova
  ripresa).

## 2026-08-27 (3) — Pan/zoom nell'editor punti di controllo

- **[webapp/public/study.php](webapp/public/study.php)**
  - Nuovo toggle **✎ Punto / ✋ Sposta** nell'editor punti di controllo: in
    "Sposta" il trascinamento sposta la vista invece di piazzare un punto.
  - Nuovo slider di zoom (10%–800%) per ciascuna ripresa, accanto ai preset
    Adatta/100/200/400% già presenti.
  - Fix: le due colonne griglia (`grid-template-columns: 1fr 1fr`) si
    espandevano per contenere l'immagine ingrandita invece di ritagliarla con
    lo scroll (il classico "grid blowout" da `min-width:auto` implicito sulle
    colonne `1fr`) — a zoom alto il contenitore cresceva a piena larghezza
    dell'immagine invece di mostrare le barre di scorrimento, rendendo
    panning/zoom inutilizzabili oltre il 100%. Corretto con `min-width:0`
    sulle due colonne.

- **[webapp/public/assets/js/study.js](webapp/public/assets/js/study.js)**
  `setupControlPointsEditor()`: nuova gestione modalità Punto/Sposta
  (`cpMode`), trascinamento a mouse e touch sui contenitori scroll (attivo
  solo in modalità Sposta, così il clic in modalità Punto resta affidabile),
  slider di zoom sincronizzato bidirezionalmente con i pulsanti preset.

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
