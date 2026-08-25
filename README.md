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
- Fetch automatico da **Copernicus/Sentinel-2** (storico, ~10m/pixel) e **Esri World Imagery** (risoluzione più alta, ultima disponibile) tramite le rispettive API ufficiali
- Selettore d'area interattivo su mappa (Leaflet + OpenStreetMap), con basemap satellitare opzionale per riconoscere l'area prima di scaricarla

**Analisi e change detection**
- Allineamento automatico (feature matching ORB/RANSAC + rifinitura sub-pixel ECC)
- Confronto per differenza SSIM (robusta a luce/contrasto) o differenza assoluta
- Soglia di sensibilità manuale o automatica (Otsu), con preset rapidi (bassa/media/alta)
- Pulizia morfologica e filtro per area minima, per scartare rumore fotografico e falsi positivi
- Filtri di enhancement pre-analisi: bilanciamento del bianco, riduzione rumore (4 metodi), CLAHE, equalizzazione istogramma, correzione gamma, sharpening
- Viste multiple: overlay differenze, heatmap, contorni (edge detection), maschera binaria, slider prima/dopo
- Regioni di cambiamento rilevate automaticamente, numerate, cliccabili (zoom automatico sulla regione) e convertibili in annotazioni con un click
- Zoom e pan avanzati sulle immagini (rotellina/pulsanti su desktop, pinch-to-zoom e trascinamento su touch)

**Organizzazione e output**
- Annotazioni manuali persistenti, disegnabili direttamente sulle immagini
- Libreria degli studi salvati, con ricerca
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
2. **Enhancement opzionale** — applicato a entrambe le riprese in modo coerente prima del confronto.
3. **Differenza** — SSIM (si concentra sui cambi strutturali reali) oppure differenza assoluta (più veloce, più sensibile a variazioni di luce/colore non legate a cambiamenti reali).
4. **Soglia** — manuale o Otsu automatica.
5. **Pulizia** — apertura/chiusura morfologica + filtro per area minima del blob, per scartare rumore fotografico e micro-disallineamenti residui.
6. **Report grafico** — overlay in falso colore con bounding box numerate e cliccabili, heatmap, contorni, maschera binaria, slider prima/dopo.

### Annotazioni e regioni

Le annotazioni sono rettangoli disegnabili a mano su qualunque vista (overlay/heatmap/contorni/maschera), con etichetta e note libere. Le regioni rilevate automaticamente possono diventare annotazioni con un click ("✎ Annota"), preservando le coordinate esatte del rilevamento.

### Export

- **Singola immagine**: download diretto con nome file descrittivo.
- **Confronto**: ZIP con le due riprese originali, le immagini di risultato, un report HTML autonomo (apribile offline, con tutti i parametri/statistiche/regioni/annotazioni) e gli stessi dati in JSON.
- **Libreria**: export massivo (tutta la libreria, il risultato di una ricerca, o una selezione) in un unico ZIP con una sottocartella per confronto.

## Configurazione avanzata

Dalla pagina **Impostazioni** puoi modificare in qualunque momento, senza toccare file:
- Credenziali Sentinel Hub e Esri (sincronizzate automaticamente col servizio Python)
- Parametri di analisi predefiniti (metodo diff, soglia, area minima blob, kernel morfologico, opacità overlay)
- Password dell'account

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
