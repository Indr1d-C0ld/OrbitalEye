# Changelog

Registro delle modifiche sincronizzate dal deployment live a questo repo.
Ogni voce elenca i file toccati e cosa/perché è cambiato — stesso dettaglio
riportato nel messaggio del commit corrispondente.

## 2026-09-03 — Nuova funzione: condivisione su Telegram/X di riprese, confronti e riepiloghi di studio

Su richiesta esplicita, dopo una fase di progettazione condivisa: possibilità
per l'analista di condividere manualmente (mai in automatico, mai dal motore
di scaricamento pianificato) una singola ripresa, la vista corrente di un
confronto, o il riepilogo dell'ultimo confronto di uno studio, verso un canale
Telegram configurato una tantum o verso X (compose-window, nessuna API a
pagamento).

- **[webapp/src/TelegramClient.php](webapp/src/TelegramClient.php)** (nuovo)
  — client minimale per l'API bot Telegram (`sendPhoto`/`sendMessage`),
  nessuna dipendenza dal python-service: token e chat id letti da
  `AppSettings`, mai scritti su un file sincronizzabile.
- **[webapp/src/Share.php](webapp/src/Share.php)** (nuovo) — log di controllo
  (`Share::create`/`Share::recent`) di ogni condivisione effettuata, per
  sapere sempre cosa è stato reso pubblico e quando (stesso principio già
  applicato al log della ricerca inversa per immagini).
- **[webapp/public/api/share.php](webapp/public/api/share.php)** (nuovo) —
  endpoint unico per i tre tipi di contenuto (`capture`/`comparison`/`study`)
  e le due piattaforme (`telegram`/`twitter`); risolve l'immagine da inviare
  dal file già salvato sul server per confronti/studi, o dai bytes caricati
  dal client per la singola ripresa (già "cotta" con le regolazioni correnti
  lato client).
- **[webapp/public/assets/js/analyze.js](webapp/public/assets/js/analyze.js)**
  — pannello "Condividi" per la ripresa singola: invio a Telegram, copia
  dell'immagine negli appunti (per incollarla su X), apertura della finestra
  di composizione X. I pulsanti "Invia su Telegram" e "Copia negli appunti"
  ora si disabilitano durante la richiesta e si riabilitano solo a
  completamento (`finally`), per evitare invii duplicati su doppio
  click/tap; il controllo "immagine non generata" (`blob` nullo) è ora
  applicato anche al pulsante di copia, non solo a quello Telegram.
- **[webapp/public/assets/js/study.js](webapp/public/assets/js/study.js)** —
  nuova `setupShareBlock()`, riusata sia per la vista corrente di un
  confronto sia per il riepilogo di studio (qui l'immagine è già un file
  salvato sul server, nessun "bake-in" lato client necessario per Telegram).
  Stessa protezione anti-doppio-invio aggiunta ai pulsanti Telegram e copia.
- **[webapp/public/analyze_capture.php](webapp/public/analyze_capture.php)**,
  **[webapp/public/study.php](webapp/public/study.php)** — markup dei nuovi
  pannelli "Condividi"/"Riepilogo di studio" e didascalia predefinita
  (semplificata dopo un primo tentativo ridondante: `{titolo} — OrbitalEye`).
- **[webapp/public/settings.php](webapp/public/settings.php)** — nuovo
  pannello "Condivisione — Telegram" (token bot, chat id, pulsante di test
  di connessione) con promemoria che il bot va aggiunto come amministratore
  del canale (altrimenti Telegram risponde "chat not found").
- **[webapp/schema.sql](webapp/schema.sql)** — nuova tabella `shares`
  (log delle condivisioni, `ref_id` polimorfico senza FK dato che punta a
  tabelle diverse secondo `kind`).
- **[webapp/src/AppSettings.php](webapp/src/AppSettings.php)** — nuove
  chiavi di default `telegram_bot_token`/`telegram_chat_id`.

