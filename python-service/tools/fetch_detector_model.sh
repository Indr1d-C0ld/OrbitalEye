#!/bin/bash
# Installa il modello di rilevamento automatico (python-service/models/
# yolo11s-obb.onnx) usato da app/core/detect.py.
#
# Il modello non è nel repository (37 MB, licenza propria): questo script lo
# scarica dalle release ufficiali Ultralytics (YOLO11s-OBB, addestrato sul
# dataset DOTA v1) e lo converte in ONNX, il formato che il servizio esegue
# con OpenCV senza dipendenze aggiuntive. La conversione richiede PyTorch e
# il pacchetto ultralytics: vengono installati in un ambiente TEMPORANEO
# (~1,5 GB su disco, ~300 MB scaricati) che lo script cancella alla fine.
#
# Licenze: modello AGPL-3.0 (https://ultralytics.com/license), compatibile
# con la GPLv3 di OrbitalEye; i pesi addestrati su DOTA sono per uso di
# ricerca, non commerciale (https://captain-whu.github.io/DOTA/).
#
# Uso: bash python-service/tools/fetch_detector_model.sh [cartella_temporanea]
# Poi riavvia il servizio di analisi (il modello si carica al primo uso).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="$HERE/models"
if [ -n "${1:-}" ]; then
    WORK="$1"
    # Cartella indicata da chi esegue: si tolgono solo i file creati qui.
    trap 'rm -rf "$WORK/venv" "$WORK/yolo11s-obb.pt" "$WORK/yolo11s-obb.onnx"' EXIT
else
    WORK="$(mktemp -d)"
    trap 'rm -rf "$WORK"' EXIT
fi
mkdir -p "$DEST" "$WORK"

echo "Ambiente temporaneo in $WORK ..."
python3 -m venv "$WORK/venv"
"$WORK/venv/bin/pip" install -q --upgrade pip
"$WORK/venv/bin/pip" install -q torch torchvision --index-url https://download.pytorch.org/whl/cpu
"$WORK/venv/bin/pip" install -q ultralytics onnx onnxslim

cd "$WORK"
"$WORK/venv/bin/python" - <<'PY'
from ultralytics import YOLO
YOLO("yolo11s-obb.pt").export(format="onnx", imgsz=1024, opset=17, simplify=True, dynamic=False)
PY
mv "$WORK/yolo11s-obb.onnx" "$DEST/yolo11s-obb.onnx"
rm -f "$WORK/yolo11s-obb.pt"
echo "Modello installato: $DEST/yolo11s-obb.onnx"
sha256sum "$DEST/yolo11s-obb.onnx"
