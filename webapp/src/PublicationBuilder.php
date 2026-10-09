<?php

final class PublicationException extends RuntimeException
{
    public int $httpStatus;

    public function __construct(string $message, int $httpStatus = 400)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }
}

/**
 * Prepara ciò che si pubblica — le immagini nel formato scelto, l'eventuale
 * album e il file a piena risoluzione — a partire da una richiesta di
 * condivisione. Usato dall'invio diretto e dalla coda di revisione
 * (api/share.php) e dall'anteprima / copia per X (api/publication_preview.php):
 * quello che si vede in anteprima è esattamente quello che parte.
 *
 * Parametri (POST multipart):
 *  - kind: capture (anche un ritaglio) | comparison | study
 *  - ref_id, study_id, caption
 *  - format: none | strip | card | pair (pair solo per i confronti)
 *  - title: titolo della scheda (default: titolo dello studio)
 *  - view: vista del confronto (overlay, heatmap, original-a...)
 *  - album: '1' = album Telegram con la vista e le due riprese (confronti)
 *  - document: '1' = anche il file a piena risoluzione
 *  - image: per una ripresa, la copia di lavoro caricata dal browser
 *  - natural_width: larghezza, in pixel della ripresa originale, coperta
 *    dall'immagine caricata (un ritaglio ne copre una parte): serve alla
 *    barra di scala
 */
final class PublicationBuilder
{
    public const FORMATS = ['none', 'strip', 'card', 'pair'];
    private const VIEW_ALIASES = ['original-a' => 'enhanced_a', 'original-b' => 'aligned_b'];
    private const SHAREABLE_VIEWS = ['overlay', 'heatmap', 'mask', 'edges', 'enhanced_a', 'aligned_b'];
    /** Limite di Telegram per le foto. */
    private const MAX_UPLOAD = 10 * 1024 * 1024;

