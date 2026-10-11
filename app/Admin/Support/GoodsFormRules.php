<?php

namespace App\Admin\Support;

class GoodsFormRules
{
    public static function error(array $input, array $original = []): ?string
    {
        $data = array_merge($original, $input);
        $priceChanged = array_key_exists('actual_price', $input) && (float) $input['actual_price'] !== (float) ($original['actual_price'] ?? 0);
        foreach (['wholesale_price_cnf', 'other_ipu_cnf'] as $field) {
            $changed = array_key_exists($field, $input) && !self::same($input[$field], $original[$field] ?? '');
            if (!$changed && !($field === 'wholesale_price_cnf' && $priceChanged)) continue;
            $seen = [];
            foreach (preg_split('/\r\n|\r|\n/', (string) ($data[$field] ?? '')) as $index => $line) {
                if (trim($line) === '') continue;
                $parts = array_map('trim', explode('=', $line));
                $prefix = ($field === 'wholesale_price_cnf' ? '批发优惠' : '买家填写项') . '第 ' . ($index + 1) . ' 行：';
                if ($field === 'wholesale_price_cnf') {
                    if (count($parts) !== 2 || !preg_match('/\A[1-9][0-9]*\z/', $parts[0]) || !preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/', $parts[1]) || (float) $parts[1] <= 0) return $prefix . '请填写正整数件数和大于 0 的单价，例如 5=3。';
                    if ((float) $parts[1] > (float) ($data['actual_price'] ?? 0)) return $prefix . '优惠单价应小于或等于实际售价。';
                    $key = (int) $parts[0];
                } else {
                    if (count($parts) < 3 || count($parts) > 4 || !preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/', $parts[0]) || $parts[1] === '' || !in_array($parts[2], ['true', 'false'], true)) return $prefix . '请填写英文标识、名称及 true/false，或使用逐行编辑器。';
                    $key = $parts[0];
                    if (in_array($key, ['_token', 'goods_id', 'gid', 'payway', 'buy_amount', 'by_amount', 'email', 'search_pwd', 'coupon_code', 'img_code', 'img_verify_code', 'geetest_challenge', 'geetest_validate', 'geetest_seccode'], true)) return $prefix . '此英文标识与系统字段重复，请换一个名称。';
                }
                if (isset($seen[$key])) return $prefix . '与前面的行重复，请合并或使用不同标识。';
                $seen[$key] = true;
            }
        }
        return null;
    }

    public static function same($value, $original): bool
    {
        return str_replace(["\r\n", "\r"], "\n", (string) $value) === str_replace(["\r\n", "\r"], "\n", (string) $original);
    }

    public static function normalize($value): string
    {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $value) as $line) {
            if (trim($line) === '') continue;
            $lines[] = implode('=', array_map('trim', explode('=', $line)));
        }
        return implode(PHP_EOL, $lines);
    }
}