Verificato con un invio reale al canale Telegram configurato dall'utente
(credenziali fornite e usate solo in produzione, mai scritte in un file
sincronizzato). Dopo l'implementazione, eseguita una revisione di codice
completa dell'intera funzionalità: trovati e corretti 4 problemi (nessuno
grave) — assenza di una protezione anti-doppio-click sui tre pulsanti "Invia
su Telegram" (rischio concreto: invio duplicato della stessa immagine sul
canale reale), lo stesso controllo mancante sul pulsante "Copia negli
appunti", e un riferimento a un nome di file ormai errato nel commento di
`TelegramClient.php`.

## 2026-09-02 (5) — Fix: un errore di rete nello scaricamento pianificato faceva perdere il riferimento all'ultima ripresa

Trovato durante un controllo end-to-end completo del meccanismo di
scaricamento pianificato (richiesto esplicitamente per verificarne lo
stato): riprodotto fermando deliberatamente il servizio a metà test.

- **[webapp/src/ScheduledDownload.php](webapp/src/ScheduledDownload.php)**
  `recordRun()` scriveva sempre `last_capture_id` con il valore ricevuto,
  incluso `null` per un esito `'error'` (fetch fallito, nessuna ripresa
  nuova) — cancellando così il riferimento all'ultima ripresa nota. Al
  tentativo successivo, riuscito, la pianificazione veniva trattata come
  "prima ripresa mai scaricata": nessun confronto con quella davvero
  precedente, alert generato anche se la nuova ripresa era in realtà
  identica. Ora usa `COALESCE(:cap, last_capture_id)`: un esito di errore
  non tocca più il riferimento, che resta intatto fino alla prossima
  esecuzione con esito `'new'`/`'duplicate'`.

Verificato riproducendo la sequenza reale (base scaricata → servizio
fermato a metà → errore registrato correttamente senza perdere il
riferimento → servizio ripristinato → esecuzione successiva confrontata
correttamente contro la ripresa precedente, sia per lo scarto duplicato
sia per il rilevamento di una ripresa diversa con alert). Nessuna modifica
al python-service.

## 2026-09-02 (4) — Ricerca inversa per immagini: solo Google Lens

Dopo uso reale: il pulsante "Apri tutti i motori" in pratica apriva solo la
prima scheda (i browser bloccano come popup le `window.open()` successive
alla prima nello stesso gesto utente) — invece di risolverlo, scelta
diretta di tenere un solo motore. Google Lens si è dimostrato il più
efficace per il riconoscimento e supporta davvero l'incolla da appunti
(confermato con l'uso, a differenza di Yandex Images la cui compatibilità
restava incerta).

- **[webapp/public/analyze_capture.php](webapp/public/analyze_capture.php)**
  Pannello "Ricerca inversa per immagini" semplificato: rimossi i pulsanti
  "Apri tutti i motori"/Yandex/Bing/TinEye e la nota sull'incolla
  motore-per-motore, resta solo "🔍 Apri Google Lens".

- **[webapp/public/assets/js/analyze.js](webapp/public/assets/js/analyze.js)**
  Rimossi `CROP_SEARCH_ENGINES` e i listener dei motori non più presenti;
  il pulsante Google Lens resta l'unico, con l'URL diretto invariato.

## 2026-09-02 (3) — Fix: ritaglio ruotato ancora distorto + scaricamento Esri che falliva su aree molto ampie

