<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopSemanticImplementationCertifier;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ITEM10 — the FEATURE-LANE (non-refactor) ≥9 quality bar. A feature cert (complexity_proof ABSENT)
 * always RECORDS gradeFeature()'s 0-10 score in the receipt; it only GATES when delivery_bar.armed
 * (or per-task quality_bar_gate) is ON. Default OFF => byte-identical (same cert certifies, grade
 * still recorded). Also proves the deterministic coverage_added signal from the changed file set.
 *
 * ARMING-TIME COUPLING (documented honest limit): feature mutation_kill_ratio is >0 only when the
 * mutation adequacy gate is armed. With the mutation gate OFF (default), a green in-scope feature
 * caps at 6 + 1.5 (diff_earned) + 0.5 (coverage) = 8.0 < 9 — so arming delivery_bar.armed WITHOUT
 * arming the mutation gate is a universal refusal. These tests use a no-coverage diff (score 7.5)
 * to make the refusal unambiguous and to document the coupling.
 */
final class AtlasLoopFeatureQualityBarTest extends TestCase
{
    private string $workspace = '';

    protected function tearDown(): void
    {
        if ($this->workspace !== '' && is_dir($this->workspace)) {
            (new Process(['rm', '-rf', $this->workspace]))->run();
        }
        parent::tearDown();
    }

    public function test_feature_lane_records_grade_but_does_not_gate_when_disarmed(): void
    {
        config(['atlas.loop.delivery_bar.armed' => false]);
        $this->workspace = $this->workspaceWithCandidate('good');

        $receipt = app(AtlasLoopSemanticImplementationCertifier::class)->certify($this->workspace, $this->featureAcceptance(), [
            'objective' => 'make Foo return good',
            'allowed_files' => ['src/Foo.php'],
        ]);

        // Disarmed: the SAME feature cert still certifies (byte-identical to before this item).
        $this->assertTrue($receipt['certified'], json_encode($receipt['reasons']));
        // But the grade is now RECORDED on the feature lane (the first grade this lane ever had).
        $this->assertIsArray($receipt['quality_grade']);
        $this->assertArrayHasKey('score', $receipt['quality_grade']);
        $this->assertNotContains('quality_bar:below_min:'.$receipt['quality_grade']['score'], $receipt['reasons']);
    }

    public function test_feature_lane_refuses_below_bar_feature_when_armed(): void
    {
        // ARMED: a feature whose grade is below the quality bar is refused with quality_bar:below_min.
        // CORRECTED REALITY (an earlier assumption was wrong): mutation SAMPLING runs regardless of the
        // mutation-adequacy GATE flag, so a green, in-scope, diff-earned feature whose frozen suite kills
        // its sampled mutant(s) scores ~9.5 and PASSES the default 9.0 bar — arming delivery_bar.armed
        // does NOT universally refuse features. To exercise the GATE deterministically (independent of
        // mutation-sampling noise) this task demands a strict per-task bar of 9.9, which the 9.5 feature
        // is below. A feature is genuinely refused at the default 9.0 bar only when it is NOT diff-earned
        // or its frozen suite is too weak to kill the sampled mutants (kill_ratio low).
        config(['atlas.loop.delivery_bar.armed' => true]);
        $this->workspace = $this->workspaceWithCandidate('good');

        $acceptance = $this->featureAcceptance();
        $acceptance['quality_bar'] = 9.9; // stricter than the ~9.5 a fully-evidenced feature can earn

        $receipt = app(AtlasLoopSemanticImplementationCertifier::class)->certify($this->workspace, $acceptance, [
            'objective' => 'make Foo return good',
            'allowed_files' => ['src/Foo.php'],
        ]);

        $this->assertFalse($receipt['certified'], json_encode($receipt['reasons']));
        $score = $receipt['quality_grade']['score'];
        $this->assertContains('quality_bar:below_min:'.$score, $receipt['reasons']);
        $this->assertLessThan(9.9, (float) $score);
    }

    public function test_coverage_added_is_false_without_a_test_file_in_the_diff(): void
    {
        config(['atlas.loop.delivery_bar.armed' => false]);
        $this->workspace = $this->workspaceWithCandidate('good');

        $receipt = app(AtlasLoopSemanticImplementationCertifier::class)->certify($this->workspace, $this->featureAcceptance(), [
            'objective' => 'make Foo return good',
            'allowed_files' => ['src/Foo.php'],
        ]);

        // The diff touches only src/Foo.php (no *Test.php) => deterministic coverage_added is false.
        $this->assertFalse((bool) data_get($receipt, 'quality_grade.dimensions.coverage_added'));
    }

    /**
     * @return array<string,mixed>
     */
    private function featureAcceptance(): array
    {
        // metric_kind='gate' + NO complexity_proof = the FEATURE lane (the refactor block never runs).
        return [
            'commands' => ['php tests/FooTest.php'],
            'allowed_globs' => ['src/**'],
            'frozen_globs' => ['tests/**'],
            'metric_kind' => 'gate',
            'revert_recheck' => true,
            'timeout_seconds' => 30,
        ];
    }

    private function workspaceWithCandidate(string $candidateValue): string
    {
        $dir = sys_get_temp_dir().'/atlas-feature-bar-'.bin2hex(random_bytes(5));
        mkdir($dir.'/src', 0o755, true);
        mkdir($dir.'/tests', 0o755, true);
        file_put_contents($dir.'/src/Foo.php', $this->foo('bad'));
        file_put_contents($dir.'/tests/FooTest.php', <<<'PHP'
<?php
require __DIR__.'/../src/Foo.php';

$foo = new Foo();
if ($foo->value() !== 'good') {
    fwrite(STDERR, 'expected good');
    exit(1);
}
PHP);
        $this->runProcess(['git', 'init'], $dir);
        $this->runProcess(['git', 'config', 'user.email', 'atlas-loop@local'], $dir);
        $this->runProcess(['git', 'config', 'user.name', 'Atlas Loop'], $dir);
        $this->runProcess(['git', 'add', '-A'], $dir);
        $this->runProcess(['git', 'commit', '-q', '-m', 'baseline'], $dir);
        file_put_contents($dir.'/src/Foo.php', $this->foo($candidateValue));

        return $dir;
    }

    private function foo(string $value): string
    {
        return "<?php\nfinal class Foo\n{\n    public function value(): string\n    {\n        return '".$value."';\n    }\n}\n";
    }

    /**
     * @param  list<string>  $argv
     */
    private function runProcess(array $argv, string $cwd): void
    {
        $process = new Process($argv, $cwd, null, null, 30.0);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput() ?: $process->getOutput());
    }
}
