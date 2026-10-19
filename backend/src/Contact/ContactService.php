<?php
declare(strict_types=1);

namespace Diwan\Contact;

use Diwan\Config\Env;
use Diwan\Database\Database;
use Diwan\Support\Logger;
use Diwan\Support\Mailer;
use InvalidArgumentException;

/**
 * Contact form: validate, store, then email the enquiry to CONTACT_EMAIL.
 *
 * The row is written before the email is attempted, so a mail failure never
 * loses a message — it is still in contact_messages with email_sent = 0.
 * Those same rows are the rate-limit window, so no separate attempts table.
 */
final class ContactService
{
    /** topic => subject line, matching the suggestions on contact.html */
    public const TOPICS = [
        'sales'    => 'Sales question',
        'support'  => 'Technical support',
        'licence'  => 'Licence not received',
        'device'   => 'Device change',
        'refund'   => 'Refund request',
        'privacy'  => 'Privacy request',
        'other'    => 'General enquiry',
    ];

    public const MAX_MESSAGE = 3000;
    private const MIN_MESSAGE = 10;
    private const MAX_NAME = 120;

    private const WINDOW_MINUTES = 60;
    private const MAX_PER_IP = 5;
    private const MAX_PER_EMAIL = 3;

    /**
     * @param array<string,mixed> $input
     * @throws InvalidArgumentException with a customer-facing message
     */
    public function submit(array $input, string $ip): void
    {
        // Control characters out of the name: it ends up in the subject header.
        $name     = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) ($input['name'] ?? '')));
        $email    = trim((string) ($input['email'] ?? ''));
        $topic    = (string) ($input['topic'] ?? '');
        $orderRef = strtoupper(trim((string) ($input['order_ref'] ?? '')));
        $message  = trim(str_replace("\r\n", "\n", (string) ($input['message'] ?? '')));

        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            throw new InvalidArgumentException('Please enter your name.');
        }
        if ($email === '' || strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Please enter a valid email address.');
        }
        if (!isset(self::TOPICS[$topic])) {
            throw new InvalidArgumentException('Please choose what your message is about.');
        }
        if ($orderRef !== '' && !preg_match('/^[A-Z0-9-]{4,40}$/', $orderRef)) {
            throw new InvalidArgumentException('That order reference doesn\'t look right — it begins with DW.');
        }
        $length = mb_strlen($message);
        if ($length < self::MIN_MESSAGE) {
            throw new InvalidArgumentException('Please tell us a little more — at least ' . self::MIN_MESSAGE . ' characters.');
        }
        if ($length > self::MAX_MESSAGE) {
            throw new InvalidArgumentException('Please keep your message under ' . self::MAX_MESSAGE . ' characters.');
        }

        if ($this->isRateLimited($ip, $email)) {
            throw new InvalidArgumentException(
                "You've sent several messages recently. Please wait a while, or email us directly."
            );
        }

        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO contact_messages (name, email, topic, order_ref, message, ip)
             VALUES (:name, :email, :topic, :order_ref, :message, :ip)'
        )->execute([
            'name'      => $name,
            'email'     => $email,
            'topic'     => $topic,
            'order_ref' => $orderRef !== '' ? $orderRef : null,
            'message'   => $message,
            'ip'        => $ip,
        ]);
        $id = (int) $pdo->lastInsertId();

        $to = Env::get('CONTACT_EMAIL');
        if ($to === null) {
            Logger::error('CONTACT_EMAIL is not set; contact message stored but not emailed', ['id' => $id]);
            return;
        }

        $body = "New message from the Diwan contact form.\n\n"
            . "Name:    {$name}\n"
            . "Email:   {$email}\n"
            . 'Topic:   ' . self::TOPICS[$topic] . "\n"
            . ($orderRef !== '' ? "Order:   {$orderRef}\n" : '')
            . "Ref:     #{$id}\n\n"
            . str_repeat('-', 40) . "\n\n"
            . $message . "\n\n"
            . str_repeat('-', 40) . "\n"
            . "Reply to this email to answer {$name} directly.\n";

        $sent = Mailer::send(
            $to,
            '[Diwan] ' . self::TOPICS[$topic] . ' — ' . $name,
            $body,
            null,
            $email
        );

        if ($sent) {
            $pdo->prepare('UPDATE contact_messages SET email_sent = 1 WHERE id = :id')->execute(['id' => $id]);
            Logger::info('Contact message sent', ['id' => $id, 'topic' => $topic]);
        } else {
            Logger::error('Contact message stored but email failed', ['id' => $id]);
        }
    }

    private function isRateLimited(string $ip, string $email): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT
               SUM(ip = :ip)       AS by_ip,
               SUM(email = :email) AS by_email
             FROM contact_messages
            WHERE created_at > DATE_SUB(NOW(), INTERVAL :mins MINUTE)
              AND (ip = :ip2 OR email = :email2)'
        );
        $stmt->execute([
            'ip'     => $ip,
            'ip2'    => $ip,
            'email'  => $email,
            'email2' => $email,
            'mins'   => self::WINDOW_MINUTES,
        ]);
        $row = $stmt->fetch();

        return (int) ($row['by_ip'] ?? 0) >= self::MAX_PER_IP
            || (int) ($row['by_email'] ?? 0) >= self::MAX_PER_EMAIL;
    }
}