Un secondo giro sul fix di ieri (voce (5) del 2026-09-01): il ricampionamento
forzato introdotto allora ("garantisce sempre le dimensioni richieste")
schiacciava/stirava ancora il contenuto — solo in modo meno vistoso —
perché forzava comunque il ritaglio finale a un rapporto d'aspetto fisso
(quello di larghezza/altezza richieste, tipicamente 1:1) invece di lasciarlo
proporzionato alla vera forma dell'area scelta. Individuato riproducendo
esattamente (stesso rettangolo, stessa rotazione) il caso reale segnalato e
confrontando visivamente il risultato con un'immagine di riferimento non
ruotata — vedi anche la verifica indipendente della scala di misura su un
riferimento reale noto (apertura alare di un velivolo riconoscibile
nell'immagine).

- **[webapp/src/ImageRotateCrop.php](webapp/src/ImageRotateCrop.php)**
  `rotateAndCrop()`: rimosso il ricampionamento forzato a una dimensione
  fissa. Il ritaglio finale mantiene ora **sempre** il vero rapporto
  d'aspetto del rettangolo scelto; se supera `$maxOutputPx` (default 2048)
  per lato viene solo ridotto proporzionalmente, mai stirato in modo
  diverso sui due assi. Firma semplificata: da
  `(..., int $targetWidthPx, int $targetHeightPx)` a `(..., int $maxOutputPx = 2048)`.

- **[webapp/src/CaptureFetcher.php](webapp/src/CaptureFetcher.php)**
  Aggiornati i punti di chiamata a `rotateAndCrop()`/`applyRotationToStoredImage()`
  per la nuova firma (nessuna dimensione forzata).

- **[python-service/app/core/esri_client.py](python-service/app/core/esri_client.py)**
  Scoperto durante l'indagine sul punto sopra: il servizio pubblico Esri
  World Imagery rifiuta silenziosamente (HTTP 500, corpo "Error: bytes") le
  richieste di export oltre una soglia di complessità non documentata —
  verificato empiricamente non essere un semplice limite per lato o per
  pixel totali, dipende anche da quanta risoluzione sorgente è realmente
  disponibile in quel punto. Diventa frequente con la rotazione dell'area
  su rettangoli molto allungati. `fetch_world_imagery()` ora ritenta
  automaticamente a risoluzione ridotta (stesso rapporto d'aspetto, ×0.7
  per tentativo, fino a 4 tentativi) invece di fallire subito: verificato
  contro il servizio reale su due casi che prima fallivano, entrambi
  risolti (uno al primo ritentativo, un altro rientrato nella soglia già al
  tentativo iniziale su un'area diversa — conferma che il limite dipende
  dalla zona, non da una formula fissa).

## 2026-09-02 (2) — Ricerca inversa per immagini: copia negli appunti + apertura di tutti i motori in un click

- **[webapp/public/assets/js/analyze.js](webapp/public/assets/js/analyze.js)**
  Nuovo pulsante "📋 Copia negli appunti" (Clipboard API, richiede contesto
  sicuro HTTPS/localhost — messaggio chiaro con fallback a "Scarica
  frammento" se non disponibile) e "🔗 Apri tutti i motori" (apre le 4 tab
  in un solo click, tutte sincrone nello stesso gestore per non farle
  bloccare come popup). L'invio effettivo del file resta comunque sempre un
  gesto manuale ed esplicito dell'analista (incolla/trascina) — nessuna
  automazione dell'upload verso servizi terzi, per scelta deliberata.

- **[webapp/public/analyze_capture.php](webapp/public/analyze_capture.php)**
  Pannello "Ricerca inversa per immagini" aggiornato con i nuovi controlli
  e una nota su quali motori supportano l'incolla (confermato: TinEye,
  Bing; non garantito: Google Lens, Yandex Images).

## 2026-09-01 (5) — Fix: misurazione imprecisa e overlay distorto sulle riprese ruotate/non quadrate

Due difetti di onestà dei dati segnalati da un uso reale della funzione di
rotazione area introdotta in questa stessa giornata (voce (3) più sotto),
entrambi con causa più profonda della sola rotazione — corretti alla
radice, non solo per il caso segnalato.

