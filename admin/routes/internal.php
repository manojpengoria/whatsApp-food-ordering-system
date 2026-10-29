<?php

use App\Http\Controllers\Internal\BotApiController;
use Illuminate\Support\Facades\Route;

// Prefix: /internal/v1  — called only by the Node bot (see bootstrap/app.php).
Route::get('restaurant', [BotApiController::class, 'restaurant']);
Route::get('menu', [BotApiController::class, 'menu']);
Route::post('customers', [BotApiController::class, 'upsertCustomer']);

Route::get('cart', [BotApiController::class, 'showCart']);
Route::post('cart/items', [BotApiController::class, 'addCartItem']);
Route::put('cart', [BotApiController::class, 'replaceCart']);
Route::put('cart/items/{code}', [BotApiController::class, 'setCartItem']);
Route::delete('cart', [BotApiController::class, 'clearCart']);

Route::get('orders', [BotApiController::class, 'listOrders']);
Route::post('orders', [BotApiController::class, 'placeOrder']);

Route::post('payments/razorpay', [BotApiController::class, 'razorpayWebhook']);
Route::post('payments/whatsapp', [BotApiController::class, 'whatsappPayment']);
Route::post('orders/{number}/abandon', [BotApiController::class, 'abandonOrder']);
