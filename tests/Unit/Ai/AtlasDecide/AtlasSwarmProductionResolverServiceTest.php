<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasDecide;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\AtlasDecide\AtlasSwarmProductionResolverService;
use Mockery;
use Tests\TestCase;

class AtlasSwarmProductionResolverServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function fakeProvider(callable $runHandler): AiProvider
    {
        return new class($runHandler) implements AiProvider
        {
            public function __construct(private $runHandler) {}

            public function key(): string
            {
                return 'fake';
            }

            public function run(AiJob $job, string $prompt): AiProviderResult
            {
                return ($this->runHandler)($job, $prompt);
            }

            public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
            {
                return $this->run($job, $prompt);
            }

            public function health(): AiProviderHealthCheck
            {
                return new AiProviderHealthCheck(true, 'ok', null);
            }
        };
    }

    private function managerReturning(AiProvider $provider): AiProviderManager
    {
        $mgr = Mockery::mock(AiProviderManager::class);
        $mgr->shouldReceive('get')->andReturn($provider);

        return $mgr;
    }

    private function arm(string $provider = 'claude_cli', int $rank = 1): array
    {
        return [
            'arm_id' => 'arm_'.$provider,
            'rank' => $rank,
            'origin' => 'recommended',
            'provider' => $provider,
            'model' => 'opus',
        ];
    }

    public function test_success_outcome_maps_provider_result_ok(): void
    {
        $provider = $this->fakeProvider(fn () => new AiProviderResult(
            ok: true,
            output: 'hello world',
            command: ['claude'],
            exitCode: 0,
            durationMs: 120,
            stdout: 'hello world',
            stderr: '',
            errorCode: null,
            errorMessage: null,
            metadata: ['quality_score' => 0.85],
        ));
        $svc = new AtlasSwarmProductionResolverService($this->managerReturning($provider));
        $out = $svc->resolve($this->arm(), ['input' => 'olá']);
        $this->assertSame('success', $out['result']);
        $this->assertSame(120, $out['latency_ms']);
        $this->assertSame(0.85, $out['quality_score']);
        $this->assertSame('hello world', $out['output']);
    }

    public function test_failure_outcome_when_provider_returns_not_ok(): void
    {
        $provider = $this->fakeProvider(fn () => new AiProviderResult(
            ok: false,
            output: '',
            command: ['claude'],
            exitCode: 1,
            durationMs: 80,
            stdout: '',
            stderr: 'bad request',
            errorCode: 'invalid_request',
        ));
        $svc = new AtlasSwarmProductionResolverService($this->managerReturning($provider));
        $out = $svc->resolve($this->arm(), ['input' => 'olá']);
        $this->assertSame('failure', $out['result']);
        $this->assertSame('invalid_request', $out['output']);
        $this->assertNull($out['quality_score']);
    }

    public function test_timeout_outcome_when_error_code_contains_timeout(): void
    {
        $provider = $this->fakeProvider(fn () => new AiProviderResult(
            ok: false,
            output: '',
            command: ['claude'],
            exitCode: 124,
            durationMs: 30000,
            stdout: '',
            stderr: '',
            errorCode: 'request_timeout',
        ));
        $svc = new AtlasSwarmProductionResolverService($this->managerReturning($provider));
        $out = $svc->resolve($this->arm(), ['input' => 'olá']);
        $this->assertSame('timeout', $out['result']);
        $this->assertSame('request_timeout', $out['output']);
    }

    public function test_provider_throwing_becomes_deterministic_failure(): void
    {
        $provider = $this->fakeProvider(fn () => throw new \RuntimeException('synthetic crash'));
        $svc = new AtlasSwarmProductionResolverService($this->managerReturning($provider));
        $out = $svc->resolve($this->arm(), ['input' => 'olá']);
        $this->assertSame('failure', $out['result']);
        $this->assertStringContainsString('provider_run_error', $out['output']);
    }

    public function test_circuit_opens_after_threshold_failures(): void
    {
        $provider = $this->fakeProvider(fn () => new AiProviderResult(
            ok: false, output: '', command: [], exitCode: 1, durationMs: 10, stdout: '', stderr: '',
            errorCode: 'fail'
        ));
        $svc = new AtlasSwarmProductionResolverService($this->managerReturning($provider), circuitThreshold: 2, circuitCooldownSeconds: 60);

        $svc->resolve($this->arm('claude_cli'), ['input' => 'x']);
        $svc->resolve($this->arm('claude_cli'), ['input' => 'x']);
        // 3rd call should short-circuit.
        $out = $svc->resolve($this->arm('claude_cli'), ['input' => 'x']);
        $this->assertSame('failure', $out['result']);
        $this->assertSame('circuit_open', $out['output']);
        $this->assertSame(0, $out['latency_ms']);
    }

    public function test_reset_circuit_clears_state(): void
    {
        $provider = $this->fakeProvider(fn () => new AiProviderResult(
            ok: false, output: '', command: [], exitCode: 1, durationMs: 10, stdout: '', stderr: '',
            errorCode: 'fail'
        ));
        $svc = new AtlasSwarmProductionResolverService($this->managerReturning($provider), circuitThreshold: 1);
        $svc->resolve($this->arm(), ['input' => 'x']);
        $this->assertNotEmpty($svc->circuitState());
        $svc->resetCircuit();
        $this->assertSame([], $svc->circuitState());
    }

    public function test_missing_provider_in_arm_returns_failure(): void
    {
        $svc = new AtlasSwarmProductionResolverService($this->managerReturning($this->fakeProvider(fn () => new AiProviderResult(true, '', [], 0, 0, '', ''))));
        $out = $svc->resolve(['arm_id' => 'a1', 'rank' => 1], ['input' => 'x']);
        $this->assertSame('failure', $out['result']);
        $this->assertSame('arm_missing_provider', $out['output']);
    }

    public function test_missing_context_input_returns_failure(): void
    {
        $svc = new AtlasSwarmProductionResolverService($this->managerReturning($this->fakeProvider(fn () => new AiProviderResult(true, '', [], 0, 0, '', ''))));
        $out = $svc->resolve($this->arm(), []);
        $this->assertSame('failure', $out['result']);
        $this->assertSame('context_missing_input', $out['output']);
    }

    public function test_as_closure_returns_callable_resolver(): void
    {
        $provider = $this->fakeProvider(fn () => new AiProviderResult(true, 'ok', [], 0, 50, 'ok', ''));
        $svc = new AtlasSwarmProductionResolverService($this->managerReturning($provider));
        $closure = $svc->asClosure();
        $out = $closure($this->arm(), ['input' => 'hi']);
        $this->assertSame('success', $out['result']);
    }

    public function test_provider_resolve_throw_records_failure(): void
    {
        $mgr = Mockery::mock(AiProviderManager::class);
        $mgr->shouldReceive('get')->andThrow(new \RuntimeException('no such provider'));
        $svc = new AtlasSwarmProductionResolverService($mgr);
        $out = $svc->resolve($this->arm(), ['input' => 'x']);
        $this->assertSame('failure', $out['result']);
        $this->assertStringContainsString('provider_resolve_error', $out['output']);
    }

    public function test_executor_back_compat_when_flag_off(): void
    {
        config(['atlas.patamar4.swarm_production_resolver_enabled' => false]);
        $executor = $this->app->make(\App\Services\Ai\AtlasDecide\AtlasSwarmExecutorService::class);
        // With flag OFF and no manual setResolver, executor should throw
        // when execute() is called — preserving its original contract.
        $this->expectException(\InvalidArgumentException::class);
        $executor->execute([
            'dispatch_id' => 'd',
            'arms' => [['arm_id' => 'a1', 'rank' => 1, 'provider' => 'claude_cli', 'model' => 'opus']],
        ]);
    }
}
