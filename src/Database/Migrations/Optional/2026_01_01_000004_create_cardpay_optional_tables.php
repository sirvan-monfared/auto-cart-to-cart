<?php

declare(strict_types=1);

use CartBecart\CardPay\Support\Edition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature-scoped tables (§16). Neither is on the money path, so a lite install
 * — one shop, no bundled panel — simply never creates them: the audit trail
 * belongs to the host's own activity log, and settings come from config.
 *
 * This migration is only registered when at least one of the two features is
 * enabled, and each table is guarded independently. Turning `audit` or
 * `db_settings` on later makes the migration pending again, so `migrate`
 * creates the missing table with no manual SQL. The hasTable() guards also
 * make it a no-op on installs that predate this split, where both tables were
 * created by the integration migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Edition::enabled('audit') && ! Schema::hasTable('cp_audit_logs')) {
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
        }

        if (Edition::enabled('db_settings') && ! Schema::hasTable('cp_settings')) {
            Schema::create('cp_settings', function (Blueprint $table) {
                $table->id();
                $table->string('setting_key', 190)->unique();
                $table->longText('setting_value')->nullable();
                $table->string('value_type', 30)->default('string');
                $table->boolean('is_public')->default(false);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cp_settings');
        Schema::dropIfExists('cp_audit_logs');
    }
};
