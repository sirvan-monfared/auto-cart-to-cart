<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CardPay integration & infrastructure plane (§7.3 / §12): webhooks,
 * idempotency ledger, anti-replay nonces, rate-limit buckets, audit trail,
 * and settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cp_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 80)->unique();
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('payment_id');
            $table->string('event_type', 80);
            $table->text('payload_json');
            $table->timestamps();

            // One-shot emission (§FR-13 / SR-4): at most one event row per
            // (payment, event_type).
            $table->unique(['payment_id', 'event_type'], 'cp_webhook_events_oneshot_unique');
        });

        Schema::create('cp_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('webhook_event_id');
            $table->string('url', 500);
            $table->unsignedInteger('attempt')->default(0);
            $table->string('status', 30)->default('pending');
            $table->unsignedInteger('response_status')->nullable();
            $table->text('response_body')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_message', 500)->nullable();
            $table->dateTime('next_attempt_at')->nullable();
            $table->dateTime('last_attempt_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at'], 'cp_deliveries_due_idx');
            $table->index('webhook_event_id', 'cp_deliveries_event_idx');
        });

        Schema::create('cp_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('application_id');
            $table->string('idempotency_key', 190);
            $table->string('request_hash', 64);
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->text('response_json')->nullable();
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->unique(['application_id', 'idempotency_key'], 'cp_idem_app_key_unique');
            $table->index('expires_at', 'cp_idem_expires_idx');
        });

        Schema::create('cp_api_nonces', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('application_id');
            $table->string('nonce', 190);
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->unique(['application_id', 'nonce'], 'cp_api_nonces_unique');
            $table->index('expires_at', 'cp_api_nonces_expires_idx');
        });

        Schema::create('cp_device_nonces', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('device_id');
            $table->string('nonce', 190);
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->unique(['device_id', 'nonce'], 'cp_device_nonces_unique');
            $table->index('expires_at', 'cp_device_nonces_expires_idx');
        });

        Schema::create('cp_rate_limits', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 40);
            $table->string('rate_key', 190);
            $table->unsignedBigInteger('window_start');
            $table->unsignedInteger('attempts')->default(1);
            $table->dateTime('expires_at');
            $table->timestamps();

            $table->unique(['scope', 'rate_key', 'window_start'], 'cp_rate_limits_window_unique');
            $table->index('expires_at', 'cp_rate_limits_expires_idx');
        });

        Schema::create('cp_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 40);
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 120);
            $table->string('entity_type', 80)->nullable();
            $table->string('entity_id', 100)->nullable();
            $table->text('old_values')->nullable();
            $table->text('new_values')->nullable();
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id', 'created_at'], 'cp_audit_entity_idx');
        });

        Schema::create('cp_settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key', 190)->unique();
            $table->longText('setting_value')->nullable();
            $table->string('value_type', 30)->default('string');
            $table->boolean('is_public')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cp_settings');
        Schema::dropIfExists('cp_audit_logs');
        Schema::dropIfExists('cp_rate_limits');
        Schema::dropIfExists('cp_device_nonces');
        Schema::dropIfExists('cp_api_nonces');
        Schema::dropIfExists('cp_idempotency_keys');
        Schema::dropIfExists('cp_webhook_deliveries');
        Schema::dropIfExists('cp_webhook_events');
    }
};
