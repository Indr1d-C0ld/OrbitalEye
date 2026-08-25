from pathlib import Path

from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(env_file=".env", env_file_encoding="utf-8", extra="ignore")

    storage_root: Path = Path("/var/www/html/orbitaleye/storage")

    sentinelhub_client_id: str = ""
    sentinelhub_client_secret: str = ""
    sentinelhub_token_url: str = (
        "https://identity.dataspace.copernicus.eu/auth/realms/CDSE/protocol/openid-connect/token"
    )
    sentinelhub_process_url: str = "https://sh.dataspace.copernicus.eu/api/v1/process"

    # Opzionale: token ArcGIS Developer per un uso sostenuto di Esri World
    # Imagery. Il servizio pubblico funziona anche senza per uso leggero.
    esri_api_key: str = ""

    host: str = "127.0.0.1"
    port: int = 8077

    service_api_key: str = "change-me-to-a-long-random-string"

    @property
    def raw_dir(self) -> Path:
        return self.storage_root / "raw"

    @property
    def processed_dir(self) -> Path:
        return self.storage_root / "processed"

    @property
    def results_dir(self) -> Path:
        return self.storage_root / "results"


settings = Settings()

for d in (settings.raw_dir, settings.processed_dir, settings.results_dir):
    d.mkdir(parents=True, exist_ok=True)
