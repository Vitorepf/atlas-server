<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Foundry\FoundrySemanticGapFinderService;
use App\Services\Ai\Foundry\Rsi\ImmutableInvariantRegistryService;
use App\Services\Ai\Foundry\Rsi\RsiSelfImprovementProposalGate;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDeepFindingEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Rsi\ComponentValueLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Rsi\SelfTargetSelectorService;
use Tests\TestCase;

/**
 * Governed RSI · Part B · SelfTargetSelector END-TO-END through the REAL path.
 *
 * Proves the full self-improvement loop the design demands:
 *
 *   weakest non-sacred value-per-token component
 *     -> SELF capability_claim (Pilar 2 gap schema) with an outcome_contract
 *     -> screened FIRST by the fail-closed Immutable Invariant Registry guard
 *     -> reaches the FoundrySemanticGapFinder via the deep finding engine's
 *        capability_claims seam (the SAME gated pipeline as product gaps)
 *     -> lands as a capability-drift finding carrying its outcome_contract,
 *        PROPOSAL-ONLY (never auto-applied / auto-canonized).
 *
 * Plus the two safety controls:
 *   - a self gap whose proposal would touch a SACRED component is BLOCKED by the
 *     guard and never emitted into the pipeline;
 *   - with ATLAS_RSI_MODE off the self gap is never routed (honest skip).
 *
 * Deterministic: injected ledger records, no provider, no real command run.
 */
final class SelfTargetSelectorE2ETest extends TestCase
{
    /**
     * Build N proven ComponentValueLedger events for a chosen token-spending
     * component (low value-per-token => weakest legitimate target).
     *
     * @return list<array<string,mixed>>
     */
    private function provenRecords(string $componentId, string $role, int $cycles, int $tokensPerCycle, float $deltaPerCycle): array
    {
        $records = [];
        for ($i = 1; $i <= $cycles; $i++) {
            $records[] = [
                'schema_version' => ComponentValueLedgerService::EVENT_SCHEMA,
                'area_id' => 'agentic_engineering_os',
                'focus' => 'dev_forge',
                'cycle_id' => $componentId.'_c'.$i,
                'merge_hash' => 'merge'.$i,
                'outcome_status' => ComponentValueLedgerService::OUTCOME_PROVEN,
                'proven_value_delta' => $deltaPerCycle,
                'total_tokens_cycle' => $tokensPerCycle,
                'components' => [[
                    'component_id' => $componentId,
                    'role' => $role,
                    'seam_type' => ComponentValueLedgerService::SEAM_LIVE_PROVIDER,
                    'tokens_consumed' => $tokensPerCycle,
                    'measured_value_contribution' => $deltaPerCycle,
                ]],
            ];
        }

        return $records;
    }

    private function selector(): SelfTargetSelectorService
    {
        return new SelfTargetSelectorService(
            new ComponentValueLedgerService(),
            app(RsiSelfImprovementProposalGate::class),
            new ImmutableInvariantRegistryService(),
        );
    }

    public function test_weakest_non_sacred_component_emits_guard_clean_self_claim(): void
    {
        // repair_loop is a token-spending component whose source is NOT in the
        // sacred set => a legitimate weakest target.
        $records = $this->provenRecords('repair_loop', 'iterative_repair_provider_context', 5, 2000, 2.0);

        $record = $this->selector()->select([
            'records' => $records,
            'rsi_mode_enabled' => true,
        ]);

        $this->assertSame(SelfTargetSelectorService::STATUS_SELECTED, $record['status']);
        $this->assertSame('repair_loop', $record['target_component_id']);

        $claim = $record['capability_claim'];
        $this->assertSame(SelfTargetSelectorService::SELF_DRIFT_KIND, $claim['drift_kind']);
        $this->assertSame('loop_component:repair_loop', $claim['capability']);
        $this->assertSame('repair_loop_c5', $claim['anchor_cycle_id']); // most-recent proven cycle
        // Outcome contract demands a value-per-token raise via a REAL command.
        $this->assertSame('rsi_value_per_token_repair_loop', $claim['outcome_contract']['metric_id']);
        $this->assertStringContainsString('atlas:rsi:component-value-per-token', $claim['outcome_contract']['measure_command']);
        $this->assertGreaterThan(0.0, $claim['outcome_contract']['target_delta']);

        // Guard ran FIRST and PASSED -> routed to the human gate, proposal-only.
        $screening = $record['guard_screening'];
        $this->assertSame(RsiSelfImprovementProposalGate::STATUS_ROUTED_TO_HUMAN_GATE, $screening['status']);
        $this->assertTrue($screening['routed_to_human_gate']);
        $this->assertFalse($screening['auto_applied']);
        $this->assertFalse($screening['auto_canonized']);
        $this->assertTrue($record['proposal_only']);
    }

