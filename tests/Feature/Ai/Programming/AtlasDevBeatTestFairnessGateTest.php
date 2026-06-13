<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\AtlasDevBeatTestReportService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * L4-9 fairness gate (HARDENING).
 *
 * The honest end-state of the Atlas Dev vs Cursor/Claude beat-test is
 * COMPARABLE, not superior: Cursor genuinely won 2/3 medium tasks untuned, and
 * the only way Atlas "won" was by applying an Atlas-only, NON-DEFAULT runtime
 * tuning override (ACP transport + max_turns=1) that the external baseline could
 * not receive. A faster time produced by asymmetric tuning is not a clean
 * head-to-head superiority signal.
 *
 * This frozen test pins that the scorer is fail-closed against exactly that
 * fabrication vector: a faster Atlas win carrying Atlas-only non-default tuning
 * is downgraded to `unfair_atlas_only_tuning`, blocks the superiority claim, and
 * the report honestly stays comparable. It also proves the gate is NOT a blanket
 * block: a tuning declared as a shipped default (a fair, reproducible setting
 * the operator actually ships) auto-greens on the same fast wins.
 */
final class AtlasDevBeatTestFairnessGateTest extends TestCase
{
    private string $evidencePath;

    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $id = (string) Str::uuid();
        $this->evidencePath = storage_path("framework/testing/dev-beat-fairness-{$id}.json");
        $this->manifestPath = storage_path("framework/testing/dev-beat-fairness-backlog-{$id}.json");
    }

    protected function tearDown(): void
    {
        @File::delete($this->evidencePath);
        @File::delete($this->manifestPath);

        parent::tearDown();
    }

    public function test_faster_atlas_win_with_atlas_only_non_default_tuning_is_blocked_and_stays_comparable(): void
    {
        $tuning = [
            'hermes_execution_transport' => 'acp',
            'hermes_max_turns' => 1,
            'source' => 'measured_env_override_before_default_enablement',
        ];
        $this->writeEvidence([
            $this->comparableTask('bug', atlasDuration: 48, baselineDuration: 73, tuning: $tuning),
            $this->comparableTask('refactor', atlasDuration: 46, baselineDuration: 48, tuning: $tuning),
            $this->comparableTask('feature', atlasDuration: 60, baselineDuration: 90, tuning: $tuning),
        ]);

        $payload = $this->runReport();

        // Every task is mechanically a "win" (Atlas passed and was faster) ...
        $this->assertSame(3, $payload['summary']['comparable_external_count']);
        $this->assertSame(3, $payload['summary']['atlas_win_count']);
        // ... but NONE are clean head-to-head wins; all three are tuning-tainted.
        $this->assertSame(0, $payload['summary']['atlas_head_to_head_win_count']);
        $this->assertSame(3, $payload['summary']['atlas_unfair_tuning_win_count']);

        // Fail-closed: the strongest claim is COMPARABLE, never superior.
        $this->assertSame('comparable_report_ready_no_superiority', $payload['status']);
        $this->assertTrue($payload['claim_policy']['external_comparison_claim_allowed']);
        $this->assertFalse($payload['claim_policy']['external_superiority_claim_allowed']);
        $this->assertFalse($payload['claim_policy']['external_head_to_head_superiority_claim_allowed']);
        $this->assertTrue($payload['claim_policy']['superiority_blocked_by_atlas_only_tuning']);
        $this->assertTrue($payload['claim_policy']['fair_comparison_required_for_superiority']);
        $this->assertStringContainsString('non-default runtime tuning', $payload['claim_policy']['honest_operator_answer']);

        $bug = $this->taskOf($payload, 'bug');
        $this->assertTrue($bug['comparison']['beats_external']);
        $this->assertSame('unfair_atlas_only_tuning', $bug['comparison']['win_kind']);
        $this->assertSame('atlas_faster_but_used_atlas_only_non_default_runtime_tuning', $bug['comparison']['reason']);
        $this->assertContains('atlas_only_non_default_runtime_tuning', $bug['comparison']['fairness_blockers']);
        $this->assertFalse($bug['fairness']['fair']);
    }

    public function test_shipped_default_tuning_is_a_fair_comparison_and_auto_greens(): void
    {
        // Identical fast wins, but the tuning is declared a SHIPPED DEFAULT (how
        // Atlas Dev actually ships to every run, not a one-off override). That is
        // a fair, reproducible comparison, so the gate must auto-green — proving
        // it blocks asymmetric NON-default tuning, not the mere presence of tuning.
        $tuning = [
            'hermes_execution_transport' => 'acp',
            'hermes_max_turns' => 1,
            'source' => 'shipped_default',
        ];
        $this->writeEvidence([
            $this->comparableTask('bug', atlasDuration: 48, baselineDuration: 73, tuning: $tuning),
            $this->comparableTask('refactor', atlasDuration: 46, baselineDuration: 48, tuning: $tuning),
            $this->comparableTask('feature', atlasDuration: 60, baselineDuration: 90, tuning: $tuning),
        ]);

        $payload = $this->runReport();

        $this->assertSame('atlas_dev_beats_baseline', $payload['status']);
        $this->assertSame(3, $payload['summary']['atlas_head_to_head_win_count']);
        $this->assertSame(0, $payload['summary']['atlas_unfair_tuning_win_count']);
        $this->assertTrue($payload['claim_policy']['external_superiority_claim_allowed']);
        $this->assertFalse($payload['claim_policy']['superiority_blocked_by_atlas_only_tuning']);

        $bug = $this->taskOf($payload, 'bug');
        $this->assertSame('head_to_head', $bug['comparison']['win_kind']);
        $this->assertTrue($bug['fairness']['fair']);
    }

    public function test_no_tuning_at_all_is_a_fair_comparison_and_auto_greens(): void
    {
        // Baseline sanity: with no runtime_tuning block at all, fast wins are
        // genuine head-to-head wins. This guards against the gate accidentally
        // tainting clean evidence.
        $this->writeEvidence([
            $this->comparableTask('bug', atlasDuration: 48, baselineDuration: 73, tuning: null),
            $this->comparableTask('refactor', atlasDuration: 46, baselineDuration: 48, tuning: null),
            $this->comparableTask('feature', atlasDuration: 60, baselineDuration: 90, tuning: null),
        ]);

        $payload = $this->runReport();

        $this->assertSame('atlas_dev_beats_baseline', $payload['status']);
        $this->assertSame(3, $payload['summary']['atlas_head_to_head_win_count']);
        $this->assertSame(0, $payload['summary']['atlas_unfair_tuning_win_count']);
        $this->assertTrue($payload['claim_policy']['external_superiority_claim_allowed']);
    }

    /**
     * @return array<string,mixed>
     */
    private function runReport(): array
    {
        $exit = Artisan::call('atlas:dev:beat-test', [
            '--evidence' => $this->evidencePath,
            '--manifest-path' => $this->manifestPath,
            '--json' => true,
        ]);
        $output = Artisan::output();
        $this->assertSame(0, $exit, $output);

        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function taskOf(array $payload, string $type): array
    {
        foreach ((array) ($payload['tasks'] ?? []) as $task) {
            if (is_array($task) && ($task['task_type'] ?? null) === $type) {
                return $task;
            }
        }

        $this->fail("Task of type {$type} not found in report payload.");
    }

    /**
     * @param  list<array<string,mixed>>  $tasks
     */
    private function writeEvidence(array $tasks): void
    {
        File::ensureDirectoryExists(dirname($this->evidencePath));
        File::put($this->evidencePath, json_encode([
            'schema_version' => AtlasDevBeatTestReportService::EVIDENCE_SCHEMA_VERSION,
            'tasks' => $tasks,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string,mixed>|null  $tuning
     * @return array<string,mixed>
     */
    private function comparableTask(string $type, int $atlasDuration, int $baselineDuration, ?array $tuning): array
    {
        $atlas = [
            'executed' => true,
            'provider' => 'hermes_cli',
            'model' => 'gpt-5.5',
            'duration_seconds' => $atlasDuration,
            'tests_passed' => true,
            'scope_passed' => true,
            'changed_files' => ['app/Services/Ai/Programming/'.$type.'.php'],
            'validation_commands' => [
                ['command' => 'php artisan test tests/Feature/Ai/Programming/'.$type.'.php', 'exit_code' => 0],
            ],
            'evidence_refs' => ['receipt:'.$type],
        ];
        if ($tuning !== null) {
            $atlas['runtime_tuning'] = $tuning;
        }

        return [
            'id' => $type.'-tuned-001',
            'task_type' => $type,
            'title' => ucfirst($type).' tuned medium task',
            'difficulty' => 'medium',
            'target_path' => 'app/Services/Ai/Programming/AtlasDevBeatTestReportService.php',
            'atlas_dev' => $atlas,
            'baseline' => [
                'executed' => true,
                'provider' => 'cursor',
                'runner' => 'cursor',
                'model' => 'cursor-auto',
                'duration_seconds' => $baselineDuration,
                'tests_passed' => true,
                'scope_passed' => true,
                'changed_files' => ['app/Services/Ai/Programming/'.$type.'.php'],
                'validation_commands' => [
                    ['command' => 'php artisan test tests/Feature/Ai/Programming/'.$type.'.php', 'exit_code' => 0],
                ],
                'evidence_refs' => ['external-receipt:'.$type],
            ],
        ];
    }
}
