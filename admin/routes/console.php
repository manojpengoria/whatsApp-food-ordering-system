<?php

use App\Models\Customer;
use App\Models\Order;
use Database\Seeders\DemoOrdersSeeder;
use Illuminate\Support\Facades\Artisan;

Artisan::command('demo:clear', function () {
    $ids = Customer::query()->where('wa_id', 'like', DemoOrdersSeeder::FAKE_PREFIX.'%')->pluck('id');
    $orders = Order::query()->whereIn('customer_id', $ids)->delete();
    Customer::query()->whereIn('id', $ids)->delete();
    $this->info("Removed {$orders} demo orders and {$ids->count()} demo customers.");
})->purpose('Delete the sample orders created by DemoOrdersSeeder');
