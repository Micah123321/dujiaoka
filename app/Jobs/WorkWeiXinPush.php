<?php

namespace App\Jobs;

use App\Models\Order;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;


class WorkWeiXinPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * 任务最大尝试次数。
     *
     * @var int
     */
    public $tries = 1;

    /**
     * 任务运行的超时时间。
     *
     * @var int
     */
    public $timeout = 30;

    /**
     * @var Order
     */
    private $order;

    /** Kept for compatibility with previously serialized jobs. */
    private $goodsService;


    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $goodInfo = app('Service\GoodsService')->detail($this->order->goods_id);
        $client = app(Client::class);
        $apiUrl = 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key='. dujiaoka_config_get('qywxbot_key');
		$params = [
			"msgtype"=>"markdown",
			"markdown"=>[
				"content"=>__('dujiaoka.prompt.new_order_push').'(<font color="warning">'.$this->order->actual_price."</font>元)\n"
				.'>'.__('order.fields.order_id') .': <font color="comment">'.$this->order->id."</font>\n"
				.'>'.__('order.fields.order_sn') .': <font color="comment">'.$this->order->order_sn."</font>\n"
				.'>'.__('order.fields.pay_id') .': <font color="comment">'.$this->order->pay->pay_name."</font>\n"
				.'>'.__('order.fields.title') .': <font color="comment">'.$this->order->title."</font>\n"
				.'>'.__('order.fields.actual_price') .': <font color="comment">'.$this->order->actual_price."</font>\n"
				.'>'.__('order.fields.email') .': <font color="comment">'.$this->order->email."</font>\n"
				.'>'.__('goods.fields.gd_name') .': <font color="comment">'.$goodInfo->gd_name."</font>\n"
				.'>'.__('goods.fields.in_stock') .': <font color="comment">'.$goodInfo->in_stock."</font>\n"
				.'>'.__('order.fields.order_created') .': <font color="comment">'.$this->order->created_at."</font>"
			]
		];
        try {
            $response = $client->post($apiUrl, [
                'json' => $params,
                'verify' => true,
                'connect_timeout' => 5,
                'timeout' => 15,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
        } catch (\GuzzleHttp\Exception\GuzzleException $exception) {
            // Transport exceptions contain the token-bearing URL.
            throw new \RuntimeException('Enterprise WeChat notification request failed');
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Enterprise WeChat notification returned HTTP ' . $status);
        }
        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result) || !in_array($result['errcode'] ?? null, [0, '0'], true)) {
            throw new \RuntimeException('Enterprise WeChat notification returned an unsuccessful acknowledgment');
        }
    }
}
