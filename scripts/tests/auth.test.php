<?php
declare(strict_types=1);

use Diwan\Auth\EmailedTokens;
use Diwan\Auth\EmailVerificationService;
use Diwan\Auth\LoginAttempts;
use Diwan\Auth\PasswordResetService;
use Diwan\Auth\PasswordService;
use Diwan\Auth\Session;
use Diwan\Database\Database;
use Diwan\Support\Crypto;

/** A session cookie value built the way Session::start() builds one. */
function cookieFor(int $customerId, int $epoch, int $expires): string
{
    $payload = "{$customerId}.{$epoch}.{$expires}";
    return $payload . '.' . Crypto::hmac($payload);
}

function epochOf(int $customerId): int
{
    return (int) TestKit::scalar('SELECT session_epoch FROM customers WHERE id = :id', ['id' => $customerId]);
}

/** Reads the cookie the way an authenticated endpoint does. */
function sessionFrom(string $cookie): ?int
{
    $_COOKIE['diwan_session'] = $cookie;
    try {
        return Session::customerId();
    } finally {
        unset($_COOKIE['diwan_session']);
    }
}

function resetTokens(): EmailedTokens
{
    return new EmailedTokens(Database::pdo(), 'password_reset_tokens', 'Password reset');
}

return [
    'a valid session cookie identifies its customer' => static function (): void {
        $id = TestKit::customer();
        TestKit::same($id, sessionFrom(cookieFor($id, epochOf($id), time() + 3600)), 'customer id');
    },

    'a tampered, expired or malformed cookie is no session' => static function (): void {
        $id = TestKit::customer();
        $other = TestKit::customer();
        $good = cookieFor($id, epochOf($id), time() + 3600);
        $parts = explode('.', $good);
        $parts[0] = (string) $other;                      // swap in someone else's id
        TestKit::same(null, sessionFrom(implode('.', $parts)), 'id swapped under the old signature');
        TestKit::same(null, sessionFrom(cookieFor($id, epochOf($id), time() - 1)), 'expired');
        TestKit::same(null, sessionFrom('garbage'), 'malformed');
        TestKit::same(null, sessionFrom(''), 'empty');
    },

    'revokeAll ends every existing session and app token' => static function (): void {
        $id = TestKit::customer();
        $cookie = cookieFor($id, epochOf($id), time() + 3600);
        $appToken = Session::issueAppToken($id);
        Session::revokeAll(Database::pdo(), $id);
        TestKit::same(null, sessionFrom($cookie), 'cookie after revoke');
        TestKit::same(null, Session::customerIdFromAppToken($appToken), 'app token after revoke');
    },

    'app tokens and cookies cannot be replayed as each other' => static function (): void {
        $id = TestKit::customer();
        $appToken = Session::issueAppToken($id);
        TestKit::same($id, Session::customerIdFromAppToken($appToken), 'app token works as an app token');
        TestKit::same(null, sessionFrom($appToken), 'app token is not a cookie');
        TestKit::same(null, Session::customerIdFromAppToken(cookieFor($id, epochOf($id), time() + 3600)), 'cookie is not an app token');
    },

    'an emailed link redeems once, and takes the other open links with it' => static function (): void {
        $id = TestKit::customer();
        $ip = TestKit::ip();
        $tokens = resetTokens();
        $first  = $tokens->issue($id, $ip, 3600);
        $second = $tokens->issue($id, $ip, 3600);
        TestKit::check(Crypto::isToken($first), 'token shape');
        TestKit::same($id, Database::transaction(static fn () => resetTokens()->redeem($first, $ip)), 'first click');
        TestKit::same(null, Database::transaction(static fn () => resetTokens()->redeem($first, $ip)), 'second click');
        TestKit::same(null, Database::transaction(static fn () => resetTokens()->redeem($second, $ip)), 'the other open link');
    },

    'an expired or unknown emailed link redeems nothing' => static function (): void {
        $id = TestKit::customer();
        $token = resetTokens()->issue($id, TestKit::ip(), 3600);
        TestKit::exec('UPDATE password_reset_tokens SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE customer_id = :id', ['id' => $id]);
        TestKit::same(null, Database::transaction(static fn () => resetTokens()->redeem($token, TestKit::ip())), 'expired');
        TestKit::same(null, Database::transaction(static fn () => resetTokens()->redeem(Crypto::newToken(), TestKit::ip())), 'unknown');
    },

    'only token hashes are stored' => static function (): void {
        $id = TestKit::customer();
        $token = resetTokens()->issue($id, TestKit::ip(), 3600);
        TestKit::same(1, (int) TestKit::scalar('SELECT COUNT(*) FROM password_reset_tokens WHERE token_hash = :h', ['h' => Crypto::hmac($token)]), 'hash stored');
        TestKit::same(0, (int) TestKit::scalar('SELECT COUNT(*) FROM password_reset_tokens WHERE token_hash = :t', ['t' => $token]), 'raw token not stored');
    },

    'a password reset sets the password, verifies the email and ends old sessions' => static function (): void {
        $id = TestKit::customer();
        $cookie = cookieFor($id, epochOf($id), time() + 3600);
        $token = resetTokens()->issue($id, TestKit::ip(), 3600);
        TestKit::same($id, (new PasswordResetService())->reset($token, 'a-new-long-password', TestKit::ip()), 'reset');

        $row = Database::pdo()->query("SELECT password_hash, email_verified_at FROM customers WHERE id = {$id}")->fetch();
        TestKit::check(PasswordService::verify('a-new-long-password', (string) $row['password_hash']), 'new password works');
        TestKit::check($row['email_verified_at'] !== null, 'email counts as verified');
        TestKit::same(null, sessionFrom($cookie), 'pre-reset cookie');
    },

    'email verification stamps the account once' => static function (): void {
        $id = TestKit::customer();
        $token = (new EmailedTokens(Database::pdo(), 'email_verification_tokens', 'Verification'))->issue($id, TestKit::ip(), 3600);
        $service = new EmailVerificationService();
        TestKit::same($id, $service->verify($token, TestKit::ip()), 'verify');
        TestKit::check(TestKit::scalar('SELECT email_verified_at FROM customers WHERE id = :id', ['id' => $id]) !== null, 'stamped');
        TestKit::same(null, $service->verify($token, TestKit::ip()), 'link is single-use');
    },

    'reset requests are rate limited per customer' => static function (): void {
        $id = TestKit::customer();
        $email = TestKit::emailOf($id);
        $service = new PasswordResetService();
        for ($i = 0; $i < 7; $i++) {
            $service->request($email, TestKit::ip());     // a new IP each time: only the per-customer limit applies
        }
        TestKit::same(5, (int) TestKit::scalar('SELECT COUNT(*) FROM password_reset_tokens WHERE customer_id = :id', ['id' => $id]), 'links issued');
    },

    'sign-in is rate limited per email across IPs, and cleared by a success' => static function (): void {
        $email = TestKit::email();
        for ($i = 0; $i < 4; $i++) {
            LoginAttempts::record(TestKit::ip(), $email, 'bad_password');
        }
        TestKit::check(!LoginAttempts::isRateLimited(TestKit::ip(), $email), '4 failures are allowed');
        LoginAttempts::record(TestKit::ip(), strtoupper($email), 'bad_password');
        TestKit::check(LoginAttempts::isRateLimited(TestKit::ip(), $email), '5th failure (any case) trips the limit');
        LoginAttempts::clearFor($email);
        TestKit::check(!LoginAttempts::isRateLimited(TestKit::ip(), $email), 'cleared after a real sign-in');
    },

    'registration is rate limited per IP' => static function (): void {
        $ip = TestKit::ip();
        for ($i = 0; $i < 9; $i++) {
            LoginAttempts::record($ip, TestKit::email(), 'register');
        }
        TestKit::check(!LoginAttempts::isRegistrationRateLimited($ip), '9 sign-ups are allowed');
        LoginAttempts::record($ip, TestKit::email(), 'register');
        TestKit::check(LoginAttempts::isRegistrationRateLimited($ip), 'the 10th trips the limit');
    },
];
