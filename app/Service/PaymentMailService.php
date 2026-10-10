<?php

namespace App\Service;

use App\Jobs\MailSend;
use App\Models\Order;

class PaymentMailService
{
    public function recipe(Order $order): array
    {
        $manual = $order->type == Order::MANUAL_PROCESSING;
        return [
            'to' => (string) ($manual ? dujiaoka_config_get('manage_email', '') : $order->email),
            'template' => $manual ? 'manual_send_manage_mail' : 'card_send_user_email',
            'data' => [
                'created_at' => (string) $order->created_at,
                'product_name' => $order->goods->gd_name ?? $order->title,
                'webname' => dujiaoka_config_get('text_logo', '独角数卡'),
                'weburl' => config('app.url') ?? 'http://dujiaoka.com',
                'ord_info' => str_replace(PHP_EOL, '<br/>', $order->info),
                'ord_title' => $order->title,
                'order_id' => $order->order_sn,
                'buy_amount' => $order->buy_amount,
                'ord_price' => $order->actual_price,
            ],
        ];
    }

    public function statusRecipe(Order $order): array
    {
        $templates = [
            Order::STATUS_PENDING => 'pending_order',
            Order::STATUS_COMPLETED => 'completed_order',
            Order::STATUS_FAILURE => 'failed_order',
        ];
        $settings = cache('system-setting');
        return [
            'to' => (string) $order->email,
            'template' => $templates[(int) $order->status],
            'data' => [
                'created_at' => date('Y-m-d H:i'),
                'ord_title' => $order->title,
                'webname' => $settings['text_logo'] ?? '独角数卡',
                'weburl' => config('app.url'),
                'order_id' => $order->order_sn,
                'ord_price' => $order->actual_price,
                'ord_info' => str_replace(PHP_EOL, '<br/>', (string) $order->info),
            ],
        ];
    }

    public function job(array $recipe, bool $requireDelivery = true): MailSend
    {
        if (!isset($recipe['to'], $recipe['template'], $recipe['data']) ||
            !is_string($recipe['to']) || !is_string($recipe['template']) || !is_array($recipe['data'])) {
            throw new \UnexpectedValueException('Invalid payment mail snapshot.');
        }
        $template = app('Service\EmailtplService')->detailByToken($recipe['template']);
        $body = replace_mail_tpl($template, $recipe['data']);
        if (!$body) {
            throw new \RuntimeException('Payment mail template is unavailable.');
        }
        return new MailSend($recipe['to'], $body['tpl_name'], $body['tpl_content'], $requireDelivery);
    }
}
