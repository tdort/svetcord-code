<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Mailer.php';

class PasswordResetModel
{
    /**
     * Looks up the email and, if it belongs to an account, emails a reset
     * link. Always returns success — the caller (api/auth.php) shows the
     * same generic message either way, so this endpoint can't be used to
     * check which emails are registered.
     */
    public static function requestReset(string $email): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT id, username FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user) {
            return;
        }

        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $ttl = defined('PASSWORD_RESET_TOKEN_TTL_MINUTES') ? PASSWORD_RESET_TOKEN_TTL_MINUTES : 60;

        // Invalidate any older outstanding tokens for this user first.
        $pdo->prepare('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL')
            ->execute([$user['id']]);

        $stmt = $pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, created_at)
             VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), NOW())'
        );
        $stmt->execute([$user['id'], $tokenHash, $ttl]);

        $appName = defined('APP_NAME') ? APP_NAME : 'Discordish';
        $link = Mailer::baseUrl() . '/public/reset_password.php?token=' . $token;
        $body = "Hey {$user['username']}! 👋\n\n" .
                "We got a request to reset your password on {$appName}.\n\n" .
                "Click below to set a new one (this link expires in {$ttl} minutes):\n{$link}\n\n" .
                "Didn't ask for this? No worries, just ignore this email and your password stays the same.";

        Mailer::send($email, "🔒 Reset your {$appName} password", $body);
    }

    /** Looks up an unexpired, unused token. Returns the user_id or null. */
    public static function findUserIdForToken(string $token): ?int
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT user_id FROM password_resets
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();
        return $row ? (int) $row['user_id'] : null;
    }

    /** Validates the token, sets the new password, and burns the token. */
    public static function resetPassword(string $token, string $newPassword): array
    {
        if (strlen($newPassword) < 8) {
            return ['success' => false, 'error' => 'Password must be at least 8 characters.'];
        }

        $userId = self::findUserIdForToken($token);
        if ($userId === null) {
            return ['success' => false, 'error' => 'This reset link is invalid or has expired.'];
        }

        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $userId]);
            $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE token_hash = ?')
                ->execute([hash('sha256', $token)]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Something went wrong — try again.'];
        }

        return ['success' => true];
    }
}
