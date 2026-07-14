<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\QualityFoundry\QualityFoundryModeReadinessManifestService;
use Tests\TestCase;

final class QualityFoundryModeReadinessManifestServiceTest extends TestCase
{
    public function test_four_mode_manifest_is_ready_only_from_live_receipts(): void
    {
        $manifest = (new QualityFoundryModeReadinessManifestService)->build($this->input());

        self::assertSame('ready', $manifest['status']);
        self::assertFalse($manifest['completion_allowed']);
        self::assertSame('blocked', $manifest['operational_status']);
        self::assertContains('hermetic_evidence_only', $manifest['completion_blockers']);
        self::assertContains('temporal_outcomes_not_elapsed', $manifest['completion_blockers']);
        self::assertContains('rivals_claim_not_eligible', $manifest['completion_blockers']);
        self::assertSame(['kernel', 'dev', 'forge', 'autonomos'], $manifest['required_modes']);
        self::assertSame([], $manifest['blockers']);
        self::assertSame(4, $manifest['summary']['ready_modes']);
        self::assertFalse($manifest['claim_eligible']);
    }

    public function test_config_intent_or_missing_receipt_blocks_the_manifest(): void
    {
        $input = $this->input();
        $input['modes']['forge']['receipt_hashes'] = [];
        $input['modes']['autonomos']['source'] = 'config_intent';

        $manifest = (new QualityFoundryModeReadinessManifestService)->build($input);

        self::assertSame('blocked', $manifest['status']);
        self::assertFalse($manifest['completion_allowed']);
        self::assertContains('forge:live_receipts_missing', $manifest['blockers']);
        self::assertContains('autonomos:live_receipt_source_required', $manifest['blockers']);
        self::assertSame(2, $manifest['summary']['ready_modes']);
    }

    public function test_duplicate_provider_or_mutation_receipts_block_once_per_idempotency_key(): void
    {
        $input = $this->input();
        $input['idempotency']['provider_invocations'] = 2;
        $input['idempotency']['mutations'] = 2;

        $manifest = (new QualityFoundryModeReadinessManifestService)->build($input);

        self::assertSame('blocked', $manifest['status']);
        self::assertContains('provider_invocation_not_once', $manifest['blockers']);
        self::assertContains('mutation_not_once', $manifest['blockers']);
        self::assertFalse($manifest['shadow']['mutation_allowed']);
    }

    public function test_readiness_flags_cannot_synthesize_soak_or_world_claims(): void
    {
        $input = $this->input();
        $input['quality_foundry_ready'] = true;
        $input['soak_elapsed'] = true;
        $input['multiplier_proven'] = true;
        $input['world_leading'] = true;
        $input['world_10x_quality_proven'] = true;

        $manifest = (new QualityFoundryModeReadinessManifestService)->build($input);

        self::assertFalse($manifest['claim_eligible']);
        self::assertFalse($manifest['comparative_claims_allowed']);
        self::assertArrayNotHasKey('soak_elapsed', $manifest);
        self::assertArrayNotHasKey('world_leading', $manifest);
        self::assertArrayNotHasKey('world_10x_quality_proven', $manifest);
    }

    public function test_completion_requires_structured_independent_operational_temporal_and_rivals_evidence(): void
    {
        $input = $this->input();
        $input['operational_evidence'] = [
            'source' => 'production_runtime_receipt',
            'status' => 'attested',
            'production_exposure' => true,
            'synthetic' => false,
            'receipt_hash' => hash('sha256', 'production-receipt'),
        ];
        $input['temporal_projection'] = [
            'schema' => 'atlas.quality_foundry.temporal_projection.v1',
            'temporal_state' => 'complete',
            'blockers' => [],
            'projection_hash' => hash('sha256', 'temporal-projection'),
            'windows' => array_fill_keys(
                ['0h', '24h', '7d', '30d', '90d', '150d'],
                ['state' => 'observed'],
            ),
        ];
        $input['rivals_trial'] = [
            'mode' => 'quality-foundry',
            'required_exposure' => 1,
            'required_outcome_days' => 30,
            'required_critical_dimensions' => ['correctness'],
            'campaigns' => array_map(
                static fn (string $id): array => [
                    'id' => $id,
                    'distinct_units' => 1,
                    'power' => 0.95,
                    'outcome_days' => 30,
                    'synthetic' => false,
                    'execution_source' => 'native_runtime',
                    'real_execution' => true,
                    'preregistration_hash' => hash('sha256', 'preregistration:'.$id),
                    'native_receipt_hash' => hash('sha256', 'native:'.$id),
                    'evidence_pack_hash' => hash('sha256', 'evidence:'.$id),
                    'outcome_receipt_hash' => hash('sha256', 'outcome:'.$id),
                    'outcome_windows' => array_fill_keys(
                        ['0h', '24h', '7d', '30d'],
                        [
                            'state' => 'observed',
                            'source' => 'atlas_outcome_store',
                            'synthetic' => false,
                            'observation_hash' => hash('sha256', 'observation:'.$id),
                        ],
                    ),
                    'contamination_free' => true,
                    'itt_complete' => true,
                    'critical_dimensions' => ['correctness' => true],
                ],
                ['campaign-a', 'campaign-b', 'campaign-c'],
            ),
        ];

        $manifest = (new QualityFoundryModeReadinessManifestService)->build($input);

        self::assertTrue($manifest['completion_allowed']);
        self::assertSame('ready', $manifest['operational_status']);
        self::assertSame('attested', $manifest['operational_evidence']['status']);
        self::assertSame([], $manifest['completion_blockers']);
    }

