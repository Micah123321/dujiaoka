@php
    $modern = ['page' => 'search', 'search' => [
        'password' => dujiaoka_config_get('is_open_search_pwd') == \App\Models\Goods::STATUS_OPEN,
        'sn' => url('search-order-by-sn'), 'email' => url('search-order-by-email'), 'browser' => url('search-order-by-browser'),
    ]];
@endphp
@include('modern.layouts.app')
