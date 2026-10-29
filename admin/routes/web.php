<?php

use App\Http\Controllers\Admin\BillingController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ExpenseController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\KitchenController;
use App\Http\Controllers\Admin\LiveController;
use App\Http\Controllers\Admin\MenuController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\TableController;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:6,1');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    // ---------- Admin and Staff ----------
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('live/poll', LiveController::class)->name('live.poll');

    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('orders.show');
    Route::post('orders/{order}/status', [OrderController::class, 'updateStatus'])->name('orders.status');
    Route::post('orders/{order}/paid', [OrderController::class, 'markPaid'])->name('orders.paid');
    Route::post('orders/{order}/notifications/{notification}/retry', [OrderController::class, 'retryNotification'])
        ->scopeBindings()->name('orders.notifications.retry');

    Route::get('billing', [BillingController::class, 'index'])->name('billing.index');
    Route::get('billing/new', [BillingController::class, 'create'])->name('billing.create');
    Route::post('billing', [BillingController::class, 'store'])->name('billing.store');
    Route::get('billing/{order}/print', [BillingController::class, 'print'])->name('billing.print');

    Route::get('tables', [TableController::class, 'index'])->name('tables.index');

    Route::get('kitchen', KitchenController::class)->name('kitchen');

    Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::post('inventory/{item}/adjust', [InventoryController::class, 'adjust'])->name('inventory.adjust');
    Route::get('inventory/{item}/history', [InventoryController::class, 'history'])->name('inventory.history');

    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');

    // ---------- Admin only ----------
    Route::middleware('admin')->group(function () {
        Route::get('menu', [MenuController::class, 'index'])->name('menu.index');
        Route::post('menu/categories', [MenuController::class, 'storeCategory'])->name('menu.categories.store');
        Route::put('menu/categories/{category}', [MenuController::class, 'updateCategory'])->name('menu.categories.update');
        Route::delete('menu/categories/{category}', [MenuController::class, 'destroyCategory'])->name('menu.categories.destroy');
        Route::get('menu/items/create', [MenuController::class, 'createItem'])->name('menu.items.create');
        Route::post('menu/items', [MenuController::class, 'storeItem'])->name('menu.items.store');
        Route::get('menu/items/{item}/edit', [MenuController::class, 'editItem'])->name('menu.items.edit');
        Route::put('menu/items/{item}', [MenuController::class, 'updateItem'])->name('menu.items.update');
        Route::post('menu/items/{item}/toggle', [MenuController::class, 'toggleItem'])->name('menu.items.toggle');
        Route::delete('menu/items/{item}', [MenuController::class, 'destroyItem'])->name('menu.items.destroy');
        Route::post('menu/catalog/sync', [MenuController::class, 'syncCatalog'])->name('menu.catalog.sync');
        Route::post('menu/catalog/status', [MenuController::class, 'catalogStatus'])->name('menu.catalog.status');
        Route::post('menu/catalog/toggle', [MenuController::class, 'toggleCatalogMode'])->name('menu.catalog.toggle');

        Route::post('tables', [TableController::class, 'store'])->name('tables.store');
        Route::put('tables/{table}', [TableController::class, 'update'])->name('tables.update');
        Route::delete('tables/{table}', [TableController::class, 'destroy'])->name('tables.destroy');

        Route::put('inventory/{item}/settings', [InventoryController::class, 'settings'])->name('inventory.settings');

        Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
        Route::put('expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
        Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');

        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/export', [ReportController::class, 'export'])->name('reports.export');

        Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
        Route::post('staff', [StaffController::class, 'store'])->name('staff.store');
        Route::put('staff/{user}', [StaffController::class, 'update'])->name('staff.update');

        Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('settings/razorpay-test', [SettingsController::class, 'testRazorpay'])->name('settings.razorpay.test');
    });
});
