<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionOperatorActionPacketService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
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
}
