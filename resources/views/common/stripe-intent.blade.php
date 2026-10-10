<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>订单付款</title>
    <style>
        :root{color-scheme:light;--ink:#1d2a26;--muted:#596b63;--line:#dce5df;--accent:#256049}
        *{box-sizing:border-box}body{margin:0;background:#f4f6f3;color:var(--ink);font:16px/1.6 system-ui,sans-serif}
        main{max-width:580px;margin:8vh auto;padding:36px;background:#fff;border:1px solid var(--line);border-radius:20px}
        .eyebrow{font-size:12px;letter-spacing:.15em;color:var(--accent)}h1{margin:8px 0 24px;font-size:30px;letter-spacing:-.04em}
        .summary{padding:20px;background:#f4f7f3;border-radius:12px;margin-bottom:24px}.summary p{margin:4px 0}
        .amount{font-size:32px;letter-spacing:-.03em;font-weight:650}.muted{color:var(--muted);font-size:14px}
        label{display:block;margin:18px 0 8px;font-weight:600}select,button{width:100%;border-radius:10px;font:inherit;padding:13px 16px}
        select{border:1px solid var(--line);background:#fff;color:var(--ink)}button{border:0;background:var(--accent);color:#fff;font-weight:600;cursor:pointer;margin-top:20px}
        button:disabled{opacity:.6;cursor:wait}button:focus-visible,select:focus-visible,a:focus-visible{outline:3px solid #99c8b3;outline-offset:4px}
        [hidden]{display:none!important}#message{margin:16px 0;color:var(--muted)}a{color:var(--accent);text-underline-offset:3px}
        #payment-element{margin-top:24px}footer{margin-top:24px;font-size:14px;color:var(--muted)}
        @media(max-width:640px){main{margin:24px 16px;padding:24px;border-radius:16px}h1{font-size:26px}.amount{font-size:28px}}
    </style>
</head>
<body>
<main>
    <div class="eyebrow">订单付款</div>
    <h1>确认金额，继续付款</h1>
    <section class="summary" aria-label="订单摘要">
        <p>{{ $order->title }}</p>
        <p class="amount">¥{{ $cny }}</p>
        <p class="muted">订单号 {{ $order->order_sn }}</p>
    </section>
    <form id="payment-form">
        <div id="currency-picker">
            <label for="payment-currency">选择付款方式</label>
            <select id="payment-currency" @if($binding) disabled @endif>
                <option value="usd" @if($usd === null) disabled @endif>@if($usd !== null)银行卡 / 微信 · USD {{ $usd }}@else银行卡 / 微信暂不可用@endif</option>
                <option value="cny">支付宝 · ¥{{ $cny }}</option>
            </select>
            <p class="muted">付款金额保持不变。进入付款后，请继续使用所选方式。</p>
        </div>
        <div id="selected-method" class="muted" hidden></div>
        <div id="payment-element"></div>
        <p id="message" role="status" aria-live="polite"></p>
        <button id="pay-button" type="submit">继续付款</button>
    </form>
    <button id="status-button" type="button" hidden>我已付款，查询状态</button>
    <footer><a href="{{ url('detail-order-sn/' . rawurlencode($order->order_sn)) }}">返回订单详情</a><p>付款信息由 Stripe 处理。需要验证时，请按照付款页面提示操作。</p></footer>
</main>
<script id="payment-config" type="application/json">@json($clientConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)</script>
<script src="https://js.stripe.com/v3/"></script>
<script src="{{ asset('assets/common/stripe-intent.js') }}" defer></script>
</body>
</html>
