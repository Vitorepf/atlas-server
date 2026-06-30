<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Autopoiesis;

use App\Services\Ai\SelfConstruction\Autopoiesis\AtlasSelfConstructionAutopoiesisExperimentDesigner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves AtlasSelfConstructionAutopoiesisExperimentDesigner: a complete hypothesis is designed into a
 * bounded experiment plan with separation_of_powers; missing rollback.strategy throws; empty
 * expected_evidence throws; broad-directory scope_path throws; identical input ⇒ identical plan_hash.
 */
final class AtlasSelfConstructionAutopoiesisExperimentDesignerTest extends TestCase
{
    private function validHypothesis(): array
    {
        return [
            'hypothesis_id' => 'h-1',
            'claim' => 'X improves Y',
            'scope_paths' => ['app/Demo/Foo.php'],
            'expected_evidence' => ['phpunit_green', 'commit_sha'],
            'gates' => ['phpunit'],
            'rollback' => ['strategy' => 'revert_commit', 'affected_files' => ['app/Demo/Foo.php']],
        ];
    }

    public function test_happy_path_yields_designed_experiment_plan_with_separation_of_powers(): void
    {
        $r = (new AtlasSelfConstructionAutopoiesisExperimentDesigner)->design($this->validHypothesis());
        $this->assertSame('designed', $r['status']);
        $this->assertSame('exp:h-1', $r['experiment_id']);
        $this->assertSame(['app/Demo/Foo.php'], $r['scope_paths']);
        $this->assertSame('propose', $r['separation_of_powers']['autopoiesis_role']);
        $this->assertSame('packetize', $r['separation_of_powers']['task_fabric_role']);
        $this->assertSame('verify', $r['separation_of_powers']['verification_court_role']);
        $this->assertSame(64, strlen($r['plan_hash']));
    }

    public function test_missing_rollback_strategy_throws(): void
    {
        $h = $this->validHypothesis();
        $h['rollback']['strategy'] = '';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/rollback.strategy missing/');
        (new AtlasSelfConstructionAutopoiesisExperimentDesigner)->design($h);
    }

    public function test_empty_expected_evidence_throws(): void
    {
        $h = $this->validHypothesis();
        $h['expected_evidence'] = [];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/empty expected_evidence/');
        (new AtlasSelfConstructionAutopoiesisExperimentDesigner)->design($h);
    }

    public function test_broad_directory_in_scope_paths_throws(): void
    {
        $h = $this->validHypothesis();
        $h['scope_paths'] = ['app/Demo/'];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/broad directory rejected/');
        (new AtlasSelfConstructionAutopoiesisExperimentDesigner)->design($h);
    }

    public function test_empty_rollback_affected_files_throws(): void
    {
        $h = $this->validHypothesis();
        $h['rollback']['affected_files'] = [];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/rollback.affected_files empty/');
        (new AtlasSelfConstructionAutopoiesisExperimentDesigner)->design($h);
    }

    public function test_plan_hash_is_byte_identical_for_same_input(): void
    {
        $d = new AtlasSelfConstructionAutopoiesisExperimentDesigner;
        $a = $d->design($this->validHypothesis());
        $b = $d->design($this->validHypothesis());
        $this->assertSame($a['plan_hash'], $b['plan_hash']);
    }

    public function test_scope_paths_exceeding_risk_limit_throws(): void
    {
        $h = $this->validHypothesis();
        $h['scope_paths'] = array_map(fn (int $i) => "app/Demo/File{$i}.php", range(1, AtlasSelfConstructionAutopoiesisExperimentDesigner::MAX_SCOPE_PATHS + 1));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/scope_paths exceeds risk limit/');
        (new AtlasSelfConstructionAutopoiesisExperimentDesigner)->design($h);
    }
}
