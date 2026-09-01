<?php

declare(strict_types=1);

namespace CartBecart\CardPay\Services\Drivers;

use InvalidArgumentException;
use RuntimeException;

/**
 * Resolves the ACTIVE payment driver from configuration (§5/§16).
 *
 * Drivers register under their machine name; `cardpay.driver` picks one for
 * the whole deployment. An unknown configured name fails LOUDLY at resolution
 * time — a misconfiguration must surface as an exception, never as a silently
 * wrong payment method.
 */
final class DriverRegistry
{
    /** @var array<string, PaymentDriver> */
    private array $resolved = [];

    /**
     * @param  array<string, PaymentDriver>  $drivers  machine name → instance
     */
    public function __construct(
        private readonly array $drivers,
        private readonly string $activeName,
    ) {
        if ($drivers === []) {
            throw new InvalidArgumentException('No payment drivers registered.');
        }
    }

    /** The driver selected by configuration. */
    public function active(): PaymentDriver
    {
        return $this->resolve($this->activeName);
    }

    /**
     * @throws RuntimeException when the name is not registered
     */
    public function resolve(string $name): PaymentDriver
    {
        if (isset($this->resolved[$name])) {
            return $this->resolved[$name];
        }

        $driver = $this->drivers[$name]
            ?? throw new RuntimeException("Unknown payment driver [{$name}]. Registered: ".
                implode(', ', array_keys($this->drivers)).'.');

        return $this->resolved[$name] = $driver;
    }

    /**
     * All registered drivers, for admin display.
     *
     * @return list<array{name: string, label: string, supports_refund: bool}>
     */
    public function catalog(): array
    {
        $out = [];
        foreach ($this->drivers as $driver) {
            $out[] = [
                'name' => $driver->name(),
                'label' => $driver->label(),
                'supports_refund' => $driver->supportsRefund(),
            ];
        }

        return $out;
    }
}
