<?php

/**
 * Ritaglio di un'area di interesse RUOTATA a partire da un'immagine più
 * ampia scaricata "dritta" (nord in alto, come restituita da Sentinel Hub /
 * Esri: sono sempre proiezioni equirettangolari della bbox richiesta, pixel
 * linearmente proporzionali a lon/lat — stessa assunzione già usata altrove
 * nella piattaforma per il calcolo della scala, vedi Capture/meta_json
 * bbox e la correzione di aspect ratio in esri_client.py).
 *
 * Il flusso (vedi api/fetch_capture.php) è: 1) l'utente disegna un
 * rettangolo "di base" e lo ruota visivamente sulla mappa; 2) il server
 * calcola il bounding box (non ruotato) che racchiude quel rettangolo
 * ruotato e scarica QUELLO (più ampio, a risoluzione pixel aumentata di
 * conseguenza per mantenere lo stesso metri/pixel); 3) questa funzione
 * ruota l'intera immagine scaricata in modo da rendere il rettangolo
 * originale di nuovo dritto, e ne ritaglia esattamente le dimensioni.
 *
 * Convenzione rotazione: gradi, positivo = orario come visto sulla mappa
 * (stessa di CSS/canvas rotate() già usata per l'overlay in analyze.js).
 */
final class ImageRotateCrop
{
    /**
     * Calcola il bounding box (non ruotato) che racchiude per intero il
     * rettangolo $rect ruotato di $rotationDeg attorno al proprio centro.
     * Simmetrico rispetto al segno dell'angolo (racchiudere un rettangolo
     * ruotato di +θ o -θ dà lo stesso bbox): il verso esatto della
     * rotazione conta solo più avanti, nel ritaglio vero e proprio.
     *
     * Approssimazione "piatta" (lon/lat trattate come piano cartesiano,
     * nessuna conversione in metri): stessa semplificazione già accettata
     * altrove in questo progetto per aree di interesse di dimensioni
     * ragionevoli (poche decine di km); un piccolo margine di sicurezza
     * (3%) assorbe l'errore di questa approssimazione e gli arrotondamenti
     * pixel del ritaglio finale.
     */
    public static function enclosingBbox(array $rect, float $rotationDeg): array
    {
        [$minLon, $minLat, $maxLon, $maxLat] = $rect;
        $cLon = ($minLon + $maxLon) / 2;
        $cLat = ($minLat + $maxLat) / 2;
        $halfLon = ($maxLon - $minLon) / 2;
        $halfLat = ($maxLat - $minLat) / 2;

        $rad = deg2rad($rotationDeg);
        $cos = abs(cos($rad));
        $sin = abs(sin($rad));
        $margin = 1.03; // +3% di sicurezza (arrotondamenti + approssimazione piatta)

        $encHalfLon = ($halfLon * $cos + $halfLat * $sin) * $margin;
        $encHalfLat = ($halfLon * $sin + $halfLat * $cos) * $margin;

        return [$cLon - $encHalfLon, $cLat - $encHalfLat, $cLon + $encHalfLon, $cLat + $encHalfLat];
    }

