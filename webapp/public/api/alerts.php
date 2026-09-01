<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    respond_json(['alerts' => Alert::recent(200), 'unread_count' => Alert::unreadCount()]);
}

if ($method === 'POST') {
    $body = json_body();
    $action = $body['action'] ?? '';
    if ($action === 'mark_read') {
        $id = (int) ($body['id'] ?? 0);
        if (!$id) {
            respond_json(['error' => 'id mancante'], 400);
        }
        Alert::markRead($id);
        respond_json(['ok' => true]);
    }
    if ($action === 'mark_all_read') {
        Alert::markAllRead();
        respond_json(['ok' => true]);
    }
    respond_json(['error' => 'Azione non valida'], 400);
}

respond_json(['error' => 'Metodo non consentito'], 405);
