<?php
/**
 * The file was created by Assimon.
 *
 * @author    assimon<ashang@utf8.hk>
 * @copyright assimon<ashang@utf8.hk>
 * @link      http://utf8.hk/
 */

namespace App\Service;

use App\Exceptions\RuleValidationException;
use App\Jobs\ApiHook;
use App\Jobs\OrderExpired;
use App\Jobs\ServerJiang;
use App\Jobs\TelegramPush;
use App\Jobs\BarkPush;
use App\Jobs\WorkWeiXinPush;
use App\Models\BaseModel;
use App\Models\Coupon;
use App\Models\Goods;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 订单处理层
 *
 * Class OrderProcessService
 * @package App\Service
 * @author: Assimon
 * @email: Ashang@utf8.hk
 * @blog: https://utf8.hk
 * Date: 2021/5/30
 */
class OrderProcessService
{

    const PENDING_CACHE_KEY = 'PENDING_ORDERS_LIST';

    /**
     * 优惠码服务层
     * @var \App\Service\CouponService
     */
    private $couponService;

    /**
     * 订单服务层
     * @var \App\Service\OrderService
     */
    private $orderService;

    /**
     * 卡密服务层
     * @var \App\Service\CarmisService
     */
    private $carmisService;

    /**
     * 邮件服务层
     * @var \App\Service\EmailtplService
     */
    private $emailtplService;

    /**
     * 商品服务层.
     * @var \App\Service\GoodsService
     */
    private $goodsService;

    /**
     * 商品
     * @var Goods
     */
    private $goods;

    /**
     * 优惠码
     * @var Coupon;
     */
    private $coupon;

    /**
     * 其他输入框
     * @var string
     */
    private $otherIpt;

    /**
     * 购买数量
     * @var int
     */
    private $buyAmount;

    /**
     * 购买邮箱
     * @var string
     */
    private $email;

    /**
     * 查询密码
     * @var string
     */
    private $searchPwd;

    /**
     * 下单id
     * @var string
     */
    private $buyIP;

    /**
     * 支付方式
     * @var int
     */
    private $payID;

    public function __construct()
    {
        $this->couponService = app('Service\CouponService');
        $this->orderService = app('Service\OrderService');
        $this->carmisService = app('Service\CarmisService');
        $this->emailtplService = app('Service\EmailtplService');
        $this->goodsService = app('Service\GoodsService');

    }

    /**
     * 设置支付方式
     * @param int $payID
     */
    public function setPayID(int $payID): void
    {
        $this->payID = $payID;
    }



    /**
     * 下单ip
     * @param mixed $buyIP
     */
    public function setBuyIP($buyIP): void
    {
        $this->buyIP = $buyIP;
    }

    /**
     * 设置查询密码
     * @param mixed $searchPwd
     */
    public function setSearchPwd($searchPwd): void
    {
        $this->searchPwd = $searchPwd;
    }

    /**
     * 设置购买数量
     * @param mixed $buyAmount
     */
    public function setBuyAmount($buyAmount): void
    {
        $this->buyAmount = $buyAmount;
    }

    /**
     * 设置下单邮箱
     * @param mixed $email
     */
    public function setEmail($email): void
    {
        $this->email = $email;
    }

