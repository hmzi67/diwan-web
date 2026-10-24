<?php
declare(strict_types=1);

namespace Diwan\License;

use Diwan\Admin\AdminSettings;
use Diwan\Database\Database;
use Diwan\Notification\NotificationService;
use Diwan\Order\OrderService;
use Diwan\Plan\PlanCatalog;
use Diwan\Support\AuditLog;
use Diwan\Support\Logger;
use Diwan\Support\UserFacingException;
use PDO;

/**
 * Plan requests and their approval. There is no payment anywhere in this
 * flow: a customer asks for a plan, an administrator approves or rejects it,
 * and only an approval issues (or upgrades) a licence.
 *
 *   pending ──approve──▶ approved      (licence issued, or the customer's
 *      │                                current plan licence moved to the
 *      ├──reject───▶ rejected           new plan and extended)
 *      └──cancel───▶ cancelled (by the customer)
 *
 * Every transition happens under a row lock on the request, so approving
 * twice (a double click, two admins at once) issues exactly one licence: the
 * second call sees `approved` and returns the first call's licence.
 *
 * Issuance and activation are separate steps: approval issues a key (status
 * `active`, no devices); the customer activates it by entering the key in
 * the app (LicenseService::activate), which binds a device.
 */
final class LicenseRequestService
{
    private const MAX_MESSAGE = 1000;

    /** Submits a request for $planCode. Returns the new request row. */
    public function submit(int $customerId, string $planCode, array $input): array
    {
        $plan = (new PlanCatalog())->findActiveByCode($planCode);
        if ($plan === null) {
            throw new UserFacingException('That plan is not available.', 404);
        }
        $business = $this->text($input['business_name'] ?? '', 160, 'Business name');
        $phone = preg_replace('/[^\d+]/', '', (string) ($input['contact_phone'] ?? ''));
        if ($phone !== '' && (strlen($phone) < 7 || strlen($phone) > 20)) {
            throw new UserFacingException('Please enter a valid phone number.');
        }
        $message = $this->text($input['message'] ?? '', self::MAX_MESSAGE, 'Message');

        $requestId = Database::transaction(function (PDO $pdo) use ($customerId, $plan, $business, $phone, $message): int {
            // Serialises one customer's submissions (two tabs, a double click).
            $customer = $pdo->prepare(
                'SELECT id, email, status, email_verified_at FROM customers WHERE id = :id AND deleted_at IS NULL FOR UPDATE'
            );
            $customer->execute(['id' => $customerId]);
            $customer = $customer->fetch();
            if (!$customer) {
                throw new UserFacingException('Account not found.', 404);
            }
            if ($customer['status'] !== 'active') {
                throw new UserFacingException('This account is suspended. Please contact support.', 403);
            }
            if ($customer['email_verified_at'] === null) {
                throw new UserFacingException('Please verify your email address first.', 403);
            }

            $pending = $pdo->prepare("SELECT id FROM license_requests WHERE customer_id = :id AND status = 'pending' LIMIT 1");
            $pending->execute(['id' => $customerId]);
            if ($pending->fetchColumn() !== false) {
                throw new UserFacingException('You already have a request waiting for approval. Cancel it to ask for a different plan.', 409);
            }

            $last = $pdo->prepare('SELECT status FROM license_requests WHERE customer_id = :id ORDER BY id DESC LIMIT 1');
            $last->execute(['id' => $customerId]);
            if ($last->fetchColumn() === 'rejected' && !AdminSettings::bool('allow_request_after_rejection')) {
                throw new UserFacingException('Your last request was declined. Please contact support to discuss your options.', 403);
            }

            $pdo->prepare(
                'INSERT INTO license_requests (customer_id, plan_id, status, business_name, contact_phone, message, created_at)
                 VALUES (:customer, :plan, "pending", :business, :phone, :message, NOW())'
            )->execute([
                'customer' => $customerId,
                'plan'     => $plan['id'],
                'business' => $business,
                'phone'    => $phone === '' ? null : $phone,
                'message'  => $message,
            ]);
            $id = (int) $pdo->lastInsertId();
            if ($business !== null || $phone !== '') {
                $pdo->prepare(
                    'UPDATE customers SET business_name = COALESCE(:business, business_name),
                                          phone = COALESCE(:phone, phone) WHERE id = :id'
                )->execute(['business' => $business, 'phone' => $phone === '' ? null : $phone, 'id' => $customerId]);
            }
            AuditLog::record('customer', $customerId, (string) $customer['email'], 'license_request.submit',
                'license_request', $id, ['plan' => $plan['code']], $pdo);
            return $id;
        });

        $notifications = new NotificationService();
        $notifications->toCustomer(
            $customerId,
            'license_request.submitted',
            "We received your {$plan['name']} request",
            "Thanks — your request for the {$plan['name']} plan is waiting for approval. "
            . "We'll let you know here and by email as soon as it has been reviewed. No payment is taken online.",
            '/dashboard.html#plan'
        );
        $notifications->toAdmins(
            'license_request.new',
            "New {$plan['name']} request",
            'A customer is waiting for a licence decision.' . ($business !== null ? " Business: {$business}." : ''),
            '/admin/#requests'
        );
        Logger::info('Licence request submitted', ['request_id' => $requestId, 'plan' => $plan['code']]);

        return $this->find($requestId);
    }

