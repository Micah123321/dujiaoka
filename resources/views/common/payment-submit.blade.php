<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ __('dujiaoka.pay_immediately') }}</title></head>
<body>
<form id="payment-submit" action="{{ $endpoint }}" method="{{ $method }}">
    @foreach($fields as $name => $value)
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
    @endforeach
    <button type="submit">{{ __('dujiaoka.pay_immediately') }}</button>
</form>
<script>document.getElementById('payment-submit').submit();</script>
</body>
</html>
