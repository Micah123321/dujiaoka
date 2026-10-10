<?php

namespace App\Models;

use App\Events\OrderUpdated;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class Order extends BaseModel
{

    use SoftDeletes;

    protected $table = 'orders';

    protected $statusMailBaseline;
    protected $statusMailCaptureActive = false;
    protected $statusMailCaptureKey;

    public function save(array $options = [])
    {
        if (config('dujiaoka.manual_status_mail_outbox', false) &&
            $this->getConnection()->getName() !== DB::getDefaultConnection()) {
            throw new \LogicException('Order status mail requires the default database connection.');
        }
        return parent::save($options);
    }

    protected function fireModelEvent($event, $halt = true)
    {
        if ($event === 'updated' && $this->statusMailBaseline !== null) {
            $captureBefore = $this->statusMailCaptureActive;
            $this->statusMailCaptureActive = true;
            try {
                return parent::fireModelEvent($event, $halt);
            } finally {
                $this->statusMailCaptureActive = $captureBefore;
            }
        }
        $result = parent::fireModelEvent($event, $halt);
        if ($event === 'updating' && $result !== false && $this->statusMailBaseline !== null) {
            // Hooks may have saved a fresh instance of this locked row.
            $this->statusMailBaseline = $this->newModelQuery()
                ->where($this->getKeyName(), $this->getKeyForSaveQuery())->lockForUpdate()->first();
        }
        return $result;
    }

    public function isStatusMailCaptureActive(?string $eventKey = null): bool
    {
        return $this->statusMailCaptureActive &&
            ($eventKey === null || ($this->statusMailCaptureKey !== null && hash_equals($this->statusMailCaptureKey, $eventKey)));
    }

    protected function performUpdate(Builder $query)
    {
        if (!config('dujiaoka.manual_status_mail_outbox', false)) {
            return parent::performUpdate($query);
        }
        // Keep nested hook saves, the SQL update and their intents atomic.
        return $this->getConnection()->transaction(function () use ($query) {
            $baselineBefore = $this->statusMailBaseline;
            $captureBefore = $this->statusMailCaptureActive;
            $keyBefore = $this->statusMailCaptureKey;
            try {
                $this->statusMailCaptureKey = \Illuminate\Support\Str::random(64);
                $this->statusMailBaseline = $this->newModelQuery()
                    ->where($this->getKeyName(), $this->getKeyForSaveQuery())->lockForUpdate()->first();
                $this->statusMailCaptureActive = false;
                return parent::performUpdate($query);
            } finally {
                // A hook may save this same model; restore the enclosing update window.
                $this->statusMailBaseline = $baselineBefore;
                $this->statusMailCaptureActive = $captureBefore;
                $this->statusMailCaptureKey = $keyBefore;
            }
        });
    }

    public function captureStatusMailTransition(): ?array
    {
        if (!$this->isStatusMailCaptureActive() || !$this->exists || !$this->statusMailBaseline ||
            $this->getConnection()->transactionLevel() === 0 || !$this->isDirty('status') ||
            !array_key_exists('status', $this->getChanges())) {
            return null;
        }
        // Read the saved row: stale model attributes must not freeze an old buyer or type.
        $stored = $this->newModelQuery()->find($this->getKey());
        if (!$stored || (int) $stored->status === (int) $this->statusMailBaseline->status ||
            (int) $stored->type !== self::MANUAL_PROCESSING ||
            !in_array((int) $stored->status, [self::STATUS_PENDING, self::STATUS_COMPLETED, self::STATUS_FAILURE], true)) {
            return null;
        }
        return [
            'transition' => ['from' => (int) $this->statusMailBaseline->status, 'to' => (int) $stored->status],
            'order' => $stored,
            'key' => $this->statusMailCaptureKey,
        ];
    }

    /**
     * 待支付
     */
    const STATUS_WAIT_PAY = 1;

    /**
     * 待处理
     */
    const STATUS_PENDING = 2;

    /**
     * 处理中
     */
    const STATUS_PROCESSING = 3;

    /**
     * 已完成
     */
    const STATUS_COMPLETED = 4;

    /**
     * 失败
     */
    const STATUS_FAILURE = 5;

    /**
     * 过期
     */
    const STATUS_EXPIRED = -1;

    /**
     * 异常
     */
    const STATUS_ABNORMAL = 6;

    /**
     * 优惠券未回退
     */
    const COUPON_BACK_WAIT = 0;

    /**
     * 优惠券已回退
     */
    const COUPON_BACK_OK = 1;

    protected $dispatchesEvents = [
        'updated' => OrderUpdated::class
    ];


    /**
     * 状态映射
     *
     * @return array
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public static function getStatusMap()
    {
        return [
            self::STATUS_WAIT_PAY => admin_trans('order.fields.status_wait_pay'),
            self::STATUS_PENDING => admin_trans('order.fields.status_pending'),
            self::STATUS_PROCESSING => admin_trans('order.fields.status_processing'),
            self::STATUS_COMPLETED => admin_trans('order.fields.status_completed'),
            self::STATUS_FAILURE => admin_trans('order.fields.status_failure'),
            self::STATUS_ABNORMAL => admin_trans('order.fields.status_abnormal'),
            self::STATUS_EXPIRED => admin_trans('order.fields.status_expired')
        ];
    }

    /**
     * 类型映射
     *
     * @return array
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public static function getTypeMap()
    {
        return [
            self::AUTOMATIC_DELIVERY => admin_trans('goods.fields.automatic_delivery'),
            self::MANUAL_PROCESSING => admin_trans('goods.fields.manual_processing')
        ];
    }

    /**
     * 关联商品
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function goods()
    {
        return $this->belongsTo(Goods::class, 'goods_id');
    }

    /**
     * 关联优惠券
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function coupon()
    {
        return $this->belongsTo(Coupon::class, 'coupon_id');
    }

    /**
     * 关联支付
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     *
     * @author    assimon<ashang@utf8.hk>
     * @copyright assimon<ashang@utf8.hk>
     * @link      http://utf8.hk/
     */
    public function pay()
    {
        return $this->belongsTo(Pay::class, 'pay_id');
    }

}
