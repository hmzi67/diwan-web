<?php
declare(strict_types=1);

namespace Diwan\Auth;

use Diwan\Config\Env;
use Diwan\Database\Database;
use Diwan\Download\DownloadService;
use Diwan\Support\Logger;
use Diwan\Support\Mailer;
use PDO;

/**
 * "Reset your password" and "set your first password" — one mechanism.
 *
 * request() mints a short-lived, single-use token and stores only its HMAC;
 * reset() redeems it exactly once. Both cases differ only in the email's
 * wording: an account that has never had a password (password_hash IS NULL)
 * gets "set your password" copy instead of "reset it", so nobody is asked to
 * reset something they never set. Same mechanism either way — no separate
 * first-password system to build, test and secure.
 *
 * Like EmailVerificationService, this is deliberately silent about whether an
 * email has an account — the caller returns an identical response either way.
 */
final class PasswordResetService
{
    private const TABLE                     = 'password_reset_tokens';
    private const TOKEN_TTL_MINUTES         = 60;
    private const MAX_PER_IP_PER_HOUR       = 10;
    private const MAX_PER_CUSTOMER_PER_HOUR = 5;

    public function request(string $email, string $ip, string $platform = ''): void
    {
        $tokens = new EmailedTokens(Database::pdo(), self::TABLE, 'Password reset');
        $tokens->prune();

        if ($tokens->issuedFromIpLastHour($ip) >= self::MAX_PER_IP_PER_HOUR) {
            Logger::warning('Password reset rate limited by IP', ['ip' => $ip]);
            return;
        }

        // Unlike email verification, this does NOT create an account: a reset
        // request for an unknown address is a dead end, not a sign-up.
        $customer = Customers::findByEmail($email);
        if (!$customer) {
            Logger::info('Password reset requested for unknown email', ['ip' => $ip]);
            return;
        }

        $customerId = (int) $customer['id'];
        $isFirstPassword = $customer['password_hash'] === null;

        if ($tokens->issuedToCustomerLastHour($customerId) >= self::MAX_PER_CUSTOMER_PER_HOUR) {
            Logger::warning('Password reset rate limited by customer', ['customer_id' => $customerId]);
            return;
        }

        $token = $tokens->issue($customerId, $ip, self::TOKEN_TTL_MINUTES * 60);

        // Points at the static page, not an API endpoint: the customer still
        // has to type a new password, so there is nothing to redeem yet.
        $link = Env::appUrl('/auth/reset-password.html?token=' . $token);
        if (in_array($platform, DownloadService::PLATFORMS, true)) {
            $link .= '&platform=' . $platform;
        }

        $expiry = 'This link works once and expires in ' . self::TOKEN_TTL_MINUTES . " minutes.\n";
        $sent = $isFirstPassword
            ? Mailer::send(
                (string) $customer['email'],
                'Set your Diwan password',
                "Your Diwan account was created before we offered passwords.\n\n"
                . "Set one here to sign in:\n\n{$link}\n\n"
                . $expiry
                . "If you didn't request this, you can ignore this email.\n"
            )
            : Mailer::send(
                (string) $customer['email'],
                'Reset your Diwan password',
                "Set a new password for your Diwan account:\n\n{$link}\n\n"
                . $expiry
                . "If you didn't request this, you can ignore this email — your\n"
                . "current password still works.\n"
            );

        if (!$sent) {
            Logger::error('Password reset email failed to send', ['customer_id' => $customerId]);
        } else {
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
        $newHash = PasswordService::hash($newPassword);

        return Database::transaction(function (PDO $pdo) use ($rawToken, $newHash, $ip): ?int {
            $customerId = (new EmailedTokens($pdo, self::TABLE, 'Password reset'))->redeem($rawToken, $ip);
            if ($customerId === null) {
                return null;
            }

            // Setting a password here proves control of the mailbox exactly as
            // much as the verification link does — so the reset counts as
            // verification too. Without it, an owner who reset their password
            // would still be locked out of sign-in for not having clicked a
            // separate link about it.
            $pdo->prepare(
                'UPDATE customers
                    SET password_hash = :hash, password_set_at = NOW(),
                        email_verified_at = COALESCE(email_verified_at, NOW())
                  WHERE id = :id'
            )->execute(['hash' => $newHash, 'id' => $customerId]);

            // Evicts anyone already signed in as this customer on any device.
            // Someone resetting because their account was taken over is trying
            // to end exactly that session, and a stateless cookie would
            // otherwise stay valid for its full 30 days.
            Session::revokeAll($pdo, $customerId);

            Logger::info('Password reset completed', ['customer_id' => $customerId]);
            return $customerId;
        });
    }
}
