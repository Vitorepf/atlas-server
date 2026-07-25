<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrainContextInjection;

use App\Services\Ai\Context\ContextPackSelfReflectionGate;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pure policy / surface / delivery / envelope peels for
 * {@see \App\Services\Ai\AtlasOpenBrainContextInjectionService}.
 *
 * Context-delivery policy projection, surface resolution, engineering context
 * shape, operator-context refs/summary, injection summary counts, next-actions,
 * and skip/fail envelopes. No DB, Schema, config(), realpath, DI, or time.
 * Host keeps retrieval I/O, audit writes, workspace resolution, and config gates.
 */
final class ContextInjectionProjectionSupport
{
    /** @var list<string> */
    public const ALLOWED_SURFACES = [
        'cli_dev',
        'cli_continue',
        'cli_chat',
        'app_ai',
        'api',
        'system',
    ];

    /** @var list<string> */
    public const PROGRAMMING_INJECTION_SIGNALS = [
        'programming.dev',
        'programming.debug',
        'programming.review',
        'programming.repair',
        'programming.refactor',
        'programming.qa',
        'programming.security',
        'programming.database',
        'programming.frontend',
        'programming.visual',
        'programming.forge',
    ];

    /** @var list<string> */
    public const GENERIC_INJECTION_SIGNALS = [
        'dev',
        'debug',
        'review',
        'repair',
        'fix',
        'programming',
        'quality_repair',
        'execute',
    ];

