<?php

final class TelegramException extends RuntimeException
{
}

/**
 * Client minimale per l'API bot di Telegram (https://core.telegram.org/bots/api),
 * usato dalla funzione "Condividi" (vedi api/share.php): invia
 * un'immagine con didascalia (o un semplice messaggio di testo, per il test
 * di connessione) al canale/chat configurato in Impostazioni.
 *
 * Nessuna dipendenza dal python-service: due chiamate HTTP dirette
 * all'API pubblica di Telegram, token e chat_id letti da AppSettings.
 * A differenza di Sentinel Hub/Esri, il token non deve mai raggiungere il
 * python-service (non serve un file di sincronizzazione in storage/config).
 */
final class TelegramClient
{
    private string $token;
    private string $chatId;

    public function __construct()
    {
        $settings = AppSettings::all();
        $this->token = $settings['telegram_bot_token'];
        $this->chatId = $settings['telegram_chat_id'];
        if ($this->token === '' || $this->chatId === '') {
            throw new TelegramException('Telegram non configurato: imposta token del bot e ID canale/chat in Impostazioni.');
        }
    }

    /**
     * Invia una foto con didascalia al canale/chat configurato.
     * $imageBytes: contenuto binario dell'immagine (jpg/png).
     */
    public function sendPhoto(string $imageBytes, string $caption, string $filename = 'condivisione.jpg'): array
    {
        $url = "https://api.telegram.org/bot{$this->token}/sendPhoto";
        $tmpFile = tmpfile();
        $tmpPath = stream_get_meta_data($tmpFile)['uri'];
        fwrite($tmpFile, $imageBytes);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'chat_id' => $this->chatId,
                'caption' => $caption,
                // Telegram tronca le didascalie oltre 1024 caratteri: non è
                // un problema per i testi generati qui (brevi per natura),
                // ma se l'analista scrive molto di suo non viene troncato
                // silenziosamente altrove — resta un limite noto di Telegram.
                'photo' => new CURLFile($tmpPath, 'image/jpeg', $filename),
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);
        fclose($tmpFile); // elimina anche il file temporaneo

        return $this->handleResponse($response, $curlError);
    }

    /** Solo testo (usato per "Test invio" dalle Impostazioni). */
    public function sendMessage(string $text): array
    {
        $url = "https://api.telegram.org/bot{$this->token}/sendMessage";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $this->chatId, 'text' => $text]),
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        return $this->handleResponse($response, $curlError);
    }

    private function handleResponse($response, string $curlError): array
    {
        if ($response === false) {
            throw new TelegramException("Errore di connessione a Telegram: $curlError");
        }
        $decoded = json_decode($response, true);
        if (!is_array($decoded) || empty($decoded['ok'])) {
            $detail = is_array($decoded) ? ($decoded['description'] ?? $response) : $response;
            throw new TelegramException("Telegram ha rifiutato la richiesta: $detail");
        }
        return $decoded['result'] ?? [];
    }
}
