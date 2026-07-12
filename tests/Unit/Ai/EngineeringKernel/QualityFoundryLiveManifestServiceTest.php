<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\QualityFoundry\QualityFoundryLiveManifestService;
use App\Services\Ai\EngineeringKernel\Coverage\EngineeringExecutionSurfaceRegistry;
use Tests\TestCase;

final class QualityFoundryLiveManifestServiceTest extends TestCase
{
    public function test_live_manifest_runs_each_mode_and_keeps_unproven_gates_blocked(): void
    {
        $service = new QualityFoundryLiveManifestService(
            basePath: base_path(),
            runner: static fn (array $command, string $cwd): array => [
                'exit_code' => 0,
                'output' => implode(' ', $command).' @ '.$cwd,
            ],
        );

        $manifest = $service->build();

        self::assertSame('blocked', $manifest['status']);
        self::assertFalse($manifest['completion_allowed']);
        self::assertSame(4, $manifest['summary']['required_modes']);
        self::assertSame(0, $manifest['summary']['ready_modes']);
        self::assertNotContains('provider_invocation_not_once', $manifest['blockers']);
        self::assertNotContains('mutation_not_once', $manifest['blockers']);
        self::assertSame(1, $manifest['idempotency_evidence']['provider_invocations']);
        self::assertSame(1, $manifest['idempotency_evidence']['mutations']);
        self::assertNotContains('mode_parity_missing', $manifest['blockers']);

        foreach (['kernel', 'dev', 'forge', 'autonomos'] as $mode) {
            self::assertNotSame([], $manifest['manifests'][$mode]['receipt_hashes']);
            self::assertNotSame([], $manifest['manifests'][$mode]['test_refs']);
            self::assertContains('coverage_not_complete', $manifest['manifests'][$mode]['blockers']);
        }
    }

    public function test_failed_live_test_is_preserved_as_a_receipt_blocker(): void
    {
        $service = new QualityFoundryLiveManifestService(
            basePath: base_path(),
            runner: static fn (array $command, string $cwd): array => [
                'exit_code' => 1,
                'output' => 'failure receipt',
            ],
        );

        $manifest = $service->build();

        self::assertContains('kernel:kernel_route_missing', $manifest['blockers']);
        self::assertSame(1, $manifest['manifests']['kernel']['execution']['exit_code']);
        self::assertNotSame([], $manifest['manifests']['kernel']['receipt_hashes']);
        self::assertSame([], $manifest['manifests']['forge']['evidence']['packet_scales']);
        self::assertSame([], $manifest['manifests']['forge']['evidence']['soak_start_receipt']);
        self::assertSame(0, $manifest['manifests']['autonomos']['evidence']['visible_task_count']);
        self::assertSame([], $manifest['manifests']['autonomos']['evidence']['soak_start_receipts']);
    }

    public function test_successful_autonomos_fixture_records_queue_rotation_restart_and_soak_starts(): void
    {
        $service = new QualityFoundryLiveManifestService(
            basePath: base_path(),
            runner: static fn (array $command, string $cwd): array => [
                'exit_code' => 0,
                'output' => implode(' ', $command).' @ '.$cwd,
            ],
        );

        $autonomos = $service->build()['manifests']['autonomos'];

        self::assertSame(600, $autonomos['evidence']['visible_task_count']);
        self::assertTrue($autonomos['evidence']['dry_rotation_exercised']);
        self::assertTrue($autonomos['evidence']['restart_replay_exercised']);
        self::assertSame('initiated', $autonomos['evidence']['soak_start_receipts']['24h']['status']);
        self::assertSame('initiated', $autonomos['evidence']['soak_start_receipts']['7d']['status']);
    }

    public function test_all_live_modes_emit_the_same_quality_loss_input_contract(): void
    {
        $service = new QualityFoundryLiveManifestService(
            basePath: base_path(),
            runner: static fn (array $command, string $cwd): array => [
                'exit_code' => 0,
                'output' => implode(' ', $command).' @ '.$cwd,
            ],
        );

        $modes = $service->build()['manifests'];
        $inputs = array_values(array_map(static fn (array $manifest): array => $manifest['quality_loss_input'], $modes));

        self::assertCount(1, array_unique(array_map(
            static fn (array $input): string => hash('sha256', json_encode($input, JSON_THROW_ON_ERROR)),
            $inputs,
        )));
        self::assertSame(['cost', 'time', 'operator_effort'], $inputs[0]['secondary_metrics']);
    }

