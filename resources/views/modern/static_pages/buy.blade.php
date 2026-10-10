@php
    $modernFields = [];
    if ($type == \App\Models\Goods::MANUAL_PROCESSING && is_array($other_ipu ?? null)) {
        foreach ($other_ipu as $field) {
            $modernFields[] = ['field' => (string) $field['field'], 'desc' => (string) $field['desc'], 'placeholder' => (string) ($field['placeholder'] ?? ''), 'required' => $field['rule'] !== false];
        }
    }
    $modernTiers = [];
    foreach (is_array($wholesale_price_cnf ?? null) ? $wholesale_price_cnf : [] as $tier) {
        $modernTiers[] = ['number' => (int) $tier['number'], 'price' => (string) $tier['price']];
    }
    $modernPayways = [];
    foreach ($payways as $way) { $modernPayways[] = ['id' => (int) $way['id'], 'name' => (string) $way['pay_name']]; }
    $modern = ['page' => 'buy', 'buy' => [
        'id' => (int) $id, 'name' => (string) $gd_name, 'picture' => picture_ulr($picture),
        'price' => (string) $actual_price, 'stock' => (int) $in_stock, 'type' => (int) $type,
        'url' => url('buy/' . $id), 'limit' => (int) $buy_limit_num,
        'wholesale' => $modernTiers, 'fields' => $modernFields, 'payways' => $modernPayways,
        'coupon' => isset($open_coupon),
        'searchPassword' => dujiaoka_config_get('is_open_search_pwd') == \App\Models\Goods::STATUS_OPEN,
        'captcha' => dujiaoka_config_get('is_open_img_code') == \App\Models\Goods::STATUS_OPEN ? captcha_src('buy') : '',
        'geetest' => dujiaoka_config_get('is_open_geetest') == \App\Models\Goods::STATUS_OPEN,
        'geetestUrl' => url('check-geetest'), 'language' => (string) (dujiaoka_config_get('language') ?? 'zh_CN'),
        'action' => url('create-order'), 'description' => (string) ($description ?? ''), 'prompt' => (string) ($buy_prompt ?? ''),
    ]];
@endphp
@include('modern.layouts.app')