    /**
     * Dato il bbox effettivamente da scaricare (più ampio) e quello
     * originale del rettangolo di base, calcola la larghezza/altezza in
     * pixel da richiedere al servizio di fetch: abbastanza dense da coprire
     * la risoluzione voluta ($baseWidthPx x $baseHeightPx sul rettangolo
     * originale) su ENTRAMBI gli assi, nello stesso rapporto d'aspetto in
     * gradi di $fetchBbox.
     *
     * Il rapporto d'aspetto deve combaciare con quello di $fetchBbox — non
     * con quello (spesso diverso, specie dopo l'espansione per la
     * rotazione) di $rect — altrimenti Esri applica una SUA correzione
     * indipendente (vedi _adjust_bbox_to_aspect in esri_client.py, pensata
     * per il caso normale non ruotato) che espande ulteriormente il bbox in
     * modo non prevedibile da qui: prima di questo fix è capitato che
     * un'area molto allungata (rapporto 2.5:1) ruotata anche di pochi gradi
     * desse in uscita un ritaglio 1024×409 invece di 1024×1024 — non
     * distorto, ma con una risoluzione reale su un asse molto più bassa del
     * previsto. (`rotateAndCrop()` inoltre ora RICAMPIONA sempre al
     * risultato esattamente $baseWidthPx x $baseHeightPx, quindi anche in
     * caso di ulteriori sorprese a monte la ripresa salvata mantiene le
     * dimensioni promesse.)
     *
     * Il risultato è limitato a $maxPx per lato: oltre certi angoli/rapporti
     * d'aspetto molto estremi la risoluzione effettiva della sorgente può
     * risultare inferiore al nominale (poi ricampionata comunque alla
     * dimensione richiesta) — limite noto, accettato per evitare richieste
     * enormi ai provider.
     */
    public static function scaledFetchSize(array $rect, array $fetchBbox, int $baseWidthPx, int $baseHeightPx, int $maxPx = 2048): array
    {
        $rectWidthDeg = $rect[2] - $rect[0];
        $rectHeightDeg = $rect[3] - $rect[1];
        $fetchWidthDeg = $fetchBbox[2] - $fetchBbox[0];
        $fetchHeightDeg = $fetchBbox[3] - $fetchBbox[1];

        // Densità (pixel/grado) voluta su ciascun asse per il rettangolo
        // originale; si usa la maggiore delle due per non sotto-risolvere
        // nessuno dei due assi, applicata a ENTRAMBE le dimensioni di
        // $fetchBbox — così facendo l'aspect ratio richiesto combacia
        // sempre esattamente con quello di $fetchBbox.
        $pxPerDegLon = $baseWidthPx / $rectWidthDeg;
        $pxPerDegLat = $baseHeightPx / $rectHeightDeg;
        $pxPerDeg = max($pxPerDegLon, $pxPerDegLat);

        $w = $pxPerDeg * $fetchWidthDeg;
        $h = $pxPerDeg * $fetchHeightDeg;

        // Se il limite scatta, riscala ENTRAMBE le dimensioni dello stesso
        // fattore invece di limitarle indipendentemente: preserva l'aspect
        // ratio di $fetchBbox anche sotto clamp (altrimenti si ricrea lo
        // stesso disallineamento che questa funzione esiste per evitare).
        $scaleDown = min(1.0, $maxPx / max($w, $h));
        $w = max(64, (int) round($w * $scaleDown));
        $h = max(64, (int) round($h * $scaleDown));

        return [$w, $h];
    }

