<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Frozen;

use App\Services\Ai\AutonomousEvolution\Frozen\AtlasLoopFrozenContractAuditor;
use App\Services\Ai\AutonomousEvolution\Frozen\AtlasLoopFrozenContractRegistry;
use Tests\TestCase;

final class AtlasLoopFrozenContractAuditorTest extends TestCase
{
    public function test_registered_class_without_frozen_test_blocks_with_offending_pair(): void
    {
        $result = $this->auditor()->audit([
            'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php',
        ], new AtlasLoopFrozenContractRegistry);

        $this->assertSame('atlas.ai.loop_frozen_contract_auditor.v1', $result['schema_version']);
        $this->assertSame('BLOCK', $result['verdict']);
        $this->assertSame([
            [
                'fqcn' => 'App\\Services\\Ai\\AutonomousEvolution\\AtlasEvolutionFrozenJudge',
                'frozen_test_path' => 'tests/Unit/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudgeTest.php',
            ],
        ], $result['offending_pairs']);
        $this->assertSame([], $result['checked_pairs']);
    }

    public function test_registered_class_with_frozen_test_touched_allows(): void
    {
        $result = $this->auditor()->audit([
            'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php',
            'tests/Unit/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudgeTest.php',
        ], new AtlasLoopFrozenContractRegistry);

        $this->assertSame('ALLOW', $result['verdict']);
        $this->assertSame([], $result['offending_pairs']);
        $this->assertSame([
            [
                'fqcn' => 'App\\Services\\Ai\\AutonomousEvolution\\AtlasEvolutionFrozenJudge',
                'frozen_test_path' => 'tests/Unit/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudgeTest.php',
                'covered_by' => 'diff_touched',
            ],
        ], $result['checked_pairs']);
    }

    public function test_registered_class_with_rerun_green_assertion_allows_without_test_diff(): void
    {
        $result = $this->auditor()->audit(
            ['app/Services/Ai/AutonomousEvolution/AtlasLoopAttemptLedger.php'],
            new AtlasLoopFrozenContractRegistry,
            ['tests/Unit/Ai/AutonomousEvolution/AtlasLoopAttemptLedgerTest.php'],
        );

        $this->assertSame('ALLOW', $result['verdict']);
        $this->assertSame('rerun_green_asserted', $result['checked_pairs'][0]['covered_by']);
    }

    public function test_unregistered_loop_class_is_ignored_by_this_contract_gate(): void
    {
        $result = $this->auditor()->audit([
            'app/Services/Ai/AutonomousEvolution/Telemetry/AtlasLoopTelemetryFactStreamEmitter.php',
        ], new AtlasLoopFrozenContractRegistry);

        $this->assertSame('ALLOW', $result['verdict']);
        $this->assertSame([], $result['offending_pairs']);
        $this->assertSame([], $result['checked_pairs']);
    }

    public function test_auditor_is_referentially_transparent_and_config_default_is_false(): void
    {
        $auditor = $this->auditor();
        $registry = new AtlasLoopFrozenContractRegistry;
        $diff = [
            'tests/Unit/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudgeTest.php',
            'app/Services/Ai/AutonomousEvolution/AtlasEvolutionFrozenJudge.php',
        ];

        $first = $auditor->audit($diff, $registry);
        $second = $auditor->audit($diff, $registry);
        $hash = hash('sha256', serialize($first));

        $this->assertSame($first, $second);
        $this->assertSame($hash, hash('sha256', serialize($second)));
        $this->assertFalse((bool) config('atlas.loop.frozen_contract_auditor_enforced', false));
    }

    public function test_source_has_no_io_time_or_environment_reads(): void
    {
        $source = (string) file_get_contents(app_path('Services/Ai/AutonomousEvolution/Frozen/AtlasLoopFrozenContractAuditor.php'));

        $this->assertStringNotContainsString('config(', $source);
        $this->assertStringNotContainsString('file_get_contents', $source);
        $this->assertStringNotContainsString('now(', $source);
        $this->assertStringNotContainsString('env(', $source);
    }

    private function auditor(): AtlasLoopFrozenContractAuditor
    {
        return new AtlasLoopFrozenContractAuditor;
    }
}
