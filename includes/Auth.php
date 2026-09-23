<?php
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/helpers.php';

class Auth
{
    /** Start (or resume) the PHP session with sane cookie settings. */
    public static function bootSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 60 * 60 * 24 * 30,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function currentUserId(): ?int
    {
        // Bot token auth (Authorization: Bot <token>) takes priority when present,
        // so bot scripts never need a browser session/cookie at all.
        $authHeader = get_authorization_header();
        if (stripos($authHeader, 'Bot ') === 0) {
            require_once __DIR__ . '/models/BotModel.php';
            return BotModel::userIdForToken(trim(substr($authHeader, 4)));
        }

        self::bootSession();
        return $_SESSION['user_id'] ?? null;
    }

    public static function isLoggedIn(): bool
    {
        return self::currentUserId() !== null;
    }

    /** Fetch the full current user row (without password hash). */
    public static function currentUser(): ?array
    {
        $id = self::currentUserId();
        if ($id === null) {
            return null;
        }
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT id, username, email, avatar_url, status, bio, pronouns, accent_color, is_admin, is_banned,
                    site_badge, points, nitro_until, is_bot, created_at
             FROM users WHERE id = ?'
        );
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    /**
     * Aborts the request with a 401 JSON error if not logged in.
     * Banned users are still allowed to stay logged in and reach
     * read-only parts of the app (so they can see their account
     * standing); individual endpoints that perform write actions
     * should call requireNotBanned() on top of this to block those
     * specific actions.
     */
    public static function requireLogin(): array
    {
        $user = self::currentUser();
        if ($user === null) {
            json_error('Authentication required', 401);
        }
        return $user;
    }

    /**
     * Aborts the request with a 403 JSON error if the given user is
     * currently banned. Call this inside write-action endpoints
     * (sending messages, creating servers/channels, uploading, etc.)
     * after Auth::requireLogin(), so banned users can still view the
     * app and their account standing but can't take restricted actions.
     */
    public static function requireNotBanned(array $user): void
    {
        if (empty($user['is_banned'])) {
            return;
        }
        $reason = self::activeBanReason((int) $user['id']);
        json_error(
            'This action is disabled while your account is suspended'
                . ($reason ? ": $reason" : '.'),
            403
        );
    }

    /**
     * Aborts the request with a 403 JSON error if the current user
     * isn't a site admin. Call requireLogin-equivalent checks first.
     */
    public static function requireAdmin(): array
    {
        $user = self::requireLogin();
        if (empty($user['is_admin'])) {
            json_error('Admin access required.', 403);
        }
        return $user;
    }

    /** The reason text for a user's current active ban, if any. */
    public static function activeBanReason(int $userId): ?string
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT reason FROM site_bans
             WHERE user_id = ? AND lifted_at IS NULL
             ORDER BY banned_at DESC LIMIT 1'
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ? $row['reason'] : null;
    }

    public static function register(string $username, string $email, string $password): array
    {
        if (!is_valid_username($username)) {
            return ['success' => false, 'error' => 'Username must be 3-32 characters (letters, numbers, _ or -).'];
        }
        if (!is_valid_email($email)) {
            return ['success' => false, 'error' => 'Please provide a valid email address.'];
        }
        if (strlen($password) < 8) {
            return ['success' => false, 'error' => 'Password must be at least 8 characters.'];
        }

        $pdo = Database::get();

        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            return ['success' => false, 'error' => 'That username or email is already taken.'];
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, status, created_at) VALUES (?, ?, ?, "online", NOW())'
        );
        $stmt->execute([$username, $email, $hash]);
        $userId = (int) $pdo->lastInsertId();

        self::bootSession();
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;

        return ['success' => true, 'user_id' => $userId];
    }

    public static function login(string $emailOrUsername, string $password): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT id, password_hash, is_banned FROM users WHERE email = ? OR username = ? LIMIT 1'
        );
        $stmt->execute([$emailOrUsername, $emailOrUsername]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Invalid credentials.'];
        }

        // Banned users are still allowed to log in so they can see why
        // and what their account status is — restricted actions are
        // blocked individually via Auth::requireNotBanned().
        self::bootSession();
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];

        $update = $pdo->prepare('UPDATE users SET status = "online" WHERE id = ?');
        $update->execute([$user['id']]);

        return ['success' => true, 'user_id' => (int) $user['id']];
    }

    public static function logout(): void
    {
        self::bootSession();
        $userId = self::currentUserId();
        if ($userId !== null) {
            $pdo = Database::get();
            $stmt = $pdo->prepare('UPDATE users SET status = "offline", last_seen = NOW() WHERE id = ?');
            $stmt->execute([$userId]);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path']);
        }
        session_destroy();
    }
}