**1) Scala di misurazione sbagliata sulle riprese ruotate.** `pixelDistance()`
applicava le scale m/pixel per asse (mppX/mppY, calcolate su lon/lat)
assumendo che l'asse X dell'immagine fosse sempre l'asse longitudine e Y
sempre l'asse latitudine — vero per ogni ripresa normale, falso per una
ripresa scaricata ruotata (i suoi assi pixel sono ruotati di quell'angolo
rispetto a lon/lat). L'errore cresce con l'angolo e con l'anisotropia
mppX/mppY (già frequente: la compressione di un grado di longitudine in
metri varia con la latitudine), fino a un fattore prossimo a mppX/mppY per
misurazioni vicine a 45°/135° rispetto all'asse ruotato.

- **[webapp/public/assets/js/analyze.js](webapp/public/assets/js/analyze.js)**
  `pixelDistance()` ora riporta lo spostamento nel sistema locale allineato
  a lon/lat (ruotandolo indietro di `CFG.rotation`, inversa esatta della
  trasformazione applicata in `ImageRotateCrop.php` in fase di
  scaricamento) *prima* di applicare mppX/mppY — verificato con test
  numerico indipendente su più angoli e direzioni di misura.
  Nessun cambiamento per `CFG.rotation` assente/zero (la stragrande
  maggioranza delle riprese esistenti).

- **[webapp/public/analyze_capture.php](webapp/public/analyze_capture.php)**
  Nuovo campo `rotation` in `window.ORBITALEYE_ANALYZE`, letto da
  `meta_json`.

