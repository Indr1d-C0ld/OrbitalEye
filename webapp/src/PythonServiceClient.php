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

    public function post(string $path, array $payload): array
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
            CURLOPT_TIMEOUT => $this->timeout,
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

    public function health(): bool
    {
        $ch = curl_init($this->baseUrl . '/health');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $response !== false && $status === 200;
    }
}
