<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAtlasNativeFinalizationGate;
use Tests\TestCase;

class AtlasSelfConstructionAtlasNativeFinalizationGateTest extends TestCase
{
    public function test_ready_when_everything_green(): void
    {
        $verdict = (new AtlasSelfConstructionAtlasNativeFinalizationGate)->finalize($this->readyFacts());

        self::assertSame(AtlasSelfConstructionAtlasNativeFinalizationGate::FINAL_READY, $verdict['final_state']);
        self::assertTrue($verdict['passed']);
        self::assertSame([], $verdict['blockers']);
    }

    public function test_hold_when_refreshable_proof_blocker(): void
    {
        $facts = $this->readyFacts();
        $facts['ledger']['docs_sync_blocker'] = true;

        $verdict = (new AtlasSelfConstructionAtlasNativeFinalizationGate)->finalize($facts);

        self::assertSame(AtlasSelfConstructionAtlasNativeFinalizationGate::FINAL_HOLD, $verdict['final_state']);
        self::assertFalse($verdict['passed']);
        self::assertContains('refresh_docs_sync_blocker', $verdict['next_atlas_actions']);
    }

    public function test_blocked_when_autonomy_contract_violated_by_dependency_gate(): void
    {
        $facts = $this->readyFacts();
        $facts['dependency_facts']['paths'][] = [
            'id' => 'merge', 'kind' => 'ordinary', 'steady_state_required' => ['operator'],
        ];

        $verdict = (new AtlasSelfConstructionAtlasNativeFinalizationGate)->finalize($facts);

        self::assertSame(AtlasSelfConstructionAtlasNativeFinalizationGate::FINAL_BLOCKED, $verdict['final_state']);
        self::assertNotEmpty(array_filter($verdict['blockers'], static fn (string $b): bool => str_starts_with($b, 'dependency:')));
    }

    public function test_blocked_when_unsafe_release(): void
    {
        $facts = $this->readyFacts();
        $facts['ledger']['unsafe_release'] = true;

        $verdict = (new AtlasSelfConstructionAtlasNativeFinalizationGate)->finalize($facts);

        self::assertSame(AtlasSelfConstructionAtlasNativeFinalizationGate::FINAL_BLOCKED, $verdict['final_state']);
        self::assertContains('ledger_blocker:unsafe_release', $verdict['blockers']);
    }

    public function test_blocked_when_runtime_autonomy_level_below_floor(): void
    {
        $facts = $this->readyFacts();
        $facts['autonomy_verdict']['level'] = 'assisted';

        $verdict = (new AtlasSelfConstructionAtlasNativeFinalizationGate)->finalize($facts);

        self::assertSame(AtlasSelfConstructionAtlasNativeFinalizationGate::FINAL_BLOCKED, $verdict['final_state']);
        self::assertContains('autonomy_level_below_floor:assisted', $verdict['blockers']);
    }

    public function test_hold_when_evidence_facts_missing_only_refresh_needed(): void
    {
        // Simulate scenario where evidence verifier fails on a refreshable field, no contract blocker.
        // Evidence failure surfaces under 'evidence:' prefix; without dependency/ledger contract issues,
        // the gate treats it as HOLD (refresh evidence facts).
        $facts = $this->readyFacts();
        unset($facts['evidence_facts']['docs_health']); // refreshable

        $verdict = (new AtlasSelfConstructionAtlasNativeFinalizationGate)->finalize($facts);

        self::assertSame(AtlasSelfConstructionAtlasNativeFinalizationGate::FINAL_HOLD, $verdict['final_state']);
        self::assertContains('refresh_evidence_facts', $verdict['next_atlas_actions']);
    }

    public function test_ready_output_includes_source_coverage_with_no_misses(): void
    {
        $verdict = (new AtlasSelfConstructionAtlasNativeFinalizationGate)->finalize($this->readyFacts());

        self::assertArrayHasKey('source_coverage', $verdict);
        self::assertGreaterThan(0, $verdict['source_coverage']['required_count']);
        self::assertSame($verdict['source_coverage']['required_count'], $verdict['source_coverage']['observed_count']);
        self::assertSame([], $verdict['source_coverage']['missing_sources']);
        self::assertSame([], $verdict['source_coverage']['hold_sources']);
        self::assertSame([], $verdict['source_coverage']['blocked_sources']);
    }

