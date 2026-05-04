<?php

namespace Tests\Unit;

use App\Models\AtlasEngineeringBenchmarkCase;
use App\Models\AtlasTask;
use App\Services\Engineering\EngineeringBenchmarkService;
use App\Services\Engineering\EngineeringClaudeCodeBaselineRunnerService;
use App\Services\Engineering\EngineeringHarnessRunnerService;
use ReflectionMethod;
use Tests\TestCase;

class EngineeringBenchmarkFairClaudeScorecardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.ai.providers.claude_cli.premium_model', 'claude-opus-test');
    }

    public function test_fair_scorecard_requires_pass_without_human_and_verified_gates(): void
    {
        $scorecard = $this->fairScorecard([
            'run' => [
                'model_selection' => [
                    'selected_provider' => 'claude_cli',
                    'selected_model' => 'claude-opus-test',
                ],
                'fair_mode_result' => [
                    'status' => 'valid',
                    'deterministic_gates_passed' => true,
                    'pass_without_human' => true,
                    'blocking_reasons' => [],
                ],
                'attempt_count' => 2,
                'scope_safety' => $this->scopeSafetyApplied(),
            ],
        ], [
            'claude_only' => true,
            'require_pass_without_human' => true,
        ]);

        $this->assertIsArray($scorecard);
        $this->assertTrue($scorecard['required']);
        $this->assertTrue($scorecard['passed']);
        $this->assertTrue($scorecard['protocol_valid']);
        $this->assertTrue($scorecard['final_gate_passed']);
        $this->assertSame(0, $scorecard['human_intervention_count']);
        $this->assertSame(0, $scorecard['provider_violation_count']);
        $this->assertSame(0, $scorecard['fallback_violation_count']);
        $this->assertSame(2, $scorecard['attempt_count']);
        $this->assertSame(1, $scorecard['repair_attempt_count']);
        $this->assertTrue($scorecard['repair_used']);
        $this->assertTrue($scorecard['converted_to_green']);
        $this->assertTrue($scorecard['pass_without_human']);
        $this->assertTrue($scorecard['pass_without_human_reported']);
        $this->assertSame([], $scorecard['blocking_reasons']);
        $this->assertSame('applied', data_get($scorecard, 'scope_safety.status'));
    }

    public function test_fair_scorecard_blocks_pass_without_human_on_dirty_state_overlap(): void
    {
        $scorecard = $this->fairScorecard([
            'run' => [
                'model_selection' => [
                    'selected_provider' => 'claude_cli',
                    'selected_model' => 'claude-opus-test',
                ],
                'fair_mode_result' => [
                    'status' => 'valid',
                    'deterministic_gates_passed' => true,
                    'pass_without_human' => true,
                    'blocking_reasons' => [],
                ],
                'attempt_count' => 1,
                'scope_safety' => [
                    'status' => 'blocked',
                    'reason' => 'dirty_state_overlap',
                    'dirty_overlap' => ['app/Foo.php'],
                    'untracked_overlap' => [],
                    'scope_safety' => [
                        'safe' => false,
                        'status' => 'dirty_overlap',
                        'dirty_files' => ['app/Foo.php'],
                        'untracked_files' => [],
                        'dirty_overlap' => ['app/Foo.php'],
                        'untracked_overlap' => [],
                        'modified_overlap' => ['app/Foo.php'],
                    ],
                ],
            ],
        ], [
            'claude_only' => true,
            'require_pass_without_human' => true,
        ]);
        $evaluation = $this->evaluate($scorecard);

        $this->assertIsArray($scorecard);
        $this->assertFalse($scorecard['passed']);
        $this->assertFalse($scorecard['pass_without_human']);
        $this->assertTrue($scorecard['pass_without_human_reported']);
        $this->assertContains('dirty_state_overlap', $scorecard['blocking_reasons']);
        $this->assertContains('scope_safety_unverified', $scorecard['blocking_reasons']);
        $this->assertContains('pass_without_human_false', $scorecard['blocking_reasons']);
        $this->assertSame(['app/Foo.php'], data_get($scorecard, 'scope_safety.dirty_overlap'));
        $this->assertFalse($evaluation['passed']);
    }

    public function test_fair_scorecard_blocks_pass_without_human_on_untracked_overlap_warning(): void
    {
        $scorecard = $this->fairScorecard([
            'run' => [
                'model_selection' => [
                    'selected_provider' => 'claude_cli',
                    'selected_model' => 'claude-opus-test',
                ],
                'fair_mode_result' => [
                    'status' => 'valid',
                    'deterministic_gates_passed' => true,
                    'pass_without_human' => true,
                    'blocking_reasons' => [],
                ],
                'attempt_count' => 1,
                'scope_safety' => [
                    'status' => 'blocked',
                    'reason' => 'dirty_state_overlap',
                    'dirty_overlap' => ['scripts/new-thing.sh'],
                    'untracked_overlap' => ['scripts/new-thing.sh'],
                    'scope_safety' => [
                        'safe' => false,
                        'status' => 'dirty_overlap',
                        'dirty_files' => ['scripts/new-thing.sh'],
                        'untracked_files' => ['scripts/new-thing.sh'],
                        'dirty_overlap' => ['scripts/new-thing.sh'],
                        'untracked_overlap' => ['scripts/new-thing.sh'],
                        'modified_overlap' => [],
                    ],
                ],
            ],
        ], [
            'claude_only' => true,
            'require_pass_without_human' => true,
        ]);

        $this->assertFalse($scorecard['passed']);
        $this->assertFalse($scorecard['pass_without_human']);
        $this->assertContains('dirty_state_overlap', $scorecard['blocking_reasons']);
        $this->assertSame(
            ['scripts/new-thing.sh'],
            data_get($scorecard, 'scope_safety.untracked_overlap'),
        );
        $this->assertSame(
            ['scripts/new-thing.sh'],
            data_get($scorecard, 'scope_safety.untracked_files'),
        );
    }

    public function test_fair_scorecard_blocks_pass_without_human_when_scope_safety_missing(): void
    {
        $scorecard = $this->fairScorecard([
            'run' => [
                'model_selection' => [
                    'selected_provider' => 'claude_cli',
                    'selected_model' => 'claude-opus-test',
                ],
                'fair_mode_result' => [
                    'status' => 'valid',
                    'deterministic_gates_passed' => true,
                    'pass_without_human' => true,
                    'blocking_reasons' => [],
                ],
                'attempt_count' => 1,
            ],
        ], [
            'claude_only' => true,
            'require_pass_without_human' => true,
        ]);

        $this->assertFalse($scorecard['passed']);
        $this->assertFalse($scorecard['pass_without_human']);
        $this->assertTrue($scorecard['pass_without_human_reported']);
        $this->assertContains('scope_safety_unverified', $scorecard['blocking_reasons']);
        $this->assertSame('missing', data_get($scorecard, 'scope_safety.status'));
    }

    /**
     * @return array<string,mixed>
     */
    private function scopeSafetyApplied(): array
    {
        return [
            'status' => 'applied',
            'reason' => null,
            'changed_files' => ['app/Foo.php'],
            'dirty_overlap' => [],
            'untracked_overlap' => [],
            'scope_safety' => [
                'safe' => true,
                'status' => 'clean',
                'dirty_files' => [],
                'untracked_files' => [],
                'dirty_overlap' => [],
                'untracked_overlap' => [],
                'modified_overlap' => [],
            ],
        ];
    }

    public function test_fair_scorecard_blocks_unverified_even_when_decision_and_score_pass(): void
    {
        $scorecard = $this->fairScorecard([
            'run' => [
                'model_selection' => [
                    'selected_provider' => 'claude_cli',
                    'selected_model' => 'claude-opus-test',
                ],
                'fair_mode_result' => [
                    'status' => 'unverified',
                    'deterministic_gates_passed' => false,
                    'pass_without_human' => false,
                    'blocking_reasons' => ['quality_status_needs_review'],
                ],
            ],
        ], [
            'claude_only' => true,
            'require_pass_without_human' => true,
        ]);
        $evaluation = $this->evaluate($scorecard);

        $this->assertFalse($scorecard['passed']);
        $this->assertContains('fair_protocol_not_valid', $scorecard['blocking_reasons']);
        $this->assertContains('deterministic_gates_not_passed', $scorecard['blocking_reasons']);
        $this->assertFalse($evaluation['passed']);
        $this->assertStringContainsString('fair_scorecard_failed', (string) $evaluation['failure_summary']);
    }

    public function test_fair_scorecard_rejects_loose_opus_substring_model(): void
    {
        $scorecard = $this->fairScorecard([
            'run' => [
                'model_selection' => [
                    'selected_provider' => 'claude_cli',
                    'selected_model' => 'not-opus-but-not-locked',
                ],
                'fair_mode_result' => [
                    'status' => 'valid',
                    'deterministic_gates_passed' => true,
                    'pass_without_human' => true,
                    'blocking_reasons' => [],
                ],
            ],
        ], [
            'claude_only' => true,
            'require_pass_without_human' => true,
        ]);

        $this->assertFalse($scorecard['passed']);
        $this->assertContains('model_not_locked_to_opus', $scorecard['blocking_reasons']);
    }

    public function test_non_fair_benchmark_keeps_existing_decision_and_score_evaluation(): void
    {
        $scorecard = $this->fairScorecard([
            'run' => [
                'model_selection' => [
                    'selected_provider' => 'codex_cli',
                    'selected_model' => 'gpt-5.5',
                ],
            ],
        ], []);
        $evaluation = $this->evaluate($scorecard);

        $this->assertNull($scorecard);
        $this->assertTrue($evaluation['passed']);
    }

    public function test_harness_runner_claude_only_implies_all_fair_flags(): void
    {
        $service = app(EngineeringHarnessRunnerService::class);
        $method = new ReflectionMethod(EngineeringHarnessRunnerService::class, 'fairModeOptions');
        $method->setAccessible(true);

        $flags = $method->invoke($service, ['claude_only' => true]);

        $this->assertTrue($flags['fair_mode']);
        $this->assertTrue($flags['single_provider']);
        $this->assertTrue($flags['no_decide']);
        $this->assertTrue($flags['fallback_disabled']);
        $this->assertTrue($flags['require_pass_without_human']);
    }

    public function test_harness_runner_explicit_fair_mode_implies_all_fair_flags(): void
    {
        $service = app(EngineeringHarnessRunnerService::class);
        $method = new ReflectionMethod(EngineeringHarnessRunnerService::class, 'fairModeOptions');
        $method->setAccessible(true);

        $flags = $method->invoke($service, ['fair_mode' => true]);

        $this->assertTrue($flags['fair_mode']);
        $this->assertTrue($flags['single_provider']);
        $this->assertTrue($flags['no_decide']);
        $this->assertTrue($flags['fallback_disabled']);
        $this->assertTrue($flags['require_pass_without_human']);
    }

    public function test_harness_runner_partial_fair_flag_implies_full_fair_mode(): void
    {
        $service = app(EngineeringHarnessRunnerService::class);
        $method = new ReflectionMethod(EngineeringHarnessRunnerService::class, 'fairModeOptions');
        $method->setAccessible(true);

        $flags = $method->invoke($service, [
            'single_provider' => true,
            'require_pass_without_human' => false,
        ]);

        $this->assertTrue($flags['fair_mode']);
        $this->assertTrue($flags['single_provider']);
        $this->assertTrue($flags['no_decide']);
        $this->assertTrue($flags['fallback_disabled']);
        $this->assertTrue($flags['require_pass_without_human']);
    }

    public function test_benchmark_service_partial_fair_flag_implies_full_fair_defaults(): void
    {
        $service = app(EngineeringBenchmarkService::class);
        $method = new ReflectionMethod(EngineeringBenchmarkService::class, 'withFairClaudeDefaults');
        $method->setAccessible(true);

        $options = $method->invoke($service, [
            'fallback_disabled' => true,
            'require_pass_without_human' => false,
        ]);

        $this->assertTrue($options['fair_mode']);
        $this->assertTrue($options['single_provider']);
        $this->assertTrue($options['no_decide']);
        $this->assertTrue($options['fallback_disabled']);
        $this->assertTrue($options['require_pass_without_human']);
        $this->assertSame('claude_cli', $options['provider']);
        $this->assertSame('opus', $options['model']);
        $this->assertSame('fixed', $options['model_policy']);
    }

    public function test_harness_runner_fair_provider_request_locks_claude_opus(): void
    {
        $service = app(EngineeringHarnessRunnerService::class);
        $method = new ReflectionMethod(EngineeringHarnessRunnerService::class, 'fairProviderRequest');
        $method->setAccessible(true);

        $request = $method->invoke($service, null, null, 'history', ['fair_mode' => true]);

        $this->assertSame(['claude_cli', 'opus', 'fixed'], $request);
    }

    public function test_harness_runner_fair_provider_request_rejects_provider_drift(): void
    {
        $service = app(EngineeringHarnessRunnerService::class);
        $method = new ReflectionMethod(EngineeringHarnessRunnerService::class, 'fairProviderRequest');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fair_mode_violation');

        $method->invoke($service, 'codex_cli', null, 'fixed', ['fair_mode' => true]);
    }

    public function test_harness_runner_fair_provider_request_rejects_loose_opus_substring_model(): void
    {
        $service = app(EngineeringHarnessRunnerService::class);
        $method = new ReflectionMethod(EngineeringHarnessRunnerService::class, 'fairProviderRequest');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fair_mode_violation');

        $method->invoke($service, 'claude_cli', 'not-opus-but-not-locked', 'fixed', ['fair_mode' => true]);
    }

    public function test_claude_code_baseline_plan_resolves_opus_and_does_not_execute(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-baseline-plan-'.bin2hex(random_bytes(4));
        mkdir($workspace);

        try {
            $baseline = app(EngineeringClaudeCodeBaselineRunnerService::class)->capture(
                $this->benchmarkCase(),
                $this->taskModel(),
                [
                    'workspace' => $workspace,
                    'claude_code_baseline' => 'plan',
                    'claude_code_baseline_model' => 'opus',
                ],
            );

            $this->assertIsArray($baseline);
            $this->assertSame('planned', $baseline['status']);
            $this->assertFalse($baseline['executed']);
            $this->assertSame('claude_code_cli', $baseline['provider']);
            $this->assertStringContainsString('opus', strtolower((string) $baseline['model']));
            $this->assertNotEmpty($baseline['prompt_hash']);
            $this->assertNotEmpty($baseline['invocation_fingerprint']);
        } finally {
            @rmdir($workspace);
        }
    }

    public function test_claude_code_baseline_rejects_non_opus_model(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fair_mode_violation');

        app(EngineeringClaudeCodeBaselineRunnerService::class)->capture(
            $this->benchmarkCase(),
            $this->taskModel(),
            [
                'workspace' => sys_get_temp_dir(),
                'claude_code_baseline' => 'plan',
                'claude_code_baseline_model' => 'claude-sonnet-4-5',
            ],
        );
    }

    public function test_claude_code_baseline_rejects_loose_opus_substring_model(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fair_mode_violation');

        app(EngineeringClaudeCodeBaselineRunnerService::class)->capture(
            $this->benchmarkCase(),
            $this->taskModel(),
            [
                'workspace' => sys_get_temp_dir(),
                'claude_code_baseline' => 'plan',
                'claude_code_baseline_model' => 'not-opus-but-not-locked',
            ],
        );
    }

    public function test_claude_code_baseline_run_requires_separate_workspace(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires claude_code_baseline_workspace');

        app(EngineeringClaudeCodeBaselineRunnerService::class)->capture(
            $this->benchmarkCase(),
            $this->taskModel(),
            [
                'workspace' => sys_get_temp_dir(),
                'claude_code_baseline' => 'run',
                'claude_code_baseline_model' => 'opus',
            ],
        );
    }

    public function test_claude_code_baseline_run_uses_deterministic_gate_for_pass_without_human(): void
    {
        $atlasWorkspace = sys_get_temp_dir().'/atlas-baseline-atlas-'.bin2hex(random_bytes(4));
        $workspace = sys_get_temp_dir().'/atlas-baseline-run-'.bin2hex(random_bytes(4));
        mkdir($atlasWorkspace);
        mkdir($workspace);

        try {
            $baseline = app(EngineeringClaudeCodeBaselineRunnerService::class)->capture(
                $this->benchmarkCase(),
                $this->taskModel(),
                [
                    'workspace' => $atlasWorkspace,
                    'claude_code_baseline_workspace' => $workspace,
                    'claude_code_baseline' => 'run',
                    'claude_code_baseline_model' => 'opus',
                    'claude_code_baseline_binary' => '/bin/echo',
                    'test_command' => PHP_BINARY.' -r "exit(0);"',
                ],
            );

            $this->assertSame('completed', $baseline['status']);
            $this->assertTrue($baseline['executed']);
            $this->assertSame('resolved', $baseline['decision']);
            $this->assertSame(100, $baseline['score']);
            $this->assertTrue($baseline['deterministic_gates_passed']);
            $this->assertTrue($baseline['pass_without_human']);
            $this->assertSame(0, $baseline['human_intervention_count']);
            $this->assertSame('passed', data_get($baseline, 'deterministic_gate.status'));
            $this->assertSame('claude_code_baseline_replay', data_get($baseline, 'replay_packet.kind'));
            $this->assertSame('claude_code_cli', data_get($baseline, 'replay_packet.provider_lock'));
            $this->assertSame('opus', data_get($baseline, 'replay_packet.model_lock'));
            $this->assertSame('stdin', data_get($baseline, 'replay_packet.input.transport'));
            $this->assertTrue((bool) data_get($baseline, 'replay_packet.deterministic_gate.command_present'));
            $this->assertTrue((bool) data_get($baseline, 'replay_packet.deterministic_gate.pass_without_human_requires_gate_pass'));
            $this->assertArrayNotHasKey('workspace', $baseline['replay_packet']);
        } finally {
            @rmdir($workspace);
            @rmdir($atlasWorkspace);
        }
    }

    public function test_claude_code_baseline_run_rejects_same_workspace_as_atlas_arm(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-baseline-same-workspace-'.bin2hex(random_bytes(4));
        mkdir($workspace);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('fair_mode_violation');

            app(EngineeringClaudeCodeBaselineRunnerService::class)->capture(
                $this->benchmarkCase(),
                $this->taskModel(),
                [
                    'workspace' => $workspace,
                    'claude_code_baseline_workspace' => $workspace,
                    'claude_code_baseline' => 'run',
                    'claude_code_baseline_model' => 'opus',
                    'claude_code_baseline_binary' => '/bin/echo',
                    'test_command' => PHP_BINARY.' -r "exit(0);"',
                ],
            );
        } finally {
            @rmdir($workspace);
        }
    }

    public function test_claude_code_baseline_run_allows_auto_prepared_git_worktree_under_workspace(): void
    {
        $atlasWorkspace = sys_get_temp_dir().'/atlas-baseline-parent-'.bin2hex(random_bytes(4));
        $workspace = $atlasWorkspace.'/storage/app/engineering-worktrees/claude-code-baseline-case';
        mkdir($workspace, recursive: true);

        try {
            $baseline = app(EngineeringClaudeCodeBaselineRunnerService::class)->capture(
                $this->benchmarkCase(),
                $this->taskModel(),
                [
                    'workspace' => $atlasWorkspace,
                    'claude_code_baseline_workspace' => $workspace,
                    'claude_code_baseline_workspace_auto_prepared' => true,
                    'claude_code_baseline_workspace_isolation_type' => 'git_worktree',
                    'claude_code_baseline' => 'run',
                    'claude_code_baseline_model' => 'opus',
                    'claude_code_baseline_binary' => '/bin/echo',
                    'test_command' => PHP_BINARY.' -r "exit(0);"',
                ],
            );

            $this->assertSame('completed', $baseline['status']);
            $this->assertSame('resolved', $baseline['decision']);
            $this->assertTrue($baseline['pass_without_human']);
        } finally {
            @rmdir($workspace);
            @rmdir(dirname($workspace));
            @rmdir(dirname(dirname($workspace)));
            @rmdir(dirname(dirname(dirname($workspace))));
            @rmdir($atlasWorkspace);
        }
    }

    public function test_claude_code_baseline_run_without_test_command_is_unverified(): void
    {
        $atlasWorkspace = sys_get_temp_dir().'/atlas-baseline-atlas-'.bin2hex(random_bytes(4));
        $workspace = sys_get_temp_dir().'/atlas-baseline-unverified-'.bin2hex(random_bytes(4));
        mkdir($atlasWorkspace);
        mkdir($workspace);

        try {
            $baseline = app(EngineeringClaudeCodeBaselineRunnerService::class)->capture(
                $this->benchmarkCase(),
                $this->taskModel(),
                [
                    'workspace' => $atlasWorkspace,
                    'claude_code_baseline_workspace' => $workspace,
                    'claude_code_baseline' => 'run',
                    'claude_code_baseline_model' => 'opus',
                    'claude_code_baseline_binary' => '/bin/echo',
                ],
            );

            $this->assertSame('completed', $baseline['status']);
            $this->assertSame('unresolved', $baseline['decision']);
            $this->assertSame(0, $baseline['score']);
            $this->assertFalse($baseline['deterministic_gates_passed']);
            $this->assertFalse($baseline['pass_without_human']);
            $this->assertSame('skipped', data_get($baseline, 'deterministic_gate.status'));
            $this->assertContains('baseline_deterministic_gate_not_passed', $baseline['blocking_reasons']);
        } finally {
            @rmdir($workspace);
            @rmdir($atlasWorkspace);
        }
    }

    public function test_paired_scorecard_keeps_planned_baseline_inconclusive(): void
    {
        $scorecard = $this->pairedScorecard([
            'passed' => true,
            'failure_summary' => null,
        ], [
            'required' => true,
            'passed' => true,
            'attempt_count' => 2,
            'repair_attempt_count' => 1,
            'repair_used' => true,
            'converted_to_green' => true,
        ], [
            'enabled' => true,
            'status' => 'planned',
            'executed' => false,
            'provider' => 'claude_code_cli',
            'model' => 'claude-opus-test',
        ]);

        $this->assertSame('baseline_planned', $scorecard['comparison_status']);
        $this->assertFalse($scorecard['comparable']);
        $this->assertNull($scorecard['winner']);
        $this->assertContains('baseline_not_verified_pass', $scorecard['blocking_reasons']);
    }

    public function test_paired_scorecard_declares_atlas_winner_only_against_verified_baseline(): void
    {
        $scorecard = $this->pairedScorecard([
            'passed' => true,
            'failure_summary' => null,
        ], [
            'required' => true,
            'passed' => true,
            'attempt_count' => 2,
            'repair_attempt_count' => 1,
            'repair_used' => true,
            'converted_to_green' => true,
        ], [
            'enabled' => true,
            'status' => 'completed',
            'executed' => true,
            'provider' => 'claude_code_cli',
            'model' => 'claude-opus-test',
            'decision' => 'resolved',
            'score' => 88,
            'deterministic_gates_passed' => true,
            'pass_without_human' => true,
        ], atlasScore: 95);

        $this->assertSame('comparable', $scorecard['comparison_status']);
        $this->assertTrue($scorecard['comparable']);
        $this->assertSame('atlas', $scorecard['winner']);
        $this->assertSame(7, $scorecard['deltas']['score']);
        $this->assertSame('baseline_case', $scorecard['case']['case_code']);
        $this->assertTrue($scorecard['atlas']['pass_without_human']);
        $this->assertSame(2, $scorecard['atlas']['attempt_count']);
        $this->assertSame(1, $scorecard['atlas']['repair_attempt_count']);
        $this->assertTrue($scorecard['atlas']['converted_to_green']);
        $this->assertSame(0, $scorecard['atlas']['provider_violation_count']);
        $this->assertTrue($scorecard['claude_code_baseline']['verified']);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $runnerOptions
     * @return array<string,mixed>|null
     */
    private function fairScorecard(array $payload, array $runnerOptions): ?array
    {
        $service = app(EngineeringBenchmarkService::class);
        $method = new ReflectionMethod(EngineeringBenchmarkService::class, 'fairScorecard');
        $method->setAccessible(true);

        return $method->invoke($service, $payload, $runnerOptions);
    }

    /**
     * @param  array<string,mixed>|null  $fairScorecard
     * @return array<string,mixed>
     */
    private function evaluate(?array $fairScorecard): array
    {
        $case = new AtlasEngineeringBenchmarkCase;
        $case->expected_decision = 'resolved';
        $case->min_score = 85;

        $service = app(EngineeringBenchmarkService::class);
        $method = new ReflectionMethod(EngineeringBenchmarkService::class, 'evaluate');
        $method->setAccessible(true);

        return $method->invoke($service, $case, 'resolved', 90, $fairScorecard);
    }

    /**
     * @param  array<string,mixed>  $atlasEvaluation
     * @param  array<string,mixed>|null  $fairScorecard
     * @param  array<string,mixed>|null  $baseline
     * @return array<string,mixed>
     */
    private function pairedScorecard(array $atlasEvaluation, ?array $fairScorecard, ?array $baseline, int $atlasScore = 90): array
    {
        $service = app(EngineeringBenchmarkService::class);
        $method = new ReflectionMethod(EngineeringBenchmarkService::class, 'pairedScorecard');
        $method->setAccessible(true);

        return $method->invoke(
            $service,
            $this->benchmarkCase(),
            $atlasEvaluation,
            $fairScorecard,
            $baseline,
            'resolved',
            $atlasScore,
        );
    }

    private function benchmarkCase(): AtlasEngineeringBenchmarkCase
    {
        $case = new AtlasEngineeringBenchmarkCase;
        $case->id = 'case-test';
        $case->case_code = 'baseline_case';
        $case->title = 'Baseline case';
        $case->description = 'Implement a small benchmark task.';
        $case->task_contract_json = [
            'goal' => 'Implement a small benchmark task.',
            'acceptance_criteria' => ['Validation can run deterministically.'],
        ];

        return $case;
    }

    private function taskModel(): AtlasTask
    {
        $task = new AtlasTask;
        $task->id = 'task-test';
        $task->title = 'Implement a small benchmark task';
        $task->description = 'Task description';
        $task->minimum_viable_action = 'Make the smallest correct change.';
        $task->starter_step = 'Inspect the workspace.';

        return $task;
    }
}
