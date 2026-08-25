<?php

/**
 * Costruisce i pacchetti di esportazione (ZIP) per un confronto: riprese
 * originali, immagini di risultato (allineata, overlay, heatmap, maschera)
 * e un report leggibile (HTML) + uno strutturato (JSON) con parametri,
 * statistiche, regioni rilevate e annotazioni.
 */
final class ExportBuilder
{
    public static function slug(string $s, string $fallback = 'ripresa'): string
    {
        $s = trim($s);
        if ($s === '') {
            return $fallback;
        }
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        $s = preg_replace('/[^A-Za-z0-9]+/', '_', $s);
        $s = trim($s, '_');
        return $s !== '' ? substr($s, 0, 60) : $fallback;
    }

    /**
     * Raccoglie tutto il necessario per esportare un confronto: elenco dei
     * file fisici da includere nello ZIP (con il nome che avranno al suo
     * interno) e i contenuti dei due report generati al volo.
     *
     * Ritorna null se il confronto non esiste più.
     */
    public static function comparisonBundle(int $comparisonId): ?array
    {
        $comparison = Comparison::find($comparisonId);
        if (!$comparison) {
            return null;
        }
        $study = Study::find((int) $comparison['study_id']);
        $captureA = Capture::find((int) $comparison['capture_a_id']);
        $captureB = Capture::find((int) $comparison['capture_b_id']);
        if (!$study || !$captureA || !$captureB) {
            return null;
        }

        $params = json_decode($comparison['params_json'], true) ?: [];
        $stats = json_decode($comparison['stats_json'], true) ?: [];
        $regions = json_decode($comparison['regions_json'], true) ?: [];
        $resultPaths = json_decode($comparison['result_paths_json'], true) ?: [];
        $registration = json_decode($comparison['registration_json'], true) ?: [];
        $annotations = array_map(function ($a) {
            $a['coords'] = json_decode($a['coords_json'], true);
            return $a;
        }, Annotation::forComparison($comparisonId));

        $root = Config::storageRoot();
        $extA = self::extensionOf($captureA['relative_path']);
        $extB = self::extensionOf($captureB['relative_path']);
        $labelA = self::slug($captureA['label'] ?: ('capture_' . $captureA['id']), 'A');
        $labelB = self::slug($captureB['label'] ?: ('capture_' . $captureB['id']), 'B');

        $zipNames = [
            'capture_a' => "01_originale_A_{$labelA}.{$extA}",
            'capture_b' => "02_originale_B_{$labelB}.{$extB}",
            'aligned_b' => '03_risultato_allineato.jpg',
            'overlay' => '04_risultato_overlay.jpg',
            'heatmap' => '05_risultato_heatmap.jpg',
            'mask' => '06_risultato_maschera.png',
            'edges' => '07_risultato_contorni.jpg',
        ];

        $files = [];
        $add = function (string $key, string $sourceRelative) use (&$files, $zipNames, $root) {
            $full = $root . '/' . $sourceRelative;
            if (is_file($full)) {
                $files[] = ['source' => $full, 'zip_name' => $zipNames[$key]];
            }
        };
        $add('capture_a', $captureA['relative_path']);
        $add('capture_b', $captureB['relative_path']);
        if (!empty($resultPaths['aligned_b'])) $add('aligned_b', $resultPaths['aligned_b']);
        if (!empty($resultPaths['overlay'])) $add('overlay', $resultPaths['overlay']);
        if (!empty($resultPaths['heatmap'])) $add('heatmap', $resultPaths['heatmap']);
        if (!empty($resultPaths['mask'])) $add('mask', $resultPaths['mask']);
        if (!empty($resultPaths['edges'])) $add('edges', $resultPaths['edges']);

        $data = [
            'study' => $study,
            'capture_a' => $captureA,
            'capture_b' => $captureB,
            'comparison' => $comparison,
            'params' => $params,
            'stats' => $stats,
            'regions' => $regions,
            'registration' => $registration,
            'annotations' => $annotations,
        ];

        return [
            'folder_name' => self::slug($study['title']) . '_' . self::slug($comparison['title'] ?: ('confronto_' . $comparisonId)),
            'files' => $files,
            'report_html' => self::reportHtml($data, $zipNames),
            'report_json' => self::reportJson($data),
        ];
    }

