from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from .routers import analysis, fetch

app = FastAPI(
    title="OrbitalEye Analysis Service",
    description="Motore di elaborazione immagini satellitari per OrbitalEye (allineamento, "
    "change detection, filtri di enhancement).",
    version="1.0.0",
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)

app.include_router(fetch.router)
app.include_router(analysis.router)


@app.get("/health")
def health():
    return {"status": "ok", "service": "orbitaleye-analysis"}
