<?php

declare(strict_types=1);

namespace Tests\Feature\Foundry;

use App\Services\Ai\Foundry\FoundrySchemas;
use App\Services\Ai\Foundry\FoundrySemanticGapFinderService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AgenticEngineeringOsFindingEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDeepFindingEngineService;
use Tests\TestCase;

/**
 * FASE 4 / Pilar 2 · Semantic Gap-Finder END-TO-END pipeline proof.
 *
 * Drives the REAL live finding path — AreaFocusDeepFindingEngineService::scan()
 * (the same method AutonomousEvolutionSessionService calls to feed the Fase 1
 * decomposer) — with an injected documented capability claim for a capability
 * that has NO runtime implementation. It proves:
 *   - the real FoundrySemanticGapFinderService (with the real evidence verifier,
 *     NO provider) emits a concrete evidence-anchored gap;
 *   - that gap becomes a deep finding carrying an outcome_contract (metric_id /
 *     baseline / target_delta via an existing measure command) and an evidence
 *     anchor (doc path:line + the missing runtime ref);
 *   - a "keep doc in sync" claim NEVER becomes a gap/finding.
 *
 * Deterministic: synthetic dossier + cycle evidence seam, zero filesystem and
 * zero provider spend.
 */
final class FoundrySemanticGapFinderPipelineTest extends TestCase
{
    private function deepEngine(): AreaFocusDeepFindingEngineService
    {
        return app(AreaFocusDeepFindingEngineService::class);
    }

    /**
     * Quiet every other deep check + supply an empty AP-717 base report so ONLY
     * the semantic capability-gap finding source produces output.
     *
     * @return array<string,mixed>
     */
    private function quietBase(): array
    {
        return [
            'area_id' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'base_report' => [
                'schema_version' => AgenticEngineeringOsFindingEngineService::REPORT_SCHEMA,
                'status' => 'ready',
                'area_id' => 'agentic_engineering_os',
                'finding_count' => 0,
                'findings' => [],
            ],
            'focus_owner_docs' => [], // all present (no missing-doc findings)
            'wiring_chain' => [
                'release_queue' => true,
                'consumption_gate' => true,
                'owner_runtime_execution' => true,
                'owner_sandbox_run' => true,
                'result_bridge' => true,
            ],
            'skip_factory_backlog_quality' => true,
            'skip_atlas_dev_factory_runtime_bottlenecks' => true,
            'skip_factory_runtime_coverage' => true,
            'skip_strategic_multiplier_backlog' => true,
        ];
    }

    /**
     * A documented capability claim for a service that does NOT exist at runtime,
     * carrying the doc anchor (path:line), the missing runtime ref, a cycle
     * evidence anchor proving the divergence, and a measurable outcome_contract
     * built from a REAL existing artisan measure command.
     *
     * @return array<string,mixed>
     */
    private function missingRuntimeClaim(): array
    {
        return [
            'capability' => 'fleet_parallel_integration',
            'documented_state' => 'available',
            'runtime_state' => 'no_runtime_evidence',
            'drift_kind' => 'claimed_capability_no_runtime_evidence',
            'anchor_id' => 'fanchor_fleet_cycle',
            'anchor_cycle_id' => 'cyc_fleet_attempt_1',
            'source_doc' => 'atlas-axis-n-fleet-live-pilar2-foundry',
            'source_doc_path' => 'docs/engineering-knowledge-base/atlas-axis-n-fleet-live-pilar2-foundry.md',
            'source_doc_line' => 42,
            'missing_runtime_ref' => 'app/Services/Ai/Foundry/Frontier/FleetParallelIntegrationService.php',
            'outcome_contract' => [
                'metric_id' => 'service_maturity',
                'baseline' => 0.0,
                'target_delta' => 1.0,
                'measure_command' => 'php artisan atlas:aaeos:maturity --json',
                'metric_json_path' => 'metric',
            ],
        ];
    }

