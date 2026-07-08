<?php

declare(strict_types=1);

namespace Tests\Feature\Foundry\Frontier;

use App\Services\Ai\AutonomousEvolution\AtlasAutonomousEvolutionLoopService;
use App\Services\Ai\Foundry\Frontier\Armor\FrontierDriftMapperGate;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionSubsystemBuilderService;
use PHPUnit\Framework\TestCase;

final class FrontierDriftMapperGateTest extends TestCase
{
    private function gate(): FrontierDriftMapperGate
    {
        return new FrontierDriftMapperGate;
    }

    /**
     * Dossier with a measured cycle_receipt entry that names a real, in-vocabulary
     * subsystem AND carries a concrete measured value (numeric baseline OR a
     * {operator,baseline,threshold} assertion).
     *
     * @return array<string,mixed>
     */
    private function measuredDossier(): array
    {
        return [
            'schema_version' => 'atlas.foundry.dossier.v1',
            'area_id' => 'agentic_engineering_os',
            'cycle_receipts' => [
                [
                    'subsystem' => 'pipeline_not_proven',
                    'schema' => AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
                    'baseline' => 0.42,
                ],
            ],
            'evidence_packs' => [
                [
                    'subsystem' => 'autonomous_evolution_stalled',
                    'schema_version' => AtlasAutonomousEvolutionLoopService::CONTROL_PLANE_SCHEMA,
                    'measured_assertion' => [
                        'operator' => '>=',
                        'baseline' => 3,
                        'threshold' => 5,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $mapping
     * @return array<string,mixed>
     */
    private function proposalWithMapping(array $mapping): array
    {
        return [
            'proposal_id' => 'prop-1',
            'horizon' => 'frontier',
            'title' => 'Map drift to measured property',
            'thesis' => 'thesis',
            'evidence_refs' => [],
            'why_it_multiplies' => 'compounds',
            'success_metric' => 'op:>= baseline:0 threshold:1',
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

    public function test_passes_when_every_packet_maps_to_a_measured_canonical_property(): void
    {
        $verdict = $this->gate()->adjudicate(
            $this->proposalWithMapping([
                'area_id' => 'agentic_engineering_os',
                'subsystem' => 'pipeline_not_proven',
                'schema' => AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
                'measured_signal_ref' => 'cycle_receipts[0]',
            ]),
            $this->measuredDossier(),
        );

        $this->assertTrue($verdict['passed']);
        $this->assertNull($verdict['reason']);
        $this->assertSame('I9', $verdict['stage']);
        $this->assertGreaterThanOrEqual(1, $verdict['measured_targets_count']);
        $this->assertStringStartsWith('sha256:', $verdict['verdict_hash']);
    }

    public function test_passes_with_measured_assertion_target(): void
    {
        $verdict = $this->gate()->adjudicate(
            $this->proposalWithMapping([
                'area_id' => 'agentic_engineering_os',
                'subsystem' => 'autonomous_evolution_stalled',
                'schema' => AtlasAutonomousEvolutionLoopService::CONTROL_PLANE_SCHEMA,
                'measured_signal_ref' => 'evidence_packs[0]',
            ]),
            $this->measuredDossier(),
        );

        $this->assertTrue($verdict['passed']);
    }

    public function test_drops_empty_packet_mapping_with_reason(): void
    {
        $verdict = $this->gate()->adjudicate(
            $this->proposalWithMapping([]),
            $this->measuredDossier(),
        );

        $this->assertFalse($verdict['passed']);
        $this->assertSame(FrontierDriftMapperGate::REASON_EMPTY, $verdict['reason']);
        $this->assertNotNull($verdict['detail']);
    }

    public function test_drops_proposal_with_no_packets(): void
    {
        $proposal = $this->proposalWithMapping([]);
        $proposal['proposed_packets'] = [];

        $verdict = $this->gate()->adjudicate($proposal, $this->measuredDossier());

        $this->assertFalse($verdict['passed']);
        $this->assertSame(FrontierDriftMapperGate::REASON_EMPTY, $verdict['reason']);
    }

    public function test_drops_mapping_not_in_canonical_vocabulary(): void
    {
        $verdict = $this->gate()->adjudicate(
            $this->proposalWithMapping([
                'area_id' => 'agentic_engineering_os',
                'subsystem' => 'totally_invented_subsystem',
                'schema' => 'atlas.not.a.real.schema.v9',
            ]),
            $this->measuredDossier(),
        );

        $this->assertFalse($verdict['passed']);
        $this->assertSame(FrontierDriftMapperGate::REASON_UNMAPPED, $verdict['reason']);
    }

    /**
     * The teeth of the invariant: a mapping naming a REAL in-vocabulary subsystem
     * that has NO measured-value entry in the dossier is dropped — proving the
     * gate refuses a fabricated mapping that merely borrows a vocabulary name.
     */
    public function test_drops_in_vocabulary_subsystem_with_no_measured_entry(): void
    {
        $verdict = $this->gate()->adjudicate(
            $this->proposalWithMapping([
                'area_id' => 'agentic_engineering_os',
                // real subsystem + real schema, but NOT present as a measured
                // entry in measuredDossier()'s receipts/packs.
                'subsystem' => 'self_improvement_backlog_item',
                'schema' => AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
            ]),
            $this->measuredDossier(),
        );

        $this->assertFalse($verdict['passed']);
        $this->assertSame(FrontierDriftMapperGate::REASON_UNMEASURED, $verdict['reason']);
        $this->assertNotNull($verdict['detail']);
    }

    public function test_in_vocabulary_but_target_only_present_as_name_not_measured_is_unmeasured(): void
    {
        // Dossier entry names the subsystem/schema but carries NO concrete measure.
        $dossier = [
            'area_id' => 'agentic_engineering_os',
            'cycle_receipts' => [
                [
                    'subsystem' => 'pipeline_not_proven',
                    'schema' => AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
                    // no numeric baseline/metric, no {operator,baseline,threshold}
                    'note' => 'mentioned only',
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

    public function test_verdict_hash_is_deterministic(): void
    {
        $mapping = [
            'area_id' => 'agentic_engineering_os',
            'subsystem' => 'pipeline_not_proven',
            'schema' => AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
        ];
        $a = $this->gate()->adjudicate($this->proposalWithMapping($mapping), $this->measuredDossier());
        $b = $this->gate()->adjudicate($this->proposalWithMapping($mapping), $this->measuredDossier());

        $this->assertSame($a['verdict_hash'], $b['verdict_hash']);
    }

    public function test_gate_does_not_mutate_inputs_and_records_per_packet_results(): void
    {
        $dossier = $this->measuredDossier();
        $dossierCopy = $dossier;
        $proposal = $this->proposalWithMapping([
            'area_id' => 'agentic_engineering_os',
            'subsystem' => 'pipeline_not_proven',
            'schema' => AtlasSelfConstructionSubsystemBuilderService::PROPOSAL_SCHEMA,
        ]);
        $proposalCopy = $proposal;

        $verdict = $this->gate()->adjudicate($proposal, $dossier);

        // pure rules engine: zero canonical/code write, inputs untouched.
        $this->assertSame($dossierCopy, $dossier);
        $this->assertSame($proposalCopy, $proposal);
        $this->assertCount(1, $verdict['packet_results']);
        $this->assertTrue($verdict['packet_results'][0]['passed']);
    }
}
