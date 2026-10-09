<?php

final class TelegramException extends RuntimeException
{
}

/**
 * Client minimale per l'API bot di Telegram (https://core.telegram.org/bots/api),
 * usato dalla condivisione (api/share.php) e dalla coda di revisione
 * (api/publications.php): foto, album, documenti e messaggi verso il
 * canale/chat configurato in Impostazioni o verso la chat di revisione.
 *
 * Nessuna dipendenza dal python-service: chiamate HTTP dirette all'API
 * pubblica di Telegram, token e chat_id letti da AppSettings. A differenza
 * di Sentinel Hub/Esri, il token non deve mai raggiungere il python-service.
 *
 * Il bot non riceve aggiornamenti (niente webhook né getUpdates): i pulsanti
 * nei messaggi sono solo link. Così lo stesso bot può servire anche altri
 * programmi senza conflitti, e non c'è un endpoint pubblico da esporre.
 */
final class TelegramClient
{
    /** Limite di Telegram per le didascalie. */
    public const CAPTION_LIMIT = 1024;

    /** Lunghezza come la conta Telegram (unità UTF-16: un'emoji vale 2). */
    public static function textLength(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    private string $token;
    private string $chatId;

    /** @param string|null $chatId destinazione diversa da quella predefinita (es. la chat di revisione) */
    public function __construct(?string $chatId = null)
    {
        $settings = AppSettings::all();
        $this->token = $settings['telegram_bot_token'];
        $this->chatId = $chatId ?? $settings['telegram_chat_id'];
        if ($this->token === '' || $this->chatId === '') {
            throw new TelegramException('Telegram non configurato: imposta token del bot e ID canale/chat in Impostazioni.');
        }
    }

    /** Client verso la chat di revisione, o null se non configurata. */
    public static function forReview(): ?self
    {
        $id = trim((string) (AppSettings::all()['telegram_review_chat_id'] ?? ''));
        return $id === '' ? null : new self($id);
    }

    /**
     * Invia una foto con didascalia.
     * $imageBytes: contenuto binario dell'immagine (jpg/png).
     * $buttons: pulsanti-link sotto il messaggio, [[testo, url], ...].
     */
    public function sendPhoto(string $imageBytes, string $caption, string $filename = 'condivisione.jpg', string $mimeType = 'image/jpeg', array $buttons = [], ?int $replyTo = null): array
    {
        return $this->call('sendPhoto', array_filter([
            'chat_id' => $this->chatId,
            'caption' => $caption,
            'reply_markup' => self::markup($buttons),
            'reply_to_message_id' => $replyTo,
        ], fn($v) => $v !== null), ['photo' => [$imageBytes, $filename, $mimeType]], 60);
    }

    /**
     * Album di 2–10 immagini (sendMediaGroup), didascalia sulla prima.
     *
     * @param array<int,array{0:string,1:string,2:string}> $items [byte, nome file, tipo MIME]
     * @return array<int,array> i messaggi creati
     */
    public function sendMediaGroup(array $items, string $caption, ?int $replyTo = null): array
    {
        if (count($items) < 2 || count($items) > 10) {
            throw new TelegramException('Un album Telegram contiene da 2 a 10 immagini.');
        }
        $media = [];
        $files = [];
        foreach (array_values($items) as $i => [$bytes, $name, $mime]) {
            $key = 'f' . $i;
            $entry = ['type' => 'photo', 'media' => 'attach://' . $key];
            if ($i === 0 && $caption !== '') {
                $entry['caption'] = $caption;
            }
            $media[] = $entry;
            $files[$key] = [$bytes, $name, $mime];
        }
        return $this->call('sendMediaGroup', array_filter([
            'chat_id' => $this->chatId,
            'media' => json_encode($media),
            'reply_to_message_id' => $replyTo,
        ], fn($v) => $v !== null), $files, 120);
    }

    /**
     * Un file così com'è (senza la compressione che Telegram applica alle
     * foto): la versione a piena risoluzione di ciò che si pubblica.
     */
    public function sendDocument(string $bytes, string $filename, string $mimeType, string $caption = '', ?int $replyTo = null): array
    {
        return $this->call('sendDocument', array_filter([
            'chat_id' => $this->chatId,
            'caption' => $caption !== '' ? $caption : null,
            'reply_to_message_id' => $replyTo,
        ], fn($v) => $v !== null), ['document' => [$bytes, $filename, $mimeType]], 120);
    }

    /** Solo testo (test dalle Impostazioni, esito della revisione). */
    public function sendMessage(string $text, ?int $replyTo = null, array $buttons = []): array
    {
        return $this->call('sendMessage', array_filter([
            'chat_id' => $this->chatId,
            'text' => $text,
            'reply_to_message_id' => $replyTo,
            'reply_markup' => self::markup($buttons),
        ], fn($v) => $v !== null), [], 15);
    }

    /** Tastiera di pulsanti-link (url): nessun aggiornamento da gestire. */
    private static function markup(array $buttons): ?string
    {
        if (!$buttons) {
            return null;
        }
        return json_encode(['inline_keyboard' => array_map(fn($b) => [['text' => $b[0], 'url' => $b[1]]], $buttons)]);
    }

    /**
     * @param array<string,array{0:string,1:string,2:string}> $files campo => [byte, nome file, tipo MIME]
     */
    private function call(string $method, array $fields, array $files, int $timeout): array
    {
        $tmp = [];
        foreach ($files as $field => [$bytes, $name, $mime]) {
            $handle = tmpfile();
            fwrite($handle, $bytes);
            $tmp[] = $handle;
            // Tipo dichiarato in base al contenuto reale (i ritagli sono PNG).
            $fields[$field] = new CURLFile(stream_get_meta_data($handle)['uri'], $mime, $name);
        }
        // Server Bot API alternativo (locale, vedi config.example.php), se configurato.
        $base = rtrim((string) (Config::get()['telegram_api_base'] ?? 'https://api.telegram.org'), '/');
        $ch = curl_init("$base/bot{$this->token}/{$method}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $files ? $fields : http_build_query($fields),
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);
        foreach ($tmp as $handle) {
            fclose($handle); // elimina anche il file temporaneo
        }
        return $this->handleResponse($response, $curlError);
    }

    private function handleResponse($response, string $curlError): array
    {
        if ($response === false) {
            // Il messaggio di cURL può contenere l'URL, e quindi il token.
            throw new TelegramException('Errore di connessione a Telegram: ' . str_replace($this->token, '***', $curlError));
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded) || empty($decoded['ok'])) {
            $detail = is_array($decoded) ? ($decoded['description'] ?? $response) : $response;
            throw new TelegramException("Telegram ha rifiutato la richiesta: $detail");
        }
        return $decoded['result'] ?? [];
    }
}
