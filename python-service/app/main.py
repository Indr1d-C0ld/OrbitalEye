from fastapi import FastAPI

from .routers import analysis, fetch

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

app.include_router(fetch.router)
app.include_router(analysis.router)


@app.get("/health")
def health():
    return {"status": "ok", "service": "orbitaleye-analysis"}