    /**
     * @return array{kind:string, ref_id:?int, study_id:?int, caption:string, format:string,
     *   media:array<int,array{0:string,1:string,2:string}>, document:?array, is_esri:bool, summary:string}
     */
    public static function build(array $p, ?array $upload): array
    {
        $kind = (string) ($p['kind'] ?? '');
        $format = in_array($p['format'] ?? '', self::FORMATS, true) ? $p['format'] : 'strip';
        $wantAlbum = ($p['album'] ?? '0') === '1';
        $wantDocument = ($p['document'] ?? '0') === '1';
        $caption = trim((string) ($p['caption'] ?? ''));
        $studyId = !empty($p['study_id']) ? (int) $p['study_id'] : null;
        $study = $studyId ? Study::find($studyId) : null;
        $title = mb_substr(trim((string) ($p['title'] ?? '')), 0, 120) ?: ($study['title'] ?? '');
        if (in_array($format, ['card', 'pair'], true) && !PublicationComposer::available()) {
            throw new PublicationException('Scheda non disponibile: servono GD con FreeType e il font DejaVu Sans (pacchetto fonts-dejavu) sul server.', 500);
        }

        if ($kind === 'capture') {
            $capture = !empty($p['ref_id']) ? Capture::find((int) $p['ref_id']) : null;
            if (!$capture) {
                throw new PublicationException('Ripresa non trovata', 404);
            }
            if ($format === 'pair') {
                $format = 'card';
            }
            [$bytes, $mime, $width] = self::uploadedImage($upload);
            $info = ImageryAttribution::forCapture($capture);
            // Solo la scala della ripresa stessa (o ereditata), mai quella
            // ricavata dall'area dello studio: su un'immagine pubblicata una
            // barra di scala sbagliata sarebbe un'informazione falsa.
            $mpp = Capture::resolveMpp($capture);
            // Larghezza coperta in pixel della ripresa originale: fra 1 e
            // la larghezza della ripresa (un ritaglio ne copre una parte).
            $naturalWidth = (float) ($p['natural_width'] ?? 0);
            $naturalWidth = $naturalWidth > 0 ? min($naturalWidth, (float) $capture['width']) : (float) $capture['width'];
            $cardInfo = [
                'title' => $title,
                'line' => self::sentence($info['date_label'], $info['detail']),
                'credit' => ImageryAttribution::publicationCredit($info),
                'mpp_x' => $mpp && $width ? $mpp['mpp_x'] * $naturalWidth / $width : null,
                'north_deg' => self::north($capture),
            ];
            $main = self::compose($format, $bytes, $mime, $cardInfo, $info['strip']);
            $doc = $wantDocument ? ($format === 'card' ? PublicationComposer::card($bytes, $cardInfo, true) : $main) : null;
            return [
                'kind' => 'capture', 'ref_id' => (int) $capture['id'], 'study_id' => $studyId,
                'caption' => $caption, 'format' => $format,
                'media' => [[$main[0], 'ripresa_' . $capture['id'] . self::ext($main[1]), $main[1]]],
                'document' => $doc ? [$doc[0], 'ripresa_' . $capture['id'] . '_piena_risoluzione' . self::ext($doc[1]), $doc[1]] : null,
                'is_esri' => $info['is_esri'],
                'summary' => $capture['label'] ?: ('Ripresa #' . $capture['id']),
            ];
        }

        if (!in_array($kind, ['comparison', 'study'], true)) {
            throw new PublicationException('Tipo di contenuto non valido');
        }
        $comparison = $kind === 'study'
            ? ($studyId ? Comparison::latestSaved($studyId) : null)
            : (!empty($p['ref_id']) ? Comparison::find((int) $p['ref_id']) : null);
        if (!$comparison) {
            throw new PublicationException($kind === 'study'
                ? 'Nessun confronto salvato per questo studio: esegui e salva un confronto prima di condividere un riepilogo.'
                : 'Confronto non trovato', 404);
        }
        $view = (string) ($p['view'] ?? 'overlay');
        $view = $kind === 'study' ? 'overlay' : (self::VIEW_ALIASES[$view] ?? $view);
        // La scheda affiancata non usa la vista (prende sempre le due riprese
        // confrontate): va bene anche dalla vista Prima/Dopo.
        if ($format !== 'pair' && !in_array($view, self::SHAREABLE_VIEWS, true)) {
            throw new PublicationException($view === 'swipe'
                ? 'La vista Prima/Dopo non è una singola immagine: passa a un\'altra vista, o scegli il formato "Prima e Dopo affiancate".'
                : 'Vista non condivisibile.');
        }
        $paths = json_decode($comparison['result_paths_json'], true) ?: [];
        $read = function (string $key) use ($paths): array {
            $rel = $paths[$key] ?? null;
            $bytes = $rel ? @file_get_contents(Config::storageRoot() . '/' . $rel) : false;
            if ($bytes === false) {
                throw new PublicationException('Immagine del confronto non disponibile su disco', 404);
            }
            $size = getimagesizefromstring($bytes);
            return [$bytes, $size['mime'] ?? 'image/png', (int) ($size[0] ?? 0)];
        };
        $capA = Capture::find((int) $comparison['capture_a_id']);
        $capB = Capture::find((int) $comparison['capture_b_id']);
        $infoA = $capA ? ImageryAttribution::forCapture($capA) : null;
        $infoB = $capB ? ImageryAttribution::forCapture($capB) : null;
        $pair = ImageryAttribution::forCaptures(array_filter(['Prima' => $capA, 'Dopo' => $capB]));
        $credit = ImageryAttribution::publicationCredit(...array_values(array_filter([$infoA, $infoB])));
        // Scala della ripresa A (le immagini del confronto sono nella sua
        // griglia), solo se georiferita: vedi sopra.
        $mppA = $capA ? Capture::resolveMpp($capA) : null;
        $cardInfo = [
            'title' => $title,
            'line' => ucfirst($pair['date_label']),
            'credit' => $credit,
            'north_deg' => $capA ? self::north($capA) : null,
        ];
        $mppFor = fn(int $width) => $mppA && $width && $capA ? $mppA['mpp_x'] * (float) $capA['width'] / $width : null;
        $dateOf = fn(?array $info) => $info && $info['date'] ? self::it($info['date']) : 'data non nota';

        // Il file "a piena risoluzione" si compone senza il limite di
        // larghezza delle foto Telegram.
        $doc = null;
        if ($format === 'pair') {
            [$a, , $wa] = $read('enhanced_a');
            [$b] = $read('aligned_b');
            $pairInfo = $cardInfo + [
                'mpp_x' => $mppFor($wa),
                'label_a' => 'PRIMA · ' . $dateOf($infoA),
                'label_b' => 'DOPO · ' . $dateOf($infoB),
            ];
            $main = PublicationComposer::pair($a, $b, $pairInfo);
            if ($wantDocument) {
                $doc = PublicationComposer::pair($a, $b, $pairInfo, true);
            }
        } else {
            [$bytes, $mime, $w] = $read($view);
            $main = self::compose($format, $bytes, $mime, $cardInfo + ['mpp_x' => $mppFor($w)], $pair['strip']);
            if ($wantDocument) {
                $doc = $format === 'card' ? PublicationComposer::card($bytes, $cardInfo + ['mpp_x' => $mppFor($w)], true) : $main;
            }
        }
        $base = ($kind === 'study' ? 'riepilogo_studio_' . $studyId : 'confronto_' . $comparison['id']);
        $media = [[$main[0], $base . self::ext($main[1]), $main[1]]];
        if ($wantAlbum) {
            // Album: l'immagine principale e, a piena dimensione, le due
            // riprese confrontate, ciascuna con la propria data.
            foreach ([['enhanced_a', 'prima', 'Prima', $infoA], ['aligned_b', 'dopo', 'Dopo', $infoB]] as [$key, $suffix, $label, $info]) {
                [$bytes, $mime] = $read($key);
                $strip = $label . ' ' . $dateOf($info) . ($info && $info['credit'] ? ' · ' . $info['credit'] : '');
                $item = $format === 'none' ? [$bytes, $mime] : (ImageryAttribution::burnStrip($bytes, $strip) ?? [$bytes, $mime]);
                $media[] = [$item[0], $base . '_' . $suffix . self::ext($item[1]), $item[1]];
            }
        }
        return [
            'kind' => $kind,
            'ref_id' => $kind === 'study' ? $studyId : (int) $comparison['id'],
            'study_id' => $studyId,
            'caption' => $caption,
            'format' => $format,
            'media' => $media,
            'document' => $doc ? [$doc[0], $base . '_piena_risoluzione' . self::ext($doc[1]), $doc[1]] : null,
            'is_esri' => $pair['is_esri'],
            'summary' => ($kind === 'study' ? 'Riepilogo dello studio' : 'Confronto #' . $comparison['id']),
        ];
    }

