<?php

/**
 * Provenienza di una ripresa — data reale dell'immagine e attribuzione della
 * fonte — nei formati che servono a chi pubblica: una riga per la didascalia
 * e una striscia breve da incorporare nell'immagine condivisa.
 *
 * Le riprese derivate (copie migliorate, "salva come nuova ripresa",
 * ritagli, indici spettrali) non hanno una fonte propria: si risale la
 * catena source_capture_id fino all'originale. Un ritaglio di una ripresa
 * Esri che ha i metadati delle proprie acquisizioni (calcolati sull'area del
 * ritaglio) usa quelli, più precisi di quelli dell'intera sorgente.
 *
 * Testi di attribuzione:
 *  - Esri: frase richiesta dai termini d'uso delle immagini statiche
 *    (https://doc.arcgis.com/en/arcgis-online/reference/static-maps.htm),
 *    in inglese come nell'originale, più il fornitore delle immagini;
 *  - Copernicus: dicitura richiesta dalla Sentinel Data Legal Notice per
 *    dati elaborati ("Contains modified Copernicus Sentinel data [anno]").
 */
final class ImageryAttribution
{
    public const ESRI_TERMS_URL = 'https://doc.arcgis.com/en/arcgis-online/reference/static-maps.htm';
    public const ESRI_PERMISSION_URL = 'https://www.esri.com/en-us/legal/copyright-inquiry';

    /**
     * @return array{kind:string, is_esri:bool, date:?string, date_label:string,
     *   detail:string, strip:string, caption_line:string, credit:string}
     */
    public static function forCapture(array $capture): array
    {
        $origin = self::resolveOrigin($capture);
        $meta = $origin['meta'];

        if ($origin['kind'] === 'esri') {
            return self::esri($meta['esri_imagery'] ?? null);
        }
        if ($origin['kind'] === 'sentinel') {
            return self::sentinel($meta['date_from'] ?? null, $meta['date_to'] ?? null, $origin['capture']);
        }
        if ($origin['kind'] === 'custom') {
            $text = trim((string) $meta['attribution']);
            $date = $origin['capture']['capture_date'] ?? null;
            return self::result('custom', false, $date,
                $date ? 'immagine del ' . self::it($date) : '',
                '',
                $date ? 'Immagine ' . self::it($date) : '',
                'Fonte: ' . $text . '.',
                'Fonte: ' . $text);
        }
        $date = $origin['capture']['capture_date'] ?? null;
        return self::result('unknown', false, $date,
            $date ? 'immagine del ' . self::it($date) : '', '',
            $date ? 'Immagine ' . self::it($date) : '', '', '');
    }

    /**
     * Provenienza combinata di più riprese (un confronto prima/dopo):
     * date di entrambe e attribuzioni di tutte le fonti coinvolte.
     *
     * @param array<string,array> $labeled es. ['Prima' => $captureA, 'Dopo' => $captureB]
     */
    public static function forCaptures(array $labeled): array
    {
        $parts = [];
        $stripParts = [];
        $credits = [];
        $captionCredits = [];
        $isEsri = false;
        foreach ($labeled as $name => $capture) {
            $info = self::forCapture($capture);
            $isEsri = $isEsri || $info['is_esri'];
            $parts[] = $name . ': ' . ($info['date_label'] ?: 'data non nota');
            $stripParts[] = $name . ' ' . ($info['date'] ? self::it($info['date']) : 'data non nota');
            if ($info['credit'] !== '') {
                $credits[$info['credit']] = true;
            }
            if ($info['caption_line'] !== '') {
                // Solo la parte di attribuzione: le date sono già in $parts.
                $captionCredits[self::creditSentence($info)] = true;
            }
        }
        $strip = implode(' · ', $stripParts);
        if ($credits) {
            $strip .= ' · ' . implode(' · ', array_keys($credits));
        }
        return [
            'is_esri' => $isEsri,
            'date_label' => implode(' — ', $parts),
            'strip' => $strip,
            'caption_line' => implode("\n", array_filter(array_merge(
                [ucfirst(implode('; ', $parts)) . '.'],
                array_keys(array_filter($captionCredits, fn($k) => $k !== '', ARRAY_FILTER_USE_KEY))
            ))),
        ];
    }

