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
 * Same mechanism as PasswordResetService (EmailedTokens: hash-only tokens,
 * dual-window rate limits, redeem inside a transaction) because it is the same problem:
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
    private const TABLE                     = 'email_verification_tokens';
    private const TOKEN_TTL_HOURS           = 24;
    private const MAX_PER_IP_PER_HOUR       = 10;
    private const MAX_PER_CUSTOMER_PER_HOUR = 5;

    /**
     * Sends a verification link to an address that has an account but has not
     * verified it. Unknown or already-verified addresses are a silent no-op —
     * the caller responds identically either way, so this cannot be used to
     * find out which emails have accounts.
     */
    public function send(string $email, string $ip, ?string $plan = null): void
    {
        $tokens = new EmailedTokens(Database::pdo(), self::TABLE, 'Verification');
        $tokens->prune();

        if ($tokens->issuedFromIpLastHour($ip) >= self::MAX_PER_IP_PER_HOUR) {
            Logger::warning('Verification link rate limited by IP', ['ip' => $ip]);
            return;
        }

        $customer = Customers::findByEmail($email);
        if (!$customer || $customer['email_verified_at'] !== null) {
            Logger::info('Verification link requested for unknown or verified email', ['ip' => $ip]);
            return;
        }

        $customerId = (int) $customer['id'];
        if ($tokens->issuedToCustomerLastHour($customerId) >= self::MAX_PER_CUSTOMER_PER_HOUR) {
            Logger::warning('Verification link rate limited by customer', ['customer_id' => $customerId]);
            return;
        }

        $token = $tokens->issue($customerId, $ip, self::TOKEN_TTL_HOURS * 3600);

        // Points at the static page, not an API endpoint: the page reads the
        // token from its own query string and POSTs it back, so the browser
        // never navigates to an API URL directly.
        $link = Env::appUrl('/auth/verify-email.html?token=' . $token);
        // Bare-SKU shape only, same rule as everywhere else plan rides in a
        // URL: a UI convenience for landing back on the chosen checkout after
        // verifying, never trusted for anything security-sensitive.
        if ($plan !== null && preg_match('/^[a-z0-9-]{1,64}$/', $plan) === 1) {
            $link .= '&plan=' . rawurlencode($plan);
        }

        $sent = Mailer::send(
            (string) $customer['email'],
            'Verify your Diwan email address',
            "One click to finish creating your Diwan account:\n\n{$link}\n\n"
            . 'This link works once and expires in ' . self::TOKEN_TTL_HOURS . " hours.\n"
            . "If you didn't sign up for Diwan, you can ignore this email — nothing\n"
            . "has been created that you need to undo.\n"
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
     * The stamp is idempotent: an account verified some other way (a password
     * reset counts too) keeps its original verification time.
     */
    public function verify(string $rawToken, string $ip): ?int
    {
        return Database::transaction(function (PDO $pdo) use ($rawToken, $ip): ?int {
            $customerId = (new EmailedTokens($pdo, self::TABLE, 'Verification'))->redeem($rawToken, $ip);
            if ($customerId === null) {
                return null;
            }

            $pdo->prepare(
                'UPDATE customers SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = :id'
            )->execute(['id' => $customerId]);

            Logger::info('Email verified', ['customer_id' => $customerId]);
            return $customerId;
        });
    }
}