    /**
     * 设置商品
     *
     * @param Goods $goods
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function setGoods(Goods $goods)
    {
        $this->goods = $goods;
    }

    /**
     * 设置优惠码.
     *
     * @param ?Coupon $coupon
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function setCoupon(?Coupon $coupon)
    {
        $this->coupon = $coupon;
    }

    /**
     * 其他输入框设置.
     *
     * @param ?string $otherIpt
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function setOtherIpt(?string $otherIpt)
    {
        $this->otherIpt = $otherIpt;
    }

    /**
     * 计算优惠码价格
     *
     * @return float
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    private function calculateTheCouponPrice(): float
    {
        $couponPrice = 0;
        // 优惠码优惠价格
        if ($this->coupon) {
            $couponPrice =  $this->coupon->discount;
        }
        return $couponPrice;
    }

    /**
     * 计算批发优惠
     * @return float
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    private function calculateTheWholesalePrice(): float
    {
        $wholesalePrice = 0; // 优惠单价
        $wholesaleTotalPrice = 0; // 优惠总价
        if ($this->goods->wholesale_price_cnf) {
            $formatWholesalePrice = format_wholesale_price($this->goods->wholesale_price_cnf);
            foreach ($formatWholesalePrice as $item) {
                if ($this->buyAmount >= $item['number']) {
                    $wholesalePrice = $item['price'];
                }
            }
        }
        if ($wholesalePrice > 0 ) {
            $totalPrice = $this->calculateTheTotalPrice(); // 实际原总价
            $newTotalPrice = bcmul($wholesalePrice, $this->buyAmount, 2); // 批发价优惠后的总价
            $wholesaleTotalPrice = bcsub($totalPrice, $newTotalPrice, 2); // 批发总优惠
        }
        return $wholesaleTotalPrice;
    }

    /**
     * 订单总价
     * @return float
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    private function calculateTheTotalPrice(): float
    {
        $price = $this->goods->actual_price;
        return bcmul($price, $this->buyAmount, 2);
    }

    /**
     * 计算实际需要支付的价格
     *
     * @param float $totalPrice 总价
     * @param float $couponPrice 优惠码优惠价
     * @param float $wholesalePrice 批发优惠
     * @return float
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    private function calculateTheActualPrice(float $totalPrice, float $couponPrice, float $wholesalePrice): float
    {
        $actualPrice = bcsub($totalPrice, $couponPrice, 2);
        $actualPrice = bcsub($actualPrice, $wholesalePrice, 2);
        if ($actualPrice <= 0) {
            $actualPrice = 0;
        }
        return $actualPrice;
    }

    /**
     * 创建订单.
     * @return Order
     * @throws RuleValidationException
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function createOrder(): Order
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuleValidationException('Order creation requires its own transaction.');
        }
        try {
            $order = DB::transaction(function () {
                $order = new Order();
                // 生成订单号
                $order->order_sn = strtoupper(Str::random(16));
                // 设置商品
                $order->goods_id = $this->goods->id;
                // 标题
                $order->title = $this->goods->gd_name . ' x ' . $this->buyAmount;
                // 订单类型
                $order->type = $this->goods->type;
                // 查询密码
                $order->search_pwd = $this->searchPwd;
                // 邮箱
                $order->email = $this->email;
                // 支付方式.
                $order->pay_id = $this->payID;
                // 商品单价
                $order->goods_price = $this->goods->actual_price;
                // 购买数量
                $order->buy_amount = $this->buyAmount;
                // 订单详情
                $order->info = $this->otherIpt;
                // ip地址
                $order->buy_ip = $this->buyIP;
                // 优惠码优惠价格
                $order->coupon_discount_price = $this->calculateTheCouponPrice();
                if ($this->coupon) {
                    $order->coupon_id = $this->coupon->id;
                }
                // 批发价
                $order->wholesale_discount_price = $this->calculateTheWholesalePrice();
                // 订单总价
                $order->total_price = $this->calculateTheTotalPrice();
                // 订单实际需要支付价格
                $order->actual_price = $this->calculateTheActualPrice(
                    $this->calculateTheTotalPrice(),
                    $this->calculateTheCouponPrice(),
                    $this->calculateTheWholesalePrice()
                );
                // 保存订单
                $order->save();
                // 如果有用到优惠券
                if ($this->coupon) {
                    if (!$this->couponService->retDecr($this->coupon->coupon)) {
                        throw new RuleValidationException(__('dujiaoka.prompt.coupon_lack_of_available_opportunities'));
                    }
                }
                return $order;
            });
        } catch (\Throwable $exception) {
            throw new RuleValidationException($exception->getMessage());
        }

        // The committed order is the durable fallback if queue delivery fails.
        try {
            $deadline = $order->created_at->copy()->addMinutes($this->orderService->expirationMinutes());
            OrderExpired::dispatch($order->order_sn, $deadline->getTimestamp())->delay($deadline);
        } catch (\Throwable $exception) {
            error_log('Order expiry dispatch failed for ' . $order->order_sn . ': ' . $exception->getMessage());
        }
        return $order;
    }


    /**
     * 订单成功方法
     *
     * @param string $orderSN 订单号
     * @param float $actualPrice 实际支付金额
     * @param string $tradeNo 第三方订单号
     * @return Order
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function completedOrder(string $orderSN, float $actualPrice, string $tradeNo = '', ?callable $paymentGuard = null)
    {
        if (DB::transactionLevel() !== 0) {
            throw new RuleValidationException('Payment completion requires its own transaction.');
        }
        $changed = false;
        $outbox = app(NotificationOutboxService::class);
        try {
            $order = DB::transaction(function () use ($orderSN, $actualPrice, $tradeNo, $paymentGuard, $outbox, &$changed) {
                $order = Order::query()->where('order_sn', $orderSN)->lockForUpdate()->first();
                if (!$order) {
                    throw new \RuntimeException(__('dujiaoka.prompt.order_does_not_exist'));
                }
                // Modern receipt guards hold gateway/binding locks until the fulfillment commits.
                if ($paymentGuard !== null) {
                    $paymentGuard($order);
                }
                if (bccomp($order->actual_price, $actualPrice, 2) != 0) {
                    throw new \RuntimeException(__('dujiaoka.prompt.order_inconsistent_amounts'));
                }
                if ($order->status != Order::STATUS_WAIT_PAY && $order->status != Order::STATUS_EXPIRED) {
                    if ((string) $order->trade_no !== '' && $tradeNo !== '' && (string) $order->trade_no !== $tradeNo) {
                        throw new \RuntimeException('Payment transaction does not match.');
                    }
                    return $order;
                }
                $changed = true;
                $order->trade_no = $tradeNo;
                if ($order->status == Order::STATUS_EXPIRED) {
                    // Finish returns from legacy queued expiry jobs before recording late payment.
                    if ($this->orderService->returnCoupon($orderSN)) {
                        $order->coupon_ret_back = Order::COUPON_BACK_OK;
                    }
                    // A refunded coupon or released inventory must not be consumed again.
                    $order->status = Order::STATUS_ABNORMAL;
                    $order->info = 'Late payment received; manual reconciliation/refund required. ' . $order->info;
                    $this->saveOrderWithoutNotifications($order);
                    $outbox->recordPayment($order);
                    return $order;
                }
                if ($order->type == Order::AUTOMATIC_DELIVERY) {
                    $this->processAuto($order);
                } else {
                    $this->processManual($order);
                }
                $this->goodsService->salesVolumeIncr($order->goods_id, $order->buy_amount);
                $outbox->recordPayment($order);
                return $order;
            });
        } catch (\Throwable $exception) {
            throw new RuleValidationException($exception->getMessage());
        }
        if ($changed) {
            // Notification failures must never turn a committed payment into a failed callback.
            try {
                $outbox->publishDue(100, (int) $order->id);
                $this->notifyOrder($order);
            } catch (\Throwable $exception) {
                error_log('Order notification failed for ' . $orderSN . ': ' . $exception->getMessage());
            }
        }
        return $order;
    }

    private function saveOrderWithoutNotifications(Order $order): void
    {
        Order::withoutEvents(function () use ($order) {
            $order->save();
        });
    }

    protected function notifyOrder(Order $order): void
    {
        event(new \App\Events\OrderUpdated($order));
        if (!app(NotificationOutboxService::class)->mailEnabled() &&
            ($order->status == Order::STATUS_PENDING || $order->status == Order::STATUS_COMPLETED)) {
            $mail = app(PaymentMailService::class);
            app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatch($mail->job($mail->recipe($order), false));
        }
        foreach ([
            'is_open_server_jiang' => ServerJiang::class,
            'is_open_telegram_push' => TelegramPush::class,
            'is_open_bark_push' => BarkPush::class,
            'is_open_qywxbot_push' => WorkWeiXinPush::class,
        ] as $key => $job) {
            if (dujiaoka_config_get($key, 0) == BaseModel::STATUS_OPEN) {
                $job::dispatch($order);
            }
        }
        if (!app(NotificationOutboxService::class)->apiHookEnabled()) {
            ApiHook::dispatch($order);
        }
    }

    /**
     * 手动处理的订单.
     *
     * @param Order $order 订单
     * @return Order 订单
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function processManual(Order $order)
    {
        if (!$this->goodsService->inStockDecr($order->goods_id, $order->buy_amount)) {
            $order->status = Order::STATUS_ABNORMAL;
            $order->info = 'Paid order requires manual fulfillment: insufficient stock. ' . $order->info;
            $this->saveOrderWithoutNotifications($order);
            return $order;
        }
        // 设置订单为待处理
        $order->status = Order::STATUS_PENDING;
        // 保存订单
        $this->saveOrderWithoutNotifications($order);
        return $order;
    }

    /**
     * 处理自动发货.
     *
     * @param Order $order 订单
     * @return Order 订单
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function processAuto(Order $order): Order
    {
        // 获得卡密
        $carmis = $this->carmisService->withGoodsByAmountAndStatusUnsold($order->goods_id, $order->buy_amount);
        // 实际可使用的库存已经少于购买数量了
        if (count($carmis) != $order->buy_amount) {
            $order->info = __('dujiaoka.prompt.order_carmis_insufficient_quantity_available');
            $order->status = Order::STATUS_ABNORMAL;
            $this->saveOrderWithoutNotifications($order);
            return $order;
        }
        $carmisInfo = array_column($carmis, 'carmi');
        $ids = array_column($carmis, 'id');
        $order->info = implode(PHP_EOL, $carmisInfo);
        $order->status = Order::STATUS_COMPLETED;
        $this->saveOrderWithoutNotifications($order);
        // 将卡密设置为已售出
        $this->carmisService->soldByIDS($ids);
        return $order;
    }

}
