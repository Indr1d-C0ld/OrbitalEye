from fastapi import Header, HTTPException, status

from .config import settings


def require_service_key(x_orbitaleye_key: str = Header(default="")):
    if not settings.service_api_key or x_orbitaleye_key != settings.service_api_key:
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Chiave di servizio non valida")
