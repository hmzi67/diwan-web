<?php
declare(strict_types=1);

namespace Diwan\Apple;

use Diwan\Config\Env;
use Diwan\Database\Database;
use Diwan\Support\Logger;
use Diwan\Trial\TrialService;
use PDO;
use RuntimeException;

/**
 * Customer accounts behind Sign in with Apple, for the website and the app.
 *
 * An Apple ID is identified by its stable `sub`, never by email: the email
 * can be a private-relay address, and the user can change which address
 * Apple shares. Lookup order on every sign-in:
 *   1. a customer already linked to this `sub`  → that account;
 *   2. a customer with the same Apple-VERIFIED email → link it (the person
 *      who registered with a password can now also use Apple);
 *   3. otherwise → a new account, created with its trial in the same
 *      transaction, exactly like register.php.
 */
final class AppleAccountService
{
    /**
     * @param array{sub:string, email:?string, email_verified:bool} $claims verified identity-token claims
     * @return array{customer_id:int, session_epoch:int, created:bool}
     */
    public function signIn(array $claims, ?string $name, ?string $refreshToken, string $clientId): array
    {
        return Database::transaction(function (PDO $pdo) use ($claims, $name, $refreshToken, $clientId): array {
            $bySub = $pdo->prepare(
                'SELECT id, session_epoch, name FROM customers WHERE apple_sub = :sub LIMIT 1 FOR UPDATE'
            );
            $bySub->execute(['sub' => $claims['sub']]);
            $customer = $bySub->fetch();

            if (!$customer && $claims['email'] !== null && $claims['email_verified']) {
                $byEmail = $pdo->prepare(
                    'SELECT id, session_epoch, name, apple_sub, email_verified_at
                       FROM customers WHERE email = :email LIMIT 1 FOR UPDATE'
                );
                $byEmail->execute(['email' => $claims['email']]);
                $customer = $byEmail->fetch();
                if ($customer && $customer['apple_sub'] !== null) {
                    // Same address, different Apple ID. Never re-point an
                    // account from one Apple ID to another.
                    throw new RuntimeException('That email address is already linked to a different Apple ID.');
                }
                if ($customer && $customer['email_verified_at'] === null) {
                    // An unverified row means nobody has proved they own this
                    // mailbox — anyone can register someone else's address and
                    // pick its password. Apple has just proved ownership, so the
                    // account becomes this person's: drop the unproven password
                    // and end any sessions, or its author would share the account.
                    $pdo->prepare(
                        'UPDATE customers
                            SET apple_sub = :sub, email_verified_at = NOW(),
                                password_hash = NULL, password_set_at = NULL,
                                session_epoch = session_epoch + 1
                          WHERE id = :id'
                    )->execute(['sub' => $claims['sub'], 'id' => $customer['id']]);
                    $customer['session_epoch'] = (int) $customer['session_epoch'] + 1;
                    Logger::info('Apple ID claimed unverified account', ['customer_id' => (int) $customer['id']]);
                } elseif ($customer) {
                    $pdo->prepare('UPDATE customers SET apple_sub = :sub WHERE id = :id')
                        ->execute(['sub' => $claims['sub'], 'id' => $customer['id']]);
                    Logger::info('Apple ID linked to existing account', ['customer_id' => (int) $customer['id']]);
                }
            }

            $created = false;
            if (!$customer) {
                if ($claims['email'] === null) {
                    throw new RuntimeException(
                        'Apple did not share an email address. Remove Diwan from "Sign in with Apple" in your '
                        . 'Apple ID settings, then sign in again and choose an email to share.'
                    );
                }
                $pdo->prepare(
                    'INSERT INTO customers (email, name, apple_sub, email_verified_at, created_at)
                     VALUES (:email, :name, :sub, IF(:verified = 1, NOW(), NULL), NOW())'
                )->execute([
                    'email'    => $claims['email'],
                    'name'     => $name,
                    'sub'      => $claims['sub'],
                    'verified' => $claims['email_verified'] ? 1 : 0,   // Apple checked the mailbox
                ]);
                $id = (int) $pdo->lastInsertId();
                (new TrialService())->ensureForCustomer($pdo, $id);
                $customer = ['id' => $id, 'session_epoch' => 0, 'name' => $name];
                $created  = true;
                Logger::info('Customer registered with Apple', ['customer_id' => $id]);
            }

            $customerId = (int) $customer['id'];
            // Apple sends the name only on the very first authorization.
            if ($name !== null && $name !== '' && ($customer['name'] ?? null) === null) {
                $pdo->prepare('UPDATE customers SET name = :name WHERE id = :id')
                    ->execute(['name' => mb_substr($name, 0, 160), 'id' => $customerId]);
            }
            if ($refreshToken !== null) {
                $pdo->prepare(
                    'UPDATE customers SET apple_refresh_token_encrypted = :token, apple_token_client_id = :client
                      WHERE id = :id'
                )->execute(['token' => $this->encrypt($refreshToken), 'client' => $clientId, 'id' => $customerId]);
            }

            return [
                'customer_id'   => $customerId,
                'session_epoch' => (int) $customer['session_epoch'],
                'created'       => $created,
            ];
        });
    }

