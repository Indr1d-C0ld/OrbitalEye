<?php

final class PythonServiceException extends RuntimeException
{
}

final class PythonServiceClient
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeout;

    public function __construct()
    {
        $config = Config::get();
        $this->baseUrl = rtrim($config['python_service_url'], '/');
        $this->apiKey = $config['python_service_key'];
        $this->timeout = $config['python_service_timeout'] ?? 60;
    }

    /** @param int|null $timeout Secondi di attesa per questa chiamata, se
     * diversi dal valore generale di configurazione (es. i download). */
    public function post(string $path, array $payload, ?int $timeout = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-OrbitalEye-Key: ' . $this->apiKey,
            ],
            CURLOPT_TIMEOUT => $timeout ?? $this->timeout,
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new PythonServiceException("Errore di connessione al servizio di analisi: $err");
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);

        if ($status >= 400) {
            $detail = $decoded['detail'] ?? $response;
            throw new PythonServiceException("Servizio di analisi ha risposto $status: " . (is_string($detail) ? $detail : json_encode($detail)));
        }

        if (!is_array($decoded)) {
            throw new PythonServiceException('Risposta non valida dal servizio di analisi');
        }

        return $decoded;
    }

    /** Secondi di validità dell'esito di health() memorizzato in sessione. */
    private const HEALTH_CACHE_TTL = 30;

    /**
     * Stato del servizio di analisi, mostrato nella barra laterale a ogni
     * pagina (vedi partials/nav.php).
     *
     * L'esito viene messo in cache per qualche secondo nella sessione: senza,
     * ogni singolo caricamento di pagina faceva una richiesta HTTP sincrona,
     * e un servizio piantato (che accetta la connessione ma non risponde)
     * aggiungeva il timeout intero a OGNI pagina, rendendo l'interfaccia
     * inutilizzabile proprio nel momento in cui si vuole capire cosa non va.
     * Timeout ridotto di conseguenza: qui interessa solo "risponde subito?".
     */
    public function health(): bool
    {
        $now = time();
        if (isset($_SESSION['_health_cache'], $_SESSION['_health_cache_at'])
            && ($now - (int) $_SESSION['_health_cache_at']) < self::HEALTH_CACHE_TTL
        ) {
            return (bool) $_SESSION['_health_cache'];
        }

        $ch = curl_init($this->baseUrl . '/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 1,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $ok = $response !== false && $status === 200;
        $_SESSION['_health_cache'] = $ok;
        $_SESSION['_health_cache_at'] = $now;
        return $ok;
    }
}
