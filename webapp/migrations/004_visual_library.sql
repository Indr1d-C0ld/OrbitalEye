-- Libreria visiva (vedi src/VisualLibrary.php e
-- python-service/app/core/embed.py): i vettori DINOv2 degli aerei e degli
-- elicotteri rilevati, per oggetto (numero → vettori in base64, uno per
-- orientamento), e quello dell'oggetto di ogni verifica con il tipo
-- confermato, che diventa un riferimento della libreria.
ALTER TABLE detections ADD COLUMN embeddings_json TEXT;
ALTER TABLE detection_reviews ADD COLUMN embedding_json TEXT;
