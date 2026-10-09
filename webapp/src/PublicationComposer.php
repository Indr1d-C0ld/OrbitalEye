<?php

/**
 * Composizione delle immagini da pubblicare, lato server (GD), in un solo
 * posto per tutte le condivisioni — Telegram, copia per X, anteprima — così
 * il formato è lo stesso ovunque:
 *  - 'none':  l'immagine così com'è;
 *  - 'strip': striscia in basso con data e fonte (ImageryAttribution::burnStrip);
 *  - 'card':  scheda di pubblicazione — immagine e fascia con titolo, data
 *             reale e sensore, barra di scala, freccia del nord e
 *             attribuzione richiesta dalla fonte;
 *  - 'pair':  scheda con Prima e Dopo affiancate (confronti).
 *
 * Nessuna coordinata nella scheda: come per le didascalie, la posizione la
 * aggiunge l'analista se e quando vuole.
 */
final class PublicationComposer
{
    private const FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
    private const FONT_BOLD = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
    /** Larghezza minima della scheda: sotto, i testi non stanno (un ritaglio
     * piccolo viene ingrandito). Massima: limite pratico delle foto Telegram. */
    private const MIN_WIDTH = 1000;
    private const MAX_WIDTH = 2560;
    /** Altezza massima della parte immagine: un ritaglio molto stretto non
     * viene ingrandito oltre (Telegram rifiuta foto con larghezza + altezza
     * oltre 10000 px o proporzioni oltre 20:1); se serve, si allarga la
     * fascia ai lati invece di ingrandire. */
    private const MAX_HEIGHT = 4000;
    /** Tetto alle immagini da comporre (GD tiene ~4 byte per pixel). */
    private const MAX_PIXELS = 40_000_000;
    /** Larghezza massima del file "a piena risoluzione". */
    private const DOCUMENT_MAX_WIDTH = 8000;

    public static function available(): bool
    {
        return function_exists('imagettftext') && is_file(self::FONT) && is_file(self::FONT_BOLD);
    }

    /**
     * @param array{title?:string, line?:string, credit?:string, mpp_x?:?float, north_deg?:?float} $info
     *   mpp_x: metri per pixel (asse orizzontale) dell'immagine passata;
     *   north_deg: direzione del nord rispetto all'alto dell'immagine, in
     *   gradi orari (0 = nord in alto), null se non nota.
     * @return array{0:string,1:string} [byte JPEG, tipo MIME]
     */
    /** @param bool $fullResolution senza il limite di larghezza delle foto
     *   Telegram: per il file allegato "a piena risoluzione". */
    public static function card(string $imageBytes, array $info, bool $fullResolution = false): array
    {
        $img = self::load($imageBytes);
        [$img, $scale] = self::fitWidth($img, $fullResolution);
        $mpp = !empty($info['mpp_x']) ? $info['mpp_x'] / $scale : null;
        return self::withFooter($img, $info, $mpp);
    }

