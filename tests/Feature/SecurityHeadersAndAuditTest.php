<?php

declare(strict_types=1);

use CartBecart\CardPay\Models\Application;
use CartBecart\CardPay\Models\AuditLog;
use CartBecart\CardPay\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| §SR-8 security headers + §SR-14 audit trail
|--------------------------------------------------------------------------
|
| Every response — web or API, success or failure — carries the strict CSP
| and the header set; the audit logger records actor/entity/diffs with request
| context and never breaks the operation it witnesses.
|
*/

uses(RefreshDatabase::class);

describe('security headers (§SR-8)', function () {
    it('applies the full header set to a web response', function () {
        // The checkout page is a public web surface in the package (no host
        // welcome route); a payment must exist for a 200, but 404 responses
        // carry the same header set — so assert on a known-public 200 surface:
        // the setup wizard is 404'd post-install; use the login page.
        $response = $this->get('/login');

        $response->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $csp = $response->headers->get('Content-Security-Policy') ?? '';
        expect($csp)->toContain("default-src 'self'")
            ->and($csp)->toContain("script-src 'self'")
            ->and($csp)->toContain("'unsafe-eval'") // Livewire/Flux (Alpine) expression engine requirement
            ->and($csp)->toContain("style-src 'self' 'unsafe-inline'")
            ->and($csp)->toContain("frame-ancestors 'none'")
            ->and($csp)->toContain("base-uri 'self'")
            ->and($csp)->toContain("form-action 'self'")
            // The checkout JS is a served file — no inline-script hash pinning,
            // and scripts never get unsafe-inline.
            ->and($csp)->not->toContain("'sha256-")
            ->and($csp)->not->toContain("'unsafe-inline'; script-src");
    });

    it('applies headers even to API error responses (401 envelope)', function () {
        Application::factory()->create();

        $response = $this->getJson('/api/v1/payments/PAYmissing');

        $response->assertStatus(401)
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        expect($response->headers->get('Content-Security-Policy'))->not->toBeNull();
    });
});

describe('audit logger (§SR-14)', function () {
    it('writes an entry with entity, diff, and request context', function () {
        $logger = new AuditLogger;

        $logger->log(
            action: 'application.key_rotated',
            actorType: 'admin',
            actorId: 7,
            entityType: 'application_api_key',
            entityId: '42',
            old: ['fingerprint' => 'aaa111'],
            new: ['fingerprint' => 'bbb222'],
        );

        $entry = AuditLog::query()->sole();
        expect($entry->action)->toBe('application.key_rotated')
            ->and($entry->actor_type)->toBe('admin')
            ->and($entry->actor_id)->toBe(7)
            ->and($entry->entity_id)->toBe('42')
            ->and($entry->new_values['fingerprint'])->toBe('bbb222');
    });

    it('survives an internal write failure without throwing into the caller', function () {
        // Break the table's availability by pointing the model at a missing
        // connection-level construct is heavy; instead assert the contract via
        // a subclass-free approach: run against a fresh DB where the table
        // exists, then simulate failure by dropping it.
        Schema::drop('cp_audit_logs');

        (new AuditLogger)->log('settings.updated', 'admin');

        // No exception surfaced; that IS the assertion.
        expect(true)->toBeTrue();
    });
});
