"""Ambiente dei test: storage temporaneo (la configurazione del servizio crea
le sue cartelle all'importazione) e nessuna credenziale reale."""
import os
import tempfile

STORAGE = tempfile.mkdtemp(prefix="orbitaleye-pytest-")
os.environ["STORAGE_ROOT"] = STORAGE
os.environ["SENTINELHUB_CLIENT_ID"] = ""
os.environ["SENTINELHUB_CLIENT_SECRET"] = ""
