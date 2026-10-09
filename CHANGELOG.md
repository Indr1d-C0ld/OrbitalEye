# Changelog

Registro delle modifiche sincronizzate dal deployment live a questo repo.
Ogni voce elenca i file toccati e cosa/perché è cambiato — stesso dettaglio
riportato nel messaggio del commit corrispondente.

## 2026-10-09 (3) — Confronto affidabile fra riprese ad alta risoluzione di epoche diverse

Limite rimasto aperto dal punto 2 del piano di evoluzione: fra riprese
sotto il metro di epoche, sensori o elaborazioni diverse, il confronto
segnava come cambiata quasi tutta l'area.

### Diagnosi, sulle riprese reali

Versioni Wayback di un piazzale di Sigonella (2022, 2023 WV02, 2024) e di
un'area di Ghedi (2020, 2025, con un grande cantiere reale), più coppie
della stessa acquisizione come controllo:

- **SSIM:** 94–99% dell'area "cambiata" fra epoche diverse, 0% sui
  controlli.
- **Causa principale:** sotto il metro lo SSIM misura la correlazione
  della trama fine (cemento, erba, macchie), che fra due foto diverse è
  quasi nulla ovunque. Spostando di 1,5 m la stessa identica foto si
  passava dallo 0% al 95%.
- **Ricampionare a 2–3 m da solo non basta:** con lo SSIM resta il 75–95%.
- **Soglie adattive dalla mediana, scartate:** con questo rumore di fondo
  non segnalano più nulla, nemmeno il cantiere di Ghedi.
- **Attenuazione delle ombre, scartata:** il criterio "scuro in una sola
  delle due immagini" prende anche velivoli grigio scuro e vegetazione. Un
  velivolo scuro comparso su cemento chiaro rischiava di sparire.

### Confronto robusto

- **[python-service/app/core/diff.py](python-service/app/core/diff.py)** —
  nuovo `compute_robust_diff` (metodo `robust`):
  1. Colori di B portati su quelli di A in L\*a\*b\* (media e deviazione
     per canale, solo dove entrambe hanno dati).
  2. Entrambe ricampionate a celle quadrate di 2 m a terra, per asse: le
     riprese in gradi hanno pixel diversi sui due assi.
  3. Distanza di colore fra le celle, riportata alla griglia originale
     (0–255, stesse soglie degli altri metodi).
- **Scelte e protezioni:**
  - La luminosità pesa circa 2,5 volte il colore, per la codifica a 8 bit
    di OpenCV. È voluto: a pesi uguali il colore della vegetazione, che
    cambia con la stagione, alzava Ghedi dal 62% al 76%.
  - Fuori dai dati validi (bordi neri del warp) B prende i valori di A
    prima della media. Altrimenti il nero si mescolava ai pixel vicini e
    lungo i bordi compariva una fascia di falso cambiamento, più larga con
    pixel più fini.
  - Se una delle due immagini è senza colore (foto storica in bianco e
    nero, radar) si confronta solo la luminosità. Uniformare il colore
    segnava come cambiato metà dell'area.
  - Il guadagno con cui si uniformano i colori è limitato a 0,25–4.
- **Risultati sulle stesse coppie:**
  - Sigonella: 9–19% di area variata, concentrata su velivoli comparsi o
    spariti e sul cantiere, contro l'86–99% dello SSIM;
  - Ghedi: il cantiere resta segnalato (63%);
  - controlli: 0%;
  - 1,5 m di disallineamento della stessa foto: 1,5–2,2%, contro il 95%.
- **Limiti:** oggetti più piccoli della scala (veicoli) si attenuano.
  Ombre, inclinazione degli edifici e vegetazione possono ancora comparire
  come variazioni.
- **[python-service/app/routers/analysis.py](python-service/app/routers/analysis.py)**:
  - `diff_method: robust` e `analysis_scale_m` (predefinito 2 m);
  - mappa di calore amplificata solo per la visualizzazione: le distanze
    di colore stanno quasi tutte sotto 85 e la mappa risultava quasi nera.

### Scelta automatica e avvisi

- **[webapp/src/ComparisonRunner.php](webapp/src/ComparisonRunner.php)**:
  - nuovo metodo `auto`, ora predefinito. Diventa `robust` sotto
    1,5 m/pixel (asse più grossolano della ripresa A) e `ssim` sopra, o a
    scala ignota: per Sentinel non cambia nulla;
  - senza una scala nota, o con scala nulla nei metadati, anche il robusto
    scelto a mano diventa SSIM, perché non saprebbe quanto mediare. Il
    metodo richiesto viene ricondotto a uno di quelli previsti;
  - salvati sia il metodo richiesto sia quello effettivo, e la risposta
    riporta i parametri usati;
  - `DIFF_METHODS` è l'unico elenco dei metodi con le loro etichette.
- **[webapp/public/study.php](webapp/public/study.php)**,
  **[webapp/public/settings.php](webapp/public/settings.php)**,
  **[webapp/src/AppSettings.php](webapp/src/AppSettings.php)** — metodi
  dall'elenco unico; predefinito "Automatico (consigliato)"; spiegazione
  dei metodi aggiornata. Il valore salvato nelle impostazioni ora è
  validato.
- **[webapp/public/assets/js/study.js](webapp/public/assets/js/study.js)**:
  - riquadro "Confronto" fra i risultati, con metodo e scala;
  - avvisi sull'affidabilità della percentuale, anche per i confronti
    dello storico:
    - SSIM o differenza assoluta sotto 1,5 m/pixel;
    - variazione oltre il 35% dell'area: verificare con lo swipe prima di
      trarre conclusioni o pubblicare;
  - con il metodo robusto, una nota sui suoi limiti.
- **[webapp/src/ExportBuilder.php](webapp/src/ExportBuilder.php)** — nel
  report del pacchetto, il metodo per esteso e "(scelto automaticamente)".
- **[webapp/cli/run_scheduled_downloads.php](webapp/cli/run_scheduled_downloads.php)**:
  il confronto con la ripresa precedente usa la stessa scelta del metodo e
  lo stesso allineamento dalle coordinate della pagina. Prima segnava lo
  SSIM sotto il metro, e l'alert "Variazione rilevata" riportava le
  percentuali dell'86–99%. Ora su Sigonella dà 18,8%, come la pagina. Le
  soglie dei duplicati restano valide:
  - una nuova immagine Esri si riconosce già dalla data nei metadati e si
    tiene comunque;
  - la soglia conta solo quando i metadati mancano, e lì il confronto
    robusto dà 0% sulla stessa immagine e almeno il 9% fra epoche
    diverse.

### Test

- [python-service/tests/test_diff.py](python-service/tests/test_diff.py),
  5 nuovi test:
  - 1,5 m di disallineamento: oltre il 50% per lo SSIM, sotto il 5% per il
    robusto;
  - colori e contrasto diversi non contano come cambiamento;
  - un oggetto di ~16 m comparso su trama diversa viene rilevato;
  - nessuna fascia di cambiamento lungo i bordi senza dati;
  - una foto in bianco e nero contro una a colori della stessa scena.

  Gli ultimi due falliscono senza le relative protezioni.
- [webapp/tests/ComparisonTest.php](webapp/tests/ComparisonTest.php)
  (nuovo): scelta automatica del metodo.
- Totali: 41 test PHP, 25 Python.

Revisione indipendente: 5 punti, tutti corretti. I principali: fascia di
falso cambiamento ai bordi, bianco e nero contro colore, robusto senza
scala, cron rimasto sullo SSIM.

## 2026-10-09 (2) — Fondamenta tecniche: lavori in background, allineamento dalle coordinate, dettaglio nativo, test e CI, migrazioni

Quinta e ultima parte del piano di evoluzione.

### Lavori in background con avanzamento

Scaricamenti, confronti e rilevamenti duravano fino a qualche minuto dentro
una sola richiesta. La pagina restava ferma senza dire a che punto fosse e,
cambiando pagina, non si sapeva più se il lavoro fosse finito.

- **[webapp/src/Job.php](webapp/src/Job.php)** (nuovo),
  **[webapp/cli/run_job.php](webapp/cli/run_job.php)** (nuovo),
  **[webapp/public/api/jobs.php](webapp/public/api/jobs.php)** (nuovo):
  - La richiesta registra il lavoro e avvia un processo PHP separato, uno
    per lavoro, senza demoni né code esterne. Il processo lo esegue e
    aggiorna avanzamento e fase.
  - Presa in carico atomica: un lavoro parte una volta sola.
  - Il processo viene avviato con `setsid`, in una sessione propria, e
    l'avvio viene verificato: PID restituito e processo vivo, o lavoro già
    preso in carico, ripetuto per un secondo al massimo, perché all'inizio
    il processo può essere ancora la shell che sta per avviare PHP. Se non
    parte, il lavoro gira dentro la richiesta.
    L'uscita d'errore dei processi va in `data/jobs.log`.
  - Viene segnato come interrotto, invece di restare "in corso" per sempre,
    un lavoro che:
    - ha perso il processo. Il processo si riconosce dalla riga di comando
      in `/proc`, non dal solo PID, che il sistema può riassegnare;
    - è in esecuzione da oltre 20 minuti;
    - è rimasto in coda oltre due minuti.
  - Un lavoro che finisce mentre lo si sta controllando resta finito: conta
    l'esito scritto dal processo.
  - I lavori conclusi da più di un giorno vengono eliminati.
  - Se `exec` non è disponibile, il lavoro gira dentro la richiesta come
    prima.
  - Limite noto: un riavvio completo di Apache (`systemctl restart`)
    termina anche i lavori in corso, che stanno nel suo gruppo di processi.
    Vengono segnati come interrotti e si possono rilanciare. Il reload e il
    riavvio "graceful" non li toccano.
- **[webapp/src/ComparisonRunner.php](webapp/src/ComparisonRunner.php)**,
  **[webapp/src/DetectionRunner.php](webapp/src/DetectionRunner.php)** (nuovi) —
  la logica di `api/compare.php` e `api/detect.php` spostata in classi
  usate sia dalle API dirette (risposte invariate) sia dai lavori.
  `CaptureFetcher::fetchAndSave` riceve un callback di avanzamento con le
  fasi reali: ricerca del passaggio, scaricamento, lettura delle date,
  salvataggio.
- **[webapp/public/assets/js/common.js](webapp/public/assets/js/common.js)** —
  `runJob`, `waitJob` e barra di avanzamento con la fase in corso.
  Qualche errore di rete passeggero non fa perdere il lavoro, che intanto
  continua sul server.
- **[webapp/public/assets/js/study.js](webapp/public/assets/js/study.js)**,
  **[webapp/public/assets/js/analyze.js](webapp/public/assets/js/analyze.js)** —
  scaricamenti, confronti, rilevamenti (anche in serie dallo storico) e
  dettagli passano dai lavori. Riaprendo uno studio, un riquadro "⏳ Lavori
  in corso" ritrova quelli ancora attivi e ne segue la fine.

### Allineamento dalle coordinate

- **[python-service/app/core/registration.py](python-service/app/core/registration.py)**,
  **[routers/analysis.py](python-service/app/routers/analysis.py)** —
  nuovo `register_geo`.
  - L'omografia viene ricavata da punti calcolati dalle coordinate
    geografiche, non dalle immagini.
  - La rifinitura ECC viene accettata solo se migliora davvero la
    correlazione e sposta meno del 2% della diagonale: le coordinate sono
    già giuste a meno di qualche metro.
  - La correlazione prima e dopo la rifinitura si misura nello stesso modo:
    - solo dove B ha dati reali, altrimenti con riprese parzialmente
      sovrapposte i bordi neri del warp facevano scartare la rifinitura;
    - sulle immagini non sfocate. Il valore restituito da
      `findTransformECC` è misurato su immagini sfocate ed è più alto:
      faceva accettare quasi ogni rifinitura e gonfiava la confidenza.
  - Nuovo `register_auto`. Le coordinate salvate non sono sempre giuste: le
    riprese Esri scaricate prima della correzione dell'aspect ratio hanno
    bbox sbagliate anche di un fattore 2. Se dopo l'allineamento geografico
    le immagini restano poco correlate (sotto 0,5), si prova anche quello
    dalle immagini e si tiene il migliore, misurato allo stesso modo. La
    confidenza salvata è quella stessa correlazione per entrambi i metodi.
  - Si allinea con le immagini, invece di fallire, anche quando le
    coordinate non bastano: aree quasi disgiunte, punti in comune tutti su
    una riga (nel webapp servono almeno due righe e due colonne della
    griglia).
- **[webapp/src/Capture.php](webapp/src/Capture.php)** — nuovo
  `lonLatToFrac()`, inverso esatto di `fracToLonLat()` anche per le riprese
  ruotate in entrambi i modelli (errore ~1e-13).
- **ComparisonRunner** — in modalità automatica, se entrambe le riprese
  sono georiferite, 25 punti di A vengono portati in lon/lat e poi nei
  pixel di B.
  - Correlazione dopo l'allineamento, misurata allo stesso modo per i due
    metodi (dove B ha dati, senza sfocatura):
    - versione Wayback 2018 contro l'immagine attuale: 0,42 dalle
      coordinate più rifinitura, contro 0,37 dalle immagini;
    - su Sigonella, Sentinel-2 contro Esri: 0,53 per entrambi.
  - Il vantaggio è la robustezza più che la precisione: le coordinate non
    dipendono dal trovare dettagli in comune, che fra epoche e sensori
    diversi possono mancare o ingannare.
