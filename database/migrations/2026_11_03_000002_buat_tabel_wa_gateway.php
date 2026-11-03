<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wa_gateway_auth_state')) {
            Schema::create('wa_gateway_auth_state', function (Blueprint $table) {
                $table->string('id', 191)->primary();
                $table->text('data');
                $table->timestampTz('updated_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('wa_gateway_outbox')) {
            Schema::create('wa_gateway_outbox', function (Blueprint $table) {
                $table->id();
                $table->string('idempotency_key', 191)->unique();
                $table->string('payload_hash', 128);
                $table->text('phone_enc');
                $table->string('phone_hint', 64);
                $table->text('message_enc');
                $table->string('status', 32)->default('queued');
                $table->integer('attempts')->default(0);
                $table->timestampTz('next_attempt_at')->useCurrent();
                $table->string('wa_message_id', 191)->nullable();
                $table->string('error_code', 64)->nullable();
                $table->text('error_message')->nullable();
                $table->timestampTz('created_at')->useCurrent();
                $table->timestampTz('updated_at')->useCurrent();
                $table->timestampTz('sent_at')->nullable();
                $table->timestampTz('delivered_at')->nullable();
                $table->timestampTz('expires_at');

                $table->index(['status', 'next_attempt_at'], 'wa_gateway_outbox_status_next_idx');
                $table->index('wa_message_id', 'wa_gateway_outbox_wa_msg_idx');
                $table->index('sent_at', 'wa_gateway_outbox_sent_at_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_gateway_outbox');
        Schema::dropIfExists('wa_gateway_auth_state');
    }
};
