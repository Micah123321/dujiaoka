<?php

namespace App\Payments;

use Yansongda\Pay\Plugin\Alipay\V2\StartPlugin;

class RawKeyAlipayStartPlugin extends StartPlugin
{
    protected function getPayload(array $params): array
    {
        $payload = parent::getPayload($params);
        unset($payload['app_cert_sn'], $payload['alipay_root_cert_sn']);

        return $payload;
    }

    protected function getAppCertSn(string $tenant, array $config): string
    {
        return '';
    }

    protected function getAlipayRootCertSn(string $tenant, array $config): string
    {
        return '';
    }
}
