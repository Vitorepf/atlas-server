<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasDecide;

use App\Models\AiJob;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AtlasDecide\AtlasSwarmAutoFailoverService;
use App\Services\Ai\AtlasDecide\AtlasSwarmExecutorService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use Tests\TestCase;

class AtlasSwarmAutoFailoverServiceTest extends TestCase
{
    private function stubExecutor(): AtlasSwarmExecutorService
    {
        $u = uniqid('', true);
        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting(sys_get_temp_dir()."/atlas_failover_kernel_{$u}.jsonl");
        $feedback = new AtlasDecideLiveOutcomeFeedbackService;
        $feedback->setLogPathForTesting(sys_get_temp_dir()."/atlas_failover_feedback_{$u}.jsonl");
        $svc = new AtlasSwarmExecutorService($kernel, $feedback);
        $svc->setLogPathForTesting(sys_get_temp_dir()."/atlas_failover_exec_{$u}.jsonl");

        return $svc;
    }

    private function newService(?\Closure $dispatchResolver = null, ?AtlasSwarmExecutorService $executor = null): AtlasSwarmAutoFailoverService
    {
        // We need a concrete conductor even though we'll override dispatch.
        // Use the real container's binding.
        $conductor = $this->app->make(\App\Services\Ai\AtlasDecide\AtlasSwarmConductorService::class);
        $executor ??= $this->stubExecutor();
        $svc = new AtlasSwarmAutoFailoverService($conductor, $executor);
        if ($dispatchResolver !== null) {
            $svc->setDispatchResolverForTesting($dispatchResolver);
        }

        return $svc;
    }

    private function failureResult(): AiProviderResult
    {
        return new AiProviderResult(false, '', [], 1, 10, '', '', 'primary_failure');
    }

    private function ephemeralJob(): AiJob
    {
        $job = new AiJob;
        $job->kind = 'code_generation';
        $job->agent_slug = 'engineer';
        $job->prompt = 'fix bug';
        $job->input_text = 'fix bug';

        return $job;
    }

    public function test_returns_null_when_result_ok(): void
    {
        $svc = $this->newService();
        $ok = new AiProviderResult(true, 'ok', [], 0, 50, 'ok', '');
        $this->assertNull($svc->observeProviderFailure($this->ephemeralJob(), $ok));
    }

    public function test_returns_null_when_trigger_flag_off(): void
    {
        config(['atlas.patamar4.swarm_auto_failover_enabled' => false]);
        config(['atlas.patamar4.swarm_production_resolver_enabled' => true]);
        $svc = $this->newService();
        $this->assertNull($svc->observeProviderFailure($this->ephemeralJob(), $this->failureResult()));
    }

    public function test_returns_null_when_resolver_flag_off(): void
    {
        config(['atlas.patamar4.swarm_auto_failover_enabled' => true]);
        config(['atlas.patamar4.swarm_production_resolver_enabled' => false]);
        $svc = $this->newService();
        $this->assertNull($svc->observeProviderFailure($this->ephemeralJob(), $this->failureResult()));
    }

    public function test_fires_swarm_when_both_flags_on_and_failure(): void
    {
        config(['atlas.patamar4.swarm_auto_failover_enabled' => true]);
        config(['atlas.patamar4.swarm_production_resolver_enabled' => true]);

        $executor = $this->stubExecutor();
        $executor->setResolver(function (array $arm): array {
            if ($arm['arm_id'] === 'a1') {
                return ['result' => 'success', 'latency_ms' => 100, 'quality_score' => 0.9, 'output' => 'winner'];
            }

            return ['result' => 'failure', 'latency_ms' => 50, 'quality_score' => null, 'output' => 'loss'];
        });
        $dispatchResolver = fn (): array => [
            'dispatch_id' => 'd_test',
            'arms' => [
                ['arm_id' => 'a1', 'rank' => 1, 'origin' => 'recommended', 'provider' => 'claude_cli', 'model' => 'opus'],
                ['arm_id' => 'a2', 'rank' => 2, 'origin' => 'runner_up', 'provider' => 'codex_cli', 'model' => 'gpt'],
            ],
        ];

        $svc = $this->newService($dispatchResolver, $executor);
        $result = $svc->observeProviderFailure($this->ephemeralJob(), $this->failureResult());

        $this->assertNotNull($result);
        $this->assertTrue($result->ok);
        $this->assertSame('a1', $result->metadata['winner_arm_id']);
        $this->assertSame('claude_cli', $result->metadata['winner_provider']);
        $this->assertTrue($result->metadata['swarm_failover']);
    }

    public function test_returns_null_when_dispatch_throws(): void
    {
        config(['atlas.patamar4.swarm_auto_failover_enabled' => true]);
        config(['atlas.patamar4.swarm_production_resolver_enabled' => true]);

        $svc = $this->newService(function () {
            throw new \RuntimeException('synthetic conductor failure');
        });
        $this->assertNull($svc->observeProviderFailure($this->ephemeralJob(), $this->failureResult()));
    }

    public function test_returns_null_when_no_winner(): void
    {
        config(['atlas.patamar4.swarm_auto_failover_enabled' => true]);
        config(['atlas.patamar4.swarm_production_resolver_enabled' => true]);

        $executor = $this->stubExecutor();
        $executor->setResolver(fn () => ['result' => 'failure', 'latency_ms' => 10, 'quality_score' => null, 'output' => 'all_fail']);

        $dispatchResolver = fn (): array => [
            'dispatch_id' => 'd_no_winner',
            'arms' => [
                ['arm_id' => 'a1', 'rank' => 1, 'origin' => 'recommended', 'provider' => 'x', 'model' => 'y'],
            ],
        ];

        $svc = $this->newService($dispatchResolver, $executor);
        $this->assertNull($svc->observeProviderFailure($this->ephemeralJob(), $this->failureResult()));
    }

    public function test_returns_null_when_dispatch_has_no_arms(): void
    {
        config(['atlas.patamar4.swarm_auto_failover_enabled' => true]);
        config(['atlas.patamar4.swarm_production_resolver_enabled' => true]);

        $svc = $this->newService(fn (): array => ['dispatch_id' => 'empty', 'arms' => []]);
        $this->assertNull($svc->observeProviderFailure($this->ephemeralJob(), $this->failureResult()));
    }

    public function test_claim_policy_provider_safe(): void
    {
        $cp = $this->newService()->claimPolicy();
        $this->assertFalse($cp['benchmark_claim_allowed']);
        $this->assertFalse($cp['rivals_claim_allowed']);
        $this->assertFalse($cp['superiority_claim_allowed']);
        $this->assertTrue($cp['provider_safe_only_enforced']);
    }
}