    public function test_self_claim_reaches_gap_finder_and_lands_as_proposal_only_finding(): void
    {
        $records = $this->provenRecords('repair_loop', 'iterative_repair_provider_context', 5, 2000, 2.0);

        $selector = $this->selector();
        $claims = $selector->capabilityClaims([
            'records' => $records,
            'rsi_mode_enabled' => true,
        ]);
        $this->assertCount(1, $claims);

        // Drive the REAL Pilar 2 gap-finder with the SELF claim (same path as a
        // product claim). Build the dossier + verifier seam exactly as the deep
        // finding engine does for an injected cycle anchor.
        $finder = app(FoundrySemanticGapFinderService::class);
        $claim = $claims[0];
        $report = $finder->project([
            'dossier' => [
                'schema_version' => 'atlas.foundry.dossier.v1',
                'status' => 'ready',
                'area_id' => 'agentic_engineering_os',
                'anchors' => [[
                    'anchor_id' => $claim['anchor_id'],
                    'anchor_type' => 'cycle_id',
                    'anchor_source' => 'cycles',
                    'source_path' => 'cycle.cycle_id',
                    'anchor_claim' => $claim['anchor_cycle_id'],
                    'resolved' => true,
                    'integrity_status' => 'ok',
                ]],
            ],
            'capability_claims' => $claims,
            'verifier_input' => ['cycles' => [['cycle_id' => $claim['anchor_cycle_id'], 'area_id' => 'agentic_engineering_os']]],
        ]);

        $this->assertContains($report['status'], [FoundrySemanticGapFinderService::STATUS_READY, FoundrySemanticGapFinderService::STATUS_PARTIAL]);
        $this->assertCount(1, $report['gaps']);
        $gap = $report['gaps'][0];
        $this->assertSame(SelfTargetSelectorService::SELF_DRIFT_KIND, $gap['drift_kind']);
        $this->assertSame('loop_component:repair_loop', $gap['capability']);
        $this->assertSame('confirmed', $gap['anchor_verdict']);
        $this->assertSame('rsi_value_per_token_repair_loop', $gap['outcome_contract']['metric_id']);
        // The gap-finder is proposal-only: never writes canon/code, never a provider.
        $this->assertFalse($report['claim_policy']['canonical_doc_write_allowed']);
        $this->assertFalse($report['claim_policy']['provider_invoked']);
    }

