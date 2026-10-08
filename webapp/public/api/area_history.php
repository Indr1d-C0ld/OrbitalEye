<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();

/**
 * Storico dell'area di uno studio (vedi AreaHistory): JSON, oppure CSV con
 * format=csv per un foglio di calcolo.
 */
$studyId = (int) ($_GET['study_id'] ?? 0);
$study = $studyId ? Study::find($studyId) : null;
if (!$study) {
    respond_json(['error' => 'Studio non trovato'], 404);
}
$rows = AreaHistory::forStudy($studyId);

if (($_GET['format'] ?? '') === 'csv') {
    $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', $study['title']) ?: 'studio';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="storico_' . $name . '.csv"');
    echo AreaHistory::csv($rows);
    exit;
}
respond_json(['rows' => $rows, 'classes' => Detection::HISTORY_CLASSES]);