- **[webapp/public/study.php](webapp/public/study.php)**, **study.js** —
  nuova modalità "Solo dalle immagini" e riquadro "Allineamento" fra i
  risultati: dalle coordinate, coordinate + immagini, dalle immagini, punti
  manuali, non riuscito. In giallo i casi da verificare a vista.

### Scarica quest'area in dettaglio

- **[webapp/src/DetailFetcher.php](webapp/src/DetailFetcher.php)** (nuovo),
  **[webapp/public/analyze_capture.php](webapp/public/analyze_capture.php)**,
  **analyze.js** — da una ripresa Esri d'insieme, l'area del ritaglio
  viene riscaricata alla risoluzione nativa delle immagini, indicata dai
  metadati Esri.
  - Restano la stessa rotazione e, per una versione storica, la stessa
    versione dell'archivio.
  - Le dimensioni si chiedono in proporzione ai gradi, come le interpreta
    Esri. Con una proporzione in metri Esri allargava l'area in altezza
    (del 41% a 45° di latitudine) e il dettaglio peggiorava.
  - La risoluzione indicata è quella ottenuta, letta dalla ripresa salvata,
    non quella chiesta: il ritaglio ruotato e i tentativi a risoluzione
    ridotta di Esri possono abbassarla.
  - Per le riprese ruotate, la risoluzione indicata è quella dei pixel
    scaricati in latitudine. Il ritaglio ruotato ricampiona sul passo più
    fine, quello della longitudine, ma in latitudine è solo un
    ingrandimento.
  - Lato massimo 2500 px, con un avviso se l'area è troppo grande per la
    risoluzione nativa.
  - Il pulsante compare solo quando la ripresa è almeno 1,5 volte meno
    dettagliata delle immagini Esri.
  - Ghedi: da una ripresa di ~6 km a 7 m/pixel, un riquadro di 600 m
    riscaricato a 0,34 m/pixel (2500×2000 px; nativa 0,31). La bbox
    salvata coincide esattamente con quella chiesta.

### Migrazioni del database

- **[webapp/src/Database.php](webapp/src/Database.php)**,
  **[webapp/migrations/001_jobs.sql](webapp/migrations/001_jobs.sql)** (nuovo) —
  migrazioni numerate, applicate una volta sola, in ordine, ciascuna in una
  transazione e registrate in `schema_migrations`.
  - Una migrazione fallita blocca l'applicazione invece di lasciarla su uno
    schema a metà.
  - Confronto per nome fra file e versioni registrate: un file rinominato
    o rimosso non nasconde una migrazione nuova.
  - `BEGIN IMMEDIATE` e un nuovo controllo dentro la transazione: due
    richieste arrivate insieme dopo un aggiornamento applicano la
    migrazione una volta sola.
  - Se SQLite ha già annullato la transazione da sé (disco pieno, I/O),
    l'errore riportato resta quello vero.
  - `schema.sql` resta lo schema di base idempotente; le migrazioni servono
    per ciò che non può fare (aggiungere colonne, trasformare dati).

### Test automatici e CI

- **[webapp/tests/](webapp/tests/)** (nuovo) — 39 test PHP senza
  dipendenze esterne, su database e storage temporanei
  (`Config::set`), senza contattare servizi esterni. Coprono:
  - geometria (coordinate ↔ pixel con rotazione, scala, ritagli, punti per
    l'allineamento);
  - identificatore dei velivoli ed etichette per parola intera;
  - attribuzioni e chiavi di acquisizione;
  - schema e migrazioni;
  - lavori (errori, processi spariti, PID riassegnati, durata massima,
    lavori finiti durante il controllo);
  - dimensioni del dettaglio alla risoluzione nativa;
  - schede di pubblicazione (dimensioni, ritagli stretti, limiti) e coda
    di revisione.
- **[python-service/tests/](python-service/tests/)** (nuovo) — 20 test:
  - allineamento dalle coordinate con e senza rifinitura (anche con
    sovrapposizione parziale), rifinitura inutile che non gonfia la
    confidenza, aree disgiunte, punti in fila;
  - ripiego sulle immagini con coordinate sbagliate o insufficienti;
  - immagini scorrelate non dichiarate allineate;
  - aree delle regioni coerenti col totale;
  - tasselli e frammenti del rilevamento;
  - raggruppamento dei passaggi, statistiche con "NaN";
  - calcoli dei tasselli Wayback.
- **[.github/workflows/ci.yml](.github/workflows/ci.yml)** (nuovo) — a ogni
  push e pull request: sintassi e test PHP 8.4, test Python 3.13, sintassi
  JavaScript. Badge nel README.
  - Il runner esce con codice 1 anche se non esegue nessun test.
  - [webapp/src/bootstrap.php](webapp/src/bootstrap.php): senza
    `config.php`, il bootstrap usciva con codice 0 da riga di comando, e in
    CI i test PHP sarebbero risultati superati senza girare. Ora esce con
    codice 1, e i test, che forniscono la configurazione da sé, saltano il
    controllo.
- Script di sincronizzazione: aggiunte `webapp/migrations`, `webapp/tests`,
  `python-service/tests` e `webapp/cli/run_job.php`.

## 2026-10-09 — Flusso di pubblicazione: scheda, album Prima/Dopo, file a piena risoluzione, coda di revisione

Quarta parte del piano di evoluzione. Le condivisioni avevano due
composizioni diverse: la striscia data/fonte era disegnata nel browser per
riprese e ritagli e sul server per confronti e riepiloghi. Partivano sempre
come singola foto compressa da Telegram, e dritte sul canale.

### Composizione unica sul server

- **[webapp/src/PublicationComposer.php](webapp/src/PublicationComposer.php)** (nuovo) —
  GD con DejaVu Sans.
  - **Scheda di pubblicazione**: l'immagine e una fascia con titolo, data
    reale e sensore, barra di scala, freccia del nord e attribuzione
    completa (per Esri la frase dei termini delle immagini statiche).
  - **Scheda con Prima e Dopo affiancate**, una sopra l'altra se molto
    larghe, con l'etichetta e la data su ciascuna.
  - Larghezza fra 1000 e 2560 px: un ritaglio piccolo viene ingrandito, e
    la barra di scala ne tiene conto.
  - L'altezza dell'immagine non supera 4000 px. Un ritaglio stretto e alto
    (una pista, una nave) resta al centro di una fascia più larga invece di
    diventare 1000×22500, che Telegram rifiuterebbe.
  - Immagini oltre 40 megapixel rifiutate con un messaggio chiaro, nessuna
    copia superflua dei JPEG, limite di memoria alzato solo durante la
    composizione.
  - Un titolo senza spazi (hashtag, indirizzo) va a capo invece di finire
    sopra la scala; titolo limitato a 120 caratteri anche sul server.
  - La freccia del nord ruota per le aree scaricate ruotate: positivo =
    orario sulla mappa, quindi il nord è a −θ.
  - Nessuna coordinata viene aggiunta.
- **[webapp/src/PublicationBuilder.php](webapp/src/PublicationBuilder.php)** (nuovo) —
  da una richiesta (ripresa o ritaglio, confronto, riepilogo; formato
  scheda, affiancata, striscia o solo immagine; album; documento) produce
  le immagini da pubblicare.
  - La scala è solo quella della ripresa georiferita (`Capture::resolveMpp`),
    mai quella ricavata dall'area dello studio: su un'immagine pubblica una
    barra sbagliata sarebbe un'informazione falsa. Viene riportata
    all'immagine effettivamente caricata (`natural_width`, limitata alla
    larghezza della ripresa: un ritaglio ne copre una parte).
  - Il file "a piena risoluzione" di una scheda viene composto senza il
    limite di 2560 px delle foto Telegram, fino a 8000 px.
  - Verifica le immagini caricate (tipo reale, 10 MB).
  - La scheda affiancata si può avere anche dalla vista Prima/Dopo, che non
    è un'immagine.
- **[webapp/public/api/publication_preview.php](webapp/public/api/publication_preview.php)** (nuovo) —
  l'immagine composta esattamente come partirebbe, per l'anteprima e per la
  copia da incollare su X.
- **[webapp/src/ImageryAttribution.php](webapp/src/ImageryAttribution.php)** —
  `publicationCredit()` per una o più provenienze: un solo "© Esri" con
  tutti i fornitori e una sola frase legale. Prima, con due riprese Esri,
  la frase era ripetuta. Unione dei crediti in `mergeCredits()`, usata
  anche dalle strisce.

### Telegram: album, documento, link

- **[webapp/src/TelegramClient.php](webapp/src/TelegramClient.php)** — una
  chiamata multipart generica.
  - `sendMediaGroup` (album), `sendDocument` (file senza compressione),
    pulsanti-link, destinazione variabile (canale o chat di revisione).
  - Il token viene oscurato negli errori di rete.
  - Server Bot API locale facoltativo (`telegram_api_base` in config.php,
    file fino a 2 GB).
  - Il bot non riceve aggiornamenti (niente webhook né getUpdates): lo
    stesso bot può servire altri programmi e non c'è un endpoint pubblico.
- **Album di un confronto**: l'immagine principale nel formato scelto e,
  a piena dimensione, le due riprese confrontate, ciascuna con la propria
  data e il proprio fornitore.
