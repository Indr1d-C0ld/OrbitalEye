<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();
// Composizione e invio possono durare decine di secondi (album, file a
// piena risoluzione): la sessione si chiude subito.
session_write_close();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(['error' => 'Metodo non consentito'], 405);
}

/**
 * Condivisione (multipart/form-data, vedi PublicationBuilder per i campi):
 *  - platform=twitter: nessun invio server-side (l'immagine viene copiata
 *    negli appunti lato browser, vedi api/publication_preview.php, e incollata
 *    a mano nella finestra di composizione): si registra solo l'evento;
 *  - platform=telegram: le immagini vengono composte qui, nel formato
 *    scelto, e inviate al canale — oppure, con review=1 e una chat di
 *    revisione configurata, messe in coda e mandate prima alla chat di
 *    revisione (vedi Publication). Nessuna pubblicazione automatica: ogni
 *    invio parte da un gesto dell'analista, e quello verso il canale, in
 *    revisione, da un secondo gesto di chi approva.
 */
$platform = $_POST['platform'] ?? '';
$caption = trim($_POST['caption'] ?? '');
$studyId = !empty($_POST['study_id']) ? (int) $_POST['study_id'] : null;
$kind = $_POST['kind'] ?? '';
$refId = !empty($_POST['ref_id']) ? (int) $_POST['ref_id'] : null;

if (!in_array($platform, ['telegram', 'twitter'], true)) {
    respond_json(['error' => 'Piattaforma non valida'], 400);
}
if (!in_array($kind, ['capture', 'comparison', 'study'], true)) {
    respond_json(['error' => 'Tipo di contenuto non valido'], 400);
}

if ($platform === 'twitter') {
    // Registro di ciò che è stato reso pubblico (la composizione su X la fa l'analista).
    Share::create($studyId, $kind, $refId, 'twitter', $caption);
    respond_json(['ok' => true]);
}

if (TelegramClient::textLength($caption) > TelegramClient::CAPTION_LIMIT) {
    respond_json(['error' => 'Didascalia troppo lunga per Telegram: ' . TelegramClient::textLength($caption) . ' caratteri (le emoji contano doppio), il massimo è ' . TelegramClient::CAPTION_LIMIT . '.'], 400);
}

try {
    $built = PublicationBuilder::build($_POST, $_FILES['image'] ?? null);
} catch (PublicationException $e) {
    respond_json(['error' => $e->getMessage()], $e->httpStatus);
} catch (RuntimeException $e) {
    respond_json(['error' => $e->getMessage()], 400);
}

// ---- Con revisione: in coda e alla chat di revisione, non al canale ----
if (($_POST['review'] ?? '0') === '1') {
    try {
        $review = TelegramClient::forReview();
    } catch (TelegramException $e) {
        respond_json(['error' => $e->getMessage()], 400);
    }
    if (!$review) {
        respond_json(['error' => 'Nessuna chat di revisione configurata (Impostazioni → Telegram).'], 400);
    }
    try {
        $pubId = Publication::enqueue($built);
    } catch (Throwable $e) {
        respond_json(['error' => $e->getMessage()], 500);
    }
    try {
        $sent = Publication::sendForReview($review, Publication::find($pubId));
        Publication::setReviewMessage($pubId, $sent['first']);
        if ($sent['warnings']) {
            Publication::setError($pubId, implode('; ', $sent['warnings']));
        }
    } catch (Throwable $e) {
        // In coda resta comunque: si può approvare o scartare dalla piattaforma.
        Publication::setError($pubId, 'invio alla chat di revisione non riuscito: ' . $e->getMessage());
        respond_json(['ok' => true, 'queued' => $pubId, 'warning' => 'Messa in coda, ma l\'invio alla chat di revisione non è riuscito: ' . $e->getMessage()]);
    }
    respond_json(['ok' => true, 'queued' => $pubId]);
}

// ---- Diretta: al canale ----
try {
    $sent = Publication::send(new TelegramClient(), ['media' => $built['media'], 'document' => $built['document']], $built['caption']);
} catch (Throwable $e) {
    // Il primo invio è fallito: non è uscito nulla.
    respond_json(['error' => $e->getMessage()], 502);
}

// La foto è già pubblicata: un errore nel registro (es. studio eliminato
// nel frattempo) non deve trasformarsi in un errore per l'analista, che
// riproverebbe e pubblicherebbe la stessa immagine una seconda volta.
try {
    Share::create($built['study_id'], $built['kind'], $built['ref_id'], 'telegram', $built['caption']);
} catch (Throwable $e) {
    error_log('OrbitalEye: condivisione Telegram inviata ma non registrata: ' . $e->getMessage());
}
// Il contenuto è pubblicato anche se il file allegato non è partito: un
// avviso, non un errore (un nuovo tentativo lo pubblicherebbe due volte).
respond_json($sent['warnings'] ? ['ok' => true, 'warning' => 'Pubblicato, ma ' . implode('; ', $sent['warnings']) . '.'] : ['ok' => true]);
