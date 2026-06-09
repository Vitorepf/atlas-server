<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;

final class AtlasTokenEconomyRuntimeService
{
    public const SCHEMA_VERSION = 'atlas.aucri.token_economy_runtime.v1';

    public const BUDGET_SCHEMA = 'atlas.token_economy.budget.v1';

    public const COMPRESSION_RECEIPT_SCHEMA = 'atlas.token_economy.compression_receipt.v1';

    public const REUSE_RECEIPT_SCHEMA = 'atlas.token_economy.reuse_receipt.v1';

    public const LOCAL_PREREASONING_SCHEMA = 'atlas.token_economy.local_prereasoning.v1';

    public const QUALITY_CHECK_SCHEMA = 'atlas.token_economy.quality_check.v1';

    public function __construct(
        private readonly AtlasContextCompilerRuntimeService $compiler,
        private readonly AtlasAucriTokenQualityCanarySetService $canarySet,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function optimize(array $input = []): array
    {
        $risk = $this->risk((string) ($input['risk_level'] ?? 'low'));
        $provider = $this->provider((string) ($input['provider'] ?? 'gpt'));
        $compiled = $this->compiler->compile($input + ['provider' => $provider, 'risk_level' => $risk]);
        $before = (int) data_get($compiled, 'prompt_budget_receipt.input_tokens_before', 0);
        $compiledAfter = (int) data_get($compiled, 'prompt_budget_receipt.input_tokens_after', $before);
        $reuse = $this->reuseReceipt($input, $compiled);
        $local = $this->localPrereasoning($input);
        $compression = $this->compressionReceipt($compiledAfter, $risk, $reuse, $local, $input);
        $budget = $this->budget($compiled, $compression, $risk, $provider);
        $providerSelection = $this->providerSelection($risk, $provider, (int) $compression['input_tokens_after'], $local);
        $quality = $this->qualityCheck($compression, $compiled, $input);
        $status = (string) $quality['quality_gate_status'] === 'passed' ? 'ready' : 'blocked';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'budget' => $budget,
            'compression_receipt' => $compression,
            'reuse_receipt' => $reuse,
            'local_prereasoning' => $local,
            'provider_model_selection' => $providerSelection,
            'quality_check' => $quality,
            'compiled_ref' => [
                'schema_version' => AtlasContextCompilerRuntimeService::SCHEMA_VERSION,
                'status' => (string) ($compiled['status'] ?? 'unknown'),
                'context_compiler_hash' => (string) ($compiled['context_compiler_hash'] ?? ''),
                'compiled_hash' => (string) data_get($compiled, 'compiled_pack.compiled_hash', ''),
            ],
            'canary_ref' => [
                'schema_version' => AtlasAucriTokenQualityCanarySetService::SCHEMA_VERSION,
                'canary_set_hash' => (string) $this->canarySet->report()['canary_set_hash'],
            ],
            'claims' => [
                'providers_invoked' => false,
                'writes' => false,
                'rivals_run' => false,
                'benchmark_run' => false,
                'raw_text_exposed' => false,
                'provider_selection_applied' => false,
            ],
        ];

        $hashPayload = $payload;
        unset($hashPayload['generated_at']);
        $payload['token_economy_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $compiled
     * @param  array<string,mixed>  $compression
     * @return array<string,mixed>
     */
    private function budget(array $compiled, array $compression, string $risk, string $provider): array
    {
        $outputBudget = match ($risk) {
            'low' => 900,
            'medium' => 1400,
            'high' => 2200,
            default => 3000,
        };

        $budget = [
            'schema_version' => self::BUDGET_SCHEMA,
            'flow_id' => (string) data_get($compiled, 'compiler_input.flow_id', 'atlas.context.compile'),
            'risk_level' => $risk,
            'provider' => $provider,
            'input_tokens_before' => (int) $compression['input_tokens_before'],
            'input_tokens_after' => (int) $compression['input_tokens_after'],
            'output_budget' => $outputBudget,
            'savings_estimate' => (int) $compression['savings_estimate'],
        ];
        $budget['receipt_hash'] = MissionCanonicalHash::sha256($budget);

        return $budget;
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $compiled
     * @return array<string,mixed>
     */
    private function reuseReceipt(array $input, array $compiled): array
    {
        $compiledHash = (string) data_get($compiled, 'compiled_pack.compiled_hash', '');
        $previousHash = (string) ($input['previous_compiled_hash'] ?? '');
        $fresh = (bool) ($input['reuse_fresh'] ?? true);
        $eligible = $previousHash !== '' && hash_equals($previousHash, $compiledHash) && $fresh;
        $saved = $eligible ? (int) round((int) data_get($compiled, 'prompt_budget_receipt.input_tokens_after', 0) * 0.55) : 0;
        $receipt = [
            'schema_version' => self::REUSE_RECEIPT_SCHEMA,
            'eligible' => $eligible,
            'freshness_required' => true,
            'freshness_passed' => $fresh,
            'previous_compiled_hash_match' => $previousHash !== '' && hash_equals($previousHash, $compiledHash),
            'reused_tokens_estimate' => $saved,
        ];
        $receipt['receipt_hash'] = MissionCanonicalHash::sha256($receipt);

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function localPrereasoning(array $input): array
    {
        $policy = LocalPrereasoningPolicy::classify(
            (string) ($input['task_type'] ?? ''),
            (int) ($input['local_saved_tokens'] ?? 1200),
        );

        $receipt = [
            'schema_version' => self::LOCAL_PREREASONING_SCHEMA,
            'task_type' => $policy['task_type'],
            'can_resolve_locally' => $policy['can_resolve_locally'],
            'provider_call_avoidable' => $policy['provider_call_avoidable'],
            'saved_tokens_estimate' => $policy['saved_tokens_estimate'],
            'allowed_operations' => $policy['allowed_operations'],
        ];
        $receipt['receipt_hash'] = MissionCanonicalHash::sha256($receipt);

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $reuse
     * @param  array<string,mixed>  $local
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function compressionReceipt(int $compiledAfter, string $risk, array $reuse, array $local, array $input): array
    {
        $safeCompression = match ($risk) {
            'low' => 0.28,
            'medium' => 0.18,
            'high' => 0.10,
            default => 0.05,
        };
        $compressionSavings = (int) round($compiledAfter * $safeCompression);
        $reuseSavings = (int) ($reuse['reused_tokens_estimate'] ?? 0);
        $localSavings = (int) ($local['saved_tokens_estimate'] ?? 0);
        $totalSavings = min($compiledAfter, $compressionSavings + $reuseSavings + $localSavings);
        $after = max(0, $compiledAfter - $totalSavings);
        $mustKeepCoverage = array_key_exists('must_keep_coverage', $input)
            ? max(0.0, min(1.0, (float) $input['must_keep_coverage']))
            : 1.0;
        $lossScore = round($compressionSavings / max(1, $compiledAfter), 4);

        $receipt = [
            'schema_version' => self::COMPRESSION_RECEIPT_SCHEMA,
            'input_tokens_before' => $compiledAfter,
            'input_tokens_after' => $after,
            'semantic_compression_savings' => $compressionSavings,
            'reuse_savings' => $reuseSavings,
            'local_prereasoning_savings' => $localSavings,
            'savings_estimate' => $totalSavings,
            'must_keep_coverage' => $mustKeepCoverage,
            'loss_score' => $lossScore,
            'compression_strategy' => $risk === 'low' ? 'semantic_optional_trim' : 'lossless_must_keep_only',
        ];
        $receipt['receipt_hash'] = MissionCanonicalHash::sha256($receipt);

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $local
     * @return array<string,mixed>
     */
    private function providerSelection(string $risk, string $requestedProvider, int $tokensAfter, array $local): array
    {
        $selected = match (true) {
            (bool) ($local['provider_call_avoidable'] ?? false) => 'none_local_only',
            $risk === 'low' && $tokensAfter < 2500 => 'local',
            $risk === 'medium' && $tokensAfter < 7000 => 'gpt',
            $risk === 'high' => in_array($requestedProvider, ['claude', 'gpt'], true) ? $requestedProvider : 'gpt',
            $risk === 'irreversible' => 'claude',
            default => $requestedProvider,
        };

        return [
            'schema_version' => 'atlas.token_economy.provider_model_selection.v1',
            'requested_provider' => $requestedProvider,
            'selected_provider' => $selected,
            'selection_applied' => false,
            'reason' => $selected === 'none_local_only' ? 'local_prereasoning_can_resolve' : 'lowest_safe_provider_for_risk_and_token_shape',
            'cheap_provider_blocked_by_risk' => in_array($risk, ['high', 'irreversible'], true) && in_array($selected, ['local', 'none_local_only'], true) === false,
        ];
    }

    /**
     * @param  array<string,mixed>  $compression
     * @param  array<string,mixed>  $compiled
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function qualityCheck(array $compression, array $compiled, array $input): array
    {
        $risk = $this->risk((string) ($input['risk_level'] ?? data_get($compiled, 'compiler_input.risk_level', 'low')));
        $lossLimit = match ($risk) {
            'low' => 0.30,
            'medium' => 0.22,
            'high' => 0.14,
            default => 0.08,
        };
        $mustKeep = (float) $compression['must_keep_coverage'];
        $loss = (float) $compression['loss_score'];
        $compiledLoss = (string) data_get($compiled, 'loss_check.status', 'blocked');
        $blockers = [];
        if ($mustKeep < 1.0) {
            $blockers[] = 'must_keep_coverage_below_one';
        }
        if ($loss > $lossLimit) {
            $blockers[] = 'loss_score_above_risk_limit';
        }
        if ($compiledLoss !== 'passed') {
            $blockers[] = 'compiled_context_loss_check_failed';
        }

        $check = [
            'schema_version' => self::QUALITY_CHECK_SCHEMA,
            'quality_gate_status' => $blockers === [] ? 'passed' : 'blocked',
            'must_keep_coverage' => $mustKeep,
            'loss_score' => $loss,
            'loss_limit' => $lossLimit,
            'blockers' => $blockers,
            'sufficiency_regression_allowed' => false,
            'privacy_regression_allowed' => false,
        ];
        $check['receipt_hash'] = MissionCanonicalHash::sha256($check);

        return $check;
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