    public function test_documented_capability_with_no_runtime_becomes_evidence_anchored_gap_with_outcome_contract(): void
    {
        $report = $this->deepEngine()->scan($this->quietBase() + [
            'capability_claims' => [$this->missingRuntimeClaim()],
        ]);

        self::assertSame('ready', $report['status']);

        $gapFindings = array_values(array_filter(
            $report['findings'],
            static fn (array $f): bool => ($f['origin'] ?? '') === 'deep_semantic_capability_gap',
        ));
        self::assertCount(1, $gapFindings, 'exactly one semantic capability-gap finding expected');

        $finding = $gapFindings[0];
        self::assertSame('capability_drift_claimed_capability_no_runtime_evidence', $finding['origin_type']);
        self::assertStringContainsString('fleet_parallel_integration', $finding['title']);

        // Carries the outcome_contract via a REAL existing metric.
        self::assertArrayHasKey('outcome_contract', $finding);
        self::assertSame('service_maturity', $finding['outcome_contract']['metric_id']);
        self::assertSame(1.0, $finding['outcome_contract']['target_delta']);
        self::assertSame('php artisan atlas:aaeos:maturity --json', $finding['outcome_contract']['measure_command']);

        // Carries an evidence anchor: doc path:line AND the missing runtime ref.
        self::assertContains('doc_anchor:docs/engineering-knowledge-base/atlas-axis-n-fleet-live-pilar2-foundry.md:42', $finding['evidence_refs']);
        self::assertContains('missing_runtime_ref:app/Services/Ai/Foundry/Frontier/FleetParallelIntegrationService.php', $finding['evidence_refs']);
        self::assertContains('anchor:fanchor_fleet_cycle:confirmed', $finding['evidence_refs']);

        // The gap rode in with a confirmed evidence anchor.
        self::assertSame('confirmed', $finding['capability_gap']['anchor_verdict']);

        // The source metadata reports the gap-finder actually ran and confirmed.
        self::assertSame(1, $report['source_summary']['semantic_capability_gaps']['gap_count']);
        self::assertSame('ready', $report['source_summary']['semantic_capability_gaps']['report_status']);
    }

    public function test_keep_doc_in_sync_claim_never_becomes_a_gap_finding(): void
    {
        $report = $this->deepEngine()->scan($this->quietBase() + [
            'capability_claims' => [
                [
                    'capability' => 'documentation_freshness',
                    'documented_state' => 'available',
                    'runtime_state' => 'available',
                    'drift_kind' => 'keep doc in sync', // banned boilerplate
                    'anchor_id' => 'fanchor_fleet_cycle',
                    'anchor_cycle_id' => 'cyc_fleet_attempt_1',
                    'source_doc' => 'atlas-axis-n-fleet-live-pilar2-foundry',
                    'outcome_contract' => [
                        'metric_id' => 'service_maturity',
                        'baseline' => 0.0,
                        'target_delta' => 1.0,
                        'measure_command' => 'php artisan atlas:aaeos:maturity --json',
                    ],
                ],
            ],
        ]);

        $gapFindings = array_values(array_filter(
            $report['findings'],
            static fn (array $f): bool => ($f['origin'] ?? '') === 'deep_semantic_capability_gap',
        ));
        self::assertCount(0, $gapFindings, 'a "keep doc in sync" claim must never become a gap finding');
        self::assertSame(0, $report['source_summary']['semantic_capability_gaps']['gap_count']);
        self::assertSame(1, $report['source_summary']['semantic_capability_gaps']['drop_count']);

        $blob = strtolower((string) json_encode($report['findings']));
        self::assertStringNotContainsString('keep doc in sync', $blob);
    }

    public function test_off_by_default_is_byte_identical_no_gap_source(): void
    {
        // No capability_claims, flag not set => the gap source is inert.
        $report = $this->deepEngine()->scan($this->quietBase());

        self::assertFalse($report['source_summary']['semantic_capability_gaps']['enabled']);
        self::assertSame(0, $report['source_summary']['semantic_capability_gaps']['emitted_count']);
    }

    public function test_gap_schema_keys_present(): void
    {
        $verifier = app(\App\Services\Ai\Foundry\FoundryEvidenceVerifierService::class);
        $finder = new FoundrySemanticGapFinderService($verifier);

        $report = $finder->project([
            'dossier' => [
                'schema_version' => 'atlas.foundry.dossier.v1',
                'status' => 'ready',
                'area_id' => 'agentic_engineering_os',
                'anchors' => [[
                    'anchor_id' => 'fanchor_fleet_cycle',
                    'anchor_type' => 'cycle_id',
                    'anchor_source' => 'cycles',
                    'source_path' => 'cycle.cycle_id',
                    'anchor_claim' => 'cyc_fleet_attempt_1',
                    'resolved' => true,
                    'integrity_status' => 'ok',
                    'anchor_hash' => 'sha256:x',
                ]],
            ],
            'capability_claims' => [$this->missingRuntimeClaim()],
            'verifier_input' => ['cycles' => [['cycle_id' => 'cyc_fleet_attempt_1', 'area_id' => 'agentic_engineering_os']]],
        ]);

        self::assertSame(1, $report['gap_count']);
        $shape = FoundrySchemas::validateShape(FoundrySchemas::CAPABILITY_GAP, $report['gaps'][0]);
        self::assertSame([], $shape['missing'], 'capability_gap.v1 required keys must all be present');
        $reportShape = FoundrySchemas::validateShape(FoundrySchemas::CAPABILITY_GAP_REPORT, $report);
        self::assertSame([], $reportShape['missing']);
    }
}
