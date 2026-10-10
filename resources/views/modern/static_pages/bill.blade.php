@php
    $modern = ['page' => 'bill', 'order' => [
        'sn' => (string) $order_sn, 'title' => (string) $title, 'quantity' => (int) $buy_amount,
        'price' => (string) $actual_price, 'goodsPrice' => (string) $goods_price, 'email' => (string) $email,
        'type' => (int) $type, 'status' => (int) $status, 'created' => (string) $created_at,
        'payName' => (string) ($pay['pay_name'] ?? ''), 'info' => (string) ($info ?? ''),
        'coupon' => (string) ($coupon['coupon'] ?? ''), 'couponDiscount' => (string) ($coupon_discount_price ?? '0'),
        'wholesaleDiscount' => (string) ($wholesale_discount_price ?? '0'),
        'payUrl' => isset($pay['pay_handleroute'], $pay['pay_check']) ? url('pay-gateway', ['handle' => urlencode($pay['pay_handleroute']), 'payway' => $pay['pay_check'], 'orderSN' => $order_sn]) : '',
    ]];
@endphp
@include('modern.layouts.app')
