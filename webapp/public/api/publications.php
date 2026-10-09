<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();
session_write_close();

/**
 * Decisione su una pubblicazione in coda (vedi Publication):
 *  POST {action: 'approve', id, caption}  pubblica sul canale gli stessi
 *       file rivisti, con la didascalia eventualmente corretta;
 *  POST {action: 'reject', id}            la scarta ed elimina i file.
 * In entrambi i casi la chat di revisione riceve, in risposta al messaggio
 * originale, l'esito (se l'invio non riesce la decisione resta valida).
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(['error' => 'Metodo non consentito'], 405);
}
$body = json_body();
$id = (int) ($body['id'] ?? 0);
$pub = $id ? Publication::find($id) : null;
if (!$pub) {
    respond_json(['error' => 'Pubblicazione non trovata'], 404);
}
if (in_array($pub['status'], ['published', 'rejected'], true)) {
    respond_json(['error' => 'Questa pubblicazione è già stata ' . ($pub['status'] === 'published' ? 'pubblicata' : 'scartata') . '.'], 409);
}

$notifyReview = function (string $text) use ($pub): void {
    try {
        $review = TelegramClient::forReview();
        if ($review) {
            $review->sendMessage($text, $pub['review_message_id'] ? (int) $pub['review_message_id'] : null);
        }
    } catch (Throwable $e) {
        error_log('OrbitalEye: esito della revisione non notificato: ' . $e->getMessage());
    }
};

$action = $body['action'] ?? '';
if (!in_array($action, ['approve', 'reject'], true)) {
    respond_json(['error' => 'Azione non valida'], 400);
}
$caption = trim((string) ($body['caption'] ?? $pub['caption']));
if ($action === 'approve' && TelegramClient::textLength($caption) > TelegramClient::CAPTION_LIMIT) {
    respond_json(['error' => 'Didascalia troppo lunga per Telegram (massimo ' . TelegramClient::CAPTION_LIMIT . ' caratteri).'], 400);
}
if (!Publication::claim($id)) {
    respond_json(['error' => 'Questa pubblicazione è in invio in questo momento, o è stata decisa nel frattempo: ricarica la pagina.'], 409);
}

if ($action === 'reject') {
    Publication::markRejected($id);
    $notifyReview("✖ Pubblicazione #$id scartata.");
    respond_json(['ok' => true]);
}

if ($action === 'approve') {
    try {
        $sent = Publication::send(new TelegramClient(), Publication::payload($pub), $caption);
    } catch (Throwable $e) {
        // Il primo invio è fallito: non è uscito nulla, si può riprovare.
        Publication::release($id);
        Publication::setError($id, 'pubblicazione non riuscita: ' . $e->getMessage());
        respond_json(['error' => $e->getMessage()], 502);
    }
    // Già sul canale: da qui in poi nessun errore deve far ripetere l'invio.
    try {
        Publication::markPublished($id, $caption);
        Share::create($pub['study_id'] !== null ? (int) $pub['study_id'] : null, $pub['kind'], $pub['ref_id'] !== null ? (int) $pub['ref_id'] : null, 'telegram', $caption);
    } catch (Throwable $e) {
        error_log('OrbitalEye: pubblicazione inviata ma non registrata: ' . $e->getMessage());
    }
    if ($sent['warnings']) {
        Publication::setError($id, implode('; ', $sent['warnings']));
    }
    $notifyReview("✅ Pubblicazione #$id approvata e pubblicata sul canale.");
    respond_json($sent['warnings'] ? ['ok' => true, 'warning' => 'Pubblicata, ma ' . implode('; ', $sent['warnings']) . '.'] : ['ok' => true]);
}
