@php
    $modern['site'] = [
        'title' => (string) dujiaoka_config_get('text_logo', dujiaoka_config_get('title', '数字商店')),
        'home' => url('/'), 'search' => url('order-search'),
        'notice' => (string) dujiaoka_config_get('notice', ''),
        'footer' => (string) dujiaoka_config_get('footer', ''),
    ];
    $modern['csrf'] = csrf_token();
    $modern['errors'] = isset($errors) ? $errors->all() : [];
    $manifestPath = public_path('assets/modern/manifest.json');
    $manifest = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : [];
    $entry = $manifest['src/main.ts'] ?? null;
    $assetReady = is_array($entry) && isset($entry['file']) && is_file(public_path('assets/modern/' . $entry['file']));
    if ($assetReady) {
        foreach ($entry['css'] ?? [] as $css) {
            if (!is_file(public_path('assets/modern/' . $css))) { $assetReady = false; }
        }
    }
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $page_title ?? '' }} | {{ $modern['site']['title'] }}</title>
    <meta name="description" content="{{ $gd_description ?? dujiaoka_config_get('description', '') }}">
    <meta name="keywords" content="{{ $gd_keywords ?? dujiaoka_config_get('keywords', '') }}">
    <meta name="theme-color" content="#137f70">
    @if($modern['page'] !== 'home' && $modern['page'] !== 'buy')
        <meta name="robots" content="noindex,nofollow">
        <meta name="referrer" content="same-origin">
    @endif
    @if($assetReady)
        @foreach($entry['css'] ?? [] as $css)
            <link rel="stylesheet" href="{{ asset('assets/modern/' . $css) }}">
        @endforeach
    @endif
</head>
<body>
    <div id="modern-app">
        <div style="max-width:640px;margin:12vh auto;padding:28px;font-family:system-ui,sans-serif;line-height:1.8;color:#203c38;background:#f1f7f3;border-radius:16px">
            <h1>{{ $modern['site']['title'] }}</h1>
            @if($assetReady)
                <p role="status">正在加载店铺…若长时间没有显示，请刷新页面。</p>
            @else
                <p role="status">店铺页面正在准备中，请稍后刷新，或联系店主完成主题资源构建。</p>
            @endif
            <a href="{{ url('/') }}">返回首页</a> · <a href="{{ url('order-search') }}">查询订单</a>
            <noscript><p>此主题需要 JavaScript，请在浏览器中启用后刷新。</p></noscript>
        </div>
    </div>
    @if($assetReady)
        <script id="modern-data" type="application/json">{!! json_encode($modern, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) !!}</script>
        <script type="module" src="{{ asset('assets/modern/' . $entry['file']) }}"></script>
    @endif
</body>
</html>