    /** Etichetta predefinita di una ripresa Esri: data dell'immagine, non
     * del download (che resta nei metadati come fetched_at). */
    public static function esriCaptureLabel(?array $imagery): string
    {
        if (!$imagery || empty($imagery['acquisitions'])) {
            return 'Esri World Imagery — data immagine non disponibile (scaricata il ' . date('d/m/Y') . ')';
        }
        $min = $imagery['date_min'];
        $max = $imagery['date_max'];
        return $min === $max
            ? 'Esri World Imagery — immagine del ' . self::it($min)
            : 'Esri World Imagery — immagini dal ' . self::it($min) . ' al ' . self::it($max);
    }

    /** Font per la striscia scritta lato server (pacchetto fonts-dejavu). */
    private const STRIP_FONTS = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/TTF/DejaVuSans.ttf',
    ];

    /**
     * Scrive la striscia di provenienza su un'immagine lato server — per le
     * immagini che partono direttamente dal server (confronti, riepilogo di
     * studio inviati a Telegram). Stesso aspetto di drawAttributionStrip()
     * in common.js. Se il font o GD non sono disponibili restituisce null:
     * l'attribuzione resta comunque nella didascalia.
     *
     * @return array{0:string,1:string}|null [byte JPEG, tipo MIME]
     */
    public static function burnStrip(string $imageBytes, string $text): ?array
    {
        $font = null;
        foreach (self::STRIP_FONTS as $candidate) {
            if (is_file($candidate)) {
                $font = $candidate;
                break;
            }
        }
        if ($text === '' || !$font || !function_exists('imagettftext')) {
            return null;
        }
        $img = @imagecreatefromstring($imageBytes);
        if (!$img) {
            return null;
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $fontPx = max(10, min(22, (int) round($w * 0.018)));
        $pad = (int) round($fontPx * 0.5);
        // GD misura il carattere in punti a 96 dpi: px = pt × 96/72.
        $textWidth = function (int $px) use ($font, $text): int {
            $box = imagettfbbox($px * 0.75, 0, $font, $text);
            return abs($box[2] - $box[0]);
        };
        while ($fontPx > 8 && $textWidth($fontPx) > $w - 2 * $pad) {
            $fontPx--;
        }
        $stripH = $fontPx + 2 * $pad;
        imagealphablending($img, true);
        $bg = imagecolorallocatealpha($img, 0, 0, 0, 48); // ≈ 62% di opacità
        imagefilledrectangle($img, 0, $h - $stripH, $w, $h, $bg);
        $white = imagecolorallocate($img, 255, 255, 255);
        $box = imagettfbbox($fontPx * 0.75, 0, $font, $text);
        $x = $w - $pad - abs($box[2] - $box[0]);
        $textH = abs($box[7] - $box[1]);
        $y = (int) round($h - $stripH / 2 + $textH / 2);
        imagettftext($img, $fontPx * 0.75, 0, max($pad, $x), $y, $white, $font, $text);
        ob_start();
        imagejpeg($img, null, 92);
        $out = ob_get_clean();
        imagedestroy($img);
        return [$out, 'image/jpeg'];
    }

    // ------------------------------------------------------------------

    private static function esri(?array $imagery): array
    {
        $year = date('Y');
        $legal = 'Map image is the intellectual property of Esri and is used herein under license. '
            . "Copyright © $year Esri and its licensors. All rights reserved.";
        if (!$imagery || empty($imagery['acquisitions'])) {
            return self::result('esri', true, null, 'data dell\'immagine non nota', '',
                'Data immagine non nota',
                "Data dell'immagine non nota.\n" . $legal,
                '© Esri e licenzianti');
        }
        $acq = $imagery['acquisitions'];
        $dom = $acq[0];
        $providers = array_values(array_unique(array_filter(array_map(fn($a) => $a['provider'] ?? '', $acq))));
        $credit = '© Esri' . ($providers ? ', ' . implode(', ', $providers) : '');
        $detail = trim(implode(' · ', array_filter([
            $dom['sensor_name'] ?? '',
            isset($dom['resolution_m']) ? str_replace('.', ',', rtrim(rtrim(sprintf('%.2f', $dom['resolution_m']), '0'), ',.')) . ' m' : '',
            $dom['provider'] ?? '',
        ])));

        $min = $imagery['date_min'] ?? $dom['date'];
        $max = $imagery['date_max'] ?? $dom['date'];
        if ($min === $max) {
            $dateLabel = 'immagine del ' . self::it($dom['date']);
            $stripDate = 'Immagine ' . self::it($dom['date']) . ($dom['sensor_name'] ? ' · ' . $dom['sensor_name'] : '');
            $captionDate = 'Immagine del ' . self::it($dom['date']) . ' (' . $detail . ').';
        } else {
            $dateLabel = 'mosaico di ' . count($acq) . ' acquisizioni dal ' . self::it($min) . ' al ' . self::it($max)
                . ' (prevalente: ' . self::it($dom['date']) . ', ' . round($dom['coverage'] * 100) . '% dell\'area)';
            $stripDate = 'Immagini ' . self::it($min) . '–' . self::it($max);
            $captionDate = 'Mosaico di ' . count($acq) . ' acquisizioni dal ' . self::it($min) . ' al ' . self::it($max)
                . ' (prevalente: ' . self::it($dom['date']) . ', ' . $detail . ').';
        }
        // Area aggiornata da Esri proprio a cavallo del download: non si può
        // sapere quale versione sia stata scaricata, e va detto.
        if (isset($imagery['certain']) && $imagery['certain'] === false && !empty($imagery['alternative']['dominant_date'])) {
            $alt = self::it($imagery['alternative']['dominant_date']);
            $dateLabel .= " — data incerta, oppure $alt";
            $stripDate .= " (o $alt)";
            $captionDate .= " Data incerta: l'area è stata aggiornata da Esri intorno al giorno del download (in alternativa $alt).";
        }
        return self::result('esri', true, $dom['date'], $dateLabel, $detail,
            $stripDate,
            $captionDate . "\n" . $legal,
            $credit);
    }

    private static function sentinel(?string $from, ?string $to, array $capture): array
    {
        $to = $to ?: ($capture['capture_date'] ?? null);
        $year = $to ? substr($to, 0, 4) : date('Y');
        $credit = "Contains modified Copernicus Sentinel data $year";
        if ($from && $to && $from !== $to) {
            $label = 'Sentinel-2, mosaico dal ' . self::it($from) . ' al ' . self::it($to);
            $strip = 'Sentinel-2 ' . self::it($from) . '–' . self::it($to);
        } elseif ($to) {
            $label = 'Sentinel-2 del ' . self::it($to);
            $strip = 'Sentinel-2 ' . self::it($to);
        } else {
            $label = 'Sentinel-2';
            $strip = 'Sentinel-2';
        }
        return self::result('sentinel', false, $to, $label, 'Sentinel-2 L2A · 10 m',
            $strip,
            ucfirst($label) . ".\n" . $credit . '.',
            $credit);
    }

    /** Segue la catena delle riprese derivate fino a quella che porta
     * l'informazione di provenienza. */
    private static function resolveOrigin(array $capture): array
    {
        $seen = [];
        $current = $capture;
        while ($current && !isset($seen[(int) $current['id']])) {
            $seen[(int) $current['id']] = true;
            $meta = json_decode($current['meta_json'] ?? '', true);
            $meta = is_array($meta) ? $meta : [];
            if (!empty($meta['esri_imagery']) || ($current['source'] ?? '') === 'esri') {
                return ['kind' => 'esri', 'meta' => $meta, 'capture' => $current];
            }
            if (($current['source'] ?? '') === 'sentinelhub') {
                return ['kind' => 'sentinel', 'meta' => $meta, 'capture' => $current];
            }
            if (!empty($meta['attribution'])) {
                return ['kind' => 'custom', 'meta' => $meta, 'capture' => $current];
            }
            if (empty($meta['source_capture_id'])) {
                break;
            }
            $current = Capture::find((int) $meta['source_capture_id']);
        }
        return ['kind' => 'unknown', 'meta' => [], 'capture' => $capture];
    }

    private static function result(string $kind, bool $isEsri, ?string $date, string $dateLabel, string $detail,
        string $stripDate, string $captionLine, string $credit): array
    {
        return [
            'kind' => $kind,
            'is_esri' => $isEsri,
            'date' => $date,
            'date_label' => $dateLabel,
            'detail' => $detail,
            'strip_date' => $stripDate,
            'strip' => implode(' · ', array_filter([$stripDate, $credit])),
            'caption_line' => $captionLine,
            'credit' => $credit,
        ];
    }

    /** Solo la frase di attribuzione (senza data) di una provenienza. */
    private static function creditSentence(array $info): string
    {
        $lines = explode("\n", $info['caption_line']);
        return count($lines) > 1 ? end($lines) : ($info['kind'] === 'custom' ? $info['caption_line'] : '');
    }

    private static function it(?string $isoDate): string
    {
        if (!$isoDate || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $isoDate, $m)) {
            return (string) $isoDate;
        }
        return "$m[3]/$m[2]/$m[1]";
    }
}