**2) Overlay distorto su riprese non quadrate.** Le frazioni di dimensione
dell'overlay (`baseWFrac`/`baseHFrac`, calcolate al caricamento per
preservare il rapporto d'aspetto reale dell'immagine sovrapposta) venivano
poi moltiplicate ciascuna per `canvas.width`/`canvas.height` *separatamente*
in `renderOverlay()`/`drawOverlayOnto()`: corretto solo se il canvas è
quadrato. Su qualunque ripresa non quadrata (un ritaglio rettangolare,
sempre più comune da quando esiste la rotazione dell'area) la sovrapposizione
risultava distorta di un fattore pari al rapporto d'aspetto del canvas.
Bug preesistente alla rotazione, solo reso più evidente da essa.

- **[webapp/public/assets/js/analyze.js](webapp/public/assets/js/analyze.js)**
  Il calcolo di `baseWFrac`/`baseHFrac` ora considera anche l'aspect ratio
  del canvas (`aspect / canvasAspect` al posto del solo `aspect`
  dell'immagine caricata): su canvas quadrato il comportamento è invariato
  (era già corretto in quel caso), su canvas non quadrato l'overlay
  mantiene finalmente il proprio rapporto d'aspetto reale.

**3) Causa più a monte scoperta durante l'indagine: risoluzione silenziosamente
degradata su un asse per rettangoli molto allungati ruotati anche di pochi
gradi.** `scaledFetchSize()` derivava larghezza/altezza da richiedere al
provider da `rect` (rapporto d'aspetto A), ma il bbox da scaricare per
davvero ha un rapporto d'aspetto diverso (B, cambia con l'angolo) — se A e B
divergono abbastanza, Esri applica una propria correzione indipendente
(`_adjust_bbox_to_aspect`, pensata per il caso normale non ruotato) che
espande il bbox in modo non prevedibile da qui. Caso reale che ha innescato
l'indagine: un'area 2.5:1 (Sigonella) ruotata di soli 6° aveva prodotto un
ritaglio 1024×409 invece dei 1024×1024 attesi — non distorto, ma con una
risoluzione reale su un asse 2.5 volte inferiore al previsto (e una scala
d'immagine complessivamente meno accurata).

- **[webapp/src/ImageRotateCrop.php](webapp/src/ImageRotateCrop.php)**
  - `scaledFetchSize()` ora richiede sempre larghezza/altezza con lo STESSO
    rapporto d'aspetto del bbox da scaricare (usando la maggiore delle due
    densità pixel/grado implicite in `rect`, applicata a entrambi gli assi
    del bbox allargato) — la correzione di Esri diventa un no-op nella
    stragrande maggioranza dei casi. Il clamp al limite massimo per lato
    ora riscala entrambe le dimensioni dello stesso fattore (non più
    indipendentemente), preservando l'aspect ratio anche quando scatta.
  - `rotateAndCrop()` accetta due nuovi parametri (`$targetWidthPx`,
    `$targetHeightPx`) e **ricampiona sempre** il ritaglio geometrico alla
    dimensione finale richiesta (`imagecopyresampled`): la ripresa salvata
    ha sempre esattamente le dimensioni promesse, qualunque sorpresa
    accada a monte (arrotondamenti, clamp, ulteriori correzioni d'aspetto
    del provider) — rete di sicurezza in più oltre al fix del punto sopra.

- **[webapp/src/CaptureFetcher.php](webapp/src/CaptureFetcher.php)**
  Passa le dimensioni richieste dall'utente a `rotateAndCrop()` come
  target garantito.

Verificato con test pixel dedicati (geometria di ritaglio, aspect ratio
richiesto vs bbox, dimensione finale garantita anche simulando la
correzione Esri) e con test numerico indipendente per la formula di
misurazione, su più angoli e casi limite (incluso il caso reale
segnalato). Nessuna modifica al python-service.

## 2026-09-01 (4) — Scaricamento automatico pianificato, con rilevamento duplicati e alert

Nuovo meccanismo, pensato per il monitoraggio nel tempo di un'area senza
doverla riscaricare a mano ogni volta: per ogni sezione di scaricamento
(Sentinel Hub/Esri) si può ora attivare un controllo periodico che scarica
di nuovo la stessa area, la confronta automaticamente con l'ultima ripresa
già tenuta e scarta da sola le riprese identiche (nessun accumulo di
doppioni), generando invece un alert quando arriva qualcosa di
effettivamente diverso. Esecuzione da cron (non da richiesta web): nessuna
modifica al python-service, riusa l'endpoint `/analysis/compare` già
esistente (stessa pipeline SSIM+maschera del confronto manuale) per
decidere se scartare o tenere.

- **[webapp/schema.sql](webapp/schema.sql)**
  Due nuove tabelle: `scheduled_downloads` (area/fonte/parametri di fetch,
  intervallo in giorni, soglia di duplicato, esito/ripresa dell'ultima
  esecuzione) e `alerts` (notifiche "nuova ripresa diversa dalla precedente",
  legate a studio/ripresa/pianificazione).

- **[webapp/src/CaptureFetcher.php](webapp/src/CaptureFetcher.php)** (nuovo)
  Estrae in una classe condivisa la logica di scaricamento che prima viveva
  interamente in `api/fetch_capture.php` (validazione, rotazione/ritaglio,
  chiamata al servizio Python, salvataggio) — necessaria sia alla richiesta
  interattiva dell'utente sia al cron, senza duplicare ~150 righe. Le
  condizioni d'errore diventano `CaptureFetchException` con uno
  `httpStatus` associato (404/400/502/500 a seconda del caso), così chi
  chiama da HTTP può rispondere col codice giusto e chi chiama da CLI può
  semplicemente loggare il messaggio.

- **[webapp/public/api/fetch_capture.php](webapp/public/api/fetch_capture.php)**
  Ridotto a un sottile wrapper attorno a `CaptureFetcher::fetchAndSave()`:
  stesso comportamento/contratto di prima, logica vera altrove.

- **[webapp/src/ScheduledDownload.php](webapp/src/ScheduledDownload.php)** (nuovo)
  CRUD sulle pianificazioni + `due()` (quelle la cui prossima esecuzione è
  scaduta, calcolato interamente in SQL con `datetime(last_run_at, '+N days')`)
  + `recordRun()` (registra esito/ripresa/errore di ogni esecuzione).

- **[webapp/src/Alert.php](webapp/src/Alert.php)** (nuovo)
  Creazione/lettura degli alert, conteggio non letti (per il badge in
  sidebar), segna-come-letto singolo/tutti.

- **[webapp/cli/run_scheduled_downloads.php](webapp/cli/run_scheduled_downloads.php)** (nuovo)
  Entrypoint da cron. Per ogni pianificazione scaduta: ricalcola la finestra
  di date "scorrevole" per Sentinel Hub (`date_window_days` giorni indietro
  da *oggi*, mai date fisse), scarica, e se esiste già una ripresa
  precedente per quella pianificazione la confronta via `/analysis/compare`
  (SSIM, allineamento automatico): sotto la soglia di duplicato configurata
  scarta la nuova ripresa (`Capture::delete`, nessun file orfano), altrimenti
  la tiene e crea un alert. Un fallimento nel confronto non scarta mai per
  prudenza (tiene la ripresa e segnala di verificarla a mano).

- **[webapp/public/api/schedule_download.php](webapp/public/api/schedule_download.php)** (nuovo)
  GET (elenco pianificazioni di uno studio), POST con `action`
  create/toggle/delete. La creazione scarica subito una prima ripresa di
  base (così la pianificazione non resta vuota fino al prossimo passaggio
  del cron, anche a un giorno di distanza) e genera il primo alert
  "pianificazione avviata".

- **[webapp/public/api/alerts.php](webapp/public/api/alerts.php)** (nuovo)
  GET (elenco + conteggio non letti), POST `mark_read`/`mark_all_read`.

- **[webapp/public/alerts.php](webapp/public/alerts.php)** (nuovo)
  Pagina dedicata: elenco alert con link diretto alla ripresa, segna
  letto/tutti letti.

- **[webapp/public/partials/nav.php](webapp/public/partials/nav.php)**
  Nuova voce "🔔 Alert" in sidebar con badge del conteggio non letti.

- **[webapp/public/study.php](webapp/public/study.php)** / **[webapp/public/assets/js/study.js](webapp/public/assets/js/study.js)**
  Nuovo pannello "Scaricamento automatico pianificato" in ciascuna sezione
  di scaricamento: checkbox di attivazione, intervallo (giorni/settimane,
  minimo 1 giorno), finestra di ricerca composito (solo Sentinel Hub), soglia
  di duplicato (% variazione minima), elenco delle pianificazioni già attive
  per quell'area con sospendi/riattiva/elimina.

Nessun canale email per gli alert (solo notifica integrata in piattaforma) e
intervallo minimo di un giorno: entrambe scelte esplicite per restare
coerenti con la reale cadenza di revisit di queste fonti (Sentinel-2 ~5
giorni, i compositi Esri si aggiornano sporadicamente) ed evitare consumi
inutili delle rispettive quote API.

## 2026-09-01 (3) — Rotazione dell'area di interesse in fase di scaricamento

Controlli di rotazione per il rettangolo di selezione, vicino al toggle
"Sposta mappa" di entrambe le sezioni Sentinel Hub/Esri: né l'API Sentinel
Hub né quella Esri supportano bbox ruotate nativamente, quindi la
piattaforma scarica un'area di raccolta più ampia (che racchiude per intero
il rettangolo ruotato, a risoluzione aumentata di conseguenza per non
perdere metri/pixel) e la ritaglia server-side per ottenere esattamente
l'area voluta, già dritta.

