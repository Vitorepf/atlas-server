<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\Support;

use App\Services\Ai\Programming\AtlasCodeForgeFastPathService;
use App\Services\Ai\Programming\Support\ForgeFastPathReportSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * Pure Fast Path report sanitizer locks — no I/O, no FastPathService run(), no AWIS gate.
 */
final class ForgeFastPathReportSanitizerTest extends TestCase
{
    public function test_resolve_next_action_blockers_win(): void
    {
        $this->assertSame(
            'resolve_remaining_blockers',
            ForgeFastPathReportSanitizer::resolveNextAction(
                AtlasCodeForgeFastPathService::MODE_PREPARE_ONLY,
                ['execution_status' => 'passed'],
                ['workspace_missing'],
            ),
        );
    }

    public function test_resolve_next_action_by_mode(): void
    {
        $this->assertSame(
            'review_spec_plan_then_dispatch_forge',
            ForgeFastPathReportSanitizer::resolveNextAction(
                AtlasCodeForgeFastPathService::MODE_PREPARE_ONLY,
                [],
                [],
            ),
        );
        $this->assertSame(
            'open_atlas_code_review',
            ForgeFastPathReportSanitizer::resolveNextAction(
                AtlasCodeForgeFastPathService::MODE_EXECUTE_SYNC,
                ['execution_status' => 'passed'],
                [],
            ),
        );
        $this->assertSame(
            'inspect_remaining_blockers',
            ForgeFastPathReportSanitizer::resolveNextAction(
                AtlasCodeForgeFastPathService::MODE_EXECUTE_SYNC,
                ['execution_status' => 'failed'],
                [],
            ),
        );
        $this->assertSame(
            'poll_async_execution_and_open_review_when_passed',
            ForgeFastPathReportSanitizer::resolveNextAction(
                AtlasCodeForgeFastPathService::MODE_EXECUTE_ASYNC,
                [],
                [],
            ),
        );
    }

    public function test_compute_progress_empty_and_partial(): void
    {
        $this->assertSame(0, ForgeFastPathReportSanitizer::computeProgress([]));

        $stages = [
            ['name' => 'obra_binding', 'status' => 'passed'],
            ['name' => 'workspace_binding', 'status' => 'degraded'],
            ['name' => 'work_item_resolution', 'status' => 'blocked'],
        ];
        // 2 of 8 canonical stages → 25%
        $this->assertSame(25, ForgeFastPathReportSanitizer::computeProgress($stages));
    }

    public function test_resolve_current_stage_blocked_and_last(): void
    {
        $stages = [
            ['name' => 'obra_binding', 'status' => 'passed'],
            ['name' => 'workspace_binding', 'status' => 'blocked'],
            ['name' => 'work_item_resolution', 'status' => 'skipped'],
        ];
        $this->assertSame(
            'workspace_binding',
            ForgeFastPathReportSanitizer::resolveCurrentStage($stages, 'blocked'),
        );
        $this->assertSame(
            'work_item_resolution',
            ForgeFastPathReportSanitizer::resolveCurrentStage($stages, 'prepared'),
        );
        $this->assertSame(
            'obra_binding',
            ForgeFastPathReportSanitizer::resolveCurrentStage([], 'prepared'),
        );
    }

    public function test_truncate_string(): void
    {
        $this->assertSame('abc', ForgeFastPathReportSanitizer::truncateString('abc', 10));
        $this->assertSame('ab...', ForgeFastPathReportSanitizer::truncateString('abcdef', 2));
    }

