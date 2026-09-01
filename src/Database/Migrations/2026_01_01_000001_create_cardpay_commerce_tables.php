<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CardPay commerce core (§7.1 / §12).
 *
 * Foreign keys are intentionally deferred to a dedicated later migration so
 * the create order is unconstrained (spec convention). Money is stored as
 * integer minor units. Domain datetimes use DATETIME (not TIMESTAMP) to avoid
 * the MySQL 2038 boundary; the app runs in UTC so every stored value is UTC
 * wall-clock, converted to the display timezone only at render time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cp_bank_cards', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->string('bank_name', 150);
            $table->text('card_number_encrypted');
            $table->string('card_number_last_four', 4);
            $table->string('card_holder_name', 190);
            $table->text('iban_encrypted')->nullable();
            $table->text('description')->nullable();
            $table->unsignedBigInteger('sms_parser_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active', 'cp_cards_active_idx');
        });

        Schema::create('cp_applications', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('slug', 150)->unique();
            $table->text('description')->nullable();
            $table->string('public_key', 100)->unique();
            $table->string('webhook_url', 500)->nullable();
            $table->string('callback_url', 500)->nullable();
            $table->text('allowed_domains')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('token_digits')->default(3);
            $table->unsignedInteger('payment_expiration_minutes')->default(30);
            $table->unsignedBigInteger('default_bank_card_id')->nullable();
            $table->timestamps();
        });

        Schema::create('cp_application_api_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('application_id');
            $table->string('public_key', 100)->unique();
            $table->text('secret_encrypted');
            $table->string('secret_fingerprint', 64);
            $table->string('label', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'is_active'], 'cp_apikeys_app_active_idx');
        });

        Schema::create('cp_payments', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 80)->unique();
            $table->unsignedBigInteger('application_id');
            $table->unsignedBigInteger('bank_card_id');
            $table->string('driver', 60)->default('card_transfer');
            $table->string('external_order_id', 190)->nullable();
            $table->unsignedBigInteger('original_amount');
            $table->unsignedInteger('token');
            $table->unsignedBigInteger('payable_amount');
            $table->string('currency', 10)->default('IRR');
            $table->text('description')->nullable();
            $table->string('customer_name', 190)->nullable();
            $table->string('customer_mobile', 30)->nullable();
            $table->string('customer_reference', 190)->nullable();
            $table->string('status', 30);
            $table->dateTime('expires_at');
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('canceled_at')->nullable();
            $table->unsignedBigInteger('matched_sms_id')->nullable();
            $table->string('return_url', 500)->nullable();
            $table->string('callback_url', 500)->nullable();
            $table->text('metadata_json')->nullable();
            $table->timestamps();

            // The matcher's hot path: equality on card+status+amount, range on expiry.
            $table->index(
                ['bank_card_id', 'status', 'payable_amount', 'expires_at'],
                'cp_payments_matcher_idx'
            );
            $table->index(['application_id', 'status', 'created_at'], 'cp_payments_app_idx');
            $table->index('created_at', 'cp_payments_created_idx');
        });

        Schema::create('cp_payment_token_reservations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->unsignedBigInteger('bank_card_id');
            $table->unsignedBigInteger('payable_amount');
            $table->unsignedInteger('token');
            // NULL = released. UNIQUE treats NULLs as distinct in both MySQL and
            // SQLite, so releasing (active_key=NULL) frees the amount slot while
            // keeping the historical row. This is the sole concurrency guard (A1).
            $table->boolean('active_key')->nullable()->default(true);
            $table->dateTime('release_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['bank_card_id', 'payable_amount', 'active_key'],
                'cp_ptr_amount_unique'
            );
            $table->index(['active_key', 'release_at'], 'cp_ptr_release_idx');
            $table->index('payment_id', 'cp_ptr_payment_idx');
        });

        Schema::create('cp_manual_review_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('incoming_sms_id')->nullable();
            $table->unsignedBigInteger('reported_amount')->nullable();
            $table->dateTime('approximate_paid_at')->nullable();
            $table->string('contact_mobile', 30)->nullable();
            $table->text('customer_note')->nullable();
            $table->string('receipt_path', 500)->nullable();
            $table->unsignedBigInteger('actual_amount')->nullable();
            $table->text('internal_note')->nullable();
            $table->string('status', 30)->default('pending');
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at'], 'cp_reviews_status_idx');
            $table->index('payment_id', 'cp_reviews_payment_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cp_manual_review_requests');
        Schema::dropIfExists('cp_payment_token_reservations');
        Schema::dropIfExists('cp_payments');
        Schema::dropIfExists('cp_application_api_keys');
        Schema::dropIfExists('cp_applications');
        Schema::dropIfExists('cp_bank_cards');
    }
};
