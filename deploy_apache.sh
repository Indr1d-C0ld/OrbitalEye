#!/bin/bash
# =============================================================================
# Integra OrbitalEye in un vhost Apache condiviso già esistente
# (DocumentRoot già impostato su una cartella che ospita altri siti/app),
# tramite un Alias — utile se non vuoi/puoi dedicare un vhost o un
# (sotto)dominio solo a OrbitalEye.
#
# Aggiunge, nei file elencati in FILES sotto (di norma sia il vhost HTTP che
# quello HTTPS), un blocco Alias che punta a webapp/public di questo
# progetto, così la webapp è raggiungibile su http://<host>/<URL_PATH>/
# senza esporre codice sorgente, config o storage (che restano fuori da
# qualunque percorso raggiungibile via URL una volta che l'Alias è attivo).
#
# Idempotente: se il blocco è già presente lo salta. Fa un backup di ogni
# file prima di modificarlo e verifica la sintassi prima di ricaricare Apache
# (se la verifica fallisce, ripristina automaticamente il backup).
#
# ADATTA ALLA TUA INSTALLAZIONE prima di eseguirlo: la lista FILES sotto
# assume un vhost Debian/Ubuntu standard (000-default.conf) — se il tuo
# vhost si chiama diversamente, modifica l'elenco.
#
# Da eseguire con sudo:  sudo bash deploy_apache.sh
# =============================================================================
set -e

if [[ $EUID -ne 0 ]]; then
    echo "Questo script deve essere eseguito con sudo (modifica file in /etc/apache2)."
    echo "  sudo bash $0"
    exit 1
fi

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
URL_PATH="/orbitaleye"   # percorso su cui sarà raggiungibile la webapp

FILES=(
    /etc/apache2/sites-available/000-default.conf
    /etc/apache2/sites-available/000-default-le-ssl.conf
)

MARKER_BEGIN="    # --- OrbitalEye BEGIN (gestito da deploy_apache.sh, non modificare a mano) ---"
MARKER_END="    # --- OrbitalEye END ---"

IFS= read -r -d '' SNIPPET <<EOF || true
$MARKER_BEGIN
    # Alias per OrbitalEye — piattaforma di analisi immagini satellitari
    Alias $URL_PATH $PROJECT_DIR/webapp/public
    <Directory $PROJECT_DIR/webapp/public>
        Options -Indexes
        AllowOverride All
        Require all granted
    </Directory>
$MARKER_END
EOF

CHANGED=0

for f in "${FILES[@]}"; do
    if [ ! -f "$f" ]; then
        echo "Salto $f (non trovato)."
        continue
    fi
    if grep -q "OrbitalEye BEGIN" "$f"; then
        echo "Salto $f: blocco OrbitalEye già presente."
        continue
    fi

    backup="$f.bak.$(date +%Y%m%d%H%M%S)"
    cp "$f" "$backup"
    echo "Backup creato: $backup"

    # Inserisce il blocco appena prima della prima riga ErrorLog del file
    awk -v snippet="$SNIPPET" '
        /ErrorLog/ && !done { print snippet; done=1 }
        { print }
    ' "$f" > "$f.tmp"
    mv "$f.tmp" "$f"
    echo "Aggiunto blocco OrbitalEye a $f"
    CHANGED=1
done

if [ "$CHANGED" -eq 0 ]; then
    echo "Nessuna modifica necessaria (già tutto configurato)."
    exit 0
fi

echo
echo "Verifica sintassi Apache..."
if ! apache2ctl configtest; then
    echo "ERRORE: configurazione non valida. Ripristino i backup..."
    for f in "${FILES[@]}"; do
        latest_backup=$(ls -t "$f".bak.* 2>/dev/null | head -1 || true)
        if [ -n "$latest_backup" ]; then
            cp "$latest_backup" "$f"
            echo "Ripristinato $f da $latest_backup"
        fi
    done
    exit 1
fi

echo "Ricarico Apache..."
systemctl reload apache2

echo
echo "=== Fatto ==="
echo "Prova: curl -I http://127.0.0.1${URL_PATH}/login.php"
