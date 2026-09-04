<?php
declare(strict_types=1);

namespace Diwan\Payment;

use Diwan\Config\Env;

/**
 * EasyPaisa (Telenor Microfinance) hosted checkout.
 *
 * EasyPaisa signs the request with AES-128-ECB over the sorted parameter
 * string using the merchant hash key. Confirm the parameter set against the
 * integration pack you were issued before enabling this in production.
 */
final class EasyPaisaGateway implements GatewayInterface
{
    public function name(): string
    {
        return 'easypaisa';
    }

    public function buildCheckout(string $orderRef, int $amountPaisa, array $customer): array
    {
        $fields = [
            'storeId'            => Env::require('EASYPAISA_STORE_ID'),
            'amount'             => number_format($amountPaisa / 100, 1, '.', ''),
            'postBackURL'        => rtrim(Env::require('APP_URL'), '/') . '/api/payment-webhook.php',
            'orderRefNum'        => $orderRef,
            'expiryDate'         => (new \DateTimeImmutable('+1 hour'))->format('Ymd His'),
            'merchantHashedReq'  => '',
            'autoRedirect'       => '1',
            'paymentMethod'      => '',
            'emailAddr'          => (string) ($customer['email'] ?? ''),
            'mobileNum'          => (string) ($customer['phone'] ?? ''),
        ];
        $fields['merchantHashedReq'] = $this->sign($fields);

        return [
            'redirect_url' => 'https://easypay.easypaisa.com.pk/easypay/Index.jsf',
            'fields'       => $fields,
        ];
    }

    public function verifyCallback(array $payload, array $headers, string $rawBody): bool
    {
        $received = (string) ($payload['merchantHashedReq'] ?? '');
        return $received !== '' && hash_equals($this->sign($payload), $received);
    }

    public function orderRefFrom(array $payload): ?string
    {
        $ref = $payload['orderRefNumber'] ?? $payload['orderRefNum'] ?? null;
        return $ref !== null ? (string) $ref : null;
    }

    public function isPaid(array $payload): bool
    {
        return (string) ($payload['status'] ?? '') === '0000';
    }

    public function transactionIdFrom(array $payload): ?string
    {
        $id = $payload['transactionId'] ?? null;
        return $id !== null ? (string) $id : null;
    }

    private function sign(array $fields): string
    {
        unset($fields['merchantHashedReq']);
        $fields = array_filter($fields, static fn ($v) => (string) $v !== '');
        ksort($fields, SORT_STRING);

        $pairs = [];
        foreach ($fields as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        $encrypted = openssl_encrypt(
            implode('&', $pairs),
            'AES-128-ECB',
            Env::require('EASYPAISA_HASH_KEY'),
            OPENSSL_RAW_DATA
        );

        return base64_encode((string) $encrypted);
    }
}
