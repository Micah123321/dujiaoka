<?php

namespace App\Admin\Support;

use Illuminate\Support\Facades\DB;

class PaymentFormCatalog
{
    private static function field(string $label, string $help, string $placeholder = '', bool $required = true): array
    {
        return compact('label', 'help', 'placeholder', 'required');
    }

    private static function check(string $value, string $label, int $method = 1, int $client = 3): array
    {
        return compact('value', 'label', 'method', 'client');
    }

    public static function all($id = null): array
    {
        $f = function ($label, $help, $placeholder = '', $required = true) { return self::field($label, $help, $placeholder, $required); };
        $c = function ($value, $label, $method = 1, $client = 3) { return self::check($value, $label, $method, $client); };
        $unused = $f('此渠道无需填写', '此字段不参与该渠道的付款请求，可留空。', '', false);
        $profiles = [
            'pay/alipay' => ['label' => '支付宝官方', 'note' => '请使用支付宝开放平台的应用信息。公钥与私钥是不同字段，按所开通的产品选择付款方式。',
                'checks' => [$c('zfbf2f', '当面付 · 扫码', 2), $c('aliweb', '电脑网站支付', 1, 1), $c('aliwap', '手机网站支付', 1, 2), $c('alipayscan', '当面付 · 兼容标识', 2)],
                'fields' => ['merchant_id' => $f('应用 App ID', '支付宝开放平台 → 应用详情中的 App ID。', '如 2021…'), 'merchant_key' => $f('支付宝公钥', '填写支付宝生成的 RSA 公钥，非应用公钥。'), 'merchant_pem' => $f('应用私钥', '填写与该应用公钥配对的 RSA 私钥，保留完整内容。')]],
            'pay/wepay' => ['label' => '微信支付 · API V2 扫码', 'note' => '当前接口使用 API V2，填写 V2 密钥。', 'checks' => [$c('wescan', '微信扫码', 2)],
                'fields' => ['merchant_id' => $f('微信 App ID', '填写开通微信支付的应用 App ID。', 'wx…'), 'merchant_key' => $f('微信商户号', '微信支付商户平台的 mch_id。'), 'merchant_pem' => $f('API V2 密钥', '微信支付商户平台设置的 API V2 密钥，非 API V3 密钥。')]],
            'pay/mapay' => ['label' => '码支付', 'note' => '填写码支付服务提供的商户信息及完整支付请求地址。', 'checks' => [$c('mzfb', '支付宝'), $c('mwx', '微信'), $c('mqq', 'QQ 钱包')],
                'fields' => ['merchant_id' => $f('商户 ID', '码支付提供的数字商户 ID。'), 'merchant_key' => $f('支付请求地址', '填写服务提供的完整请求 URL。', 'https://gateway.example.com/pay.php'), 'merchant_pem' => $f('通讯签名密钥', '使用该商户的通讯密钥。')]],
            'pay/paysapi' => ['label' => 'Paysapi', 'note' => '商户 UID 与通讯密钥来自 Paysapi。', 'checks' => [$c('pszfb', '支付宝'), $c('pswx', '微信')],
                'fields' => ['merchant_id' => $f('商户 UID', '填写 Paysapi 的 UID。'), 'merchant_key' => $unused, 'merchant_pem' => $f('通讯密钥', '填写该 UID 对应的通讯密钥。')]],
            'pay/payjs' => ['label' => 'Payjs · 微信扫码', 'note' => '当前接口使用 Payjs 微信扫码。', 'checks' => [$c('payjswescan', '微信扫码', 2)],
                'fields' => ['merchant_id' => $f('商户号', 'Payjs 提供的 mchid。'), 'merchant_key' => $unused, 'merchant_pem' => $f('通讯密钥', 'Payjs 商户的通讯密钥。')]],
            'pay/yipay' => ['label' => '易支付', 'note' => '使用你的易支付服务提供的 PID、密钥与完整提交地址。', 'checks' => [$c('alipay', '支付宝'), $c('wxpay', '微信'), $c('qqpay', 'QQ 钱包')],
                'fields' => ['merchant_id' => $f('商户 PID', '填写易支付商户的 PID。'), 'merchant_key' => $f('支付提交地址', '填写完整提交地址，通常以 submit.php 结尾。', 'https://gateway.example.com/submit.php'), 'merchant_pem' => $f('商户通讯密钥', '填写服务提供的 MD5 通讯密钥。')]],
            'pay/paypal' => ['label' => 'PayPal', 'note' => config('dujiaoka.paypal_orders_v2') ? '当前使用 Orders V2。需要收款 Merchant ID；店铺模式为 ' . config('dujiaoka.paypal_orders_mode', 'live') . '。' : '当前使用旧版付款流程，连接 live 环境；收款 Merchant ID 可留空。', 'checks' => [$c('paypal', 'PayPal 收银台')],
                'fields' => ['merchant_id' => $f('收款 Merchant ID', 'Orders V2 使用 13 位大写字母数字商户 ID，非邮箱。', '13 位商户 ID', (bool) config('dujiaoka.paypal_orders_v2')), 'merchant_key' => $f('Client ID', 'PayPal REST 应用的 Client ID。'), 'merchant_pem' => $f('Client Secret', '与 Client ID 配对的 Secret。')]],
            'pay/vpay' => ['label' => 'V 免签', 'note' => '使用 V 免签服务根地址，系统会追加 /createOrder。', 'checks' => [$c('vzfb', '支付宝'), $c('vwx', '微信')],
                'fields' => ['merchant_id' => $f('通讯密钥', '填写 V 免签服务设置的通讯密钥。'), 'merchant_key' => $unused, 'merchant_pem' => $f('服务根地址', '填写服务根 URL，不带查询参数。', 'https://gateway.example.com')]],
            'pay/stripe' => ['label' => 'Stripe', 'note' => config('dujiaoka.stripe_payment_intents') ? '当前使用 PaymentIntent。公钥和私钥必须属于同一账户及同一 test/live 模式；可用付款方式以该账户开通情况为准。' : '当前使用兼容收银页；公钥和私钥须属于同一账户。', 'checks' => [$c('stripe', 'Stripe 收银台')],
                'fields' => ['merchant_id' => $f('Publishable Key · 公钥', 'Stripe 开发者控制台的可发布密钥。', 'pk_test_… / pk_live_…'), 'merchant_key' => $f('Webhook 签名密钥', '开启签名回调时，填写本渠道回调端点的密钥；普通收银模式可留空。', 'whsec_…', (bool) config('dujiaoka.stripe_reconciliation')), 'merchant_pem' => $f('Secret Key · 私钥', 'Stripe API 私钥；Intent 模式也支持具备对应权限的 Restricted Key。', 'sk_test_… / sk_live_…')]],
            'pay/coinbase' => ['label' => 'Coinbase Commerce', 'note' => '费率填写小数：0 不加价，0.01 表示加价 1%。', 'checks' => [$c('coinbase', 'Coinbase 加密货币')],
                'fields' => ['merchant_id' => $f('加价费率', '填 0 或小数费率；这里不是商户 ID。', '0'), 'merchant_key' => $f('Commerce API Key', 'Coinbase Commerce 提供的 API Key。'), 'merchant_pem' => $f('Webhook Shared Secret', 'Coinbase Commerce 的通知签名密钥。')]],
            'pay/epusdt' => ['label' => 'Epusdt', 'note' => '填写完整创建交易 API 地址，系统直接向该地址发送请求。', 'checks' => [$c('epusdt', 'USDT')],
                'fields' => ['merchant_id' => $f('API 签名密钥', '填写 Epusdt 配置的 API 密钥。'), 'merchant_key' => $unused, 'merchant_pem' => $f('创建交易 API 地址', '填写完整的创建交易接口 URL。', 'https://gateway.example.com/api/v1/order/create-transaction')]],
            'pay/bepusdt' => ['label' => 'BEpusdt', 'note' => '选择 USDT 后，买家在 BEpusdt 收银台选择网络；可用网络以服务端已配置的钱包为准。也可选择固定 TRC20 网络。', 'checks' => [$c('USDT', 'USDT · 自选网络'), $c('usdt.trc20', 'USDT · TRC20')],
                'fields' => ['merchant_id' => $f('AUTH_TOKEN 对接令牌', '填写 BEpusdt 服务配置的 AUTH_TOKEN。'), 'merchant_key' => $unused, 'merchant_pem' => $f('服务地址', '可填服务根地址或完整创建交易 API 地址。', 'https://gateway.example.com')]],
            'pay/tokenpay' => ['label' => 'TokenPay', 'note' => '支付标识须以 tokenpay- 开头。Currency 必须与服务实际资产名称一致，标识本身不决定币种。', 'checks' => [$c('tokenpay-usdt-trc', 'USDT · TRC20'), $c('tokenpay-trx', 'TRX'), $c('tokenpay-eth', 'ETH')],
                'fields' => ['merchant_id' => $f('资产 Currency', '填写服务中实际的资产标识。例如 USDT_TRC20、TRX、EVM_ETH_ETH。', 'USDT_TRC20'), 'merchant_key' => $f('API 签名密钥', '填写 TokenPay 提供的签名密钥。'), 'merchant_pem' => $f('服务根地址', '填写根 URL，系统会追加 /CreateOrder。', 'https://gateway.example.com')]],
        ];
        foreach ($profiles as $route => &$profile) {
            $profile['notify'] = url($route . '/notify_url');
        }
        unset($profile);
        $profiles['pay/stripe']['notify'] = $id ? url('pay/stripe/webhook/' . $id) : '保存渠道后显示专属回调地址';
        $profiles['pay/paypal']['notify'] = config('dujiaoka.paypal_orders_v2') ? ($id ? url('pay/paypal/webhook/' . $id) : '保存渠道后显示专属回调地址') : url('pay/paypal/notify_url');
        if (config('dujiaoka.stripe_payment_intents') && !config('dujiaoka.payment_quote_freeze')) $profiles['pay/stripe']['note'] .= ' 当前缺少冻结报价开关，需先完成运行配置后再启用。';
        if (config('dujiaoka.paypal_orders_v2') && !config('dujiaoka.payment_quote_freeze')) $profiles['pay/paypal']['note'] .= ' 当前缺少冻结报价开关，需先完成运行配置后再启用。';
        if (config('dujiaoka.paypal_reconciliation')) $profiles['pay/paypal']['note'] .= ' 签名回调的 Webhook ID 由运行配置按本渠道编号保存，非 Client ID。克隆后须为新渠道编号配置实际 Webhook ID，原渠道的 Webhook ID 不自动复用。';
        return $profiles;
    }

