<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Foundry\Frontier\Armor;

use App\Services\Ai\AutonomousEvolution\AtlasAutonomousEvolutionLoopService;
use App\Services\Ai\Foundry\Frontier\Armor\FrontierDriftMapperGate;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionSubsystemBuilderService;
use PHPUnit\Framework\TestCase;

/**
 * P2-ARMOR-I9-MEASURED — tightens the I9 measured-target predicate (Finding 23).
 *
 * Defects closed:
 *   (a) a supplied measured_signal_ref MUST resolve to a concrete-measure entry
 *       id/key, not merely to a matching subsystem/schema target;
 *   (b) a pure 0 baseline as the sole numeric signal (and non-finite numbers)
 *       no longer counts as measured — only a real {operator,baseline,threshold}
 *       assertion proves intent in that case.
 *
 * I9 stays drift-to-canonical; only the unmeasured-rejection is tightened. The
 * assertion path and a real concrete-measure entry still pass (no weakening).
 */
final class FrontierDriftMapperMeasuredTest extends TestCase
{
    private function gate(): FrontierDriftMapperGate
    {
        return new FrontierDriftMapperGate;
    }

    /**
     * @param  array<string,mixed>  $mapping
     * @return array<string,mixed>
     */
    private function proposalWithMapping(array $mapping): array
    {
        return [
            'proposal_id' => 'prop-measured',
            'horizon' => 'frontier',
            'title' => 'Map drift to a measured property',
            'thesis' => 'thesis',
            'evidence_refs' => [],
            'why_it_multiplies' => 'compounds',
            'success_metric' => 'op:>= baseline:1 threshold:2',
            'rollback' => 'revert',
            'risk_level' => 'medium',
            'dependencies' => [],
            'proposed_packets' => [
                [
                    'packet_id' => 'pk-1',
                    'label' => 'packet one',
                    'objective' => 'obj',
                    'delivery' => 'del',
                    'acceptance_criteria' => ['a'],
                    'tests_required' => ['t'],
                    'owner_candidate' => 'self_construction',
                    'kind' => 'service',
                    'canonical_property_mapping' => $mapping,
                ],
            ],
            'provider_tier_required' => 'premium',
            'anti_pattern_self_check' => 'ok',
        ];
    }

    // ---- Acceptance 1: measured_signal_ref matching no concrete-measure entry is rejected.

    public function test_ref_matching_no_concrete_measure_entry_is_unmeasured(): void
    {
        // The target tuple IS present as a concrete-measure entry (positional
        // ref cycle_receipts[0]), but the packet cites a ref that resolves to
        // nothing concrete — a fabricated citation.
        $dossier = [
            'area_id' => 'agentic_engineering_os',
            'cycle_receipts' => [
                [
                    'subsystem' => 'pipeline_not_proven',
                    'schema' => AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
                    'baseline' => 0.42,
                ],
            ],
            'evidence_packs' => [],
        ];

        $verdict = $this->gate()->adjudicate(
            $this->proposalWithMapping([
                'area_id' => 'agentic_engineering_os',
                'subsystem' => 'pipeline_not_proven',
                'schema' => AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
                'measured_signal_ref' => 'cycle_receipts[7]', // no such concrete entry
            ]),
            $dossier,
        );

        $this->assertFalse($verdict['passed']);
        $this->assertSame(FrontierDriftMapperGate::REASON_UNMEASURED, $verdict['reason']);
    }

    // ---- Acceptance 2: only signal is a dossier-level 0 baseline with no assertion -> rejected.

    public function test_pure_zero_baseline_with_no_assertion_is_unmeasured(): void
    {
        $dossier = [
            'area_id' => 'agentic_engineering_os',
            'cycle_receipts' => [
                [
                    'subsystem' => 'pipeline_not_proven',
                    'schema' => AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
                    'baseline' => 0, // incidental zero, no assertion
                ],
            ],
            'evidence_packs' => [],
        ];

        $verdict = $this->gate()->adjudicate(
            $this->proposalWithMapping([
                'area_id' => 'agentic_engineering_os',
                'subsystem' => 'pipeline_not_proven',
                'schema' => AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
            ]),
            $dossier,
        );

        $this->assertFalse($verdict['passed']);
        $this->assertSame(FrontierDriftMapperGate::REASON_UNMEASURED, $verdict['reason']);
        $this->assertSame(0, $verdict['measured_targets_count']);
    }

    // ---- Acceptance 3: a real {operator,baseline,threshold} assertion still passes (even with baseline 0).

    public function test_real_assertion_still_passes(): void
    {
        $dossier = [
            'area_id' => 'agentic_engineering_os',
            'cycle_receipts' => [],
            'evidence_packs' => [
                [
                    'subsystem' => 'autonomous_evolution_stalled',
                    'schema_version' => AtlasAutonomousEvolutionLoopService::CONTROL_PLANE_SCHEMA,
                    'measured_assertion' => [
                        'operator' => '>=',
                        'baseline' => 0, // zero baseline is fine WITH a real assertion
                        'threshold' => 5,
                    ],
                ],
            ],
        ];

        $verdict = $this->gate()->adjudicate(
            $this->proposalWithMapping([
                'area_id' => 'agentic_engineering_os',
                'subsystem' => 'autonomous_evolution_stalled',
                'schema' => AtlasAutonomousEvolutionLoopService::CONTROL_PLANE_SCHEMA,
            ]),
            $dossier,
        );

        $this->assertTrue($verdict['passed']);
        $this->assertNull($verdict['reason']);
    }