- **[webapp/src/ImageRotateCrop.php](webapp/src/ImageRotateCrop.php)** (nuovo)
  `enclosingBbox()` (bbox non ruotata che racchiude il rettangolo ruotato,
  simmetrica rispetto al segno dell'angolo, con margine di sicurezza 3%),
  `scaledFetchSize()` (pixel da richiedere sull'area allargata per
  mantenere lo stesso metri/pixel del rettangolo originale, con tetto
  configurabile) e `rotateAndCrop()` (ruota via GD l'immagine scaricata e
  ne ritaglia esattamente le dimensioni del rettangolo originale — verificata
  con test pixel su più angoli, inclusi i segni di rotazione di GD, che
  ruota in senso antiorario per angoli positivi).

- **[webapp/public/api/fetch_capture.php](webapp/public/api/fetch_capture.php)**
  Nuovo campo `rotation` nel body: se diverso da zero, calcola la bbox
  allargata da scaricare davvero, applica il ritaglio ruotato al risultato
  ed elimina il file grezzo allargato (non serve più a nessun uso
  successivo). `meta_json.bbox` salvato resta **sempre** il rettangolo di
  base (mai quello allargato): lo strumento di misura continua a funzionare
  esattamente come prima, nessuna modifica al calcolo della scala.

- **[webapp/public/assets/js/map-picker.js](webapp/public/assets/js/map-picker.js)**
  Rettangolo disegnato come `L.polygon` (invece di `L.rectangle`) quando la
  rotazione è diversa da zero, con i 4 angoli ruotati in coordinate schermo
  (proiezione Leaflet, non lon/lat grezze) attorno al proprio centro —
  anteprima visivamente corretta a qualunque zoom/latitudine. I 4 campi
  lon/lat continuano a rappresentare il rettangolo di base non ruotato,
  invariati per tutto il resto della piattaforma.

- **[webapp/public/study.php](webapp/public/study.php)**
  Slider di rotazione (-180°/180°) + pulsante di reset, vicino al toggle
  "Sposta mappa" di entrambe le sezioni, come richiesto.

- **[webapp/public/assets/js/study.js](webapp/public/assets/js/study.js)**
  Il valore di rotazione (se diverso da zero) viene incluso nel payload
  inviato a `fetch_capture.php`.

## 2026-09-01 (2) — Maniglie dirette per ridimensionare/ruotare l'overlay

Controlli diretti sul livello di sovrapposizione, oltre agli slider già
esistenti: 4 maniglie d'angolo per ridimensionare dal centro, 1 maniglia
sopra per ruotare, trascinabili direttamente sull'immagine sovrapposta.
Inclinazione e opacità restano solo a slider (scelta di scope, per non
moltiplicare la complessità delle maniglie).

- **[webapp/public/assets/js/analyze.js](webapp/public/assets/js/analyze.js)**
  - `overlayLocalToCanvas()`: trasforma un punto locale (relativo al centro
    dell'overlay, prima di scala/inclinazione/rotazione) in coordinate
    canvas correnti, con la stessa identica composizione
    inclinazione-poi-rotazione già usata e verificata in `drawOverlayOnto()`
    — le maniglie seguono esattamente la forma visibile qualunque sia la
    trasformazione corrente.
  - `drawOverlayHandles()` / `hitOverlayHandle()`: disegno e hit-test delle
    4 maniglie d'angolo (ridimensiona) + 1 di rotazione (cerchio vuoto, per
    distinguerla a colpo d'occhio), visibili solo in modalità "Sovrapponi".
  - `handleStart`/`handleMove` estesi con due nuovi stati di trascinamento,
    `resize-overlay` (ridimensionamento uniforme dal centro, ignorando
    l'inclinazione corrente come approssimazione accettata) e
    `rotate-overlay` (angolo calcolato con `atan2` rispetto al centro) —
    entrambi sincronizzano in tempo reale gli slider corrispondenti.
  - Corretto un piccolo effetto collaterale scoperto durante
    l'implementazione: `redrawAnnotations()` pulisce l'intero canvas
    condiviso, quindi a fine trascinamento in modalità overlay va
    richiamato anche `renderOverlay()` per farlo ricomparire (già capitava,
    solo poco visibile, anche per il trascinamento del corpo dell'overlay
    esistente da prima).

- **[webapp/public/analyze_capture.php](webapp/public/analyze_capture.php)**
  Titolo del pulsante "🖼 Sovrapponi" aggiornato per descrivere anche le
  nuove maniglie (corpo per spostare, angoli per ridimensionare, maniglia
  sopra per ruotare).

## 2026-09-01 — Modalità "Sposta" attiva di default ovunque presente

In ogni sezione con un toggle "Sposta"/"Sposta mappa" accanto ad altre
modalità (Annota, Disegna area, ecc.), ora si apre già in modalità Sposta:
la prima cosa che si vuole fare aprendo un confronto o un selettore di area
è quasi sempre esplorare/inquadrare, non disegnare — partire già in modalità
attiva rischiava annotazioni/aree accidentali durante la prima esplorazione.

- **[webapp/public/analyze_capture.php](webapp/public/analyze_capture.php)**
  Pulsante attivo di default nel toolbar della pagina: "✋ Sposta" invece di
  "✎ Annota".
- **[webapp/public/study.php](webapp/public/study.php)**
  Stesso cambio sul toolbar del riquadro di confronto principale e su
  entrambi i map-picker (Sentinel Hub ed Esri): "✋ Sposta"/"✋ Sposta mappa"
  attivi di default.
- **[webapp/public/assets/js/study.js](webapp/public/assets/js/study.js)**
  `stageMode` iniziale cambiato da `'annotate'` a `'pan'`.
- **[webapp/public/assets/js/map-picker.js](webapp/public/assets/js/map-picker.js)**
  `drawMode` iniziale cambiato da disegno ad attivo-spento, con
  `setDrawMode(false)` esplicito in fase di init (la funzione già gestiva
  correttamente lo stato visivo di entrambi i pulsanti, nessun'altra
  modifica necessaria).
- **[webapp/public/assets/js/analyze.js](webapp/public/assets/js/analyze.js)**
  `mode` iniziale cambiato da `'annotate'` a `'pan'`.

## 2026-08-29 (3) — Fix: scala errata sulle riprese Esri con bbox non quadrata

L'operazione "export" di ArcGIS MapServer (World Imagery) espande
automaticamente la bbox richiesta quando il suo rapporto larghezza/altezza
in gradi non combacia con quello dell'immagine richiesta (di norma
quadrata), per evitare di restituire un'immagine distorta — ma la
piattaforma continuava a salvare la bbox *originale* come riferimento
geografico invece di quella *effettivamente coperta*. Su bbox molto
rettangolari l'errore di scala risultante nello strumento di misura poteva
arrivare a un fattore 2× o più (verificato: 0.56 m/pixel calcolati contro
1.37 m/pixel reali su un caso con rapporto d'aspetto 2.43). Sentinel Hub
non è affetto (la Process API campiona esattamente la bbox richiesta).

- **[python-service/app/core/esri_client.py](python-service/app/core/esri_client.py)**
  Nuova `_adjust_bbox_to_aspect()`: pre-adatta la bbox richiesta esattamente
  con la stessa logica di ArcGIS (confronto diretto lon/lat vs width/height,
  senza conversione a metri) *prima* di inviarla — ArcGIS non deve più
  modificarla. `fetch_world_imagery()` ora ritorna una tupla
  `(bytes, bbox_effettiva)` invece del solo `bytes`.

- **[python-service/app/routers/fetch.py](python-service/app/routers/fetch.py)**
  `/fetch/esri` include la bbox effettiva (non quella richiesta) nella
  risposta.

- **[webapp/public/api/fetch_capture.php](webapp/public/api/fetch_capture.php)**
  Salva `$result['bbox']` (quella effettiva restituita dal servizio) in
  `meta_json`, non più la bbox originale del form.

Corretta retroattivamente anche la ripresa Esri già presente in produzione,
ricalcolando la bbox corretta dai dati già noti (width/height/bbox
originale), senza doverla riscaricare.

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
