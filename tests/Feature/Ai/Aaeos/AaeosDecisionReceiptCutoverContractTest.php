<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\EngineeringKernel\Coverage\EngineeringExecutionSurfaceRegistry;
use App\Services\Ai\Kernel\Decision\DecisionReceipt;
use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Decision\DecisionReceiptRuntimeGuard;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * P2b-CONTRACT: freeze post-cutover invariants (writer selection + dual-transport
 * companion on new issuance; live authority signature; R102 mode identity).
 * Rollback remains writer selection only — never rewrite signed bytes.
 * Reader still dual-reads V2 as governor; cutover LIVE = signed V3 companion.
 */
final class AaeosDecisionReceiptCutoverContractTest extends TestCase
{
    protected function tearDown(): void
    {
        config([
            'atlas.ai.decision_receipt_v3_cutover_enabled' => false,
            'atlas.ai.decision_receipt_v3_canary_percent' => 0,
        ]);
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_contract_freezes_cutover_defaults_and_schema_constants(): void
    {
        $this->assertFalse((bool) config('atlas.ai.decision_receipt_v3_cutover_enabled'));
        $this->assertSame(0, (int) config('atlas.ai.decision_receipt_v3_canary_percent'));
        $this->assertSame('atlas.decide.v2', DecisionReceipt::SCHEMA_VERSION);
        $this->assertSame('atlas.decide.v3', DecisionReceipt::SCHEMA_VERSION_V3);
        $this->assertSame('receipt_v2', DecisionReceipt::RECEIPT_V2_KEY);
        $this->assertSame('receipt_v3', DecisionReceipt::RECEIPT_V3_KEY);
    }

    public function test_contract_cutover_forces_companion_on_new_issuance_without_rewriting_v2(): void
    {
        config(['atlas.ai.decision_receipt_v3_cutover_enabled' => true]);
        $issuer = app(DecisionReceiptIssuer::class);
        $envelope = app(OperationEnvelopeFactory::class)->create([
            'text' => 'contract cutover',
            'operator' => ['operator_id' => 'op-contract', 'tenant_id' => 'tenant-contract'],
        ]);
        $receipt = $issuer->issue($envelope, [
            'receipt_id' => 'contract-cutover-v2',
            'domain' => 'programming',
            'flow' => 'programming.dev',
        ]);
        $v2 = $receipt->toArray();
        $v3 = $issuer->issueV3CanaryCompanion($receipt, $envelope);

        $this->assertTrue($issuer->isV3CanarySelected($receipt->receiptId));
        $this->assertIsArray($v3);
        $this->assertSame($v2, $receipt->toArray());
        $this->assertSame(DecisionReceipt::SCHEMA_VERSION, $v2['schema_version']);
    }

    public function test_contract_writer_selection_does_not_drain_valid_v2_only_under_cutover(): void
    {
        // Canonical reader semantics (DecisionReceiptRuntimeGuard + unit tests):
        // cutover is a writer selector. Dual-read keeps V2 as runtime governor;
        // a valid v2-only envelope is still accepted. Live cutover proof is the
        // signed V3 companion (atlas.decide.v3-cutover + authority_signature).
        config(['atlas.ai.decision_receipt_v3_cutover_enabled' => true]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-05T12:00:00Z'));
        $guard = new DecisionReceiptRuntimeGuard;
        $envelope = app(OperationEnvelopeFactory::class)->create(['text' => 'old worker']);
        $issuer = app(DecisionReceiptIssuer::class);
        $v2Receipt = $issuer->issue($envelope, [
            'receipt_id' => 'old-worker-v2',
            'provider_selection' => ['primary' => 'codex_cli', 'model' => 'gpt-5.5', 'fallbacks' => []],
        ]);
        $v2 = $v2Receipt->toArray();
        $v3 = $issuer->issueV3CanaryCompanion($v2Receipt, $envelope);

        $this->assertNull(
            $guard->violationForReceipt([DecisionReceipt::RECEIPT_V2_KEY => $v2], 'codex_cli', 'gpt-5.5')?->errorCode,
            'Writer cutover must not refuse a cryptographically valid v2-only receipt.',
        );
        $this->assertIsArray($v3);
        $this->assertSame('atlas.decide.v3-cutover', $v3['signed_by'] ?? null);
        $this->assertTrue(
            \App\Services\Ai\Kernel\Decision\DecisionReceiptHash::v3LiveAuthoritySignatureMatches($v3),
            'Cutover companion must carry a server-keyed live authority signature.',
        );
        $this->assertTrue((bool) data_get($v3, 'authority.effect.allowed'));
    }

    public function test_contract_r102_executor_modes_are_explicit(): void
    {
        $this->assertSame('dev', EngineeringExecutionSurfaceRegistry::surface('atlas_dev.pipeline_run_executor')['awis_mode']);
        $this->assertSame('forge', EngineeringExecutionSurfaceRegistry::surface('atlas_forge.work_packet_execution_cycle')['awis_mode']);
        $this->assertSame('autonomos', EngineeringExecutionSurfaceRegistry::surface('atlas_autonomos.task_serving')['awis_mode']);
    }
}
