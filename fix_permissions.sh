#!/bin/bash
# =============================================================================
# Script di configurazione permessi per OrbitalEye
#
# Da eseguire come l'utente che possiede il codice e fa girare il servizio
# Python (di norma il tuo utente normale, NON serve sudo: i file sono già
# di sua proprietà):
#
#   bash fix_permissions.sh [utente] [gruppo-webserver]
#
# Se omessi, [utente] è rilevato automaticamente (whoami) e [gruppo-webserver]
# è "www-data" (default su Debian/Ubuntu — su altre distro potrebbe essere
# "apache" o "http": passalo come secondo argomento in quel caso).
# =============================================================================
set -e

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
USER="${1:-$(whoami)}"
GROUP="${2:-www-data}"

VENV_DIR="$PROJECT_DIR/python-service/venv"
STORAGE_DIR="$PROJECT_DIR/storage"
WEBAPP_DATA_DIR="$PROJECT_DIR/webapp/data"
WEBAPP_CONFIG="$PROJECT_DIR/webapp/config/config.php"
PY_ENV_FILE="$PROJECT_DIR/python-service/.env"

echo "=== Configurazione permessi per $PROJECT_DIR ==="

# 0. Se il database SQLite non esiste ancora, crealo vuoto come $USER
#    (verrà popolato dallo schema al primo accesso PHP): così resta di
#    proprietà di $USER anche dopo che il webserver ci scrive dentro, invece
#    di diventare di proprietà di www-data come accade se è Apache a
#    crearlo per primo (caso che il punto 1 tollera comunque, ma è meglio
#    evitarlo per le installazioni pulite).
mkdir -p "$WEBAPP_DATA_DIR"
[ -f "$WEBAPP_DATA_DIR/orbitaleye.sqlite" ] || touch "$WEBAPP_DATA_DIR/orbitaleye.sqlite"

# 1. Proprietario e gruppo di base per tutto il progetto.
#    NOTA: alcuni file (webapp/data/*.sqlite, storage/raw/*, storage/config/*)
#    a webapp avviata sono già di proprietà di www-data (creati a runtime da
#    Apache/PHP: database, upload, credenziali salvate da Impostazioni).
#    Cambiarne il proprietario richiederebbe root e non serve: grazie al
#    gruppo condiviso e al bit setgid sulle directory (punto 4) restano
#    comunque leggibili/scrivibili da entrambi i servizi. `|| true` evita che
#    questi errori attesi interrompano lo script prima dei passi successivi.
chown -R "$USER:$GROUP" "$PROJECT_DIR" || true

# 2. Permessi generali: directory 755, file 644 (stessa tolleranza del punto 1)
find "$PROJECT_DIR" -type d -exec chmod 755 {} \; || true
find "$PROJECT_DIR" -type f -exec chmod 644 {} \; || true

# 3. Script eseguibili
chmod +x "$PROJECT_DIR/python-service/run.sh"
chmod +x "$PROJECT_DIR/fix_permissions.sh"

# 4. Directory scrivibili sia da Apache/PHP (www-data) sia dal servizio Python
#    (che scarica riprese Sentinel Hub e scrive gli output di analisi): bit
#    setgid così i nuovi file create da chiunque dei due ereditano il gruppo
#    www-data, ed entrambi possono leggerli/scriverli.
for d in "$STORAGE_DIR/raw" "$STORAGE_DIR/processed" "$STORAGE_DIR/results" "$STORAGE_DIR/config" "$WEBAPP_DATA_DIR"; do
    mkdir -p "$d"
    chmod 2775 "$d"
done
find "$STORAGE_DIR" -type f -exec chmod 664 {} \; 2>/dev/null || true
for f in "$WEBAPP_DATA_DIR"/*.sqlite; do
    [ -f "$f" ] && chmod 664 "$f" || true
done

# 5. File con segreti (chiave condivisa PHP<->Python, credenziali): niente
#    accesso per "altri", solo owner ($USER) e gruppo ($GROUP, che deve
#    poter leggere config.php per servire la webapp).
[ -f "$WEBAPP_CONFIG" ] && { chmod 640 "$WEBAPP_CONFIG" || true; }
[ -f "$PY_ENV_FILE" ] && { chmod 640 "$PY_ENV_FILE" || true; }

# 6. Virtual environment Python: usato solo dal demone uvicorn (eseguito come
#    $USER), non deve essere toccato né letto dal webserver.
if [ -d "$VENV_DIR" ]; then
    chown -R "$USER:$USER" "$VENV_DIR"
    chmod -R 700 "$VENV_DIR"
    echo "Permessi del virtual environment Python impostati (esclusivi di $USER)."
else
    echo "Nota: $VENV_DIR non trovato — verrà creato al primo avvio di python-service/run.sh"
fi

echo "=== Permessi configurati con successo ==="
echo "Se il servizio orbitaleye-analysis è già attivo: sudo systemctl restart orbitaleye-analysis"
