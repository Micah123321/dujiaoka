<?php
/**
 * The file was created by Assimon.
 *
 * @author    assimon<ashang@utf8.hk>
 * @copyright assimon<ashang@utf8.hk>
 * @link      http://utf8.hk/
 */

return [
    'dujiaoka_version' => '2.0.6',
    'container_installation' => (bool) env('DUJIAOKA_CONTAINER', false),
    // Presentation only; zero disables caching. Purchase validation stays live.
    'catalog_cache_seconds' => (int) env('CATALOG_CACHE_SECONDS', 10),
    // Cache successful quotes by provider; zero fetches every time.
    'currency_quote_cache_seconds' => (int) env('CURRENCY_QUOTE_CACHE_SECONDS', 300),
    // Install payment_quotes before enabling persistent checkout prices.
    'payment_quote_freeze' => (bool) env('PAYMENT_QUOTE_FREEZE', false),
    // Requires frozen quotes and the stripe_payment_intents migration.
    'stripe_payment_intents' => (bool) env('STRIPE_PAYMENT_INTENTS', false),
    // Install reconciliation tables and configure signed Stripe events before enabling.
    'stripe_reconciliation' => (bool) env('STRIPE_RECONCILIATION', false),
    // Requires frozen quotes and the paypal_orders migration.
    'paypal_orders_v2' => (bool) env('PAYPAL_ORDERS_V2', false),
    'paypal_orders_mode' => env('PAYPAL_ORDERS_MODE', 'live'),
    // Requires both modern Orders and the reconciliation migration; IDs map local Pay IDs to endpoint IDs.
    'paypal_reconciliation' => (bool) env('PAYPAL_RECONCILIATION', false),
    'paypal_webhook_ids' => json_decode(env('PAYPAL_WEBHOOK_IDS', '{}'), true),
    // Enable only after installing the notification_outbox migration.
    'api_hook_outbox' => (bool) env('API_HOOK_OUTBOX', false),
    // Payment mail also requires the encrypted payload migration.
    'payment_mail_outbox' => (bool) env('PAYMENT_MAIL_OUTBOX', false),
    // Requires payload and event_key migrations for manual status transition mail.
    'manual_status_mail_outbox' => (bool) env('MANUAL_STATUS_MAIL_OUTBOX', false),
    // 模板集合
    'templates' => [
        'unicorn' => '官方[unicorn-独角兽]',
        'luna' => 'Luna[Julyssn]',
        'hyper' => 'hyper[Bimoe]',
        'modern' => 'Modern[Vue 商店]'
    ],
    // 语言
    'language' => [
        'zh_CN' => '简体中文',
        'zh_TW' => '繁体中文',
    ],
];
