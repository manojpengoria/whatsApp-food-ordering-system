<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $notCancelled = fn ($o) => $o->whereNotIn('status', OrderStatus::hiddenValues());

        $customers = Customer::query()
            ->withCount(['orders' => $notCancelled])
            ->withSum(['orders as spent_paise' => $notCancelled], 'total_paise')
            ->withMax('orders as last_order_at', 'created_at')
            ->when($q !== '', function ($query) use ($q) {
                $digits = preg_replace('/\D/', '', $q);
                $query->where(function ($w) use ($q, $digits) {
                    $w->where('name', 'like', '%'.$q.'%');
                    if (strlen($digits) >= 4) {
                        $w->orWhere('wa_id', 'like', '%'.$digits.'%');
                    }
                });
            })
            ->orderByDesc('last_order_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.customers.index', ['customers' => $customers, 'q' => $q]);
    }
}
