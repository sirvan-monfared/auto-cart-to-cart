<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Referential integrity for CardPay (§7 note / §12), layered after all tables
 * exist so circular references (payments ↔ incoming_sms, reservations →
 * payments) can be expressed.
 *
 * SQLite cannot add foreign keys to an existing table via ALTER, so this is a
 * no-op there (the test database). Correctness of the money path never depends
 * on FKs — it rests on the inline UNIQUE constraints and conditional updates,
 * which exist on every driver. On MySQL these constraints add defence in depth.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        // Attribution FKs point at the HOST's user table (resolvable only after
        // the host model is configured; users is the standard Laravel name).
        $usersTable = (new ((string) config('cardpay.user.model', User::class)))->getTable();

        Schema::table('cp_bank_cards', function (Blueprint $table) {
            $table->foreign('sms_parser_id')->references('id')->on('cp_sms_parsers')->nullOnDelete();
        });

        Schema::table('cp_applications', function (Blueprint $table) {
            $table->foreign('default_bank_card_id')->references('id')->on('cp_bank_cards')->nullOnDelete();
        });

        Schema::table('cp_application_api_keys', function (Blueprint $table) {
            $table->foreign('application_id')->references('id')->on('cp_applications')->cascadeOnDelete();
        });

        Schema::table('cp_payments', function (Blueprint $table) {
            $table->foreign('application_id')->references('id')->on('cp_applications')->restrictOnDelete();
            $table->foreign('bank_card_id')->references('id')->on('cp_bank_cards')->restrictOnDelete();
            $table->foreign('matched_sms_id')->references('id')->on('cp_incoming_sms')->nullOnDelete();
        });

        Schema::table('cp_payment_token_reservations', function (Blueprint $table) {
            $table->foreign('payment_id')->references('id')->on('cp_payments')->nullOnDelete();
            $table->foreign('bank_card_id')->references('id')->on('cp_bank_cards')->restrictOnDelete();
        });

        Schema::table('cp_manual_review_requests', function (Blueprint $table) {
            $table->foreign('payment_id')->references('id')->on('cp_payments')->cascadeOnDelete();
            $table->foreign('incoming_sms_id')->references('id')->on('cp_incoming_sms')->nullOnDelete();
            $table->foreign('reviewed_by')->references('id')->on($usersTable)->nullOnDelete();
        });

        Schema::table('cp_devices', function (Blueprint $table) {
            $table->foreign('bank_card_id')->references('id')->on('cp_bank_cards')->restrictOnDelete();
        });

        Schema::table('cp_incoming_sms', function (Blueprint $table) {
            $table->foreign('device_id')->references('id')->on('cp_devices')->cascadeOnDelete();
            $table->foreign('bank_card_id')->references('id')->on('cp_bank_cards')->restrictOnDelete();
            $table->foreign('matched_payment_id')->references('id')->on('cp_payments')->nullOnDelete();
        });

        Schema::table('cp_payment_matches', function (Blueprint $table) {
            $table->foreign('payment_id')->references('id')->on('cp_payments')->cascadeOnDelete();
            $table->foreign('incoming_sms_id')->references('id')->on('cp_incoming_sms')->cascadeOnDelete();
            $table->foreign('decided_by')->references('id')->on($usersTable)->nullOnDelete();
        });

        Schema::table('cp_webhook_events', function (Blueprint $table) {
            $table->foreign('application_id')->references('id')->on('cp_applications')->restrictOnDelete();
            $table->foreign('payment_id')->references('id')->on('cp_payments')->cascadeOnDelete();
        });

        Schema::table('cp_webhook_deliveries', function (Blueprint $table) {
            $table->foreign('webhook_event_id')->references('id')->on('cp_webhook_events')->cascadeOnDelete();
        });

        Schema::table('cp_idempotency_keys', function (Blueprint $table) {
            $table->foreign('application_id')->references('id')->on('cp_applications')->cascadeOnDelete();
            $table->foreign('payment_id')->references('id')->on('cp_payments')->nullOnDelete();
        });

        Schema::table('cp_api_nonces', function (Blueprint $table) {
            $table->foreign('application_id')->references('id')->on('cp_applications')->cascadeOnDelete();
        });

        Schema::table('cp_device_nonces', function (Blueprint $table) {
            $table->foreign('device_id')->references('id')->on('cp_devices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        $drops = [
            'cp_bank_cards' => ['sms_parser_id'],
            'cp_applications' => ['default_bank_card_id'],
            'cp_application_api_keys' => ['application_id'],
            'cp_payments' => ['application_id', 'bank_card_id', 'matched_sms_id'],
            'cp_payment_token_reservations' => ['payment_id', 'bank_card_id'],
            'cp_manual_review_requests' => ['payment_id', 'incoming_sms_id', 'reviewed_by'],
            'cp_devices' => ['bank_card_id'],
            'cp_incoming_sms' => ['device_id', 'bank_card_id', 'matched_payment_id'],
            'cp_payment_matches' => ['payment_id', 'incoming_sms_id', 'decided_by'],
            'cp_webhook_events' => ['application_id', 'payment_id'],
            'cp_webhook_deliveries' => ['webhook_event_id'],
            'cp_idempotency_keys' => ['application_id', 'payment_id'],
            'cp_api_nonces' => ['application_id'],
            'cp_device_nonces' => ['device_id'],
        ];

        foreach ($drops as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($columns) {
                foreach ($columns as $column) {
                    $table->dropForeign([$column]);
                }
            });
        }
    }
};
