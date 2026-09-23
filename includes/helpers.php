<?php
require_once __DIR__ . '/../config/config.php';

/**
 * Safety net: any exception/error that isn't explicitly caught (a missing
 * migration, a bug, whatever) would otherwise leak raw PHP error HTML into
 * what's supposed to be a JSON API response — which breaks the frontend
 * with a confusing "Unexpected token '<'" instead of a real message. This
 * converts it into a clean JSON error and logs the real one server-side.
 */
set_exception_handler(function (Throwable $e) {
    error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => false, 'error' => 'Something went wrong on the server. Please try again.']);
    exit;
});

/**
 * Send a JSON response and terminate the script.
 */
function json_response(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function json_error(string $message, int $statusCode = 400): void
{
    json_response(['success' => false, 'error' => $message], $statusCode);
}

function json_ok(array $data = []): void
{
    json_response(array_merge(['success' => true], $data));
}

/**
 * Read + decode a JSON request body. Falls back to $_POST for
 * classic form submissions (used by the upload endpoints).
 */
function request_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return $_POST ?: [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ($_POST ?: []);
}

function require_method(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        json_error('Method not allowed', 405);
    }
}

function generate_invite_code(int $length = 8): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $code;
}

function sanitize_text(string $text): string
{
    return trim($text);
}

function html_out(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

/**
 * Validate a username: 3-32 chars, letters/numbers/underscore/dash.
 */
function is_valid_username(string $username): bool
{
    return (bool) preg_match('/^[a-zA-Z0-9_\-]{3,32}$/', $username);
}

function is_valid_email(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function is_valid_hex_color(string $color): bool
{
    return (bool) preg_match('/^#[0-9A-Fa-f]{6}$/', $color);
}

/**
 * Reads the Authorization header regardless of how this PHP/webserver
 * combo exposes it — some Apache setups strip $_SERVER['HTTP_AUTHORIZATION']
 * unless you add a rewrite rule, so this checks the common fallbacks too.
 */
function get_authorization_header(): string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            if (strtolower($name) === 'authorization') {
                return $value;
            }
        }
    }
    return '';
}
