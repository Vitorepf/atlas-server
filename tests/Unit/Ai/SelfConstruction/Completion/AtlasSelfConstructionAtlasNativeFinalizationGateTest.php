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
        unset($facts['evidence_facts']['sources']['docs_health']); // refreshable

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
        unset($facts['evidence_facts']['sources']['docs_health']); // refreshable source

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
        unset($facts['evidence_facts']['sources']['docs_health']);

        $verdict = (new AtlasSelfConstructionAtlasNativeFinalizationGate)->finalize($facts);

        self::assertSame(AtlasSelfConstructionAtlasNativeFinalizationGate::FINAL_BLOCKED, $verdict['final_state']);
        self::assertContains('ledger_blocker:unsafe_release', $verdict['blockers']);
        self::assertContains('docs_health', $verdict['source_coverage']['hold_sources']);
    }

    // --- soak proof + human dependency regression ---

    public function test_missing_soak_proof_yields_hold(): void
    {
        $facts = $this->readyFacts();
        unset($facts['soak_proof']);
        $verdict = (new AtlasSelfConstructionAtlasNativeFinalizationGate)->finalize($facts);
        self::assertSame(AtlasSelfConstructionAtlasNativeFinalizationGate::FINAL_HOLD, $verdict['final_state']);
        self::assertContains('extend_runtime_soak_to_minimum_hours', $verdict['next_atlas_actions']);
        self::assertContains('record_unattended_recovery_events', $verdict['next_atlas_actions']);
        self::assertContains('complete_queue_drain_cycles', $verdict['next_atlas_actions']);
    }

    public function test_insufficient_soak_hours_yields_hold(): void
    {
        $facts = $this->readyFacts();
        $facts['soak_proof']['runtime_soak_hours'] = 10; // below MIN_SOAK_HOURS (24)
        $verdict = (new AtlasSelfConstructionAtlasNativeFinalizationGate)->finalize($facts);
        self::assertSame(AtlasSelfConstructionAtlasNativeFinalizationGate::FINAL_HOLD, $verdict['final_state']);
        self::assertContains('extend_runtime_soak_to_minimum_hours', $verdict['next_atlas_actions']);
    }

    public function test_missing_unattended_recovery_events_yields_hold(): void
    {
        $facts = $this->readyFacts();
        $facts['soak_proof']['unattended_recovery_events'] = 0;
        $verdict = (new AtlasSelfConstructionAtlasNativeFinalizationGate)->finalize($facts);
        self::assertSame(AtlasSelfConstructionAtlasNativeFinalizationGate::FINAL_HOLD, $verdict['final_state']);
        self::assertContains('record_unattended_recovery_events', $verdict['next_atlas_actions']);
    }

    public function test_missing_queue_drain_cycles_yields_hold(): void
    {
        $facts = $this->readyFacts();
        $facts['soak_proof']['queue_drain_cycles'] = 0;
        $verdict = (new AtlasSelfConstructionAtlasNativeFinalizationGate)->finalize($facts);
        self::assertSame(AtlasSelfConstructionAtlasNativeFinalizationGate::FINAL_HOLD, $verdict['final_state']);
        self::assertContains('complete_queue_drain_cycles', $verdict['next_atlas_actions']);
    }

    public function test_human_dependency_regression_fail_yields_blocked(): void
    {
        $facts = $this->readyFacts();
        $facts['human_dependency_regression'] = ['status' => 'fail'];
        $verdict = (new AtlasSelfConstructionAtlasNativeFinalizationGate)->finalize($facts);
        self::assertSame(AtlasSelfConstructionAtlasNativeFinalizationGate::FINAL_BLOCKED, $verdict['final_state']);
        self::assertContains('human_dependency_regression_not_passed:fail', $verdict['blockers']);
    }

    public function test_missing_human_dependency_regression_yields_blocked(): void
    {
        $facts = $this->readyFacts();
        unset($facts['human_dependency_regression']);
        $verdict = (new AtlasSelfConstructionAtlasNativeFinalizationGate)->finalize($facts);
        self::assertSame(AtlasSelfConstructionAtlasNativeFinalizationGate::FINAL_BLOCKED, $verdict['final_state']);
        self::assertContains('human_dependency_regression_not_passed:missing', $verdict['blockers']);
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
                'sources' => [
                    'task_serving_contract_sentinel' => ['status' => 'pass'],
                    'code_index_readiness_bridge' => ['status' => 'pass'],
                    'multi_project_governance_dossier' => ['status' => 'pass'],
                    'native_worker_readiness' => ['status' => 'pass'],
                    'verification_court' => ['status' => 'pass'],
                    'merge_governor' => ['status' => 'pass'],
                    'rollback' => ['status' => 'pass'],
                    'receipts' => ['status' => 'pass'],
                    'learning_transfer' => ['status' => 'pass'],
                    'docs_health' => ['status' => 'pass'],
                    'knowledge_sync' => ['status' => 'pass'],
                    'task_graph_coverage_dossier' => ['status' => 'pass'],
                    'task_graph_autonomous_replenisher' => ['status' => 'pass'],
                    'scope_expansion_governor' => ['status' => 'pass'],
                    'native_worker_runtime' => ['status' => 'pass'],
                    'runtime_daemon' => ['status' => 'pass'],
                    'unattended_runtime_supervisor' => ['status' => 'pass'],
                    'runtime_soak' => ['status' => 'pass'],
                    'multi_project_runtime_instances' => ['status' => 'pass'],
                ],
            ],
            'dependency_facts' => [
                'final_runtime_owner' => 'atlas_native',
                'paths' => [
                    ['id' => 'p1', 'kind' => 'ordinary', 'steady_state_required' => ['atlas_native']],
                ],
            ],
            'autonomy_verdict' => ['level' => 'atlas_native_bounded'],
            'soak_proof' => [
                'runtime_soak_hours' => 24,
                'unattended_recovery_events' => 3,
                'queue_drain_cycles' => 5,
            ],
            'human_dependency_regression' => ['status' => 'pass'],
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
