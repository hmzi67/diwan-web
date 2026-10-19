<?php
declare(strict_types=1);

namespace Diwan\Support;

use Diwan\Config\Env;
use RuntimeException;

/**
 * Sends outbound mail (login links, licence keys).
 *
 * Two drivers, chosen by MAIL_DRIVER:
 *
 *  - "sendmail" (default): PHP's built-in mail(), which shells out to the
 *    local MTA. This is what shared hosting (Hostinger et al.) already has
 *    configured, so production needs zero extra setup.
 *
 *  - "smtp": talks SMTP directly over a socket (no Composer, no PHPMailer —
 *    this project stays dependency-free for FTP-only deploys). Works against
 *    Hostinger (MAIL_HOST=smtp.hostinger.com, MAIL_PORT=587 = STARTTLS or 465
 *    = implicit TLS, MAIL_USERNAME = the full mailbox address) and against a
 *    local dev mail
 *    catcher, e.g. Mailpit (`brew install mailpit && mailpit`, then
 *    MAIL_HOST=127.0.0.1 MAIL_PORT=1025) or a Mailtrap sandbox inbox — either
 *    way you get a real inbox to inspect without a working outbound MTA. This
 *    is the fix for "mail() silently does nothing on my laptop": macOS ships a
 *    sendmail binary but no running Postfix, so mail() has nothing to hand off
 *    to.
 */
final class Mailer
{
    /**
     * $replyTo must already be a validated address (FILTER_VALIDATE_EMAIL
     * rejects CR/LF, so it cannot inject extra headers). $from is the From
     * header's value ("Name <addr>" or "addr"), defaulting to MAIL_FROM.
     */
    public static function send(
        string $to,
        string $subject,
        string $body,
        ?string $from = null,
        ?string $replyTo = null
    ): bool {
        $from ??= Env::get('MAIL_FROM', 'no-reply@localhost');
        $driver = strtolower(Env::get('MAIL_DRIVER', 'sendmail') ?? 'sendmail');

        return $driver === 'smtp'
            ? self::sendSmtp($to, $subject, $body, $from, $replyTo)
            : mail($to, $subject, $body, "From: {$from}" . ($replyTo !== null ? "\r\nReply-To: <{$replyTo}>" : ''));
    }

    private static function sendSmtp(
        string $to,
        string $subject,
        string $body,
        string $fromValue,
        ?string $replyTo
    ): bool {
        $host = Env::get('MAIL_HOST', '127.0.0.1');
        $port = Env::int('MAIL_PORT', 1025);
        $user = Env::get('MAIL_USERNAME');
        $pass = Env::get('MAIL_PASSWORD');
        $from = self::extractAddress($fromValue) ?? Env::get('MAIL_FROM', 'no-reply@localhost');

        try {
            // Port 465 is implicit TLS (SMTPS): the connection is encrypted
            // before the server even sends its banner. Port 587 stays plain
            // and upgrades mid-session via STARTTLS below.
            $smtps = $port === 465;
            $socket = @fsockopen(($smtps ? 'ssl://' : '') . $host, $port, $errno, $errstr, 5);
            if ($socket === false) {
                throw new RuntimeException("Could not reach SMTP server {$host}:{$port} ({$errstr})");
            }

            self::expect($socket, 220);
            self::command($socket, 'EHLO diwan.local', 250);

            // Gmail (and most real providers) refuse AUTH on port 587 until
            // the connection is upgraded to TLS.
            if (!$smtps && $port === 587) {
                self::command($socket, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS negotiation failed');
                }
                // RFC 3207: EHLO must be resent after STARTTLS.
                self::command($socket, 'EHLO diwan.local', 250);
            }

            if ($user !== null && $pass !== null) {
                self::command($socket, 'AUTH LOGIN', 334);
                self::command($socket, base64_encode($user), 334);
                self::command($socket, base64_encode($pass), 235);
            }

            self::command($socket, "MAIL FROM:<{$from}>", 250);
            self::command($socket, "RCPT TO:<{$to}>", 250);
            self::command($socket, 'DATA', 354);

            $headers = "From: {$fromValue}\r\n"
                . "To: <{$to}>\r\n"
                . ($replyTo !== null ? "Reply-To: <{$replyTo}>\r\n" : '')
                . "Subject: {$subject}\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n";

            // Dot-stuff any line that starts with '.', per RFC 5321.
            $escapedBody = preg_replace('/^\./m', '..', $body);

            self::command($socket, $headers . "\r\n" . $escapedBody . "\r\n.", 250);
            self::command($socket, 'QUIT', 221);
            fclose($socket);

            return true;
        } catch (RuntimeException $e) {
            // Logger masks addresses in the reason; SMTP replies echo the recipient.
            Logger::error('SMTP send failed', ['reason' => $e->getMessage(), 'host' => $host, 'port' => $port]);
            return false;
        }
    }

    /** @param resource $socket */
    private static function command($socket, string $line, int $expectedCode): string
    {
        fwrite($socket, $line . "\r\n");
        return self::expect($socket, $expectedCode);
    }

    /** @param resource $socket */
    private static function expect($socket, int $expectedCode): string
    {
        $response = '';
        do {
            $line = fgets($socket, 512);
            if ($line === false) {
                throw new RuntimeException('SMTP connection closed unexpectedly');
            }
            $response .= $line;
        } while (isset($line[3]) && $line[3] === '-'); // multi-line reply continues until "code SP"

        $code = (int) substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new RuntimeException("Unexpected SMTP response (wanted {$expectedCode}): " . trim($response));
        }
        return $response;
    }

    private static function extractAddress(string $from): ?string
    {
        // "Name <addr@x>" or "addr@x" -> addr@x
        if (preg_match('/<([^>]+)>/', $from, $m)) {
            return $m[1];
        }
        if (preg_match('/([^\s:]+@[^\s>]+)/', $from, $m)) {
            return $m[1];
        }
        return null;
    }
}
