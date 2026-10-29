<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // When staff can first see the order. Online-payment orders get this only once paid.
            $table->timestamp('visible_at')->nullable()->after('status')->index();
            // reference_id sent in the WhatsApp Pay order_details message (unique per message).
            $table->string('payment_reference', 40)->nullable()->unique()->after('payment_ref');
        });
        DB::table('orders')->update(['visible_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['payment_reference']);
            $table->dropIndex(['visible_at']);
            $table->dropColumn(['visible_at', 'payment_reference']);
        });
    }
};
