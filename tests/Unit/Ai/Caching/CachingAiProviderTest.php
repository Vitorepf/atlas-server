<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Caching;

use App\Models\AiJob;
use App\Models\AiProviderCostRate;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\Caching\AiCallCostExceededException;
use App\Services\Ai\Caching\AiCallCostGuard;
use App\Services\Ai\Caching\CachingAiProvider;
use App\Services\Ai\Caching\EfficiencyOutcomeRecorder;
use App\Services\Ai\Telemetry\AiCostEstimator;
use App\Services\Ai\Tokens\AtlasTokenEconomyBudgetPolicyService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Counting mock provider — increments a public $calls counter on EVERY real
 * run()/runStreaming() invocation so a test can prove, byte-exactly, whether
 * the inner provider was actually called or short-circuited by the cache.
 *
 * The result carries usage in metadata.usage.{prompt_tokens,completion_tokens}
 * — the shape the canonical AiCostEstimator::estimateProviderResult() actually
 * reads (providerResultUsage()) — so costSaved is recomputed from the REAL
 * stored tokens × the active rate, never a constant or a model self-report.
 * (Note: some emitters use usage.input_tokens/output_tokens instead; the
 * estimator does not read those, so a result lacking the prompt_tokens shape
 * degrades to a chars/4 estimate of the real output — still real, never faked.)
 */
class CountingProbeProvider implements AiProvider
{
    public int $calls = 0;

    public int $streamCalls = 0;

    /** @var list<string> */
    public array $streamEvents = [];

    public function __construct(
        private readonly string $output = 'real-output',
        private readonly int $inputTokens = 1000,
        private readonly int $outputTokens = 500,
        private readonly int $durationMs = 7,
    ) {}

    public function key(): string
    {
        return 'probe_cli';
    }

    public function run(AiJob $job, string $prompt): AiProviderResult
    {
        $this->calls++;

        return new AiProviderResult(
            true,
            $this->output,
            ['probe', '--run'],
            0,
            $this->durationMs,
            'stdout-text',
            '',
            null,
            null,
            ['usage' => ['prompt_tokens' => $this->inputTokens, 'completion_tokens' => $this->outputTokens], 'probe_marker' => 'X'],
        );
    }

    public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
    {
        $this->streamCalls++;
        if ($onEvent !== null) {
            $onEvent(['type' => 'delta', 'text' => 'a']);
            $onEvent(['type' => 'delta', 'text' => 'b']);
        }
        $this->streamEvents = ['a', 'b'];

        return $this->run($job, $prompt);
    }

    public function health(): AiProviderHealthCheck
    {
        return new AiProviderHealthCheck('probe_cli', 'ok', 'healthy');
    }
}

/**
 * A provider that always FAILS (ok === false) — to prove failures are never
 * cached.
 */
final class FailingProbeProvider implements AiProvider
{
    public int $calls = 0;

    public function key(): string
    {
        return 'probe_cli';
    }

    public function run(AiJob $job, string $prompt): AiProviderResult
    {
        $this->calls++;

        return new AiProviderResult(false, '', ['probe'], 1, 3, '', 'boom', 'E_FAIL', 'failed');
    }

    public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
    {
        return $this->run($job, $prompt);
    }

    public function health(): AiProviderHealthCheck
    {
        return new AiProviderHealthCheck('probe_cli', 'ok', 'healthy');
    }
}

/**
 * In-memory spy over the efficiency-outcome governor. Captures the `signals`
 * payload of every recordOutcome call so the cost ledger is asserted without a
 * DB. Never persists.
 */
final class SpyEfficiencyGovernor implements EfficiencyOutcomeRecorder
{
    /** @var list<array<string,mixed>> */
    public array $captured = [];

    public function recordOutcome(array $input): array
    {
        $this->captured[] = $input;

        return ['writes' => false, 'spy' => true];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function signalsFor(string $outcomeType): array
    {
        return array_values(array_map(
            static fn (array $row): array => is_array($row['signals'] ?? null) ? $row['signals'] : [],
            array_filter(
                $this->captured,
                static fn (array $row): bool => ($row['outcome_type'] ?? null) === $outcomeType,
            ),
        ));
    }
}

final class CachingAiProviderTest extends TestCase
{
    private string $store = 'array';

