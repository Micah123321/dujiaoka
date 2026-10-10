<?php

// Isolated browser fixture: never boot with the workspace environment or live data.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (strpos($path, '/assets/') === 0 && strpos($path, '..') === false && preg_match('/[.](js|css|png|jpg|jpeg|svg|webp|woff2?)$/i', $path)) {
    return false;
}
require __DIR__ . '/bootstrap.php';
$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->useEnvironmentPath(__DIR__);
$app->loadEnvironmentFrom('.env.testing');
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Illuminate\Support\Facades\URL::forceRootUrl('http://127.0.0.1:8097');
$app->make('session')->start();
\Illuminate\Support\Facades\Cache::put('system-setting', [
    'template' => 'modern', 'text_logo' => '独角数卡', 'notice' => '测试商店：浏览商品并查询订单。',
    'is_open_img_code' => 0, 'is_open_geetest' => 0, 'is_open_search_pwd' => 1,
], 60);
if (strpos($path, '/check-order-status/') === 0) {
    header('Content-Type: application/json');
    echo json_encode(['code' => 400000, 'msg' => 'wait....']);
    return;
}
$pages = \Tests\Fixtures\ThemeData::pages();
$routes = ['/' => 'home', '/buy/1' => 'buy', '/order-search' => 'searchOrder', '/bill/FIXTUREORDER0001' => 'bill', '/detail-order-sn/FIXTUREORDER0001' => 'orderinfo', '/qrpay' => 'qrpay'];
$page = $routes[$path] ?? null;
if (!$page || $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(404);
    echo 'Fixture route not found';
    return;
}
echo view('modern.static_pages.' . $page, array_merge($pages[$page], ['page_title' => '测试商店']))->render();
