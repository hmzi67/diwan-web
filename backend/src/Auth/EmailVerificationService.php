<?php
declare(strict_types=1);

namespace Diwan\Auth;

use Diwan\Config\Env;
use Diwan\Database\Database;
use Diwan\Support\Logger;
use Diwan\Support\Mailer;
use PDO;

/**
 * Signup email verification: request() mints a short-lived, single-use token
 * and emails the link; verify() redeems it exactly once and stamps
 * customers.email_verified_at.
 *
 * Same shape as PasswordResetService — hash-only tokens, dual-window rate
 * limits, redeem inside a transaction — because it is the same problem:
 * an emailed single-use link that must die after one click. Sign-in refuses
 * to start a session until this has happened (see sign-in.php), so the
 * verification link is the only thing standing between a signup and a
 * session, and it is worth exactly as much care as a password reset.
 *
 * Why it exists at all: the licence key is delivered by email. A purchase
 * made against an address the buyer cannot read is a support ticket, and a
 * session that can be started for an address nobody has proven is an
 * account-squatting tool.
 */
final class EmailVerificationService
{
    private const TOKEN_TTL_HOURS         = 24;
    private const MAX_PER_IP_PER_HOUR     = 10;
    private const MAX_PER_CUSTOMER_PER_HOUR = 5;

    /**
     * Sends a verification link to an address that has an account but has not
     * verified it. Unknown or already-verified addresses are a silent no-op —
     * the caller responds identically either way, so this cannot be used to
     * find out which emails have accounts.
     */
    public function send(string $email, string $ip, ?string $plan = null): void
    {
        $email = strtolower(trim($email));
        $pdo = Database::pdo();

        // Token tables are self-pruning: nothing else ever cleans them up,
        // and expired rows are never consulted again.
        $pdo->exec('DELETE FROM email_verification_tokens WHERE expires_at < NOW()');

        $ipCount = $pdo->prepare(
            'SELECT COUNT(*) FROM email_verification_tokens
              WHERE issued_ip = :ip AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );
        $ipCount->execute(['ip' => $ip]);
        if ((int) $ipCount->fetchColumn() >= self::MAX_PER_IP_PER_HOUR) {
            Logger::warning('Verification link rate limited by IP', ['ip' => $ip]);
            return;
        }

        $customer = $pdo->prepare(
            'SELECT id, email_verified_at FROM customers WHERE email = :email LIMIT 1'
        );
        $customer->execute(['email' => $email]);
        $customer = $customer->fetch();

        if (!$customer || $customer['email_verified_at'] !== null) {
            Logger::info('Verification link requested for unknown or verified email', ['ip' => $ip]);
            return;
        }

        $customerId = (int) $customer['id'];

        $customerCount = $pdo->prepare(
            'SELECT COUNT(*) FROM email_verification_tokens
              WHERE customer_id = :id AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );
        $customerCount->execute(['id' => $customerId]);
        if ((int) $customerCount->fetchColumn() >= self::MAX_PER_CUSTOMER_PER_HOUR) {
            Logger::warning('Verification link rate limited by customer', ['customer_id' => $customerId]);
            return;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable('+' . self::TOKEN_TTL_HOURS . ' hours'))
            ->format('Y-m-d H:i:s');

        $pdo->prepare(
            'INSERT INTO email_verification_tokens (token_hash, customer_id, issued_ip, expires_at, created_at)
             VALUES (:hash, :id, :ip, :expires, NOW())'
        )->execute([
            'hash'    => $this->hashToken($token),
            'id'      => $customerId,
            'ip'      => $ip,
            'expires' => $expiresAt,
        ]);

        // Points at the static page, not an API endpoint: the page reads the
        // token from its own query string and POSTs it back, so the browser
        // never navigates to an API URL directly.
        $link = rtrim((string) Env::require('APP_URL'), '/')
            . '/auth/verify-email.html?token=' . $token;
        // Bare-SKU shape only, same rule as everywhere else plan rides in a
        // URL: a UI convenience for landing back on the chosen checkout after
        // verifying, never trusted for anything security-sensitive.
        if ($plan !== null && preg_match('/^[a-z0-9-]{1,64}$/', $plan) === 1) {
            $link .= '&plan=' . rawurlencode($plan);
        }

        $sent = Mailer::send(
            $email,
            'Verify your Diwan email address',
            "One click to finish creating your Diwan account:\n\n{$link}\n\n"
            . 'This link works once and expires in ' . self::TOKEN_TTL_HOURS . " hours.\n"
            . "If you didn't sign up for Diwan, you can ignore this email — nothing\n"
            . "has been created that you need to undo.\n",
            'From: ' . Env::get('MAIL_FROM', 'no-reply@localhost')
        );

        if (!$sent) {
            Logger::error('Verification email failed to send', ['customer_id' => $customerId]);
        } else {
            Logger::info('Verification link sent', ['customer_id' => $customerId]);
        }
    }

    /**
     * Redeems a token exactly once, stamps the account verified and returns
     * the customer id — or null for anything invalid, expired or used.
     *
     * A re-click after verification succeeds too (the row is already marked,
     * there is simply nothing left to do): the person holding the link is the
     * mailbox owner either way, and telling them "expired" for an event that
     * already happened is just confusing.
     */
    public function verify(string $rawToken, string $ip): ?int
    {
        $hash = $this->hashToken($rawToken);

        return Database::transaction(function (PDO $pdo) use ($hash, $ip) {
            $stmt = $pdo->prepare(
                'SELECT id, customer_id, used_at, expires_at FROM email_verification_tokens
                  WHERE token_hash = :hash LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['hash' => $hash]);
            $token = $stmt->fetch();

            if (!$token) {
                Logger::warning('Verification link redeemed: not found', ['ip' => $ip]);
                return null;
            }
            if ($token['used_at'] !== null) {
                Logger::warning('Verification link redeemed: already used', ['ip' => $ip, 'token_id' => $token['id']]);
                return null;
            }
            if (strtotime((string) $token['expires_at']) < time()) {
                Logger::warning('Verification link redeemed: expired', ['ip' => $ip, 'token_id' => $token['id']]);
                return null;
            }

            $customerId = (int) $token['customer_id'];

            $pdo->prepare('UPDATE email_verification_tokens SET used_at = NOW() WHERE id = :id')
                ->execute(['id' => $token['id']]);

            // Any other link still outstanding for this account dies with it —
            // otherwise a stale copy keeps working after the first click.
            $pdo->prepare(
                'UPDATE email_verification_tokens SET used_at = NOW()
                  WHERE customer_id = :id AND used_at IS NULL'
            )->execute(['id' => $customerId]);

            // Idempotent for an already-verified account (see docblock).
            $pdo->prepare(
                'UPDATE customers SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = :id'
            )->execute(['id' => $customerId]);

            Logger::info('Email verified', ['customer_id' => $customerId]);
            return $customerId;
        });
    }

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, Env::require('APP_KEY'));
    }
}
