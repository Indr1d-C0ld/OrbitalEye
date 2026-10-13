#!/bin/bash
# Installa il modello della libreria visiva (python-service/models/
# dinov2-small.onnx) usato da app/core/embed.py: DINOv2 ViT-S/14 di Meta
# (licenza Apache-2.0), nella conversione ONNX pubblicata su Hugging Face
# (Xenova/dinov2-small, onnx/model.onnx, 88,5 MB).
#
# Il modello trasforma il ritaglio di un velivolo in un vettore di 384
# numeri: velivoli simili danno vettori vicini. Gira in locale, nessuna
# immagine lascia la piattaforma. Non è nel repository.
#
# Uso: bash python-service/tools/fetch_embedding_model.sh
# Poi riavvia il servizio di analisi (il modello si carica al primo uso).
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="$HERE/models"
# Revisione fissata e impronta verificata: un file cambiato a monte non
# viene installato in silenzio.
REVISION="c2bb04a51fab207c420665f1946016107bffc701"
SHA256="83141175ec78b4ff9a2bb58a4c7c264ba0054d1c2e122e5a8114b79a8d4179ea"
URL="https://huggingface.co/Xenova/dinov2-small/resolve/$REVISION/onnx/model.onnx"
mkdir -p "$DEST"
curl -fsSL --retry 3 -C - -o "$DEST/dinov2-small.onnx.part" "$URL"
echo "$SHA256  $DEST/dinov2-small.onnx.part" | sha256sum -c - >/dev/null || { echo "Impronta SHA-256 diversa da quella attesa: file non installato."; rm -f "$DEST/dinov2-small.onnx.part"; exit 1; }
mv "$DEST/dinov2-small.onnx.part" "$DEST/dinov2-small.onnx"
echo "Modello installato: $DEST/dinov2-small.onnx (SHA-256 verificato)"
