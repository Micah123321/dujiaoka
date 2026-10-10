@php
    $modern = ['page' => 'qrpay', 'qr' => [
        'value' => (string) $qr_code, 'price' => (string) $actual_price, 'name' => (string) $payname,
        'sn' => (string) $orderid, 'checkUrl' => url('check-order-status', ['orderSN' => $orderid]),
        'detailUrl' => url('detail-order-sn', ['orderSN' => $orderid]), 'jumpUrl' => (string) ($jump_payuri ?? ''),
        'minutes' => (int) dujiaoka_config_get('order_expire_time', 5),
    ]];
@endphp
@include('modern.layouts.app')