    public static function routeOptions($current = null): array
    {
        $options = array_map(function ($profile) { return $profile['label']; }, self::all());
        if ($current && !isset($options[$current])) $options[$current] = '历史自定义渠道 · ' . $current;
        return $options;
    }

    public static function checkOptions($current = null): array
    {
        $options = [];
        foreach (self::all() as $profile) foreach ($profile['checks'] as $check) $options[$check['value']] = $profile['label'] . ' · ' . $check['label'];
        if ($current && !isset($options[$current])) $options[$current] = self::isDraft($current) ? '待选实际付款方式 · 克隆草稿' : '当前自定义标识 · ' . $current;
        return $options;
    }

    public static function isDraft($check): bool
    {
        return is_string($check) && stripos($check, 'draft-') === 0;
    }

    public static function error(array $input, array $original = []): ?string
    {
        $data = array_merge($original, $input);
        $route = trim((string) ($data['pay_handleroute'] ?? ''), '/');
        $check = (string) ($data['pay_check'] ?? '');
        // Draft checks are reserved and never inherit the legacy unchanged-check exemption.
        if (self::isDraft($check) && ($data['is_open'] ?? false)) return '克隆草稿待选实际付款方式：请替换 draft-* 临时标识后再启用。';
        $unchanged = $original && $route === trim((string) ($original['pay_handleroute'] ?? ''), '/') && $check === (string) ($original['pay_check'] ?? '');
        $profile = self::all()[$route] ?? null;
        if (!$profile && !$unchanged) return '请选择列表中的支付服务。历史自定义渠道可保持原值编辑。';
        if ($check === '' || strlen($check) > 50 || preg_match('/[\s\/\\\\?#]/u', $check)) return '请填写不含空格、斜杠或 URL 参数的支付标识，最长 50 字节。';
        if (self::isDraft($check) && !$unchanged) return 'draft-* 是克隆草稿临时标识，请选择实际付款方式。';
        if (!$unchanged && $profile) {
            $fixed = ['pay/alipay', 'pay/wepay', 'pay/mapay', 'pay/paysapi', 'pay/payjs', 'pay/yipay', 'pay/vpay', 'pay/epusdt'];
            if (in_array($route, $fixed, true) && !in_array($check, array_column($profile['checks'], 'value'), true)) return '付款方式与支付服务不匹配，请重新选择该服务的付款方式。';
            if ($route === 'pay/tokenpay' && strpos($check, 'tokenpay-') !== 0) return 'TokenPay 的支付标识须以 tokenpay- 开头。';
        }
        $duplicate = DB::table('pays')->where('pay_check', $check);
        if (isset($original['id'])) $duplicate->where('id', '<>', $original['id']);
        if ($duplicate->exists()) return '该付款方式的支付标识已被其他渠道占用，请编辑原渠道或选择该服务支持的另一付款方式；固定付款方式不支持另造标识。';
        if (!$profile || !($data['is_open'] ?? false)) return null;
        foreach ($profile['fields'] as $name => $field) if ($field['required'] && trim((string) ($data[$name] ?? '')) === '') return '启用前请填写：' . $field['label'] . '。';
        if ($route === 'pay/paypal' && config('dujiaoka.paypal_orders_v2') && !preg_match('/\A[A-Z0-9]{13}\z/', (string) ($data['merchant_id'] ?? ''))) return 'PayPal 收款 Merchant ID 须为 13 位大写字母数字，非邮箱。';
        if ($route === 'pay/coinbase' && (!is_numeric($data['merchant_id']) || $data['merchant_id'] < 0)) return '加价费率请填 0 或正数小数，例如 0.01 表示 1%。';
        if ($route === 'pay/tokenpay' && !preg_match('/\A[A-Za-z0-9_.:-]{1,64}\z/', (string) ($data['merchant_id'] ?? ''))) return 'TokenPay Currency 请填写服务中实际的资产标识，仅使用英文、数字、点、下划线、冒号或短横线。';
        $urlField = in_array($route, ['pay/mapay', 'pay/yipay'], true) ? 'merchant_key' : (in_array($route, ['pay/vpay', 'pay/epusdt', 'pay/bepusdt', 'pay/tokenpay'], true) ? 'merchant_pem' : null);
        if ($urlField && !preg_match('#\Ahttps?://[^\s]+\z#i', (string) ($data[$urlField] ?? ''))) return '请填写包含 http:// 或 https:// 的完整服务地址。';
        return null;
    }
}
