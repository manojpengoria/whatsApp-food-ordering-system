<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DiningTable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TableController extends Controller
{
    public function index(): View
    {
        $tables = DiningTable::query()->orderBy('sort_order')->orderBy('name')
            ->with(['openOrder.items'])->get();

        return view('admin.tables.index', [
            'tables' => $tables,
            'occupied' => $tables->filter(fn ($t) => $t->openOrder)->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40', 'unique:dining_tables,name'],
            'seats' => ['required', 'integer', 'min:1', 'max:30'],
        ]);
        DiningTable::query()->create($data + ['sort_order' => (int) DiningTable::query()->max('sort_order') + 1]);

        return back()->with('status', "Table “{$data['name']}” added.");
    }

    public function update(Request $request, DiningTable $table): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40', Rule::unique('dining_tables', 'name')->ignore($table->id)],
            'seats' => ['required', 'integer', 'min:1', 'max:30'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:999'],
        ]);
        $table->update($data + ['is_active' => $request->boolean('is_active')]);

        return back()->with('status', "Table “{$table->name}” saved.");
    }

    public function destroy(DiningTable $table): RedirectResponse
    {
        if ($table->openOrder()->exists()) {
            return back()->withErrors(['table' => "“{$table->name}” has a running bill. Finish it first."]);
        }
        $table->delete();

        return back()->with('status', 'Table deleted. Past bills keep their record.');
    }
}