    public function test_hold_when_refreshable_source_is_missing_via_coverage(): void
    {
        $facts = $this->readyFacts();
        unset($facts['evidence_facts']['docs_health']); // refreshable source

        $verdict = (new AtlasSelfConstructionAtlasNativeFinalizationGate)->finalize($facts);

        self::assertSame(AtlasSelfConstructionAtlasNativeFinalizationGate::FINAL_HOLD, $verdict['final_state']);
        self::assertContains('docs_health', $verdict['source_coverage']['missing_sources']);
        self::assertContains('docs_health', $verdict['source_coverage']['hold_sources']);
        self::assertSame([], $verdict['source_coverage']['blocked_sources']);
    }

    public function test_blocked_when_unsafe_source_fails_via_coverage(): void
    {
        $facts = $this->readyFacts();
        // Provide an explicit failing source (unsafe — non-refreshable).
        $facts['evidence_facts']['sources'] = [
            'task_serving_contract_sentinel' => ['status' => 'pass'],
            'code_index_readiness_bridge' => ['status' => 'pass'],
            'multi_project_governance_dossier' => ['status' => 'pass'],
            'native_worker_readiness' => ['status' => 'pass'],
            'verification_court' => ['status' => 'fail'],
            'merge_governor' => ['status' => 'pass'],
            'rollback' => ['status' => 'pass'],
            'receipts' => ['status' => 'pass'],
            'learning_transfer' => ['status' => 'pass'],
            'docs_health' => ['status' => 'pass'],
            'kb_sync' => ['status' => 'pass'],
        ];

        $verdict = (new AtlasSelfConstructionAtlasNativeFinalizationGate)->finalize($facts);

        self::assertSame(AtlasSelfConstructionAtlasNativeFinalizationGate::FINAL_BLOCKED, $verdict['final_state']);
        self::assertContains('verification_court', $verdict['source_coverage']['blocked_sources']);
    }

    public function test_blocked_due_autonomy_violation_still_reports_source_coverage(): void
    {
        $facts = $this->readyFacts();
        $facts['autonomy_verdict']['level'] = 'assisted';

        $verdict = (new AtlasSelfConstructionAtlasNativeFinalizationGate)->finalize($facts);

        self::assertSame(AtlasSelfConstructionAtlasNativeFinalizationGate::FINAL_BLOCKED, $verdict['final_state']);
        self::assertContains('autonomy_level_below_floor:assisted', $verdict['blockers']);
        self::assertArrayHasKey('source_coverage', $verdict);
        self::assertSame([], $verdict['source_coverage']['blocked_sources']);
    }

    public function test_previous_finalization_blockers_are_preserved_alongside_source_coverage(): void
    {
        $facts = $this->readyFacts();
        $facts['ledger']['unsafe_release'] = true;
        unset($facts['evidence_facts']['docs_health']);

        $verdict = (new AtlasSelfConstructionAtlasNativeFinalizationGate)->finalize($facts);

        self::assertSame(AtlasSelfConstructionAtlasNativeFinalizationGate::FINAL_BLOCKED, $verdict['final_state']);
        self::assertContains('ledger_blocker:unsafe_release', $verdict['blockers']);
        self::assertContains('docs_health', $verdict['source_coverage']['hold_sources']);
    }

    /**
     * @return array<string,mixed>
     */
    private function readyFacts(): array
    {
        return [
            'evidence_facts' => [
                'final_runtime_owner' => 'atlas_native',
                'steady_state_runtime_owner' => 'atlas_server',
                'autonomy_dependencies' => [
                    'depends_on_operator' => false,
                    'depends_on_claude_code' => false,
                    'depends_on_codex' => false,
                    'depends_on_external_provider_network' => false,
                ],
                'serving_queue_health' => true,
                'native_worker_readiness' => true,
                'verification_court_readiness' => true,
                'merge_governor_readiness' => true,
                'rollback_readiness' => true,
                'learning_transfer_readiness' => true,
                'docs_health' => true,
                'kb_sync' => true,
                'code_index_readiness' => true,
                'multi_project_lane_readiness' => true,
            ],
            'dependency_facts' => [
                'final_runtime_owner' => 'atlas_native',
                'paths' => [
                    ['id' => 'p1', 'kind' => 'ordinary', 'steady_state_required' => ['atlas_native']],
                ],
            ],
            'autonomy_verdict' => ['level' => 'atlas_native_bounded'],
            'ledger' => [
                'queue_blocker' => false,
                'rollback_blocker' => false,
                'code_index_blocker' => false,
                'docs_sync_blocker' => false,
                'release_blocker' => false,
                'learning_blocker' => false,
                'unsafe_release' => false,
            ],
        ];
    }
}
