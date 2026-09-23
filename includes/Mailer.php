<?php

class Mailer
{
    /** Best-effort site base URL (no trailing slash) for building links in emails. */
    public static function baseUrl(): string
    {
        if (defined('BASE_URL') && BASE_URL !== '') {
            return rtrim(BASE_URL, '/');
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return "{$scheme}://{$host}";
    }

    /**
     * Sends a plain-text email. Uses real SMTP (MAIL_TRANSPORT = 'smtp' in
     * config.php) if configured — required for local dev stacks (XAMPP/
     * WAMP/MAMP/Laragon), which have no working PHP mail() by default.
     * Falls back to PHP's mail() otherwise (fine on most real hosting).
     * Returns false (and logs) instead of throwing — a mail failure should
     * never surface details to the client, see PasswordResetModel.
     */
    public static function send(string $toEmail, string $subject, string $body): bool
    {
        $transport = defined('MAIL_TRANSPORT') ? MAIL_TRANSPORT : 'mail';
        $sent = $transport === 'smtp'
            ? self::sendViaSmtp($toEmail, $subject, $body)
            : self::sendViaPhpMail($toEmail, $subject, $body);

        if (!$sent) {
            error_log("Mailer: failed to send \"{$subject}\" to {$toEmail}");
        }
        return $sent;
    }

    private static function sendViaPhpMail(string $toEmail, string $subject, string $body): bool
    {
        $fromAddress = defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'no-reply@example.com';
        $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Discordish';
        $headers = "From: {$fromName} <{$fromAddress}>\r\n" .
                   "Content-Type: text/plain; charset=UTF-8\r\n";
        return @mail($toEmail, $subject, $body, $headers);
    }

    /**
     * Minimal SMTP client: connects, optionally STARTTLS, AUTH LOGIN, sends
     * one plain-text message. No external dependencies — just a raw socket
     * talking the SMTP protocol, since composer isn't available everywhere
     * this project runs.
     */
    private static function sendViaSmtp(string $toEmail, string $subject, string $body): bool
    {
        $host = defined('SMTP_HOST') ? SMTP_HOST : '';
        $port = defined('SMTP_PORT') ? SMTP_PORT : 587;
        $username = defined('SMTP_USERNAME') ? SMTP_USERNAME : '';
        $password = defined('SMTP_PASSWORD') ? SMTP_PASSWORD : '';
        $encryption = defined('SMTP_ENCRYPTION') ? SMTP_ENCRYPTION : 'tls'; // 'tls' (STARTTLS), 'ssl', or 'none'
        $fromAddress = defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'no-reply@example.com';
        $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Discordish';

        if ($host === '') {
            error_log('Mailer: MAIL_TRANSPORT is "smtp" but SMTP_HOST is not set in config.php');
            return false;
        }

        $connectHost = $encryption === 'ssl' ? "ssl://{$host}" : $host;
        $sock = @stream_socket_client("{$connectHost}:{$port}", $errno, $errstr, 10);
        if (!$sock) {
            error_log("Mailer: SMTP connect failed — {$errstr} ({$errno})");
            return false;
        }
        stream_set_timeout($sock, 10);

        $read = function () use ($sock) {
            $data = '';
            while (($line = fgets($sock, 515)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') break; // last line of a multi-line reply
            }
            return $data;
        };
        $write = function (string $cmd) use ($sock) { fwrite($sock, $cmd . "\r\n"); };
        $expect = function (string $expectedCode) use ($read, &$lastReply) {
            $lastReply = $read();
            return substr($lastReply, 0, 3) === $expectedCode;
        };

        try {
            if (!$expect('220')) return false;

            $write('EHLO localhost');
            if (!$expect('250')) return false;

            if ($encryption === 'tls') {
                $write('STARTTLS');
                if (!$expect('220')) return false;
                if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    error_log('Mailer: STARTTLS negotiation failed');
                    return false;
                }
                $write('EHLO localhost');
                if (!$expect('250')) return false;
            }

            if ($username !== '') {
                $write('AUTH LOGIN');
                if (!$expect('334')) return false;
                $write(base64_encode($username));
                if (!$expect('334')) return false;
                $write(base64_encode($password));
                if (!$expect('235')) return false;
            }

            $write("MAIL FROM:<{$fromAddress}>");
            if (!$expect('250')) return false;
            $write("RCPT TO:<{$toEmail}>");
            if (!$expect('250') && !$expect('251')) return false;
            $write('DATA');
            if (!$expect('354')) return false;

            $headers = "From: {$fromName} <{$fromAddress}>\r\n" .
                       "To: <{$toEmail}>\r\n" .
                       "Subject: {$subject}\r\n" .
                       "Content-Type: text/plain; charset=UTF-8\r\n";
            // Per RFC 5321, lines starting with "." must be escaped by doubling it.
            $escapedBody = preg_replace('/^\./m', '..', $body);
            $write($headers . "\r\n" . $escapedBody . "\r\n.");
            if (!$expect('250')) return false;

            $write('QUIT');
            return true;
        } finally {
            fclose($sock);
        }
    }
}
