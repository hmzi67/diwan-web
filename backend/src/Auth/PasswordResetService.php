<?php
declare(strict_types=1);

namespace Diwan\Auth;

use Diwan\Config\Env;
use Diwan\Database\Database;
use Diwan\Support\Logger;
use Diwan\Support\Mailer;
use PDO;

/**
 * "Reset your password" and "set your first password" — one mechanism.
 *
 * Structurally identical to LoginService: request() mints a short-lived,
 * single-use token and stores only its HMAC; reset() redeems it exactly once.
 *
 * The two cases differ only in the email's wording. A customer created during
 * the magic-link era has password_hash IS NULL and has never had a password —
 * sending them down a "reset" path they never set would be confusing, so the
 * copy adapts. The mechanism, tokens and expiry are the same, which is the
 * whole point: no separate first-password system to build, test and secure.
 *
 * Like requestLink() before it, this is deliberately silent about whether an
 * email has an account — the caller returns an identical response either way.
 */
final class PasswordResetService
{
    private const TOKEN_TTL_MINUTES        = 60;
    private const MAX_PER_IP_PER_HOUR      = 10;
    private const MAX_PER_CUSTOMER_PER_HOUR = 5;

    public function request(string $email, string $ip): void
    {
        $email = strtolower(trim($email));
        $pdo = Database::pdo();

        $ipCount = $pdo->prepare(
            'SELECT COUNT(*) FROM password_reset_tokens
              WHERE issued_ip = :ip AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );
        $ipCount->execute(['ip' => $ip]);
        if ((int) $ipCount->fetchColumn() >= self::MAX_PER_IP_PER_HOUR) {
            Logger::warning('Password reset rate limited by IP', ['ip' => $ip]);
            return;
        }

        // Unlike the magic-link flow, this does NOT create an account: a reset
        // request for an unknown address is a dead end, not a sign-up. Sign-up
        // is register.php and requires a password.
        $customer = $pdo->prepare(
            'SELECT id, password_hash FROM customers WHERE email = :email LIMIT 1'
        );
        $customer->execute(['email' => $email]);
        $customer = $customer->fetch();

        if (!$customer) {
            Logger::info('Password reset requested for unknown email', ['ip' => $ip]);
            return;
        }

        $customerId = (int) $customer['id'];
        $isFirstPassword = $customer['password_hash'] === null;

        $customerCount = $pdo->prepare(
            'SELECT COUNT(*) FROM password_reset_tokens
              WHERE customer_id = :id AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );
        $customerCount->execute(['id' => $customerId]);
        if ((int) $customerCount->fetchColumn() >= self::MAX_PER_CUSTOMER_PER_HOUR) {
            Logger::warning('Password reset rate limited by customer', ['customer_id' => $customerId]);
            return;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable('+' . self::TOKEN_TTL_MINUTES . ' minutes'))
            ->format('Y-m-d H:i:s');

        $pdo->prepare(
            'INSERT INTO password_reset_tokens (token_hash, customer_id, issued_ip, expires_at, created_at)
             VALUES (:hash, :id, :ip, :expires, NOW())'
        )->execute([
            'hash'    => $this->hashToken($token),
            'id'      => $customerId,
            'ip'      => $ip,
            'expires' => $expiresAt,
        ]);

        // Points at the static page, not an API endpoint: the customer still
        // has to type a new password, so there is nothing to redeem yet.
        $link = rtrim((string) Env::require('APP_URL'), '/')
            . '/auth/reset-password.html?token=' . $token;

        $sent = $isFirstPassword
            ? Mailer::send(
                $email,
                'Set your Diwan password',
                "Your Diwan account was created before we offered passwords.\n\n"
                . "Set one here to sign in:\n\n{$link}\n\n"
                . 'This link works once and expires in ' . self::TOKEN_TTL_MINUTES . " minutes.\n"
                . "If you didn't request this, you can ignore this email.\n",
                'From: ' . Env::get('MAIL_FROM', 'no-reply@localhost')
            )
            : Mailer::send(
                $email,
                'Reset your Diwan password',
                "Set a new password for your Diwan account:\n\n{$link}\n\n"
                . 'This link works once and expires in ' . self::TOKEN_TTL_MINUTES . " minutes.\n"
                . "If you didn't request this, you can ignore this email — your\n"
                . "current password still works.\n",
                'From: ' . Env::get('MAIL_FROM', 'no-reply@localhost')
            );

        if (!$sent) {
            Logger::error('Password reset email failed to send', ['customer_id' => $customerId]);
        } else {
            // Key deliberately avoids the substring "pass": Logger redacts any
            // context key containing it, which would blank this out.
            Logger::info('Password reset link sent', [
                'customer_id'  => $customerId,
                'is_first_set' => $isFirstPassword,
            ]);
        }
    }

    /**
     * Redeems a token and sets the new password, atomically. Returns the
     * customer id on success, or null for anything invalid, expired or used.
     */
    public function reset(string $rawToken, string $newPassword, string $ip): ?int
    {
        $hash = $this->hashToken($rawToken);
        $newHash = PasswordService::hash($newPassword);

        return Database::transaction(function (PDO $pdo) use ($hash, $newHash, $ip) {
            $stmt = $pdo->prepare(
                'SELECT id, customer_id, used_at, expires_at FROM password_reset_tokens
                  WHERE token_hash = :hash LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['hash' => $hash]);
            $token = $stmt->fetch();

            if (!$token) {
                Logger::warning('Password reset redeemed: not found', ['ip' => $ip]);
                return null;
            }
            if ($token['used_at'] !== null) {
                Logger::warning('Password reset redeemed: already used', ['ip' => $ip]);
                return null;
            }
            if (strtotime((string) $token['expires_at']) < time()) {
                Logger::warning('Password reset redeemed: expired', ['ip' => $ip]);
                return null;
            }

            $customerId = (int) $token['customer_id'];

            $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = :id')
                ->execute(['id' => $token['id']]);

            $pdo->prepare(
                'UPDATE customers SET password_hash = :hash, password_set_at = NOW() WHERE id = :id'
            )->execute(['hash' => $newHash, 'id' => $customerId]);

            // Anyone holding an older reset link for this account loses it —
            // otherwise a stolen-but-unused link still works after the real
            // owner has recovered the account.
            $pdo->prepare(
                'UPDATE password_reset_tokens SET used_at = NOW()
                  WHERE customer_id = :id AND used_at IS NULL'
            )->execute(['id' => $customerId]);

            // Evicts anyone already signed in as this customer on any device.
            // Someone resetting because their account was taken over is trying
            // to end exactly that session, and a stateless cookie would
            // otherwise stay valid for its full 30 days.
            Session::revokeAll($pdo, $customerId);

            Logger::info('Password reset completed', ['customer_id' => $customerId]);
            return $customerId;
        });
    }

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, Env::require('APP_KEY'));
    }
}
