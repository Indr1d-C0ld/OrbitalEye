# ◈ OrbitalEye

**Piattaforma self-hosted per l'analisi comparativa di immagini satellitari** — allineamento automatico, rilevamento dei cambiamenti nel tempo con filtri anti-rumore, enhancement dell'immagine, annotazioni e libreria degli studi, con un'interfaccia ispirata alle console di analisi/intelligence.

Pensata per essere installata ed eseguita su un proprio server (VPS, homelab, NAS), senza dipendere da servizi cloud di terze parti per l'elaborazione.

![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)

---

## Indice

- [Cos'è OrbitalEye](#cosè-orbitaleye)
- [Caratteristiche](#caratteristiche)
- [Architettura](#architettura)
- [Fonti immagini e note legali](#fonti-immagini-e-note-legali)
- [Requisiti](#requisiti)
- [Installazione](#installazione)
- [Primo avvio](#primo-avvio)
- [Guida all'uso](#guida-alluso)
- [Configurazione avanzata](#configurazione-avanzata)
- [Sicurezza](#sicurezza)
- [Struttura del progetto](#struttura-del-progetto)
- [Librerie e servizi di terze parti](#librerie-e-servizi-di-terze-parti)
- [Licenza](#licenza)

---

## Cos'è OrbitalEye

OrbitalEye confronta due riprese satellitari della stessa area geografica scattate in momenti diversi e ne evidenzia automaticamente le differenze: nuove costruzioni, variazioni del territorio, cambi di uso del suolo, movimenti di grandi strutture. È pensato per chi vuole condurre questo tipo di analisi in autonomia — ricercatori, giornalisti investigativi, analisti OSINT, urbanisti, ambientalisti, appassionati di osservazione della Terra — senza dover dipendere da piattaforme SaaS chiuse o inviare i propri dati a terzi.

Il confronto non è una semplice sovrapposizione: le due immagini vengono prima **riallineate automaticamente** (per correggere piccoli scostamenti di inquadratura tra le riprese), poi confrontate con algoritmi di elaborazione immagini (SSIM o differenza assoluta), ripulite dal rumore fotografico con filtri morfologici, e infine presentate come report visivo con le aree di cambiamento evidenziate ed elencate.

## Caratteristiche

**Acquisizione riprese**
- Caricamento manuale di immagini da qualunque fonte tu sia autorizzato a usare offline
- Fetch automatico da **Copernicus** — **Sentinel-2** ottico (~10 m/pixel, con coppia Rosso+NIR scaricata a parte per gli indici spettrali) e **Sentinel-1** radar SAR (vede di notte e attraverso le nuvole) — e da **Esri World Imagery** (risoluzione spesso sub-metrica; retry automatico a risoluzione ridotta contro il limite di complessità del servizio) tramite le rispettive API ufficiali
- **Ogni ripresa Copernicus è un singolo passaggio con data e ora certe**: "Cerca passaggi" elenca tutti i passaggi del periodo sull'area con satellite e orbita e, per Sentinel-2, la **nuvolosità sulla sola area di interesse** (non sull'intero tassello di 110 km, che può dire 60% con l'area sgombra o 5% con l'area coperta) e la quota di area coperta. Senza scelta, si scarica il passaggio più recente entro la soglia di nuvole
- **Archivio storico Esri (Wayback)**: le versioni del mosaico World Imagery pubblicate dal 2014 in cui l'immagine dell'area è davvero diversa, raggruppate per acquisizione e ciascuna con la data reale dello scatto — su una base aerea tipica una decina di immagini sub-metriche dal 2011 a oggi, già allineate pixel per pixel con le riprese Esri correnti della stessa area
- **Data reale delle immagini Esri**: il mosaico World Imagery è composto da acquisizioni di epoche diverse e aggiornato di rado, quindi la data del download non dice nulla su quando è stata scattata la foto. Per ogni ripresa vengono letti dai metadati ufficiali Esri data, sensore (WorldView-2/3, Legion…), risoluzione nativa e fornitore di ogni acquisizione che copre l'area, con la quota di area di ciascuna; la data prevalente diventa la data della ripresa. Le riprese più vecchie si datano com'erano il giorno del download grazie all'archivio storico Wayback
- Selettore d'area interattivo su mappa (Leaflet, incluso nel progetto) con mappa stradale Esri World Street Map, basemap satellitare opzionale e **ricerca luogo** per nome (Nominatim/OpenStreetMap, solo su richiesta esplicita)
- Possibilità di **ruotare l'area** prima dello scaricamento: la rotazione avviene nelle proporzioni reali del terreno, quindi la ripresa salvata coincide esattamente con il poligono mostrato sulla mappa, a qualunque latitudine
- **Scaricamento pianificato guidato dai nuovi dati**: si scarica solo quando la fonte ha qualcosa di nuovo — un passaggio Sentinel-2 successivo all'ultimo con nuvole sull'area entro la soglia, un passaggio Sentinel-1 della stessa orbita, oppure un aggiornamento delle immagini Esri dell'area (riconosciuto dai metadati, senza scaricare) — con **alert** che riporta le date e la variazione rispetto alla ripresa precedente; pagina di gestione centralizzata di tutte le pianificazioni con badge d'errore nel menu

**Analisi e change detection**
- Allineamento automatico (feature matching ORB/RANSAC + rifinitura sub-pixel ECC) o **manuale** tramite editor di punti di controllo. Quando l'allineamento non è affidabile il motore lo dichiara (metodo "none") invece di forzare un risultato, e punti di controllo coincidenti o allineati vengono rifiutati con una spiegazione
- Confronto per differenza SSIM (robusta a luce/contrasto) o differenza assoluta
- Soglia di sensibilità manuale o automatica (Otsu), con preset rapidi (bassa/media/alta)
- Pulizia morfologica e filtro per area minima, per scartare rumore fotografico e falsi positivi
- Filtri di enhancement pre-analisi con **parametri regolabili**: bilanciamento del bianco, riduzione rumore (4 metodi + intensità), CLAHE (clip limit + tile), equalizzazione istogramma, correzione gamma, sharpening, desaturazione, contorni
- Viste multiple: overlay differenze, heatmap, contorni (edge detection), maschera binaria, slider prima/dopo
- Statistiche: superficie variata (%), numero di regioni e — quando la scala reale è nota — **aree in m²/km²** (totale variato, regione più estesa, area di ogni regione)
- Regioni di cambiamento rilevate automaticamente, numerate, cliccabili (zoom automatico sulla regione) e convertibili in annotazioni con un click
- **Indici spettrali** per le riprese Sentinel-2 con banda NIR: NDVI (vegetazione), NDWI (acqua), falso colore infrarosso. La banda NIR è scaricata senza saturazione, così l'NDVI della vegetazione densa non viene compresso
- Le zone senza dati delle riprese (trasparenza / `dataMask` Sentinel) sono escluse dal calcolo del cambiamento

**Vista di analisi ripresa singola**
- Zoom/pan sincronizzato tra originale e copia di lavoro (rotellina/pulsanti, pinch-to-zoom e trascinamento su touch)
- Regolazioni in tempo reale (luminosità, contrasto, saturazione, nitidezza, gamma) nel browser via filtri CSS/SVG; filtri avanzati elaborati dal servizio Python con gli stessi parametri granulari
- **Sovrapposizione immagine** con manipolazione diretta sull'immagine: sposta (corpo), ridimensiona (angoli), inclina/skew (metà lato), ruota (maniglia in alto), opacità (guida in basso), tutte sincronizzate con gli slider; **chroma key** (trasparenza selettiva per colore, con sfumatura ai bordi)
- **Misurazioni** distanza reale sul terreno (metri/pixel per asse, consapevoli della rotazione dell'area), con etichette, persistenti; calibrazione manuale se manca la scala geografica
- **Stima altezza da ombra** (elevazione solare + lunghezza dell'ombra)
- **Annotazioni vettoriali**: rettangolo, polilinea, poligono (vertici trascinabili), con colore ed etichetta, persistenti
- **Barra di scala** sovrapposta "come su una cartina": adattiva allo zoom nella vista live, fissa nell'export
- **Ritaglio** di un frammento → ricerca inversa (Google Lens) e/o analisi con assistenti AI (Google Gemini, Claude, ChatGPT, DeepSeek) per incolla manuale; il frammento si può salvare come nuova ripresa (con la propria area geografica esatta) o condividere
- **Livello annotazioni/misurazioni/scala incorporabile a scelta** nelle immagini salvate/condivise
- Salva come nuova ripresa (cuoce le regolazioni nei pixel), con **ereditarietà di scala, area geografica, rotazione e data** dalla sorgente (misurazioni ed export corretti anche su ritagli e riprese migliorate)
- Mini-anteprima flottante durante lo scroll (spostabile, ridimensionabile, ricordata); pannelli collassabili in tutta la piattaforma

**Identificazione**
- **Identificatore di velivoli dalle misure**: misuri apertura alare (o diametro del rotore) e lunghezza, e la piattaforma propone i tipi compatibili da una **tabella locale di 166 velivoli** militari e civili frequenti nelle basi (caccia, addestratori, bombardieri, trasporti e aerocisterne, sorveglianza, executive, linea, droni, elicotteri), con link alla scheda tecnica di ciascuno. La compatibilità tiene conto della risoluzione della ripresa; per i velivoli a geometria variabile si considera anche l'apertura ad ali a freccia, la configurazione tipica a terra; un filtro sulla **forma delle ali** (freccia, dritta, delta…) separa tipi di dimensioni simili. Tutto offline
- **Rilevamento automatico** di aerei, elicotteri, navi, veicoli e altri oggetti con un modello di intelligenza artificiale che gira sul tuo server (YOLO11s-OBB, dataset DOTA): nessuna immagine lascia la piattaforma. Riquadri orientati disegnati sulla ripresa; per ogni velivolo, misure del riquadro e tipi compatibili; con un clic diventano annotazioni con il tipo scelto. Per riprese dettagliate (fino a 2 m/pixel)
- **Storico dell'area**: per ogni ripresa di uno studio, nell'ordine della data reale dell'immagine, quanti aerei, elicotteri, navi e veicoli sono stati rilevati e quali tipi identificati, con grafico, rilevamento in serie e export CSV

**Organizzazione e output**
- Annotazioni e misurazioni persistenti, disegnabili direttamente sulle immagini
- Libreria degli studi salvati, con ricerca
- **Condivisione** manuale di ripresa/ritaglio/confronto/riepilogo studio su Telegram (bot) o X/Twitter (finestra di composizione), con registro di controllo — nessuna pubblicazione automatica, mai agganciata al motore di scaricamento
- **Scheda di pubblicazione**: l'immagine con una fascia che riporta titolo, data reale e sensore, barra di scala, freccia del nord e attribuzione richiesta dalla fonte — composta sul server, identica su Telegram, nella copia da incollare su X e nell'anteprima; per i confronti anche con **Prima e Dopo affiancate**
- **Album Telegram** con l'immagine principale e le due riprese confrontate, e **file a piena risoluzione** allegato (Telegram comprime le foto)
- **Coda di revisione** facoltativa: le condivisioni vanno prima a una chat privata, dove si vedono esattamente come usciranno, e partono verso il canale solo dopo l'approvazione nella piattaforma (pagina **Pubblicazioni**, con il registro di tutto ciò che è stato pubblicato)
- **Provenienza in ogni pubblicazione**: data reale dell'immagine, sensore e attribuzione richiesta dalla fonte (Esri, Copernicus o quella dichiarata al caricamento) nella didascalia e, a scelta, in una striscia sull'immagine stessa; avviso sui termini d'uso Esri accanto ai pulsanti di condivisione; segnalazione quando si confrontano due riprese della stessa acquisizione
- **Export georeferenziato KML/GeoJSON** di annotazioni e misurazioni (per Google Earth / QGIS), esatto anche per riprese ruotate, ritagli e copie derivate
- Export per singola immagine, per confronto (ZIP con immagini + report HTML/JSON) o per l'intera libreria
- Tooltip esplicativi su ogni parametro/filtro

**Interfaccia**
- Responsive: utilizzabile da desktop, tablet e smartphone (menu a scomparsa, target touch dedicati)
- Tema scuro in stile console di analisi, con font monospace/display dedicati

## Architettura

```
orbitaleye/
├── python-service/       Motore di analisi immagini (FastAPI + OpenCV/NumPy)
│   └── app/
│       ├── core/          registrazione, diff, enhancement, rilevamento oggetti, client Copernicus/Esri/Wayback
│       └── routers/        /fetch/*, /analysis/compare, /analysis/enhance, /analysis/detect
│   ├── models/            modello di rilevamento (non versionato, vedi Installazione)
│   └── tools/             script di installazione del modello
├── webapp/                 Frontend PHP (autenticazione, libreria, annotazioni, DB SQLite)
│   ├── public/               document root del webserver
│   ├── src/                   classi applicative
│   └── config/                 configurazione (config.php, non versionato)
└── storage/                 Filesystem condiviso tra i due servizi
    ├── raw/                    riprese originali (upload o fetch)
    ├── processed/               output di enhancement standalone
    ├── results/                  output dei confronti (overlay, heatmap, contorni, maschera)
    ├── publications/              immagini in attesa di revisione (coda di pubblicazione)
    └── config/                    credenziali dinamiche (Sentinel Hub/Esri), non versionate
```

Il webapp PHP e il servizio Python girano sulla stessa macchina e comunicano in due modi: il PHP invoca l'API REST del servizio Python (in locale, `127.0.0.1`) passandogli solo **percorsi relativi** alle immagini — non ne carica/scarica mai i byte via HTTP — mentre entrambi leggono/scrivono sullo stesso filesystem condiviso (`storage/`). Questo significa che il servizio Python non deve mai essere esposto pubblicamente: basta che sia raggiungibile dal webapp sulla stessa macchina.

## Fonti immagini e note legali

Le tile satellitari di **Google Maps/Earth non sono scaricabili in blocco** per analisi offline: i relativi Termini di Servizio vietano l'estrazione e l'archiviazione massiva delle tile al di fuori del visualizzatore ufficiale (lo stesso vale, in generale, per lo scraping di tile XYZ grezze da qualunque provider di basemap tramite strumenti di terze parti pensati per aggirare questi limiti). Per questo il fetch automatico di OrbitalEye usa esclusivamente fonti che espongono un **punto di integrazione ufficiale** pensato per richieste programmatiche:

- **[Copernicus Data Space Ecosystem](https://dataspace.copernicus.eu)** (ESA/UE) — gratuito, richiede un client OAuth gratuito. **Sentinel-2** L2A (ottico, ~10 m/pixel, un passaggio ogni 2–5 giorni, archivio dal 2015) e **Sentinel-1** GRD (radar, ~10 m/pixel, calibrato, ortorettificato sul DEM Copernicus, filtro anti-speckle; archivio dal 2014). Si usano le API Catalog (elenco dei passaggi), Statistical (nuvole sull'area) e Process (scaricamento), tutte con lo stesso client; le ricerche consumano poche unità di elaborazione del piano gratuito.
- **[Esri World Imagery](https://developers.arcgis.com)** — immagine attuale tramite l'operazione REST ufficiale `/export` del MapServer pubblico, risoluzione spesso sub-metrica (varia per zona). Funziona anche senza API key per uso leggero; per un uso sostenuto è consigliato un account ArcGIS Developer gratuito.
- **[Esri World Imagery Wayback](https://livingatlas.arcgis.com/wayback/)** — versioni storiche del mosaico, dal servizio WMTS che Esri documenta per l'uso in client GIS di terze parti (es. QGIS): per una ripresa si scaricano solo i pochi tasselli che coprono l'area, come fa un client GIS quando esporta una mappa. Valgono gli stessi termini d'uso di World Imagery (vedi sotto).

Per immagini a risoluzione ancora più alta puoi sempre usare il **caricamento manuale**, con qualunque fonte tu sia legalmente autorizzato a usare offline (dataset pubblici come USGS/NAIP per gli USA, riprese aeree proprie, immagini acquistate da provider commerciali con licenza per uso offline, ecc.).

### Pubblicare le immagini: termini d'uso e attribuzione

- **Copernicus Sentinel**: dati liberi e aperti per qualunque uso lecito, compresa la pubblicazione; richiesta la dicitura *"Contains modified Copernicus Sentinel data [anno]"* per immagini elaborate ([Sentinel Data Legal Notice](https://sentinels.copernicus.eu/documents/247904/690755/Sentinel_Data_Legal_Notice)). OrbitalEye la inserisce automaticamente.
- **Esri World Imagery**: i [termini d'uso delle immagini statiche](https://doc.arcgis.com/en/arcgis-online/reference/static-maps.htm) consentono uso personale o interno, rapporti per clienti, materiale promozionale proprio, pubblicazioni accademiche e opere governative; per ogni altro uso — come la pubblicazione su canali pubblici — chiedono un permesso preventivo ([richiesta](https://www.esri.com/en-us/legal/copyright-inquiry)). OrbitalEye inserisce l'attribuzione richiesta e mostra questo avviso prima di ogni pubblicazione, ma la valutazione resta a chi pubblica.
- **Immagini caricate a mano**: il campo "Fonte / attribuzione" del caricamento viene riportato nelle didascalie e sull'immagine.

Il selettore mappa integrato usa tile **Esri World Street Map** per la navigazione (i tile OSM anonimi vengono spesso limitati per uso non banale) e, opzionalmente, tile **Esri World Imagery** come basemap satellitare per il solo riconoscimento visivo dell'area — in entrambi i casi solo visualizzazione interattiva, senza alcun download/archiviazione delle tile. La **ricerca luogo** interroga [Nominatim](https://nominatim.org/) (OpenStreetMap) solo quando l'analista preme Invio o la lente, mai mentre digita, nel rispetto della sua policy d'uso. La libreria Leaflet è inclusa nel progetto e servita localmente: aprire una mappa non comunica nulla a CDN di terze parti.

## Requisiti

- Linux con Apache2 (mod_php) + **PHP 8.1+** (estensioni: `pdo_sqlite`, `curl`, `fileinfo`, `session`, `zip`)
- **Python 3.10+**
- Un client OAuth Copernicus Data Space Ecosystem (gratuito) se si vuole il fetch automatico da Sentinel-2 — non necessario per Esri (uso leggero) né per il solo caricamento manuale

## Installazione

```bash
git clone https://github.com/Indr1d-C0ld/OrbitalEye.git
cd OrbitalEye
```

### 1. Permessi

```bash
bash fix_permissions.sh
```

Non serve sudo: imposta proprietario/gruppo su tutto il progetto (auto-rileva il tuo utente; passa `bash fix_permissions.sh utente gruppo` per specificarli esplicitamente), rende scrivibili da webserver e servizio Python le cartelle condivise (`storage/*`, `webapp/data/`) senza lasciarle leggibili ad altri utenti della macchina (il database contiene token e credenziali), restringe i file con segreti e isola il virtualenv Python.

### 2. Servizio Python (motore di analisi)

```bash
cd python-service
./run.sh   # primo avvio: crea il venv, installa le dipendenze, copia .env.example in .env
```

Ferma il processo (Ctrl+C) e apri `.env`: imposta almeno

- `SERVICE_API_KEY`: una stringa lunga e casuale (es. `openssl rand -hex 32`)
- `STORAGE_ROOT`: percorso assoluto della cartella `storage/` del progetto — deve combaciare con `storage_root` in `webapp/config/config.php`

Le credenziali Sentinel Hub/Esri possono restare vuote qui: si impostano più comodamente dalla pagina **Impostazioni** del webapp una volta online.

**Rilevamento automatico (facoltativo).** Il modello (37 MB) non è nel repository: per installarlo

```bash
bash python-service/tools/fetch_detector_model.sh
```

Lo script scarica YOLO11s-OBB dalle release ufficiali Ultralytics e lo converte in ONNX, eseguito poi con OpenCV senza dipendenze aggiuntive. La conversione usa PyTorch in un ambiente temporaneo (~300 MB scaricati, ~1,5 GB su disco) che lo script cancella alla fine. Senza il modello tutto il resto funziona; il pannello di rilevamento risponde che il modello non è installato.

Per tenerlo sempre attivo è incluso un unit systemd già pronto (adatta `User=` e i percorsi al tuo ambiente prima di installarlo — vedi il commento nel file):

```bash
sudo cp python-service/orbitaleye-analysis.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now orbitaleye-analysis
sudo systemctl status orbitaleye-analysis   # deve essere "active (running)"
```

### 3. Webapp PHP

```bash
cp webapp/config/config.example.php webapp/config/config.php
```

Modifica `config.php`:
- `python_service_key` deve combaciare con `SERVICE_API_KEY` del `.env` Python
- `storage_root` deve combaciare con `STORAGE_ROOT`

### 4. Webserver

Il **document root** del virtual host Apache deve puntare a `webapp/public` (non alla cartella del progetto intera). Due modi comuni per farlo:

**A. Vhost dedicato** (dominio o sottodominio proprio):

```apache
<VirtualHost *:443>
    ServerName orbitaleye.tuo-dominio.tld
    DocumentRoot /percorso/a/OrbitalEye/webapp/public
    <Directory /percorso/a/OrbitalEye/webapp/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

**B. Sottopercorso di un vhost esistente** (es. `https://tuo-dominio.tld/orbitaleye/`), tramite `Alias`:

```apache
    Alias /orbitaleye /percorso/a/OrbitalEye/webapp/public
    <Directory /percorso/a/OrbitalEye/webapp/public>
        Options -Indexes
        AllowOverride All
        Require all granted
    </Directory>
```

In entrambi i casi, i file `.htaccess` già presenti fanno da rete di sicurezza aggiuntiva: con l'opzione B, in particolare, ogni richiesta sotto `/orbitaleye/` viene rimappata direttamente su `webapp/public`, quindi il resto dell'albero del progetto (`python-service/`, `storage/`, `webapp/src`, `webapp/config`, `webapp/data`) non è raggiungibile da nessun URL.

Uno script `deploy_apache.sh` è incluso come riferimento per inserire automaticamente il blocco `Alias` in un vhost Debian/Ubuntu standard (`000-default.conf`/`000-default-le-ssl.conf`): backuppa i file prima di modificarli e verifica la sintassi prima di ricaricare Apache. Leggilo e adattalo al tuo ambiente prima di eseguirlo — ogni installazione è diversa.

## Primo avvio

Apri il sito: verrai reindirizzato a `setup.php` per creare l'account operatore (username + password, uno solo — OrbitalEye è pensato per uso mono-utente/personale). Da lì:

1. **Impostazioni** → inserisci le credenziali Sentinel Hub/Esri (opzionali) e i parametri di analisi predefiniti.
2. **Nuovo Studio** → crea uno studio per un'area di interesse, disegnandola sulla mappa integrata.
3. Nella pagina dello studio: carica manualmente due riprese, oppure scaricale da Copernicus (scegli due passaggi dall'elenco) o da Esri (immagine attuale e versioni storiche), selezionale come **A (prima)** e **B (dopo)**, regola soglia/filtri (o usa un preset di sensibilità) ed esegui il confronto.
4. Esplora i risultati: overlay, heatmap, contorni, maschera, slider prima/dopo. Clicca una regione rilevata (nella lista o direttamente sull'immagine) per ingrandirla, o trasformala in un'annotazione con un click.
5. Salva i confronti interessanti in **Libreria** ed esportali (ZIP con immagini + report) quando serve condividerli o archiviarli.

## Guida all'uso

### Pipeline di analisi (sintesi)

1. **Allineamento** (ORB + RANSAC, rifinito con ECC sub-pixel) — corregge piccoli disallineamenti tra le due riprese prima di confrontarle; i bordi privi di dati reali generati dal riallineamento vengono esclusi dal calcolo per evitare falsi positivi lungo il perimetro.
2. **Enhancement opzionale** — bilanciamento del bianco, riduzione rumore, CLAHE, equalizzazione istogramma, gamma, sharpening, desaturazione, contorni (tutti con parametri regolabili), applicato a entrambe le riprese in modo coerente prima del confronto.
3. **Differenza** — SSIM (si concentra sui cambi strutturali reali) oppure differenza assoluta (più veloce, più sensibile a variazioni di luce/colore non legate a cambiamenti reali).
4. **Soglia** — manuale o Otsu automatica.
5. **Pulizia** — apertura/chiusura morfologica + filtro per area minima del blob, per scartare rumore fotografico e micro-disallineamenti residui.
6. **Report grafico** — overlay in falso colore con bounding box numerate e cliccabili, heatmap, contorni, maschera binaria, slider prima/dopo.
7. **Statistiche** — superficie variata (%), numero di regioni e, quando la scala reale della ripresa è nota, aree in m²/km² (totale e per regione).

### Scegliere le immagini da confrontare

- **Sentinel-2** (sezione Copernicus): imposta il periodo e premi **Cerca passaggi**. Ogni riga è un passaggio con data e ora UTC, satellite, nuvole e copertura *sulla tua area*; scegli due passaggi sgombri e scaricali. A ~10 m per pixel servono aree di qualche chilometro.
- **Sentinel-1** (radar): utile quando le nuvole coprono l'area per settimane o per osservare di notte. Piste e piazzali appaiono scuri, edifici e oggetti metallici — velivoli compresi — punti chiari. Per confrontare due riprese radar scegli passaggi **della stessa orbita** (stessa geometria di vista): da orbite diverse le strutture sono viste da lati opposti e quasi tutto risulterebbe "cambiato". La piattaforma lo segnala.
- **Esri, versioni storiche**: **🕰 Versioni storiche** elenca le immagini diverse disponibili nell'archivio Wayback per l'area, con data reale, sensore e risoluzione; le versioni dell'archivio che contengono la stessa acquisizione sono raggruppate ("+N"). Le immagini storiche e quella attuale della stessa area sono già allineate pixel per pixel. Il confronto automatico tra immagini sub-metriche di anni e sensori diversi è sensibile a ombre, colori e vegetazione: usa soglie più alte e un'area minima più grande, o guarda le immagini affiancate con lo slider prima/dopo.
- Se due riprese sono **la stessa acquisizione** (stesso passaggio, o la stessa immagine Esri scaricata due volte o in due versioni dell'archivio), il confronto lo dice: le differenze misurate vengono solo da risoluzione, ritaglio o elaborazione.

### Identificare velivoli

- **Dalle misure**: nella vista di analisi, con lo strumento Misura traccia l'apertura alare (da un'estremità all'altra delle ali; per un elicottero il diametro del rotore) e la lunghezza (dal muso alla coda). Etichettandole "apertura alare" e "lunghezza" vengono scelte da sole nel riquadro **Identifica velivolo dalle misure**. Scegli la forma delle ali che vedi sulla ripresa e premi **Trova tipi compatibili**. Con **🏷 Annota** il tipo scelto diventa un'annotazione intorno al velivolo, con un punto di domanda: è un'ipotesi da confermare guardando motori, impennaggi, forma della fusoliera.
- **Automaticamente**: **🎯 Rileva oggetti** trova aerei, elicotteri, navi e veicoli. I lati dei riquadri sono un limite superiore delle dimensioni (includono margine e spesso l'ombra), e i tipi compatibili ne tengono conto. Scegli un tipo fra quelli proposti e salva come annotazioni. Il modello può sbagliare o mancare oggetti, soprattutto piccoli: l'opzione **Cerca anche oggetti piccoli** ingrandisce l'immagine prima del rilevamento ed è più lenta (su un processore desktop di qualche anno, da qualche secondo a un paio di minuti).
- **Nel tempo**: il pannello **Storico dell'area** dello studio riassume, data per data, conteggi e tipi identificati; **Rileva sulle riprese mancanti** esegue il rilevamento su tutte le riprese adatte, e **CSV** esporta la tabella.

### Annotazioni, misurazioni e regioni

Le annotazioni sono forme vettoriali disegnabili a mano — **rettangolo, polilinea, poligono** (vertici trascinabili) — su qualunque vista, con colore, etichetta e note. Sono persistenti e legate alla ripresa/confronto. Le regioni rilevate automaticamente diventano annotazioni con un click ("✎ Annota"), preservando le coordinate esatte del rilevamento.

Nella vista di analisi ripresa singola sono disponibili anche **misurazioni** di distanza reale sul terreno (persistenti, con etichette), la **stima di altezza da ombra** e una **barra di scala** sovrapponibile. Annotazioni, misurazioni e scala possono essere **incorporate a scelta** nelle immagini salvate o condivise.

### Condivisione ed export

- **Condivisione** (Telegram / X) di una ripresa, di un confronto o del riepilogo di uno studio: gesto sempre manuale e previewabile, con didascalia modificabile e registro di controllo. Nessun contenuto viene mai pubblicato automaticamente.
- **Data e fonte**: la didascalia proposta riporta la data reale dell'immagine (non quella del download) e l'attribuzione richiesta dalla fonte.
- **Formato**: *Scheda di pubblicazione* (predefinito) — l'immagine con una fascia in basso: titolo (modificabile), data reale e sensore, barra di scala, freccia del nord (ruotata per le aree ruotate) e attribuzione completa; *Scheda con Prima e Dopo affiancate* per i confronti, con data su ciascuna; *Striscia data e fonte*; *Solo immagine*. Le immagini sono composte sul server, quindi Telegram, la copia per X (**📋 Copia**) e l'anteprima (**👁 Anteprima**) sono identiche. Data, fonte e scheda non vengono mai scritte nelle riprese salvate in archivio né nel frammento per la ricerca inversa. Nessuna coordinata viene aggiunta.
- **Album e file a piena risoluzione** (Telegram): per un confronto, un album con l'immagine principale e le due riprese a piena dimensione, ciascuna con la propria data; per qualunque condivisione, la stessa immagine anche come file, senza la compressione di Telegram.
- **Revisione**: con una chat di revisione configurata, l'opzione **Passa dalla revisione** (attiva di default) manda il contenuto prima lì, con un link alla pagina **📤 Pubblicazioni**. Lì si vede com'è, si può correggere la didascalia, e lo si **approva** — solo allora parte, identico, verso il canale — o lo si **scarta**. La chat di revisione riceve l'esito. Il bot non riceve comandi da Telegram: l'approvazione avviene sempre nella piattaforma, dopo il login.
- **Export georeferenziato KML / GeoJSON** di annotazioni e misurazioni, pronto per Google Earth / QGIS. La conversione in coordinate geografiche è esatta anche per le riprese ruotate e per ritagli e copie derivate (che conservano l'area geografica della sorgente); solo per un'immagine caricata a mano senza alcun riferimento viene usata, con un avviso esplicito, l'area dello studio.
- **Singola immagine**: download diretto con nome file descrittivo.
- **Confronto**: ZIP con le due riprese originali, le immagini di risultato, un report HTML autonomo (apribile offline, con tutti i parametri/statistiche/regioni/annotazioni) e gli stessi dati in JSON.
- **Libreria**: export massivo (tutta la libreria, il risultato di una ricerca, o una selezione) in un unico ZIP con una sottocartella per confronto.

## Configurazione avanzata

Dalla pagina **Impostazioni** puoi modificare in qualunque momento, senza toccare file:
- Credenziali Copernicus (Sentinel Hub) e token Esri (sincronizzate automaticamente col servizio Python)
- Bot Telegram per la condivisione (token + chat id; restano nel DB, mai in un file versionato), chat di revisione facoltativa e indirizzo pubblico della piattaforma (per il link "approva" nei messaggi di revisione)
- Parametri di analisi predefiniti (metodo diff, soglia, area minima blob, kernel morfologico, opacità overlay)
- Password dell'account

Per inviare file più grandi dei limiti dell'API pubblica di Telegram si può
usare un [server Bot API locale](https://github.com/tdlib/telegram-bot-api):
basta indicarne l'indirizzo in `webapp/config/config.php` con la chiave
`telegram_api_base` (vedi `config.example.php`).

Lo **scaricamento pianificato** richiede una voce cron che invochi
periodicamente `webapp/cli/run_scheduled_downloads.php` (es. ogni 6 ore); le
pianificazioni si creano e si gestiscono dall'interfaccia (sezione
scaricamento di uno studio e pagina **Pianificazioni**). Ogni controllo
verifica prima se la fonte ha qualcosa di nuovo (catalogo Copernicus,
metadati Esri) e scarica solo in quel caso: un controllo senza novità costa
una richiesta leggera e ha esito "niente di nuovo". Lo stesso cron esegue
anche la **manutenzione dello storage**: anteprime e confronti mai salvati
vengono rimossi dopo 48 ore. Un'esecuzione alla volta (lock), e le
pianificazioni rispettano la cadenza impostata anche se il giro precedente è
terminato qualche secondo dopo l'orario del cron.

Per le riprese Esri archiviate prima che la data reale venisse letta al
download (o se il servizio metadati non aveva risposto):

```bash
php webapp/cli/refresh_esri_metadata.php            # solo quelle senza metadati
php webapp/cli/refresh_esri_metadata.php --dry-run  # mostra cosa cambierebbe
php webapp/cli/refresh_esri_metadata.php --all      # tutte
```

Le etichette generate automaticamente vengono aggiornate con la data
dell'immagine; quelle scritte a mano non vengono toccate.

## Sicurezza

- Autenticazione a sessione singola, password con hashing nativo PHP (`password_hash`); blocco temporaneo dopo ripetuti tentativi falliti; tempi di risposta uguali per utenti esistenti e inesistenti
- Cookie di sessione dedicato, `HttpOnly`, `SameSite=Lax`, `Secure` quando il sito è servito in HTTPS; le azioni che modificano dati (incluso "segna tutti come letti" e il logout) sono solo POST
- File con segreti (`config.php`, `.env`, credenziali dinamiche) a permessi `640`; database e storage non leggibili da altri utenti della macchina (`fix_permissions.sh`), mai serviti via HTTP
- Le immagini arrivano al browser solo tramite `media.php`, limitato alle cartelle delle riprese e ai formati immagine: file di configurazione e credenziali non sono raggiungibili nemmeno da una sessione autenticata
- Protezione da path traversal su tutti gli endpoint che accettano percorsi file; testo inserito dall'analista (etichette, note, colori, titoli) sempre inserito come testo, mai come HTML
- `storage/` e le altre cartelle sensibili sono protette da `.htaccess` (`Require all denied`) indipendentemente da come è configurato il document root
- Il servizio Python richiede una chiave condivisa (`X-OrbitalEye-Key`, confrontata a tempo costante; il valore segnaposto dell'esempio viene rifiutato), non espone CORS ed è pensato per restare su `127.0.0.1`, mai esposto direttamente; parametri e dimensioni delle immagini sono limitati prima di arrivare a OpenCV
- Le API rispondono sempre in JSON, anche in caso di sessione scaduta (401) o errore interno, e l'interfaccia segnala ogni salvataggio non riuscito invece di perderlo in silenzio

## Struttura del progetto

Vedi [Architettura](#architettura) sopra per l'albero delle cartelle principali. I file `*.example.*` (config, `.env`) sono i template versionati: copiali e personalizzali, non modificare direttamente eventuali file generati a runtime.

## Librerie e servizi di terze parti

- [FastAPI](https://fastapi.tiangolo.com/) / [Uvicorn](https://www.uvicorn.org/) — servizio di analisi
- [OpenCV](https://opencv.org/) / [NumPy](https://numpy.org/) — elaborazione immagini
- [Leaflet](https://leafletjs.com/) — selettore mappa interattivo (incluso in `webapp/public/assets/leaflet/`)
- [Esri World Street Map](https://www.esri.com/) — tile di navigazione della mappa
- [Nominatim](https://nominatim.org/) / [OpenStreetMap](https://www.openstreetmap.org/) — ricerca luogo
- [Copernicus Data Space Ecosystem](https://dataspace.copernicus.eu/) — imagery Sentinel-2 e Sentinel-1
- [Esri World Imagery](https://www.esri.com/) e [World Imagery Wayback](https://livingatlas.arcgis.com/wayback/) — imagery satellitare ad alta risoluzione, attuale e storica
- [Ultralytics YOLO11](https://docs.ultralytics.com/) — modello di rilevamento orientato (YOLO11s-OBB), licenza AGPL-3.0; non incluso, si installa con `python-service/tools/fetch_detector_model.sh`
- [DOTA](https://captain-whu.github.io/DOTA/) — dataset di immagini aeree su cui è addestrato il modello: i pesi sono destinati a uso di ricerca, non commerciale
- [Wikipedia](https://en.wikipedia.org/) — dimensioni dei velivoli nella tabella `webapp/src/aircraft_types.json`, tratte dalle schede tecniche delle voci indicate per ciascun tipo (testi CC BY-SA 4.0)

## Licenza

Distribuito con licenza **GNU General Public License v3.0** — vedi [LICENSE](LICENSE).
