<?php

declare(strict_types=1);

namespace App\Services\Ai\Caching;

use App\Models\AiJob;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderHealthCheck;
use App\Services\Ai\AiProviderResult;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\RuntimeEfficiency\AtlasRuntimeEfficiencyGovernorService;
use App\Services\Ai\Telemetry\AiCostEstimator;
use App\Services\Ai\Tokens\AtlasTokenEconomyBudgetPolicyService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Additive, transparent decorator over a concrete {@see AiProvider}.
 *
 * It wraps the inner provider and changes NOTHING about the observable result
 * on every path except a genuine cacheable cache HIT. On a HIT it short-circuits
 * the synchronous {@see self::run()} call, returns a byte-identical
 * reconstruction of the previously stored {@see AiProviderResult} (same 9
 * positional fields) plus additive metadata keys ('cache_hit' => true,
 * 'cost_saved_microusd' => N), and books a REAL cost-saved figure into the
 * existing runtime-efficiency outcome ledger.
 *
 * Safety posture (the crux):
 *   - CONSERVATIVE cacheability, DEFAULT-DENY: a call is cached only when it is
 *     provably deterministic/idempotent via an explicit opt-in signal
 *     ({@see self::isCacheable()}). Absence of a signal ⇒ NOT cached.
 *   - {@see self::runStreaming()} is NEVER cached — it delegates straight to the
 *     inner provider. A wrong replay of an incremental/stateful stream is a
 *     correctness bug worse than the slowdown.
 *   - Failure results (ok === false) are NEVER stored.
 *   - FAIL-CLOSED cacheability: a job is cached only when its payload carries
 *     NOTHING beyond a vetted cache-neutral allowlist AND no key (payload or
 *     metadata) couples output to external/mutable state the hash cannot capture
 *     (a file PATH is not its CONTENT; cwd, tools and conversation/context state
 *     are environmental). Attachments, tool_permissions, hermes.*, context_refs,
 *     cwd, … therefore make a job NON-cacheable — unknown/new fields DENY by
 *     default. This is the conservative fix for the attachment/tool/context
 *     key-collision wrong-result a bare-prompt key would silently serve.
 *   - The key hashes the FULL job payload (+ provider key, model, kind, prompt,
 *     and the output-determining decoding params from metadata) via
 *     {@see MissionCanonicalHash::sha256()}, so two jobs differing in ANY payload
 *     field MISS rather than collide. It NEVER keys on, or gates by, any model
 *     self-reported confidence/score.
 *
 * costSaved honesty: it is the REAL estimated cost of the response we are NOT
 * re-fetching — actual result tokens × the active per-model rate, delegated to
 * the proven {@see AiCostEstimator::estimateProviderResult()} (microUSD). When
 * no rate row exists it honestly degrades to null, never a fabricated constant.
 *
 * NOTE (intended dormancy): cacheability defaults OFF. Until a caller opts a
 * deterministic jobtype in (payload.cacheable, a zero temperature, or a
 * configured allow-listed kind) the cache is dormant by design — a wrong hit is
 * worse than a miss. Do not auto-enable.
 *
 * NOTE (no atomic CAS on the file store): under a simultaneous burst of
 * identical jobs you can get a brief double real-call before the key is written.
 * That is acceptable for a deterministic job (same answer) on a single-operator
 * local runtime; this cache is an idempotency/cost optimization, not a
 * distributed lock.
 */
final class CachingAiProvider implements AiProvider
{
    /**
     * Payload keys that are PROVABLY cache-neutral — pure decoding/control params,
     * fully captured by the key. A cacheable job's payload must contain NOTHING
     * outside this allowlist; any other field denies (fail-closed on unknown/new).
     *
     * @var list<string>
     */
    private const CACHE_SAFE_PAYLOAD_KEYS = [
        'cacheable', 'cache_ttl_seconds',
        'temperature', 'top_p', 'top_k', 'max_tokens', 'seed', 'stop',
        'frequency_penalty', 'presence_penalty',
        'stateful', 'agent_reasoning',
    ];

