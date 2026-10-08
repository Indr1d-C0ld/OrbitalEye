<?php
/**
 * Recupera (o aggiorna) data, sensore e risoluzione REALI delle immagini
 * Esri per le riprese già archiviate — quelle scaricate prima che il
 * download li leggesse, o per cui il servizio metadati non aveva risposto.
 * Vedi EsriImageryMetadata.php.
 *
 * Uso:
 *   php refresh_esri_metadata.php            solo le riprese senza metadati
 *   php refresh_esri_metadata.php --all      anche quelle che li hanno già
 *   php refresh_esri_metadata.php --id=84    una sola ripresa
 *   aggiungere --dry-run per vedere cosa cambierebbe senza scrivere nulla
 *
 * Oltre ai metadati aggiorna la data della ripresa (capture_date) e
 * l'etichetta, ma SOLO se è ancora quella generata automaticamente al
 * download ("Esri World Imagery — scaricata il ..."): un'etichetta scritta
 * dall'analista non viene mai toccata.
 */
require __DIR__ . '/../src/bootstrap.php';

$opts = getopt('', ['all', 'id:', 'dry-run']);
$dryRun = isset($opts['dry-run']);
$onlyId = isset($opts['id']) ? (int) $opts['id'] : null;

$rows = Database::get()->query('SELECT * FROM captures ORDER BY id')->fetchAll();
$autoLabel = '/^Esri (World Imagery|Wayback \(archivio del \d{2}\/\d{2}\/\d{4}\)) — (scaricata il \d{2}\/\d{2}\/\d{4}|data immagine non disponibile.*|immagine del \d{2}\/\d{2}\/\d{4}|immagini dal .*)$/u';

$done = 0;
foreach ($rows as $c) {
    if ($onlyId && (int) $c['id'] !== $onlyId) {
        continue;
    }
    $meta = json_decode($c['meta_json'] ?? '', true) ?: [];
    $isEsriOrigin = $c['source'] === 'esri' || !empty($meta['esri_imagery']);
    if (!$isEsriOrigin) {
        continue;
    }
    if (!$onlyId && !isset($opts['all']) && !empty($meta['esri_imagery'])) {
        continue;
    }
    $geo = Capture::resolveGeoRef($c);
    $mpp = Capture::resolveMpp($c);
    if (!$geo || !$mpp) {
        echo "#{$c['id']}: area o scala non note, salto.\n";
        continue;
    }
    // Per una ripresa ruotata l'area effettivamente coperta è quella di
    // raccolta (fetch_aabb), che racchiude il rettangolo ruotato.
    $area = (!empty($meta['fetch_aabb']) && abs((float) ($meta['rotation'] ?? 0)) >= 0.01) ? $meta['fetch_aabb'] : $geo['bbox'];

    // Riprese già archiviate: il mosaico va letto com'era il giorno del
    // download (archivio Wayback), non com'è oggi — nel frattempo Esri può
    // aver aggiornato l'area e la data corrente non descriverebbe l'immagine
    // che si ha in archivio.
    // Una versione storica (Wayback) si data con i metadati di QUELLA
    // versione, indipendentemente dal giorno del download.
    $fetchedAt = !empty($meta['fetched_at']) ? substr((string) $meta['fetched_at'], 0, 10) : null;
    $waybackRelease = (int) ($meta['wayback']['release'] ?? 0);
    try {
        $imagery = $waybackRelease
            ? EsriWayback::imageryFor($waybackRelease, $area, (float) $mpp['mpp_x'])
            : ($fetchedAt
                ? EsriImageryMetadata::queryAsOf($area, (float) $mpp['mpp_x'], $fetchedAt)
                : EsriImageryMetadata::query($area, (float) $mpp['mpp_x']));
    } catch (Throwable $e) {
        echo "#{$c['id']}: ERRORE — {$e->getMessage()}\n";
        continue;
    }

    $newLabel = $c['label'];
    if ($c['label'] === null || $c['label'] === '' || preg_match($autoLabel, $c['label'])) {
        $newLabel = ImageryAttribution::esriCaptureLabel($imagery, $meta['wayback']['release_date'] ?? null);
    }
    $certainty = !isset($imagery['certain']) ? '' : ($imagery['certain']
        ? ' [certa: uguale nelle istantanee ' . implode(' e ', $imagery['bracket']) . ']'
        : ' [INCERTA: l\'area è cambiata fra ' . implode(' e ', $imagery['bracket'] ?? []) . ', alternativa ' . ($imagery['alternative']['dominant_date'] ?? '?') . ']');
    printf(
        "#%d: %s → data %s, etichetta \"%s\"%s%s\n",
        $c['id'],
        $imagery['layer'],
        $imagery['dominant_date'] . ($imagery['date_min'] !== $imagery['date_max'] ? " (mosaico {$imagery['date_min']}…{$imagery['date_max']})" : ''),
        $newLabel,
        $newLabel !== $c['label'] ? ' (era "' . $c['label'] . '")' : '',
        $certainty
    );

    if (!$dryRun) {
        $meta['esri_imagery'] = $imagery;
        unset($meta['esri_imagery_error']);
        $stmt = Database::get()->prepare('UPDATE captures SET meta_json = :m, capture_date = :d, label = :l WHERE id = :id');
        $stmt->execute([
            ':m' => json_encode($meta),
            ':d' => $imagery['dominant_date'],
            ':l' => $newLabel,
            ':id' => $c['id'],
        ]);
    }
    $done++;
}
echo ($dryRun ? '[simulazione] ' : '') . "Riprese elaborate: $done\n";
