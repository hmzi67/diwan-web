<?php
declare(strict_types=1);

namespace Diwan\Payment;

use Diwan\Config\Env;

/**
 * Safepay integration.
 *
 * NOTE: the checkout-session call and the exact webhook signature header must
 * be confirmed against your Safepay dashboard before going live. The signature
 * verification below is the shape Safepay documents (HMAC-SHA256 of the raw
 * request body); do not relax it.
 */
final class SafepayGateway implements GatewayInterface
{
    public function name(): string
    {
        return 'safepay';
    }

    public function buildCheckout(string $orderRef, int $amountPaisa, array $customer): array
    {
        // Safepay expects a server-to-server call that returns a tracker token,
        // which is then appended to the hosted checkout URL.
        $response = $this->post('https://api.getsafepay.com/order/v1/init', [
            'merchant_api_key' => Env::require('SAFEPAY_API_KEY'),
            'intent'           => 'CYBERSOURCE',
            'mode'             => 'payment',
            'currency'         => 'PKR',
            'amount'           => $amountPaisa / 100,
            'order_id'         => $orderRef,
        ]);

        $token = $response['data']['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Safepay did not return a checkout token');
        }

        return [
            'redirect_url' => 'https://getsafepay.com/checkout/pay',
            'fields'       => [
                'tracker'     => $token,
                'order_id'    => $orderRef,
                'source'      => 'custom',
                'redirect_url'=> rtrim(Env::require('APP_URL'), '/') . '/api/payment-return.php',
            ],
        ];
    }

    public function verifyCallback(array $payload, array $headers, string $rawBody): bool
    {
        $signature = $headers['x-sfpy-signature'] ?? $headers['X-SFPY-SIGNATURE'] ?? '';
        if ($signature === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, Env::require('SAFEPAY_WEBHOOK_SECRET'));
        return hash_equals($expected, (string) $signature);
    }

    public function orderRefFrom(array $payload): ?string
    {
        $ref = $payload['data']['order_id'] ?? $payload['order_id'] ?? null;
        return $ref !== null ? (string) $ref : null;
    }

    public function isPaid(array $payload): bool
    {
        $state = strtolower((string) ($payload['data']['state'] ?? $payload['state'] ?? ''));
        return in_array($state, ['paid', 'completed', 'tracker_ended'], true);
    }

    public function transactionIdFrom(array $payload): ?string
    {
        $id = $payload['data']['tracker'] ?? $payload['tracker'] ?? null;
        return $id !== null ? (string) $id : null;
    }

    private function post(string $url, array $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('Safepay request failed: ' . $err);
        }
        if ($code >= 400) {
            throw new \RuntimeException('Safepay returned HTTP ' . $code);
        }
        return json_decode((string) $raw, true) ?: [];
    }
}