    /**
     * Ruota l'immagine scaricata (che copre $fetchedBbox) in modo da
     * raddrizzare il rettangolo $rect ruotato di $rotationDeg, e ne
     * ritaglia esattamente le dimensioni. Geometria verificata con test
     * pixel dedicato (marker sintetico) per più angoli — vedi CHANGELOG.
     *
     * @param string $imageBytes Bytes dell'immagine scaricata (formato $format)
     * @param string $format     'png' o 'jpg'/'jpeg'
     * @param array  $fetchedBbox Bbox effettivamente coperta dall'immagine [minLon,minLat,maxLon,maxLat]
     * @param array  $rect        Rettangolo di base originale (non ruotato) [minLon,minLat,maxLon,maxLat]
     * @param float  $rotationDeg Rotazione richiesta dall'utente (gradi, positivo = orario)
     * @param int    $targetWidthPx  Larghezza finale garantita del file salvato
     * @param int    $targetHeightPx Altezza finale garantita del file salvato
     * @return string Bytes dell'immagine ritagliata, stesso formato in ingresso
     */
    public static function rotateAndCrop(string $imageBytes, string $format, array $fetchedBbox, array $rect, float $rotationDeg, int $targetWidthPx, int $targetHeightPx): string
    {
        $src = @imagecreatefromstring($imageBytes);
        if ($src === false) {
            throw new RuntimeException('Impossibile decodificare l\'immagine scaricata per il ritaglio ruotato.');
        }

        $fw = imagesx($src);
        $fh = imagesy($src);
        $pxPerLonX = $fw / ($fetchedBbox[2] - $fetchedBbox[0]);
        $pxPerLatY = $fh / ($fetchedBbox[3] - $fetchedBbox[1]);

        $cLon = ($rect[0] + $rect[2]) / 2;
        $cLat = ($rect[1] + $rect[3]) / 2;
        $cxPx = ($cLon - $fetchedBbox[0]) * $pxPerLonX;
        $cyPx = ($fetchedBbox[3] - $cLat) * $pxPerLatY; // Y invertita: riga 0 = lat massima
        $halfWpx = ($rect[2] - $rect[0]) / 2 * $pxPerLonX;
        $halfHpx = ($rect[3] - $rect[1]) / 2 * $pxPerLatY;

        $isPng = str_starts_with(strtolower($format), 'png');
        if ($isPng) {
            imagesavealpha($src, true);
            $bg = imagecolorallocatealpha($src, 0, 0, 0, 127);
        } else {
            // Il JPEG non supporta la trasparenza: un riempimento neutro va
            // bene perché, se il bbox allargato è stato calcolato
            // correttamente (vedi enclosingBbox, con il suo margine di
            // sicurezza), quest'area di riempimento resta sempre fuori dal
            // ritaglio finale.
            $bg = imagecolorallocate($src, 0, 0, 0);
        }

        // GD ruota in senso ANTIORARIO per angoli positivi (documentato);
        // la nostra convenzione (positivo = orario, come CSS/canvas) è
        // quindi già quella giusta da passare direttamente a imagerotate
        // per raddrizzare un rettangolo inclinato di +rotationDeg in senso
        // orario — verificato numericamente con test pixel dedicato.
        $rotated = imagerotate($src, $rotationDeg, $bg);
        if ($rotated === false) {
            throw new RuntimeException('Rotazione immagine fallita.');
        }
        if ($isPng) imagesavealpha($rotated, true);
        $nw = imagesx($rotated);
        $nh = imagesy($rotated);

        // Dove finisce il centro del rettangolo dopo la rotazione: stessa
        // trasformazione di imagerotate ma applicata a un singolo punto,
        // nel verso inverso (-rotationDeg) perché stiamo tracciando dove
        // VA un punto fisso della scena, non ruotando l'immagine stessa.
        $rad = deg2rad(-$rotationDeg);
        $dx = $cxPx - $fw / 2;
        $dy = $cyPx - $fh / 2;
        $dxRot = $dx * cos($rad) - $dy * sin($rad);
        $dyRot = $dx * sin($rad) + $dy * cos($rad);
        $newCx = $nw / 2 + $dxRot;
        $newCy = $nh / 2 + $dyRot;

        $cropW = max(1, (int) round($halfWpx * 2));
        $cropH = max(1, (int) round($halfHpx * 2));
        $srcX = (int) round($newCx - $halfWpx);
        $srcY = (int) round($newCy - $halfHpx);

        $cropped = imagecreatetruecolor($cropW, $cropH);
        if ($isPng) {
            imagealphablending($cropped, false);
            imagesavealpha($cropped, true);
        }
        imagecopy($cropped, $rotated, 0, 0, $srcX, $srcY, $cropW, $cropH);

        // Ricampiona sempre alla dimensione finale RICHIESTA: la geometria
        // sopra ($cropW/$cropH) riflette la vera risoluzione ottenuta
        // dall'immagine scaricata, che può differire leggermente da quella
        // nominale (arrotondamenti, clamp di scaledFetchSize() su angoli
        // estremi, eventuali ulteriori correzioni d'aspetto del provider) —
        // così facendo la ripresa salvata ha SEMPRE esattamente le
        // dimensioni promesse, come per ogni altra ripresa della
        // piattaforma (non ruotata inclusa), e il calcolo della scala
        // (meta_json.bbox=rect ÷ dimensioni immagine) resta coerente.
        $out = imagecreatetruecolor($targetWidthPx, $targetHeightPx);
        if ($isPng) {
            imagealphablending($out, false);
            imagesavealpha($out, true);
        }
        imagecopyresampled($out, $cropped, 0, 0, 0, 0, $targetWidthPx, $targetHeightPx, $cropW, $cropH);
        imagedestroy($cropped);

        ob_start();
        if ($isPng) {
            imagepng($out);
        } else {
            imagejpeg($out, null, 92);
        }
        $bytes = ob_get_clean();

        imagedestroy($src);
        imagedestroy($rotated);
        imagedestroy($out);

        return $bytes;
    }
}
