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

    public static function attempt(string $username, string $password): bool
    {
        $stmt = Database::get()->prepare('SELECT * FROM users WHERE username = :u');
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

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
        session_destroy();
    }

    public static function requireLogin(): void
    {
        if (!self::hasAnyUser()) {
            header('Location: setup.php');
            exit;
        }
        if (!self::check()) {
            header('Location: login.php');
            exit;
        }
    }

    public static function changePassword(int $userId, string $newPassword): void
    {
        $stmt = Database::get()->prepare('UPDATE users SET password_hash = :p WHERE id = :id');
        $stmt->execute([':p' => password_hash($newPassword, PASSWORD_DEFAULT), ':id' => $userId]);
    }
}
