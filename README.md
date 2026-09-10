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
- Fetch automatico da **Copernicus/Sentinel-2** (storico, ~10m/pixel, con coppia Rosso+NIR scaricata a parte per gli indici spettrali) e **Esri World Imagery** (risoluzione più alta, ultima disponibile; retry automatico a risoluzione ridotta contro il limite di complessità del servizio) tramite le rispettive API ufficiali
- Selettore d'area interattivo su mappa (Leaflet + OpenStreetMap), con basemap satellitare opzionale, e possibilità di **ruotare l'area** del ritaglio prima dello scaricamento
- **Scaricamento pianificato**: controllo periodico di un'area/fonte, scarto automatico dei duplicati (soglia configurabile) e **alert** all'arrivo di una ripresa diversa; pagina di gestione centralizzata di tutte le pianificazioni con badge d'errore nel menu

**Analisi e change detection**
- Allineamento automatico (feature matching ORB/RANSAC + rifinitura sub-pixel ECC) o **manuale** tramite editor di punti di controllo
- Confronto per differenza SSIM (robusta a luce/contrasto) o differenza assoluta
- Soglia di sensibilità manuale o automatica (Otsu), con preset rapidi (bassa/media/alta)
- Pulizia morfologica e filtro per area minima, per scartare rumore fotografico e falsi positivi
- Filtri di enhancement pre-analisi con **parametri regolabili**: bilanciamento del bianco, riduzione rumore (4 metodi + intensità), CLAHE (clip limit + tile), equalizzazione istogramma, correzione gamma, sharpening, desaturazione, contorni
- Viste multiple: overlay differenze, heatmap, contorni (edge detection), maschera binaria, slider prima/dopo
- Statistiche: superficie variata (%), numero di regioni e — quando la scala reale è nota — **aree in m²/km²** (totale variato, regione più estesa, area di ogni regione)
- Regioni di cambiamento rilevate automaticamente, numerate, cliccabili (zoom automatico sulla regione) e convertibili in annotazioni con un click
- **Indici spettrali** per le riprese Sentinel Hub con banda NIR: NDVI (vegetazione), NDWI (acqua), falso colore infrarosso

