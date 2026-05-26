<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasDecide;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AtlasDecide\AtlasSwarmExecutorService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use Tests\TestCase;

class AtlasSwarmExecutorServiceTest extends TestCase
{
    private string $log;

    private string $feedbackLog;

    private AtlasSwarmExecutorService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $u = uniqid('', true);
        $this->log = sys_get_temp_dir()."/atlas_swarm_exec_{$u}.jsonl";
        $this->feedbackLog = sys_get_temp_dir()."/atlas_swarm_exec_feedback_{$u}.jsonl";

        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting(sys_get_temp_dir()."/atlas_swarm_exec_kernel_{$u}.jsonl");

        $feedback = new AtlasDecideLiveOutcomeFeedbackService;
        $feedback->setLogPathForTesting($this->feedbackLog);

        $this->svc = new AtlasSwarmExecutorService($kernel, $feedback);
        $this->svc->setLogPathForTesting($this->log);
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        @unlink($this->feedbackLog);
        parent::tearDown();
    }

    private function dispatchEnvelope(): array
    {
        return [
            'dispatch_id' => 'test_dispatch_1',
            'arms' => [
                ['arm_id' => 'a1', 'rank' => 1, 'origin' => 'recommended', 'provider' => 'claude_cli', 'model' => 'opus'],
                ['arm_id' => 'a2', 'rank' => 2, 'origin' => 'runner_up', 'provider' => 'codex_cli', 'model' => 'gpt'],
                ['arm_id' => 'a3', 'rank' => 3, 'origin' => 'local_fallback', 'provider' => 'atlas_local', 'model' => 'm1'],
            ],
        ];
    }

    public function test_execute_requires_resolver_set(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->execute($this->dispatchEnvelope());
    }

    public function test_execute_requires_arms(): void
    {
        $this->svc->setResolver(fn () => ['result' => 'success']);
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->execute(['dispatch_id' => 'x', 'arms' => []]);
    }

    public function test_execute_runs_all_arms_and_records_outcomes(): void
    {
        $this->svc->setResolver(function (array $arm): array {
            return [
                'result' => 'success',
                'latency_ms' => 100 + (int) $arm['rank'] * 10,
                'quality_score' => 0.5 + (4 - (int) $arm['rank']) * 0.1,
                'output' => 'out_'.$arm['provider'],
            ];
        });
        $env = $this->svc->execute($this->dispatchEnvelope(), [
            'task_category' => 'code_generation',
            'role' => 'primary',
        ]);
        $this->assertSame(3, $env['arm_count']);
        $this->assertCount(3, $env['outcomes']);
        $this->assertSame('a1', $env['winner']['arm_id']);
    }

    public function test_envelope_carries_schema_and_hash(): void
    {
        $this->svc->setResolver(fn () => ['result' => 'success', 'latency_ms' => 100, 'quality_score' => 0.8, 'output' => 'x']);
        $env = $this->svc->execute($this->dispatchEnvelope(), ['task_category' => 't', 'role' => 'r']);
        $this->assertSame(AtlasSwarmExecutorService::ENVELOPE_SCHEMA, $env['schema_version']);
        $this->assertStringStartsWith('sha256:', $env['execution_hash']);
        $this->assertArrayHasKey('kernel_hash', $env);
    }

    public function test_tie_break_no_success_returns_no_winner(): void
    {
        $outcomes = [
            ['arm_id' => 'a1', 'rank' => 1, 'result' => 'failure'],
            ['arm_id' => 'a2', 'rank' => 2, 'result' => 'timeout'],
        ];
        $winner = $this->svc->tieBreak($outcomes);
        $this->assertSame(AtlasSwarmExecutorService::STATUS_NO_WINNER, $winner['result']);
        $this->assertNull($winner['arm_id']);
    }

    public function test_tie_break_picks_highest_quality(): void
    {
        $outcomes = [
            ['arm_id' => 'a1', 'rank' => 1, 'result' => 'success', 'quality_score' => 0.6, 'latency_ms' => 100],
            ['arm_id' => 'a2', 'rank' => 2, 'result' => 'success', 'quality_score' => 0.9, 'latency_ms' => 200],
            ['arm_id' => 'a3', 'rank' => 3, 'result' => 'success', 'quality_score' => 0.5, 'latency_ms' => 50],
        ];
        $winner = $this->svc->tieBreak($outcomes);
        $this->assertSame('a2', $winner['arm_id']);
    }

    public function test_tie_break_breaks_quality_ties_by_latency(): void
    {
        $outcomes = [
            ['arm_id' => 'a1', 'rank' => 1, 'result' => 'success', 'quality_score' => 0.8, 'latency_ms' => 300],
            ['arm_id' => 'a2', 'rank' => 2, 'result' => 'success', 'quality_score' => 0.8, 'latency_ms' => 100],
            ['arm_id' => 'a3', 'rank' => 3, 'result' => 'success', 'quality_score' => 0.8, 'latency_ms' => 200],
        ];
        $winner = $this->svc->tieBreak($outcomes);
        $this->assertSame('a2', $winner['arm_id']);
    }

    public function test_tie_break_breaks_latency_ties_by_rank(): void
    {
        $outcomes = [
            ['arm_id' => 'a1', 'rank' => 1, 'result' => 'success', 'quality_score' => 0.8, 'latency_ms' => 100],
            ['arm_id' => 'a2', 'rank' => 2, 'result' => 'success', 'quality_score' => 0.8, 'latency_ms' => 100],
        ];
        $winner = $this->svc->tieBreak($outcomes);
        $this->assertSame('a1', $winner['arm_id']);
    }

    public function test_execute_persists_append_only(): void
    {
        $this->svc->setResolver(fn () => ['result' => 'success', 'latency_ms' => 100, 'quality_score' => 0.7, 'output' => 'x']);
        $this->svc->execute($this->dispatchEnvelope(), ['task_category' => 't', 'role' => 'r']);
        $this->svc->execute($this->dispatchEnvelope(), ['task_category' => 't', 'role' => 'r']);
        $this->assertCount(2, $this->svc->listExecutions());
    }

    public function test_resolver_throwing_records_failure_outcome(): void
    {
        $this->svc->setResolver(function () {
            throw new \RuntimeException('synthetic resolver failure');
        });
        $env = $this->svc->execute($this->dispatchEnvelope(), ['task_category' => 't', 'role' => 'r']);
        foreach ($env['outcomes'] as $o) {
            $this->assertSame('failure', $o['result']);
        }
        $this->assertSame(AtlasSwarmExecutorService::STATUS_NO_WINNER, $env['winner']['result']);
    }

    public function test_status_constants_canon(): void
    {
        $this->assertSame('success', AtlasSwarmExecutorService::STATUS_SUCCESS);
        $this->assertSame('failure', AtlasSwarmExecutorService::STATUS_FAILURE);
        $this->assertSame('timeout', AtlasSwarmExecutorService::STATUS_TIMEOUT);
        $this->assertSame('no_winner', AtlasSwarmExecutorService::STATUS_NO_WINNER);
    }

    public function test_outcome_carries_output_hash(): void
    {
        $this->svc->setResolver(fn () => ['result' => 'success', 'latency_ms' => 100, 'quality_score' => 0.7, 'output' => 'real_output']);
        $env = $this->svc->execute($this->dispatchEnvelope(), ['task_category' => 't', 'role' => 'r']);
        $this->assertStringStartsWith('sha256:', $env['outcomes'][0]['output_hash']);
    }
}
