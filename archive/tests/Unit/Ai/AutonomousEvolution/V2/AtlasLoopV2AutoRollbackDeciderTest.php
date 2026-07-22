<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\V2;

use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2AuditJournal;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2AutoRollbackDecider;
use App\Services\Ai\AutonomousEvolution\V2\AtlasLoopV2BlastRadiusCalculator;
use Tests\TestCase;

final class AtlasLoopV2AutoRollbackDeciderTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_none_baseline_is_recorded_with_zero_severity(): void
    {
        [$decider, $journal] = $this->decider();

        $decision = $decider->decide(
            ['tests_failing' => 0, 'red_main_streak' => 0, 'perf_regression_pct' => 0.0],
            ['tier' => 'safe'],
        );

        $this->assertSame('atlas.ai.loop_v2.rollback.v1', $decision['schema_version']);
        $this->assertSame('none', $decision['action']);
        $this->assertSame(0, $decision['severity']);
        $this->assertSame('post_merge_signals_within_rollback_thresholds', $decision['reason']);

        $tail = $journal->tail(1);
        $this->assertSame('rollback_decision', $tail[0]['event_type']);
        $this->assertSame($decision, $tail[0]['payload']);
    }

    public function test_red_main_streak_reverts_for_any_blast_radius(): void
    {
        [$decider] = $this->decider();

        $decision = $decider->decide(
            ['tests_failing' => 0, 'red_main_streak' => 3, 'perf_regression_pct' => 0.0],
            ['tier' => 'safe'],
        );

        $this->assertSame('revert', $decision['action']);
        $this->assertSame('red_main_streak_threshold_reached', $decision['reason']);
        $this->assertSame(90, $decision['severity']);
    }

    public function test_critical_blast_with_test_failure_reverts_before_other_rules(): void
    {
        [$decider] = $this->decider();

        $decision = $decider->decide(
            ['tests_failing' => 1, 'red_main_streak' => 0, 'perf_regression_pct' => 0.0],
            ['tier' => 'critical'],
        );

        $this->assertSame('revert', $decision['action']);
        $this->assertSame('critical_blast_radius_with_test_failures', $decision['reason']);
        $this->assertSame(65, $decision['severity']);
    }

    public function test_wide_blast_with_perf_regression_quarantines(): void
    {
        [$decider] = $this->decider();

        $decision = $decider->decide(
            ['tests_failing' => 0, 'red_main_streak' => 0, 'perf_regression_pct' => 12.0],
            ['tier' => 'wide'],
        );

        $this->assertSame('quarantine', $decision['action']);
        $this->assertSame('blast_radius_with_perf_regression', $decision['reason']);
        $this->assertSame(37, $decision['severity']);
    }

    public function test_severity_formula_uses_three_inputs_and_tier_weight(): void
    {
        [$decider] = $this->decider();

        $decision = $decider->decide(
            ['tests_failing' => 2, 'red_main_streak' => 1, 'perf_regression_pct' => 12.4],
            ['tier' => 'moderate'],
        );

        $this->assertSame(82, $decision['severity']);
        $this->assertSame([
            'blast_radius' => ['tier' => 'moderate'],
            'blast_tier' => 'moderate',
            'perf_regression_pct' => 12.4,
            'red_main_streak' => 1,
            'tests_failing' => 2,
        ], $decision['evidence']);
    }

    public function test_no_process_shell_or_binary_invocation_is_present(): void
    {
        $source = (string) file_get_contents(app_path('Services/Ai/AutonomousEvolution/V2/AtlasLoopV2AutoRollbackDecider.php'));

        $this->assertStringNotContainsString('Process', $source);
        $this->assertStringNotContainsString('shell_exec', $source);
        $this->assertStringNotContainsString("'git'", $source);
        $this->assertStringNotContainsString('"git"', $source);
    }

    /**
     * @return array{AtlasLoopV2AutoRollbackDecider,AtlasLoopV2AuditJournal}
     */
    private function decider(): array
    {
        $path = sys_get_temp_dir().'/atlas-loop-v2-rollback-decider-'.uniqid('', true).'.ndjson';
        $this->paths[] = $path;
        $journal = new AtlasLoopV2AuditJournal($path, static fn (): string => '2026-06-24T20:00:00+00:00');

        return [
            new AtlasLoopV2AutoRollbackDecider(new AtlasLoopV2BlastRadiusCalculator, $journal),
            $journal,
        ];
    }
}
