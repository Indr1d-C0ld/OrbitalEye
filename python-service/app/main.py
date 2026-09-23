import os

# Prima di qualunque import di OpenCV: tetto alla decodifica delle immagini
# (il limite predefinito è ~1 gigapixel). Il limite effettivo per
# l'elaborazione è più basso ed è in core/utils.py con un messaggio chiaro;
# questo è solo la rete di sicurezza a livello di decoder.
os.environ.setdefault("OPENCV_IO_MAX_IMAGE_PIXELS", str(40_000_000))

import cv2  # noqa: E402
from fastapi import FastAPI, Request  # noqa: E402
from fastapi.responses import JSONResponse  # noqa: E402

from .routers import analysis, fetch  # noqa: E402

app = FastAPI(
    title="OrbitalEye Analysis Service",
    description="Motore di elaborazione immagini satellitari per OrbitalEye (allineamento, "
    "change detection, filtri di enhancement).",
    version="1.0.0",
)

# Nessun middleware CORS: l'unico client di questo servizio è il webapp PHP,
# che lo chiama server-side via curl — dove CORS non entra in gioco. Il
# browser non contatta mai direttamente questa porta. Un
# allow_origins=["*"] era quindi superficie d'attacco senza alcun beneficio.

# Immagini illeggibili/troppo grandi, trasformazioni impossibili e simili
# sollevano ValueError o cv2.error nel mezzo dell'elaborazione: senza questi
# gestori diventavano 500 senza messaggio, e il webapp non poteva dire
# all'analista cosa non andava.
@app.exception_handler(ValueError)
async def _value_error(_request: Request, exc: ValueError):
    return JSONResponse(status_code=400, content={"detail": str(exc) or "Richiesta non valida"})


@app.exception_handler(cv2.error)
async def _cv_error(_request: Request, exc: cv2.error):
    return JSONResponse(
        status_code=422,
        content={"detail": "Elaborazione dell'immagine non riuscita con questi dati/parametri."},
    )


app.include_router(fetch.router)
app.include_router(analysis.router)


@app.get("/health")
def health():
    return {"status": "ok", "service": "orbitaleye-analysis"}