    public function test_successful_forge_fixture_records_scale_unique_effect_and_soak_start(): void
    {
        $service = new QualityFoundryLiveManifestService(
            basePath: base_path(),
            runner: static fn (array $command, string $cwd): array => [
                'exit_code' => 0,
                'output' => implode(' ', $command).' @ '.$cwd,
            ],
        );

        $manifest = $service->build();
        $forge = $manifest['manifests']['forge'];

        self::assertSame([1, 3, 10], $forge['evidence']['packet_scales']);
        self::assertTrue($forge['evidence']['duplicate_effect_proven']);
        self::assertSame('24h', $forge['evidence']['soak_start_receipt']['window']);
        self::assertSame('initiated', $forge['evidence']['soak_start_receipt']['status']);
        self::assertNotSame('', $forge['evidence']['soak_start_receipt']['receipt_hash']);
    }

    public function test_successful_canonical_rollback_suite_is_recorded_as_live_rollback_evidence(): void
    {
        $commands = [];
        $service = new QualityFoundryLiveManifestService(
            basePath: base_path(),
            runner: static function (array $command, string $cwd) use (&$commands): array {
                $commands[] = $command;

                return [
                    'exit_code' => 0,
                    'output' => implode(' ', $command),
                ];
            },
        );

        $manifest = $service->build();

        self::assertNotEmpty(array_filter(
            $commands,
            static fn (array $command): bool => in_array('tests/Feature/Ai/EngineeringKernel/CanonicalCommitActuationTest.php', $command, true),
        ));
        self::assertNotEmpty(array_filter(
            $commands,
            static fn (array $command): bool => in_array('tests/Feature/Ai/EngineeringKernel/QualityFoundryExactlyOnceEvidenceTest.php', $command, true),
        ));
        foreach (['kernel', 'dev', 'forge', 'autonomos'] as $mode) {
            self::assertTrue($manifest['manifests'][$mode]['evidence']['rollback_exercised']);
            self::assertTrue($manifest['manifests'][$mode]['evidence']['outcome_writer_active']);
            self::assertNotContains('rollback_not_exercised', $manifest['manifests'][$mode]['blockers']);
            self::assertNotContains('outcome_writer_inactive', $manifest['manifests'][$mode]['blockers']);
        }
    }

    public function test_live_manifest_derives_mode_parity_from_the_shared_execution_order_factory(): void
    {
        $service = new QualityFoundryLiveManifestService(
            basePath: base_path(),
            runner: static fn (array $command, string $cwd): array => [
                'exit_code' => 0,
                'output' => implode(' ', $command).' @ '.$cwd,
            ],
        );

        $manifest = $service->build();

        self::assertNotContains('mode_parity_missing', $manifest['blockers']);
        self::assertTrue($manifest['parity_evidence']['parity']);
    }

    public function test_coverage_percent_requires_a_valid_structured_six_surface_receipt(): void
    {
        $surfaceIds = EngineeringExecutionSurfaceRegistry::ids();
        $marker = 'QUALITY_FOUNDRY_COVERAGE_JSON='.json_encode([
            'schema' => 'atlas.quality_foundry.coverage_evidence.v1',
            'covered_surfaces' => $surfaceIds,
            'registered_surfaces' => $surfaceIds,
            'complete_events' => 6,
            'total_events' => 6,
            'coverage_percent' => 100,
        ], JSON_THROW_ON_ERROR);
        $service = new QualityFoundryLiveManifestService(
            basePath: base_path(),
            runner: static function (array $command, string $cwd) use ($marker): array {
                return [
                'exit_code' => 0,
                'output' => $marker,
                ];
            },
        );

        $manifest = $service->build();

        foreach (['kernel', 'dev', 'forge', 'autonomos'] as $mode) {
            self::assertSame(100, $manifest['manifests'][$mode]['coverage_percent']);
            self::assertNotContains('coverage_not_complete', $manifest['manifests'][$mode]['blockers']);
            self::assertSame(6, $manifest['manifests'][$mode]['evidence']['coverage']['complete_events']);
        }
    }
}
