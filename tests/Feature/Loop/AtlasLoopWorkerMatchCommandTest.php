<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\WorkerSwarm\AtlasSelfConstructionWorkerAssignmentMatcher;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the worker assignment matcher is live at the operator surface: of two workers, only the one meeting
 * every requirement (capabilities, risk, scope, evidence, readiness) is eligible and chosen; the other is
 * listed ineligible with a reason.
 */
final class AtlasLoopWorkerMatchCommandTest extends TestCase
{
    private function match(array $task, array $workers): array
    {
        $exit = Artisan::call('atlas:loop:worker-match', [
            '--task' => (string) json_encode($task),
            '--workers' => (string) json_encode($workers),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_only_eligible_worker_is_chosen(): void
    {
        $task = [
            'task_packet_id' => 't1',
            'required_capabilities' => ['php'],
            'risk_class' => 'low',
            'allowed_files' => ['app/Foo.php'],
            'required_evidence_kinds' => ['tests_or_gates_result'],
        ];
        $eligibleWorker = [
            'worker_id' => 'wA',
            'capabilities' => ['php'],
            'max_risk_class' => 'high',
            'allowed_scope_prefixes' => ['app/'],
            'evidence_kinds_supported' => ['tests_or_gates_result'],
            'readiness' => ['ready' => true],
        ];
        $ineligibleWorker = [
            'worker_id' => 'wB',
            'capabilities' => [], // missing 'php'
            'max_risk_class' => 'high',
            'allowed_scope_prefixes' => ['app/'],
            'evidence_kinds_supported' => ['tests_or_gates_result'],
            'readiness' => ['ready' => true],
        ];

        ['exit' => $exit, 'd' => $d] = $this->match($task, [$eligibleWorker, $ineligibleWorker]);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasSelfConstructionWorkerAssignmentMatcher::SCHEMA, $d['schema_version']);
        $this->assertSame('wA', $d['chosen_worker'], (string) json_encode($d));
        $this->assertSame(1, $d['eligible_count']);
        $this->assertSame('wB', $d['ineligible'][0]['worker_id']);
        $this->assertNotEmpty($d['ineligible'][0]['reasons']);
    }

    public function test_no_eligible_worker_yields_null_choice(): void
    {
        ['d' => $d] = $this->match(
            ['task_packet_id' => 't1', 'required_capabilities' => ['rust']],
            [['worker_id' => 'wA', 'capabilities' => ['php'], 'readiness' => ['ready' => true]]],
        );

        $this->assertNull($d['chosen_worker']);
        $this->assertSame(0, $d['eligible_count']);
    }

    public function test_missing_task_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:worker-match', ['--workers' => '[]', '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
