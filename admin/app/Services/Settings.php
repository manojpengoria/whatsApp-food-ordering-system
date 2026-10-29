<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Restaurant settings stored as key/value rows. Values are strings; typed getters below.
 */
class Settings
{
    public const DEFAULTS = [
        'restaurant_name' => 'Cafe Shala',
        'restaurant_phone' => '',
        'restaurant_address' => '',
        'opening_time' => '10:00',
        'closing_time' => '22:00',
        'accepting_orders' => '1',
        'delivery_enabled' => '1',
        'tax_rate_bp' => '500',          // 5% GST
        'delivery_fee_paise' => '3000',  // ₹30
        'sales_contact' => '8690992982',
        'catalog_enabled' => '0',     // 1 = WhatsApp menu opens the photo catalog
        // Payments offered on WhatsApp
        'pay_cash_enabled' => '1',
        'pay_razorpay_enabled' => '0',
        'razorpay_key_id' => '',
        'razorpay_key_secret' => '',      // stored encrypted
        'razorpay_webhook_secret' => '',  // stored encrypted
        'pay_whatsapp_enabled' => '0',      // native WhatsApp Pay (order_details / Review and Pay)
        'wa_pay_gateway' => 'razorpay',     // razorpay | payu | zaakpay | billdesk (linked in WhatsApp Manager)
        'wa_pay_config_name' => '',         // payment configuration name from WhatsApp Manager
        'payment_window_minutes' => '15',   // unpaid online orders are cancelled after this
        'restaurant_city' => 'Jaipur',
        'restaurant_state' => 'Rajasthan',
        'restaurant_pincode' => '',
    ];

    private const CACHE_KEY = 'settings.all';

    private ?array $loaded = null;

    public function all(): array
    {
        if ($this->loaded === null) {
            $stored = Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()->pluck('value', 'key')->all());
            $this->loaded = array_merge(self::DEFAULTS, $stored);
        }

        return $this->loaded;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->all()[$key] ?? $default;
    }

    public function int(string $key): int
    {
        return (int) $this->get($key, '0');
    }

    public function bool(string $key): bool
    {
        return $this->get($key) === '1';
    }

    public function save(array $values): void
    {
        foreach ($values as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value === null ? null : (string) $value]);
        }
        Cache::forget(self::CACHE_KEY);
        $this->loaded = null;
    }

    /** Secrets are kept encrypted with APP_KEY. Returns '' if empty or unreadable. */
    public function secret(string $key): string
    {
        $value = (string) $this->get($key, '');
        if ($value === '') {
            return '';
        }
        try {
            return \Illuminate\Support\Facades\Crypt::decryptString($value);
        } catch (\Throwable) {
            return '';
        }
    }

    public function saveSecret(string $key, string $plain): void
    {
        $this->save([$key => $plain === '' ? '' : \Illuminate\Support\Facades\Crypt::encryptString($plain)]);
    }

    /**
     * Payment options a WhatsApp customer can pick, in menu order.
     * @return list<string> values of PaymentMethod
     */
    public function whatsappPaymentMethods(): array
    {
        $methods = [];
        if ($this->bool('pay_razorpay_enabled') && $this->get('razorpay_key_id') !== '' && $this->secret('razorpay_key_secret') !== '') {
            $methods[] = 'razorpay';
        }
        if ($this->whatsappPayReady()) {
            array_unshift($methods, 'whatsapp_pay');
        }
        if ($this->bool('pay_cash_enabled') || $methods === []) {
            $methods[] = 'cash';
        }

        return $methods;
    }

    public function whatsappPayReady(): bool
    {
        return $this->bool('pay_whatsapp_enabled')
            && $this->get('wa_pay_config_name') !== ''
            && filled(config('restaurant.catalog.id'));
    }

    public function paymentWindowMinutes(): int
    {
        return max(5, min(120, $this->int('payment_window_minutes') ?: 15));
    }

    public function timezone(): string
    {
        return config('restaurant.timezone');
    }

    /** Is the kitchen open right now (by opening/closing time, in restaurant timezone)? Handles overnight hours. */
    public function isOpenAt(?CarbonImmutable $at = null): bool
    {
        $at = ($at ?? CarbonImmutable::now())->setTimezone($this->timezone());
        $now = $at->format('H:i');
        $open = $this->get('opening_time');
        $close = $this->get('closing_time');

        if ($open === $close) {
            return true; // treated as open 24 hours
        }

        return $open < $close
            ? ($now >= $open && $now < $close)
            : ($now >= $open || $now < $close);
    }

    public function canTakeOrders(): bool
    {
        return $this->bool('accepting_orders') && $this->isOpenAt();
    }

    public function hoursLabel(): string
    {
        $fmt = fn (string $t) => CarbonImmutable::createFromFormat('H:i', $t)->format('g:i A');

        return $fmt($this->get('opening_time')).' – '.$fmt($this->get('closing_time'));
    }
}
