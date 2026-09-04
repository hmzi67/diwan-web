<?php
declare(strict_types=1);

namespace Diwan\Payment;

use Diwan\Config\Env;
use InvalidArgumentException;

final class GatewayFactory
{
    public static function make(?string $name = null): GatewayInterface
    {
        $name ??= Env::get('PAYMENT_GATEWAY', 'jazzcash');

        return match (strtolower((string) $name)) {
            'jazzcash'  => new JazzCashGateway(),
            'easypaisa' => new EasyPaisaGateway(),
            'safepay'   => new SafepayGateway(),
            default     => throw new InvalidArgumentException("Unknown payment gateway: {$name}"),
        };
    }
}
