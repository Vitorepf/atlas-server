<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\QualityFoundry\QualityFoundryLiveManifestService;
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
        self::assertContains('provider_invocation_not_once', $manifest['blockers']);
        self::assertContains('mode_parity_missing', $manifest['blockers']);

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
        foreach (['kernel', 'dev', 'forge', 'autonomos'] as $mode) {
            self::assertTrue($manifest['manifests'][$mode]['evidence']['rollback_exercised']);
            self::assertTrue($manifest['manifests'][$mode]['evidence']['outcome_writer_active']);
            self::assertNotContains('rollback_not_exercised', $manifest['manifests'][$mode]['blockers']);
            self::assertNotContains('outcome_writer_inactive', $manifest['manifests'][$mode]['blockers']);
        }
    }
}
