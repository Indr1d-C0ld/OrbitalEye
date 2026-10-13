#!/bin/bash
# Installa un modello di rilevamento automatico (python-service/models/
# yolo11<variante>-obb.onnx) usato da app/core/detect.py: "s" (veloce, 37 MB,
# quello di serie) o "m" (più accurato e circa tre volte più lento, ~80 MB).
#
# Il modello non è nel repository (licenza propria): questo script lo
# scarica dalle release ufficiali Ultralytics (YOLO11-OBB, addestrato sul
# dataset DOTA v1) e lo converte in ONNX, il formato che il servizio esegue
# con OpenCV senza dipendenze aggiuntive. La conversione richiede PyTorch e
# il pacchetto ultralytics: vengono installati in un ambiente TEMPORANEO
# (~1,5 GB su disco, ~300 MB scaricati) che lo script cancella alla fine.
#
# Licenze: modello AGPL-3.0 (https://ultralytics.com/license), compatibile
# con la GPLv3 di OrbitalEye; i pesi addestrati su DOTA sono per uso di
# ricerca, non commerciale (https://captain-whu.github.io/DOTA/).
#
# Uso: [VARIANT=m] [TORCH_WHEEL=file.whl] bash python-service/tools/fetch_detector_model.sh [cartella_temporanea]
# Poi riavvia il servizio di analisi (il modello si carica al primo uso).
set -euo pipefail

VARIANT="${VARIANT:-s}"
case "$VARIANT" in s|m) ;; *) echo "VARIANT dev'essere s o m"; exit 1 ;; esac
NAME="yolo11${VARIANT}-obb"

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="$HERE/models"
if [ -n "${1:-}" ]; then
    WORK="$1"
    # Cartella indicata da chi esegue: si tolgono solo i file creati qui.
    trap 'rm -rf "$WORK/venv" "$WORK/$NAME.pt" "$WORK/$NAME.onnx"' EXIT
else
    WORK="$(mktemp -d)"
    trap 'rm -rf "$WORK"' EXIT
fi
mkdir -p "$DEST" "$WORK"

echo "Ambiente temporaneo in $WORK ..."
python3 -m venv "$WORK/venv"
"$WORK/venv/bin/pip" install -q --upgrade pip
# TORCH_WHEEL: un file .whl di PyTorch già scaricato (con connessioni che si
# interrompono a metà, curl -C - riprende dove pip non ci riesce).
if [ -n "${TORCH_WHEEL:-}" ]; then
    "$WORK/venv/bin/pip" install -q "$TORCH_WHEEL"
fi
"$WORK/venv/bin/pip" install -q torch torchvision --index-url https://download.pytorch.org/whl/cpu
"$WORK/venv/bin/pip" install -q ultralytics onnx onnxslim

cd "$WORK"
NAME="$NAME" "$WORK/venv/bin/python" - <<'PY'
import os
from ultralytics import YOLO
YOLO(os.environ["NAME"] + ".pt").export(format="onnx", imgsz=1024, opset=17, simplify=True, dynamic=False)
PY
mv "$WORK/$NAME.onnx" "$DEST/$NAME.onnx"
rm -f "$WORK/$NAME.pt"
echo "Modello installato: $DEST/$NAME.onnx"
sha256sum "$DEST/$NAME.onnx"
