<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            // Path on the "public" disk, e.g. menu/abc123.jpg
            $table->string('image_path', 255)->nullable()->after('description');
            // When this item was last sent to the WhatsApp (Meta) catalog.
            $table->timestamp('catalog_synced_at')->nullable()->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropColumn(['image_path', 'catalog_synced_at']);
        });
    }
};
