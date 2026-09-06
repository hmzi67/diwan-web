<?php
declare(strict_types=1);

namespace Diwan\Auth;

use Diwan\Config\Env;
use Diwan\Database\Database;
use Diwan\Support\Logger;
use Diwan\Support\Mailer;
use PDO;

/**
 * Passwordless login AND signup: a single-use magic link emailed to the
 * customer. An unknown email creates a new customers row on the spot (this
 * is the site's only signup path — checkout requires a session, and a
 * session only ever comes from here) then proceeds exactly like an existing
 * customer.
 *
 * Same two-step shape as DownloadService: requestLink() mints a short-lived,
 * single-use token (only its hash is stored); verify() redeems it exactly
 * once. Still silent about which case happened — new vs. existing account —
 * mirroring activate-license.php's "don't become an enumeration oracle"
 * rule: the caller's response is identical either way.
 */
final class LoginService
{
    private const LINK_TTL_MINUTES = 20;
    private const MAX_LINKS_PER_IP_PER_HOUR = 10;
    private const MAX_LINKS_PER_CUSTOMER_PER_HOUR = 5;

    /**
     * Finds or creates the customer for this email, then mints a token and
     * emails the link. $plan (a product SKU, optional) is carried along in
     * the link so verify-login.php can send the customer straight back to
     * the checkout they started — it's a UI convenience, not trusted for
     * anything security-sensitive.
     */
    public function requestLink(string $email, string $ip, ?string $plan = null): void
    {
        $email = strtolower(trim($email));
        $pdo = Database::pdo();

        $ipCount = $pdo->prepare(
            'SELECT COUNT(*) FROM login_tokens
              WHERE issued_ip = :ip AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );
        $ipCount->execute(['ip' => $ip]);
        if ((int) $ipCount->fetchColumn() >= self::MAX_LINKS_PER_IP_PER_HOUR) {
            Logger::warning('Login link rate limited by IP', ['ip' => $ip]);
            return;
        }

        $customerId = $this->findOrCreateCustomer($pdo, $email);

        $customerCount = $pdo->prepare(
            'SELECT COUNT(*) FROM login_tokens
              WHERE customer_id = :id AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
        );
        $customerCount->execute(['id' => $customerId]);
        if ((int) $customerCount->fetchColumn() >= self::MAX_LINKS_PER_CUSTOMER_PER_HOUR) {
            Logger::warning('Login link rate limited by customer', ['customer_id' => $customerId]);
            return;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = (new \DateTimeImmutable('+' . self::LINK_TTL_MINUTES . ' minutes'))->format('Y-m-d H:i:s');

        $pdo->prepare(
            'INSERT INTO login_tokens (token_hash, customer_id, issued_ip, expires_at, created_at)
             VALUES (:hash, :id, :ip, :expires, NOW())'
        )->execute([
            'hash'    => $this->hashToken($token),
            'id'      => $customerId,
            'ip'      => $ip,
            'expires' => $expiresAt,
        ]);

        $link = rtrim((string) Env::require('APP_URL'), '/') . '/api/verify-login.php?token=' . $token;
        // Only a bare SKU shape is ever appended — this is a UI convenience
        // (which checkout page to land on after verifying), not something
        // trusted for auth or given any special handling server-side.
        if ($plan !== null && preg_match('/^[a-z0-9-]{1,64}$/', $plan) === 1) {
            $link .= '&plan=' . $plan;
        }

        $sent = Mailer::send(
            $email,
            'Your Diwan login link',
            "Click below to log in to your Diwan account:\n\n{$link}\n\n"
            . "This link works once and expires in " . self::LINK_TTL_MINUTES . " minutes.\n"
            . "If you didn't request this, you can ignore this email.\n",
            'From: ' . Env::get('MAIL_FROM', 'no-reply@localhost')
        );

        if (!$sent) {
            Logger::error('Login link email failed to send', ['customer_id' => $customerId]);
        } else {
            Logger::info('Login link sent', ['customer_id' => $customerId]);
        }
    }

    /**
     * Redeems a token exactly once. Returns the customer row on success, or
     * null for anything invalid, expired, or already used.
     */
    public function verify(string $rawToken, string $ip): ?array
    {
        $hash = $this->hashToken($rawToken);

        return Database::transaction(function (PDO $pdo) use ($hash, $ip) {
            $stmt = $pdo->prepare(
                'SELECT id, customer_id, used_at, expires_at FROM login_tokens
                  WHERE token_hash = :hash LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['hash' => $hash]);
            $token = $stmt->fetch();

            if (!$token) {
                Logger::warning('Login link redeemed: not found', ['ip' => $ip]);
                return null;
            }
            if ($token['used_at'] !== null) {
                Logger::warning('Login link redeemed: already used', ['ip' => $ip, 'token_id' => $token['id']]);
                return null;
            }
            if (strtotime((string) $token['expires_at']) < time()) {
                Logger::warning('Login link redeemed: expired', ['ip' => $ip, 'token_id' => $token['id']]);
                return null;
            }

            $pdo->prepare('UPDATE login_tokens SET used_at = NOW() WHERE id = :id')
                ->execute(['id' => $token['id']]);

            $customer = $pdo->prepare('SELECT id, email, name FROM customers WHERE id = :id LIMIT 1');
            $customer->execute(['id' => $token['customer_id']]);
            $customer = $customer->fetch();

            if (!$customer) {
                // Orphaned token (customer row deleted) — treat as invalid.
                return null;
            }

            Logger::info('Customer logged in', ['customer_id' => $customer['id']]);
            return $customer;
        });
    }

    /**
     * The site's only signup path: an email with no customers row gets one,
     * silently, the first time it asks for a login link. Two concurrent
     * requests for a brand-new email would both try to INSERT — the unique
     * key on customers.email makes the loser's insert fail, and it just
     * re-reads the row the winner created instead of erroring.
     */
    private function findOrCreateCustomer(PDO $pdo, string $email): int
    {
        $customer = $pdo->prepare('SELECT id FROM customers WHERE email = :email LIMIT 1');
        $customer->execute(['email' => $email]);
        $customer = $customer->fetch();

        if ($customer) {
            return (int) $customer['id'];
        }

        try {
            $pdo->prepare('INSERT INTO customers (email, created_at) VALUES (:email, NOW())')
                ->execute(['email' => $email]);
            $customerId = (int) $pdo->lastInsertId();
            Logger::info('New customer signed up via login link', ['customer_id' => $customerId]);
            return $customerId;
        } catch (\PDOException $e) {
            // Unique constraint hit: someone else's concurrent request won
            // the race and created this row first.
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            $customer = $pdo->prepare('SELECT id FROM customers WHERE email = :email LIMIT 1');
            $customer->execute(['email' => $email]);
            return (int) $customer->fetch()['id'];
        }
    }

    private function hashToken(string $token): string
    {
        return hash_hmac('sha256', $token, Env::require('APP_KEY'));
    }
}
