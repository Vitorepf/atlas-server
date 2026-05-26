<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasDecide;

use App\Services\Ai\AtlasDecide\AtlasSwarmParallelDispatchService;
use Tests\TestCase;

class AtlasSwarmParallelDispatchServiceTest extends TestCase
{
    private string $log;

    private AtlasSwarmParallelDispatchService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = sys_get_temp_dir().'/atlas_swarm_parallel_'.uniqid('', true).'.jsonl';
        $this->svc = new AtlasSwarmParallelDispatchService;
        $this->svc->setLogPathForTesting($this->log);
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        parent::tearDown();
    }

    private function arms(): array
    {
        return [
            ['arm_id' => 'a1', 'rank' => 1, 'origin' => 'recommended', 'provider' => 'claude_cli', 'model' => 'opus'],
            ['arm_id' => 'a2', 'rank' => 2, 'origin' => 'runner_up', 'provider' => 'codex_cli', 'model' => 'gpt'],
            ['arm_id' => 'a3', 'rank' => 3, 'origin' => 'local_fallback', 'provider' => 'atlas_local', 'model' => 'm1'],
        ];
    }

    private function successBuilder(): callable
    {
        return function (array $arm): array {
            $json = json_encode([
                'result' => 'success',
                'latency_ms' => 50,
                'quality_score' => 0.8,
                'output' => 'ok_'.($arm['provider'] ?? ''),
            ]);

            return [PHP_BINARY, '-r', "echo '".$json."';"];
        };
    }

    public function test_unwired_returns_envelope_with_unwired_reason(): void
    {
        $env = $this->svc->dispatch($this->arms());
        $this->assertSame('unwired', $env['mode']);
        $this->assertStringContainsString('commandBuilder', $env['reason']);
        $this->assertSame(0, $env['outcome_count']);
    }

    public function test_empty_arms_returns_noop(): void
    {
        $this->svc->setCommandBuilder($this->successBuilder());
        $env = $this->svc->dispatch([]);
        $this->assertSame('noop_no_arms', $env['mode']);
        $this->assertSame(0, $env['outcome_count']);
    }

    public function test_serial_mode_when_flag_off(): void
    {
        config(['atlas.patamar4.swarm_parallel_enabled' => false]);
        $this->svc->setCommandBuilder($this->successBuilder());
        $env = $this->svc->dispatch($this->arms(), ['task_category' => 't', 'role' => 'r']);
        $this->assertSame('serial', $env['mode']);
        $this->assertSame(3, $env['outcome_count']);
        $this->assertFalse($env['flag_enabled']);
        foreach ($env['outcomes'] as $o) {
            $this->assertSame('success', $o['result']);
        }
    }

    public function test_parallel_mode_when_flag_on(): void
    {
        config(['atlas.patamar4.swarm_parallel_enabled' => true]);
        $this->svc->setCommandBuilder($this->successBuilder());
        $env = $this->svc->dispatch($this->arms(), ['task_category' => 't', 'role' => 'r']);
        $this->assertSame('parallel', $env['mode']);
        $this->assertSame(3, $env['outcome_count']);
        $this->assertTrue($env['flag_enabled']);
    }

    public function test_outcome_canonical_shape(): void
    {
        config(['atlas.patamar4.swarm_parallel_enabled' => false]);
        $this->svc->setCommandBuilder($this->successBuilder());
        $env = $this->svc->dispatch($this->arms());
        $first = $env['outcomes'][0];
        $this->assertArrayHasKey('arm_id', $first);
        $this->assertArrayHasKey('rank', $first);
        $this->assertArrayHasKey('result', $first);
        $this->assertArrayHasKey('latency_ms', $first);
        $this->assertArrayHasKey('quality_score', $first);
        $this->assertArrayHasKey('output_hash', $first);
        $this->assertStringStartsWith('sha256:', $first['output_hash']);
    }

    public function test_command_builder_returning_empty_records_failure(): void
    {
        config(['atlas.patamar4.swarm_parallel_enabled' => false]);
        $this->svc->setCommandBuilder(fn () => []);
        $env = $this->svc->dispatch([$this->arms()[0]]);
        $this->assertSame('failure', $env['outcomes'][0]['result']);
    }

    public function test_jsonl_persists_envelope_on_dispatch(): void
    {
        config(['atlas.patamar4.swarm_parallel_enabled' => false]);
        $this->svc->setCommandBuilder($this->successBuilder());
        $this->svc->dispatch($this->arms());
        $this->svc->dispatch($this->arms());
        $this->assertCount(2, $this->svc->listDispatches());
    }

    public function test_claim_policy_provider_safe(): void
    {
        $cp = $this->svc->claimPolicy();
        $this->assertFalse($cp['benchmark_claim_allowed']);
        $this->assertFalse($cp['rivals_claim_allowed']);
        $this->assertFalse($cp['superiority_claim_allowed']);
        $this->assertFalse($cp['external_rivals_certification_touched']);
        $this->assertTrue($cp['cognitive_immune_law_enforced']);
        $this->assertTrue($cp['provider_safe_only_enforced']);
    }

    public function test_envelope_carries_schema_and_dispatch_hash(): void
    {
        config(['atlas.patamar4.swarm_parallel_enabled' => false]);
        $this->svc->setCommandBuilder($this->successBuilder());
        $env = $this->svc->dispatch($this->arms());
        $this->assertSame('atlas.swarm.parallel_dispatch.v1', $env['schema_version']);
        $this->assertStringStartsWith('sha256:', $env['dispatch_hash']);
    }

    public function test_subprocess_emitting_failure_maps_to_failure(): void
    {
        config(['atlas.patamar4.swarm_parallel_enabled' => false]);
        $this->svc->setCommandBuilder(fn (): array => [PHP_BINARY, '-r', "echo '".json_encode(['result' => 'failure', 'latency_ms' => 10, 'output' => 'bad'])."';"]);
        $env = $this->svc->dispatch([$this->arms()[0]]);
        $this->assertSame('failure', $env['outcomes'][0]['result']);
    }

    public function test_subprocess_emitting_non_json_records_failure_with_reason(): void
    {
        config(['atlas.patamar4.swarm_parallel_enabled' => false]);
        $this->svc->setCommandBuilder(fn (): array => [PHP_BINARY, '-r', "echo 'plain text not json';"]);
        $env = $this->svc->dispatch([$this->arms()[0]]);
        $this->assertSame('failure', $env['outcomes'][0]['result']);
    }
}
