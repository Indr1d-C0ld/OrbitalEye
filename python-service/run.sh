#!/usr/bin/env bash
# Avvia il servizio di analisi OrbitalEye (FastAPI/uvicorn).
# Uso: ./run.sh   (crea il venv al primo avvio se non esiste)
set -e
cd "$(dirname "$0")"

if [ ! -d venv ]; then
    python3 -m venv venv
    ./venv/bin/pip install --upgrade pip
    ./venv/bin/pip install -r requirements.txt
fi

if [ ! -f .env ]; then
    echo "ATTENZIONE: .env non trovato, copio da .env.example (da configurare)."
    cp .env.example .env
fi

exec ./venv/bin/uvicorn app.main:app --host "${HOST:-127.0.0.1}" --port "${PORT:-8077}"
