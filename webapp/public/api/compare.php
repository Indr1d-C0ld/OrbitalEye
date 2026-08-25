<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond_json(['error' => 'Metodo non consentito'], 405);
}

$body = json_body();

$studyId = (int) ($body['study_id'] ?? 0);
$captureAId = (int) ($body['capture_a_id'] ?? 0);
$captureBId = (int) ($body['capture_b_id'] ?? 0);

$study = $studyId ? Study::find($studyId) : null;
$captureA = $captureAId ? Capture::find($captureAId) : null;
$captureB = $captureBId ? Capture::find($captureBId) : null;

if (!$study || !$captureA || !$captureB) {
    respond_json(['error' => 'Studio o riprese non trovati'], 404);
}
if ((int)$captureA['study_id'] !== $studyId || (int)$captureB['study_id'] !== $studyId) {
    respond_json(['error' => 'Le riprese non appartengono a questo studio'], 400);
}

function build_enhance_steps(array $opts): array
{
    $steps = [];
    if (!empty($opts['white_balance'])) {
        $steps[] = ['filter' => 'white_balance', 'params' => new stdClass()];
    }
    if (!empty($opts['denoise'])) {
        $steps[] = ['filter' => 'denoise', 'params' => [
            'method' => $opts['denoise_method'] ?? 'gaussian',
            'strength' => (int) ($opts['denoise_strength'] ?? 3),
        ]];
    }
    if (!empty($opts['clahe'])) {
        $steps[] = ['filter' => 'clahe', 'params' => [
            'clip_limit' => (float) ($opts['clahe_clip'] ?? 2.0),
            'tile_grid_size' => (int) ($opts['clahe_grid'] ?? 8),
        ]];
    }
    if (!empty($opts['hist_eq'])) {
        $steps[] = ['filter' => 'histogram_equalization', 'params' => new stdClass()];
    }
    if (!empty($opts['gamma_enabled'])) {
        $steps[] = ['filter' => 'gamma', 'params' => ['gamma' => (float) ($opts['gamma'] ?? 1.0)]];
    }
    if (!empty($opts['sharpen'])) {
        $steps[] = ['filter' => 'sharpen', 'params' => ['amount' => (float) ($opts['sharpen_amount'] ?? 1.0)]];
    }
    return $steps;
}

$enhanceOpts = $body['enhance'] ?? [];
$enhanceSteps = build_enhance_steps($enhanceOpts);

$payload = [
    'capture_a_path' => $captureA['relative_path'],
    'capture_b_path' => $captureB['relative_path'],
    'align' => !empty($body['align']),
    'diff_method' => $body['diff_method'] ?? 'ssim',
    'threshold' => (int) ($body['threshold'] ?? 30),
    'use_otsu' => !empty($body['use_otsu']),
    'morph_kernel' => (int) ($body['morph_kernel'] ?? 3),
    'open_iterations' => (int) ($body['open_iterations'] ?? 1),
    'close_iterations' => (int) ($body['close_iterations'] ?? 2),
    'min_blob_area' => (int) ($body['min_blob_area'] ?? 40),
    'overlay_alpha' => (float) ($body['overlay_alpha'] ?? 0.35),
    'enhance_a' => $enhanceSteps,
    'enhance_b' => $enhanceSteps,
];

try {
    $client = new PythonServiceClient();
    $result = $client->post('/analysis/compare', $payload);
} catch (PythonServiceException $e) {
    respond_json(['error' => $e->getMessage()], 502);
}

$comparisonId = Comparison::create(
    $studyId,
    $captureAId,
    $captureBId,
    trim($body['title'] ?? '') ?: null,
    $payload,
    $result['stats'],
    $result['regions'],
    $result['paths'],
    $result['registration']
);

Study::touch($studyId);

respond_json([
    'comparison_id' => $comparisonId,
    'stats' => $result['stats'],
    'regions' => $result['regions'],
    'registration' => $result['registration'],
    'urls' => [
        'aligned_b' => storage_url($result['paths']['aligned_b']),
        'mask' => storage_url($result['paths']['mask']),
        'overlay' => storage_url($result['paths']['overlay']),
        'heatmap' => storage_url($result['paths']['heatmap']),
        'edges' => storage_url($result['paths']['edges']),
    ],
]);
