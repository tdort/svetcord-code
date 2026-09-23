<?php
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/helpers.php';

Auth::bootSession();

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'register': {
        require_method('POST');
        $body = request_body();
        $username = sanitize_text($body['username'] ?? '');
        $email = sanitize_text($body['email'] ?? '');
        $password = (string) ($body['password'] ?? '');

        $result = Auth::register($username, $email, $password);
        if (!$result['success']) {
            json_error($result['error']);
        }
        json_ok(['user' => Auth::currentUser()]);
        break;
    }

    case 'login': {
        require_method('POST');
        $body = request_body();
        $identifier = sanitize_text($body['identifier'] ?? '');
        $password = (string) ($body['password'] ?? '');

        if ($identifier === '' || $password === '') {
            json_error('Please provide your username/email and password.');
        }

        $result = Auth::login($identifier, $password);
        if (!$result['success']) {
            json_error($result['error'], 401);
        }
        json_ok(['user' => Auth::currentUser()]);
        break;
    }

    case 'logout': {
        require_method('POST');
        Auth::logout();
        json_ok();
        break;
    }

    case 'update_profile': {
        require_method('POST');
        $user = Auth::requireLogin();
        $body = request_body();

        $bio = sanitize_text($body['bio'] ?? '');
        $pronouns = sanitize_text($body['pronouns'] ?? '');
        $accentColor = sanitize_text($body['accent_color'] ?? '#5865f2');

        if (mb_strlen($bio) > 190) {
            json_error('Bio must be 190 characters or fewer.');
        }
        if (mb_strlen($pronouns) > 40) {
            json_error('Pronouns must be 40 characters or fewer.');
        }
        if (!is_valid_hex_color($accentColor)) {
            $accentColor = '#5865f2';
        }

        require_once __DIR__ . '/../includes/models/UserModel.php';
        UserModel::updateProfile((int) $user['id'], $bio, $pronouns, $accentColor);
        json_ok(['user' => Auth::currentUser()]);
        break;
    }

    case 'me': {
        $user = Auth::currentUser();
        if ($user === null) {
            json_error('Not authenticated', 401);
        }
        json_ok(['user' => $user]);
        break;
    }

    case 'standing': {
        $user = Auth::requireLogin();
        $pdo = Database::get();

        $stmt = $pdo->prepare(
            'SELECT reason, banned_at, lifted_at FROM site_bans
             WHERE user_id = ? ORDER BY banned_at DESC'
        );
        $stmt->execute([$user['id']]);
        $bans = $stmt->fetchAll();

        $stmt = $pdo->prepare(
            'SELECT reason, warned_at, expires_at FROM site_warnings
             WHERE user_id = ? ORDER BY warned_at DESC'
        );
        try {
            $stmt->execute([$user['id']]);
            $warnings = $stmt->fetchAll();
        } catch (Throwable $e) {
            $warnings = []; // migrations/004_warnings.sql not run yet
        }

        $activeBans = array_values(array_filter($bans, fn($b) => $b['lifted_at'] === null));
        $expiredBans = array_values(array_filter($bans, fn($b) => $b['lifted_at'] !== null));

        $now = new DateTime();
        $activeWarnings = array_values(array_filter(
            $warnings,
            fn($w) => new DateTime($w['expires_at']) > $now
        ));
        $expiredWarnings = array_values(array_filter(
            $warnings,
            fn($w) => new DateTime($w['expires_at']) <= $now
        ));

        // Normalize both kinds of violations to a common shape so the
        // frontend can render them in one combined, newest-first list.
        $normalize = fn($rows, $dateKey, $resolvedKey, $type) => array_map(
            fn($r) => [
                'type' => $type,
                'reason' => $r['reason'],
                'issued_at' => $r[$dateKey],
                'resolved_at' => $r[$resolvedKey] ?? null,
                'expires_at' => $type === 'warning' ? $r['expires_at'] : null,
            ],
            $rows
        );

        $active = array_merge(
            $normalize($activeBans, 'banned_at', null, 'ban'),
            $normalize($activeWarnings, 'warned_at', null, 'warning')
        );
        $expired = array_merge(
            $normalize($expiredBans, 'banned_at', 'lifted_at', 'ban'),
            $normalize($expiredWarnings, 'warned_at', 'expires_at', 'warning')
        );
        usort($active, fn($a, $b) => strtotime($b['issued_at']) <=> strtotime($a['issued_at']));
        usort($expired, fn($a, $b) => strtotime($b['issued_at']) <=> strtotime($a['issued_at']));

        $suspended = count($activeBans) > 0;
        // Graduated standing based on active warning count, capped
        // below "suspended" (a real ban always wins outright).
        $activeWarningCount = count($activeWarnings);
        if ($suspended) {
            $severity = 4; // Suspended
        } elseif ($activeWarningCount >= 3) {
            $severity = 3; // At risk
        } elseif ($activeWarningCount === 2) {
            $severity = 2; // Very limited
        } elseif ($activeWarningCount === 1) {
            $severity = 1; // Limited
        } else {
            $severity = 0; // All good
        }

        json_ok([
            'suspended' => $suspended,
            'severity' => $severity,
            'active_violations' => $active,
            'expired_violations' => $expired,
        ]);
        break;
    }

    case 'request_password_reset': {
        require_method('POST');
        $body = request_body();
        $email = sanitize_text($body['email'] ?? '');

        if (is_valid_email($email)) {
            require_once __DIR__ . '/../includes/models/PasswordResetModel.php';
            PasswordResetModel::requestReset($email);
        }
        // Always the same response, whether or not that email exists.
        json_ok(['message' => 'If an account uses that email, a reset link is on its way.']);
        break;
    }

    case 'check_reset_token': {
        $token = (string) ($_GET['token'] ?? '');
        require_once __DIR__ . '/../includes/models/PasswordResetModel.php';
        $valid = $token !== '' && PasswordResetModel::findUserIdForToken($token) !== null;
        json_ok(['valid' => $valid]);
        break;
    }

    case 'reset_password': {
        require_method('POST');
        $body = request_body();
        $token = (string) ($body['token'] ?? '');
        $password = (string) ($body['password'] ?? '');

        require_once __DIR__ . '/../includes/models/PasswordResetModel.php';
        $result = PasswordResetModel::resetPassword($token, $password);
        if (!$result['success']) {
            json_error($result['error']);
        }
        json_ok();
        break;
    }

    default:
        json_error('Unknown action', 404);
}
