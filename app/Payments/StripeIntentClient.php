<?php

namespace App\Payments;

use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;
use Stripe\StripeClient;
use Throwable;

class StripeIntentClient
{
    public function create(string $apiKey, array $params, string $idempotencyKey): array
    {
        return $this->request(function () use ($apiKey, $params, $idempotencyKey) {
            return $this->client($apiKey)->paymentIntents->create($params, ['idempotency_key' => $idempotencyKey])->toArray();
        });
    }

    public function retrieve(string $apiKey, string $id): array
    {
        return $this->request(function () use ($apiKey, $id) {
            return $this->client($apiKey)->paymentIntents->retrieve($id, ['expand' => ['latest_charge']])->toArray();
        });
    }

    private function client(string $apiKey): StripeClient
    {
        return new StripeClient(['api_key' => $apiKey, 'stripe_version' => \Stripe\Util\ApiVersion::CURRENT,
            'api_base' => 'https://api.stripe.com', 'max_network_retries' => 0]);
    }

    private function request(callable $request): array
    {
        $http = ApiRequestor::httpClient();
        if ($http instanceof CurlClient) {
            $oldConnect = $http->getConnectTimeout();
            $oldTimeout = $http->getTimeout();
            $http->setConnectTimeout(3);
            $http->setTimeout(10);
        }
        try {
            return $request();
        } catch (Throwable $exception) {
            throw new RuntimeException('Payment provider unavailable.');
        } finally {
            if ($http instanceof CurlClient) {
                $http->setConnectTimeout($oldConnect);
                $http->setTimeout($oldTimeout);
            }
        }
    }
}