    /** @return array{0:string,1:string} byte e tipo MIME nel formato richiesto */
    private static function compose(string $format, string $bytes, string $mime, array $info, string $strip): array
    {
        if ($format === 'card') {
            return PublicationComposer::card($bytes, $info);
        }
        if ($format === 'strip' && $strip !== '') {
            return ImageryAttribution::burnStrip($bytes, $strip) ?? [$bytes, $mime];
        }
        return [$bytes, $mime];
    }

    /** @return array{0:string,1:string,2:int} byte, tipo MIME, larghezza */
    private static function uploadedImage(?array $upload): array
    {
        if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new PublicationException('Immagine mancante nella richiesta');
        }
        // Questi byte lasciano il server verso un servizio esterno: vanno
        // verificati almeno quanto quelli di un caricamento normale.
        if ($upload['size'] > self::MAX_UPLOAD) {
            throw new PublicationException('Immagine troppo grande per Telegram (limite 10 MB).');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new PublicationException('Formato immagine non supportato (usare PNG, JPEG o WEBP).');
        }
        $bytes = file_get_contents($upload['tmp_name']);
        $size = $bytes !== false ? getimagesizefromstring($bytes) : false;
        if (!$size) {
            throw new PublicationException('Immagine caricata non leggibile');
        }
        return [$bytes, $mime, (int) $size[0]];
    }

    /**
     * Direzione del nord rispetto all'alto della ripresa (gradi orari), se
     * la ripresa è georiferita. Un'area ruotata di θ in senso orario sulla
     * mappa viene salvata raddrizzata: il nord vi punta quindi a −θ.
     */
    private static function north(array $capture): ?float
    {
        $geo = Capture::resolveGeoRef($capture);
        return $geo ? -(float) ($geo['rotation'] ?? 0) : null;
    }

    private static function sentence(string $dateLabel, string $detail): string
    {
        $s = ucfirst($dateLabel);
        return $detail !== '' ? "$s · $detail" : $s;
    }

    private static function ext(string $mime): string
    {
        return ['image/png' => '.png', 'image/webp' => '.webp'][$mime] ?? '.jpg';
    }

    private static function it(string $iso): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m) ? "$m[3]/$m[2]/$m[1]" : $iso;
    }
}
