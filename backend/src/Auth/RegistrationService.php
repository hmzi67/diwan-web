<?php
declare(strict_types=1);

namespace Diwan\Auth;

use Diwan\Config\Env;
use Diwan\Database\Database;
use Diwan\Support\Logger;
use Diwan\Support\Mailer;
use Diwan\Trial\TrialService;
use PDO;
use PDOException;

/**
 * Account creation for register.php. The outcome is deliberately not returned:
 * the endpoint answers identically whether or not the address already had an
 * account, and only the email that goes out differs.
 */
final class RegistrationService
{
    /** $email must already be validated; $passwordHash comes from PasswordService::hash(). */
    public function register(string $email, string $passwordHash, string $ip, ?string $plan): void
    {
        $email = Customers::normalizeEmail($email);
        $existing = Customers::findByEmail($email);

        if ($existing) {
            $this->notifyExisting($existing, $ip, $plan);
            return;
        }

        try {
            // TEMPORARY no-payment onboarding: the account and its free trial
            // are committed together, so the licence is waiting in the
            // dashboard as soon as the address is verified.
            $customerId = Database::transaction(static function (PDO $pdo) use ($email, $passwordHash): int {
                $id = Customers::create($pdo, $email, $passwordHash);
                (new TrialService())->ensureForCustomer($pdo, $id);
                return $id;
            });
        } catch (PDOException $e) {
            // Unique key on customers.email: a concurrent request won the race.
            // An unrelated uniqueness failure must not pretend signup succeeded.
            if (($e->errorInfo[1] ?? null) !== 1062 || Customers::findByEmail($email) === null) {
                throw $e;
            }
            Logger::info('Registration raced into existing email', ['ip' => $ip]);
            return;
        }

        Logger::info('Customer registered, pending verification', ['customer_id' => $customerId]);
        (new EmailVerificationService())->send($email, $ip, $plan);
    }

    /**
     * Never touches the password — "registering" an address you don't own
     * must never take over the account that does.
     */
    private function notifyExisting(array $customer, string $ip, ?string $plan): void
    {
        Logger::info('Registration attempted for existing email', ['customer_id' => $customer['id']]);

        if ($customer['email_verified_at'] === null) {
            // Same mailbox proof either way: hand the real owner a working link
            // so the notice cannot be used to stall someone else's signup.
            (new EmailVerificationService())->send((string) $customer['email'], $ip, $plan);
            return;
        }

        Mailer::send(
            (string) $customer['email'],
            'You already have a Diwan account',
            "Someone (hopefully you) tried to sign up for Diwan with this email address,\n"
            . "but an account already exists — so nothing was changed.\n\n"
            . 'To sign in: ' . Env::appUrl('/auth/login.html') . "\n"
            . "If you've forgotten your password: " . Env::appUrl('/auth/forgot-password.html') . "\n"
        );
    }
}
