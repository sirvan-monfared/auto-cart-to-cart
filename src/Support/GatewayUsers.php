<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Support;

use App\Models\User;
use CartBecart\CardPay\Contracts\GatewayUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves the host's user model — the single place the package touches the
 * host's auth identities. The model class comes from config('cardpay.user.model)
 * and must implement GatewayUser (the host does this by adopting the
 * IsGatewayUser trait on its User model).
 */
final class GatewayUsers
{
    /**
     * The configured host user model FQCN.
     *
     * @return class-string<GatewayUser&Model>
     */
    public static function model(): string
    {
        $model = (string) config('cardpay.user.model', User::class);

        if (! is_a($model, Model::class, true)) {
            throw new \RuntimeException("cardpay.user.model [$model] must be an Eloquent model class.");
        }

        if (! is_a($model, GatewayUser::class, true)) {
            throw new \RuntimeException(
                "cardpay.user.model [$model] must implement CartBecart\CardPay\Contracts\GatewayUser — ".
                'add the CartBecart\CardPay\Concerns\IsGatewayUser trait to your User model.'
            );
        }

        return $model;
    }

    /**
     * A fresh query on the host user model.
     *
     * @return Builder<GatewayUser&Model>
     */
    public static function query(): Builder
    {
        /** @var Builder<GatewayUser&Model> $query */
        $query = self::model()::query();

        return $query;
    }

    /**
     * Find a user by the login username column.
     */
    public static function findByUsername(string $username): ?GatewayUser
    {
        /** @var GatewayUser|null */
        return self::query()->where('username', $username)->first();
    }

    /**
     * Any active admin already present (setup wizard skip logic).
     */
    public static function hasActiveAdmin(): bool
    {
        return self::query()
            ->where('role', 'admin')
            ->where('is_active', true)
            ->exists();
    }
}
