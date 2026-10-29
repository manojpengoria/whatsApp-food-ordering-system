@use('App\Support\LocalTime')
@use('App\Support\Money')
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bill {{ $order->order_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; background: #f4f6f8; font: 13px/1.45 ui-monospace, 'SF Mono', Menlo, Consolas, monospace; color: #111; }
        .bill { width: 80mm; max-width: 100%; margin: 16px auto; background: #fff; padding: 14px 12px; }
        h1 { font-size: 16px; margin: 0; text-align: center; }
        .c { text-align: center; } .m { color: #555; } .r { text-align: right; }
        hr { border: 0; border-top: 1px dashed #999; margin: 8px 0; }
        table { width: 100%; border-collapse: collapse; } td { padding: 2px 0; vertical-align: top; }
        .tot td { font-weight: 700; font-size: 15px; padding-top: 4px; }
        .actions { text-align: center; margin: 12px; font-family: system-ui, sans-serif; }
        .actions button { padding: 8px 16px; border-radius: 8px; border: 1px solid #0e948f; background: #0e948f; color: #fff; font-size: 14px; cursor: pointer; }
        @media print { body { background: #fff; } .bill { margin: 0; width: 100%; padding: 0; } .actions { display: none; } @page { margin: 4mm; } }
    </style>
</head>
<body>
    <div class="actions"><button onclick="window.print()">Print</button></div>
    <div class="bill">
        <h1>{{ $s['restaurant_name'] }}</h1>
        @if ($s['restaurant_address'])<p class="c m">{{ $s['restaurant_address'] }}</p>@endif
        @if ($s['restaurant_phone'])<p class="c m">Ph: {{ $s['restaurant_phone'] }}</p>@endif
        <hr>
        <table>
            <tr><td>Bill: <b>{{ $order->order_number }}</b></td><td class="r">{{ LocalTime::format($order->created_at, 'd/m/Y g:i A') }}</td></tr>
            <tr><td>{{ $order->type_label }}</td><td class="r">{{ $order->source_label }}</td></tr>
            <tr><td colspan="2">{{ $order->customer_label }}@if($order->customer_phone) · {{ $order->customer_phone }}@endif</td></tr>
        </table>
        <hr>
        <table>
            @foreach ($order->items as $i)
                <tr><td colspan="2">{{ $i->item_name }}</td></tr>
                <tr><td class="m">&nbsp;&nbsp;{{ $i->quantity }} × {{ Money::format($i->unit_price_paise) }}</td><td class="r">{{ Money::format($i->line_total_paise) }}</td></tr>
            @endforeach
        </table>
        <hr>
        <table>
            <tr><td>Subtotal</td><td class="r">{{ Money::format($order->subtotal_paise) }}</td></tr>
            <tr><td>GST {{ rtrim(rtrim(number_format($order->tax_rate_bp / 100, 2), '0'), '.') }}%</td><td class="r">{{ Money::format($order->tax_paise) }}</td></tr>
            @if ($order->delivery_fee_paise)<tr><td>Delivery</td><td class="r">{{ Money::format($order->delivery_fee_paise) }}</td></tr>@endif
            <tr class="tot"><td>TOTAL</td><td class="r">{{ $order->total }}</td></tr>
        </table>
        <hr>
        <p class="c">{{ $order->payment_method->label() }} · {{ $order->payment_status->label() }}</p>
        @if ($order->status->value === 'cancelled')<p class="c"><b>CANCELLED</b></p>@endif
        <p class="c m">Thank you! Visit again.</p>
    </div>
</body>
</html>
