<?php

declare(strict_types=1);

use CartBecart\CardPay\Support\Edition;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/*
|--------------------------------------------------------------------------
| §16 edition + feature resolution
|--------------------------------------------------------------------------
|
| Editions pick DEFAULTS; an explicit config boolean always wins. That is what
| lets a deployment run lite with the audit trail on, or full without the
| bundled panel, instead of forking the package.
|
| A pure unit test: only a config repository is bound, no framework boot.
|
*/

function editionConfig(array $cardpay = []): void
{
    $container = new Container;
    $container->instance('config', new Repository(['cardpay' => $cardpay]));
    Container::setInstance($container);
}

afterEach(function () {
    Container::setInstance(null);
});

describe('edition resolution', function () {
    it('defaults to full when unset', function () {
        editionConfig();

        expect(Edition::current())->toBe('full')
            ->and(Edition::isFull())->toBeTrue()
            ->and(Edition::isLite())->toBeFalse();
    });

    it('reads lite from config, case- and whitespace-insensitively', function (string $raw) {
        editionConfig(['edition' => $raw]);

        expect(Edition::current())->toBe('lite')
            ->and(Edition::isLite())->toBeTrue();
    })->with(['lite', 'LITE', ' Lite ']);

    it('falls back to full for an unrecognised edition rather than disabling everything', function () {
        editionConfig(['edition' => 'enterprise']);

        expect(Edition::current())->toBe('full');
    });
});

describe('feature defaults per edition', function () {
    it('gives full the panel, wizard, audit, settings table and app CRUD', function (string $feature) {
        editionConfig(['edition' => 'full']);

        expect(Edition::enabled($feature))->toBeTrue();
    })->with(['panel', 'setup_wizard', 'audit', 'db_settings', 'applications_admin']);

    it('withholds all of those from lite', function (string $feature) {
        editionConfig(['edition' => 'lite']);

        expect(Edition::enabled($feature))->toBeFalse();
    })->with(['panel', 'setup_wizard', 'audit', 'db_settings', 'applications_admin']);

    it('keeps the payment engine surfaces in BOTH editions — lite is fewer screens, not a weaker gateway', function (string $feature) {
        editionConfig(['edition' => 'full']);
        expect(Edition::enabled($feature))->toBeTrue();

        editionConfig(['edition' => 'lite']);
        expect(Edition::enabled($feature))->toBeTrue();
    })->with(['admin_api', 'checkout', 'merchant_api', 'device_api']);
});

describe('explicit overrides', function () {
    it('lets lite turn a feature back on', function () {
        editionConfig(['edition' => 'lite', 'features' => ['audit' => true]]);

        expect(Edition::enabled('audit'))->toBeTrue()
            ->and(Edition::enabled('panel'))->toBeFalse();
    });

    it('lets full turn the bundled panel off without becoming lite', function () {
        editionConfig(['edition' => 'full', 'features' => ['panel' => false]]);

        expect(Edition::enabled('panel'))->toBeFalse()
            ->and(Edition::current())->toBe('full')
            ->and(Edition::enabled('audit'))->toBeTrue();
    });

    it('ignores a null override and uses the edition default', function () {
        editionConfig(['edition' => 'lite', 'features' => ['panel' => null]]);

        expect(Edition::enabled('panel'))->toBeFalse();
    });

    it('ignores a non-boolean override rather than coercing it', function () {
        // env() can hand back a string; only a real bool should override.
        editionConfig(['edition' => 'lite', 'features' => ['panel' => 'yes']]);

        expect(Edition::enabled('panel'))->toBeFalse();
    });
});

it('rejects an undeclared feature name instead of silently resolving to off', function () {
    editionConfig();

    Edition::enabled('teleportation');
})->throws(InvalidArgumentException::class, 'teleportation');

it('reports the whole resolved map for a host admin to render its menu from', function () {
    editionConfig(['edition' => 'lite']);

    $all = Edition::all();

    expect(array_keys($all))->toEqual(Edition::features())
        ->and($all['panel'])->toBeFalse()
        ->and($all['admin_api'])->toBeTrue();
});
