<?php

declare(strict_types=1);

use CartBecart\CardPay\Enums\ApiErrorCode;
use CartBecart\CardPay\Exceptions\ApiException;
use CartBecart\CardPay\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case bindings
|--------------------------------------------------------------------------
|
| Feature tests boot the full framework via CartBecart\CardPay\Tests\TestCase. Unit tests stay
| on Pest's default PHPUnit\Framework\TestCase so pure logic (crypto, text
| normalization, state maps) runs without a framework bootstrap. Database
| refresh is opted into per integration-test file with uses(RefreshDatabase).
|
*/

uses(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Shared expectations
|--------------------------------------------------------------------------
*/

/**
 * Assert that $fn throws an ApiException carrying exactly the given catalog code
 * (§11.4). Declared once here so every API test shares one implementation
 * without redeclaring it across files in Pest's shared process.
 */
function expectApiError(Closure $fn, ApiErrorCode $expected): void
{
    try {
        $fn();
    } catch (ApiException $e) {
        expect($e->errorCode)->toBe($expected);

        return;
    }

    throw new RuntimeException("Expected ApiException [{$expected->value}] was not thrown.");
}