    protected function setUp(): void
    {
        parent::setUp();
        // Real but disposable store. NEVER RefreshDatabase; sqlite :memory:.
        Cache::store($this->store)->flush();
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function decorate(AiProvider $inner, SpyEfficiencyGovernor $spy, array $config = []): CachingAiProvider
    {
        return new CachingAiProvider(
            $inner,
            new AiCallCostGuard(new AtlasTokenEconomyBudgetPolicyService),
            $spy,
            app(AiCostEstimator::class),
            new AtlasTokenEconomyBudgetPolicyService,
            array_merge([
                'enabled' => true,
                'store' => $this->store,
                'ttl_seconds' => 3600,
                'max_ttl_seconds' => 86400,
                'record_outcomes' => false, // governor is the spy; no DB writes.
                'cacheable_kinds' => [],
                'cost_guard' => ['soft_units' => 0.0, 'hard_units' => 0.0],
            ], $config),
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $metadata
     */
    private function job(array $payload = [], array $metadata = [], string $kind = 'unit_probe', string $model = 'probe-model-1'): AiJob
    {
        $job = new AiJob;
        $job->kind = $kind;
        $job->provider = 'probe_cli';
        $job->model = $model;
        $job->payload = $payload;
        $job->metadata = $metadata;

        return $job;
    }

    private function seedRate(string $model, int $inPer1k, int $outPer1k): void
    {
        Schema::create('ai_provider_cost_rates', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('provider', 80);
            $table->string('model', 120);
            $table->unsignedInteger('input_microusd_per_1k');
            $table->unsignedInteger('output_microusd_per_1k');
            $table->string('currency', 8)->default('USD');
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamp('created_at')->nullable();
        });

        AiProviderCostRate::query()->create([
            'provider' => 'probe_cli',
            'model' => $model,
            'input_microusd_per_1k' => $inPer1k,
            'output_microusd_per_1k' => $outPer1k,
            'currency' => 'USD',
            'effective_from' => now()->subDay(),
            'effective_until' => null,
            'metadata' => [],
            'created_at' => now(),
        ]);
    }

    // ----------------------------------------------------------------------
    // (A) FAIL-ON-STUB: costSaved is REAL (tokens × rate), not a constant.
    // ----------------------------------------------------------------------
    public function test_cache_hit_records_real_cost_saved_from_actual_tokens_and_rate(): void
    {
        // input_microusd_per_1k = 2000, output = 8000.
        $this->seedRate('probe-model-1', 2000, 8000);

        $inner = new CountingProbeProvider(output: 'deterministic', inputTokens: 1000, outputTokens: 500);
        $spy = new SpyEfficiencyGovernor;
        $decorated = $this->decorate($inner, $spy);

        $job = $this->job(payload: ['cacheable' => true]);
        $prompt = 'compute 2 + 2 deterministically';

        $first = $decorated->run($job, $prompt);
        $this->assertSame(1, $inner->calls, 'MISS must invoke the inner provider exactly once.');
        $this->assertSame('deterministic', $first->output);

        $second = $decorated->run($job, $prompt);
        $this->assertSame(1, $inner->calls, 'HIT must NOT invoke the inner provider again.');
        $this->assertSame('deterministic', $second->output);
        $this->assertTrue((bool) ($second->metadata['probe_marker'] === 'X'));

        $signals = $spy->signalsFor('provider_response_cache_hit');
        $this->assertCount(1, $signals);

        // REAL microUSD = (1000/1000)*2000 + (500/1000)*8000 = 2000 + 4000 = 6000.
        $expectedMicrousd = (int) round((1000 / 1000) * 2000 + (500 / 1000) * 8000);
        $this->assertSame(6000, $expectedMicrousd);
        $this->assertSame($expectedMicrousd, $signals[0]['cost_saved_microusd']);
        $this->assertSame(1500, $signals[0]['result_tokens']);
        // Real provider-reported usage (usage.prompt_tokens/completion_tokens)
        // present + a rate row ⇒ METERED confidence (the most accurate tier).
        $this->assertSame(AiCostEstimator::COST_CONFIDENCE_METERED, $signals[0]['cost_confidence']);
    }

    public function test_cost_saved_scales_with_actual_result_tokens_not_a_constant(): void
    {
        $this->seedRate('probe-model-1', 2000, 8000);
        $spy = new SpyEfficiencyGovernor;

        // Bigger response (2000 in / 1000 out) must yield a DIFFERENT, larger cost.
        $inner = new CountingProbeProvider(output: 'bigger', inputTokens: 2000, outputTokens: 1000);
        $decorated = $this->decorate($inner, $spy);
        $job = $this->job(payload: ['cacheable' => true]);

        $decorated->run($job, 'prompt-big');
        $decorated->run($job, 'prompt-big'); // HIT

        $signals = $spy->signalsFor('provider_response_cache_hit');
        $this->assertCount(1, $signals);

        // (2000/1000)*2000 + (1000/1000)*8000 = 4000 + 8000 = 12000 (≠ the 6000 above).
        $this->assertSame(12000, $signals[0]['cost_saved_microusd']);
        $this->assertNotSame(6000, $signals[0]['cost_saved_microusd']);
    }

    public function test_cost_saved_is_null_when_no_rate_row_exists(): void
    {
        // No ai_provider_cost_rates table/row at all → honest degrade to null.
        if (Schema::hasTable('ai_provider_cost_rates')) {
            Schema::drop('ai_provider_cost_rates');
        }

        $spy = new SpyEfficiencyGovernor;
        $inner = new CountingProbeProvider(inputTokens: 1000, outputTokens: 500);
        $decorated = $this->decorate($inner, $spy);
        $job = $this->job(payload: ['cacheable' => true]);

        $decorated->run($job, 'p');
        $decorated->run($job, 'p'); // HIT

        $signals = $spy->signalsFor('provider_response_cache_hit');
        $this->assertCount(1, $signals);
        $this->assertNull($signals[0]['cost_saved_microusd'], 'No rate row ⇒ costSaved must be null, never a fabricated constant.');
        $this->assertSame(AiCostEstimator::COST_CONFIDENCE_UNKNOWN, $signals[0]['cost_confidence']);
    }

    // ----------------------------------------------------------------------
    // (B) FAIL-ON-STUB: a mutated keyed input MISSES — no stale hit.
    // ----------------------------------------------------------------------
    public function test_mutated_prompt_misses_cache_and_returns_fresh_result(): void
    {
        $spy = new SpyEfficiencyGovernor;
        // Distinct outputs per call so a stale hit would be detectable.
        $inner = new class extends CountingProbeProvider
        {
            public function run(AiJob $job, string $prompt): AiProviderResult
            {
                $this->calls++;

                return new AiProviderResult(
                    true,
                    'out-for:'.$prompt,
                    ['probe'],
                    0,
                    7,
                    'so',
                    '',
                    null,
                    null,
                    ['usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 500]],
                );
            }
        };
        $decorated = $this->decorate($inner, $spy);
        $job = $this->job(payload: ['cacheable' => true]);

        $decorated->run($job, 'prompt-A');
        $decorated->run($job, 'prompt-A'); // HIT
        $this->assertSame(1, $inner->calls);

        // Mutate ONE char of the prompt → different sha256 key → MISS.
        $mutated = $decorated->run($job, 'prompt-B');
        $this->assertSame(2, $inner->calls, 'Mutated prompt MUST miss and re-invoke inner.');
        $this->assertSame('out-for:prompt-B', $mutated->output, 'Must return the NEW result, not the stale one.');
    }

    public function test_mutated_payload_decoding_param_misses_cache(): void
    {
        $spy = new SpyEfficiencyGovernor;
        $inner = new CountingProbeProvider;
        $decorated = $this->decorate($inner, $spy);

        $jobA = $this->job(payload: ['cacheable' => true, 'temperature' => 0, 'top_p' => 0.1]);
        $decorated->run($jobA, 'same-prompt');
        $decorated->run($jobA, 'same-prompt'); // HIT
        $this->assertSame(1, $inner->calls);

        // Same prompt, different keyed decoding param (top_p) → MISS.
        $jobB = $this->job(payload: ['cacheable' => true, 'temperature' => 0, 'top_p' => 0.9]);
        $decorated->run($jobB, 'same-prompt');
        $this->assertSame(2, $inner->calls, 'Different decoding param MUST produce a different key and miss.');
    }

    // ----------------------------------------------------------------------
    // (C) FAIL-ON-STUB: non-cacheable jobs are NEVER cached.
    // ----------------------------------------------------------------------
    public function test_job_without_cacheable_signal_is_never_cached(): void
    {
        $spy = new SpyEfficiencyGovernor;
        $inner = new CountingProbeProvider;
        $decorated = $this->decorate($inner, $spy);

        $job = $this->job(); // no flag at all (default-deny)
        $decorated->run($job, 'p');
        $decorated->run($job, 'p');

        $this->assertSame(2, $inner->calls, 'Default job must call inner BOTH times (nothing cached).');
        $this->assertSame([], $spy->signalsFor('provider_response_cache_hit'));
    }

    public function test_stateful_job_is_never_cached_even_with_cacheable_flag(): void
    {
        $spy = new SpyEfficiencyGovernor;
        $inner = new CountingProbeProvider;
        $decorated = $this->decorate($inner, $spy);

        // Hard exclusion wins over the opt-in flag.
        $job = $this->job(payload: ['cacheable' => true, 'stateful' => true]);
        $decorated->run($job, 'p');
        $decorated->run($job, 'p');

        $this->assertSame(2, $inner->calls);
        $this->assertSame([], $spy->signalsFor('provider_response_cache_hit'));
    }

    public function test_agent_reasoning_job_is_never_cached(): void
    {
        $spy = new SpyEfficiencyGovernor;
        $inner = new CountingProbeProvider;
        $decorated = $this->decorate($inner, $spy);

        $job = $this->job(payload: ['cacheable' => true], metadata: ['agent_reasoning' => true]);
        $decorated->run($job, 'p');
        $decorated->run($job, 'p');

        $this->assertSame(2, $inner->calls);
    }

    public function test_failed_result_is_never_cached(): void
    {
        $spy = new SpyEfficiencyGovernor;
        $inner = new FailingProbeProvider;
        $decorated = $this->decorate($inner, $spy);

        $job = $this->job(payload: ['cacheable' => true]);
        $r1 = $decorated->run($job, 'p');
        $r2 = $decorated->run($job, 'p');

        $this->assertFalse($r1->ok);
        $this->assertSame(2, $inner->calls, 'A failure (ok=false) must never be stored/served from cache.');
        $this->assertSame([], $spy->signalsFor('provider_response_cache_hit'));
    }

    public function test_allow_listed_kind_is_cacheable(): void
    {
        $spy = new SpyEfficiencyGovernor;
        $inner = new CountingProbeProvider;
        $decorated = $this->decorate($inner, $spy, ['cacheable_kinds' => ['deterministic_kind']]);

        // No explicit flag — eligibility comes purely from the allow-listed kind.
        $job = $this->job(kind: 'deterministic_kind');
        $decorated->run($job, 'p');
        $decorated->run($job, 'p'); // HIT

        $this->assertSame(1, $inner->calls);
        $this->assertCount(1, $spy->signalsFor('provider_response_cache_hit'));
    }

    public function test_zero_temperature_makes_job_cacheable(): void
    {
        $spy = new SpyEfficiencyGovernor;
        $inner = new CountingProbeProvider;
        $decorated = $this->decorate($inner, $spy);

        $job = $this->job(payload: ['temperature' => 0]);
        $decorated->run($job, 'p');
        $decorated->run($job, 'p'); // HIT

        $this->assertSame(1, $inner->calls);
    }

    // ----------------------------------------------------------------------
    // (D) FAIL-ON-STUB: hard-gate THROWS before the inner provider is called.
    // ----------------------------------------------------------------------
    public function test_hard_cost_gate_throws_before_invoking_inner(): void
    {
        $spy = new SpyEfficiencyGovernor;
        $inner = new CountingProbeProvider;
        // Tiny hard threshold; a long prompt + output budget pushes pre-cost over.
        $decorated = $this->decorate($inner, $spy, [
            'cost_guard' => ['soft_units' => 0.0, 'hard_units' => 0.0001],
        ]);

        $job = $this->job(payload: ['cacheable' => true], kind: 'unit_probe');
        $longPrompt = str_repeat('x', 5000);

        $threw = false;
        try {
            $decorated->run($job, $longPrompt);
        } catch (AiCallCostExceededException $e) {
            $threw = true;
            $this->assertGreaterThan($e->hardThresholdUnits, $e->preCostUnits);
            $this->assertSame('probe_cli', $e->providerKey);
        }

        $this->assertTrue($threw, 'Pre-cost over the hard threshold MUST throw.');
        $this->assertSame(0, $inner->calls, 'Provider must NEVER be invoked when the hard-gate fires — no spend.');

        $guardSignals = $spy->signalsFor('provider_call_cost_guard');
        $this->assertNotEmpty($guardSignals);
        $this->assertSame(AiCallCostGuard::OUTCOME_HARD_GATE, $guardSignals[0]['cost_guard']);
    }

    public function test_pre_cost_just_under_hard_threshold_does_not_throw(): void
    {
        $spy = new SpyEfficiencyGovernor;
        $inner = new CountingProbeProvider;
        // Generous hard threshold — a short prompt stays comfortably under it.
        $decorated = $this->decorate($inner, $spy, [
            'cost_guard' => ['soft_units' => 0.0, 'hard_units' => 1000.0],
        ]);

        $job = $this->job(payload: ['cacheable' => true]);
        $result = $decorated->run($job, 'short');

        $this->assertTrue($result->ok);
        $this->assertSame(1, $inner->calls, 'Under the hard threshold ⇒ inner IS called.');
    }

    public function test_soft_warn_records_telemetry_but_proceeds(): void
    {
        $spy = new SpyEfficiencyGovernor;
        $inner = new CountingProbeProvider;
        // Soft very low (warns), hard very high (never blocks).
        $decorated = $this->decorate($inner, $spy, [
            'cost_guard' => ['soft_units' => 0.00001, 'hard_units' => 1000.0],
        ]);

        $job = $this->job(payload: ['cacheable' => true]);
        $result = $decorated->run($job, str_repeat('y', 400));

        $this->assertTrue($result->ok);
        $this->assertSame(1, $inner->calls, 'Soft warn must NOT block — the call proceeds.');

        $guardSignals = $spy->signalsFor('provider_call_cost_guard');
        $this->assertNotEmpty($guardSignals);
        $this->assertSame(AiCallCostGuard::OUTCOME_SOFT_WARN, $guardSignals[0]['cost_guard']);
    }

    public function test_hard_gate_is_exempt_on_cache_hit(): void
    {
        $spy = new SpyEfficiencyGovernor;
        $inner = new CountingProbeProvider;
        // Hard threshold low enough that the MISS would normally throw — but we
        // prime the cache with a CHEAP first call, then the HIT must be exempt.
        $decorated = $this->decorate($inner, $spy, [
            'cost_guard' => ['soft_units' => 0.0, 'hard_units' => 1000.0],
        ]);
        $job = $this->job(payload: ['cacheable' => true]);
        $decorated->run($job, 'p'); // cheap MISS, populates cache
        $this->assertSame(1, $inner->calls);

        // Now tighten the gate and confirm the HIT path does not evaluate it.
        $decoratedTight = $this->decorate($inner, $spy, [
            'cost_guard' => ['soft_units' => 0.0, 'hard_units' => 0.0001],
        ]);
        $hit = $decoratedTight->run($job, 'p'); // must be a HIT, exempt from gate
        $this->assertSame(1, $inner->calls, 'A HIT spends ~nothing and is exempt from the hard-gate.');
        $this->assertSame('real-output', $hit->output);
    }

    // ----------------------------------------------------------------------
    // TRANSPARENCY adversary: decorated == bare on every non-HIT path.
    // ----------------------------------------------------------------------
    public function test_miss_is_byte_identical_to_bare_provider(): void
    {
        $spy = new SpyEfficiencyGovernor;
        $bare = new CountingProbeProvider(output: 'identical', inputTokens: 1000, outputTokens: 500);
        $innerForDecorated = new CountingProbeProvider(output: 'identical', inputTokens: 1000, outputTokens: 500);
        $decorated = $this->decorate($innerForDecorated, $spy);

        $job = $this->job(payload: ['cacheable' => true]);
        $bareResult = $bare->run($job, 'p');
        $decoratedResult = $decorated->run($job, 'p'); // COLD ⇒ MISS

        $this->assertResultFieldsIdentical($bareResult, $decoratedResult);
        $this->assertSame(1, $innerForDecorated->calls, 'MISS calls inner exactly once — no double-invoke, no swallow.');
    }

    public function test_non_cacheable_run_is_byte_identical_to_bare_provider(): void
    {
        $spy = new SpyEfficiencyGovernor;
        $bare = new CountingProbeProvider(output: 'identical');
        $inner = new CountingProbeProvider(output: 'identical');
        $decorated = $this->decorate($inner, $spy);

        $job = $this->job(); // not cacheable
        $bareResult = $bare->run($job, 'p');
        $decoratedResult = $decorated->run($job, 'p');

        $this->assertResultFieldsIdentical($bareResult, $decoratedResult);
        $this->assertSame(1, $inner->calls);
    }

    public function test_run_streaming_passes_through_and_is_never_cached(): void
    {
        $spy = new SpyEfficiencyGovernor;
        $bare = new CountingProbeProvider(output: 'identical');
        $inner = new CountingProbeProvider(output: 'identical');
        $decorated = $this->decorate($inner, $spy);

        // Even a "cacheable"-flagged job must NOT be cached on the streaming path.
        $job = $this->job(payload: ['cacheable' => true]);

        $bareEvents = [];
        $decoratedEvents = [];
        $bareResult = $bare->runStreaming($job, 'p', function (array $e) use (&$bareEvents): void {
            $bareEvents[] = $e['text'] ?? '';
        });
        $decoratedResult = $decorated->runStreaming($job, 'p', function (array $e) use (&$decoratedEvents): void {
            $decoratedEvents[] = $e['text'] ?? '';
        });

        $this->assertResultFieldsIdentical($bareResult, $decoratedResult);
        $this->assertSame(['a', 'b'], $bareEvents);
        $this->assertSame(['a', 'b'], $decoratedEvents, 'Streaming callback must forward the same events through the decorator.');
        $this->assertSame(1, $inner->streamCalls, 'runStreaming must hit the inner provider every time (never cached).');

        // A second streaming call must STILL hit the inner provider.
        $decorated->runStreaming($job, 'p');
        $this->assertSame(2, $inner->streamCalls);
        $this->assertSame([], $spy->signalsFor('provider_response_cache_hit'));
    }

    public function test_key_and_health_delegate_verbatim(): void
    {
        $spy = new SpyEfficiencyGovernor;
        $inner = new CountingProbeProvider;
        $decorated = $this->decorate($inner, $spy);

        $this->assertSame($inner->key(), $decorated->key());
        $this->assertSame($inner->health()->provider, $decorated->health()->provider);
        $this->assertSame($inner->health()->status, $decorated->health()->status);
    }

    public function test_globally_disabled_cache_is_pure_passthrough(): void
    {
        $spy = new SpyEfficiencyGovernor;
        $inner = new CountingProbeProvider;
        $decorated = $this->decorate($inner, $spy, ['enabled' => false]);

        $job = $this->job(payload: ['cacheable' => true]);
        $decorated->run($job, 'p');
        $decorated->run($job, 'p');

        $this->assertSame(2, $inner->calls, 'Disabled cache ⇒ every call passes straight to inner.');
        $this->assertSame([], $spy->signalsFor('provider_response_cache_hit'));
    }

    private function assertResultFieldsIdentical(AiProviderResult $a, AiProviderResult $b): void
    {
        $this->assertSame($a->ok, $b->ok);
        $this->assertSame($a->output, $b->output);
        $this->assertSame($a->command, $b->command);
        $this->assertSame($a->exitCode, $b->exitCode);
        $this->assertSame($a->durationMs, $b->durationMs);
        $this->assertSame($a->stdout, $b->stdout);
        $this->assertSame($a->stderr, $b->stderr);
        $this->assertSame($a->errorCode, $b->errorCode);
        $this->assertSame($a->errorMessage, $b->errorMessage);
        $this->assertSame($a->metadata, $b->metadata);
    }
}
