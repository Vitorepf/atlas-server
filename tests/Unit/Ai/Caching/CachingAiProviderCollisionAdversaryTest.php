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
 * ADVERSARY A — WRONG RESULT.
 *
 * Real provider drivers (ClaudeCliProvider::runStreaming line 50-53,
 * CodexCliProvider, HermesCliProvider) fold OUTPUT-AFFECTING fields read from
 * $job->payload into the prompt actually sent to the model:
 *   $promptForProvider = withFileAttachmentInstructions(
 *       withImageAttachmentInstructions($prompt, $job), $job);
 * i.e. the real output depends on $job->payload['attachments'] (files/images),
 * codex 'model_reasoning_effort', 'tool_permissions.codex_sandbox',
 * hermes 'toolsets'/'skills'/'provider'/'worktree', and the job cwd.
 *
 * The cache key (CachingAiProvider::cacheKey) hashes ONLY
 *   provider, model, kind, prompt, temperature, top_p, max_tokens
 * — NOT attachments / reasoning_effort / toolsets / sandbox / cwd.
 *
 * Therefore two semantically-DIFFERENT jobs (same bare $prompt + same keyed
 * params, different attachments) collide to the SAME key. If both are flagged
 * cacheable (temperature 0 / cacheable:true — neither is excluded), the second
 * job receives the FIRST job's cached answer. That is a wrong/stale result the
 * conservative predicate is supposed to make impossible.
 */
final class CachingAiProviderCollisionAdversaryTest extends TestCase
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
                'ttl_seconds' => 3600,
                'max_ttl_seconds' => 86400,
                'record_outcomes' => false,
                'cacheable_kinds' => [],
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

    public function test_different_file_attachments_collide_and_return_a_wrong_cached_result(): void
    {
        // Inner provider faithfully mimics the real drivers: the EFFECTIVE prompt
        // (and thus output) includes the attached file paths from the payload —
        // exactly like ClaudeCliProvider::withFileAttachmentInstructions().
        $inner = new AttachmentAwareProbeProvider;
        $decorated = $this->decorate($inner);

        $prompt = 'Summarize the attached document.';

        // Job A: deterministic (cacheable) + attaches document A.
        $jobA = $this->job([
            'cacheable' => true,
            'attachments' => ['files' => [['path' => '/docs/quarterly-revenue.pdf']]],
        ]);

        // Job B: SAME bare prompt + SAME cacheability, attaches a DIFFERENT document.
        $jobB = $this->job([
            'cacheable' => true,
            'attachments' => ['files' => [['path' => '/docs/medical-history.pdf']]],
        ]);

        $resultA = $decorated->run($jobA, $prompt);
        $this->assertSame(1, $inner->calls, 'Job A is a cold MISS — inner called once.');
        $this->assertStringContainsString('quarterly-revenue.pdf', $resultA->output);

        $resultB = $decorated->run($jobB, $prompt);

        // What a CORRECT cache must do: Job B is a different request (different
        // attachment ⇒ different real output) and MUST miss → inner called again.
        $this->assertSame(
            2,
            $inner->calls,
            'WRONG-RESULT: Job B has different attachments and a different real '.
            'output, yet it collided to Job A\'s cache key and the inner provider '.
            'was NOT re-invoked. The cache served a stale, wrong answer.'
        );

        // And the served output must describe medical-history, not quarterly-revenue.
        $this->assertStringContainsString(
            'medical-history.pdf',
            $resultB->output,
            'WRONG-RESULT: Job B received Job A\'s cached answer about a DIFFERENT '.
            'document. Returned output references the wrong file.'
        );
    }

    /**
     * METADATA path is the one surface the fail-closed payload allowlist does NOT
     * police (it only iterates payload keys). The single guard there is the
     * EXTERNAL_COUPLING_KEYS denial. AiWorker writes context injection
     * (context_pack/open_brain_injection/execution_plan/skills_activated) into
     * $job->metadata, and cacheKey() deliberately hashes only the decoding params
     * from metadata — so a context copy the key cannot see must, on presence
     * alone, make the job non-cacheable. Two jobs with the SAME bare prompt + SAME
     * clean payload but DIFFERENT metadata injection would otherwise collide.
     */
    public function test_open_brain_injection_in_metadata_makes_job_non_cacheable(): void
    {
        $inner = new AttachmentAwareProbeProvider;
        $decorated = $this->decorate($inner);

        // Clean, opt-in payload — the only output-coupling lives in metadata.
        $job = $this->job(
            ['cacheable' => true],
            ['open_brain_injection' => ['prompt_section_hash' => 'sha-A', 'context_refs' => ['m1']]],
        );

        $decorated->run($job, 'same bare prompt');
        $decorated->run($job, 'same bare prompt');

        $this->assertSame(
            2,
            $inner->calls,
            'A job carrying open_brain_injection in metadata couples output to state '.
            'the cache key cannot see and MUST NOT be cached (default-deny on presence).'
        );
    }

    /**
     * OPEN-ENDED FIELD guard. Real drivers fold payload fields the key never sees
     * into the invocation — e.g. CodexCliProvider reads model_reasoning_effort.
     * Such a field is neither a hard exclusion nor a known coupling key, so the
     * defense that closes the hole is the fail-closed payload allowlist: any key
     * outside CACHE_SAFE_PAYLOAD_KEYS denies by default. A future/unknown
     * output-affecting field therefore degrades to a MISS, never a silent collision.
     */
    public function test_unknown_output_affecting_payload_field_denies_cache(): void
    {
        $inner = new AttachmentAwareProbeProvider;
        $decorated = $this->decorate($inner);

        // model_reasoning_effort changes the real codex invocation but is not on
        // the cache-neutral allowlist ⇒ fail-closed: the job is not cacheable.
        $job = $this->job(['cacheable' => true, 'model_reasoning_effort' => 'high']);

        $decorated->run($job, 'p');
        $decorated->run($job, 'p');

        $this->assertSame(
            2,
            $inner->calls,
            'An unrecognized output-affecting payload field must deny caching '.
            '(fail-closed allowlist), not silently collide.'
        );
    }
}

/**
 * Probe whose output depends on the attached file paths in $job->payload —
 * faithfully reproducing how the real CLI drivers fold attachments into the
 * effective prompt before invoking the model.
 */
final class AttachmentAwareProbeProvider implements AiProvider
{
    public int $calls = 0;

    public function key(): string
    {
        return 'probe_cli';
    }

    public function run(AiJob $job, string $prompt): AiProviderResult
    {
        $this->calls++;

        $files = data_get($job->payload, 'attachments.files', []);
        $paths = [];
        foreach (is_array($files) ? $files : [] as $file) {
            $p = is_array($file) ? ($file['path'] ?? null) : null;
            if (is_string($p) && $p !== '') {
                $paths[] = basename($p);
            }
        }

        // Effective prompt = bare prompt + attachment instructions (mirrors the
        // real driver). Output is a function of that effective prompt.
        $effective = $prompt.' [attachments: '.implode(',', $paths).']';

        return new AiProviderResult(
            true,
            'answer-about: '.implode(',', $paths).' :: '.$effective,
            ['probe', '--run'],
            0,
            7,
            'stdout',
            '',
            null,
            null,
            ['usage' => ['prompt_tokens' => 1000, 'completion_tokens' => 500]],
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
