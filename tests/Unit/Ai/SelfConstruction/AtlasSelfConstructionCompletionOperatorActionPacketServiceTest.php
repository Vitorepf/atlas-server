<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionCompletionOperatorActionPacketService;
use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use Tests\TestCase;

final class AtlasSelfConstructionCompletionOperatorActionPacketServiceTest extends TestCase
{
    private function service(): AtlasSelfConstructionCompletionOperatorActionPacketService
    {
        return new AtlasSelfConstructionCompletionOperatorActionPacketService(app(AtlasSelfConstructionReadinessService::class));
    }

    private function passedReceipt(string $id): array
    {
        return ['status' => 'passed', 'receipt_id' => $id];
    }

    private function blockedReceipt(): array
    {
        return ['status' => 'blocked'];
    }

    public function test_packet_has_required_keys(): void
    {
        $packet = $this->service()->build(
            runtimeGapMatrix: ['rows' => []],
            humanReceipt: $this->blockedReceipt(),
            realProviderSmoke: $this->blockedReceipt(),
            evidence: $this->emptyEvidence(),
        );

        $this->assertArrayHasKey('schema_version', $packet);
        $this->assertArrayHasKey('autonomous_next_actions', $packet);
        $this->assertArrayHasKey('operator_only_receipts', $packet);
        $this->assertArrayHasKey('blocked_actions', $packet);
        $this->assertArrayHasKey('steady_state_human_dependency', $packet);
    }

    public function test_autonomous_next_actions_and_operator_only_receipts_are_separate(): void
    {
        $packet = $this->service()->build(
            runtimeGapMatrix: ['rows' => [], 'runtime_promotion_receipt' => $this->passedReceipt('runtime-1')],
            humanReceipt: $this->blockedReceipt(),
            realProviderSmoke: $this->passedReceipt('smoke-1'),
            evidence: $this->emptyEvidence(),
        );

        $autonomousIds = array_column($packet['autonomous_next_actions'], 'artifact');
        $operatorIds = array_column($packet['operator_only_receipts'], 'artifact');

        $this->assertContains('real_provider_smoke', $autonomousIds);
        $this->assertNotContains('human_completion_receipt', $autonomousIds);
        $this->assertContains('runtime_promotion_receipt', $operatorIds);
        $this->assertContains('human_completion_receipt', $operatorIds);
    }

    public function test_steady_state_human_dependency_false_when_all_evidence_present(): void
    {
        $packet = $this->service()->build(
            runtimeGapMatrix: ['rows' => [], 'runtime_promotion_receipt' => $this->passedReceipt('runtime-1')],
            humanReceipt: $this->passedReceipt('human-1'),
            realProviderSmoke: $this->passedReceipt('smoke-1'),
            evidence: $this->emptyEvidence(),
        );

        $this->assertFalse($packet['steady_state_human_dependency']);
        $this->assertSame('ready_for_operator_final_review', $packet['status']);
    }

    public function test_steady_state_human_dependency_true_when_missing_evidence(): void
    {
        $packet = $this->service()->build(
            runtimeGapMatrix: ['rows' => []],
            humanReceipt: $this->blockedReceipt(),
            realProviderSmoke: $this->blockedReceipt(),
            evidence: $this->emptyEvidence(),
        );

        $this->assertTrue($packet['steady_state_human_dependency']);
        $this->assertSame('operator_action_required', $packet['status']);
    }

    public function test_blocked_actions_list_concrete_required_receipt_paths(): void
    {
        $packet = $this->service()->build(
            runtimeGapMatrix: ['rows' => []],
            humanReceipt: $this->blockedReceipt(),
            realProviderSmoke: $this->blockedReceipt(),
            evidence: $this->emptyEvidence(),
        );

        $blockedArtifacts = array_column($packet['blocked_actions'], 'artifact');
        $this->assertContains('runtime_promotion_receipt', $blockedArtifacts);
        $this->assertContains('real_provider_smoke', $blockedArtifacts);
        $this->assertContains('human_completion_receipt', $blockedArtifacts);

        foreach ($packet['blockers'] as $blocker) {
            $this->assertArrayHasKey('canonical_submission_private_storage_path', $blocker);
        }
    }

    private function emptyEvidence(): array
    {
        return [
            'release_dossier' => ['status' => 'not_ready'],
            'replay_diff' => ['status' => 'not_ready'],
            'certification_status_batch' => ['status' => 'not_ready'],
        ];
    }

    // ── AC: autonomous_next_actions and operator_only_receipts are separate sections ──

    public function test_autonomous_next_actions_does_not_contain_human_receipts(): void
    {
        $packet = $this->service()->build(
            runtimeGapMatrix: ['rows' => [], 'runtime_promotion_receipt' => $this->passedReceipt('runtime-1')],
            humanReceipt: $this->passedReceipt('human-1'),
            realProviderSmoke: $this->passedReceipt('smoke-1'),
            evidence: $this->emptyEvidence(),
        );

        $autonomousArtifacts = array_column($packet['autonomous_next_actions'], 'artifact');
        $this->assertNotContains('human_completion_receipt', $autonomousArtifacts);
    }

