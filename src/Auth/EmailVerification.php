<?php
declare(strict_types=1);

namespace Chat\Auth;

use Chat\DB\Connection;
use Chat\Security\{CSRF, Session};
use Chat\Mail\Mailer;

class EmailVerification
{
    public static function verify(): void
    {
        $rawToken = (string) ($_GET['token'] ?? '');
        if ($rawToken === '') {
            self::failRedirect('Неверная ссылка подтверждения.');
        }

        $tokenHash = hash('sha256', $rawToken);
        $db  = Connection::getInstance();
        $row = $db->fetchOne(
            'SELECT id, user_id, expires_at, used_at FROM email_verifications WHERE token_hash = ?',
            [$tokenHash]
        );

        if (!$row) {
            self::failRedirect('Ссылка недействительна.');
        }
        if ($row['used_at'] !== null) {
            self::failRedirect('Ссылка уже использована.');
        }
        if (strtotime($row['expires_at']) < time()) {
            self::failRedirect('Ссылка истекла. Запросите новое письмо.');
        }

        $db->execute(
            'UPDATE email_verifications SET used_at = NOW() WHERE id = ?',
            [(int) $row['id']]
        );
        $db->execute(
            'UPDATE users SET email_verified = 1 WHERE id = ?',
            [(int) $row['user_id']]
        );

        $user = $db->fetchOne('SELECT id, is_banned FROM users WHERE id = ?', [(int) $row['user_id']]);
        if (!$user || (int) $user['is_banned'] === 1) {
            self::failRedirect('Аккаунт заблокирован.');
        }

        $ip    = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua    = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $token = Session::create((int) $row['user_id'], $ip, $ua);
        Session::setCookie($token);

        header('Location: /');
        exit;
    }

    public static function resend(): void
    {
        if (!CSRF::verifyRequest()) {
            self::jsonError('Неверный CSRF токен.', 403);
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::jsonError('Некорректный email.');
        }

        $db   = Connection::getInstance();
        $user = $db->fetchOne(
            'SELECT id, username, email_verified FROM users WHERE email = ? LIMIT 1',
            [$email]
        );

        // Always return success to avoid email enumeration
        if (!$user || (int) $user['email_verified'] === 1) {
            self::jsonSuccess();
        }

        // Cooldown: block resend if last token created less than 60 seconds ago
        $last = $db->fetchOne(
            'SELECT created_at FROM email_verifications
             WHERE user_id = ? AND used_at IS NULL
             ORDER BY created_at DESC LIMIT 1',
            [(int) $user['id']]
        );
        if ($last && (time() - strtotime($last['created_at'])) < 60) {
            self::jsonError('Подождите минуту перед повторной отправкой.');
        }

        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $db->execute(
            'INSERT INTO email_verifications (user_id, token_hash, expires_at) VALUES (?, ?, ?)',
            [(int) $user['id'], $tokenHash, date('Y-m-d H:i:s', time() + 86400)]
        );

        try {
            Mailer::sendVerification($email, (string) $user['username'], $rawToken);
        } catch (\Throwable $e) {
            error_log('Mailer::sendVerification (resend) failed: ' . $e->getMessage());
        }
        self::jsonSuccess();
    }

    private static function failRedirect(string $message): never
    {
        header('Location: /?auth_error=' . urlencode($message));
        exit;
    }

    private static function jsonError(string $message, int $code = 400): never
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }

    private static function jsonSuccess(): never
    {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
