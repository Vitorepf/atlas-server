<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Caching;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\Caching\AiCallCostGuard;
use App\Services\Ai\Caching\CachingAiProvider;
use App\Services\Ai\Caching\EfficiencyOutcomeRecorder;
use App\Services\Ai\Telemetry\AiCostEstimator;
use App\Services\Ai\Tokens\AtlasTokenEconomyBudgetPolicyService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * FAIL-CLOSED generalisation of the wrong-result adversary.
 *
 * The collision adversary proved ONE family (file attachments). The same hole
 * was named for codex tool_permissions/sandbox, hermes toolsets, model
 * reasoning-effort, cwd, and context_refs — every output-determining input the
 * real drivers fold out of $job->payload/metadata but a bare-prompt key ignored.
 *
 * The fix is a WHITELIST, not a per-field blacklist: a job is cacheable only if
 * its payload carries nothing beyond a vetted cache-neutral allowlist AND no
 * external-coupling key is present. So ALL of these families — including unknown
 * future fields — must be NON-cacheable by default, while a genuinely clean
 * deterministic job must still cache (no over-correction).
 *
 * Load-safe: sqlite-less (array cache), mock provider, ZERO real LLM/provider
 * calls, no RefreshDatabase.
 */
final class CachingAiProviderFailClosedTest extends TestCase
{
    private string $store = 'array';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::store($this->store)->flush();
    }

    private function decorate(AiProvider $inner): CachingAiProvider
    {
        $nullRecorder = new class implements EfficiencyOutcomeRecorder
        {
            public function recordOutcome(array $input): array
            {
                return ['writes' => false];
            }
        };

        return new CachingAiProvider(
            $inner,
            new AiCallCostGuard(new AtlasTokenEconomyBudgetPolicyService),
            $nullRecorder,
            app(AiCostEstimator::class),
            new AtlasTokenEconomyBudgetPolicyService,
            [
                'enabled' => true,
                'store' => $this->store,
                'record_outcomes' => false,
                'cost_guard' => ['soft_units' => 0.0, 'hard_units' => 0.0],
            ],
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $metadata
     */
    private function job(array $payload, array $metadata = []): AiJob
    {
        $job = new AiJob;
        $job->kind = 'unit_probe';
        $job->provider = 'probe_cli';
        $job->model = 'probe-model-1';
        $job->payload = $payload;
        $job->metadata = $metadata;

        return $job;
    }

    /**
     * Running the SAME job twice: a cacheable job hits on the 2nd call (inner
     * called once); a non-cacheable job re-invokes the inner (called twice).
     *
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $metadata
     */
    private function callsForRepeatedRun(array $payload, array $metadata = []): int
    {
        $inner = new FailClosedProbeProvider;
        $decorated = $this->decorate($inner);
        $job = $this->job($payload, $metadata);

        $decorated->run($job, 'identical bare prompt');
        $decorated->run($job, 'identical bare prompt');

        return $inner->calls;
    }

    public function test_codex_tool_permissions_make_job_non_cacheable(): void
    {
        $this->assertSame(2, $this->callsForRepeatedRun([
            'cacheable' => true,
            'tool_permissions' => ['codex_sandbox' => 'workspace-write'],
        ]), 'A job carrying tool_permissions couples output to tool state the key cannot capture — must NOT be cached.');
    }

    public function test_hermes_toolsets_make_job_non_cacheable(): void
    {
        $this->assertSame(2, $this->callsForRepeatedRun([
            'cacheable' => true,
            'hermes' => ['toolsets' => ['fs', 'web'], 'provider' => 'gpt-5.5'],
        ]), 'A hermes job folds toolsets/provider/worktree into the call — must NOT be cached.');
    }

    public function test_unknown_payload_field_denies_by_default_fail_closed(): void
    {
        // 'model_reasoning_effort' is in neither the safe allowlist nor the coupling
        // denylist — the WHITELIST must still deny it (a future field cannot silently
        // become a stale hit). This is the core fail-closed guarantee.
        $this->assertSame(2, $this->callsForRepeatedRun([
            'cacheable' => true,
            'model_reasoning_effort' => 'high',
        ]), 'An unknown/un-vetted payload field must DENY caching by default (fail-closed).');
    }

    public function test_context_refs_in_metadata_make_job_non_cacheable(): void
    {
        $this->assertSame(2, $this->callsForRepeatedRun(
            ['cacheable' => true],
            ['context_refs' => ['doc:atlas-cognition', 'doc:forge']],
        ), 'Injected context (in metadata) is output-determining — must NOT be cached.');
    }

    public function test_clean_deterministic_job_still_caches_no_over_correction(): void
    {
        // The fix must not over-correct: a genuinely clean cacheable job (payload
        // only cache-neutral keys) still caches — 2nd identical call HITS.
        $this->assertSame(1, $this->callsForRepeatedRun([
            'cacheable' => true,
            'temperature' => 0,
            'max_tokens' => 500,
        ]), 'A clean deterministic job must still cache (inner called once across two identical runs).');
    }
}

/**
 * Minimal provider that counts real invocations; output is a pure function of the
 * bare prompt (so any cache HIT is observable purely via the call counter).
 */
final class FailClosedProbeProvider implements AiProvider
{
    public int $calls = 0;

    public function key(): string
    {
        return 'probe_cli';
    }

    public function run(AiJob $job, string $prompt): AiProviderResult
    {
        $this->calls++;

        return new AiProviderResult(
            true,
            'answer:'.$prompt,
            ['probe', '--run'],
            0,
            5,
            'stdout',
            '',
            null,
            null,
            ['usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50]],
        );
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
