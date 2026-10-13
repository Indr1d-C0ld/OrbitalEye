<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();
session_write_close();

/**
 * Verifica dei rilevamenti (vedi DetectionReview.php).
 *  GET  ?capture_id=N                          verifiche della ripresa e se è verificata per intero
 *  POST {action: 'save', capture_id, verdict, class, type_name, polygon, source}
 *  POST {action: 'delete', id}
 *  POST {action: 'complete', capture_id, complete}
 */
$method = $_SERVER['REQUEST_METHOD'];
$body = $method === 'POST' ? json_body() : $_GET;

if ($method === 'GET') {
    $captureId = (int) ($body['capture_id'] ?? 0);
    if (!$captureId || !Capture::find($captureId)) {
        respond_json(['error' => 'Ripresa non trovata'], 404);
    }
    respond_json(['reviews' => DetectionReview::forCapture($captureId), 'complete' => DetectionReview::isComplete($captureId)]);
}
if ($method !== 'POST') {
    respond_json(['error' => 'Metodo non consentito'], 405);
}

$action = (string) ($body['action'] ?? '');
try {
    if ($action === 'save') {
        $captureId = (int) ($body['capture_id'] ?? 0);
        $id = DetectionReview::save($captureId, $body);
        respond_json(['ok' => true, 'id' => $id, 'reviews' => DetectionReview::forCapture($captureId)]);
    }
    if ($action === 'delete') {
        $id = (int) ($body['id'] ?? 0);
        $stmt = Database::get()->prepare('SELECT capture_id FROM detection_reviews WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $captureId = (int) $stmt->fetchColumn();
        if (!$captureId) {
            respond_json(['error' => 'Verifica non trovata'], 404);
        }
        DetectionReview::delete($id);
        respond_json(['ok' => true, 'reviews' => DetectionReview::forCapture($captureId)]);
    }
    if ($action === 'complete') {
        $captureId = (int) ($body['capture_id'] ?? 0);
        if (!$captureId || !Capture::find($captureId)) {
            respond_json(['error' => 'Ripresa non trovata'], 404);
        }
        DetectionReview::setComplete($captureId, !empty($body['complete']));
        respond_json(['ok' => true, 'complete' => DetectionReview::isComplete($captureId)]);
    }
} catch (InvalidArgumentException $e) {
    respond_json(['error' => $e->getMessage()], 400);
}
respond_json(['error' => 'Azione non valida'], 400);
