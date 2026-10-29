@use('App\Support\Money')
<x-layouts.admin title="Settings">
    <div class="mb-5">
        <h1 class="text-2xl font-semibold tracking-tight">Settings</h1>
        <p class="mt-1 text-sm text-muted">What the WhatsApp bot tells customers, and how orders are charged.</p>
    </div>

    <div class="grid gap-5 xl:grid-cols-[1fr_340px]">
        <form method="post" action="{{ route('settings.update') }}" class="card space-y-6 p-6">
            @csrf @method('put')

            <fieldset class="space-y-4">
                <legend class="font-semibold">Restaurant</legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="restaurant_name" class="label">Name</label>
                        <input id="restaurant_name" name="restaurant_name" required maxlength="80" value="{{ old('restaurant_name', $s['restaurant_name']) }}" class="field">
                    </div>
                    <div>
                        <label for="restaurant_phone" class="label">Phone</label>
                        <input id="restaurant_phone" name="restaurant_phone" maxlength="20" value="{{ old('restaurant_phone', $s['restaurant_phone']) }}" class="field" inputmode="tel">
                    </div>
                </div>
                <div>
                    <label for="restaurant_address" class="label">Address</label>
                    <input id="restaurant_address" name="restaurant_address" maxlength="255" value="{{ old('restaurant_address', $s['restaurant_address']) }}" class="field">
                </div>
            </fieldset>

            <fieldset class="space-y-4 border-t border-line pt-6">
                <legend class="font-semibold">Orders</legend>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="opening_time" class="label">Opens at</label>
                        <input id="opening_time" name="opening_time" type="time" required value="{{ old('opening_time', $s['opening_time']) }}" class="field">
                    </div>
                    <div>
                        <label for="closing_time" class="label">Closes at</label>
                        <input id="closing_time" name="closing_time" type="time" required value="{{ old('closing_time', $s['closing_time']) }}" class="field">
                    </div>
                    <div>
                        <label for="tax_percent" class="label">GST (%)</label>
                        <input id="tax_percent" name="tax_percent" required inputmode="decimal" value="{{ old('tax_percent', rtrim(rtrim(number_format(((int) $s['tax_rate_bp']) / 100, 2), '0'), '.')) }}" class="field">
                    </div>
                    <div>
                        <label for="delivery_fee" class="label">Delivery fee (₹)</label>
                        <input id="delivery_fee" name="delivery_fee" required inputmode="decimal" value="{{ old('delivery_fee', Money::toRupeesString((int) $s['delivery_fee_paise'])) }}" class="field">
                    </div>
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="accepting_orders" value="1" @checked(old('accepting_orders', $s['accepting_orders']) === '1') class="rounded border-line text-brand-600"> Accept new orders on WhatsApp</label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="delivery_enabled" value="1" @checked(old('delivery_enabled', $s['delivery_enabled']) === '1') class="rounded border-line text-brand-600"> Offer home delivery (takeaway is always offered)</label>
            </fieldset>

            <fieldset class="space-y-4 border-t border-line pt-6">
                <legend class="font-semibold">Payments on WhatsApp</legend>
                <p class="text-sm text-muted">Customers pick one of the switched-on options at checkout. Orders paid online reach the panel (with the alarm) only after the payment is confirmed.</p>
                <div class="max-w-xs"><label for="pay-window" class="label">Time allowed to pay (minutes)</label><input id="pay-window" name="payment_window_minutes" type="number" min="5" max="120" value="{{ old('payment_window_minutes', $s['payment_window_minutes']) }}" class="field"><p class="mt-1 text-xs text-muted">Unpaid orders are cancelled after this and the stock goes back.</p></div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="pay_cash_enabled" value="1" @checked(old('pay_cash_enabled', $s['pay_cash_enabled']) === '1') class="rounded border-line text-brand-600"> Cash on delivery / pay at pickup</label>

                <div class="rounded-xl border border-brand-500/40 bg-brand-50/40 p-4">
                    <label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" name="pay_whatsapp_enabled" value="1" @checked(old('pay_whatsapp_enabled', $s['pay_whatsapp_enabled']) === '1') class="rounded border-line text-brand-600"> WhatsApp Pay — native “Review and Pay” inside WhatsApp (UPI apps, cards, netbanking)</label>
                    <p class="mt-1 text-xs text-muted">Your gateway is linked to WhatsApp in <b>WhatsApp Manager → Payment configurations</b>. Copy the configuration name here. Needs the WhatsApp catalog (META_CATALOG_ID).</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div><label for="wa-gw" class="label">Gateway linked in WhatsApp Manager</label>
                            <select id="wa-gw" name="wa_pay_gateway" class="field">
                                @foreach (['razorpay' => 'Razorpay', 'payu' => 'PayU', 'zaakpay' => 'Zaakpay', 'billdesk' => 'BillDesk'] as $v => $l)
                                    <option value="{{ $v }}" @selected(old('wa_pay_gateway', $s['wa_pay_gateway']) === $v)>{{ $l }}</option>
                                @endforeach
                            </select></div>
                        <div><label for="wa-cfg" class="label">Payment configuration name</label><input id="wa-cfg" name="wa_pay_config_name" value="{{ old('wa_pay_config_name', $s['wa_pay_config_name']) }}" maxlength="60" class="field font-mono" placeholder="cafe-shala-razorpay"></div>
                        <div><label for="r-city" class="label">City</label><input id="r-city" name="restaurant_city" value="{{ old('restaurant_city', $s['restaurant_city']) }}" maxlength="60" class="field"></div>
                        <div class="grid grid-cols-2 gap-3">
                            <div><label for="r-state" class="label">State</label><input id="r-state" name="restaurant_state" value="{{ old('restaurant_state', $s['restaurant_state']) }}" maxlength="60" class="field"></div>
                            <div><label for="r-pin" class="label">PIN code</label><input id="r-pin" name="restaurant_pincode" value="{{ old('restaurant_pincode', $s['restaurant_pincode']) }}" maxlength="6" inputmode="numeric" class="field" placeholder="302016"></div>
                        </div>
                    </div>
                    <p class="mt-2 text-xs text-muted">City / state / PIN are used for delivery orders when the customer’s address has no PIN code (Meta requires a structured address).</p>
                </div>

                <div class="rounded-xl border border-line p-4">
                    <label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" name="pay_razorpay_enabled" value="1" @checked(old('pay_razorpay_enabled', $s['pay_razorpay_enabled']) === '1') class="rounded border-line text-brand-600"> Razorpay payment link (backup option, opens in the browser)</label>
                    <p class="mt-1 text-xs text-muted">Razorpay Dashboard → Account &amp; Settings → API Keys. Use <code>rzp_test_</code> keys to try without real money.</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div><label for="rzp-id" class="label">Key ID</label><input id="rzp-id" name="razorpay_key_id" value="{{ old('razorpay_key_id', $s['razorpay_key_id']) }}" class="field font-mono" placeholder="rzp_test_..." autocomplete="off"></div>
                        <div><label for="rzp-secret" class="label">Key Secret</label><input id="rzp-secret" name="razorpay_key_secret" type="password" class="field font-mono" placeholder="{{ $hasKeySecret ? 'Saved — leave empty to keep' : 'Paste key secret' }}" autocomplete="new-password"></div>
                        <div class="sm:col-span-2"><label for="rzp-wh" class="label">Webhook Secret</label><input id="rzp-wh" name="razorpay_webhook_secret" type="password" class="field font-mono" placeholder="{{ $hasWebhookSecret ? 'Saved — leave empty to keep' : 'Same secret you type in Razorpay → Webhooks' }}" autocomplete="new-password"></div>
                    </div>
                    <p class="mt-3 text-xs text-muted">In Razorpay → Webhooks add <code class="break-all">{{ $webhookUrl }}</code> with the event <b>payment_link.paid</b>. Paid orders then turn “Paid” here on their own.</p>
                </div>

            </fieldset>

            <fieldset class="space-y-4 border-t border-line pt-6">
                <legend class="font-semibold">Contact for the demo</legend>
                <div class="max-w-xs">
                    <label for="sales_contact" class="label">Sales contact number</label>
                    <input id="sales_contact" name="sales_contact" maxlength="20" value="{{ old('sales_contact', $s['sales_contact']) }}" class="field" inputmode="tel">
                </div>
            </fieldset>

            <div class="border-t border-line pt-5"><button class="btn-primary">Save settings</button></div>
        </form>

        <aside class="space-y-5">
            <section class="card p-5">
                <h2 class="font-semibold">Right now</h2>
                <p class="mt-3 flex items-center gap-2 text-sm">
                    <span class="size-2 rounded-full {{ $isOpen ? 'bg-emerald-500' : 'bg-slate-400' }}"></span>
                    {{ $isOpen ? 'Open by the clock' : 'Closed by the clock' }}
                </p>
                @if ($s['accepting_orders'] !== '1')
                    <p class="mt-2 text-sm text-amber-700">New orders are switched off.</p>
                @endif
            </section>

            <section class="card p-5">
                <h2 class="font-semibold">Razorpay</h2>
                <p class="mt-2 text-sm text-muted">{{ $s['razorpay_key_id'] && $hasKeySecret ? 'Keys saved ('.(str_starts_with($s['razorpay_key_id'], 'rzp_test_') ? 'test mode' : 'live mode').').' : 'Not set up.' }}</p>
                <form method="post" action="{{ route('settings.razorpay.test') }}" class="mt-3">@csrf<button class="btn-ghost w-full">Test connection</button></form>
            </section>

            <section class="card p-5">
                <h2 class="font-semibold">WhatsApp bot</h2>
                <p class="mt-3 flex items-center gap-2 text-sm">
                    <span class="size-2 rounded-full {{ $bot['ok'] ? 'bg-emerald-500' : 'bg-rose-500' }}"></span>
                    {{ $bot['ok'] ? 'Connected' : 'Not reachable' }}
                </p>
                <p class="mt-1 text-xs break-all text-muted">{{ $bot['url'] }}/health</p>
                @unless ($bot['ok'])
                    <p class="mt-3 text-xs text-muted">Start the bot with <code class="rounded bg-canvas px-1">npm start</code> in the bot folder. Until then, order status updates can’t reach customers.</p>
                @endunless
            </section>
        </aside>
    </div>
</x-layouts.admin>
