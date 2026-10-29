<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Support\LocalTime;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ExpenseController extends Controller
{
    public function index(Request $request): View
    {
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? LocalTime::now()->format('Y-m');
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01');

        $expenses = Expense::query()->with('user')
            ->whereDate('spent_on', '>=', $start->toDateString())->whereDate('spent_on', '<=', $start->endOfMonth()->toDateString())
            ->orderByDesc('spent_on')->orderByDesc('id')->get();

        return view('admin.expenses.index', [
            'expenses' => $expenses,
            'month' => $month,
            'monthLabel' => $start->format('F Y'),
            'total' => $expenses->sum('amount_paise'),
            'byCategory' => $expenses->groupBy('category')->map->sum('amount_paise')->sortDesc(),
            'categories' => Expense::CATEGORIES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $expense = Expense::query()->create($this->validated($request) + ['user_id' => $request->user()->id]);

        return back()->with('status', "Expense of {$expense->amount} added.");
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        $expense->update($this->validated($request));

        return back()->with('status', 'Expense saved.');
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        $expense->delete();

        return back()->with('status', 'Expense deleted.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'spent_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.LocalTime::now()->addDay()->format('Y-m-d')],
            'category' => ['required', Rule::in(Expense::CATEGORIES)],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'string', 'max:12'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
        ]);
        $paise = Money::parseRupees($data['amount']);
        if (! $paise) {
            throw ValidationException::withMessages(['amount' => 'Enter the amount in rupees, like 450 or 450.50.']);
        }
        unset($data['amount']);

        return $data + ['amount_paise' => $paise];
    }
}
