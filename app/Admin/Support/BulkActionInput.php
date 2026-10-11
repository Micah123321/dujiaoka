<?php

namespace App\Admin\Support;

use App\Models\Coupon;
use App\Models\Goods;
use App\Models\GoodsGroup;
use App\Models\Pay;

class BulkActionInput
{
    public const RESOURCES = [Goods::class => 'goods', Pay::class => 'pay', GoodsGroup::class => 'goods-group', Coupon::class => 'coupon'];

    public static function model($model, bool $clone = false): string
    {
        if (!is_string($model) || !isset(self::RESOURCES[$model]) || ($clone && !in_array($model, [Goods::class, Pay::class], true))) {
            abort(422, 'Invalid action model.');
        }
        return $model;
    }

    public static function keys($keys): array
    {
        if (!is_array($keys) || !$keys || count($keys) > 100) abort(422, 'Select between 1 and 100 rows.');
        $result = [];
        foreach ($keys as $key) {
            if ((!is_int($key) && !is_string($key)) || !preg_match('/^[1-9][0-9]*$/D', (string) $key)
                || strlen((string) $key) > strlen((string) PHP_INT_MAX)
                || (strlen((string) $key) === strlen((string) PHP_INT_MAX) && strcmp((string) $key, (string) PHP_INT_MAX) > 0)) {
                abort(422, 'Row IDs must be positive integers.');
            }
            $result[] = (int) $key;
        }
        if (count(array_unique($result)) !== count($result)) abort(422, 'Row IDs must be unique.');
        sort($result, SORT_NUMERIC);
        return $result;
    }

    public static function status($field, $state): int
    {
        if ($field !== 'is_open' || !in_array($state, [0, 1, '0', '1'], true)) abort(422, 'Invalid action field or state.');
        return (int) $state;
    }
}
