import secrets

from fastapi import Header, HTTPException, status

from .config import settings

# Valore presente in .env.example: se resta questo, il servizio non è stato
# configurato e accettare richieste equivarrebbe a non avere autenticazione
# (chiunque raggiunga la porta conosce il valore, è pubblicato nel repo).
PLACEHOLDER_KEY = "change-me-to-a-long-random-string"


def require_service_key(x_orbitaleye_key: str = Header(default="")):
    key = settings.service_api_key
    if not key or key == PLACEHOLDER_KEY:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail=(
                "Servizio non configurato: imposta SERVICE_API_KEY nel file .env "
                "con un valore casuale (es. openssl rand -hex 32) e riavvia."
            ),
        )
    # Confronto a tempo costante: con un != normale il tempo di risposta
    # dipende da quanti caratteri iniziali coincidono.
    # Confronto fra byte: compare_digest su stringhe solleva TypeError se una
    # contiene caratteri non ASCII (500 invece di 401).
    if not secrets.compare_digest(x_orbitaleye_key.encode("utf-8"), key.encode("utf-8")):
        raise HTTPException(status_code=status.HTTP_401_UNAUTHORIZED, detail="Chiave di servizio non valida")
