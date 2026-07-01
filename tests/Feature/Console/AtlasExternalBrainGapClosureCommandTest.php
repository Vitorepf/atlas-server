<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasExternalBrainGapClosureCommandTest extends TestCase
{
    private string $inputPath;

    protected function tearDown(): void
    {
        if (isset($this->inputPath) && is_file($this->inputPath)) {
            unlink($this->inputPath);
        }
        parent::tearDown();
    }

    private function writeInput(array $payload): string
    {
        $this->inputPath = tempnam(sys_get_temp_dir(), 'gap_closure_input_').'.json';
        file_put_contents($this->inputPath, (string) json_encode($payload));

        return $this->inputPath;
    }

    private function callCommand(array $options = []): array
    {
        $exitCode = Artisan::call('atlas:external-brain:gap-closure', $options);
        $raw = trim(Artisan::output());
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "Command output is not valid JSON:\n{$raw}");
        $decoded['__exit_code'] = $exitCode;

        return $decoded;
    }

    private function completeCycle(): array
    {
        return [
            'cycle_id'              => 'cy-1',
            'origination_receipt'   => ['task_packet_id' => 'task-001'],
            'attribution'           => ['owner' => 'worker-fyheho4s'],
            'implementation_result' => ['commit_sha' => 'abc123'],
            'runnable_evidence'     => ['command' => './vendor/bin/phpunit tests/FooTest.php', 'outcome' => 'OK'],
            'learning_update'       => ['pattern_family' => 'spec-quality', 'delta' => 0.1],
            'next_batch_constraint' => ['promoted_rule' => 'require_behavior_proof'],
        ];
    }

    private function readySpineSections(): array
    {
        return [
            ['name' => 'originator', 'ready' => true],
            ['name' => 'task_fabric', 'ready' => true],
        ];
    }

    public function test_bare_invocation_with_json_flag_exits_zero_and_returns_valid_json(): void
    {
        $result = $this->callCommand(['--json' => true]);

        $this->assertSame(0, $result['__exit_code']);
        $this->assertSame('ok', $result['status']);
    }

    public function test_output_includes_all_required_keys(): void
    {
        $result = $this->callCommand(['--json' => true]);

        foreach (['ready', 'missing_links', 'spine_sections', 'queue_repair_summary', 'outcome_feedback_summary', 'recommended_next_action'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function test_bare_invocation_without_evidence_is_not_ready_and_recommends_close_gap(): void
    {
        $result = $this->callCommand(['--json' => true]);

        $this->assertFalse($result['ready']);
        $this->assertSame('close_gap', $result['recommended_next_action']);
        $this->assertNotSame('create_more_detached_organs', $result['recommended_next_action']);
    }

    public function test_fully_ready_scenario_recommends_proceed_and_exits_zero(): void
    {
        $result = $this->callCommand([
            '--json'  => true,
            '--input' => $this->writeInput([
                'cycles'                   => [$this->completeCycle()],
                'spine_sections'           => $this->readySpineSections(),
                'queue_repair_summary'     => ['repaired' => 5, 'still_broken' => 0],
                'outcome_feedback_summary' => ['learned' => 3, 'leaks' => 0],
            ]),
        ]);

        $this->assertTrue($result['ready']);
        $this->assertSame(0, $result['__exit_code']);
        $this->assertNotSame('close_gap', $result['recommended_next_action']);
    }

    public function test_missing_links_from_incomplete_cycle_block_readiness_and_recommend_close_gap(): void
    {
        $result = $this->callCommand([
            '--json'  => true,
            '--input' => $this->writeInput([
                'cycles'                   => [array_merge($this->completeCycle(), ['learning_update' => null])],
                'spine_sections'           => $this->readySpineSections(),
                'queue_repair_summary'     => ['repaired' => 5, 'still_broken' => 0],
                'outcome_feedback_summary' => ['learned' => 3, 'leaks' => 0],
            ]),
        ]);

        $this->assertFalse($result['ready']);
        $this->assertNotEmpty($result['missing_links']);
        $this->assertSame('close_gap', $result['recommended_next_action']);
        // Reporter command, not a hard gate — still exits 0.
        $this->assertSame(0, $result['__exit_code']);
    }

    public function test_unready_spine_section_blocks_readiness(): void
    {
        $result = $this->callCommand([
            '--json'  => true,
            '--input' => $this->writeInput([
                'cycles'                   => [$this->completeCycle()],
                'spine_sections'           => [
                    ['name' => 'originator', 'ready' => true],
                    ['name' => 'maestro', 'ready' => false, 'reasons' => ['not_wired']],
                ],
                'queue_repair_summary'     => ['repaired' => 5, 'still_broken' => 0],
                'outcome_feedback_summary' => ['learned' => 3, 'leaks' => 0],
            ]),
        ]);

        $this->assertFalse($result['ready']);
        $this->assertSame('close_gap', $result['recommended_next_action']);
    }

    public function test_queue_still_broken_blocks_readiness(): void
    {
        $result = $this->callCommand([
            '--json'  => true,
            '--input' => $this->writeInput([
                'cycles'                   => [$this->completeCycle()],
                'spine_sections'           => $this->readySpineSections(),
                'queue_repair_summary'     => ['repaired' => 2, 'still_broken' => 1],
                'outcome_feedback_summary' => ['learned' => 3, 'leaks' => 0],
            ]),
        ]);

        $this->assertFalse($result['ready']);
        $this->assertSame(1, $result['queue_repair_summary']['still_broken']);
        $this->assertSame('close_gap', $result['recommended_next_action']);
    }

    public function test_outcome_leak_blocks_readiness(): void
    {
        $result = $this->callCommand([
            '--json'  => true,
            '--input' => $this->writeInput([
                'cycles'                   => [$this->completeCycle()],
                'spine_sections'           => $this->readySpineSections(),
                'queue_repair_summary'     => ['repaired' => 5, 'still_broken' => 0],
                'outcome_feedback_summary' => ['learned' => 3, 'leaks' => 2],
            ]),
        ]);

        $this->assertFalse($result['ready']);
        $this->assertSame(2, $result['outcome_feedback_summary']['leaks']);
        $this->assertSame('close_gap', $result['recommended_next_action']);
    }

    public function test_invalid_input_path_fails(): void
    {
        $exitCode = Artisan::call('atlas:external-brain:gap-closure', ['--input' => '/nonexistent/path.json']);

        $this->assertNotSame(0, $exitCode);
    }
}
