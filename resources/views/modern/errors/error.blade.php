@php
    $modern = ['page' => 'error', 'error' => ['title' => (string) ($title ?? '提示'), 'content' => (string) ($content ?? ''), 'url' => (string) ($url ?? '')]];
@endphp
@include('modern.layouts.app')
