<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ---- Staff: two roles, admin and staff ----
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('staff')->after('email');
            $table->boolean('is_active')->default(true)->after('role');
        });
        DB::table('users')->update(['role' => 'admin']); // everyone who existed before is an admin

        // ---- Tables ----
        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->unsignedTinyInteger('seats')->default(4);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ---- Billing: counter / dine-in orders made in the admin ----
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->change();   // walk-in guests have no WhatsApp
            $table->foreignId('dining_table_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $table->string('guest_name', 120)->nullable()->after('dining_table_id');
            $table->string('guest_phone', 20)->nullable()->after('guest_name');
            $table->foreignId('created_by')->nullable()->after('cancel_reason')->constrained('users')->nullOnDelete();
        });

        // ---- Inventory: simple stock per menu item ----
        Schema::table('menu_items', function (Blueprint $table) {
            $table->boolean('track_stock')->default(false)->after('is_available');
            $table->integer('stock_qty')->default(0)->after('track_stock');
            $table->unsignedInteger('low_stock_at')->default(5)->after('stock_qty');
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->integer('change');                 // +10 restock, -2 sold
            $table->integer('balance_after');
            $table->string('reason', 20);              // sale | cancel | restock | adjust
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['menu_item_id', 'created_at']);
        });

        // ---- Expenses ----
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->date('spent_on');
            $table->string('category', 40);
            $table->string('description', 255);
            $table->unsignedInteger('amount_paise');
            $table->string('payment_method', 20)->default('cash');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('spent_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('stock_movements');
        Schema::table('menu_items', fn (Blueprint $t) => $t->dropColumn(['track_stock', 'stock_qty', 'low_stock_at']));
        Schema::table('orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('created_by');
            $t->dropConstrainedForeignId('dining_table_id');
            $t->dropColumn(['guest_name', 'guest_phone']);
        });
        Schema::dropIfExists('dining_tables');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'is_active']));
    }
};