**Vista di analisi ripresa singola**
- Zoom/pan sincronizzato tra originale e copia di lavoro (rotellina/pulsanti, pinch-to-zoom e trascinamento su touch)
- Regolazioni in tempo reale (luminosità, contrasto, saturazione, nitidezza, gamma) nel browser via filtri CSS/SVG; filtri avanzati elaborati dal servizio Python con gli stessi parametri granulari
- **Sovrapposizione immagine** con manipolazione diretta sull'immagine: sposta (corpo), ridimensiona (angoli), inclina/skew (metà lato), ruota (maniglia in alto), opacità (guida in basso), tutte sincronizzate con gli slider; **chroma key** (trasparenza selettiva per colore, con sfumatura ai bordi)
- **Misurazioni** distanza reale sul terreno (metri/pixel per asse, consapevoli della rotazione dell'area), con etichette, persistenti; calibrazione manuale se manca la scala geografica
- **Stima altezza da ombra** (elevazione solare + lunghezza dell'ombra)
- **Annotazioni vettoriali**: rettangolo, polilinea, poligono (vertici trascinabili), con colore ed etichetta, persistenti
- **Barra di scala** sovrapposta "come su una cartina": adattiva allo zoom nella vista live, fissa nell'export
- **Ritaglio** di un frammento → ricerca inversa (Google Lens) e/o analisi con assistenti AI (Claude, ChatGPT, DeepSeek) per incolla manuale; il frammento si può salvare come nuova ripresa o condividere
- **Livello annotazioni/misurazioni/scala incorporabile a scelta** nelle immagini salvate/condivise
- Salva come nuova ripresa (cuoce le regolazioni nei pixel), con **ereditarietà della scala reale** dalla sorgente (misurazioni corrette anche su ritagli e riprese migliorate)
- Mini-anteprima flottante durante lo scroll (spostabile, ridimensionabile, ricordata); pannelli collassabili in tutta la piattaforma

**Organizzazione e output**
- Annotazioni e misurazioni persistenti, disegnabili direttamente sulle immagini
- Libreria degli studi salvati, con ricerca
- **Condivisione** manuale di ripresa/confronto/riepilogo studio su Telegram (bot) o X/Twitter (finestra di composizione), con registro di controllo — nessuna pubblicazione automatica, mai agganciata al motore di scaricamento
- **Export georeferenziato KML/GeoJSON** di annotazioni e misurazioni (per Google Earth / QGIS)
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
│       ├── core/          registrazione, diff, enhancement, client Sentinel Hub/Esri
│       └── routers/        /fetch/*, /analysis/compare, /analysis/enhance
├── webapp/                 Frontend PHP (autenticazione, libreria, annotazioni, DB SQLite)
│   ├── public/               document root del webserver
│   ├── src/                   classi applicative
│   └── config/                 configurazione (config.php, non versionato)
└── storage/                 Filesystem condiviso tra i due servizi
    ├── raw/                    riprese originali (upload o fetch)
    ├── processed/               output di enhancement standalone
    ├── results/                  output dei confronti (overlay, heatmap, contorni, maschera)
    └── config/                    credenziali dinamiche (Sentinel Hub/Esri), non versionate
```

Il webapp PHP e il servizio Python girano sulla stessa macchina e comunicano in due modi: il PHP invoca l'API REST del servizio Python (in locale, `127.0.0.1`) passandogli solo **percorsi relativi** alle immagini — non ne carica/scarica mai i byte via HTTP — mentre entrambi leggono/scrivono sullo stesso filesystem condiviso (`storage/`). Questo significa che il servizio Python non deve mai essere esposto pubblicamente: basta che sia raggiungibile dal webapp sulla stessa macchina.

## Fonti immagini e note legali

Le tile satellitari di **Google Maps/Earth non sono scaricabili in blocco** per analisi offline: i relativi Termini di Servizio vietano l'estrazione e l'archiviazione massiva delle tile al di fuori del visualizzatore ufficiale (lo stesso vale, in generale, per lo scraping di tile XYZ grezze da qualunque provider di basemap tramite strumenti di terze parti pensati per aggirare questi limiti). Per questo il fetch automatico di OrbitalEye usa esclusivamente fonti che espongono un **punto di integrazione ufficiale** pensato per richieste programmatiche:

- **[Copernicus Data Space Ecosystem](https://dataspace.copernicus.eu)** (Sentinel-2, ESA/UE) — gratuito, ~10m/pixel, rivisitazione ~5 giorni, intervallo di date storico selezionabile. Richiede un client OAuth gratuito.
- **[Esri World Imagery](https://developers.arcgis.com)** — tramite l'operazione REST ufficiale `/export` del MapServer pubblico, risoluzione spesso più alta (sub-metrica in molte aree, varia per zona) ma solo il composito "più recente disponibile". Funziona anche senza API key per uso leggero; per un uso sostenuto è consigliato un account ArcGIS Developer gratuito.

Per immagini a risoluzione ancora più alta puoi sempre usare il **caricamento manuale**, con qualunque fonte tu sia legalmente autorizzato a usare offline (dataset pubblici come USGS/NAIP per gli USA, riprese aeree proprie, immagini acquistate da provider commerciali con licenza per uso offline, ecc.).

Il selettore mappa integrato usa tile **OpenStreetMap** per la navigazione (uso conforme alla relativa policy: solo visualizzazione interattiva in-browser, nessun download bulk) e, opzionalmente, tile **Esri World Imagery** come basemap satellitare per il solo riconoscimento visivo dell'area — anche qui senza alcun download/archiviazione delle tile stesse.

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

Non serve sudo: imposta proprietario/gruppo su tutto il progetto (auto-rileva il tuo utente; passa `bash fix_permissions.sh utente gruppo` per specificarli esplicitamente), rende scrivibili da webserver e servizio Python le cartelle condivise (`storage/*`, `webapp/data/`), restringe i file con segreti e isola il virtualenv Python.

### 2. Servizio Python (motore di analisi)

```bash
cd python-service
./run.sh   # primo avvio: crea il venv, installa le dipendenze, copia .env.example in .env
```

Ferma il processo (Ctrl+C) e apri `.env`: imposta almeno

- `SERVICE_API_KEY`: una stringa lunga e casuale (es. `openssl rand -hex 32`)
- `STORAGE_ROOT`: percorso assoluto della cartella `storage/` del progetto — deve combaciare con `storage_root` in `webapp/config/config.php`

Le credenziali Sentinel Hub/Esri possono restare vuote qui: si impostano più comodamente dalla pagina **Impostazioni** del webapp una volta online.

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
3. Nella pagina dello studio: carica manualmente due riprese, oppure scaricale da Sentinel Hub/Esri per due periodi diversi, selezionale come **A (prima)** e **B (dopo)**, regola soglia/filtri (o usa un preset di sensibilità) ed esegui il confronto.
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

### Annotazioni, misurazioni e regioni

Le annotazioni sono forme vettoriali disegnabili a mano — **rettangolo, polilinea, poligono** (vertici trascinabili) — su qualunque vista, con colore, etichetta e note. Sono persistenti e legate alla ripresa/confronto. Le regioni rilevate automaticamente diventano annotazioni con un click ("✎ Annota"), preservando le coordinate esatte del rilevamento.

Nella vista di analisi ripresa singola sono disponibili anche **misurazioni** di distanza reale sul terreno (persistenti, con etichette), la **stima di altezza da ombra** e una **barra di scala** sovrapponibile. Annotazioni, misurazioni e scala possono essere **incorporate a scelta** nelle immagini salvate o condivise.

### Condivisione ed export

- **Condivisione** (Telegram / X) di una ripresa, di un confronto o del riepilogo di uno studio: gesto sempre manuale e previewabile, con didascalia modificabile e registro di controllo. Nessun contenuto viene mai pubblicato automaticamente.
- **Export georeferenziato KML / GeoJSON** di annotazioni e misurazioni, pronto per Google Earth / QGIS.
- **Singola immagine**: download diretto con nome file descrittivo.
- **Confronto**: ZIP con le due riprese originali, le immagini di risultato, un report HTML autonomo (apribile offline, con tutti i parametri/statistiche/regioni/annotazioni) e gli stessi dati in JSON.
- **Libreria**: export massivo (tutta la libreria, il risultato di una ricerca, o una selezione) in un unico ZIP con una sottocartella per confronto.

## Configurazione avanzata

Dalla pagina **Impostazioni** puoi modificare in qualunque momento, senza toccare file:
- Credenziali Sentinel Hub e token Esri (sincronizzate automaticamente col servizio Python)
- Bot Telegram per la condivisione (token + chat id; restano nel DB, mai in un file versionato)
- Parametri di analisi predefiniti (metodo diff, soglia, area minima blob, kernel morfologico, opacità overlay)
- Password dell'account

Lo **scaricamento pianificato** richiede una voce cron che invochi
periodicamente `webapp/cli/run_scheduled_downloads.php` (es. ogni 6 ore); le
pianificazioni si creano e si gestiscono dall'interfaccia (sezione
scaricamento di uno studio e pagina **Pianificazioni**).

## Sicurezza

- Autenticazione a sessione singola, password con hashing nativo PHP (`password_hash`)
- File con segreti (`config.php`, `.env`, credenziali dinamiche) impostati a permessi `640`, mai serviti via HTTP
- Protezione da path traversal su tutti gli endpoint che accettano percorsi file
- `storage/` e le altre cartelle sensibili sono protette da `.htaccess` (`Require all denied`) indipendentemente da come è configurato il document root
- Il servizio Python richiede una chiave condivisa (`X-OrbitalEye-Key`) su ogni richiesta ed è pensato per restare su `127.0.0.1`, mai esposto direttamente

## Struttura del progetto

Vedi [Architettura](#architettura) sopra per l'albero delle cartelle principali. I file `*.example.*` (config, `.env`) sono i template versionati: copiali e personalizzali, non modificare direttamente eventuali file generati a runtime.

## Librerie e servizi di terze parti

- [FastAPI](https://fastapi.tiangolo.com/) / [Uvicorn](https://www.uvicorn.org/) — servizio di analisi
- [OpenCV](https://opencv.org/) / [NumPy](https://numpy.org/) — elaborazione immagini
- [Leaflet](https://leafletjs.com/) — selettore mappa interattivo
- [OpenStreetMap](https://www.openstreetmap.org/) — tile di navigazione della mappa
- [Copernicus Data Space Ecosystem](https://dataspace.copernicus.eu/) — imagery Sentinel-2
- [Esri World Imagery](https://www.esri.com/) — imagery satellitare ad alta risoluzione

## Licenza

Distribuito con licenza **GNU General Public License v3.0** — vedi [LICENSE](LICENSE).
