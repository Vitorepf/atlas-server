<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Caching;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\Caching\AiCallCostExceededException;
use App\Services\Ai\Caching\AiCallCostGuard;
use App\Services\Ai\Caching\CachingAiProvider;
use App\Services\Ai\Caching\EfficiencyOutcomeRecorder;
use App\Services\Ai\Telemetry\AiCostEstimator;
use App\Services\Ai\Tokens\AtlasTokenEconomyBudgetPolicyService;
use Tests\TestCase;

/**
 * G2 — o cost guard desacoplado do cache: com cache.enabled=false o guard
 * continua valendo em TODA chamada real (run + streaming) de TODA stack que
 * resolve provider pelo AiProviderManager. Default 0/0 segue byte-idêntico.
 */
final class GuardProbeProvider implements AiProvider
{
    public int $calls = 0;

    public int $streamCalls = 0;

    public function key(): string
    {
        return 'probe_cli';
    }

    public function run(AiJob $job, string $prompt): AiProviderResult
    {
        $this->calls++;

        return new AiProviderResult(true, 'ok', ['probe'], 0, 3, 'out', '', null, null, []);
    }

    public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
    {
        $this->streamCalls++;

        return $this->run($job, $prompt);
    }

    public function health(): AiProviderHealthCheck
    {
        return new AiProviderHealthCheck('probe_cli', 'ok', 'healthy');
    }
}

final class GuardSpyGovernor implements EfficiencyOutcomeRecorder
{
    /** @var list<array<string,mixed>> */
    public array $captured = [];

    public function recordOutcome(array $input): array
    {
        $this->captured[] = $input;

        return ['writes' => false];
    }
}

final class CostGuardDecoupledFromCacheTest extends TestCase
{
    private function decorate(GuardProbeProvider $inner, GuardSpyGovernor $spy, float $soft, float $hard): CachingAiProvider
    {
        return new CachingAiProvider(
            $inner,
            new AiCallCostGuard(new AtlasTokenEconomyBudgetPolicyService),
            $spy,
            app(AiCostEstimator::class),
            new AtlasTokenEconomyBudgetPolicyService,
            [
                'enabled' => false, // CACHE OFF — o ponto da prova.
                'store' => 'array',
                'record_outcomes' => false,
                'cacheable_kinds' => [],
                'cost_guard' => ['soft_units' => $soft, 'hard_units' => $hard],
            ],
        );
    }

    private function job(): AiJob
    {
        $job = new AiJob;
        $job->kind = 'unit_probe';
        $job->provider = 'probe_cli';
        $job->payload = [];
        $job->metadata = [];

        return $job;
    }

    public function test_zero_thresholds_with_cache_off_is_pure_passthrough(): void
    {
        $inner = new GuardProbeProvider;
        $spy = new GuardSpyGovernor;

        $result = $this->decorate($inner, $spy, 0.0, 0.0)->run($this->job(), str_repeat('a', 4000));

        $this->assertTrue($result->ok);
        $this->assertSame(1, $inner->calls);
        $this->assertSame([], $spy->captured);
    }

    public function test_hard_gate_refuses_before_spend_even_with_cache_off(): void
    {
        $inner = new GuardProbeProvider;
        $spy = new GuardSpyGovernor;
        $wrapped = $this->decorate($inner, $spy, 0.0, 0.0001);

        try {
            $wrapped->run($this->job(), str_repeat('a', 40000));
            $this->fail('hard gate must refuse before the inner provider is invoked');
        } catch (AiCallCostExceededException) {
            // expected
        }

        $this->assertSame(0, $inner->calls, 'inner provider must NOT be called past the hard gate');
    }

    public function test_streaming_is_guarded_too(): void
    {
        $inner = new GuardProbeProvider;
        $spy = new GuardSpyGovernor;
        $wrapped = $this->decorate($inner, $spy, 0.0, 0.0001);

        $this->expectException(AiCallCostExceededException::class);

        try {
            $wrapped->runStreaming($this->job(), str_repeat('a', 40000));
        } finally {
            $this->assertSame(0, $inner->streamCalls);
        }
    }

    public function test_soft_warn_records_telemetry_and_still_calls_inner(): void
    {
        $inner = new GuardProbeProvider;
        $spy = new GuardSpyGovernor;

        $result = $this->decorate($inner, $spy, 0.0001, 0.0)->run($this->job(), str_repeat('a', 40000));

        $this->assertTrue($result->ok);
        $this->assertSame(1, $inner->calls, 'soft warn NEVER blocks');
        $warns = array_filter($spy->captured, fn (array $row): bool => ($row['outcome_type'] ?? null) === 'provider_call_cost_guard');
        $this->assertNotEmpty($warns, 'soft warn must record telemetry');
    }

    public function test_manager_wraps_when_only_guard_is_configured(): void
    {
        config([
            'atlas.ai.cache.enabled' => false,
            'atlas.ai.cache.cost_guard' => ['soft_units' => 3.0, 'hard_units' => 0.0],
        ]);

        $manager = app(AiProviderManager::class);
        $manager->registerDriver('probe_cli', new GuardProbeProvider);

        $this->assertInstanceOf(CachingAiProvider::class, $manager->get('probe_cli'));
    }

    public function test_manager_returns_inner_unchanged_when_guard_and_cache_are_off(): void
    {
        config([
            'atlas.ai.cache.enabled' => false,
            'atlas.ai.cache.cost_guard' => ['soft_units' => 0.0, 'hard_units' => 0.0],
        ]);

        $manager = app(AiProviderManager::class);
        $probe = new GuardProbeProvider;
        $manager->registerDriver('probe_cli', $probe);

        $this->assertSame($probe, $manager->get('probe_cli'));
    }
}