    private static function extensionOf(string $relativePath): string
    {
        $ext = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        return $ext !== '' ? $ext : 'jpg';
    }

    private static function reportJson(array $d): string
    {
        return json_encode([
            'generated_at' => date('c'),
            'study' => [
                'title' => $d['study']['title'],
                'area_name' => $d['study']['area_name'],
                'notes' => $d['study']['notes'],
                'bbox' => $d['study']['bbox_json'] ? json_decode($d['study']['bbox_json'], true) : null,
            ],
            'capture_a' => [
                'label' => $d['capture_a']['label'],
                'source' => $d['capture_a']['source'],
                'capture_date' => $d['capture_a']['capture_date'],
                'width' => $d['capture_a']['width'],
                'height' => $d['capture_a']['height'],
            ],
            'capture_b' => [
                'label' => $d['capture_b']['label'],
                'source' => $d['capture_b']['source'],
                'capture_date' => $d['capture_b']['capture_date'],
                'width' => $d['capture_b']['width'],
                'height' => $d['capture_b']['height'],
            ],
            'comparison' => [
                'id' => (int) $d['comparison']['id'],
                'title' => $d['comparison']['title'],
                'created_at' => $d['comparison']['created_at'],
                'saved_to_library' => (bool) $d['comparison']['is_saved_to_library'],
            ],
            'params' => $d['params'],
            'registration' => $d['registration'],
            'stats' => $d['stats'],
            'regions' => $d['regions'],
            'annotations' => array_map(fn($a) => [
                'label' => $a['label'],
                'notes' => $a['notes'],
                'target_image' => $a['target_image'],
                'coords' => $a['coords'],
                'color' => $a['color'],
                'created_at' => $a['created_at'],
            ], $d['annotations']),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function reportHtml(array $d, array $zipNames): string
    {
        $e = fn($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $study = $d['study']; $capA = $d['capture_a']; $capB = $d['capture_b']; $cmp = $d['comparison'];
        $stats = $d['stats']; $params = $d['params']; $regions = $d['regions']; $reg = $d['registration'];
        $annotations = $d['annotations'];

        $regionsRows = '';
        foreach ($regions as $i => $r) {
            $regionsRows .= '<tr><td>#' . ($i + 1) . '</td><td>' . (int)$r['x'] . ', ' . (int)$r['y'] . '</td><td>' . (int)$r['w'] . '&times;' . (int)$r['h'] . ' px</td><td>' . (int)$r['area'] . ' px&sup2;</td></tr>';
        }
        if (!$regions) {
            $regionsRows = '<tr><td colspan="4" class="muted">Nessuna regione di cambiamento rilevata con i parametri usati.</td></tr>';
        }

        $annotationRows = '';
        foreach ($annotations as $a) {
            $annotationRows .= '<tr><td>' . $e($a['label'] ?: '—') . '</td><td>' . $e($a['notes'] ?: '—') . '</td><td>' . $e($a['target_image']) . '</td></tr>';
        }
        if (!$annotations) {
            $annotationRows = '<tr><td colspan="3" class="muted">Nessuna annotazione manuale su questo confronto.</td></tr>';
        }

        $bboxLine = '';
        if (!empty($study['bbox_json'])) {
            $bbox = json_decode($study['bbox_json'], true);
            if (is_array($bbox) && count($bbox) === 4) {
                $bboxLine = '<div class="kv"><span>Bounding box</span><span>' . implode(', ', array_map(fn($v) => round((float)$v, 5), $bbox)) . '</span></div>';
            }
        }

        $title = $e($cmp['title'] ?: ('Confronto #' . $cmp['id']));
        $changedPct = isset($stats['changed_ratio']) ? round($stats['changed_ratio'] * 100, 2) . '%' : '—';

        return <<<HTML
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<title>OrbitalEye — {$title}</title>
<style>
  :root {
    --bg: #060a10; --panel: #0b131c; --line: #1c2e3d; --cyan: #00fff2;
    --text: #d9f6f5; --muted: #7fa3ab; --amber: #ffb020;
  }
  * { box-sizing: border-box; }
  body { background: var(--bg); color: var(--text); font-family: 'Courier New', monospace; margin: 0; padding: 32px; line-height: 1.5; }
  h1 { color: var(--cyan); font-size: 22px; letter-spacing: 0.06em; text-transform: uppercase; margin: 0 0 4px 0; }
  h2 { color: var(--cyan); font-size: 14px; letter-spacing: 0.06em; text-transform: uppercase; border-bottom: 1px solid var(--line); padding-bottom: 6px; margin-top: 36px; }
  .sub { color: var(--muted); font-size: 12px; letter-spacing: 0.1em; text-transform: uppercase; margin-bottom: 28px; }
  .panel { background: var(--panel); border: 1px solid var(--line); border-radius: 4px; padding: 18px 22px; margin-bottom: 18px; }
  .kv { display: flex; justify-content: space-between; gap: 20px; padding: 4px 0; font-size: 13px; border-bottom: 1px dashed var(--line); }
  .kv:last-child { border-bottom: none; }
  .kv span:first-child { color: var(--muted); }
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
  table { width: 100%; border-collapse: collapse; font-size: 13px; }
  th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--line); }
  th { color: var(--muted); text-transform: uppercase; font-size: 10px; letter-spacing: 0.05em; }
  .muted { color: var(--muted); font-style: italic; }
  .stat-row { display: flex; gap: 14px; flex-wrap: wrap; margin-bottom: 8px; }
  .stat { background: var(--panel); border: 1px solid var(--line); border-radius: 4px; padding: 14px 18px; min-width: 140px; }
  .stat .v { color: var(--cyan); font-size: 24px; font-weight: bold; }
  .stat .l { color: var(--muted); font-size: 11px; text-transform: uppercase; margin-top: 4px; }
  .imgs { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 10px; }
  .imgs figure { margin: 0; background: #000; border: 1px solid var(--line); border-radius: 4px; overflow: hidden; }
  .imgs img { width: 100%; display: block; }
  .imgs figcaption { padding: 6px 10px; font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; }
  footer { margin-top: 40px; color: var(--muted); font-size: 11px; text-align: center; letter-spacing: 0.05em; }
  @media print {
    body { background: #fff; color: #111; }
    .panel, .stat, .imgs figure { background: #f4f4f4; border-color: #ccc; }
    h1, h2, .stat .v { color: #005f5a; }
  }
</style>
</head>
<body>
  <h1>&#9670; ORBITALEYE — Report di analisi comparativa</h1>
  <div class="sub">Generato il {$e(date('d/m/Y H:i'))} · Confronto #{$e($cmp['id'])}</div>

  <div class="panel">
    <div class="kv"><span>Studio</span><span>{$e($study['title'])}</span></div>
    <div class="kv"><span>Area</span><span>{$e($study['area_name'] ?: '—')}</span></div>
    <div class="kv"><span>Titolo confronto</span><span>{$title}</span></div>
    <div class="kv"><span>Eseguito il</span><span>{$e(format_datetime_it($cmp['created_at']))}</span></div>
    {$bboxLine}
  </div>

  <h2>Riprese analizzate</h2>
  <div class="grid2">
    <div class="panel">
      <div class="kv"><span>Ruolo</span><span>A · PRIMA</span></div>
      <div class="kv"><span>Etichetta</span><span>{$e($capA['label'] ?: '—')}</span></div>
      <div class="kv"><span>Data ripresa</span><span>{$e(format_date_it($capA['capture_date']))}</span></div>
      <div class="kv"><span>Fonte</span><span>{$e($capA['source'])}</span></div>
      <div class="kv"><span>Dimensioni</span><span>{$e($capA['width'])}&times;{$e($capA['height'])}px</span></div>
    </div>
    <div class="panel">
      <div class="kv"><span>Ruolo</span><span>B · DOPO</span></div>
      <div class="kv"><span>Etichetta</span><span>{$e($capB['label'] ?: '—')}</span></div>
      <div class="kv"><span>Data ripresa</span><span>{$e(format_date_it($capB['capture_date']))}</span></div>
      <div class="kv"><span>Fonte</span><span>{$e($capB['source'])}</span></div>
      <div class="kv"><span>Dimensioni</span><span>{$e($capB['width'])}&times;{$e($capB['height'])}px</span></div>
    </div>
  </div>

  <h2>Parametri di analisi</h2>
  <div class="panel">
    <div class="kv"><span>Metodo diff</span><span>{$e($params['diff_method'] ?? '—')}</span></div>
    <div class="kv"><span>Soglia</span><span>{$e($params['use_otsu'] ?? false ? 'Automatica (Otsu)' : ($params['threshold'] ?? '—'))}</span></div>
    <div class="kv"><span>Area minima blob</span><span>{$e($params['min_blob_area'] ?? '—')} px&sup2;</span></div>
    <div class="kv"><span>Kernel morfologico</span><span>{$e($params['morph_kernel'] ?? '—')}</span></div>
    <div class="kv"><span>Allineamento automatico</span><span>{$e(!empty($params['align']) ? 'Sì' : 'No')}</span></div>
    <div class="kv"><span>Metodo di registrazione</span><span>{$e($reg['method'] ?? '—')} (confidenza {$e(isset($reg['confidence']) ? round($reg['confidence'] * 100) . '%' : '—')})</span></div>
  </div>

  <h2>Risultati</h2>
  <div class="stat-row">
    <div class="stat"><div class="v">{$e($changedPct)}</div><div class="l">Superficie variata</div></div>
    <div class="stat"><div class="v">{$e($stats['num_regions'] ?? 0)}</div><div class="l">Regioni rilevate</div></div>
    <div class="stat"><div class="v">{$e($stats['largest_region_area'] ?? 0)}</div><div class="l">Area regione max (px&sup2;)</div></div>
    <div class="stat"><div class="v">{$e($stats['changed_pixels'] ?? 0)}</div><div class="l">Pixel variati</div></div>
  </div>

  <div class="imgs">
    <figure><img src="{$zipNames['overlay']}" alt="Overlay"><figcaption>Overlay differenze</figcaption></figure>
    <figure><img src="{$zipNames['heatmap']}" alt="Heatmap"><figcaption>Heatmap</figcaption></figure>
    <figure><img src="{$zipNames['capture_a']}" alt="Prima"><figcaption>Originale A — prima</figcaption></figure>
    <figure><img src="{$zipNames['aligned_b']}" alt="Dopo (allineata)"><figcaption>Originale B — dopo (allineata su A)</figcaption></figure>
  </div>

  <h2>Regioni di cambiamento rilevate</h2>
  <div class="panel">
    <table>
      <thead><tr><th>#</th><th>Posizione (x, y)</th><th>Dimensione</th><th>Area</th></tr></thead>
      <tbody>{$regionsRows}</tbody>
    </table>
  </div>

  <h2>Annotazioni operatore</h2>
  <div class="panel">
    <table>
      <thead><tr><th>Etichetta</th><th>Note</th><th>Vista</th></tr></thead>
      <tbody>{$annotationRows}</tbody>
    </table>
  </div>

  <footer>ORBITALEYE // ANALISI COMPARATIVA IMMAGINI SATELLITARI // AD USO LOCALE — report generato automaticamente, dati completi in report.json</footer>
</body>
</html>
HTML;
    }
}
