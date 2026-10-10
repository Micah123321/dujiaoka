<?php

namespace App\Jobs;

use App\Models\Order;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\BaseModel;


class BarkPush implements ShouldQueue
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
        $apiUrl = dujiaoka_config_get('bark_server') .'/'. dujiaoka_config_get('bark_token');
		$params = [
			"title" => __('dujiaoka.prompt.new_order_push').'('.$this->order->actual_price.'元)',
			"body" => __('order.fields.order_id') .': '.$this->order->id."\n"
				. __('order.fields.order_sn') .': '.$this->order->order_sn."\n"
				. __('order.fields.pay_id') .': '.$this->order->pay->pay_name."\n"
				. __('order.fields.title') .': '.$this->order->title."\n"
				. __('order.fields.actual_price') .': '.$this->order->actual_price."\n"
				. __('order.fields.email') .': '.$this->order->email."\n"
				. __('goods.fields.gd_name') .': '.$goodInfo->gd_name."\n"
				. __('goods.fields.in_stock') .': '.$goodInfo->in_stock."\n"
				. __('order.fields.order_created') .': '.$this->order->created_at,
			"icon"=>url('assets/common/images/default.jpg'),
			"level"=>"timeSensitive",
			"group"=>dujiaoka_config_get('text_logo', '独角数卡')
		];
		if (dujiaoka_config_get('is_open_bark_push_url', 0) == BaseModel::STATUS_OPEN) {
			$params["url"] = url('detail-order-sn/'.$this->order->order_sn);
		}
        try {
            $response = $client->post($apiUrl, [
                'form_params' => $params,
                'verify' => true,
                'connect_timeout' => 5,
                'timeout' => 15,
                'allow_redirects' => false,
                'http_errors' => false,
            ]);
        } catch (\GuzzleHttp\Exception\GuzzleException $exception) {
            // Transport exceptions contain the token-bearing URL.
            throw new \RuntimeException('Bark notification request failed');
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Bark notification returned HTTP ' . $status);
        }
        $result = json_decode((string) $response->getBody(), true);
        if (!is_array($result) || !in_array($result['code'] ?? null, [200, '200'], true)) {
            throw new \RuntimeException('Bark notification returned an unsuccessful acknowledgment');
        }
    }
}