    private function __construct()
    {
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public static function payload(array $options): array
    {
        return is_array($options['payload'] ?? null) ? $options['payload'] : [];
    }

    /**
     * Lowercased non-empty scalar string, or null.
     */
    public static function lowerString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== ''
            ? Str::of((string) $value)->lower()->trim()->value()
            : null;
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $payload
     */
    public static function surface(array $options, array $payload): string
    {
        $explicit = self::lowerString(data_get($options, 'open_brain.surface', data_get($payload, 'open_brain.surface')));
        if ($explicit && in_array($explicit, self::ALLOWED_SURFACES, true)) {
            return $explicit;
        }

        $appSurface = Str::of((string) data_get($payload, 'app_surface', data_get($payload, 'surface', '')))->lower()->value();
        if (str_contains($appSurface, 'atlas_cli')) {
            $devPlan = data_get($payload, 'dev_execution_plan');
            if (is_array($devPlan) && $devPlan !== []) {
                return data_get($devPlan, 'resumed_at') ? 'cli_continue' : 'cli_dev';
            }

            return 'cli_chat';
        }

        if (str_contains($appSurface, 'atlas_ai') || ($options['source_type'] ?? null) === 'app') {
            return 'app_ai';
        }

        return ($options['source_type'] ?? null) === 'manual' ? 'cli_chat' : 'api';
    }

    /**
     * @param  list<string>  $signals
     */
    public static function anySignalRequiresInjection(array $signals): bool
    {
        foreach ($signals as $signal) {
            if (! is_string($signal) || $signal === '') {
                continue;
            }
            if (self::signalRequiresInjection($signal)) {
                return true;
            }
        }

        return false;
    }

    public static function signalRequiresInjection(string $signal): bool
    {
        $signal = Str::of($signal)->lower()->trim()->value();
        if ($signal === '') {
            return false;
        }

        if (str_starts_with($signal, 'programming.')) {
            return in_array($signal, self::PROGRAMMING_INJECTION_SIGNALS, true);
        }

        return in_array($signal, self::GENERIC_INJECTION_SIGNALS, true);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public static function engineeringContext(?string $workspace, array $payload): array
    {
        return [
            'project_id' => data_get($payload, 'project_id'),
            'task_id' => data_get($payload, 'task_id'),
            'engineering_run_id' => data_get($payload, 'engineering_run_id', data_get($payload, 'run_id')),
            'workspace' => $workspace,
            'contract' => data_get($payload, 'dev_execution_plan.engineering_contract', []),
            'blueprint' => data_get($payload, 'dev_execution_plan.engineering_blueprint', []),
            'tags' => array_values(array_filter([
                data_get($payload, 'atlas_workflow_mode'),
                data_get($payload, 'routing_task'),
                data_get($payload, 'routing_domain'),
                data_get($payload, 'task_type'),
            ], 'is_string')),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $pack
     * @return array<string,mixed>|null
     */
    public static function contextDeliveryPolicy(array $payload, array $pack): ?array
    {
        foreach ([
            data_get($payload, 'context_delivery_policy'),
            data_get($payload, 'open_brain.context_delivery_policy'),
            data_get($payload, 'token_economy.context_delivery_policy'),
            data_get($pack, 'context_delivery_policy'),
            data_get($pack, 'token_economy.context_delivery_policy'),
        ] as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $policy = self::providerSafeContextDeliveryPolicy($candidate);
            if ($policy !== null) {
                return $policy;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>|null
     */
    public static function providerSafeContextDeliveryPolicy(array $policy): ?array
    {
        if ((string) ($policy['status'] ?? '') !== 'active') {
            return null;
        }

        if (data_get($policy, 'policy.raw_text_exposed') === true || data_get($policy, 'policy.provider_safe_only') === false) {
            return null;
        }

        return [
            'schema_version' => TextNormalizeSupport::stringValue($policy['schema_version'] ?? null, 'atlas.token_economy.context_delivery_policy.v1'),
            'status' => 'active',
            'source' => TextNormalizeSupport::stringValue($policy['source'] ?? null, 'unknown'),
            'delivery_mode' => TextNormalizeSupport::stringValue($policy['delivery_mode'] ?? null, 'standard_compiled_pack'),
            'reason' => TextNormalizeSupport::stringValue($policy['reason'] ?? null, 'context_delivery_policy_active'),
            'initial_context_token_budget' => max(0, (int) ($policy['initial_context_token_budget'] ?? 0)),
            'expansion_token_reserve' => max(0, (int) ($policy['expansion_token_reserve'] ?? 0)),
            'initial_ref_limit' => max(0, (int) ($policy['initial_ref_limit'] ?? 0)),
            'initial_source_types' => TextNormalizeSupport::stringList($policy['initial_source_types'] ?? []),
            'deferred_source_types' => TextNormalizeSupport::stringList($policy['deferred_source_types'] ?? []),
            'guarded_required_source_types' => TextNormalizeSupport::stringList($policy['guarded_required_source_types'] ?? []),
            'expansion_triggers' => TextNormalizeSupport::stringList($policy['expansion_triggers'] ?? []),
            'quality_gate_hint' => TextNormalizeSupport::stringValue($policy['quality_gate_hint'] ?? null, 'feedback_guided_staging_allowed'),
            'advisory_only' => true,
            'policy' => [
                'provider_safe_only' => true,
                'raw_text_exposed' => false,
                'providers_invoked' => false,
                'writes' => false,
                'auto_apply_learning' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<int,array<string,mixed>>
     */
    public static function contextDeliveryRefs(array $policy): array
    {
        $refs = [];
        foreach ((array) ($policy['initial_source_types'] ?? []) as $sourceType) {
            $refs[] = [
                'type' => 'atlas_context_initial_source',
                'id' => 'initial:'.$sourceType,
                'source_type' => $sourceType,
                'reason' => 'context_delivery_initial_source',
                'provider_safe' => true,
            ];
        }
        foreach ((array) ($policy['deferred_source_types'] ?? []) as $sourceType) {
            $refs[] = [
                'type' => 'atlas_context_expansion_handle',
                'id' => 'expand:'.$sourceType,
                'source_type' => $sourceType,
                'reason' => 'context_delivery_deferred_source',
                'provider_safe' => true,
            ];
        }
        foreach ((array) ($policy['guarded_required_source_types'] ?? []) as $sourceType) {
            $refs[] = [
                'type' => 'atlas_context_required_recheck',
                'id' => 'recheck:'.$sourceType,
                'source_type' => $sourceType,
                'reason' => 'context_delivery_required_source_recheck',
                'provider_safe' => true,
            ];
        }

        return PromptAssemblySupport::mergeRefs($refs);
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    public static function contextDeliveryPolicySummary(array $policy): array
    {
        return [
            'schema_version' => (string) ($policy['schema_version'] ?? 'atlas.token_economy.context_delivery_policy.v1'),
            'status' => 'active',
            'delivery_mode' => (string) ($policy['delivery_mode'] ?? 'standard_compiled_pack'),
            'source' => (string) ($policy['source'] ?? 'unknown'),
            'initial_context_token_budget' => (int) ($policy['initial_context_token_budget'] ?? 0),
            'expansion_token_reserve' => (int) ($policy['expansion_token_reserve'] ?? 0),
            'initial_ref_limit' => (int) ($policy['initial_ref_limit'] ?? 0),
            'initial_source_types' => (array) ($policy['initial_source_types'] ?? []),
            'deferred_source_types' => (array) ($policy['deferred_source_types'] ?? []),
            'guarded_required_source_types' => (array) ($policy['guarded_required_source_types'] ?? []),
            'expansion_handle_count' => count((array) ($policy['deferred_source_types'] ?? [])) + count((array) ($policy['guarded_required_source_types'] ?? [])),
            'quality_gate_hint' => (string) ($policy['quality_gate_hint'] ?? 'feedback_guided_staging_allowed'),
            'advisory_only' => true,
        ];
    }

    /**
     * @return array<int,string>
     */
    public static function contextDeliveryPolicyWarnings(?array $policy): array
    {
        if ($policy === null) {
            return [];
        }

        return (array) ($policy['guarded_required_source_types'] ?? []) !== []
            ? ['context_delivery_required_source_recheck']
            : [];
    }

    /**
     * @param  array<string,mixed>  $selfReflection
     * @return array<int,string>
     */
    public static function selfReflectionWarnings(array $selfReflection): array
    {
        return match ((string) ($selfReflection['status'] ?? 'unknown')) {
            ContextPackSelfReflectionGate::STATUS_INSUFFICIENT => ['context_pack_insufficient'],
            ContextPackSelfReflectionGate::STATUS_CONTRADICTORY => ['context_pack_contradictory'],
            ContextPackSelfReflectionGate::STATUS_RISKY => ['context_pack_risky'],
            default => [],
        };
    }

    /**
     * @param  array<string,mixed>  $operatorContext
     * @return array<int,array<string,mixed>>
     */
    public static function operatorContextRefs(array $operatorContext): array
    {
        return collect((array) ($operatorContext['items'] ?? []))
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): array => [
                'type' => 'operator_profile_item',
                'id' => (string) ($item['id'] ?? ''),
                'profile_key' => (string) ($item['profile_key'] ?? ''),
                'taxonomy_item_id' => (string) ($item['taxonomy_item_id'] ?? ''),
                'effect' => (string) ($item['effect'] ?? ''),
                'provider_safe' => true,
                'reason' => 'operator_intelligence_profile_match',
            ])
            ->filter(fn (array $ref): bool => $ref['id'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $operatorContext
     * @return array<string,mixed>
     */
    public static function operatorContextSummary(array $operatorContext): array
    {
        $items = collect((array) ($operatorContext['items'] ?? []))
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values();

        return [
            'schema_version' => 'atlas.operator_open_brain_context.v1',
            'enabled' => (bool) ($operatorContext['enabled'] ?? true),
            'status' => (string) ($operatorContext['status'] ?? 'unknown'),
            'reason' => (string) ($operatorContext['reason'] ?? 'unknown'),
            'operator_id_hash' => is_string($operatorContext['operator_id'] ?? null) ? hash('sha256', (string) $operatorContext['operator_id']) : null,
            'flow' => $operatorContext['flow'] ?? null,
            'provider_external' => (bool) ($operatorContext['provider_external'] ?? true),
            'item_count' => $items->count(),
            'omitted_count' => count((array) ($operatorContext['omitted'] ?? [])),
            'profile_keys' => $items
                ->map(fn (array $item): string => (string) ($item['profile_key'] ?? ''))
                ->filter()
                ->take(12)
                ->values()
                ->all(),
            'effects' => $items
                ->map(fn (array $item): string => (string) ($item['effect'] ?? ''))
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * Ref-count summary shell. Caller supplies precomputed retrieval_plan summary.
     *
     * @param  array<int,array<string,mixed>>  $contextRefs
     * @param  array<int,array<string,mixed>>  $knowledgeRefs
     * @param  array<int,array<string,mixed>>  $codeRefs
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>|null  $retrievalPlan
     * @return array<string,mixed>
     */
    public static function injectionSummary(
        array $contextRefs,
        array $knowledgeRefs,
        array $codeRefs,
        array $policy,
        ?array $retrievalPlan = null,
    ): array {
        $refs = collect($contextRefs);

        return [
            'context_refs' => $refs->count(),
            'memory_refs' => $refs->where('type', 'atlas_memory_entry')->count(),
            'verbatim_refs' => $refs->where('type', 'atlas_verbatim_memory')->count(),
            'semantic_refs' => $refs->where('type', 'semantic_note')->count(),
            'memory_recall_refs' => $refs->where('type', 'atlas_memory_recall')->count(),
            'reality_graph_refs' => $refs->where('type', 'atlas_reality_path')->count(),
            'operator_profile_refs' => $refs->where('type', 'operator_profile_item')->count(),
            'context_expansion_handles' => $refs->whereIn('type', ['atlas_context_expansion_handle', 'atlas_context_required_recheck'])->count(),
            'knowledge_refs' => count($knowledgeRefs),
            'code_refs' => count($codeRefs),
            'budget_chars' => (int) ($policy['budget_chars'] ?? 0),
            'used_chars' => 0,
            'provider_safe' => true,
            'retrieval_plan' => $retrievalPlan,
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @param  array<string,mixed>  $payload
     */
    public static function isPreview(array $options, array $payload): bool
    {
        return (bool) data_get($options, 'open_brain.preview', data_get($payload, 'open_brain.preview', false));
    }

    /**
     * @param  array<string,mixed>  $policy
     */
    public static function requester(array $policy): string
    {
        return match ((string) ($policy['surface'] ?? '')) {
            'cli_dev' => 'atlas-dev',
            'cli_continue' => 'atlas-continue',
            'cli_chat' => 'atlas-chat',
            'app_ai' => 'atlas-ai-app',
            default => 'atlas',
        };
    }

    /**
     * @param  array<int,string>  $warnings
     * @param  array<string,mixed>  $summary
     * @return array<int,string>
     */
    public static function nextActions(array $warnings, array $summary = []): array
    {
        $actions = [];
        $retrievalAction = data_get($summary, 'retrieval_plan.review_signal.recommended_action');
        if (is_string($retrievalAction) && $retrievalAction !== '' && $retrievalAction !== 'none') {
            $actions[] = match ($retrievalAction) {
                'refresh_evidence_replay_or_attach_trace_before_retry' => 'Refresh evidence replay or attach trace/envelope evidence before retrying.',
                'refresh_code_intelligence_before_retry' => 'Refresh code intelligence before retrying.',
                'refresh_memory_context_before_retry' => 'Refresh Atlas memory/context sources before retrying.',
                'degrade_graph_context_or_attach_relationship_evidence' => 'Attach relationship evidence or explicitly degrade graph context before retrying.',
                default => 'Refresh unavailable retrieval sources before retrying.',
            };
        }
        if (in_array('no_engineering_knowledge_refs', $warnings, true) || in_array('no_code_intelligence_refs', $warnings, true)) {
            $actions[] = 'Run atlas memory maintain to sync docs and code intelligence.';
        }
        if (in_array('open_brain_audit_table_missing', $warnings, true)) {
            $actions[] = 'Run migrations before requiring Open Brain injection.';
        }
        if (in_array('context_delivery_required_source_recheck', $warnings, true)) {
            $actions[] = 'Call atlas_context_expand for guarded required sources before implementation.';
        }
        if ((int) data_get($summary, 'context_delivery_policy.expansion_token_reserve', 0) > 0) {
            $actions[] = 'Call atlas_context_expand for deferred sources before dumping full docs, tests or graph output.';
        }

        return array_values(array_unique($actions));
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    public static function skipped(array $policy, string $reason): array
    {
        return [
            'enabled' => false,
            'status' => 'skipped',
            'reason' => $reason,
            'surface' => $policy['surface'] ?? 'unknown',
            'mode' => $policy['mode'] ?? 'off',
            'workspace' => null,
            'context_pack_hash' => null,
            'audit_id' => null,
            'prompt_section' => null,
            'summary' => [
                'context_refs' => 0,
                'memory_refs' => 0,
                'knowledge_refs' => 0,
                'code_refs' => 0,
                'budget_chars' => $policy['budget_chars'] ?? null,
                'used_chars' => 0,
                'provider_safe' => true,
            ],
            'warnings' => [],
            'next_actions' => [],
            'context_refs' => [],
            'policy' => $policy,
        ];
    }

    /**
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    public static function failed(array $policy, Throwable $exception): array
    {
        $required = ($policy['mode'] ?? null) === 'required';

        return [
            'enabled' => true,
            'status' => $required ? 'failed_closed' : 'failed_open',
            'reason' => $required ? 'required_open_brain_failed' : 'open_brain_failed_open',
            'surface' => $policy['surface'] ?? 'unknown',
            'mode' => $policy['mode'] ?? 'auto',
            'workspace' => null,
            'context_pack_hash' => null,
            'audit_id' => null,
            'prompt_section' => null,
            'summary' => [
                'context_refs' => 0,
                'memory_refs' => 0,
                'knowledge_refs' => 0,
                'code_refs' => 0,
                'budget_chars' => $policy['budget_chars'] ?? null,
                'used_chars' => 0,
                'provider_safe' => true,
            ],
            'warnings' => ['open_brain_exception:'.class_basename($exception)],
            'next_actions' => ['Inspect logs and run atlas memory maintain before retrying.'],
            'context_refs' => [],
            'policy' => $policy,
        ];
    }

    /**
     * First non-empty trimmed string among candidates.
     *
     * @param  array<int,mixed>  $candidates
     */
    public static function firstNonEmptyString(array $candidates): ?string
    {
        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