    public function test_sanitize_value_truncates_strings_and_depth(): void
    {
        $long = str_repeat('x', 2005);
        $sanitized = ForgeFastPathReportSanitizer::sanitizeValueForReport($long);
        $this->assertIsString($sanitized);
        $this->assertSame(2003, strlen($sanitized)); // 2000 + '...'
        $this->assertStringEndsWith('...', $sanitized);

        // depth 0..5 nested arrays → leaf at depth 6 is truncated
        $nested = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => 'deep']]]]]]];
        $out = ForgeFastPathReportSanitizer::sanitizeValueForReport($nested);
        $this->assertSame(['truncated' => true, 'reason' => 'max_depth'], $out['a']['b']['c']['d']['e']['f']);
    }

    public function test_sanitize_value_max_items(): void
    {
        $items = [];
        for ($i = 0; $i < 130; $i++) {
            $items['k'.$i] = $i;
        }
        $out = ForgeFastPathReportSanitizer::sanitizeValueForReport($items);
        $this->assertTrue($out['truncated']);
        $this->assertSame('max_items', $out['truncated_reason']);
        $this->assertArrayHasKey('k0', $out);
        $this->assertArrayNotHasKey('k120', $out);
    }

    public function test_sanitize_stage_and_report_array_forms(): void
    {
        $stage = ForgeFastPathReportSanitizer::sanitizeStageForReport([
            'name' => 'obra_binding',
            'status' => 'passed',
            'project' => ['id' => 'p-1', 'title' => 'Title'],
            'work_item' => [
                'id' => 'wi-1',
                'code' => 'WI-1',
                'status' => 'ready',
                'current_stage' => 'spec',
                'risk_level' => 'low',
                'spec_hash' => 's',
                'plan_hash' => 'p',
                'tasks_json' => [['id' => 1], ['id' => 2]],
            ],
            'note' => 'ok',
        ]);

        $this->assertSame('p-1', $stage['project_id']);
        $this->assertSame('Title', $stage['project_title']);
        $this->assertArrayNotHasKey('project', $stage);
        $this->assertSame('wi-1', $stage['work_item']['id']);
        $this->assertSame(2, $stage['work_item']['task_count']);
        $this->assertSame('ok', $stage['note']);

        $report = ForgeFastPathReportSanitizer::sanitizeReportForStorage([
            'status' => 'prepared',
            'stages' => [
                ['name' => 'obra_binding', 'status' => 'passed'],
            ],
            'blob' => str_repeat('y', 10),
        ]);
        $this->assertSame('prepared', $report['status']);
        $this->assertSame('obra_binding', $report['stages'][0]['name']);
        $this->assertSame('yyyyyyyyyy', $report['blob']);
    }

    public function test_run_projection_pure_with_caller_clock(): void
    {
        $report = [
            'fast_path_run_id' => 'run-1',
            'obra_id' => 'obra-1',
            'mode' => AtlasCodeForgeFastPathService::MODE_PREPARE_ONLY,
            'status' => 'passed',
            'current_stage' => 'operator_next_action',
            'progress_percent' => 100,
            'updated_at' => '2026-01-01T00:00:00+00:00',
            'blockers' => ['x'],
            'evidence_refs' => ['e1'],
            'next_action' => 'done',
            'commands' => ['a' => 'b'],
        ];

        $run = ForgeFastPathReportSanitizer::runProjection($report, 'FALLBACK-NOW', 'fallback-id');

        $this->assertSame(AtlasCodeForgeFastPathService::RUN_SCHEMA_VERSION, $run['schema_version']);
        $this->assertSame('run-1', $run['fast_path_run_id']);
        $this->assertSame('obra-1', $run['obra_id']);
        $this->assertSame('2026-01-01T00:00:00+00:00', $run['updated_at']);
        $this->assertSame('2026-01-01T00:00:00+00:00', $run['completed_at']);
        $this->assertFalse($run['external_provider_call']);

        $running = ForgeFastPathReportSanitizer::runProjection(
            ['status' => 'queued'],
            'FALLBACK-NOW',
            'generated-id',
        );
        $this->assertSame('generated-id', $running['fast_path_run_id']);
        $this->assertSame('FALLBACK-NOW', $running['updated_at']);
        $this->assertNull($running['completed_at']);
    }
}
