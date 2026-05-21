<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;

final class AtlasContextCompilerRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.aucri.context_compiler_runtime.v1';

    public const INPUT_SCHEMA = 'atlas.context.compiler_input.v1';

    public const COMPILED_PACK_SCHEMA = 'atlas.context.compiled_pack.v1';

    public const PROVIDER_PROFILE_SCHEMA = 'atlas.context.provider_profile.v1';

    public const LOSS_CHECK_SCHEMA = 'atlas.context.loss_check.v1';

    public const BUDGET_RECEIPT_SCHEMA = 'atlas.context.prompt_budget_receipt.v1';

    public function __construct(private readonly AtlasCognitiveMemoryFabricService $memoryFabric) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function compile(array $input = []): array
    {
        $provider = $this->provider((string) ($input['provider'] ?? 'gpt'));
        $risk = $this->risk((string) ($input['risk_level'] ?? 'low'));
        $profile = $this->providerProfile($provider, $risk);
        $segments = $this->segments($input);
        $memory = $this->memoryFabric->plan([
            'items' => array_map(static fn (array $segment): array => [
                'kind' => $segment['kind'],
                'ref' => $segment['ref'],
                'tokens' => $segment['tokens'],
                'bytes' => $segment['tokens'] * 640,
                'must_keep' => $segment['must_keep'],
                'heat' => $segment['priority'],
            ], $segments),
            'memory_available_bytes' => (int) ($input['memory_available_bytes'] ?? 12 * 1073741824),
            'repeated_tokens' => (int) ($input['repeated_tokens'] ?? 0),
        ]);
        [$included, $excluded] = $this->allocate($segments, (int) $profile['token_budget']);
        $compiledPack = $this->compiledPack($included, $excluded, $profile, $input, $memory);
        $lossCheck = $this->lossCheck($segments, $included, $excluded);
        $budgetReceipt = $this->budgetReceipt($segments, $included, $excluded, $profile);
        $status = (string) $lossCheck['status'] === 'passed' ? 'ready' : 'blocked';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'compiler_input' => [
                'schema_version' => self::INPUT_SCHEMA,
                'flow_id' => (string) ($input['flow_id'] ?? 'atlas.context.compile'),
                'provider' => $provider,
                'risk_level' => $risk,
                'segment_count' => count($segments),
                'input_hash' => MissionCanonicalHash::sha256($segments),
            ],
            'provider_profile' => $profile,
            'compiled_pack' => $compiledPack,
            'loss_check' => $lossCheck,
            'prompt_budget_receipt' => $budgetReceipt,
            'memory_ref' => [
                'schema_version' => AtlasCognitiveMemoryFabricService::SCHEMA_VERSION,
                'status' => (string) ($memory['status'] ?? 'unknown'),
                'cognitive_memory_hash' => (string) ($memory['cognitive_memory_hash'] ?? ''),
                'delta_receipt_hash' => (string) data_get($memory, 'delta_receipt.receipt_hash', ''),
            ],
            'claims' => [
                'providers_invoked' => false,
                'writes' => false,
                'rivals_run' => false,
                'benchmark_run' => false,
                'raw_text_exposed' => false,
            ],
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['context_compiler_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function providerProfile(string $provider, string $risk): array
    {
        $baseBudget = match ($provider) {
            'claude' => 12000,
            'gemini' => 20000,
            'local' => 6000,
            default => 10000,
        };
        $tokenBudget = (int) round($baseBudget * match ($risk) {
            'high' => 0.82,
            'irreversible' => 0.70,
            default => 1.0,
        });

        return [
            'schema_version' => self::PROVIDER_PROFILE_SCHEMA,
            'provider' => $provider,
            'risk_level' => $risk,
            'token_budget' => $tokenBudget,
            'format_strategy' => match ($provider) {
                'claude' => 'narrative_sections_with_evidence_refs',
                'gemini' => 'large_context_anchored_sections',
                'local' => 'compact_contract_first',
                default => 'objective_contract_with_acceptance_criteria',
            },
            'compression_strategy' => $risk === 'low' ? 'lossless_delta_then_summarize_optional' : 'lossless_must_keep_first',
            'must_keep_coverage_required' => 1.0,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<int,array<string,mixed>>
     */
    private function segments(array $input): array
    {
        $segments = (array) ($input['segments'] ?? []);
        if ($segments === []) {
            $segments = [
                ['kind' => 'decision', 'ref' => 'decision:current', 'tokens' => 800, 'priority' => 1.0, 'must_keep' => true],
                ['kind' => 'blocker', 'ref' => 'blocker:current', 'tokens' => 700, 'priority' => 0.98, 'must_keep' => true],
                ['kind' => 'dod', 'ref' => 'dod:current', 'tokens' => 500, 'priority' => 0.96, 'must_keep' => true],
                ['kind' => 'evidence', 'ref' => 'evidence:retrieval', 'tokens' => 2400, 'priority' => 0.82],
                ['kind' => 'memory', 'ref' => 'memory:recent', 'tokens' => 1800, 'priority' => 0.64],
                ['kind' => 'estimate', 'ref' => 'estimate:optional', 'tokens' => 1200, 'priority' => 0.42],
            ];
        }

        return array_values(array_map(fn (mixed $segment, int $index): array => $this->segment(is_array($segment) ? $segment : ['ref' => 'segment:'.$index], $index), $segments, array_keys($segments)));
    }

    /**
     * @param  array<string,mixed>  $segment
     * @return array<string,mixed>
     */
    private function segment(array $segment, int $index): array
    {
        $kind = (string) ($segment['kind'] ?? 'context');
        $ref = (string) ($segment['ref'] ?? $kind.':'.$index);
        $mustKeep = (bool) ($segment['must_keep'] ?? in_array($kind, ['decision', 'blocker', 'constraint', 'dod', 'risk', 'receipt'], true));
        $content = isset($segment['content']) && is_scalar($segment['content']) ? (string) $segment['content'] : $ref;

        return [
            'kind' => $kind,
            'ref' => $ref,
            'segment_hash' => MissionCanonicalHash::sha256([$kind, $ref, $content]),
            'content_hash' => MissionCanonicalHash::sha256($content),
            'tokens' => max(1, (int) ($segment['tokens'] ?? 1000)),
            'priority' => round(max(0.0, min(1.0, (float) ($segment['priority'] ?? ($mustKeep ? 1.0 : 0.5)))), 4),
            'must_keep' => $mustKeep,
            'category' => $this->category($kind),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $segments
     * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>}
     */
    private function allocate(array $segments, int $tokenBudget): array
    {
        usort($segments, static fn (array $a, array $b): int => [(bool) $b['must_keep'], (float) $b['priority']] <=> [(bool) $a['must_keep'], (float) $a['priority']]);

        $included = [];
        $excluded = [];
        $used = 0;
        foreach ($segments as $segment) {
            $tokens = (int) $segment['tokens'];
            if ((bool) $segment['must_keep'] || $used + $tokens <= $tokenBudget) {
                $included[] = $segment + ['include_reason' => (bool) $segment['must_keep'] ? 'must_keep' : 'within_provider_budget'];
                $used += $tokens;

                continue;
            }

            $excluded[] = $segment + ['exclude_reason' => 'budget_trim_optional'];
        }

        return [$included, $excluded];
    }

    /**
     * @param  array<int,array<string,mixed>>  $included
     * @param  array<int,array<string,mixed>>  $excluded
     * @param  array<string,mixed>  $profile
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $memory
     * @return array<string,mixed>
     */
    private function compiledPack(array $included, array $excluded, array $profile, array $input, array $memory): array
    {
        $sections = array_values(array_map(static fn (array $segment): array => [
            'kind' => $segment['kind'],
            'category' => $segment['category'],
            'segment_hash' => $segment['segment_hash'],
            'content_hash' => $segment['content_hash'],
            'tokens' => $segment['tokens'],
            'must_keep' => $segment['must_keep'],
            'include_reason' => $segment['include_reason'],
        ], $included));
        $pack = [
            'schema_version' => self::COMPILED_PACK_SCHEMA,
            'flow_id' => (string) ($input['flow_id'] ?? 'atlas.context.compile'),
            'provider' => (string) $profile['provider'],
            'format_strategy' => (string) $profile['format_strategy'],
            'compression_strategy' => (string) $profile['compression_strategy'],
            'included_refs' => array_values(array_column($sections, 'segment_hash')),
            'excluded_refs' => array_values(array_map(static fn (array $segment): array => [
                'segment_hash' => $segment['segment_hash'],
                'reason' => $segment['exclude_reason'],
                'must_keep' => $segment['must_keep'],
            ], $excluded)),
            'compiled_sections' => $sections,
            'output_contract' => [
                'separate_fact_inference_memory_estimate' => true,
                'cite_evidence_hashes' => true,
                'do_not_expose_raw_internal_ids' => true,
            ],
            'memory_delta_receipt_hash' => (string) data_get($memory, 'delta_receipt.receipt_hash', ''),
        ];
        $pack['compiled_hash'] = MissionCanonicalHash::sha256($pack);

        return $pack;
    }

    /**
     * @param  array<int,array<string,mixed>>  $segments
     * @param  array<int,array<string,mixed>>  $included
     * @param  array<int,array<string,mixed>>  $excluded
     * @return array<string,mixed>
     */
    private function lossCheck(array $segments, array $included, array $excluded): array
    {
        $mustKeepTotal = count(array_filter($segments, static fn (array $segment): bool => (bool) $segment['must_keep']));
        $mustKeepIncluded = count(array_filter($included, static fn (array $segment): bool => (bool) $segment['must_keep']));
        $mustKeepCoverage = $mustKeepTotal === 0 ? 1.0 : round($mustKeepIncluded / $mustKeepTotal, 4);
        $excludedOptionalTokens = array_sum(array_map(static fn (array $segment): int => (bool) $segment['must_keep'] ? 0 : (int) $segment['tokens'], $excluded));
        $totalTokens = max(1, array_sum(array_map(static fn (array $segment): int => (int) $segment['tokens'], $segments)));
        $lossScore = round($excludedOptionalTokens / $totalTokens, 4);
        $criticalLoss = $mustKeepCoverage < 1.0;

        $check = [
            'schema_version' => self::LOSS_CHECK_SCHEMA,
            'status' => $criticalLoss ? 'blocked' : 'passed',
            'must_keep_coverage' => $mustKeepCoverage,
            'loss_score' => $lossScore,
            'critical_loss' => $criticalLoss,
            'blockers' => $criticalLoss ? ['must_keep_coverage_below_one'] : [],
        ];
        $check['loss_check_hash'] = MissionCanonicalHash::sha256($check);

        return $check;
    }

    /**
     * @param  array<int,array<string,mixed>>  $segments
     * @param  array<int,array<string,mixed>>  $included
     * @param  array<int,array<string,mixed>>  $excluded
     * @param  array<string,mixed>  $profile
     * @return array<string,mixed>
     */
    private function budgetReceipt(array $segments, array $included, array $excluded, array $profile): array
    {
        $before = array_sum(array_map(static fn (array $segment): int => (int) $segment['tokens'], $segments));
        $after = array_sum(array_map(static fn (array $segment): int => (int) $segment['tokens'], $included));
        $receipt = [
            'schema_version' => self::BUDGET_RECEIPT_SCHEMA,
            'provider' => (string) $profile['provider'],
            'token_budget' => (int) $profile['token_budget'],
            'input_tokens_before' => $before,
            'input_tokens_after' => $after,
            'excluded_tokens' => array_sum(array_map(static fn (array $segment): int => (int) $segment['tokens'], $excluded)),
            'token_savings_estimate' => max(0, $before - $after),
            'budget_exceeded_by_must_keep' => $after > (int) $profile['token_budget'],
        ];
        $receipt['receipt_hash'] = MissionCanonicalHash::sha256($receipt);

        return $receipt;
    }

    private function category(string $kind): string
    {
        return match ($kind) {
            'decision', 'blocker', 'constraint', 'dod', 'risk', 'receipt' => 'must_keep',
            'evidence', 'source', 'test' => 'evidence',
            'memory' => 'memory',
            'estimate' => 'estimate',
            default => 'context',
        };
    }

    private function provider(string $provider): string
    {
        return in_array($provider, ['claude', 'gpt', 'gemini', 'local'], true) ? $provider : 'gpt';
    }

    private function risk(string $risk): string
    {
        return in_array($risk, ['low', 'medium', 'high', 'irreversible'], true) ? $risk : 'low';
    }
}