    /**
     * Keys whose presence (in payload OR metadata) couples output to external /
     * mutable state a content hash cannot capture — a file PATH is not its CONTENT;
     * cwd, tools and conversation/context state are environmental. Presence ⇒ the
     * job is NON-cacheable regardless of any opt-in flag.
     *
     * @var list<string>
     */
    private const EXTERNAL_COUPLING_KEYS = [
        'attachments', 'files', 'images', 'file', 'image',
        'cwd', 'working_directory',
        'tools', 'toolsets', 'tool_permissions', 'codex_sandbox', 'mcp', 'skills', 'skills_activated',
        'hermes', 'worktree', 'resume', 'continue', 'conversation_id',
        'context_refs', 'context_pack', 'context', 'execution_plan', 'open_brain_injection',
    ];

    /**
     * Metadata keys that ARE output-determining decoding params — folded into the
     * key (the rest of metadata is volatile bookkeeping and is deliberately NOT
     * keyed, so it can't needlessly bust every entry).
     *
     * @var list<string>
     */
    private const METADATA_DECODING_PARAM_KEYS = ['temperature', 'top_p', 'top_k', 'max_tokens', 'seed', 'stop'];

    public function __construct(
        private readonly AiProvider $inner,
        private readonly AiCallCostGuard $costGuard,
        private readonly EfficiencyOutcomeRecorder $efficiencyGovernor,
        private readonly AiCostEstimator $costEstimator,
        private readonly AtlasTokenEconomyBudgetPolicyService $tokenEconomy,
        /** @var array<string,mixed> */
        private readonly array $config = [],
    ) {}

    public function key(): string
    {
        // Verbatim delegation — registry/identity semantics stay byte-identical.
        return $this->inner->key();
    }

    public function health(): AiProviderHealthCheck
    {
        // Verbatim delegation — wrapping must be invisible to health checks.
        return $this->inner->health();
    }

    public function runStreaming(AiJob $job, string $prompt, ?callable $onEvent = null): AiProviderResult
    {
        // Streaming is the hard-excluded, never-cached path — but it still SPENDS,
        // so the cost guard runs before it (G2: guard uniforme em toda chamada
        // real; no-op quando thresholds = 0, o default histórico).
        $this->enforceCostGuard($job, $prompt);

        return $this->inner->runStreaming($job, $prompt, $onEvent);
    }

    public function run(AiJob $job, string $prompt): AiProviderResult
    {
        if (! $this->cacheEnabled()) {
            // Cache globally disabled ⇒ pass-through SEM cache, mas o cost guard
            // continua valendo (G2: guard desacoplado do cache; no-op em 0/0).
            $this->enforceCostGuard($job, $prompt);

            return $this->inner->run($job, $prompt);
        }

        $cacheable = $this->isCacheable($job);

        // --- Cacheable HIT path (the cheap path; exempt from the hard-gate) ---
        if ($cacheable) {
            $key = $this->cacheKey($job, $prompt);
            $stored = $this->store()->get($key);
            if (is_array($stored)) {
                $hit = $this->rehydrate($stored);
                $this->recordCacheHit($job, $hit, $key);

                return $hit;
            }
        }

        // --- Real-call path: pre-cost guard runs BEFORE the inner provider ---
        $this->enforceCostGuard($job, $prompt);

        $result = $this->inner->run($job, $prompt);

        // Store ONLY for a cacheable, successful result. Never cache failures.
        if ($cacheable && $result->ok === true) {
            $this->store()->put(
                $this->cacheKey($job, $prompt),
                $this->dehydrate($result),
                $this->ttlSeconds($job),
            );
        }

        // Inner result returned UNCHANGED on MISS / non-cacheable.
        return $result;
    }

