<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDecisionReceiptGuardService;
use Tests\TestCase;

/**
 * Pins the documented Decision Receipt boundary rules.
 *
 * @see docs/engineering-knowledge-base/system-graph/decision-receipt.md
 */
final class AtlasDecisionReceiptGuardTest extends TestCase
{
    private AtlasDecisionReceiptGuardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasDecisionReceiptGuardService;
    }

    /**
     * @return array<string,mixed>
     */
    private function completeReceipt(array $overrides = []): array
    {
        return array_merge([
            'decision_id' => 'dec_1',
            'trace_id' => 'trace_1',
            'obra' => 'atlas-kernel',
            'domain' => 'engineering',
            'flow' => 'engineering.refactor',
            'provider' => 'atlas.decide',
            'fallback' => 'atlas.decide.secondary',
            'confidence' => 0.9,
            'budget' => ['tokens' => 1000],
            'autonomy' => 'governed',
            'allowed_scope' => ['app/Services/**'],
            'forbidden_scope' => ['app/Services/**/Secrets/**'],
            'rollback' => 'git restore -- app/Services',
            'required_gates' => ['unit-tests'],
        ], $overrides);
    }

    /** Rule 0 — "Nenhuma execucao relevante deve ocorrer sem receipt." */
    public function test_no_receipt_stops_execution(): void
    {
        $d = $this->service->decide(null, 'app/Services/Foo.php');

        $this->assertSame('stop', $d['verdict']);
        $this->assertFalse($d['allowed']);
        $this->assertSame('no_receipt', $d['reason']);
    }

    /** Rule 1 — an incomplete receipt is governance theater: it must stop and name the gap. */
    public function test_incomplete_receipt_stops_and_lists_missing_fields(): void
    {
        $receipt = $this->completeReceipt();
        unset($receipt['rollback'], $receipt['allowed_scope']);

        $d = $this->service->decide($receipt, 'app/Services/Foo.php');

        $this->assertSame('stop', $d['verdict']);
        $this->assertSame('receipt_incomplete', $d['reason']);
        $this->assertContains('rollback', $d['detail']['missing_fields']);
        $this->assertContains('allowed_scope', $d['detail']['missing_fields']);
    }

    /** Rule 4/5 — target inside allowed scope and outside forbidden scope proceeds. */
    public function test_target_inside_allowed_scope_proceeds(): void
    {
        $d = $this->service->decide($this->completeReceipt([
            'allowed_scope' => [' app/Services/** ', 'app/Services/**', '', null],
            'forbidden_scope' => [' app/Services/**/Secrets/** ', 'app/Services/**/Secrets/**'],
        ]), 'app/Services/Billing/InvoiceService.php');

        $this->assertSame('proceed', $d['verdict']);
        $this->assertTrue($d['allowed']);
        $this->assertSame('within_contract', $d['reason']);
    }

    /** Regra para IA — a target outside the allowed scope must stop. */
    public function test_target_outside_allowed_scope_stops(): void
    {
        $d = $this->service->decide($this->completeReceipt(), 'config/app.php');

        $this->assertSame('stop', $d['verdict']);
        $this->assertSame('target_outside_allowed_scope', $d['reason']);
    }

    /** Risk — "Runtime ignorar escopo proibido": forbidden wins even when it also matches allowed. */
    public function test_forbidden_scope_wins_over_allowed(): void
    {
        // Path matches allowed (app/Services/**) AND forbidden (.../Secrets/**).
        $d = $this->service->decide(
            $this->completeReceipt(),
            'app/Services/Vault/Secrets/ApiKeyService.php'
        );

        $this->assertSame('stop', $d['verdict']);
        $this->assertSame('target_in_forbidden_scope', $d['reason']);
    }

    /** Rule 3 — "Assinatura existir sem validar payload real": signature present but unverified stops. */
    public function test_signature_required_but_unvalidated_stops(): void
    {
        $receipt = $this->completeReceipt([
            'requires_signature' => true,
            'signature' => 'ed25519:abc',
            'signature_valid' => false,
        ]);

        $stop = $this->service->decide($receipt, 'app/Services/Foo.php');
        $this->assertSame('stop', $stop['verdict']);
        $this->assertSame('signature_invalid', $stop['reason']);

        // Same receipt with a validated signature proceeds.
        $receipt['signature_valid'] = true;
        $proceed = $this->service->decide($receipt, 'app/Services/Foo.php');
        $this->assertSame('proceed', $proceed['verdict']);
    }

    /** Rule 2 — a receipt is a contract before runtime, not forever: expiry stops. */
    public function test_expired_receipt_stops(): void
    {
        $receipt = $this->completeReceipt(['expires_at' => '2020-01-01T00:00:00Z']);

        $d = $this->service->decide($receipt, 'app/Services/Foo.php', ['now' => '2026-06-01T00:00:00Z']);

        $this->assertSame('stop', $d['verdict']);
        $this->assertSame('receipt_expired', $d['reason']);
    }

    /** Bulk gate blocks the whole changeset the moment any file leaves the contract. */
    public function test_violations_reports_only_out_of_contract_files(): void
    {
        $files = [
            'app/Services/Ok.php',                       // in scope -> ok
            'config/app.php',                            // outside allowed -> stop
            'app/Services/Vault/Secrets/Key.php',        // forbidden -> stop
        ];

        $violations = $this->service->violations($this->completeReceipt(), $files);

        $this->assertCount(2, $violations);
        $this->assertSame('config/app.php', $violations[0]['file']);
        $this->assertSame('target_outside_allowed_scope', $violations[0]['reason']);
        $this->assertSame('target_in_forbidden_scope', $violations[1]['reason']);
    }
}