    /**
     * The UUID the app passes to StoreKit as `appAccountToken`, so a purchase
     * made while signed in carries its owner inside Apple's signed
     * transaction — including in refund notifications. Random, stored, and
     * stable per account (never derived from the id, which would leak it).
     */
    public function appAccountToken(int $customerId): string
    {
        return Database::transaction(function (PDO $pdo) use ($customerId): string {
            $stmt = $pdo->prepare('SELECT app_account_token FROM customers WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $customerId]);
            $existing = $stmt->fetchColumn();
            if (is_string($existing) && $existing !== '') {
                return $existing;
            }
            $bytes    = random_bytes(16);
            $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);   // version 4
            $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);   // RFC 4122 variant
            $uuid     = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
            $pdo->prepare('UPDATE customers SET app_account_token = :t WHERE id = :id')
                ->execute(['t' => $uuid, 'id' => $customerId]);
            return $uuid;
        });
    }

    public function customerIdForAppAccountToken(string $token): ?int
    {
        $stmt = Database::pdo()->prepare('SELECT id FROM customers WHERE app_account_token = :t LIMIT 1');
        $stmt->execute(['t' => strtolower($token)]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Deletes the account's personal data. Orders and licences are kept —
     * they are the purchase record, and a paid licence keeps working on the
     * device it is activated on — but every copy of the email on them is
     * scrubbed. Every session and app token dies with the epoch bump.
     * Apple's refresh token is revoked after the commit (App Review rule).
     */
    public function delete(int $customerId): void
    {
        $revoke = Database::transaction(function (PDO $pdo) use ($customerId): ?array {
            $stmt = $pdo->prepare(
                'SELECT apple_refresh_token_encrypted, apple_token_client_id FROM customers
                  WHERE id = :id AND deleted_at IS NULL FOR UPDATE'
            );
            $stmt->execute(['id' => $customerId]);
            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }

            $pdo->prepare(
                'UPDATE customers
                    SET email = :email, name = NULL, password_hash = NULL, password_set_at = NULL,
                        apple_sub = NULL, apple_refresh_token_encrypted = NULL, apple_token_client_id = NULL,
                        app_account_token = NULL, session_epoch = session_epoch + 1, deleted_at = NOW()
                  WHERE id = :id'
            )->execute(['email' => "deleted-{$customerId}@deleted.invalid", 'id' => $customerId]);
            $pdo->prepare('UPDATE orders SET customer_email = "", customer_phone = "" WHERE customer_id = :id')
                ->execute(['id' => $customerId]);
            $pdo->prepare('UPDATE licenses SET customer_email = "" WHERE customer_id = :id')
                ->execute(['id' => $customerId]);
            $pdo->prepare('DELETE FROM email_verification_tokens WHERE customer_id = :id')->execute(['id' => $customerId]);
            $pdo->prepare('DELETE FROM password_reset_tokens WHERE customer_id = :id')->execute(['id' => $customerId]);

            $token = $this->decrypt($row['apple_refresh_token_encrypted']);
            return $token !== null && $row['apple_token_client_id'] !== null
                ? ['token' => $token, 'client_id' => (string) $row['apple_token_client_id']]
                : null;
        });

        if ($revoke !== null && !AppleAuthClient::revoke($revoke['token'], $revoke['client_id'])) {
            Logger::warning('Account deleted but Apple token was not revoked', ['customer_id' => $customerId]);
        }
        Logger::info('Customer account deleted', ['customer_id' => $customerId]);
    }

    /** AES-256-GCM under a key derived from APP_KEY, separate from the licence-key one. */
    private function encrypt(string $plain): string
    {
        $nonce  = random_bytes(12);
        $tag    = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $nonce, $tag);
        return base64_encode($nonce . $tag . $cipher);
    }

    private function decrypt(?string $encoded): ?string
    {
        $raw = $encoded !== null ? base64_decode($encoded, true) : false;
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA,
            substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }

    private function key(): string
    {
        return hash_hmac('sha256', 'apple-refresh-token', Env::require('APP_KEY'), true);
    }
}
