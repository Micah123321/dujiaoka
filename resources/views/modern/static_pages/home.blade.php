@php
    $modern = ['page' => 'home', 'groups' => []];
    foreach ($data as $group) {
        $items = [];
        foreach ($group['goods'] as $good) {
            $items[] = [
                'id' => (int) $good['id'], 'name' => (string) $good['gd_name'],
                'picture' => picture_ulr($good['picture']), 'price' => (string) $good['actual_price'],
                'stock' => (int) $good['in_stock'], 'type' => (int) $good['type'],
                'url' => url('buy/' . $good['id']),
            ];
        }
        $modern['groups'][] = ['id' => (int) $group['id'], 'name' => (string) $group['gp_name'], 'goods' => $items];
    }
@endphp
@include('modern.layouts.app')
