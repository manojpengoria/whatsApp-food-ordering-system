<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\View\View;

class KitchenController extends Controller
{
    public function __invoke(): View
    {
        $columns = [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::Ready];

        $orders = Order::query()
            ->whereIn('status', array_map(fn ($s) => $s->value, $columns))
            ->with(['items', 'customer', 'diningTable'])
            ->oldest('id')
            ->get()
            ->groupBy(fn (Order $o) => $o->status->value);

        return view('admin.kitchen', ['columns' => $columns, 'orders' => $orders]);
    }
}
