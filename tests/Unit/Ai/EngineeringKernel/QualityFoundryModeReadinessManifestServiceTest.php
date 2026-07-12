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
        self::assertTrue($manifest['completion_allowed']);
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
            'rollback_exercised' => true,
            'outcome_writer_active' => true,
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
