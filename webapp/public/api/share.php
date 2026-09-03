<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(['error' => 'Metodo non consentito'], 405);
}

// multipart/form-data (non JSON): per una "ripresa" l'immagine da
// condividere è la copia di lavoro con le regolazioni correnti già
// applicate lato browser (stesso meccanismo di "Salva come nuova
// ripresa", mai persistita finché non lo decide l'analista) — deve quindi
// arrivare come file caricato dal client, non risolta da un percorso sul
// server. Per un confronto/riepilogo di studio il file esiste già sul
// server (risultato salvato dall'analisi): lì "image" resta assente e si
// risolve tramite ref_id.
$platform = $_POST['platform'] ?? '';
$kind = $_POST['kind'] ?? '';
$refId = !empty($_POST['ref_id']) ? (int) $_POST['ref_id'] : null;
$caption = trim($_POST['caption'] ?? '');
$studyId = !empty($_POST['study_id']) ? (int) $_POST['study_id'] : null;
$view = $_POST['view'] ?? 'overlay';

if (!in_array($platform, ['telegram', 'twitter'], true)) {
    respond_json(['error' => 'Piattaforma non valida'], 400);
}
if (!in_array($kind, ['capture', 'comparison', 'study'], true)) {
    respond_json(['error' => 'Tipo di contenuto non valido'], 400);
}

/**
 * Risolve i byte dell'immagine da condividere: per una ripresa, il file
 * appena caricato dal client (la copia di lavoro regolata); per un
 * confronto/riepilogo di studio, il risultato già salvato sul server (per
 * 'study' si usa l'ultimo confronto salvato dello studio).
 */
function resolve_share_image(string $kind, ?int $refId, string $view): array
{
    if ($kind === 'capture') {
        if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Immagine mancante nella richiesta');
        }
        $bytes = file_get_contents($_FILES['image']['tmp_name']);
        if ($bytes === false) {
            throw new RuntimeException('Immagine caricata non leggibile');
        }
        return [$bytes, 'ripresa.jpg'];
    }

    $comparisonId = $refId;
    if ($kind === 'study') {
        if (!$refId) {
            throw new RuntimeException('Studio non specificato');
        }
        $latest = Comparison::forStudy($refId)[0] ?? null;
        if (!$latest) {
            throw new RuntimeException('Nessun confronto salvato per questo studio: esegui e salva un confronto prima di condividere un riepilogo.');
        }
        $comparisonId = (int) $latest['id'];
    }
    if (!$comparisonId || !($comparison = Comparison::find($comparisonId))) {
        throw new RuntimeException('Confronto non trovato');
    }
    $paths = json_decode($comparison['result_paths_json'], true) ?: [];
    // "overlay" come ripiego se la vista richiesta non esiste per questo confronto.
    $relPath = $paths[$view] ?? $paths['overlay'] ?? null;
    if (!$relPath) {
        throw new RuntimeException('Immagine del confronto non disponibile');
    }
    $absPath = Config::storageRoot() . '/' . $relPath;
    $bytes = @file_get_contents($absPath);
    if ($bytes === false) {
        throw new RuntimeException('File immagine non trovato su disco');
    }
    return [$bytes, basename($relPath)];
}

// Twitter/X: nessun invio server-side in questa piattaforma (l'immagine
// resta sul dispositivo dell'analista, copiata negli appunti lato browser
// — vedi analyze.js/study.js — e incollata a mano nella finestra di
// composizione già aperta): qui si registra solo l'evento nel registro
// condivisioni, per sapere in seguito cosa è stato reso pubblico.
if ($platform === 'twitter') {
    Share::create($studyId, $kind, $refId, 'twitter', $caption);
    respond_json(['ok' => true]);
}

try {
    [$imageBytes, $filename] = resolve_share_image($kind, $refId, $view);
} catch (RuntimeException $e) {
    respond_json(['error' => $e->getMessage()], 404);
}

try {
    $client = new TelegramClient();
    $client->sendPhoto($imageBytes, $caption, $filename);
} catch (Throwable $e) {
    respond_json(['error' => $e->getMessage()], 502);
}

Share::create($studyId, $kind, $refId, 'telegram', $caption);
respond_json(['ok' => true]);