    /**
     * Prima e Dopo affiancate (o una sopra l'altra se molto larghe), con
     * etichetta e data su ciascuna, e la stessa fascia della scheda.
     *
     * @param array{title?:string, line?:string, credit?:string, mpp_x?:?float, north_deg?:?float,
     *   label_a?:string, label_b?:string} $info  mpp_x riferito all'immagine A
     */
    public static function pair(string $bytesA, string $bytesB, array $info, bool $fullResolution = false): array
    {
        $a = self::load($bytesA);
        $b = self::load($bytesB);
        $wa = imagesx($a);
        $ha = imagesy($a);
        $vertical = $wa / max(1, $ha) > 1.6;
        $gap = 6;
        // B alla stessa scala di A (di norma è già allineata, stesse dimensioni).
        $b = self::resizeTo($b, $wa, (int) round(imagesy($b) * $wa / max(1, imagesx($b))));
        $hb = imagesy($b);
        $w = $vertical ? $wa : $wa * 2 + $gap;
        $h = $vertical ? $ha + $hb + $gap : max($ha, $hb);
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 11, 17, 24));
        imagecopy($canvas, $a, 0, 0, 0, 0, $wa, $ha);
        $bx = $vertical ? 0 : $wa + $gap;
        $by = $vertical ? $ha + $gap : 0;
        imagecopy($canvas, $b, $bx, $by, 0, 0, $wa, $hb);
        imagedestroy($a);
        imagedestroy($b);

        [$canvas, $scale, $offsetX] = self::fitWidth($canvas, $fullResolution);
        $k = self::k(imagesx($canvas));
        self::badge($canvas, (int) round($offsetX + 10 * $k), (int) round(10 * $k), $info['label_a'] ?? 'PRIMA', $k);
        self::badge($canvas, (int) round($offsetX + ($bx * $scale) + 10 * $k), (int) round(($by * $scale) + 10 * $k), $info['label_b'] ?? 'DOPO', $k);
        $mpp = !empty($info['mpp_x']) ? $info['mpp_x'] / $scale : null;
        return self::withFooter($canvas, $info, $mpp);
    }

    // ------------------------------------------------------------------

    private static function load(string $bytes)
    {
        $size = @getimagesizefromstring($bytes);
        if (!$size) {
            throw new RuntimeException('Immagine non leggibile per la composizione.');
        }
        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            throw new RuntimeException(sprintf('Immagine troppo grande da comporre (%d×%d pixel): scegli "Solo immagine" o un ritaglio.', $size[0], $size[1]));
        }
        // Una ripresa grande occupa centinaia di MB in GD: il limite
        // predefinito di PHP (128 MB) non basterebbe.
        $limit = ini_get('memory_limit');
        if ($limit !== '-1' && self::bytes($limit) < 768 * 1024 * 1024) {
            @ini_set('memory_limit', '768M');
        }
        $img = @imagecreatefromstring($bytes);
        if (!$img) {
            throw new RuntimeException('Immagine non leggibile per la composizione.');
        }
        if (($size[2] ?? 0) !== IMAGETYPE_PNG && ($size[2] ?? 0) !== IMAGETYPE_WEBP) {
            return $img; // JPEG: nessuna trasparenza, nessuna copia
        }
        // Trasparenza (zone senza dati, ritagli PNG): su fondo scuro.
        $w = imagesx($img);
        $h = imagesy($img);
        $out = imagecreatetruecolor($w, $h);
        imagefill($out, 0, 0, imagecolorallocate($out, 11, 17, 24));
        imagecopy($out, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);
        return $out;
    }

    private static function bytes(string $v): int
    {
        $n = (int) $v;
        return match (strtolower(substr(trim($v), -1))) {
            'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n,
        };
    }

    private static function resizeTo($img, int $w, int $h)
    {
        if (imagesx($img) === $w && imagesy($img) === $h) {
            return $img;
        }
        $out = imagecreatetruecolor(max(1, $w), max(1, $h));
        imagecopyresampled($out, $img, 0, 0, 0, 0, $w, $h, imagesx($img), imagesy($img));
        imagedestroy($img);
        return $out;
    }

    /**
     * Porta la larghezza fra MIN_WIDTH e MAX_WIDTH (o DOCUMENT_MAX_WIDTH per
     * il file a piena risoluzione), senza superare MAX_HEIGHT: un'immagine
     * stretta e alta resta al centro di una fascia più larga, non viene
     * ingrandita a dismisura.
     * @return array{0:\GdImage,1:float,2:int} immagine, fattore applicato, margine sinistro
     */
    private static function fitWidth($img, bool $fullResolution = false): array
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $maxW = $fullResolution ? self::DOCUMENT_MAX_WIDTH : self::MAX_WIDTH;
        $scale = max(self::MIN_WIDTH, min($maxW, $w)) / $w;
        $maxH = $fullResolution ? max(self::MAX_HEIGHT, $h) : self::MAX_HEIGHT;
        if ($h * $scale > $maxH) {
            $scale = $maxH / $h;
        }
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        if ($scale !== 1.0) {
            $img = self::resizeTo($img, $nw, $nh);
        }
        if ($nw >= self::MIN_WIDTH) {
            return [$img, $scale, 0];
        }
        $canvas = imagecreatetruecolor(self::MIN_WIDTH, $nh);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 11, 17, 24));
        $offset = intdiv(self::MIN_WIDTH - $nw, 2);
        imagecopy($canvas, $img, $offset, 0, 0, 0, $nw, $nh);
        imagedestroy($img);
        return [$canvas, $scale, $offset];
    }

    private static function k(int $width): float
    {
        return max(0.8, min(2.2, $width / 1200));
    }

    private static function withFooter($img, array $info, ?float $mpp): array
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $k = self::k($w);
        $pad = (int) round(18 * $k);
        $titleSize = 22 * $k;
        $lineSize = 15 * $k;
        $creditSize = 12 * $k;
        $title = trim((string) ($info['title'] ?? ''));
        $line = trim((string) ($info['line'] ?? ''));
        $credit = trim((string) ($info['credit'] ?? ''));

        // Spazio a destra per scala e nord; i testi vanno a capo prima.
        $rightW = (int) round(($mpp ? 300 : 0) * $k + (isset($info['north_deg']) ? 60 * $k : 0));
        $textW = $w - 2 * $pad - $rightW - ($rightW ? $pad : 0);
        $titleLines = $title !== '' ? self::wrap($title, self::FONT_BOLD, $titleSize, $textW, 2) : [];
        $lineLines = $line !== '' ? self::wrap($line, self::FONT, $lineSize, $textW, 3) : [];
        $creditLines = $credit !== '' ? self::wrap($credit, self::FONT, $creditSize, $w - 2 * $pad, 3) : [];
        $lh = fn(float $size) => (int) round($size * 1.75);
        $footerH = $pad + count($titleLines) * $lh($titleSize) + count($lineLines) * $lh($lineSize)
            + ($creditLines ? (int) round(8 * $k) + count($creditLines) * $lh($creditSize) : 0) + $pad;
        $footerH = max($footerH, (int) round(($mpp || isset($info['north_deg']) ? 110 : 60) * $k));

        $out = imagecreatetruecolor($w, $h + $footerH);
        $bg = imagecolorallocate($out, 11, 17, 24);
        imagefill($out, 0, 0, $bg);
        imagecopy($out, $img, 0, 0, 0, 0, $w, $h);
        imagedestroy($img);
        $cyan = imagecolorallocate($out, 0, 255, 242);
        $white = imagecolorallocate($out, 235, 242, 248);
        $grey = imagecolorallocate($out, 150, 165, 178);
        imagefilledrectangle($out, 0, $h, $w, $h + max(2, (int) round(3 * $k)) - 1, $cyan);

        $y = $h + $pad;
        foreach ($titleLines as $t) {
            $y += $lh($titleSize);
            imagettftext($out, $titleSize * 0.75, 0, $pad, $y - (int) round($titleSize * 0.45), $white, self::FONT_BOLD, $t);
        }
        foreach ($lineLines as $t) {
            $y += $lh($lineSize);
            imagettftext($out, $lineSize * 0.75, 0, $pad, $y - (int) round($lineSize * 0.45), $white, self::FONT, $t);
        }
        if ($creditLines) {
            $y += (int) round(8 * $k);
            foreach ($creditLines as $t) {
                $y += $lh($creditSize);
                imagettftext($out, $creditSize * 0.75, 0, $pad, $y - (int) round($creditSize * 0.45), $grey, self::FONT, $t);
            }
        }

        $right = $w - $pad;
        if (isset($info['north_deg'])) {
            self::northArrow($out, $right - (int) round(22 * $k), $h + (int) round(60 * $k), 20 * $k, (float) $info['north_deg'], $white);
            $right -= (int) round(60 * $k);
        }
        if ($mpp) {
            self::scaleBar($out, $right, $h + (int) round(40 * $k), $mpp, $w, $k, $white);
        }

        ob_start();
        imagejpeg($out, null, 92);
        $bytes = ob_get_clean();
        imagedestroy($out);
        return [$bytes, 'image/jpeg'];
    }

    /** Righe che stanno in $maxW pixel, al più $maxLines (l'ultima con "…"). */
    private static function wrap(string $text, string $font, float $size, int $maxW, int $maxLines): array
    {
        $width = function (string $s) use ($font, $size): int {
            $b = imagettfbbox($size * 0.75, 0, $font, $s);
            return abs($b[2] - $b[0]);
        };
        $lines = [];
        foreach (preg_split('/\R/u', $text) as $paragraph) {
            $current = '';
            foreach (preg_split('/\s+/u', trim($paragraph)) as $word) {
                if ($word === '') {
                    continue;
                }
                // Una parola più larga della riga (un hashtag, un indirizzo)
                // si spezza: altrimenti finirebbe sopra la scala e fuori.
                while ($width($word) > $maxW && mb_strlen($word) > 1) {
                    $cut = mb_strlen($word) - 1;
                    while ($cut > 1 && $width(mb_substr($word, 0, $cut)) > $maxW) {
                        $cut--;
                    }
                    if ($current !== '') {
                        $lines[] = $current;
                        $current = '';
                    }
                    $lines[] = mb_substr($word, 0, $cut);
                    $word = mb_substr($word, $cut);
                }
                $try = $current === '' ? $word : "$current $word";
                if ($current !== '' && $width($try) > $maxW) {
                    $lines[] = $current;
                    $current = $word;
                } else {
                    $current = $try;
                }
            }
            if ($current !== '') {
                $lines[] = $current;
            }
        }
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, 0, $maxLines);
            $last = $lines[$maxLines - 1];
            while ($last !== '' && $width($last . '…') > $maxW) {
                $last = mb_substr($last, 0, -1);
            }
            $lines[$maxLines - 1] = rtrim($last) . '…';
        }
        return $lines;
    }

    /** Barra di scala a due segmenti, allineata a destra in $right. */
    private static function scaleBar($img, int $right, int $top, float $mpp, int $imageW, float $k, int $color): void
    {
        $target = $mpp * min($imageW * 0.22, 260 * $k);
        $nice = 0;
        foreach ([1, 2, 5] as $m) {
            for ($e = -2; $e <= 6; $e++) {
                $v = $m * 10 ** $e;
                if ($v <= $target && $v > $nice) {
                    $nice = $v;
                }
            }
        }
        if ($nice <= 0) {
            return;
        }
        $px = (int) round($nice / $mpp);
        if ($px < 20) {
            return;
        }
        $barH = max(4, (int) round(7 * $k));
        $left = $right - $px;
        $black = imagecolorallocate($img, 20, 20, 20);
        imagefilledrectangle($img, $left, $top, $left + intdiv($px, 2), $top + $barH, $color);
        imagefilledrectangle($img, $left + intdiv($px, 2), $top, $right, $top + $barH, $black);
        imagerectangle($img, $left, $top, $right, $top + $barH, $color);
        $label = $nice >= 1000 ? str_replace('.', ',', (string) ($nice / 1000)) . ' km'
            : ($nice < 1 ? str_replace('.', ',', (string) round($nice * 100)) . ' cm' : str_replace('.', ',', (string) $nice) . ' m');
        $size = 13 * $k * 0.75;
        $b = imagettfbbox($size, 0, self::FONT, $label);
        imagettftext($img, $size, 0, $right - abs($b[2] - $b[0]), $top + $barH + (int) round(22 * $k), $color, self::FONT, $label);
        imagettftext($img, $size, 0, $left, $top + $barH + (int) round(22 * $k), $color, self::FONT, '0');
    }

    /** Freccia del nord ruotata di $deg gradi in senso orario. */
    private static function northArrow($img, int $cx, int $cy, float $r, float $deg, int $color): void
    {
        $rot = function (float $x, float $y) use ($cx, $cy, $deg): array {
            $a = deg2rad($deg);
            return [(int) round($cx + $x * cos($a) - $y * sin($a)), (int) round($cy + $x * sin($a) + $y * cos($a))];
        };
        $tip = $rot(0, -$r);
        $l = $rot(-$r * 0.45, $r * 0.6);
        $m = $rot(0, $r * 0.25);
        $rr = $rot($r * 0.45, $r * 0.6);
        imagefilledpolygon($img, [$tip[0], $tip[1], $l[0], $l[1], $m[0], $m[1]], $color);
        imagepolygon($img, [$tip[0], $tip[1], $m[0], $m[1], $rr[0], $rr[1]], $color);
        $n = $rot(0, -$r * 1.55);
        $size = $r * 0.55;
        $b = imagettfbbox($size, 0, self::FONT_BOLD, 'N');
        imagettftext($img, $size, 0, $n[0] - intdiv(abs($b[2] - $b[0]), 2), $n[1] + intdiv(abs($b[7] - $b[1]), 2), $color, self::FONT_BOLD, 'N');
    }

    private static function badge($img, int $x, int $y, string $text, float $k): void
    {
        $size = 14 * $k * 0.75;
        $b = imagettfbbox($size, 0, self::FONT_BOLD, $text);
        $tw = abs($b[2] - $b[0]);
        $th = abs($b[7] - $b[1]);
        $p = (int) round(7 * $k);
        imagealphablending($img, true);
        imagefilledrectangle($img, $x, $y, $x + $tw + 2 * $p, $y + $th + 2 * $p, imagecolorallocatealpha($img, 11, 17, 24, 30));
        imagettftext($img, $size, 0, $x + $p, $y + $p + $th, imagecolorallocate($img, 0, 255, 242), self::FONT_BOLD, $text);
    }
}
