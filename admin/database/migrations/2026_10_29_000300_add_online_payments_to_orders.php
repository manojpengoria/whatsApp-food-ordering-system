<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_link_id', 64)->nullable()->unique()->after('paid_at');
            $table->string('payment_link_url', 255)->nullable()->after('payment_link_id');
            $table->string('payment_ref', 64)->nullable()->after('payment_link_url'); // gateway payment id
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['payment_link_id']);
            $table->dropColumn(['payment_link_id', 'payment_link_url', 'payment_ref']);
        });
    }
};