    /** A customer withdraws their own pending request. */
    public function cancel(int $customerId, int $requestId): array
    {
        Database::transaction(function (PDO $pdo) use ($customerId, $requestId): void {
            $request = $this->lock($pdo, $requestId);
            if ($request === null || (int) $request['customer_id'] !== $customerId) {
                throw new UserFacingException('Request not found.', 404);
            }
            if ($request['status'] === 'cancelled') {
                return;
            }
            if ($request['status'] !== 'pending') {
                throw new UserFacingException('Only a pending request can be cancelled.', 409);
            }
            $pdo->prepare("UPDATE license_requests SET status = 'cancelled', updated_at = NOW() WHERE id = :id")
                ->execute(['id' => $requestId]);
            AuditLog::record('customer', $customerId, null, 'license_request.cancel', 'license_request', $requestId, [], $pdo);
        });
        return $this->find($requestId);
    }

    /**
     * Approves a pending request. $options: plan_code (approve a different
     * plan than asked), period_days, max_devices, note (shown to the
     * customer).
     *
     * When the customer already holds an active plan licence, that licence is
     * moved to the approved plan and its expiry extended (an upgrade or
     * renewal keeps the key and the devices). Otherwise a new licence is
     * issued and its key is returned — the only time this response carries it.
     *
     * @return array{request: array, license_id: int, license_key: ?string, mode: string, already: bool}
     */
    public function approve(int $requestId, array $admin, array $options = []): array
    {
        $catalog = new PlanCatalog();
        $periodDays = filter_var($options['period_days'] ?? AdminSettings::int('license_period_days'), FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 3660]]);
        if ($periodDays === false) {
            throw new UserFacingException('Licence period must be between 1 and 3660 days.');
        }
        $note = $this->text($options['note'] ?? '', self::MAX_MESSAGE, 'Note');

        $outcome = Database::transaction(function (PDO $pdo) use ($requestId, $admin, $options, $catalog, $periodDays, $note): array {
            $request = $this->lock($pdo, $requestId);
            if ($request === null) {
                throw new UserFacingException('Request not found.', 404);
            }
            if ($request['status'] === 'approved') {
                // Idempotent: the first approval already did the work.
                return ['already' => true, 'license_id' => (int) $request['license_id'], 'license_key' => null, 'mode' => 'already'];
            }
            if ($request['status'] !== 'pending') {
                throw new UserFacingException("This request was already {$request['status']}.", 409);
            }

            $plan = isset($options['plan_code']) && $options['plan_code'] !== ''
                ? $catalog->findActiveByCode((string) $options['plan_code'])
                : $catalog->findById((int) $request['plan_id']);
            if ($plan === null) {
                throw new UserFacingException('That plan is not available.', 404);
            }
            $maxDevices = filter_var($options['max_devices'] ?? $plan['max_devices'], FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 1000]]);
            if ($maxDevices === false) {
                throw new UserFacingException('Devices must be between 1 and 1000.');
            }

            $customer = $pdo->prepare('SELECT id, email, status FROM customers WHERE id = :id FOR UPDATE');
            $customer->execute(['id' => $request['customer_id']]);
            $customer = $customer->fetch();
            if (!$customer || $customer['status'] !== 'active') {
                throw new UserFacingException('The customer account is suspended or no longer exists.', 409);
            }

            // An existing, still-usable plan licence is upgraded/renewed in
            // place rather than duplicated.
            $existing = $pdo->prepare(
                "SELECT id, plan_id FROM licenses
                  WHERE customer_id = :customer AND plan_id IS NOT NULL AND status IN ('active','expired')
                  ORDER BY id DESC LIMIT 1 FOR UPDATE"
            );
            $existing->execute(['customer' => $customer['id']]);
            $existing = $existing->fetch();

            if ($existing) {
                $licenseId = (int) $existing['id'];
                $pdo->prepare(
                    "UPDATE licenses
                        SET plan_id = :plan, max_devices = GREATEST(max_devices, :devices), status = 'active',
                            expires_at = DATE_ADD(GREATEST(NOW(), COALESCE(expires_at, NOW())), INTERVAL :days DAY),
                            status_changed_at = NOW(), status_reason = :reason
                      WHERE id = :id"
                )->execute([
                    'plan'    => $plan['id'],
                    'devices' => $maxDevices,
                    'days'    => $periodDays,
                    'reason'  => "Request #{$requestId} approved",
                    'id'      => $licenseId,
                ]);
                $key = null;
                $mode = (int) $existing['plan_id'] === (int) $plan['id'] ? 'renewed' : 'plan_changed';
            } else {
                $pdo->prepare(
                    'INSERT INTO orders (order_ref, product_id, customer_id, customer_email, customer_phone,
                                         amount_paisa, currency, gateway, status, created_at, paid_at)
                     SELECT :ref, p.id, :customer, :email, :phone, :amount, :currency, "admin", "fulfilled", NOW(), NULL
                       FROM products p WHERE p.sku = "diwan-plan-licence"'
                )->execute([
                    'ref'      => OrderService::newReference('PL'),
                    'customer' => $customer['id'],
                    'email'    => $customer['email'],
                    'phone'    => (string) ($request['contact_phone'] ?? ''),
                    'amount'   => min((int) $plan['price_minor'], 4294967295),
                    'currency' => $plan['currency'],
                ]);
                $orderId = (int) $pdo->lastInsertId();
                if ($orderId === 0) {
                    throw new \RuntimeException('Plan licence product is missing. Apply migration 016.');
                }
                $issued = (new LicenseService())->issueForPlan(
                    $pdo, $orderId, (string) $customer['email'], (int) $plan['id'], $requestId,
                    (int) $admin['id'], $periodDays, $maxDevices
                );
                $licenseId = (int) $issued['license_id'];
                $key = $issued['license_key'];
                $mode = 'issued';
            }

            $pdo->prepare(
                "UPDATE license_requests
                    SET status = 'approved', plan_id = :plan, decided_by = :admin, decided_at = NOW(),
                        decision_note = :note, license_id = :license, updated_at = NOW()
                  WHERE id = :id"
            )->execute([
                'plan'    => $plan['id'],
                'admin'   => $admin['id'],
                'note'    => $note,
                'license' => $licenseId,
                'id'      => $requestId,
            ]);
            AuditLog::record('admin', (int) $admin['id'], (string) $admin['email'], 'license_request.approve',
                'license_request', $requestId, [
                    'plan'         => $plan['code'],
                    'requested'    => (int) $request['plan_id'] !== (int) $plan['id'] ? 'different plan' : 'as requested',
                    'license_id'   => $licenseId,
                    'mode'         => $mode,
                    'period_days'  => $periodDays,
                    'max_devices'  => $maxDevices,
                    'key_prefix'   => $key !== null ? substr($key, 0, 11) : null,
                ], $pdo);

            return [
                'already'     => false,
                'license_id'  => $licenseId,
                'license_key' => $key,
                'mode'        => $mode,
                'customer_id' => (int) $customer['id'],
                'plan_name'   => (string) $plan['name'],
                'period_days' => $periodDays,
                'note'        => $note,
            ];
        });

        if (!$outcome['already']) {
            $noteLine = $outcome['note'] !== null ? "\n\nNote from the Diwan team: {$outcome['note']}" : '';
            $body = match ($outcome['mode']) {
                'issued' => "Your {$outcome['plan_name']} plan has been approved for {$outcome['period_days']} days. "
                    . 'Your licence key is in your dashboard: download Diwan, open it, and enter the key to activate this device.',
                'plan_changed' => "Your licence has been moved to the {$outcome['plan_name']} plan and extended by {$outcome['period_days']} days. "
                    . 'The app picks up the new plan the next time it checks in (within a few hours while online), with the same key.',
                default => "Your {$outcome['plan_name']} plan has been renewed for another {$outcome['period_days']} days. Nothing to do in the app.",
            };
            (new NotificationService())->toCustomer(
                $outcome['customer_id'],
                $outcome['mode'] === 'issued' ? 'license.issued' : 'license.approved',
                "Approved: your {$outcome['plan_name']} plan",
                $body . $noteLine,
                '/dashboard.html#plan'
            );
            Logger::info('Licence request approved', ['request_id' => $requestId, 'mode' => $outcome['mode']]);
        }

        return [
            'request'     => $this->find($requestId),
            'license_id'  => $outcome['license_id'],
            'license_key' => $outcome['license_key'],
            'mode'        => $outcome['mode'],
            'already'     => $outcome['already'],
        ];
    }

    /** Rejects a pending request; $note is shown to the customer. */
    public function reject(int $requestId, array $admin, ?string $note): array
    {
        $note = $this->text($note ?? '', self::MAX_MESSAGE, 'Reason');
        $notify = Database::transaction(function (PDO $pdo) use ($requestId, $admin, $note): ?array {
            $request = $this->lock($pdo, $requestId);
            if ($request === null) {
                throw new UserFacingException('Request not found.', 404);
            }
            if ($request['status'] === 'rejected') {
                return null;
            }
            if ($request['status'] !== 'pending') {
                throw new UserFacingException("This request was already {$request['status']}.", 409);
            }
            $pdo->prepare(
                "UPDATE license_requests SET status = 'rejected', decided_by = :admin, decided_at = NOW(),
                        decision_note = :note, updated_at = NOW() WHERE id = :id"
            )->execute(['admin' => $admin['id'], 'note' => $note, 'id' => $requestId]);
            AuditLog::record('admin', (int) $admin['id'], (string) $admin['email'], 'license_request.reject',
                'license_request', $requestId, ['has_note' => $note !== null], $pdo);
            return $request;
        });

        if ($notify !== null) {
            $again = AdminSettings::bool('allow_request_after_rejection')
                ? ' You are welcome to submit a new request at any time.'
                : ' Please contact support if you would like to discuss it.';
            (new NotificationService())->toCustomer(
                (int) $notify['customer_id'],
                'license_request.rejected',
                "Your {$notify['plan_name']} request was not approved",
                'We were not able to approve your request this time.'
                . ($note !== null ? "\n\nReason: {$note}" : '') . $again,
                '/dashboard.html#plan'
            );
        }
        return $this->find($requestId);
    }

    /** The customer's own requests, newest first. */
    public function forCustomer(int $customerId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT r.id, r.status, r.business_name, r.contact_phone, r.message, r.decision_note,
                    r.created_at, r.decided_at, r.license_id, p.code AS plan_code, p.name AS plan_name
               FROM license_requests r JOIN plans p ON p.id = r.plan_id
              WHERE r.customer_id = :id ORDER BY r.id DESC LIMIT 20'
        );
        $stmt->execute(['id' => $customerId]);
        return $stmt->fetchAll();
    }

    public function find(int $requestId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT r.*, p.code AS plan_code, p.name AS plan_name, p.price_minor, p.currency,
                    c.email AS customer_email, c.name AS customer_name, a.email AS decided_by_email
               FROM license_requests r
               JOIN plans p ON p.id = r.plan_id
               JOIN customers c ON c.id = r.customer_id
          LEFT JOIN admin_users a ON a.id = r.decided_by
              WHERE r.id = :id'
        );
        $stmt->execute(['id' => $requestId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new UserFacingException('Request not found.', 404);
        }
        return $row;
    }

    private function lock(PDO $pdo, int $requestId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT r.*, p.name AS plan_name FROM license_requests r JOIN plans p ON p.id = r.plan_id
              WHERE r.id = :id FOR UPDATE'
        );
        $stmt->execute(['id' => $requestId]);
        return $stmt->fetch() ?: null;
    }

    /** Trimmed text or null; refuses over-long input instead of truncating it. */
    private function text(mixed $value, int $max, string $label): ?string
    {
        $text = trim((string) $value);
        if (mb_strlen($text) > $max) {
            throw new UserFacingException("{$label} can be at most {$max} characters.");
        }
        return $text === '' ? null : $text;
    }
}
