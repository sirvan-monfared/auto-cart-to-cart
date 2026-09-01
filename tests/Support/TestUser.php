<?php

namespace CartBecart\CardPay\Tests\Support;

use CartBecart\CardPay\Concerns\IsGatewayUser;
use CartBecart\CardPay\Contracts\GatewayUser;
use CartBecart\CardPay\Tests\Database\Factories\TestUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * Stands in for a HOST application's user model in the package test suite:
 * the base users table + the published cardpay user-migration columns.
 */
class TestUser extends Authenticatable implements GatewayUser, PasskeyUser
{
    /** @use HasFactory<TestUserFactory> */
    use HasFactory, IsGatewayUser, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected $table = 'users';

    protected $fillable = [
        'name',
        'username',
        'email',
        'mobile',
        'role',
        'is_active',
        'email_verified_at',
        'password',
        'last_login_at',
        'last_ip',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function newFactory(): TestUserFactory
    {
        return TestUserFactory::new();
    }
}
