<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the existing users table with CardPay admin fields (§7.1 / §12).
 *
 * The starter kit's Fortify-backed `password`/`email` columns are kept intact
 * (Fortify + passkeys depend on them). We add username login, role/activation
 * gating, and login audit columns. `username` is nullable+unique so this
 * migration is safe on an already-populated table; Setup enforces presence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 190)->nullable()->unique()->after('name');
            $table->string('mobile', 30)->nullable()->after('email');
            $table->string('role', 40)->default('admin')->after('mobile');
            $table->boolean('is_active')->default(true)->after('role');
            $table->dateTime('last_login_at')->nullable()->after('is_active');
            $table->string('last_ip', 64)->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'mobile', 'role', 'is_active', 'last_login_at', 'last_ip']);
        });
    }
};