    public function test_missing_mode_canary_crash_wip_or_zero_human_evidence_blocks_readiness(): void
    {
        $input = $this->input();
        unset($input['modes']['dev']['evidence']['wip_preserved']);
        unset($input['modes']['forge']['evidence']['crash_boundaries_exercised']);
        unset($input['modes']['autonomos']['evidence']['zero_human_proven']);
        unset($input['modes']['kernel']['evidence']['canary_exercised']);

        $manifest = (new QualityFoundryModeReadinessManifestService)->build($input);

        self::assertSame('blocked', $manifest['status']);
        self::assertContains('kernel:canary_evidence_missing', $manifest['blockers']);
        self::assertContains('dev:wip_preservation_evidence_missing', $manifest['blockers']);
        self::assertContains('forge:crash_boundary_evidence_missing', $manifest['blockers']);
        self::assertContains('autonomos:zero_human_evidence_missing', $manifest['blockers']);
    }

    public function test_dev_readiness_requires_risk_band_canaries_surface_parity_and_operator_measurement(): void
    {
        $input = $this->input();
        unset($input['modes']['dev']['evidence']['canary_risk_bands']);
        unset($input['modes']['dev']['evidence']['surface_parity']);
        unset($input['modes']['dev']['evidence']['operator_effort']);

        $manifest = (new QualityFoundryModeReadinessManifestService)->build($input);

        self::assertSame('blocked', $manifest['status']);
        self::assertContains('dev:risk_band_canaries_missing', $manifest['blockers']);
        self::assertContains('dev:surface_parity_missing', $manifest['blockers']);
        self::assertContains('dev:operator_effort_evidence_missing', $manifest['blockers']);
    }

    public function test_forge_readiness_requires_packet_scale_unique_effect_and_soak_start_receipt(): void
    {
        $input = $this->input();
        unset($input['modes']['forge']['evidence']['packet_scales']);
        unset($input['modes']['forge']['evidence']['duplicate_effect_proven']);
        unset($input['modes']['forge']['evidence']['soak_start_receipt']);

        $manifest = (new QualityFoundryModeReadinessManifestService)->build($input);

        self::assertSame('blocked', $manifest['status']);
        self::assertContains('forge:packet_scale_evidence_missing', $manifest['blockers']);
        self::assertContains('forge:duplicate_effect_evidence_missing', $manifest['blockers']);
        self::assertContains('forge:soak_start_receipt_missing', $manifest['blockers']);
    }

    public function test_autonomos_readiness_requires_visible_queue_zero_human_rotation_restart_and_two_soak_starts(): void
    {
        $input = $this->input();
        unset($input['modes']['autonomos']['evidence']['visible_task_count']);
        unset($input['modes']['autonomos']['evidence']['dry_rotation_exercised']);
        unset($input['modes']['autonomos']['evidence']['restart_replay_exercised']);
        unset($input['modes']['autonomos']['evidence']['soak_start_receipts']);

        $manifest = (new QualityFoundryModeReadinessManifestService)->build($input);

        self::assertSame('blocked', $manifest['status']);
        self::assertContains('autonomos:visible_queue_evidence_missing', $manifest['blockers']);
        self::assertContains('autonomos:dry_rotation_evidence_missing', $manifest['blockers']);
        self::assertContains('autonomos:restart_replay_evidence_missing', $manifest['blockers']);
        self::assertContains('autonomos:soak_start_receipts_missing', $manifest['blockers']);
    }

    public function test_all_modes_require_the_same_quality_loss_input_contract(): void
    {
        $input = $this->input();
        $input['modes']['forge']['quality_loss_input']['kernel_bar_hash'] = hash('sha256', 'different-bar');

        $manifest = (new QualityFoundryModeReadinessManifestService)->build($input);

        self::assertSame('blocked', $manifest['status']);
        self::assertContains('quality_loss_inputs_not_comparable', $manifest['blockers']);

        $input = $this->input();
        unset($input['modes']['autonomos']['quality_loss_input']);
        $manifest = (new QualityFoundryModeReadinessManifestService)->build($input);

        self::assertContains('autonomos:quality_loss_input_missing', $manifest['blockers']);
    }