    // ---- Acceptance 4: measured_signal_ref matching a real concrete-measure entry still passes.

    public function test_ref_matching_positional_concrete_entry_passes(): void
    {
        $dossier = [
            'area_id' => 'agentic_engineering_os',
            'cycle_receipts' => [
                [
                    'subsystem' => 'pipeline_not_proven',
                    'schema' => AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
                    'baseline' => 0.42,
                ],
            ],
            'evidence_packs' => [],
        ];

        $verdict = $this->gate()->adjudicate(
            $this->proposalWithMapping([
                'area_id' => 'agentic_engineering_os',
                'subsystem' => 'pipeline_not_proven',
                'schema' => AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
                'measured_signal_ref' => 'cycle_receipts[0]',
            ]),
            $dossier,
        );

        $this->assertTrue($verdict['passed']);
        $this->assertNull($verdict['reason']);
    }

    public function test_ref_matching_explicit_entry_id_passes(): void
    {
        $dossier = [
            'area_id' => 'agentic_engineering_os',
            'cycle_receipts' => [
                [
                    'id' => 'cr-pipeline-2026-05',
                    'subsystem' => 'pipeline_not_proven',
                    'schema' => AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
                    'baseline' => 0.42,
                ],
            ],
            'evidence_packs' => [],
        ];

        $verdict = $this->gate()->adjudicate(
            $this->proposalWithMapping([
                'area_id' => 'agentic_engineering_os',
                'subsystem' => 'pipeline_not_proven',
                'schema' => AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
                'measured_signal_ref' => 'cr-pipeline-2026-05',
            ]),
            $dossier,
        );

        $this->assertTrue($verdict['passed']);
    }

    // ---- Non-finite numbers never count as a measured signal.

    public function test_non_finite_baseline_is_unmeasured(): void
    {
        $dossier = [
            'area_id' => 'agentic_engineering_os',
            'cycle_receipts' => [
                [
                    'subsystem' => 'pipeline_not_proven',
                    'schema' => AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
                    'baseline' => INF,
                ],
            ],
            'evidence_packs' => [],
        ];

        $verdict = $this->gate()->adjudicate(
            $this->proposalWithMapping([
                'area_id' => 'agentic_engineering_os',
                'subsystem' => 'pipeline_not_proven',
                'schema' => AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
            ]),
            $dossier,
        );

        $this->assertFalse($verdict['passed']);
        $this->assertSame(FrontierDriftMapperGate::REASON_UNMEASURED, $verdict['reason']);
    }

    // ---- Preserved invariants: gate is NO WEAKER than before.

    public function test_gate_is_no_weaker_legacy_rejections_still_hold(): void
    {
        $measured = [
            'area_id' => 'agentic_engineering_os',
            'cycle_receipts' => [
                [
                    'subsystem' => 'pipeline_not_proven',
                    'schema' => AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
                    'baseline' => 0.42,
                ],
            ],
            'evidence_packs' => [],
        ];

        // empty mapping still dropped
        $empty = $this->gate()->adjudicate($this->proposalWithMapping([]), $measured);
        $this->assertFalse($empty['passed']);
        $this->assertSame(FrontierDriftMapperGate::REASON_EMPTY, $empty['reason']);

        // out-of-vocabulary still dropped
        $unmapped = $this->gate()->adjudicate(
            $this->proposalWithMapping([
                'area_id' => 'agentic_engineering_os',
                'subsystem' => 'totally_invented_subsystem',
                'schema' => 'atlas.not.a.real.schema.v9',
            ]),
            $measured,
        );
        $this->assertFalse($unmapped['passed']);
        $this->assertSame(FrontierDriftMapperGate::REASON_UNMAPPED, $unmapped['reason']);

        // in-vocabulary but no measured entry still dropped
        $unmeasured = $this->gate()->adjudicate(
            $this->proposalWithMapping([
                'area_id' => 'agentic_engineering_os',
                'subsystem' => 'self_improvement_backlog_item',
                'schema' => AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
            ]),
            $measured,
        );
        $this->assertFalse($unmeasured['passed']);
        $this->assertSame(FrontierDriftMapperGate::REASON_UNMEASURED, $unmeasured['reason']);
    }

    public function test_verdict_hash_is_deterministic_and_inputs_untouched(): void
    {
        $dossier = [
            'area_id' => 'agentic_engineering_os',
            'cycle_receipts' => [
                [
                    'subsystem' => 'pipeline_not_proven',
                    'schema' => AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
                    'baseline' => 0.42,
                ],
            ],
            'evidence_packs' => [],
        ];
        $mapping = [
            'area_id' => 'agentic_engineering_os',
            'subsystem' => 'pipeline_not_proven',
            'schema' => AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
            'measured_signal_ref' => 'cycle_receipts[0]',
        ];

        $proposal = $this->proposalWithMapping($mapping);
        $proposalCopy = $proposal;
        $dossierCopy = $dossier;

        $a = $this->gate()->adjudicate($proposal, $dossier);
        $b = $this->gate()->adjudicate($this->proposalWithMapping($mapping), $dossier);

        $this->assertSame($a['verdict_hash'], $b['verdict_hash']);
        $this->assertStringStartsWith('sha256:', $a['verdict_hash']);
        $this->assertSame($proposalCopy, $proposal);
        $this->assertSame($dossierCopy, $dossier);
    }
}
