<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ApiHook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 任务最大尝试次数。
     *
     * @var int
     */
    public $tries = 2;

    /**
     * 任务运行的超时时间。
     *
     * @var int
     */
    public $timeout = 30;

    public $retryAfter = 30;

    /**
     * @var Order
     */
    private $order;

    private $requireHook = false;

    /**
     * 商品服务层.
     * @var \App\Service\PayService
     */
    private $goodsService;


    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Order $order, bool $requireHook = false)
    {
        $this->order = $order;
        $this->requireHook = $requireHook;
    }

    public function backoff(): int
    {
        // Keep one delay source for Laravel 6 retryAfter and modern queue payloads.
        return $this->retryAfter;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $goodInfo = app('Service\GoodsService')->detail($this->order->goods_id);
        // 判断是否有配置支付回调
        if (empty($goodInfo->api_hook)) {
            if ($this->requireHook) {
                throw new \RuntimeException('Order webhook is no longer configured.');
            }
            return;
        }
        $postdata = [
            'title' => $this->order->title,
            'order_sn' => $this->order->order_sn,
            'email' => $this->order->email,
            'actual_price' => $this->order->actual_price,
            'order_info' => $this->order->info,
            'good_id' => $goodInfo->id,
            'gd_name' => $goodInfo->gd_name

        ];

        
        $response = app(\GuzzleHttp\Client::class)->request('POST', $goodInfo->api_hook, [
            'json' => $postdata,
            'connect_timeout' => 5,
            'timeout' => 15,
            'allow_redirects' => false,
            'http_errors' => false,
        ]);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            // Do not include URLs or response bodies: they may contain credentials.
            throw new \RuntimeException('Order webhook returned HTTP ' . $response->getStatusCode());
        }
    }
}