    /**
     * CONSERVATIVE, FAIL-CLOSED cacheability predicate (default = NOT cacheable).
     *
     * A job is cacheable only when ALL hold:
     *   (1) no 'stateful'/'agent_reasoning' signal (payload or metadata);
     *   (2) no EXTERNAL_COUPLING_KEYS present (payload or metadata) — attachments,
     *       files/images, cwd, tools/tool_permissions/codex_sandbox, hermes.*,
     *       skills/skills_activated, resume/continue, and the AiWorker-written
     *       context injection (context_refs/context_pack/execution_plan/
     *       open_brain_injection) — because the content hash cannot capture file
     *       CONTENT behind a path or external/conversation state;
     *   (3) every payload key is in CACHE_SAFE_PAYLOAD_KEYS (unknown/new fields
     *       deny by default — so a future provider field can't silently collide);
     *   (4) an explicit opt-in: payload/metadata 'cacheable' === true, OR a
     *       declared zero temperature, OR $job->kind in the configured allow-list.
     *
     * (Streaming is excluded structurally — runStreaming() bypasses the cache and
     * never reaches this method.)
     */
    private function isCacheable(AiJob $job): bool
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $metadata = is_array($job->metadata) ? $job->metadata : [];

        // (1) Hard structural exclusions — non-idempotent by nature.
        foreach (['stateful', 'agent_reasoning'] as $excluded) {
            if (data_get($payload, $excluded) === true || data_get($metadata, $excluded) === true) {
                return false;
            }
        }

        // (2) External-coupling guard — output depends on state the hash can't see.
        foreach (self::EXTERNAL_COUPLING_KEYS as $coupling) {
            if ($this->present(data_get($payload, $coupling)) || $this->present(data_get($metadata, $coupling))) {
                return false;
            }
        }

        // (3) Fail-closed payload allowlist — nothing beyond vetted cache-neutral keys.
        foreach (array_keys($payload) as $payloadKey) {
            if (! in_array((string) $payloadKey, self::CACHE_SAFE_PAYLOAD_KEYS, true)) {
                return false;
            }
        }

        // (4) Explicit opt-in (reached only once the job is proven payload-clean).
        if (data_get($payload, 'cacheable') === true || data_get($metadata, 'cacheable') === true) {
            return true;
        }

        if ($this->isZeroTemperature(data_get($payload, 'temperature'))
            || $this->isZeroTemperature(data_get($metadata, 'temperature'))) {
            return true;
        }

        $kind = (string) ($job->kind ?? '');
        if ($kind !== '' && in_array($kind, $this->cacheableKinds(), true)) {
            return true;
        }

