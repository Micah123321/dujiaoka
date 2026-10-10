<?php

namespace Tests\Fixtures;

class ThemeData
{
    public static function pages(): array
    {
        $pay = ['pay_name' => 'Fixture pay', 'pay_handleroute' => '/pay/alipay', 'pay_check' => 'alipay', 'id' => 1, 'merchant_pem' => 'PRIVATE-PAYMENT-MARKER'];
        $goods = [
            'id' => 1, 'gd_name' => '测试商品', 'picture' => '', 'type' => 1,
            'actual_price' => '10.00', 'in_stock' => 8, 'buy_limit_num' => 3,
            'wholesale_price_cnf' => [['number' => 2, 'price' => '9.00']],
            'description' => '<p>商品说明</p>', 'gd_description' => '<p>商品说明</p>',
            'other_ipu' => [], 'open_coupon' => 1, 'payways' => [$pay],
        ];
        $order = [
            'order_sn' => 'FIXTUREORDER0001', 'title' => '测试商品 x 1',
            'goods_price' => '10.00', 'buy_amount' => 1, 'email' => 'fixture@example.test',
            'type' => 1, 'status' => 4, 'coupon' => null, 'coupon_discount_price' => '0.00',
            'wholesale_discount_price' => '0.00', 'actual_price' => '10.00',
            'created_at' => '2026-01-01 12:00:00', 'info' => 'FIXTURE-CARD',
            'pay' => $pay, 'search_pwd' => 'PRIVATE-SEARCH-MARKER',
        ];
        return [
            'home' => ['data' => [['id' => 1, 'gp_name' => '数字商品', 'goods' => [$goods]]]],
            'buy' => $goods,
            'bill' => array_merge($order, ['status' => 1, 'info' => '']),
            'searchOrder' => [],
            'orderinfo' => ['orders' => [$order]],
            'qrpay' => ['orderid' => $order['order_sn'], 'payname' => 'Fixture pay', 'actual_price' => '10.00', 'qr_code' => 'https://example.test/fixture-payment'],
        ];
    }
}
