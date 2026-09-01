<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CardPay SMS plane (§7.2 / §12): parser presets, trusted relay devices,
 * ingested messages, and evidence links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cp_sms_parsers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('bank_name', 150);
            $table->string('sender_pattern', 255)->nullable();
            $table->string('amount_regex', 500);
            $table->string('date_regex', 500)->nullable();
            $table->string('time_regex', 500)->nullable();
            $table->string('transaction_type_regex', 500)->nullable();
            $table->text('positive_keywords')->nullable();
            $table->text('negative_keywords')->nullable();
            $table->text('sample_sms')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cp_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('platform', 20);
            $table->string('device_key', 100)->unique();
            $table->text('device_secret_encrypted');
            $table->string('secret_fingerprint', 64);
            $table->unsignedBigInteger('bank_card_id');
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_seen_at')->nullable();
            $table->string('last_ip', 64)->nullable();
            $table->unsignedBigInteger('sms_count')->default(0);
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['bank_card_id', 'is_active'], 'cp_devices_card_active_idx');
        });

        Schema::create('cp_incoming_sms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('bank_card_id');
            $table->string('message_id', 190);
            $table->string('sender', 190)->nullable();
            $table->text('raw_sms');
            $table->dateTime('received_at');
            $table->dateTime('server_received_at');
            $table->string('source_ip', 64)->nullable();
            $table->string('parse_status', 30)->default('pending');
            $table->unsignedBigInteger('parsed_amount')->nullable();
            $table->dateTime('parsed_transaction_at')->nullable();
            $table->string('parse_error', 500)->nullable();
            $table->string('match_status', 30)->default('unmatched');
            $table->unsignedBigInteger('matched_payment_id')->nullable();
            $table->dateTime('used_at')->nullable();
            $table->timestamps();

            // Per-device dedupe (SR-4): the same relayed message can never be
            // ingested twice.
            $table->unique(['device_id', 'message_id'], 'cp_sms_device_msg_unique');
            $table->index(
                ['bank_card_id', 'match_status', 'parsed_amount', 'created_at'],
                'cp_sms_matcher_idx'
            );
        });

        Schema::create('cp_payment_matches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('incoming_sms_id');
            $table->string('match_type', 30);
            $table->string('confidence', 20);
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['payment_id', 'incoming_sms_id'], 'cp_matches_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cp_payment_matches');
        Schema::dropIfExists('cp_incoming_sms');
        Schema::dropIfExists('cp_devices');
        Schema::dropIfExists('cp_sms_parsers');
    }
};