        return false;
    }

    /** Non-null, non-empty (so a present-but-empty array/string/false does not deny). */
    private function present(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [] && $value !== false;
    }

    /**
     * Content-hash cache key over the FULL on-job request identity: provider key,
     * model, kind, the full prompt, the ENTIRE payload (not a cherry-picked
     * subset), and the output-determining decoding params from metadata. Two jobs
     * that share a bare prompt but differ in ANY payload field hash to DIFFERENT
     * keys ⇒ MISS, never a stale collision. Volatile metadata bookkeeping is
     * deliberately excluded so it can't needlessly bust every entry; isCacheable()
     * has already refused any job whose output couples to external state the hash
     * cannot capture, so what reaches here is a pure prompt-function.
     */
    private function cacheKey(AiJob $job, string $prompt): string
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $metadata = is_array($job->metadata) ? $job->metadata : [];

        $metadataDecoding = [];
        foreach (self::METADATA_DECODING_PARAM_KEYS as $param) {
            $metadataDecoding[$param] = data_get($metadata, $param);
        }

        $hash = MissionCanonicalHash::sha256([
            'provider' => $this->inner->key(),
            'model' => $job->model,
            'kind' => $job->kind,
            'prompt' => $prompt,
            'payload' => $payload,
            'metadata_decoding' => $metadataDecoding,
        ]);

        return $this->keyPrefix().$hash;
    }

    /**
     * Reconstruct the SAME positional AiProviderResult from stored scalars/arrays
     * and stamp additive cache metadata. The first 9 positional fields are
     * byte-identical to what the inner provider originally returned.
     */
    private function rehydrate(array $stored): AiProviderResult
    {
        $metadata = is_array($stored['metadata'] ?? null) ? $stored['metadata'] : [];

        return new AiProviderResult(
            (bool) ($stored['ok'] ?? false),
            (string) ($stored['output'] ?? ''),
            is_array($stored['command'] ?? null) ? $stored['command'] : [],
            $this->nullableInt($stored['exitCode'] ?? null),
            (int) ($stored['durationMs'] ?? 0),
            (string) ($stored['stdout'] ?? ''),
            (string) ($stored['stderr'] ?? ''),
            $this->nullableString($stored['errorCode'] ?? null),
            $this->nullableString($stored['errorMessage'] ?? null),
            $metadata,
        );
    }

    /**
     * Serializable projection of an AiProviderResult — explicit scalar/array
     * fields only, no PHP-version-fragile object serialization of the readonly
     * DTO.
     *
     * @return array<string,mixed>
     */
    private function dehydrate(AiProviderResult $result): array
    {
        return [
            'ok' => $result->ok,
            'output' => $result->output,
            'command' => $result->command,
            'exitCode' => $result->exitCode,
            'durationMs' => $result->durationMs,
            'stdout' => $result->stdout,
            'stderr' => $result->stderr,
            'errorCode' => $result->errorCode,
            'errorMessage' => $result->errorMessage,
            'metadata' => $result->metadata,
        ];
    }

    /**
     * On a HIT, compute the REAL cost saved (actual result tokens × active rate,
     * microUSD) by delegating to the proven AiCostEstimator, and record it into
     * the existing efficiency-outcome ledger via recordOutcome's signals. The
     * store is the response cache; the governor records the efficiency OUTCOME —
     * clean separation, no duplication.
     */
    private function recordCacheHit(AiJob $job, AiProviderResult $hit, string $key): void
    {
        $estimate = $this->costEstimator->estimateProviderResult($job, $hit);
        $costSavedMicrousd = $estimate['cost_microusd'] ?? null; // null when no rate row — honest under-claim.
        $promptTokens = (int) ($estimate['prompt_tokens'] ?? 0);
        $completionTokens = (int) ($estimate['completion_tokens'] ?? 0);

        // Secondary, normalized-unit savings (same currency as the cost guard).
        $costSavedUnits = $this->tokenEconomy->estimateCost($promptTokens, $completionTokens);

        $this->efficiencyGovernor->recordOutcome([
            'outcome_type' => 'provider_response_cache_hit',
            'status' => AtlasRuntimeEfficiencyGovernorService::STATUS_READY,
            'persist' => $this->persistOutcomes(),
            'signals' => [
                'cache_hit' => true,
                'provider' => $this->inner->key(),
                'model' => $job->model,
                'kind' => $job->kind,
                'cache_key' => $key,
                'cost_saved_microusd' => $costSavedMicrousd,
                'cost_saved_units' => $costSavedUnits,
                'result_tokens' => $promptTokens + $completionTokens,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'cost_confidence' => $estimate['cost_confidence'] ?? AiCostEstimator::COST_CONFIDENCE_UNKNOWN,
            ],
        ]);
    }

    /**
     * Run the per-operation pre-cost guard on the would-be-real-call path. Soft
     * breaches are recorded as telemetry and proceed; a hard breach throws
     * before the inner provider is invoked, so no spend happens.
     */
    private function enforceCostGuard(AiJob $job, string $prompt): void
    {
        [$soft, $hard] = $this->guardThresholds();
        if ($soft <= 0.0 && $hard <= 0.0) {
            return; // guard disabled (default) ⇒ no-op, fully backward compatible.
        }

        $evaluation = $this->costGuard->evaluate($job, $prompt, $soft, $hard);

        if ($evaluation['hard_exceeded']) {
            $this->efficiencyGovernor->recordOutcome([
                'outcome_type' => 'provider_call_cost_guard',
                'status' => AtlasRuntimeEfficiencyGovernorService::STATUS_BLOCKED,
                'persist' => $this->persistOutcomes(),
                'signals' => [
                    'cost_guard' => AiCallCostGuard::OUTCOME_HARD_GATE,
                    'provider' => $this->inner->key(),
                    'pre_cost_units' => $evaluation['pre_cost_units'],
                    'hard_threshold_units' => $evaluation['hard_threshold_units'],
                    'estimated_input_tokens' => $evaluation['estimated_input_tokens'],
                    'estimated_output_tokens' => $evaluation['estimated_output_tokens'],
                    'flow_id' => $evaluation['flow_id'],
                    'risk_level' => $evaluation['risk_level'],
                ],
            ]);

            throw new AiCallCostExceededException(
                preCostUnits: $evaluation['pre_cost_units'],
                hardThresholdUnits: $evaluation['hard_threshold_units'],
                providerKey: $this->inner->key(),
                estimatedInputTokens: $evaluation['estimated_input_tokens'],
                estimatedOutputTokens: $evaluation['estimated_output_tokens'],
            );
        }

        if ($evaluation['soft_warn']) {
            $this->efficiencyGovernor->recordOutcome([
                'outcome_type' => 'provider_call_cost_guard',
                'status' => AtlasRuntimeEfficiencyGovernorService::STATUS_WATCH,
                'persist' => $this->persistOutcomes(),
                'signals' => [
                    'cost_guard' => AiCallCostGuard::OUTCOME_SOFT_WARN,
                    'provider' => $this->inner->key(),
                    'pre_cost_units' => $evaluation['pre_cost_units'],
                    'soft_threshold_units' => $evaluation['soft_threshold_units'],
                    'flow_id' => $evaluation['flow_id'],
                    'risk_level' => $evaluation['risk_level'],
                ],
            ]);
        }
    }

    private function cacheEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    private function persistOutcomes(): bool
    {
        // recordOutcome itself table-availability-guards every write, so even when
        // persistence is requested it no-ops safely without the migration.
        return (bool) ($this->config['record_outcomes'] ?? true);
    }

    private function store(): CacheRepository
    {
        $storeName = $this->config['store'] ?? null;

        return is_string($storeName) && $storeName !== ''
            ? Cache::store($storeName)
            : Cache::store();
    }

    private function ttlSeconds(AiJob $job): int
    {
        $default = (int) ($this->config['ttl_seconds'] ?? 3600);
        $max = (int) ($this->config['max_ttl_seconds'] ?? 86400);

        $override = data_get($job->payload, 'cache_ttl_seconds');
        $ttl = is_numeric($override) ? (int) $override : $default;

        // Bound it so a stale deterministic answer can't live forever.
        return max(1, min($ttl, max(1, $max)));
    }

    private function keyPrefix(): string
    {
        $prefix = $this->config['key_prefix'] ?? 'atlas:ai:response_cache:';

        return is_string($prefix) ? $prefix : 'atlas:ai:response_cache:';
    }

    /**
     * @return list<string>
     */
    private function cacheableKinds(): array
    {
        $kinds = $this->config['cacheable_kinds'] ?? [];
        if (! is_array($kinds)) {
            return [];
        }

        return array_values(array_filter(
            $kinds,
            static fn ($k): bool => is_string($k) && $k !== '',
        ));
    }

    /**
     * @return array{0:float,1:float}
     */
    private function guardThresholds(): array
    {
        $guard = is_array($this->config['cost_guard'] ?? null) ? $this->config['cost_guard'] : [];

        return [
            (float) ($guard['soft_units'] ?? 0.0),
            (float) ($guard['hard_units'] ?? 0.0),
        ];
    }

    private function isZeroTemperature(mixed $value): bool
    {
        return is_numeric($value) && (float) $value === 0.0;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
