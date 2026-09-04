<?php
declare(strict_types=1);

namespace Diwan\Payment;

use Diwan\Config\Env;

/**
 * JazzCash HTTP (Page Redirection) integration.
 *
 * Integrity check: HMAC-SHA256 over the integrity salt followed by every
 * non-empty pp_* field, sorted by key and joined with '&'.
 */
final class JazzCashGateway implements GatewayInterface
{
    public function name(): string
    {
        return 'jazzcash';
    }

    public function buildCheckout(string $orderRef, int $amountPaisa, array $customer): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Karachi'));

        $fields = [
            'pp_Version'             => '1.1',
            'pp_TxnType'             => 'MWALLET',
            'pp_Language'            => 'EN',
            'pp_MerchantID'          => Env::require('JAZZCASH_MERCHANT_ID'),
            'pp_SubMerchantID'       => '',
            'pp_Password'            => Env::require('JAZZCASH_PASSWORD'),
            'pp_BankID'              => '',
            'pp_ProductID'           => '',
            'pp_TxnRefNo'            => $orderRef,
            'pp_Amount'              => (string) $amountPaisa,   // JazzCash expects paisa
            'pp_TxnCurrency'         => 'PKR',
            'pp_TxnDateTime'         => $now->format('YmdHis'),
            'pp_BillReference'       => $orderRef,
            'pp_Description'         => 'Diwan POS licence',
            'pp_TxnExpiryDateTime'   => $now->modify('+1 hour')->format('YmdHis'),
            'pp_ReturnURL'           => rtrim(Env::require('APP_URL'), '/') . '/api/payment-return.php',
            'pp_SecureHash'          => '',
            'ppmpf_1'                => (string) ($customer['email'] ?? ''),
            'ppmpf_2'                => (string) ($customer['phone'] ?? ''),
        ];

        $fields['pp_SecureHash'] = $this->secureHash($fields);

        return [
            'redirect_url' => Env::require('JAZZCASH_ENDPOINT'),
            'fields'       => $fields,
        ];
    }

    public function verifyCallback(array $payload, array $headers, string $rawBody): bool
    {
        $received = (string) ($payload['pp_SecureHash'] ?? '');
        if ($received === '') {
            return false;
        }
        // hash_equals: constant-time, so an attacker cannot time-probe the hash.
        return hash_equals($this->secureHash($payload), strtoupper($received));
    }

    public function orderRefFrom(array $payload): ?string
    {
        $ref = $payload['pp_TxnRefNo'] ?? $payload['pp_BillReference'] ?? null;
        return $ref !== null ? (string) $ref : null;
    }

    public function isPaid(array $payload): bool
    {
        // '000' = success, '121' = success (already processed) in JazzCash docs.
        return in_array((string) ($payload['pp_ResponseCode'] ?? ''), ['000', '121'], true);
    }

    public function transactionIdFrom(array $payload): ?string
    {
        $id = $payload['pp_RetreivalReferenceNo'] ?? $payload['pp_TxnRefNo'] ?? null;
        return $id !== null ? (string) $id : null;
    }

    /** @param array<string,mixed> $fields */
    private function secureHash(array $fields): string
    {
        unset($fields['pp_SecureHash']);
        ksort($fields, SORT_STRING);

        $parts = [Env::require('JAZZCASH_INTEGRITY_SALT')];
        foreach ($fields as $key => $value) {
            if (!str_starts_with((string) $key, 'pp')) {
                continue; // pp_* and ppmpf_* only
            }
            $value = (string) $value;
            if ($value !== '') {
                $parts[] = $value;
            }
        }

        return strtoupper(hash_hmac(
            'sha256',
            implode('&', $parts),
            Env::require('JAZZCASH_INTEGRITY_SALT')
        ));
    }
}