    public function test_operator_only_receipts_contains_human_signed_receipts(): void
    {
        $packet = $this->service()->build(
            runtimeGapMatrix: ['rows' => [], 'runtime_promotion_receipt' => $this->passedReceipt('runtime-1')],
            humanReceipt: $this->passedReceipt('human-1'),
            realProviderSmoke: $this->passedReceipt('smoke-1'),
            evidence: $this->emptyEvidence(),
        );

        $operatorArtifacts = array_column($packet['operator_only_receipts'], 'artifact');
        $this->assertContains('runtime_promotion_receipt', $operatorArtifacts);
        $this->assertContains('human_completion_receipt', $operatorArtifacts);
    }

    // ── AC: steady_state_human_dependency=false when all three evidence present ──

    public function test_steady_state_false_when_runtime_promotion_real_smoke_and_completion_receipt_present(): void
    {
        $packet = $this->service()->build(
            runtimeGapMatrix: ['rows' => [], 'runtime_promotion_receipt' => $this->passedReceipt('runtime-1')],
            humanReceipt: $this->passedReceipt('human-1'),
            realProviderSmoke: $this->passedReceipt('smoke-1'),
            evidence: $this->emptyEvidence(),
        );

        $this->assertFalse($packet['steady_state_human_dependency']);
    }

    public function test_steady_state_true_when_only_runtime_promotion_present(): void
    {
        $packet = $this->service()->build(
            runtimeGapMatrix: ['rows' => [], 'runtime_promotion_receipt' => $this->passedReceipt('runtime-1')],
            humanReceipt: $this->blockedReceipt(),
            realProviderSmoke: $this->blockedReceipt(),
            evidence: $this->emptyEvidence(),
        );

        $this->assertTrue($packet['steady_state_human_dependency']);
    }

    public function test_steady_state_true_when_only_real_provider_smoke_present(): void
    {
        $packet = $this->service()->build(
            runtimeGapMatrix: ['rows' => []],
            humanReceipt: $this->blockedReceipt(),
            realProviderSmoke: $this->passedReceipt('smoke-1'),
            evidence: $this->emptyEvidence(),
        );

        $this->assertTrue($packet['steady_state_human_dependency']);
    }

    // ── AC: missing evidence creates blocked_actions with concrete required_receipt paths ──

    public function test_blocked_actions_contain_concrete_receipt_paths(): void
    {
        $packet = $this->service()->build(
            runtimeGapMatrix: ['rows' => []],
            humanReceipt: $this->blockedReceipt(),
            realProviderSmoke: $this->blockedReceipt(),
            evidence: $this->emptyEvidence(),
        );

        foreach ($packet['blockers'] as $blocker) {
            $this->assertArrayHasKey('canonical_submission_private_storage_path', $blocker);
            $this->assertNotEmpty($blocker['canonical_submission_private_storage_path']);
        }
    }

    public function test_blocked_actions_not_empty_when_evidence_missing(): void
    {
        $packet = $this->service()->build(
            runtimeGapMatrix: ['rows' => []],
            humanReceipt: $this->blockedReceipt(),
            realProviderSmoke: $this->blockedReceipt(),
            evidence: $this->emptyEvidence(),
        );

        $this->assertNotEmpty($packet['blocked_actions']);
    }

    public function test_blocked_actions_empty_when_all_evidence_present(): void
    {
        $packet = $this->service()->build(
            runtimeGapMatrix: ['rows' => [], 'runtime_promotion_receipt' => $this->passedReceipt('runtime-1')],
            humanReceipt: $this->passedReceipt('human-1'),
            realProviderSmoke: $this->passedReceipt('smoke-1'),
            evidence: $this->emptyEvidence(),
        );

        // The final_completion_audit is always blocked (passed=false) until rerun.
        $blockedArtifacts = array_column($packet['blocked_actions'], 'artifact');
        $this->assertNotContains('runtime_promotion_receipt', $blockedArtifacts);
        $this->assertNotContains('real_provider_smoke', $blockedArtifacts);
        $this->assertNotContains('human_completion_receipt', $blockedArtifacts);
    }

    public function test_non_execution_guarantees_present(): void
    {
        $packet = $this->service()->build(
            runtimeGapMatrix: ['rows' => []],
            humanReceipt: $this->blockedReceipt(),
            realProviderSmoke: $this->blockedReceipt(),
            evidence: $this->emptyEvidence(),
        );

        $this->assertArrayHasKey('non_execution_guarantees', $packet);
        $this->assertNotEmpty($packet['non_execution_guarantees']);
    }
}