    public function test_full_path_through_deep_finding_engine_capability_claims_seam(): void
    {
        $records = $this->provenRecords('repair_loop', 'iterative_repair_provider_context', 5, 2000, 2.0);

        /** @var AreaFocusDeepFindingEngineService $engine */
        $engine = app(AreaFocusDeepFindingEngineService::class);

        $report = $engine->scan([
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            // Opt-in the RSI self-target source with injected ledger records.
            'rsi_self_target_records' => $records,
            'rsi_mode_enabled' => true,
            // The factory backlog quality gate is a downstream prioritisation
            // filter that can reject ANY finding; skip it here so we assert the
            // WIRING (self gap reaches the backlog through the SAME pipeline as a
            // product gap), not the prioritisation policy.
            'skip_factory_backlog_quality' => true,
        ]);

        $source = $report['source_summary']['semantic_capability_gaps'] ?? [];
        $this->assertTrue($source['enabled']);
        $this->assertSame('self_target_selected', $source['rsi_self_targets']['status']);
        $this->assertSame('repair_loop', $source['rsi_self_targets']['target_component_id']);
        $this->assertSame(RsiSelfImprovementProposalGate::STATUS_ROUTED_TO_HUMAN_GATE, $source['rsi_self_targets']['guard_status']);
        // The gap-finder admitted exactly the self gap (proposal-only emission).
        $this->assertSame(1, $source['rsi_self_targets']['claim_count']);
        $this->assertSame(1, $source['gap_count']);
        $this->assertSame(1, $source['emitted_count']);

        // The SELF gap landed as a real capability-drift finding in the backlog,
        // carrying its outcome_contract, PROPOSAL-ONLY.
        $selfFinding = null;
        foreach ($report['findings'] as $finding) {
            $gap = $finding['capability_gap'] ?? null;
            if (is_array($gap) && ($gap['capability'] ?? '') === 'loop_component:repair_loop') {
                $selfFinding = $finding;
                break;
            }
        }
        $this->assertNotNull($selfFinding, 'self gap must reach the deep finding backlog');
        $this->assertSame(SelfTargetSelectorService::SELF_DRIFT_KIND, $selfFinding['capability_gap']['drift_kind']);
        $this->assertSame('confirmed', $selfFinding['capability_gap']['anchor_verdict']);
        $this->assertSame('rsi_value_per_token_repair_loop', $selfFinding['outcome_contract']['metric_id']);

        // The deep finding engine itself never applies/canonizes anything: the
        // self gap is operator-review-gated, proposal-only.
        $this->assertTrue($report['claim_policy']['operator_review_required']);
        $this->assertFalse($report['claim_policy']['auto_execution_allowed']);
        $this->assertFalse($report['claim_policy']['writes_code']);
        $this->assertFalse($report['claim_policy']['provider_invoked']);
    }

    public function test_sacred_component_self_target_is_never_emitted(): void
    {
        // session_ap786 maps to the sacred Ap786OwnerFlowExecutor path. Even as
        // the only token-spender it must be excluded by the registry-bound ledger.
        $records = $this->provenRecords('session_ap786', 'owner_flow_provider_execution', 5, 2000, 1.0);

        $record = $this->selector()->select([
            'records' => $records,
            'rsi_mode_enabled' => true,
        ]);

        // No eligible non-sacred weakest => honest no-target, no claim.
        $this->assertSame(SelfTargetSelectorService::STATUS_NO_TARGET, $record['status']);
        $this->assertNull($record['capability_claim']);
    }

    public function test_rsi_mode_off_does_not_route_a_self_gap(): void
    {
        $records = $this->provenRecords('repair_loop', 'iterative_repair_provider_context', 5, 2000, 2.0);

        // No rsi_mode_enabled override and config defaults off => proposal gate
        // inert => the self gap is never routed (honest skip), no claim emitted.
        $record = $this->selector()->select(['records' => $records]);

        $this->assertSame(SelfTargetSelectorService::STATUS_SKIPPED, $record['status']);
        $this->assertSame([], $this->selector()->capabilityClaims(['records' => $records]));
    }

    public function test_no_proven_signal_means_no_self_target(): void
    {
        // Token spent but zero proven cycles => no value-per-token signal.
        $records = [[
            'schema_version' => ComponentValueLedgerService::EVENT_SCHEMA,
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'cycle_id' => 'repair_loop_unproven',
            'outcome_status' => ComponentValueLedgerService::OUTCOME_UNPROVEN,
            'proven_value_delta' => null,
            'total_tokens_cycle' => 3000,
            'components' => [[
                'component_id' => 'repair_loop',
                'role' => 'iterative_repair_provider_context',
                'seam_type' => ComponentValueLedgerService::SEAM_LIVE_PROVIDER,
                'tokens_consumed' => 3000,
                'measured_value_contribution' => null,
            ]],
        ]];

        $record = $this->selector()->select(['records' => $records, 'rsi_mode_enabled' => true]);
        $this->assertSame(SelfTargetSelectorService::STATUS_NO_TARGET, $record['status']);
    }
}
