@php
    $modern = ['page' => 'orders', 'orders' => []];
    foreach ($orders as $order) {
        $modern['orders'][] = [
            'sn' => (string) $order['order_sn'], 'title' => (string) $order['title'], 'quantity' => (int) $order['buy_amount'],
            'price' => (string) $order['actual_price'], 'goodsPrice' => (string) ($order['goods_price'] ?? '0'),
            'email' => (string) $order['email'], 'type' => (int) $order['type'], 'status' => (int) $order['status'],
            'created' => (string) $order['created_at'], 'payName' => (string) ($order['pay']['pay_name'] ?? ''),
            'info' => (string) ($order['info'] ?? ''), 'coupon' => '', 'couponDiscount' => '0', 'wholesaleDiscount' => '0',
            'payUrl' => $order['status'] == \App\Models\Order::STATUS_WAIT_PAY ? url('bill/' . $order['order_sn']) : '',
        ];
    }
@endphp
@include('modern.layouts.app')
