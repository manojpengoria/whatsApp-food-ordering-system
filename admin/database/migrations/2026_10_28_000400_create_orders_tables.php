<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 20)->nullable()->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('status', 30)->default('pending')->index();
            $table->string('order_type', 20);            // delivery | takeaway
            $table->string('source', 20)->default('whatsapp');
            $table->text('delivery_address')->nullable();
            $table->string('notes', 500)->nullable();
            $table->unsignedInteger('subtotal_paise');
            $table->unsignedSmallInteger('tax_rate_bp')->default(0); // basis points, 500 = 5%
            $table->unsignedInteger('tax_paise')->default(0);
            $table->unsignedInteger('delivery_fee_paise')->default(0);
            $table->unsignedInteger('total_paise');
            $table->string('payment_method', 20)->default('cash');
            $table->string('payment_status', 20)->default('unpaid');
            $table->timestamp('paid_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            // Stops a retried WhatsApp message from creating the same order twice.
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->timestamps();

            $table->index('created_at');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->nullable()->constrained()->nullOnDelete();
            // Snapshot: later menu edits must not change past orders.
            $table->string('item_name', 120);
            $table->string('item_code', 10)->nullable();
            $table->unsignedInteger('unit_price_paise');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedInteger('line_total_paise');
            $table->timestamps();
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Outbox of WhatsApp notifications Laravel asks the Node bot to send.
        Schema::create('order_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40);
            $table->string('status_snapshot', 30)->nullable();
            $table->string('state', 20)->default('pending'); // pending | sent | failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->string('wa_message_id', 128)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notifications');
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
