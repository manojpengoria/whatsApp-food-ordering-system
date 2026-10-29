<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Settings;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class SettingsController extends Controller
{
    public function __construct(private readonly Settings $settings) {}

    public function edit(): View
    {
        return view('admin.settings', [
            's' => $this->settings->all(),
            'hasKeySecret' => $this->settings->secret('razorpay_key_secret') !== '',
            'hasWebhookSecret' => $this->settings->secret('razorpay_webhook_secret') !== '',
            'webhookUrl' => preg_replace('#/media/menu/?$#', '', (string) config('restaurant.public_media_url')).'/webhooks/razorpay',
            'bot' => $this->botHealth(),
            'isOpen' => $this->settings->isOpenAt(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'restaurant_name' => ['required', 'string', 'max:80'],
            'restaurant_phone' => ['nullable', 'string', 'max:20'],
            'restaurant_address' => ['nullable', 'string', 'max:255'],
            'opening_time' => ['required', 'date_format:H:i'],
            'closing_time' => ['required', 'date_format:H:i'],
            'tax_percent' => ['required', 'numeric', 'min:0', 'max:28'],
            'delivery_fee' => ['required', 'string', 'max:8'],
            'sales_contact' => ['nullable', 'string', 'max:20'],
            'razorpay_key_id' => ['nullable', 'string', 'max:40', 'regex:/^rzp_(test|live)_[A-Za-z0-9]+$/'],
            'razorpay_key_secret' => ['nullable', 'string', 'max:100'],
            'razorpay_webhook_secret' => ['nullable', 'string', 'max:100'],
            'wa_pay_gateway' => ['required', 'in:razorpay,payu,zaakpay,billdesk'],
            'wa_pay_config_name' => ['nullable', 'string', 'max:60'],
            'payment_window_minutes' => ['required', 'integer', 'min:5', 'max:120'],
            'restaurant_city' => ['nullable', 'string', 'max:60'],
            'restaurant_state' => ['nullable', 'string', 'max:60'],
            'restaurant_pincode' => ['nullable', 'digits:6'],
        ], [
            'razorpay_key_id.regex' => 'Razorpay Key ID starts with rzp_test_ or rzp_live_.',
        ]);

        $fee = Money::parseRupees($data['delivery_fee']);
        if ($fee === null) {
            throw ValidationException::withMessages(['delivery_fee' => 'Enter the delivery fee in rupees, like 30 or 0.']);
        }

        $this->settings->save([
            'restaurant_name' => $data['restaurant_name'],
            'restaurant_phone' => $data['restaurant_phone'] ?? '',
            'restaurant_address' => $data['restaurant_address'] ?? '',
            'opening_time' => $data['opening_time'],
            'closing_time' => $data['closing_time'],
            'tax_rate_bp' => (int) round(((float) $data['tax_percent']) * 100),
            'delivery_fee_paise' => $fee,
            'sales_contact' => $data['sales_contact'] ?? '',
            'accepting_orders' => $request->boolean('accepting_orders') ? '1' : '0',
            'delivery_enabled' => $request->boolean('delivery_enabled') ? '1' : '0',
            'pay_cash_enabled' => $request->boolean('pay_cash_enabled') ? '1' : '0',
            'pay_razorpay_enabled' => $request->boolean('pay_razorpay_enabled') ? '1' : '0',
            'pay_whatsapp_enabled' => $request->boolean('pay_whatsapp_enabled') ? '1' : '0',
            'razorpay_key_id' => $data['razorpay_key_id'] ?? '',
            'wa_pay_gateway' => $data['wa_pay_gateway'],
            'wa_pay_config_name' => trim($data['wa_pay_config_name'] ?? ''),
            'payment_window_minutes' => $data['payment_window_minutes'],
            'restaurant_city' => $data['restaurant_city'] ?? '',
            'restaurant_state' => $data['restaurant_state'] ?? '',
            'restaurant_pincode' => $data['restaurant_pincode'] ?? '',
        ]);
        // Secrets: an empty box means "keep the saved one".
        foreach (['razorpay_key_secret', 'razorpay_webhook_secret'] as $key) {
            if (filled($data[$key] ?? null)) {
                $this->settings->saveSecret($key, trim($data[$key]));
            }
        }
        if ($request->boolean('pay_whatsapp_enabled') && ! $this->settings->whatsappPayReady()) {
            return back()->with('status', 'Settings saved, but WhatsApp Pay stays off until the configuration name is filled and META_CATALOG_ID is set.');
        }
        if ($request->boolean('pay_razorpay_enabled') && ! app(\App\Services\RazorpayService::class)->isConfigured()) {
            return back()->with('status', 'Settings saved, but Razorpay stays off until Key ID and Key Secret are filled.');
        }

        return back()->with('status', 'Settings saved. The WhatsApp bot uses them from the next message.');
    }

    public function testRazorpay(\App\Services\RazorpayService $razorpay): RedirectResponse
    {
        if (! $razorpay->isConfigured()) {
            return back()->withErrors(['razorpay' => 'Save the Razorpay Key ID and Key Secret first.']);
        }
        [$ok, $message] = $razorpay->testConnection();

        return $ok ? back()->with('status', 'Razorpay: '.$message) : back()->withErrors(['razorpay' => $message]);
    }

    /** Pings the Node bot's /health so staff can see if WhatsApp updates will go out. */
    private function botHealth(): array
    {
        $url = rtrim((string) config('services.bot.url'), '/');
        try {
            $res = Http::connectTimeout(1)->timeout(2)->acceptJson()->get($url.'/health');

            return ['ok' => $res->successful(), 'url' => $url, 'detail' => $res->json() ?? ['http' => $res->status()]];
        } catch (Throwable $e) {
            return ['ok' => false, 'url' => $url, 'detail' => ['error' => 'Not reachable']];
        }
    }
}
