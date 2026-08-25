<?php
/**
 * Configurazione OrbitalEye. Copiare in config.php e adattare i valori.
 * config.php NON va versionato (contiene segreti/percorsi locali).
 */
return [
    // Radice storage condivisa con il servizio Python (stesso valore di
    // python-service/.env -> STORAGE_ROOT)
    'storage_root' => '/var/www/html/orbitaleye/storage',

    // Database SQLite dell'applicazione
    'db_path' => __DIR__ . '/../data/orbitaleye.sqlite',

    // Endpoint del microservizio Python (FastAPI)
    'python_service_url' => 'http://127.0.0.1:8077',

    // Deve combaciare con SERVICE_API_KEY nel .env del servizio Python
    'python_service_key' => 'change-me-to-a-long-random-string',

    // Nome applicazione / branding
    'app_name' => 'ORBITALEYE',

    // Timeout (secondi) per le chiamate al servizio Python (il compare con
    // allineamento + SSIM su immagini grandi può richiedere qualche secondo)
    'python_service_timeout' => 60,
];
