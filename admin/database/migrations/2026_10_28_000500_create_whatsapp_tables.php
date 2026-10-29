<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Laravel owns the schema of these three tables, but ONLY the Node bot writes to them.
 * Laravel reads them (e.g. to show the chat on an order page).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->json('payload');
            $table->unsignedSmallInteger('message_count')->default(0);
            $table->unsignedSmallInteger('status_count')->default(0);
            $table->timestamp('received_at', 3)->useCurrent();
            $table->timestamp('processed_at', 3)->nullable();
            $table->text('error')->nullable();

            $table->index('received_at');
        });

        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('wa_id', 20)->unique();
            $table->string('profile_name', 120)->nullable();
            $table->string('state', 40)->default('idle');
            $table->json('context')->nullable();
            $table->timestamp('last_inbound_at', 3)->nullable();
            $table->timestamp('last_outbound_at', 3)->nullable();
            $table->timestamps(3);
        });

        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            // Meta's message id. UNIQUE = durable duplicate protection for inbound messages.
            $table->string('wa_message_id', 128)->nullable()->unique();
            // Our own reference for outbound sends (e.g. "notify:42") so retries don't double-send.
            $table->string('client_ref', 64)->nullable()->unique();
            $table->foreignId('conversation_id')->nullable()->constrained('whatsapp_conversations')->nullOnDelete();
            $table->foreignId('webhook_event_id')->nullable()->constrained('webhook_events')->nullOnDelete();
            $table->string('wa_id', 20);
            $table->string('direction', 3);           // in | out
            $table->string('type', 30);
            $table->text('body')->nullable();
            $table->string('status', 20);             // received | sending | sent | delivered | read | failed
            $table->text('error')->nullable();
            $table->timestamps(3);

            $table->index(['wa_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
        Schema::dropIfExists('whatsapp_conversations');
        Schema::dropIfExists('webhook_events');
    }
};
