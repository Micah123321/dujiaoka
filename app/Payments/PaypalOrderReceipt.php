<?php

namespace App\Payments;

use Throwable;

class PaypalOrderReceipt
{
    public static function matches(array $order, string $id, string $sn, string $reference, string $usd, string $merchant): bool
    {
        $units = $order['purchase_units'] ?? null;
        if (($order['id'] ?? null) !== $id || !self::id($id) || ($order['intent'] ?? null) !== 'CAPTURE'
            || !in_array($order['status'] ?? null, ['CREATED', 'SAVED', 'APPROVED', 'COMPLETED', 'VOIDED', 'PAYER_ACTION_REQUIRED'], true)
            || !is_array($units) || array_keys($units) !== [0] || !is_array($units[0])) return false;
        $u = $units[0];
        return ($u['reference_id'] ?? null) === $reference && ($u['custom_id'] ?? null) === $reference
            && ($u['invoice_id'] ?? null) === $sn && ($u['payee']['merchant_id'] ?? null) === $merchant
            && self::amount($u['amount'] ?? null, $usd);
    }

    public static function captureId(array $order, string $id, string $sn, string $ref, string $usd, string $merchant): ?string
    {
        return self::captureIdWithStatus($order, $id, $sn, $ref, $usd, $merchant, 'COMPLETED');
    }

    public static function pendingCaptureId(array $order, string $id, string $sn, string $ref, string $usd, string $merchant): ?string
    {
        return self::captureIdWithStatus($order, $id, $sn, $ref, $usd, $merchant, 'PENDING');
    }

    private static function captureIdWithStatus(array $order, string $id, string $sn, string $ref, string $usd, string $merchant, string $status): ?string
    {
        if (!self::matches($order, $id, $sn, $ref, $usd, $merchant) || $order['status'] !== 'COMPLETED') return null;
        $captures = $order['purchase_units'][0]['payments']['captures'] ?? null;
        if (!is_array($captures) || array_keys($captures) !== [0] || !is_array($captures[0])) return null;
        $capture = $captures[0];
        if (($capture['status'] ?? null) !== $status || ($capture['final_capture'] ?? null) !== true
            || !self::amount($capture['amount'] ?? null, $usd) || !self::id($capture['id'] ?? null)) return null;
        // Order GET establishes containment; standalone Capture GET additionally checks related_ids.
        $related = $capture['supplementary_data']['related_ids']['order_id'] ?? $id;
        return $related === $id ? $capture['id'] : null;
    }

    public static function captureMatches(array $capture, string $captureId, string $id, string $usd): bool
    {
        return ($capture['id'] ?? null) === $captureId && self::id($captureId) && self::paid($capture, $usd)
            && ($capture['supplementary_data']['related_ids']['order_id'] ?? null) === $id;
    }

    public static function approvalUrl(array $order, string $mode): ?string
    {
        $expected = $mode === 'live' ? 'www.paypal.com' : ($mode === 'sandbox' ? 'www.sandbox.paypal.com' : null);
        if ($expected === null || !self::id($order['id'] ?? null)) return null;
        if (!is_array($order['links'] ?? null)) return null;
        foreach ($order['links'] as $link) {
            if (!is_array($link) || !in_array($link['rel'] ?? null, ['approve', 'payer-action'], true)
                || !is_string($link['href'] ?? null) || strlen($link['href']) > 2048) continue;
            $u = parse_url($link['href']);
            if (!is_array($u) || ($u['scheme'] ?? null) !== 'https' || ($u['host'] ?? null) !== $expected
                || ($u['path'] ?? null) !== '/checkoutnow' || isset($u['user']) || isset($u['pass']) || isset($u['port']) || isset($u['fragment'])) continue;
            if (($u['query'] ?? null) === 'token=' . $order['id']) return $link['href'];
        }
        return null;
    }

    private static function paid(array $capture, string $usd): bool
    {
        return ($capture['status'] ?? null) === 'COMPLETED' && ($capture['final_capture'] ?? null) === true
            && self::amount($capture['amount'] ?? null, $usd);
    }

    private static function amount($amount, string $usd): bool
    {
        if (!is_array($amount) || ($amount['currency_code'] ?? null) !== 'USD' || !is_string($amount['value'] ?? null)) return false;
        try { return CurrencyAmount::cny($amount['value']) === CurrencyAmount::cny($usd); }
        catch (Throwable $exception) { return false; }
    }

    private static function id($id): bool
    {
        return is_string($id) && preg_match('/\A[A-Z0-9]{17}\z/', $id) === 1;
    }
}
