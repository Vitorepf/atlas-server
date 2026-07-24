<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\EngineeringKernel\Coverage\EngineeringExecutionSurfaceRegistry;
use App\Services\Ai\Kernel\Decision\DecisionReceipt;
use App\Services\Ai\Kernel\Decision\DecisionReceiptHash;
use App\Services\Ai\Kernel\Decision\DecisionReceiptRuntimeGuard;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * P2b-CUTOVER: mixed-worker block-before-effect + AWIS R102 mode identity.
 */
final class AaeosMixedVersionWorkerCompatibilityTest extends TestCase
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

    public function test_cutover_blocks_v2_only_and_accepts_aligned_dual_or_valid_v3(): void
    {
        config(['atlas.ai.decision_receipt_v3_cutover_enabled' => true]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-24T12:00:00Z'));
        $guard = new DecisionReceiptRuntimeGuard;

        $v2 = [
            'receipt_id' => 'cutover-v2',
            'envelope_id' => 'cutover-env',
            'schema_version' => DecisionReceipt::SCHEMA_VERSION,
            'expires_at' => '2026-07-24T12:05:00Z',
            'dry_run' => false,
            'domain' => 'programming',
            'flow' => 'programming.dev',
            'provider_selection' => ['primary' => 'codex_cli', 'model' => 'gpt-5.5', 'fallbacks' => []],
        ];
        $this->assertSame(
            'decision_receipt_cutover_v2_only_refused',
            $guard->violationForReceipt([DecisionReceipt::RECEIPT_V2_KEY => $v2])?->errorCode,
        );

        $v3 = $this->alignedV3($v2);
        $this->assertNull($guard->violationForReceipt([
            DecisionReceipt::RECEIPT_V2_KEY => $v2,
            DecisionReceipt::RECEIPT_V3_KEY => $v3,
        ]));
        $this->assertNull($guard->violationForReceipt([
            DecisionReceipt::RECEIPT_V3_KEY => $v3,
        ]));
    }

    public function test_r102_autonomos_surfaces_do_not_present_as_dev(): void
    {
        foreach ([
            'atlas_autonomos.task_serving',
            'atlas_autonomos.native_worker',
            'atlas_autonomos.commit_governance',
        ] as $id) {
            $surface = EngineeringExecutionSurfaceRegistry::surface($id);
            $this->assertNotNull($surface);
            $this->assertSame('autonomos', $surface['awis_mode'], $id.' must use autonomos mode');
            $this->assertNotSame('dev', $surface['awis_mode']);
        }

        $this->assertSame('dev', EngineeringExecutionSurfaceRegistry::surface('atlas_dev.pipeline_run_executor')['awis_mode'] ?? null);
        $this->assertSame('forge', EngineeringExecutionSurfaceRegistry::surface('atlas_forge.work_packet_execution_cycle')['awis_mode'] ?? null);
    }

    public function test_r102_unknown_mode_fails_closed_as_mutative_blocker(): void
    {
        $gate = app(AtlasWorkspaceIntelligenceExecutionGateService::class);
        $result = $gate->gate(workspace: base_path(), mode: 'not-a-real-mode', task: 'cutover-r102');

        $this->assertFalse((bool) ($result['allowed'] ?? true));
        $this->assertContains('unknown_execution_mode', (array) ($result['blockers'] ?? []));
        $this->assertSame('mutative', $result['execution_class'] ?? null);
    }

    /** @param array<string,mixed> $v2 @return array<string,mixed> */
    private function alignedV3(array $v2): array
    {
        $receipt = [
            'receipt_id' => $v2['receipt_id'],
            'envelope_id' => $v2['envelope_id'],
            'schema_version' => DecisionReceipt::SCHEMA_VERSION_V3,
            'issued_at' => '2026-07-24T11:59:00Z',
            'expires_at' => $v2['expires_at'],
            'dry_run' => false,
            'signed_by' => 'atlas.decide.v3',
            'domain' => $v2['domain'],
            'flow' => $v2['flow'],
            'risk' => 'high',
            'provider_selection' => $v2['provider_selection'],
            'budgets' => ['provider_calls' => 1],
            'required_gates' => ['authority'],
            'required_evidence' => ['receipt'],
            'repair_policy' => ['enabled' => false, 'max_attempts' => 0],
            'inputs_hash' => str_repeat('b', 64),
            'parent_receipt_id' => null,
            'chain_hash' => str_repeat('c', 64),
            'authority' => [
                'authority_id' => 'mandate-cutover',
                'issuer_key_id' => 'key-cutover',
                'lifecycle' => ['status' => 'active', 'revision' => 1],
                'audience' => ['tenant_id' => 'tenant-cutover', 'principal_id' => 'principal-cutover'],
                'scope' => ['workspace_id' => 'workspace-cutover', 'modes' => ['dev'], 'capability' => 'programming.dev'],
                'effect' => ['class' => 'provider_tool_sandbox_mutation', 'allowed' => true],
                'budget' => ['budget_id' => 'budget-cutover', 'max_effects' => 1],
                'nonce' => 'nonce-cutover',
                'revocation_head' => str_repeat('a', 64),
                'separation_of_duties' => [
                    'issuer_principal_id' => 'issuer-cutover',
                    'executor_principal_id' => 'executor-cutover',
                ],
            ],
        ];
        $receipt['receipt_hash'] = DecisionReceiptHash::v3FullEnvelopeHash($receipt);

        return $receipt;
    }
}