    public function test_mutative_surface_coverage_is_100_percent_while_msi_uses_the_canonical_60_percent_floor(): void
    {
        $input = $this->input();
        foreach ($input['modes'] as &$mode) {
            $mode['mutation_coverage_percent'] = 100;
            $mode['mutation_score_percent'] = 60.0;
            $mode['evidence']['mutation_coverage']['total_mutants'] = 10;
            $mode['evidence']['mutation_coverage']['killed_mutants'] = 6;
            $mode['evidence']['mutation_coverage']['surviving_mutants'] = 4;
            $mode['evidence']['mutation_coverage']['mutation_score_percent'] = 60;
        }
        unset($mode);

        $manifest = (new QualityFoundryModeReadinessManifestService)->build($input);

        self::assertSame([], array_values(array_filter(
            $manifest['blockers'],
            static fn (string $blocker): bool => str_contains($blocker, 'mutation_coverage'),
        )));
        self::assertSame(60.0, $manifest['manifests']['kernel']['mutation_score_percent']);
    }

    /** @return array<string,mixed> */
    private function input(): array
    {
        $receipt = static fn (string $mode): array => [
            'mode' => $mode,
            'source' => 'live_receipt',
            'receipt_hashes' => [hash('sha256', 'receipt:'.$mode)],
            'test_refs' => [[
                'path' => 'tests/Unit/Ai/EngineeringKernel/QualityFoundryModeReadinessManifestServiceTest.php',
                'sha256' => (string) hash_file('sha256', base_path('tests/Unit/Ai/EngineeringKernel/QualityFoundryModeReadinessManifestServiceTest.php')),
            ]],
            'kernel_routed' => true,
            'coverage_percent' => 100,
            'mutation_coverage_percent' => 100,
            'rollback_exercised' => true,
            'outcome_writer_active' => true,
            'quality_loss_input' => [
                'schema' => 'atlas.quality_foundry.quality_loss_input.v1',
                'kernel_bar_hash' => hash('sha256', 'atlas-quality-foundry-shared-kernel-bar-v1'),
                'dimensions' => ['correctness', 'safety', 'scope', 'reliability'],
                'critical_dimensions' => ['correctness', 'safety'],
                'quality_loss_definition' => 'frozen_weighted_quality_loss_v1',
                'secondary_metrics' => ['cost', 'time', 'operator_effort'],
            ],
            'evidence' => [
                'canary_exercised' => true,
                'crash_boundaries_exercised' => true,
                'wip_preserved' => true,
                'zero_human_proven' => true,
                'canary_risk_bands' => ['R0', 'R3', 'R5'],
                'surface_parity' => true,
                'operator_effort' => [
                    'measurement_mode' => 'observed_operator_runs',
                    'run_count' => 1,
                ],
                'mutation_coverage' => [
                    'schema' => 'atlas.quality_foundry.mutation_coverage_evidence.v1',
                    'status' => 'observed',
                    'registered_mutation_surfaces' => \App\Services\Ai\EngineeringKernel\Coverage\EngineeringExecutionSurfaceRegistry::ids(),
                    'tested_mutation_surfaces' => \App\Services\Ai\EngineeringKernel\Coverage\EngineeringExecutionSurfaceRegistry::ids(),
                    'total_mutants' => 6,
                    'killed_mutants' => 6,
                    'surviving_mutants' => 0,
                    'mutation_score_percent' => 100,
                ],
                'packet_scales' => [1, 3, 10],
                'duplicate_effect_proven' => true,
                'soak_start_receipt' => [
                    'window' => '24h',
                    'status' => 'initiated',
                    'receipt_hash' => hash('sha256', 'soak-start:'.$mode),
                ],
                'visible_task_count' => 600,
                'dry_rotation_exercised' => true,
                'restart_replay_exercised' => true,
                'soak_start_receipts' => [
                    '24h' => ['status' => 'initiated', 'receipt_hash' => hash('sha256', 'soak-start:24h:'.$mode)],
                    '7d' => ['status' => 'initiated', 'receipt_hash' => hash('sha256', 'soak-start:7d:'.$mode)],
                ],
            ],
        ];

        return [
            'modes' => [
                'kernel' => $receipt('kernel'),
                'dev' => $receipt('dev'),
                'forge' => $receipt('forge'),
                'autonomos' => $receipt('autonomos'),
            ],
            'mode_parity' => true,
            'idempotency' => [
                'provider_invocations' => 1,
                'mutations' => 1,
            ],
            'shadow' => [
                'replay_only' => true,
                'mutation_allowed' => false,
            ],
        ];
    }
}
