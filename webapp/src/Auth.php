<?php

final class Auth
{
    public static function hasAnyUser(): bool
    {
        $stmt = Database::get()->query('SELECT COUNT(*) AS c FROM users');
        return (int) $stmt->fetch()['c'] > 0;
    }

    public static function createUser(string $username, string $password): void
    {
        $stmt = Database::get()->prepare(
            'INSERT INTO users (username, password_hash) VALUES (:u, :p)'
        );
        $stmt->execute([
            ':u' => $username,
            ':p' => password_hash($password, PASSWORD_DEFAULT),
        ]);
    }

    /** Tentativi falliti consecutivi oltre i quali si impone un'attesa. */
    private const MAX_ATTEMPTS = 8;
    private const LOCKOUT_SECONDS = 300;

    /** Secondi mancanti alla fine del blocco, 0 se non è attivo. */
    public static function lockoutRemaining(): int
    {
        $until = (int) ($_SESSION['_login_locked_until'] ?? 0);
        return max(0, $until - time());
    }

    /**
     * Verifica le credenziali SENZA toccare la sessione: serve a
     * ricontrollare la password corrente (es. cambio password) senza
     * l'effetto collaterale di session_regenerate_id(), che rigenera l'id e
     * scollega le altre schede aperte.
     */
    public static function verify(string $username, string $password): bool
    {
        $stmt = Database::get()->prepare('SELECT * FROM users WHERE username = :u');
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();

        if (!$user) {
            // Hash fittizio: senza, un utente inesistente risponderebbe molto
            // più in fretta di uno esistente, rivelando quali username esistono.
            password_verify($password, '$2y$10$usesomesillystringforsalttoavoidtimingleak0000000000000');
            return false;
        }
        return password_verify($password, $user['password_hash']);
    }

    public static function attempt(string $username, string $password): bool
    {
        if (self::lockoutRemaining() > 0) {
            return false;
        }

        if (!self::verify($username, $password)) {
            // Il conteggio vive nella sessione: non è una difesa assoluta
            // (chi scarta il cookie riparte da zero), ma rende inefficace il
            // tentativo a forza bruta da browser/script banale, che è lo
            // scenario realistico per un'app esposta su internet.
            $_SESSION['_login_failures'] = (int) ($_SESSION['_login_failures'] ?? 0) + 1;
            if ($_SESSION['_login_failures'] >= self::MAX_ATTEMPTS) {
                $_SESSION['_login_locked_until'] = time() + self::LOCKOUT_SECONDS;
                $_SESSION['_login_failures'] = 0;
            }
            return false;
        }

        $stmt = Database::get()->prepare('SELECT * FROM users WHERE username = :u');
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();

        unset($_SESSION['_login_failures'], $_SESSION['_login_locked_until']);
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        return true;
    }

    public static function check(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        // Senza questo il cookie resta nel browser dopo il logout, puntando a
        // una sessione distrutta: innocuo di per sé, ma è il cookie stesso a
        // dover sparire.
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $p['path'],
                'domain' => $p['domain'],
                'secure' => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?: 'Lax',
            ]);
        }
        session_destroy();
    }

    /** Una richiesta che si aspetta JSON (fetch dal browser verso api/*)
     * non deve ricevere un redirect HTML alla pagina di login: fetch lo
     * segue in silenzio e il chiamante si ritrova a fare res.json() su una
     * pagina HTML, con un errore di parsing incomprensibile — o, peggio,
     * con un salvataggio fallito senza che nulla lo segnali. */
    private static function wantsJson(): bool
    {
        if (str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/')) {
            return true;
        }
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    }

    public static function requireLogin(): void
    {
        $hasUser = self::hasAnyUser();
        if ($hasUser && self::check()) {
            return;
        }
        if (self::wantsJson()) {
            respond_json([
                'error' => $hasUser
                    ? 'Sessione scaduta: ricarica la pagina e accedi di nuovo.'
                    : 'Applicazione non ancora configurata.',
                'auth_required' => true,
            ], 401);
        }
        header('Location: ' . ($hasUser ? 'login.php' : 'setup.php'));
        exit;
    }

    public static function changePassword(int $userId, string $newPassword): void
    {
        $stmt = Database::get()->prepare('UPDATE users SET password_hash = :p WHERE id = :id');
        $stmt->execute([':p' => password_hash($newPassword, PASSWORD_DEFAULT), ':id' => $userId]);
    }
}