- **File a piena risoluzione** allegato in risposta alla foto o all'album.
- **Errori parziali**: solo il primo invio (la foto o l'album) è
  decisivo. Se dopo fallisce il file allegato, il contenuto è già pubblico:
  viene registrato come pubblicato, con un avviso. Prima l'errore invitava
  a riprovare, e un secondo clic lo pubblicava due volte.
- Lunghezza della didascalia contata come la conta Telegram, in unità
  UTF-16 (un'emoji vale 2).

### Coda di revisione

- **[webapp/schema.sql](webapp/schema.sql)**,
  **[webapp/src/Publication.php](webapp/src/Publication.php)** (nuovo) —
  tabella `publications`.
  - Le immagini già composte vengono salvate in `storage/publications/`.
    Alla chat di revisione arriva un messaggio "🕵 DA APPROVARE" con il link
    alla coda e, in risposta, il contenuto con la didascalia identica a
    quella che andrà sul canale. Chi approva vede esattamente ciò che
    uscirà; un'intestazione dentro la didascalia l'avrebbe allungata, e
    vicino al limite tagliata.
  - Presa in carico atomica (stato transitorio `sending`): due
    approvazioni simultanee non pubblicano due volte. Una presa rimasta a
    metà da oltre 10 minuti (processo interrotto durante l'invio) torna
    decidibile.
  - Le immagini delle pubblicazioni scartate vengono eliminate.
- **[webapp/public/api/share.php](webapp/public/api/share.php)** — riscritto
  sul costruttore comune: invio diretto al canale oppure in coda. La
  didascalia oltre 1024 caratteri (limite di Telegram) viene rifiutata
  prima di comporre.
- **[webapp/public/api/publications.php](webapp/public/api/publications.php)** (nuovo),
  **[webapp/public/publications.php](webapp/public/publications.php)** (nuovo) —
  pagina "📤 Pubblicazioni".
  - Coda con le immagini, didascalia modificabile, "Approva e pubblica sul
    canale" e "Scarta".
  - Registro di tutto ciò che è stato reso pubblico, che prima non era
    consultabile da nessuna pagina.
  - La chat di revisione riceve l'esito, in risposta al messaggio
    originale.
- **[webapp/public/settings.php](webapp/public/settings.php)**,
  **[webapp/src/AppSettings.php](webapp/src/AppSettings.php)** — chat di
  revisione e indirizzo pubblico della piattaforma, per il link; un
  indirizzo che non è http(s) viene rifiutato. Il test di connessione
  scrive anche nella chat di revisione.
- **[webapp/public/partials/nav.php](webapp/public/partials/nav.php)** —
  voce "Pubblicazioni" con il numero di quelle in attesa.
- **[webapp/public/media.php](webapp/public/media.php)**,
  **[fix_permissions.sh](fix_permissions.sh)**, `.gitignore`,
  `storage/publications/.gitkeep` — nuova cartella `storage/publications/`.

### Interfaccia

- **[webapp/public/partials/publish_options.php](webapp/public/partials/publish_options.php)** (nuovo) —
  le stesse opzioni in tutti e quattro i blocchi di condivisione (ripresa,
  ritaglio, confronto, riepilogo): formato, titolo della scheda, album,
  file a piena risoluzione, "Passa dalla revisione".
- **[webapp/public/assets/js/common.js](webapp/public/assets/js/common.js)** —
  `publishOptions`, `fetchPublicationImage`, `openPublicationPreview`,
  `copyPublicationImage`, `sendPublication`.
  - L'anteprima si apre nel clic stesso; se il browser blocca la nuova
    scheda compare un link.
  - La copia negli appunti passa a ClipboardItem una promessa (Safari) e
    usa PNG, l'unico formato immagine accettato in modo affidabile dagli
    appunti: la vecchia copia in JPEG poteva fallire su Chrome.
  - Rimossa `drawAttributionStrip`: la striscia la fa solo il server.
  - Una risposta non JSON del server (errore fatale) produce un messaggio
    leggibile, non "Unexpected token '<'". Gli URL temporanei delle
    anteprime vengono liberati.
- **[webapp/public/assets/js/analyze.js](webapp/public/assets/js/analyze.js)**,
  **[webapp/public/analyze_capture.php](webapp/public/analyze_capture.php)**,
  **[webapp/public/assets/js/study.js](webapp/public/assets/js/study.js)**,
  **[webapp/public/study.php](webapp/public/study.php)**:
  - Copia di lavoro e ritaglio partono "puliti" verso il server.
  - Il frammento ritagliato resta senza scritte, perché serve alla ricerca
    inversa.
  - Nuovi pulsanti "👁 Anteprima" e, per il ritaglio, "📋 Copia per X".

### Verifica

Revisione indipendente del diff: nessun XSS, path traversal o fuga del
token; nessuna coordinata aggiunta; segno della freccia del nord, scale e
presa in carico atomica corretti. Corretti i 9 punti segnalati, descritti
sopra.

Prove in ambiente isolato con un finto server Bot API locale (tramite
`telegram_api_base`), che registra ogni chiamata:
- schede di ripresa, ritaglio, confronto e riepilogo; scheda affiancata
  anche dalla vista Prima/Dopo;
- album con documento;
- revisione → approvazione con didascalia corretta → canale e notifica;
  scarto con eliminazione dei file; doppia decisione bloccata;
- documento rifiutato da Telegram dopo la foto: pubblicato con avviso;
- immagini da 6000×4000 (composta con il limite di 128 MB), 8000×6000
  (rifiutata), ritaglio 40×900, titolo senza spazi;
- Impostazioni e test di connessione;
- interfaccia nel browser;
- 13 pagine senza errori.

## 2026-10-08 (3) — Strumenti di identificazione: velivoli dalle misure, rilevamento automatico, storico dell'area

Terza parte del piano di evoluzione. L'uso reale della piattaforma è
l'identificazione di velivoli sulle basi, fatta finora a occhio. Ora la
piattaforma misura, propone i tipi compatibili e conta nel tempo.

### Identificazione dalle misure

- **[webapp/src/aircraft_types.json](webapp/src/aircraft_types.json)** (nuovo) —
  166 tipi militari e civili frequenti nelle basi: caccia, addestratori,
  bombardieri, trasporti e aerocisterne, sorveglianza, executive, linea,
  droni, elicotteri. Per ogni tipo: apertura alare, lunghezza, rotore per
  gli elicotteri, forma delle ali e voce di riferimento.
  - Le dimensioni sono estratte in automatico dalle schede tecniche
    ("Aircraft specs") delle voci Wikipedia, non scritte a memoria.
  - 17 valori sono inseriti a mano e marcati "manuale": voci senza scheda
    standard, come le varianti di linea, e varianti molto diffuse come il
    C-130J-30 e il 737-700/C-40.
  - Per i 6 velivoli a geometria variabile c'è anche l'apertura ad ali a
    freccia, la configurazione tipica a terra.
- **[webapp/src/AircraftCatalog.php](webapp/src/AircraftCatalog.php)** (nuovo) —
  compatibilità con l'incertezza di misura: ±1,5 pixel per estremo alla
  scala della ripresa, più il 4%.
  - Per i riquadri del rilevamento automatico il confronto è asimmetrico:
    il velivolo ci sta dentro, ma il riquadro include margine e spesso
    l'ombra. Su Sigonella un'apertura vera di ~33 m dava un lato di 43,7 m.
  - Le dimensioni attese sono quindi intorno all'87% del lato, con
    tolleranza ampia verso il basso e stretta verso l'alto, e si provano
    entrambi gli abbinamenti lato/apertura.
  - Per gli elicotteri conta il diametro del rotore: la lunghezza nelle
    schede a volte include i rotori e a volte no.
  - Il filtro sulla forma delle ali (freccia, dritta, delta, geometria
    variabile, tutt'ala) separa tipi di dimensioni simili.
- **[webapp/public/api/aircraft_match.php](webapp/public/api/aircraft_match.php)** (nuovo),
  **[webapp/public/analyze_capture.php](webapp/public/analyze_capture.php)**,
  **[webapp/public/assets/js/analyze.js](webapp/public/assets/js/analyze.js)** —
  nel pannello Misurazioni, "Identifica velivolo dalle misure".
  - Tendine collegate all'elenco delle misure, preselezionate da etichette
    come "apertura alare" o "lunghezza"; filtri per categoria e forma delle
    ali (quest'ultimo disattivato per gli elicotteri).
  - La scelta è un riferimento alla misura, non la sua posizione
    nell'elenco: cancellandone una non punta a un'altra. Il valore si
    rilegge a ogni ricerca, anche dopo uno spostamento degli estremi, e un
    valore scritto a mano non viene sovrascritto.
  - Tabella dei tipi compatibili con link alla scheda tecnica e
    "🏷 Annota", che crea un'annotazione intorno alle misure con il nome
    del tipo e un punto di domanda.
  - Corretto il suggerimento del pannello Misurazioni, che diceva ancora
    "le misurazioni non vengono salvate".
  - La stima dell'altezza dall'ombra prende l'ora esatta del passaggio
    Sentinel, invece del 10:00 fisso.

### Rilevamento automatico

- **[python-service/app/core/detect.py](python-service/app/core/detect.py)** (nuovo),
  **[python-service/app/routers/analysis.py](python-service/app/routers/analysis.py)** —
  YOLO11s-OBB, addestrato sul dataset DOTA v1 (15 classi: aerei,
  elicotteri, navi, veicoli, serbatoi...), esportato in ONNX ed eseguito con
  il modulo DNN di OpenCV già presente.
  - Nessuna dipendenza nuova: i risultati coincidono con quelli di
    Ultralytics sulla stessa immagine.
  - Immagini grandi divise in tasselli da 1024 px con 384 px di
    sovrapposizione: un oggetto fino a 384 px, come un C-17 a 0,15 m/pixel,
    sta sempre per intero in un tassello.
  - Doppioni eliminati per classe con NMS ruotato, poi i frammenti fra
    classi diverse: un riquadro coperto per oltre il 60% da uno più sicuro
    è un oggetto tagliato al bordo di un tassello, oppure lo stesso oggetto
    classificato in due modi.
  - "Oggetti piccoli" facoltativo: ingrandisce fino a 2× le immagini sopra
    0,5 m/pixel. Su Grosseto a 1,6 m/pixel trova un aereo in più, ma è 2–3
    volte più lento.
  - Al massimo 25 tasselli per richiesta: oltre, l'ingrandimento si
    riduce (a Grosseto 1,7× invece di 2×, 5 aerei trovati invece di 3); a
    scala nativa l'immagine viene rifiutata.
  - La rete viene caricata una volta. Se un altro rilevamento è in corso
    si attende al massimo 20 s, poi si risponde "occupato" invece di
    restare in coda oltre il timeout del PHP.
  - Nuovi `/analysis/detect` e `/analysis/detect/status`.
- **[python-service/tools/fetch_detector_model.sh](python-service/tools/fetch_detector_model.sh)** (nuovo) —
  il modello (37 MB, AGPL-3.0, pesi DOTA per uso non commerciale) non è
  nel repository. Lo script lo scarica dalle release ufficiali Ultralytics
  e lo converte in un ambiente PyTorch temporaneo, poi cancellato.
- **[webapp/schema.sql](webapp/schema.sql)**,
  **[webapp/src/Detection.php](webapp/src/Detection.php)** (nuovo),
  **[webapp/public/api/detect.php](webapp/public/api/detect.php)** (nuovo) —
  tabella `detections`, che tiene l'ultima esecuzione per ripresa e si
  elimina con la ripresa.
  - Le riprese sopra 2 m/pixel (Sentinel, aree molto grandi) vengono
    rifiutate con la spiegazione.
  - Lati dei riquadri convertiti in metri per asse, tenendo conto
    dell'angolo e di pixel non quadrati; poligoni in coordinate frazionarie.
  - Tipi compatibili per ogni aereo ed elicottero.
  - La sessione viene chiusa durante l'elaborazione.
- **analyze_capture.php, analyze.js, style.css** — nuovo pannello
  "🎯 Rilevamento automatico".
  - Confidenza regolabile, conteggi per classe, riquadri tratteggiati sulla
    ripresa (solo a video, mai negli export) e selezione di una riga.
  - Tipi compatibili cliccabili come etichetta; salvataggio come
    annotazioni poligonali, singolo o di tutti i velivoli e le navi. Il
    poligono viene copiato, e gli oggetti già salvati vengono saltati:
    niente doppioni che gonfino lo storico.
  - "Oggetti piccoli" viene registrato solo se l'ingrandimento è stato
    davvero applicato.
  - Pulsante disattivato in anticipo sulle riprese troppo poco dettagliate.

### Storico dell'area

- **[webapp/src/AreaHistory.php](webapp/src/AreaHistory.php)** (nuovo),
  **[webapp/public/api/area_history.php](webapp/public/api/area_history.php)** (nuovo),
  **[webapp/public/study.php](webapp/public/study.php)**,
  **[webapp/public/assets/js/study.js](webapp/public/assets/js/study.js)** —
  nuovo pannello "📈 Storico dell'area": per ogni ripresa, nell'ordine
  della data reale dell'immagine, conteggi di aerei, elicotteri, navi e
  veicoli dall'ultimo rilevamento, e tipi identificati dalle etichette
  delle annotazioni.
  - Grafico SVG di aerei ed elicotteri nel tempo.
  - "Rileva sulle riprese mancanti", una ripresa alla volta, con
    avanzamento; le riprese non adatte sono indicate con la risoluzione.
  - Export CSV con separatore ";", virgola decimale e BOM UTF-8 per i
    fogli di calcolo italiani, ora locale. Le celle che inizierebbero con
    = + - @ vengono neutralizzate, così un'etichetta non diventa una
    formula all'apertura.
  - Tipi riconosciuti nelle etichette per nome completo o sigla, come
    parola intera e con una lettera di variante ammessa: "UH-60" è il
    Black Hawk e non il bombardiere H-6, "F-22" non è l'F-2, "AH-64D" è
    l'Apache.
  - Stessa regola di scala del rilevamento (`Detection::resolveMpp`) per
    storico, API e vista di analisi.

### Verifica

Revisione indipendente del diff: geometria dei riquadri, unità, assenza di
XSS ed esclusione dei rilevamenti dagli export confermate. Corretti gli
11 punti segnalati, descritti sopra. Prove in ambiente isolato:
- rilevamento su riprese reali di Sigonella (7 aerei) e Grosseto;
- rifiuto delle riprese a bassa risoluzione;
- identificazione da misure, inclusi elicotteri e ali a freccia;
- annotazioni, doppi clic e storico con rilevamento in serie;
- CSV con etichetta pericolosa;
- eliminazione a cascata;
- 17 pagine e API senza errori.

Richiede il riavvio del servizio di analisi e il modello installato.

### Repository

- `python-service/tools/` sincronizzata; `python-service/models/` esclusa
  (`.gitignore` e controllo dei file sospetti dello script di sync).
- README: identificazione, rilevamento, storico, installazione del modello,
  licenze di modello, dataset e dati delle dimensioni.

## 2026-10-08 (2) — Fonti con una dimensione temporale vera: passaggi Sentinel-2, radar Sentinel-1, archivio storico Esri, pianificazioni guidate dai nuovi dati

Seconda parte del piano di evoluzione. Il motore di confronto era ben
costruito ma non riceveva dati che cambiano: Esri World Imagery è un
mosaico aggiornato di rado (sul deployment reale 64 controlli pianificati
su 64 hanno dato "nessun cambiamento") e Sentinel-2 veniva scaricato come
mosaico "meno nuvoloso di un intervallo", che mescolava giorni diversi e
prendeva come data la fine dell'intervallo.

### Sentinel-2: un passaggio, una data

- **[python-service/app/core/sentinelhub_client.py](python-service/app/core/sentinelhub_client.py)** —
  nuove `search_sentinel2()` / `search_sentinel1()`. Il catalogo STAC
  (con paginazione) restituisce i prodotti, che vengono raggruppati per
  passaggio (tasselli adiacenti, prodotti consecutivi entro ±3 minuti).
  Per ogni passaggio: data e ora UTC, satellite, orbita relativa e
  nuvolosità del tassello. La Statistical API calcola **nuvole e copertura
  sulla sola area di interesse** (classi SCL 3/8/9/10 a ~20 m). Quella del
  tassello riguarda 110 km: su Sigonella diceva 20% il 30/09 con l'area
  coperta al 94%, e 28% il 03/10 con l'area quasi sgombra (8%). Le
  statistiche sono **per passaggio**, non per mosaico giornaliero: con due
  passaggi nello stesso giorno (aree nella sovrapposizione fra orbite) il
  mosaico riempiva i pixel scoperti di uno con l'altro, e nuvole e
  copertura risultavano di entrambi. Né il filtro temporale dei dati né la
  mosaicatura per orbita lo evitano (quest'ultima raggruppa per giorno):
  l'evalscript lavora per tassello e tiene solo quelli del primo o
  dell'ultimo passaggio del giorno. Bastano due richieste per qualunque
  periodo: un anno di dati in ~9 s, contro 47 s con una richiesta per
  giorno. Le richieste rifiutate per limite di frequenza (429) vengono
  ritentate. Il catalogo segnala un errore invece di troncare in silenzio
  oltre i 3000 prodotti.
  `fetch_true_color()`/`fetch_red_nir()` accettano `pass_datetime` e
  scaricano solo quel passaggio. Le chiamate HTTP passano da un'unica
  `_post()` che traduce gli errori in messaggi leggibili.
- **[webapp/src/ImageryCatalog.php](webapp/src/ImageryCatalog.php)** (nuovo) —
  ricerca dei passaggi dal PHP e scelta del migliore: per Sentinel-2 il più
  recente con nuvole sull'area entro la soglia e area coperta almeno al
  95%; per Sentinel-1 il più recente, della stessa orbita se indicata.
  Se le statistiche non rispondono si ripiega sulla nuvolosità del tassello.
- **[webapp/src/CaptureFetcher.php](webapp/src/CaptureFetcher.php)** —
  ogni ripresa Copernicus è un passaggio. Con `pass_datetime` si scarica
  quello; con un periodo si scarica il migliore del periodo. Se nessun
  passaggio rispetta la soglia, il messaggio dice quanti ce n'erano.
  Etichetta "Sentinel-2 — 08/10/2026 10:00 UTC (nuvole sull'area 0%)",
  data della ripresa = giorno del passaggio, dettagli in `meta.s2_pass` /
  `meta.s1_pass`.

### Sentinel-1 radar

- **sentinelhub_client.py** — `fetch_sentinel1()`: GRD in modalità IW,
  calibrazione gamma0 sul terreno, ortorettifica sul DEM Copernicus, filtro
  anti-speckle Lee 3×3. Il risultato è la retrodiffusione VV in decibel in
  scala di grigi (-22..+4 dB). Tra le rese provate è la più leggibile:
  piste e piazzali scuri, edifici e velivoli punti chiari. Un falso colore
  VV/VH/rapporto era dominato dalla vegetazione.
- **[python-service/app/routers/fetch.py](python-service/app/routers/fetch.py)** —
  nuovi `/fetch/sentinel1`, `/fetch/wayback`, `/fetch/catalog/sentinel2`,
  `/fetch/catalog/sentinel1`. `/fetch/sentinelhub` accetta
  `pass_datetime`.
- Nuova fonte `sentinel1` in CaptureFetcher, nelle pianificazioni e nelle
  attribuzioni (stessa dicitura Copernicus). Si elencano solo i passaggi a
  doppia polarizzazione VV+VH, quelli che il download sa elaborare: un
  passaggio in sola HH sarebbe stato scaricato come immagine vuota.
- Un passaggio che il catalogo non conosce per quell'area e fonte viene
  rifiutato, invece di salvare un'immagine vuota con una data precisa.

### Archivio storico Esri (Wayback)

- **[webapp/src/EsriWayback.php](webapp/src/EsriWayback.php)** (nuovo) —
  elenco delle versioni del mosaico pubblicate dal 2014 (cache di un
  giorno). `versionsAt()` trova quelle in cui l'immagine dell'area è
  davvero diversa con il metodo dell'applicazione Wayback di Esri: il
  "tilemap" indica per ogni tassello la versione in cui è stato pubblicato
  l'ultima volta, e si risale di versione in versione. Su Sigonella 197
  versioni si riducono a 19. `imageryFor()` legge la data reale dai
  metadati della singola versione (cache di 30 giorni).
- **[python-service/app/core/wayback_client.py](python-service/app/core/wayback_client.py)** (nuovo) —
  scarica i tasselli WMTS che coprono l'area (in parallelo, con nuovi
  tentativi) al livello adatto alla risoluzione richiesta, scendendo di
  livello se la versione non arriva così in dettaglio (quelle più vecchie
  spesso si fermano al 17–18). Li ricampiona dalla proiezione Web Mercator
  alla griglia lon/lat lineare delle riprese, con la stessa regola di
  rapporto d'aspetto di `esri_client.py`: una versione storica e
  un'immagine attuale della stessa area sono allineate pixel per pixel.
  Se mancano tasselli ai bordi si scende di livello invece di lasciare
  blocchi neri; un'area coperta meno del 90% viene rifiutata.
  È il servizio WMTS che Esri documenta per i client GIS di terze parti.
  Si scaricano solo i tasselli dell'area, e valgono gli stessi termini
  d'uso di World Imagery (avviso alla pubblicazione invariato).
- **CaptureFetcher.php** — `wayback_release` per la fonte Esri. Etichetta
  "Esri Wayback (archivio del 14/03/2018) — immagine del 29/06/2017",
  dettagli in `meta.wayback`.
- **[webapp/src/EsriImageryMetadata.php](webapp/src/EsriImageryMetadata.php)** —
  la query chiede tutti i campi (`outFields=*`): i metadati delle versioni
  più vecchie non hanno `ReleaseName`, e chiederlo per nome faceva fallire
  la lettura. Nuova `signature()` pubblica, cioè l'impronta delle
  acquisizioni. `waybackAround()` usa l'elenco di `EsriWayback`, così la
  cache è una sola. Nomi di sensori aggiunti: Pléiades, SPOT, fotocamere
  aeree UltraCam e varianti per banda come `WV03_VNIR`.

### Interfaccia

- **[webapp/public/api/imagery_catalog.php](webapp/public/api/imagery_catalog.php)** (nuovo) —
  azioni `sentinel2`, `sentinel1`, `wayback`, `wayback_imagery`. La
  sessione viene chiusa subito, così le date delle versioni si caricano in
  parallelo e il resto dell'interfaccia non resta in attesa.
- **[webapp/public/study.php](webapp/public/study.php)**,
  **[webapp/public/assets/js/study.js](webapp/public/assets/js/study.js)**,
  **[webapp/public/assets/css/style.css](webapp/public/assets/css/style.css)**:
  - La scheda "Copernicus (Sentinel)" sceglie fra Sentinel-2 e Sentinel-1.
    **Cerca passaggi** apre una tabella con data, satellite, orbita, nuvole
    e copertura sull'area (badge colorati, copertura parziale evidenziata)
    e un "Scarica" per ogni riga.
  - Nella scheda Esri, **🕰 Versioni storiche** elenca le versioni con
    data reale, sensore e risoluzione. Le versioni consecutive con la
    stessa acquisizione (solo rielaborate, o col fornitore rinominato
    Maxar → Vantor) sono raggruppate in una riga con "+N": su Sigonella 19
    versioni diventano 12 immagini, dal 2011 al 2024.
  - L'avviso "stessa acquisizione" (punto 1) confrontava solo la data, e
    due passaggi radar dello stesso giorno, o un'immagine Sentinel e una
    Esri con la stessa data, risultavano a torto la stessa foto. Ora usa
    un identificativo preciso (`ImageryAttribution::acquisitionKey`). Nuovo
    avviso per due riprese radar da orbite diverse, che altrimenti
    darebbero ~100% di "cambiamento" dovuto solo alla geometria di vista.
  - Le miniature mostrano la fonte per nome (Sentinel-2, Sentinel-1 SAR,
    Esri Wayback…) tramite `Capture::sourceLabel()`.
- La scelta del satellite si allinea alla pagina anche dopo un
  ricaricamento (il browser può ripristinare il radio "Sentinel-1" mentre
  la fonte restava Sentinel-2). I risultati di una ricerca superata da un
  cambio di satellite vengono scartati. La chiave "stessa acquisizione"
  considera tutte le acquisizioni Esri dell'area, non solo la prevalente.
  Le versioni Wayback senza servizio di metadati restano nella catena,
  con la data "non disponibile".
- **[webapp/src/ImageryAttribution.php](webapp/src/ImageryAttribution.php)** —
  didascalie e strisce per passaggio Sentinel-2 (satellite, orbita, nuvole),
  Sentinel-1 (orbita e direzione) e versioni Wayback. Più riprese Esri di
  fornitori diversi danno un solo "© Esri, Microsoft, Vantor". Il nome del
  sensore viene ricalcolato dal codice anche sui dati già salvati.

### Pianificazioni guidate dai nuovi dati

- **[webapp/cli/run_scheduled_downloads.php](webapp/cli/run_scheduled_downloads.php)** —
  prima di scaricare si verifica se la fonte ha qualcosa di nuovo:
  - Sentinel-2: un passaggio successivo all'ultimo scaricato, con nuvole
    sull'area entro la soglia;
  - Sentinel-1: un passaggio successivo della stessa orbita relativa;
  - Esri: la data delle immagini dell'area, letta dai metadati con la
    stessa area e scala del download precedente, diversa da quella
    dell'ultima ripresa.

  Se non c'è nulla l'esito è il nuovo `no_new` e non si scarica niente:
  le 3 pianificazioni Esri del deployment ora chiudono in 1 secondo invece
  di scaricare e scartare la stessa immagine ogni giorno. Una novità certa
  si tiene sempre, e l'alert riporta le due date e la variazione rilevata.
  Se i metadati Esri non sono leggibili si torna al confronto per pixel
  con soglia di duplicato. Il passaggio letto dal catalogo arriva a
  `CaptureFetcher` così com'è (nuovo parametro `$knownPass`): una seconda
  ricerca fallita per un momento avrebbe lasciato la ripresa senza orbita,
  e il controllo successivo avrebbe confrontato radar di orbite diverse.
  Per le pianificazioni Sentinel create prima di questa versione la
  finestra è limitata a 365 giorni (oltre 400 la ricerca veniva rifiutata a
  ogni giro) e si cerca dopo la fine del vecchio mosaico.
- **[webapp/public/api/schedule_download.php](webapp/public/api/schedule_download.php)**,
  **[webapp/public/schedules.php](webapp/public/schedules.php)**,
  **[webapp/public/alerts.php](webapp/public/alerts.php)**,
  **[webapp/src/Capture.php](webapp/src/Capture.php)** — fonte `sentinel1`,
  finestra del primo passaggio (default 30 giorni), esito "= niente di
  nuovo", soglia di duplicato mostrata solo per Esri, testi aggiornati.
- **[webapp/cli/refresh_esri_metadata.php](webapp/cli/refresh_esri_metadata.php)** —
  le versioni storiche si datano con i metadati della loro versione, non
  col giorno del download. Riconosce anche le etichette automatiche Wayback.

### Verifica

Revisione indipendente del diff completo: nessun crash o XSS, 12 punti
su dati potenzialmente sbagliati in silenzio, corretti quelli descritti
sopra. Ambiente isolato (seconda istanza del servizio di analisi, copia di
DB e storage, token Telegram neutralizzato), con download reali:
- ricerche Sentinel-2 e Sentinel-1;
- passaggio migliore, passaggio scelto, assenza di passaggi sotto soglia,
  valori non validi;
- Sentinel-1 normale e ruotato;
- Wayback 2011, 2018, 2019 e attuale, normale e ruotato, con livello di
  zoom ripiegato dove manca il dettaglio;
- pianificazioni: Esri invariato, Esri aggiornato (simulato), Sentinel-2 e
  Sentinel-1 senza novità e con un passaggio nuovo, pianificazione Sentinel
  creata con la versione precedente;
- avvisi di stessa acquisizione e di orbita diversa nel confronto reale;
- 14 pagine senza errori PHP.

Le modifiche al servizio Python richiedono il riavvio di
`orbitaleye-analysis`.

## 2026-10-08 — Integrità del materiale pubblicato: data reale delle immagini Esri, attribuzione delle fonti, avviso sui termini d'uso

Prima parte del piano di evoluzione. Il mosaico Esri World Imagery è
composto da acquisizioni di epoche diverse e viene aggiornato di rado:
la data del download non dice quando è stata scattata la foto. Sul
deployment reale, riprese "scaricate a settembre 2026" risultano scattate
tra il 2022 e il 2025. Due di queste confrontate tra loro possono essere
la *stessa* acquisizione, e il "cambiamento" è solo rumore. Inoltre le
immagini condivise non riportavano né la data reale né l'attribuzione
richiesta dalle fonti.

### Data reale, sensore e risoluzione delle immagini Esri

- **[webapp/src/EsriImageryMetadata.php](webapp/src/EsriImageryMetadata.php)** (nuovo) —
  interroga il servizio metadati ufficiale di World Imagery (layer
  "Resolution Metadata" scelto in base alla scala della ripresa, con
  ripiego sui livelli più grossolani se a quella scala non ci sono dati
  e un nuovo tentativo sui falsi "Layer not found" del servizio).
  Restituisce ogni acquisizione che copre l'area: data, sensore
  (WorldView-2/3, Legion, GeoEye…), risoluzione nativa, fornitore e
  quota di area coperta. La quota è calcolata ritagliando i poligoni
  sull'area (Sutherland–Hodgman) e misurandone l'area. La data prevalente
  diventa la data della ripresa. `queryAsOf()` data una ripresa scaricata
  in passato usando l'archivio storico **Wayback**: interroga le due
  versioni del mosaico prima e dopo il download. Se coincidono la data è
  certa, altrimenti viene segnata come incerta con l'alternativa.
  L'elenco dei layer e delle versioni Wayback resta in cache in
  `app_settings`.
- **[webapp/src/CaptureFetcher.php](webapp/src/CaptureFetcher.php)** — ogni
  nuovo scaricamento Esri legge i metadati (sul riquadro realmente
  scaricato, anche per le aree ruotate) e salva data reale, etichetta
  ("Esri World Imagery — immagine del gg/mm/aaaa") e dettagli in
  `meta.esri_imagery`. Se il servizio metadati non risponde la ripresa
  viene salvata lo stesso e l'errore registrato.
- **[webapp/public/api/upload_capture.php](webapp/public/api/upload_capture.php)** —
  un ritaglio di una ripresa Esri riceve i metadati del proprio riquadro,
  non quelli dell'intera ripresa. Nuovo campo facoltativo `attribution`
  (max 200 caratteri) per le immagini caricate a mano.
- **[webapp/cli/refresh_esri_metadata.php](webapp/cli/refresh_esri_metadata.php)** (nuovo) —
  recupero per le riprese già archiviate (`--all`, `--id=N`, `--dry-run`),
  datate com'erano il giorno del download. Aggiorna solo le etichette
  generate automaticamente, mai quelle scritte a mano.

### Attribuzione delle fonti e provenienza

- **[webapp/src/ImageryAttribution.php](webapp/src/ImageryAttribution.php)** (nuovo) —
  un solo punto che ricava fonte, data reale e testo di attribuzione di
  una ripresa, risalendo la catena delle copie (ritagli, versioni
  migliorate) fino all'originale. Gestisce Esri (dicitura di copyright
  richiesta), Copernicus ("Contains modified Copernicus Sentinel data
  [anno]") e la fonte dichiarata al caricamento. Fornisce la riga per la
  didascalia, la striscia per l'immagine (anche per una coppia Prima/Dopo)
  e la scrittura della striscia lato server (GD, DejaVu Sans).
- **[webapp/public/analyze_capture.php](webapp/public/analyze_capture.php)**,
  **[webapp/public/assets/js/analyze.js](webapp/public/assets/js/analyze.js)**,
  **[webapp/public/assets/js/common.js](webapp/public/assets/js/common.js)** —
  sotto il titolo della vista di analisi: data reale, sensore,
  risoluzione nativa e data del download. Le didascalie proposte
  includono data e attribuzione. Nuova opzione "Scrivi data e fonte
  sull'immagine", attiva di default, per la ripresa intera e per il
  ritaglio; l'anteprima del ritaglio la mostra. La striscia non viene mai
  scritta nei ritagli salvati come nuove riprese, perché finirebbe nei
  pixel analizzati. `buildCropBlob()` separa ora livello annotazioni e
  striscia.
- **[webapp/public/study.php](webapp/public/study.php)**,
  **[webapp/public/assets/js/study.js](webapp/public/assets/js/study.js)**,
  **[webapp/public/api/share.php](webapp/public/api/share.php)** — le
  miniature mostrano la data reale. Le didascalie di confronto e di
  riepilogo riportano le date reali di Prima e Dopo e le fonti. La
  striscia viene scritta anche sulle immagini dei confronti, lato server
  per Telegram e lato browser per la copia verso X. Se le due riprese
  confrontate risultano la stessa acquisizione compare un avviso: le
  differenze rilevate sarebbero solo rumore.

### Avviso sui termini d'uso Esri

- **[webapp/public/partials/esri_share_notice.php](webapp/public/partials/esri_share_notice.php)** (nuovo),
  **[webapp/public/assets/css/style.css](webapp/public/assets/css/style.css)** —
  accanto ai pulsanti di condivisione di un'immagine Esri compare un
  riquadro con il riassunto dei termini. Le immagini statiche sono
  consentite per uso personale o interno, rapporti, pubblicazioni
  accademiche e simili; per la pubblicazione su canali pubblici Esri
  chiede un permesso preventivo. Il riquadro ha i link ai termini e alla
  richiesta di autorizzazione. Prima della prima pubblicazione della
  pagina viene chiesta una conferma esplicita
  (`confirmEsriPublishing()`).

### Repository

- **README.md** — documentate data reale delle immagini, provenienza nelle
  condivisioni, termini d'uso e attribuzione delle fonti, script di
  recupero.
- **.gitignore** — esclusa `webapp/cli/logs/` (dati operativi).
- La cartella `webapp/cli/` non veniva sincronizzata: lo script dello
  scaricamento pianificato (`run_scheduled_downloads.php`, già documentato
  nel README) mancava dal repo. Ora sono inclusi entrambi gli script, mai
  i log.

## 2026-09-23 — Secondo audit completo: correttezza analitica, geometria, robustezza e frontend

Revisione dell'intera base di codice (~13.200 righe) in quattro aree
indipendenti (servizio Python, backend PHP, vista di analisi, pagina studio
e resto del frontend), più una suite di test end-to-end in ambiente
completamente isolato, con una seconda istanza del motore di analisi e
download reali Esri/Sentinel: 192 controlli API + test interattivi nel
browser, tutti superati al termine. Ogni reperto è stato riprodotto prima
della correzione.

### Risultati analitici sbagliati in silenzio

- **[python-service/app/core/esri_client.py](python-service/app/core/esri_client.py)**,
  **[routers/fetch.py](python-service/app/routers/fetch.py)**,
  **[webapp/src/CaptureFetcher.php](webapp/src/CaptureFetcher.php)** —
  quando Esri rifiuta una richiesta e viene riprovata a risoluzione ridotta,
  si registravano le dimensioni *richieste* invece di quelle reali. La
  scala (metri/pixel) si calcola dividendo l'area per quelle dimensioni:
  su un'immagine 501×501 registrata come 1024×1024 ogni distanza risultava
  metà del vero e ogni area un quarto. Sul deployment reale: 5 riprese su
  13. Ora il servizio restituisce le dimensioni lette dall'immagine e il PHP
  le verifica sul file salvato (`getimagesize`); i tentativi ridotti
  scalano entrambi i lati dello stesso fattore (un minimo su un solo lato
  alterava il rapporto d'aspetto e con esso l'area coperta).
- **[python-service/app/core/diff.py](python-service/app/core/diff.py)** —
  l'area delle regioni di cambiamento era misurata sul contorno
  (`cv2.contourArea`: poligono per i centri dei pixel di bordo, buchi
  riempiti) invece che in pixel: un blob di 49 px risultava 36 e spariva
  dalle regioni, un cambiamento ad anello di 1032 px ne risultava 3754, più
  dell'intera area cambiata. Ora componenti connesse, area = pixel reali
  (verificato: 1032 + 400 + 49 = 1481 = totale). Stesso file: il filtro dei
  blob scandiva l'immagine una volta per blob (13mila blob su 1024² = 6,7 s,
  crescita quadratica fino a superare il timeout del PHP): ora una sola
  passata vettoriale, 0,02 s. Kernel morfologico e iterazioni limitati
  (valori enormi tentavano allocazioni di gigabyte).
- **[python-service/app/core/registration.py](python-service/app/core/registration.py)** —
  punti di controllo coincidenti o allineati producevano una trasformazione
  nulla o degenere dichiarata "successo, confidenza 1.0": fra due immagini
  *identiche* il confronto riportava l'89% di cambiamento. Ora vengono
  rifiutati con una spiegazione, così come le trasformazioni che fanno
  uscire quasi tutta la ripresa B. L'allineamento automatico di ripiego
  (ECC affine) "convergeva" anche fra immagini senza relazione
  (correlazione 0,17) e si dichiarava riuscito: ora serve una correlazione
  di almeno 0,5, e la confidenza riportata è quella reale.
- **[python-service/app/core/sentinelhub_client.py](python-service/app/core/sentinelhub_client.py)**,
  **[core/spectral.py](python-service/app/core/spectral.py)** — la banda NIR
  veniva salvata a 8 bit con guadagno 2,5: ogni riflettanza oltre 0,4
  saturava, e la vegetazione sana sta proprio lì (NDVI 0,77 → 0,67;
  0,05 → 0,00). Ora guadagno 1,0, registrato nei metadati della ripresa
  (`nir_gain`) così NDWI e falso colore riportano vero colore e NIR alla
  stessa scala anche per file più vecchi
  ([api/spectral_view.php](webapp/public/api/spectral_view.php)).
- **[python-service/app/core/utils.py](python-service/app/core/utils.py)**,
  **[routers/analysis.py](python-service/app/routers/analysis.py)** — le
  zone senza dati (trasparenza / `dataMask` Sentinel) diventavano nero e
  venivano contate come cambiamento: ora sono escluse, anche su B dopo
  l'allineamento (stessa trasformazione applicata alla maschera). I PNG a
  16 bit con dati a 12 bit risultavano quasi neri: ora scalati sulla
  profondità effettiva. Metodo di differenza sconosciuto rifiutato (prima
  ricadeva in silenzio su SSIM).
- **[webapp/public/api/compare.php](webapp/public/api/compare.php)** — se
  solo B ha una scala nota, ora viene riportata sulla griglia di A.

### Geometria delle riprese ruotate ed export geografico

- **[webapp/src/ImageRotateCrop.php](webapp/src/ImageRotateCrop.php)** — la
  mappa ruota il rettangolo nelle proporzioni reali del terreno, il server
  lo ruotava nello spazio dei gradi (un grado di longitudine è cos(lat)
  volte uno di latitudine): la ripresa salvata era tagliata in obliquo e
  non coincideva con il poligono mostrato — a 37–60° di latitudine 2–4
  angoli del poligono cadevano fuori dall'area scaricata. Ora l'immagine
  viene ricampionata a pixel quadrati in metri prima di ruotarla. Test a
  livello di pixel: proporzioni esatte e angoli al loro posto a ogni
  latitudine. Le nuove riprese ruotate sono marcate `rotation_model:
  metric`; le precedenti restano gestite col loro modello.
- **[webapp/src/Capture.php](webapp/src/Capture.php)** — nuovi
  `resolveGeoRef()`, `geoMetaForDerived()`, `fracToLonLat()`: le riprese
  derivate (copie migliorate, "salva come nuova ripresa", ritagli) ricevono
  area, rotazione e data della sorgente — i ritagli la propria area esatta,
  calcolata dal rettangolo inviato da
  [analyze.js](webapp/public/assets/js/analyze.js) — invece di ricadere
  sull'area dell'intero studio (export spostati anche di chilometri,
  misure sbagliate sulle copie di riprese ruotate, data persa). Usati da
  [upload_capture.php](webapp/public/api/upload_capture.php),
  [enhance_capture.php](webapp/public/api/enhance_capture.php),
  [save_enhanced_capture.php](webapp/public/api/save_enhanced_capture.php),
  [analyze_capture.php](webapp/public/analyze_capture.php).
- **[webapp/public/api/export_geo.php](webapp/public/api/export_geo.php)** —
  conversione in coordinate geografiche ora esatta anche per le riprese
  ruotate (verificato: angoli dell'immagine = poligono sulla mappa con meno
  di 0,5 m di scarto); l'avviso "approssimata" resta solo per le immagini
  caricate a mano senza alcun riferimento proprio.

### Pianificazioni, download, dati

- **[webapp/src/ScheduledDownload.php](webapp/src/ScheduledDownload.php)** —
  `last_run_at` viene scritto a fine esecuzione, qualche secondo dopo
  l'orario del cron: il giro del giorno dopo trovava la pianificazione
  "dovuta fra pochi secondi" e la saltava. Una pianificazione giornaliera
  girava ogni 30 ore (visibile nel log di produzione). Tolleranza di 30
  minuti, ben sotto l'intervallo del cron.
- **[webapp/src/CaptureFetcher.php](webapp/src/CaptureFetcher.php)**,
  **[PythonServiceClient.php](webapp/src/PythonServiceClient.php)**,
  client Esri/Sentinel — timeout dedicato ai download (180 s) e tetti di
  tempo del servizio tarati sotto di esso: prima il PHP poteva rinunciare
  mentre il servizio scriveva ancora i file, rimasti orfani. Errori di rete
  tradotti in messaggi chiari; un errore sulla sola banda NIR non fa più
  perdere il vero colore già scaricato; file rimossi se il ritaglio ruotato
  fallisce.
- **[webapp/public/new_study.php](webapp/public/new_study.php)** — l'area
  dello studio veniva salvata senza controlli ("10,23" → 10, testo → 0,
  min/max invertiti accettati). Ora validata, virgola decimale accettata,
  modulo che conserva i valori dopo un errore.
- **[webapp/public/api/upload_capture.php](webapp/public/api/upload_capture.php)**,
  **[core/utils.py](python-service/app/core/utils.py)**,
  **[app/main.py](python-service/app/main.py)** — tetto di 25 megapixel alle
  immagini elaborate (SSIM in float64 su immagini enormi poteva esaurire la
  memoria).

### Condivisione

- **[webapp/public/api/share.php](webapp/public/api/share.php)**,
  **[study.js](webapp/public/assets/js/study.js)** — dalle viste "Originale
  A/B" Telegram riceveva in silenzio l'overlay: ora le viste sono mappate
  sui file corretti, "Prima/Dopo" e viste sconosciute vengono rifiutate con
  un messaggio. Il "Riepilogo di studio" pubblicava l'ultimo confronto
  *eseguito*, anche esplorativo: ora l'ultimo salvato in libreria, come
  promesso ([Comparison::latestSaved](webapp/src/Comparison.php),
  [study.php](webapp/public/study.php)). Un errore nel registro dopo
  l'invio non si trasforma più in un errore per l'analista (che avrebbe
  ripubblicato).

### Sicurezza e robustezza

- **[fix_permissions.sh](fix_permissions.sh)** — database e storage erano
  664/775: il database (token Telegram, credenziali, hash della password)
  era leggibile da qualunque utente della macchina. Ora 660/2770, e i file
  sensibili non passano più dal 644 generico.
- **[webapp/public/alerts.php](webapp/public/alerts.php)**,
  **[logout.php](webapp/public/logout.php)**,
  **[partials/nav.php](webapp/public/partials/nav.php)** — "segna tutti come
  letti" e logout erano semplici link GET, attivabili da un altro sito (il
  cookie SameSite=Lax viaggia sulle navigazioni GET): ora solo POST.
- **[webapp/src/Auth.php](webapp/src/Auth.php)** — l'hash fittizio usato
  per gli utenti inesistenti aveva costo 10 contro 12: 75 ms contro 320 ms,
  e i tempi rivelavano quali username esistono. Ora stesso costo.
- **[webapp/src/bootstrap.php](webapp/src/bootstrap.php)** — le eccezioni non
  gestite negli endpoint `api/` restituivano un 500 vuoto: ora JSON con un
  messaggio (dettagli solo nel log).
- **[python-service/app/main.py](python-service/app/main.py)**,
  **[deps.py](python-service/app/deps.py)**,
  **[core/utils.py](python-service/app/core/utils.py)** — errori delle
  immagini e di OpenCV restituiti come 400/422 con messaggio invece di 500
  opachi; percorso vuoto o "." (radice dello storage) rifiutato; chiave non
  ASCII → 401 invece di 500.
- **[deploy_apache.sh](deploy_apache.sh)** — in caso di errore il rollback
  ripristinava l'ultimo backup di *ogni* vhost dell'elenco, anche di quelli
  non toccati: ora solo i file modificati in quell'esecuzione.
- **[webapp/public/settings.php](webapp/public/settings.php)** — kernel
  morfologico predefinito sempre dispari (un valore pari veniva mostrato
  ma lo slider inviava il successivo).

### Vista di analisi ([analyze.js](webapp/public/assets/js/analyze.js), [analyze_capture.php](webapp/public/analyze_capture.php))

- **Crash con misurazioni salvate**: su una ripresa senza scala (o al primo
  accesso, se le annotazioni arrivavano prima dell'immagine) una misura
  salvata faceva lanciare un'eccezione e **le liste di annotazioni e
  misurazioni restavano vuote**, come se il lavoro fosse perso. Ora le
  distanze vengono ricalcolate appena la scala è nota.
- **Pannello "Anteprima" chiuso**: il canvas andava a 0×0, le misure
  finivano nell'origine e la prima modifica salvava coordinate sbagliate.
  Ora il canvas non viene mai azzerato e un `ResizeObserver` lo riallinea.
- Annullare l'eliminazione di un poligono/polilinea lo ricreava come
  rettangolo (invisibile ed esportato degenere).
- Ctrl+Z mentre si scrive in un campo annullava l'ultima annotazione sul
  server; Invio/Esc chiudevano il poligono in corso.
- Misure appena disegnate: modifiche fatte prima della risposta del server
  venivano scartate (e una misura annullata subito ricompariva).
- Maniglie e soglie di trascinamento in pixel del canvas: a zoom 10× dieci
  volte più grandi e misure brevi scartate. Ora costanti a schermo.
- Pinch-zoom su touch in modalità Annota/Misura/Ritaglia creava elementi
  spuri.
- Selettori colore: una richiesta e una voce di annullamento per ogni
  colore intermedio; ora solo alla conferma.
- Ritaglio con livello incorporato: la barra di scala mancava quasi sempre
  (era nell'angolo dell'immagine intera); ora ridisegnata sul frammento.
  Anteprima coerente con l'opzione già spuntata.
- Immagine salvata più chiara dell'anteprima (limiti fra i passi e spazio
  colore dei filtri SVG allineati al browser).
- Filtri avanzati e "Salva come nuova ripresa": niente doppi invii né
  risposte superate che sovrascrivono lo stato.
- Maniglie della sovrapposizione che sparivano dopo un ridisegno; opacità
  letta ignorando l'inclinazione; annullare la rimozione mostrava
  l'immagine sbagliata; barra di scala corretta sulle riprese ruotate
  precedenti; mini-anteprima riportata nello schermo; `localStorage`
  protetto.

### Pagina studio e resto del frontend ([study.js](webapp/public/assets/js/study.js), [map-picker.js](webapp/public/assets/js/map-picker.js), [common.js](webapp/public/assets/js/common.js))

- Editor dei punti di controllo: salvava sulla coppia selezionata al
  momento del salvataggio, non su quella per cui era aperto; ora usa la
  propria e si chiude se la selezione cambia.
- Slider Prima/Dopo sotto zoom: la linea finiva lontano dal clic (a 2× un
  clic al 75% la portava al bordo); listener che si accumulavano; larghezza
  al ridimensionamento.
- Un nuovo confronto ereditava il titolo del confronto aperto in
  precedenza; un risultato arrivato dopo un cambio di selezione veniva
  attribuito alla nuova coppia.
- Pannelli filtri e indici spettrali: risposte fuori ordine potevano far
  salvare "Enhanced: Y" con i pixel di X.
- Doppi invii su download, caricamenti e pianificazioni; soglia duplicati
  0% trasformata in 0,5%; annotazioni sui risultati mostrate come salvate
  anche se il server le rifiutava; attesa senza fine se un'immagine non si
  caricava; annotazioni della vista precedente mostrate dopo un cambio
  rapido.
- Selettore mappa: longitudini oltre ±180° disegnando su una "copia" del
  mondo; etichetta della rotazione non allineata al valore ripristinato
  dal browser.
- Pannelli collassabili: `localStorage` protetto, chiave senza contatori
  (la scelta veniva dimenticata al cambiare di "Riprese (N)"), titolo del
  pannello indici spettrali che cancellava l'icona.

### README

- **[README.md](README.md)** — aggiornato allo stato reale: mappa Esri World
  Street Map e ricerca luogo (introdotte il 13/09 ma mai riportate qui),
  Leaflet incluso nel progetto, rotazione nelle proporzioni reali, export
  esatto, Gemini fra le destinazioni del ritaglio, affidabilità
  dell'allineamento, banda NIR, manutenzione dello storage, sezione
  Sicurezza riscritta.

**Nota per chi aggiorna un'installazione esistente**: rieseguire
`fix_permissions.sh` e riavviare il servizio di analisi. Le riprese Esri
scaricate prima di questa versione dopo un tentativo a risoluzione ridotta
possono avere dimensioni registrate errate: confrontare `width`/`height`
nella tabella `captures` con le dimensioni reali dei file.

## 2026-09-20 — Ricerca inversa: aggiunto Google Gemini tra le destinazioni

- **[webapp/public/analyze_capture.php](webapp/public/analyze_capture.php)**
  — nuovo pulsante "✦ Apri Google Gemini ↗" nel pannello "Ricerca inversa e
  analisi per immagini", collocato subito dopo Google Lens e prima di
  Claude/ChatGPT/DeepSeek. Aggiornati i due testi di aiuto che elencano le
  destinazioni disponibili.
- **[webapp/public/assets/js/analyze.js](webapp/public/assets/js/analyze.js)**
  — handler che apre `https://gemini.google.com/app` in una scheda nuova.
  Stesso percorso manuale di tutte le altre destinazioni: apre solo la
  pagina, il frammento si incolla a mano dagli appunti (riusa il pulsante
  "Copia negli appunti" già esistente). Nessuna chiave API, nessun endpoint
  backend, nessun invio automatico.

Verificato: ordine dei pulsanti corretto, URL aperto corretto, nessun
errore in console.

## 2026-09-15 — Audit completo della piattaforma: correzioni di sicurezza, integrità dei dati e robustezza

Revisione sistematica dell'intera base di codice (~11.900 righe: PHP, Python,
JS, SQL, shell). Ogni reperto è stato riprodotto prima di correggerlo e
riverificato dopo. Nessuna modifica funzionale: solo correzioni.

### Sicurezza

- **[webapp/public/media.php](webapp/public/media.php)** — l'endpoint
  serviva *qualunque* file sotto lo storage root, comprese le credenziali
  OAuth Sentinel Hub/Esri in `storage/config/` (scritte da
  `AppSettings::sync*CredentialsFile`). `storage/.htaccess` nega l'accesso
  via Apache, ma `readfile()` da PHP scavalcava del tutto quel diniego.
  Verificato con richiesta HTTP reale: le credenziali venivano restituite
  integralmente. Ora: allowlist di sottocartelle (`raw`/`processed`/
  `results`) e di estensioni immagine, `X-Content-Type-Options: nosniff`, e
  confronto di prefisso con lo slash finale (senza, una cartella sorella
  tipo `storage_backup` avrebbe superato il controllo).
- **[webapp/public/assets/js/study.js](webapp/public/assets/js/study.js)** —
  XSS stored: `label`, `notes` e `color` delle annotazioni finivano in
  `innerHTML` senza escaping. Verificato: un'etichetta
  `<img src=x onerror=...>` veniva eseguita, e `color` usciva
  dall'attributo `style` iniettando altri attributi. Ora si usano
  `textContent` e `style.color`, come già faceva correttamente `analyze.js`.
- **[webapp/public/api/annotations.php](webapp/public/api/annotations.php)**
  — difesa in profondità: `color` accettato solo come `#rrggbb`,
  `shape_type` solo tra le forme realmente disegnabili.
- **[webapp/src/Auth.php](webapp/src/Auth.php)** — blocco temporaneo dopo 8
  tentativi di accesso falliti; `verify()` separato da `attempt()` per
  ricontrollare la password senza rigenerare l'id di sessione (che
  scollegava le altre schede aperte); confronto a tempo costante anche per
  utenti inesistenti (prima il tempo di risposta rivelava quali username
  esistessero); il cookie di sessione ora viene rimosso al logout.
- **[webapp/src/bootstrap.php](webapp/src/bootstrap.php)** — flag `Secure`
  sul cookie di sessione quando la connessione è HTTPS (senza fissarlo,
  così l'accesso via IP in HTTP semplice continua a funzionare).
- **[python-service/app/main.py](python-service/app/main.py)** — rimosso il
  middleware CORS `allow_origins=["*"]`: l'unico client è il webapp PHP via
  curl server-side, dove CORS non entra in gioco. Era superficie d'attacco
  senza alcun beneficio.
- **[python-service/app/deps.py](python-service/app/deps.py)** — la chiave
  di servizio segnaposto di `.env.example` non viene più accettata (un
  deployment non configurato era di fatto senza autenticazione); confronto
  a tempo costante con `secrets.compare_digest`.
- **[webapp/public/api/share.php](webapp/public/api/share.php)** — l'unico
  endpoint che invia byte a un servizio terzo non validava nulla del file
  caricato: ora tipo MIME reale via `finfo` e limite di 10 MB (quello di
  Telegram), come già faceva `upload_capture.php`.

### Integrità dei dati

- **[webapp/src/Study.php](webapp/src/Study.php)** — `Study::delete()`
  eseguiva una `DELETE` secca: il `ON DELETE CASCADE` elimina le righe
  dentro SQLite, che però non può eseguire codice PHP, quindi **tutti** i
  file su disco dello studio restavano orfani per sempre. Ora vengono
  rimossi prima della cancellazione, finché è ancora possibile sapere quali
  siano.
- **[webapp/src/Capture.php](webapp/src/Capture.php)** — nuovi
  `ownedFiles()` / `deleteOwnedFiles()`: eliminano anche la banda NIR
  (`nir_relative_path` in `meta_json`), che non veniva mai cancellata, e
  saltano i file ancora referenziati da un'altra ripresa (lo stesso file
  `processed/` può essere salvato più volte — prima cancellarne una rompeva
  le altre).
- **[webapp/src/StorageMaintenance.php](webapp/src/StorageMaintenance.php)**
  (nuovo) + **[webapp/cli/run_scheduled_downloads.php](webapp/cli/run_scheduled_downloads.php)**
  — anteprime di enhancement e confronti mai salvati restavano su disco a
  tempo indeterminato. Pulizia periodica agganciata al cron esistente (con
  48 h di grazia, così un'anteprima appena generata non sparisce sotto i
  piedi). Il cron ora elimina anche la cartella `results/` che generava a
  ogni controllo duplicati e che nessuna riga referenziava.
  Sul deployment reale gli orfani erano **~81 MB su 85 MB totali**;
  verificato su copia dei dati veri: 85 MB → 18 MB, con tutte le riprese
  ancora referenziate intatte.
- **[webapp/public/assets/js/analyze.js](webapp/public/assets/js/analyze.js)**
  — le misurazioni sono in pixel canvas assoluti (le annotazioni in
  frazioni): al ridimensionamento della finestra restavano ferme ai vecchi
  pixel, finendo per indicare un punto diverso del terreno e salvando
  coordinate sbagliate alla prima modifica successiva. Verificato: deriva
  del 67% sulla posizione relativa. Ora vengono riscalate insieme al canvas.

### Robustezza

- **[webapp/src/Auth.php](webapp/src/Auth.php)** + **analyze.js** — a
  sessione scaduta le API rispondevano `302` verso la pagina di login HTML:
  `fetch` seguiva il redirect, `res.json()` falliva, e siccome i salvataggi
  erano "fire-and-forget" senza `catch` né controllo di `res.ok`,
  l'analista poteva lavorare un'intera sessione con **ogni salvataggio
  fallito e nessuna segnalazione**, perdendo tutto al ricaricamento. Ora le
  richieste che si aspettano JSON ricevono `401` con un messaggio, e ogni
  salvataggio passa da `persistFetch()`, che mostra l'errore in pagina.
- **[python-service/app/core/enhance.py](python-service/app/core/enhance.py)**
  — `clahe(tile_grid_size=0)` faceva divisione per zero dentro OpenCV e
  **abbatteva l'intero processo del servizio** con SIGFPE: un segnale, non
  un'eccezione, quindi non intercettabile da un `try/except`. Un singolo
  parametro malformato inoltrato da `enhance_capture.php` metteva giù il
  motore di analisi per tutta la piattaforma. Parametri ora limitati prima
  della chiamata a OpenCV (unico punto in cui è possibile); i parametri
  sconosciuti vengono filtrati sulla firma della funzione invece di
  generare un 500 opaco.
- **[webapp/src/PythonServiceClient.php](webapp/src/PythonServiceClient.php)**
  — `health()`, chiamato dalla barra laterale a **ogni** caricamento di
  pagina, faceva una richiesta HTTP sincrona con timeout di 5 s: un
  servizio piantato rendeva inutilizzabile l'interfaccia proprio quando
  serviva capire cosa non andasse. Ora l'esito è in cache 30 s e i timeout
  sono più stretti.
- **[webapp/cli/run_scheduled_downloads.php](webapp/cli/run_scheduled_downloads.php)**
  — lock `flock` esclusivo: due esecuzioni sovrapposte vedevano le stesse
  pianificazioni come dovute e scaricavano due volte.
- **[webapp/src/Database.php](webapp/src/Database.php)** — lo schema veniva
  riletto ed eseguito per intero a ogni richiesta; ora solo quando il file
  cambia. Documentato il limite noto: `IF NOT EXISTS` crea tabelle nuove ma
  non aggiunge colonne, servirà una migrazione esplicita.
- **[webapp/src/CaptureFetcher.php](webapp/src/CaptureFetcher.php)** —
  `width`/`height` ora limitati a 64-2500 px, coerentemente con la cura già
  riservata alla validazione della bbox.
- **[webapp/src/TelegramClient.php](webapp/src/TelegramClient.php)** +
  **share.php** — il tipo MIME era sempre dichiarato `image/jpeg`:
  l'assunzione "è sempre un JPEG" non valeva più da quando esiste la
  condivisione del ritaglio, che produce PNG. Ora deriva dal contenuto reale.
- **[webapp/public/api/export_geo.php](webapp/public/api/export_geo.php)** —
  l'avviso "ripresa ruotata, georeferenziazione approssimata" era solo nel
  KML: chi esportava GeoJSON per QGIS otteneva coordinate approssimate
  senza alcun avviso.

### Dipendenze esterne

- **webapp/public/assets/leaflet/** (nuovo) + **study.php** /
  **new_study.php** — Leaflet veniva caricato da `unpkg.com`: ogni apertura
  di uno studio comunicava a un terzo IP, user-agent e referer
  dell'analista (incoerente con l'attenzione OPSEC seguita ovunque nel
  resto della piattaforma), e la mappa di selezione dell'area smetteva di
  funzionare se il CDN era irraggiungibile. Ora servito in locale; i file
  sono bit-per-bit quelli ufficiali, verificati confrontando l'hash SHA-256
  con gli attributi `integrity` che erano già in pagina.

Verificato al termine: tutte le pagine e gli endpoint rispondono 200,
nessun errore o warning PHP, annotazioni/misurazioni/poligoni creati e
persistiti correttamente, XSS neutralizzata, credenziali non più
raggiungibili, mappa e ricerca luogo funzionanti.

## 2026-09-13 — Basemap Esri World Street Map + ricerca luogo sul selettore mappa

- **[webapp/public/assets/js/map-picker.js](webapp/public/assets/js/map-picker.js)**
  — basemap di navigazione (non satellitare) passato da tile OSM anonime a
  **Esri World Street Map** (`server.arcgisonline.com/.../World_Street_Map`,
  ordine URL `{z}/{y}/{x}`), stessa scelta già fatta in milair_ita/
  flight_anom: i tile OSM anonimi vanno spesso in rate-limit/blocco
  anti-abuso, quelli CARTO richiedono ormai una API key, Esri World Street
  Map no per uso leggero. Vale automaticamente su tutti e 3 i selettori
  mappa esistenti (Nuovo Studio, Sentinel Hub, Esri), nessuna modifica
  aggiuntiva li serviva.
  Aggiunto anche un **controllo di ricerca luogo** (Nominatim/OpenStreetMap,
  gratuito, CORS aperto, licenza open): l'analista digita un nome di
  luogo, preme Invio/lente, sceglie tra i risultati e la mappa si
  centra/inquadra lì (`fitBounds` sulla bounding box del risultato, o
  `setView` se assente). Ricerca solo su azione esplicita, mai "mentre
  digiti" — Nominatim vieta l'uso automatizzato/live; disegnare l'area
  resta un gesto separato come sempre. Implementato come `L.Control`
  iniettato da `initMapPicker()`, quindi presente automaticamente su tutte
  le mappe esistenti senza toccarne il markup.
- **[webapp/public/assets/css/style.css](webapp/public/assets/css/style.css)**
  — stile del nuovo controllo di ricerca (`.map-search-*`), in linea col
  tema scuro esistente.
- **[webapp/public/new_study.php](webapp/public/new_study.php)**,
  **[webapp/public/study.php](webapp/public/study.php)** — tooltip del
  pulsante "🗺 Mappa" aggiornato da "(OpenStreetMap)" a "(Esri World Street
  Map)" per riflettere la fonte reale.

Verificato dal vivo dall'analista, e in isolamento: tile Esri realmente
caricati (non più OSM), ricerca "Sigonella" → 3 risultati reali da
Nominatim, clic sul primo → mappa ricentrata esattamente sulle coordinate
corrette (tile x/y verificati contro la longitudine attesa).

## 2026-09-10 (4) — README aggiornato all'insieme completo delle funzionalità

- **[README.md](README.md)** — sezione "Caratteristiche" e "Guida all'uso"
  riscritte per riflettere tutto quanto aggiunto negli ultimi cicli:
  scaricamento pianificato + pagina Pianificazioni, allineamento manuale,
  parametri di enhancement regolabili, aree di cambiamento in m²/km²,
  indici spettrali NDVI/NDWI/falso colore IR, vista di analisi ripresa
  singola (sovrapposizione con manipolazione diretta skew/opacità + chroma
  key, misurazioni persistenti, stima altezza da ombra, annotazioni
  polilinea/poligono, barra di scala adattiva, livello incorporabile,
  mini-anteprima flottante, ereditarietà della scala nelle riprese
  derivate), condivisione Telegram/X, export georeferenziato KML/GeoJSON.
  Nota su bot Telegram in Impostazioni e sulla voce cron per lo
  scaricamento pianificato. Nessuna modifica al codice.

## 2026-09-10 (3) — Fix: "ghosting" delle maniglie dell'immagine sovrapposta

- **[webapp/public/assets/js/analyze.js](webapp/public/assets/js/analyze.js)**
  — `renderOverlay()` disegnava le maniglie di manipolazione sul canvas
  condiviso con annotazioni/misurazioni senza ripulirlo prima: ogni
  chiamata (una per scatto di slider o per mossa di trascinamento) le
  accumulava sopra le precedenti, e per le modifiche via slider non c'era
  alcun ridisegno pulito successivo — da cui maniglie "fantasma" residue
  (es. il riquadro a 0° rimasto sotto quello ruotato). Ora in modalità
  Sovrapponi `renderOverlay()` fa `redrawAnnotations()` (clear + ridisegno
  del resto del livello) prima di disegnare le maniglie. Verificato: il
  numero di pixel delle maniglie resta costante attraverso decine di
  chiamate consecutive (prima cresceva a ogni chiamata).

## 2026-09-10 (2) — GEOINT: maniglie skew/opacità, scala adattiva, misurazioni persistenti, poligoni, aree in m², export KML/GeoJSON, altezza da ombra

Batch in 5 fasi su richiesta esplicita, tutte testate in isolamento.

### Fase 1 — Immagine sovrapposta: skew/opacità come maniglie dirette; barra di scala adattiva allo zoom

- **[webapp/public/assets/js/analyze.js](webapp/public/assets/js/analyze.js)**
  — in modalità Sovrapponi: quadratini a metà di ogni lato per inclinare
  (lati alto/basso = skewX, sinistro/destro = skewY), cerchietto lungo una
  guida sotto il lato inferiore per l'opacità (0→1). Tutti sincronizzati
  con gli slider come rotazione/scala. `canvasToOverlayUnrotated()`,
  `overlayHandlePoints()` esteso, casi `skew-overlay`/`opacity-overlay` in
  handleMove.
- La barra di scala (`drawScaleBar()`) nella vista live ora rappresenta
  sempre ~20% dell'area VISIBILE (adattiva: 200 m a 1×, 100 m a 2×, 50 m a
  4×…) e resta ancorata in basso a sinistra del riquadro visibile anche da
  ingranditi/spostati; spessori/testo costanti a schermo. Nell'export resta
  a distanza fissa sull'immagine intera (flag `live` = `includeHandles`).
  Ridisegno agganciato al pan.
- **[webapp/public/analyze_capture.php](webapp/public/analyze_capture.php)**
  — tooltip/testi aggiornati.

### Fase 2 — Misurazioni persistenti + annotazioni poligono/polilinea

- **analyze.js** — le misurazioni ora si salvano lato server come righe
  `annotations` con `shape_type='measure'` (coords x1/y1/x2/y2 in frazioni):
  `measureCoordsFrac()`/`measureFromRow()`, `loadAnnotations()` smista i
  tipi. Create/sposta-estremo/etichetta/colore/elimina e "Cancella tutte"
  (con conferma) sincronizzati con l'endpoint. `createAnnotationServer()`
  accetta ora un parametro `shapeType`.
- Nuove forme di annotazione **polilinea/poligono**: selettore "Forma"
  nella toolbar, clic per aggiungere i vertici, "✓ Termina forma"/Invio
  (Esc annulla); vertici trascinabili in modalità Annota. Persistite come
  `shape_type='polyline'/'polygon'`, coords `{points:[[fx,fy],…]}`.
  `drawOverlayLayer()` disegna i nuovi tipi (chiuso per il poligono),
  `hitPolyVertex()`, casi `drag-poly-vertex`. Le hit-test dei rettangoli
  saltano ora le forme poly.
- **analyze_capture.php** — markup selettore forma + pulsante "Termina
  forma".

### Fase 3 — Regioni di cambiamento in m²

- **[python-service/app/core/diff.py](python-service/app/core/diff.py)** —
  `compute_stats()` accetta `mpp_x`/`mpp_y`: aggiunge `changed_area_m2` e
  `largest_region_area_m2` quando la scala reale è nota.
- **[python-service/app/routers/analysis.py](python-service/app/routers/analysis.py)**
  — `CompareRequest` accetta `mpp_x`/`mpp_y`; ogni regione in output ha
  `area_m2`.
- **[webapp/public/api/compare.php](webapp/public/api/compare.php)** —
  passa la scala della ripresa A (`Capture::resolveMpp`) al servizio.
- **[webapp/public/assets/js/study.js](webapp/public/assets/js/study.js)**
  — la vista risultato mostra "Area variata reale" e "Regione più estesa"
  in m²/km² (fallback a pixel se la scala non è nota); la lista regioni
  mostra `area_m2` per regione.
- Richiede il riavvio di `orbitaleye-analysis` (già effettuato).

### Fase 4 — Export KML / GeoJSON

- **[webapp/public/api/export_geo.php](webapp/public/api/export_geo.php)**
  (nuovo) — esporta annotazioni + misurazioni di una ripresa come layer
  georeferenziato: rect→Polygon, polilinea→LineString, poligono→Polygon,
  misura→LineString; coordinate frazionarie → lon/lat via la bbox della
  ripresa (avviso se ruotata in fase di scaricamento — conversione
  approssimata). Colori/etichette preservati (KML `aabbggrr`, GeoJSON
  `stroke`/`name`).
- **analyze_capture.php** — pulsanti "⬇ KML" / "⬇ GeoJSON" nel pannello
  Annotazioni.

### Fase 5 — Stima altezza dall'ombra

- **analyze.js** — `solarElevationDeg(lat, lon, dateUTC)` (algoritmo solare
  NOAA semplificato, ~0.5° di precisione). Nuovo blocco nel pannello
  Misurazioni: data/ora UTC (data pre-compilata da `capture_date` quando
  disponibile), elevazione solare mostrata dal vivo. "📐 Misura un'ombra"
  arma la modalità misura; la linea tracciata diventa
  `altezza ≈ shadow × tan(elevazione)` come etichetta della misurazione
  (persistita). Rifiuta il calcolo con sole sotto 3°. L'ora esatta da
  Sentinel non è ancora auto-popolata.
- **analyze_capture.php** — markup del blocco; `CFG.captureDate` e
  `CFG.geoBbox` (bbox grezza, indipendente dalla scala) aggiunti.

Verificato in ambiente isolato: skew/opacità (drag → angolo corretto non
saturato, slider sincronizzati), scala adattiva (200→100→50→20 m per zoom
1×→8×), misurazioni e poligoni persistono al reload, aree m² (calcolo
diretto), KML/GeoJSON (XML/JSON validi, coordinate corrette), elevazione
solare (valori attesi ai solstizi) e altezza da ombra end-to-end.

## 2026-09-10 — Strumenti di analisi: scala, livello annotazioni incorporabile, calibrazione riprese derivate, mini-anteprima, filtri granulari, NDWI

Batch ampio, frutto di più richieste in sequenza sulla vista di analisi
ripresa singola e sui pannelli di enhancement.

### Correzione bug: calibrazione misure irrealistica sulle riprese derivate

Segnalato dopo uso reale: su ritagli e riprese salvate/migliorate la stima
delle misure risultava del tutto sballata (spesso 4-5x troppo grande su un
ritaglio).

- **[webapp/src/Capture.php](webapp/src/Capture.php)** — nuovo
  `Capture::resolveMpp()` (+ `haversineMeters()` privato): risolve la scala
  reale metri/pixel di una ripresa da `mpp_x`/`mpp_y` già nel meta, oppure
  dalla bbox propria. Serve a farla EREDITARE dalle riprese derivate, che
  mantengono la stessa densità di pixel (nessun ridimensionamento in nessuno
  dei passaggi di salvataggio/migliora/ritaglio). Non ricade mai sulla bbox
  generica dello studio per una derivata.
- **[webapp/public/api/upload_capture.php](webapp/public/api/upload_capture.php)**
  — accetta un `source_capture_id` opzionale ("Salva come nuova ripresa" e
  "Salva ritaglio" ora lo inviano) ed eredita `mpp_x`/`mpp_y` +
  `source_capture_id` nel meta della nuova ripresa.
- **[webapp/public/api/enhance_capture.php](webapp/public/api/enhance_capture.php)**,
  **[webapp/public/api/save_enhanced_capture.php](webapp/public/api/save_enhanced_capture.php)**
  — stessa ereditarietà della scala dalla ripresa sorgente (già nota via
  `source_capture_id`), copre Migliora e gli indici spettrali NDVI/NDWI/
  falso colore IR.
- **[webapp/public/analyze_capture.php](webapp/public/analyze_capture.php)**
  — la scala per lo strumento di misura ora si risolve con
  `Capture::resolveMpp()` (mpp diretti o bbox propria); la bbox generica
  dello studio resta solo come ultimo fallback per riprese originali senza
  bbox propria, mai per le derivate. Passa `mppX`/`mppY` a `CFG`.
- **[webapp/public/assets/js/analyze.js](webapp/public/assets/js/analyze.js)**
  — `computeGeoScale()` preferisce `CFG.mppX`/`CFG.mppY` se presenti,
  saltando il ricalcolo da bbox; "Salva come nuova ripresa" e "Salva
  ritaglio" inviano `source_capture_id`.
- Riparate retroattivamente le riprese di produzione già colpite (54, 55,
  58 dello studio Sigonella) tramite uno script una tantum: la 54 dal suo
  `source_capture_id` già registrato, i ritagli 55/58 dedotti dal nome
  file originale (assegnato dal codice, affidabile). Non nel repo, agisce
  solo sul DB live.

### Barra di scala sovrapposta ("come su una cartina")

- **analyze_capture.php** / **analyze.js** — checkbox "Mostra scala in un
  angolo" nel pannello Misurazioni: disegna in basso a sinistra una barra
  graduata con etichetta, distanza "tonda" scelta automaticamente
  (`niceScaleDistance()`, convenzione 1/2/5 × potenza di 10) in base alla
  scala reale e alla dimensione dell'immagine. Solo a video di default.

### Livello annotazioni/misurazioni/scala incorporabile a scelta

- **analyze.js** — `redrawAnnotations()` rifattorizzata in
  `drawOverlayLayer(ctx, targetW, targetH, includeHandles)`: stessa funzione
  per la vista live (risoluzione CSS) e per l'incorporazione (risoluzione
  nativa), spessori/font scalati col fattore `k`, mai le maniglie di
  modifica nell'export. `renderAdjustedCanvas()` lo incorpora se la checkbox
  "Includi annotazioni/misurazioni/scala" è spuntata (copre Salva + Condividi
  Telegram/X). Nuovo `buildCropBlob()`: per il ritaglio compone il livello a
  piena risoluzione e ne ritaglia la stessa area, garantendo l'allineamento;
  usato da copia/scarica/salva/invia del frammento. Anteprima del frammento
  aggiornata al cambio checkbox.
- **analyze_capture.php** — due checkbox (ripresa intera nel pannello
  Regolazioni, frammento nel pannello ritaglio), sempre disattivate di
  default (scelta esplicita, mai automatica).

### Chroma key (trasparenza per colore) sull'immagine sovrapposta

- **analyze_capture.php** / **analyze.js** — checkbox + selettore colore +
  slider tolleranza: rende trasparente il colore scelto sull'immagine
  sovrapposta (es. sfondo bianco di una mappa), con sfumatura ai bordi
  (`applyChromaKey()`). Stessa elaborazione per anteprima live e disegno
  finale (`overlay.displaySource`, `refreshOverlayDisplay()`).

### Manipolazione diretta dell'immagine sovrapposta — scopribilità

- **analyze_capture.php** / **analyze.js** — la manipolazione diretta
  (trascina il corpo = sposta, angoli = ridimensiona, cerchietto = ruota,
  con slider sincronizzati) esisteva già ma solo in modalità Sovrapponi e
  poco evidente: ora caricando un'immagine si passa automaticamente a quella
  modalità, e i testi di aiuto lo spiegano chiaramente.

### Mini-anteprima flottante: spostabile e ridimensionabile

- **[webapp/public/assets/css/style.css](webapp/public/assets/css/style.css)**
  / **analyze_capture.php** / **analyze.js** — la mini-anteprima che compare
  scorrendo la pagina ora ha un'intestazione trascinabile e un angolo di
  ridimensionamento nativo (`resize: both`), con posizione/dimensione
  ricordate per pagina in localStorage. Corretto anche il riempimento
  dell'immagine interna (`width/height: 100%` + `object-fit: contain`): prima
  con solo `max-width/height` non seguiva l'ingrandimento del bordo.

### Pannelli collassabili

- **[webapp/public/assets/js/common.js](webapp/public/assets/js/common.js)**
  / **style.css** — ogni `.panel` con un `h2` come primo figlio diventa
  collassabile cliccando l'intestazione (icona ▾/▸), stato ricordato per
  pagina+pannello in localStorage, di default tutto aperto. Il click sul
  tooltip informativo non richiude il pannello.

### Granularità dei filtri di enhancement

- **analyze_capture.php** / **study.php** / **analyze.js** / **study.js** —
  esposti i parametri prima nascosti: CLAHE `clip_limit`/`tile_grid_size`,
  Canny soglia bassa/alta; aggiunta la desaturazione al pannello "Filtri
  avanzati" (c'era già in "Migliora"), aggiunto Canny a "Migliora". Nuovo
  pulsante "↺ Reset valori slider" nei tre pannelli (Filtri avanzati,
  Migliora, pre-confronto) che riporta solo i valori ai default senza
  deselezionare i filtri. Backend già parametrico, nessuna modifica lì.
- Ribilanciate le colonne del pannello "Filtri avanzati" (lo spazio vuoto a
  sinistra era dovuto alle nuove righe tutte accumulate a destra).

### NDWI (indice acqua)

- **[python-service/app/core/spectral.py](python-service/app/core/spectral.py)**
  — `compute_ndwi()` = (Verde − NIR)/(Verde + NIR) dai dati Rosso+NIR +
  vero colore già scaricati (nessun nuovo fetch), con palette diverging
  terra→blu dedicata (`colorize_ndwi()`).
- **[python-service/app/routers/analysis.py](python-service/app/routers/analysis.py)**
  — modalità `ndwi` accettata da `/analysis/spectral_view`.
- **[webapp/public/api/spectral_view.php](webapp/public/api/spectral_view.php)**
  / **study.php** / **study.js** — modalità `ndwi` validata, pulsante
  "💧 NDWI" accanto a NDVI/falso colore IR (solo per riprese Sentinel Hub
  con banda NIR), titoli/descrizioni.
- Richiede il riavvio di `orbitaleye-analysis` (già effettuato sul
  deployment).

Verificato in ambiente isolato: ereditarietà della scala end-to-end su
tutti e tre gli endpoint (mpp attesi 0,4996×0,6291 per Sigonella), livello
annotazioni con test di allineamento (misurazione presente solo nel
ritaglio che la include), NDWI via HTTP reale sul servizio riavviato
(nessuna regressione su NDVI/falso colore IR), filtri granulari end-to-end,
manipolazione diretta dell'immagine sovrapposta (sposta/ridimensiona/ruota
con slider sincronizzati). Le parti che dipendono da IntersectionObserver/
ResizeObserver (visibilità e salvataggio dimensione della mini-anteprima)
non erano esercitabili nell'ambiente di test headless.

## 2026-09-06 — Riepilogo globale delle pianificazioni di scaricamento automatico

Su richiesta esplicita, dopo un controllo di salute del motore di
scaricamento pianificato (studio Sigonella): prima si poteva sospendere/
riattivare/eliminare una pianificazione solo entrando nella pagina dello
studio corrispondente (sezione scaricamento) — nessun modo di vedere lo
stato di tutte le pianificazioni di tutti gli studi in un colpo solo.

- **[webapp/src/ScheduledDownload.php](webapp/src/ScheduledDownload.php)**
  — nuovi metodi `allWithStudy()` (tutte le pianificazioni con titolo
  studio già risolto via join, attive prima) e `errorCount()` (conteggio
  pianificazioni attive il cui ultimo controllo è fallito).
- **[webapp/public/schedules.php](webapp/public/schedules.php)** (nuova
  pagina) — tabella con studio, fonte, cadenza, soglia duplicati, ultimo
  controllo, esito (messaggio d'errore in tooltip se presente), stato
  attiva/sospesa; stessi pulsanti "Sospendi"/"Riattiva"/"Elimina" già
  presenti nella pagina di studio, stesso endpoint
  `api/schedule_download.php` esistente, **nessuna modifica al backend
  dell'endpoint**.
- **[webapp/public/partials/nav.php](webapp/public/partials/nav.php)** —
  nuova voce di menu "Pianificazioni", con un badge d'avviso (rosso) che
  compare solo se almeno una pianificazione attiva ha l'ultimo controllo
  in errore, per non lasciar passare inosservato un fallimento silenzioso
  (es. rete Esri irraggiungibile) senza dover controllare studio per
  studio.

Verificato in un ambiente completamente isolato (copia separata di
webapp/DB, server PHP dedicato, login di test): caricamento della pagina,
sospendi/riattiva/elimina end-to-end, stato vuoto, nessun errore/warning
PHP su nessuna delle pagine che includono il nuovo blocco di navigazione
(index, alerts, schedules).

## 2026-09-03 (4) — Ricerca inversa: aggiunti ChatGPT e DeepSeek come ulteriori destinazioni

Su richiesta esplicita: stesso identico percorso già in uso per Google
Lens e Claude, riusato per due ulteriori destinazioni a scelta
dell'analista.

- **[webapp/public/analyze_capture.php](webapp/public/analyze_capture.php)**
  — aggiunti i pulsanti "Apri ChatGPT ↗" e "Apri DeepSeek ↗" accanto a
  quelli di Lens/Claude; passi e tooltip aggiornati per le quattro
  destinazioni indipendenti (una, alcune, o tutte sullo stesso
  frammento), con nota che "Scarica frammento" resta il fallback se
  l'incolla non fosse supportato dal sito aperto.
- **[webapp/public/assets/js/analyze.js](webapp/public/assets/js/analyze.js)**
  — due nuovi handler che aprono rispettivamente `https://chatgpt.com/` e
  `https://chat.deepseek.com/` in una scheda: riusano interamente il
  pulsante "Copia negli appunti" già esistente, nessuna nuova logica,
  nessuna chiave API, nessun endpoint backend.

## 2026-09-03 (3) — Salvataggio e condivisione diretta del ritaglio selezionato

Su richiesta esplicita: la sottosezione del ritaglio (già usata per la
ricerca inversa/analisi Lens+Claude) ora permette anche di salvare il
frammento come nuova ripresa dello studio e di condividerlo direttamente
su Telegram/X, senza dover prima "cuocere" o salvare l'intera copia di
lavoro.

- **[webapp/public/analyze_capture.php](webapp/public/analyze_capture.php)**
  — nella sottosezione "Ricerca inversa e analisi per immagini", due nuovi
  blocchi sotto ai pulsanti Lens/Claude: "Salva ritaglio" (etichetta +
  pulsante "Salva ritaglio come nuova ripresa") e "Condividi ritaglio"
  (didascalia + "Invia su Telegram"/"Apri su X").
- **[webapp/public/assets/js/analyze.js](webapp/public/assets/js/analyze.js)**
  — i nuovi pulsanti riusano interamente `api/upload_capture.php` (lo
  stesso endpoint di "Salva come nuova ripresa" sulla ripresa intera) e
  `api/share.php` (lo stesso del pannello "Condividi" esistente),
  applicati a `lastCropBlob` (il blob del solo frammento, già usato per
  Lens/Claude/copia) invece che al canvas intero: **zero modifiche al
  backend**, solo riuso di endpoint già in produzione. Stessa protezione
  anti-doppio-click già introdotta per gli altri pulsanti di invio. Il
  salvataggio del ritaglio non forza la navigazione via dallo studio (a
  differenza del pulsante equivalente sulla ripresa intera), per poter
  salvare e condividere lo stesso frammento senza perdere il contesto; per
  l'incolla su X non serve un pulsante "copia" duplicato, riusa quello già
  presente per Lens/Claude.

Verificato con uso reale dall'analista.

## 2026-09-03 (2) — Ricerca inversa: aggiunta Claude come seconda destinazione, accanto a Google Lens

Su richiesta esplicita: dopo aver discusso e scartato un'integrazione via
API (chiave dedicata, chiamate backend, log di audit — giudicata inutilmente
complessa per l'obiettivo), l'analista ha chiesto di riusare esattamente lo
stesso percorso manuale già in uso per Google Lens, con Claude come seconda
destinazione indipendente.

- **[webapp/public/analyze_capture.php](webapp/public/analyze_capture.php)**
  — pannello "Ricerca inversa per immagini" rinominato "Ricerca inversa e
  analisi per immagini"; aggiunto il pulsante "🤖 Apri Claude ↗" accanto a
  quello di Google Lens; passi e tooltip aggiornati per riflettere le due
  destinazioni indipendenti (una, l'altra, o entrambe sullo stesso
  frammento) e un suggerimento di prompt testuale (non copiato negli
  appunti, solo indicativo in pagina, per non interferire con l'incolla
  dell'immagine).
- **[webapp/public/assets/js/analyze.js](webapp/public/assets/js/analyze.js)**
  — nuovo handler che apre `https://claude.ai/new` in una scheda: riusa
  interamente il pulsante "Copia negli appunti" già esistente e testato per
  Lens, nessuna nuova logica di clipboard, nessuna chiave API, nessun
  endpoint backend, nessun log — l'invio resta un gesto manuale
  dell'analista (incolla lui stesso), esattamente come per Lens.

Verificato con uso reale dall'analista ("Ho provato, funziona bene").

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
