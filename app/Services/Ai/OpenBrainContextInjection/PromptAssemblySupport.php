<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrainContextInjection;

use App\Support\YesNo;
use Illuminate\Support\Str;

/**
 * Pure prompt-assembly / provider-safety peels for AtlasOpenBrainContextInjectionService.
 *
 * Memory-quality projection, operator warnings, ref merge/fusion order,
 * provider-safe ref choke-point, programming context summary, and prompt render.
 * No DB, no DI, no config, no time side effects.
 */
final class PromptAssemblySupport
{
    private function __construct()
    {
    }

    /**
     * @param  array<string,mixed>  $operatorContext
     * @return array<int,string>
     */
    public static function operatorContextWarnings(array $operatorContext): array
    {
        if (($operatorContext['status'] ?? null) === 'unavailable') {
            return ['operator_context_unavailable'];
        }

        if (($operatorContext['enabled'] ?? true) === false) {
            return ['operator_context_disabled'];
        }

        return [];
    }

    /**
     * @param  array<string,mixed>|null  $memoryQuality
     * @return array<string,mixed>
     */
    public static function memoryQualitySummary(?array $memoryQuality): array
    {
        if ($memoryQuality === null) {
            return [
                'included' => false,
            ];
        }

        $issues = collect((array) ($memoryQuality['issues'] ?? []))
            ->filter(fn (mixed $issue): bool => is_array($issue))
            ->map(fn (array $issue): array => array_filter([
                'code' => is_scalar($issue['code'] ?? null) ? (string) $issue['code'] : null,
                'severity' => is_scalar($issue['severity'] ?? null) ? (string) $issue['severity'] : null,
                'count' => isset($issue['count']) && is_numeric($issue['count']) ? (int) $issue['count'] : null,
                'score' => isset($issue['score']) && is_numeric($issue['score']) ? (int) $issue['score'] : null,
            ], fn (mixed $value): bool => $value !== null && $value !== ''))
            ->values()
            ->all();

        return [
            'included' => true,
            'ok' => (bool) ($memoryQuality['ok'] ?? false),
            'status' => is_scalar($memoryQuality['status'] ?? null) ? (string) $memoryQuality['status'] : 'unknown',
            'score' => isset($memoryQuality['score']) && is_numeric($memoryQuality['score']) ? (int) $memoryQuality['score'] : null,
            'active' => isset($memoryQuality['counts']['active']) && is_numeric($memoryQuality['counts']['active']) ? (int) $memoryQuality['counts']['active'] : null,
            'provider_safe_active' => isset($memoryQuality['counts']['provider_safe_active']) && is_numeric($memoryQuality['counts']['provider_safe_active'])
                ? (int) $memoryQuality['counts']['provider_safe_active']
                : null,
            'latest_snapshot' => is_array($memoryQuality['latest_snapshot'] ?? null)
                ? [
                    'status' => $memoryQuality['latest_snapshot']['status'] ?? null,
                    'score' => $memoryQuality['latest_snapshot']['score'] ?? null,
                    'snapshot_at' => $memoryQuality['latest_snapshot']['snapshot_at'] ?? null,
                ]
                : null,
            'trend' => self::memoryQualityTrendSummary($memoryQuality),
            'issues' => array_slice($issues, 0, 8),
        ];
    }

    /**
     * @param  array<string,mixed>  $memoryQuality
     * @return array<string,mixed>|null
     */
    public static function memoryQualityTrendSummary(array $memoryQuality): ?array
    {
        if (! is_array($memoryQuality['trend'] ?? null)) {
            return null;
        }

        $trend = $memoryQuality['trend'];
        $drivers = collect((array) ($trend['drivers'] ?? []))
            ->filter(fn (mixed $driver): bool => is_array($driver))
            ->map(fn (array $driver): array => array_filter([
                'kind' => is_scalar($driver['kind'] ?? null) ? (string) $driver['kind'] : null,
                'key' => is_scalar($driver['key'] ?? null) ? (string) $driver['key'] : null,
                'severity' => is_scalar($driver['severity'] ?? null) ? (string) $driver['severity'] : null,
                'delta' => isset($driver['delta']) && is_numeric($driver['delta']) ? (int) $driver['delta'] : null,
                'current' => isset($driver['current']) && is_numeric($driver['current']) ? (int) $driver['current'] : null,
                'previous' => isset($driver['previous']) && is_numeric($driver['previous']) ? (int) $driver['previous'] : null,
            ], fn (mixed $value): bool => $value !== null && $value !== ''))
            ->values()
            ->take(5)
            ->all();

        $summary = array_filter([
            'status' => is_scalar($trend['status'] ?? null) ? (string) $trend['status'] : null,
            'current_score' => isset($trend['current_score']) && is_numeric($trend['current_score']) ? (int) $trend['current_score'] : null,
            'latest_snapshot_score' => isset($trend['latest_snapshot_score']) && is_numeric($trend['latest_snapshot_score']) ? (int) $trend['latest_snapshot_score'] : null,
            'previous_snapshot_score' => isset($trend['previous_snapshot_score']) && is_numeric($trend['previous_snapshot_score']) ? (int) $trend['previous_snapshot_score'] : null,
            'snapshot_count' => isset($trend['snapshot_count']) && is_numeric($trend['snapshot_count']) ? (int) $trend['snapshot_count'] : null,
            'current_delta_from_latest' => isset($trend['current_delta_from_latest']) && is_numeric($trend['current_delta_from_latest']) ? (int) $trend['current_delta_from_latest'] : null,
            'latest_delta_from_previous' => isset($trend['latest_delta_from_previous']) && is_numeric($trend['latest_delta_from_previous']) ? (int) $trend['latest_delta_from_previous'] : null,
            'window_delta' => isset($trend['window_delta']) && is_numeric($trend['window_delta']) ? (int) $trend['window_delta'] : null,
            'drivers' => $drivers,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        return $summary === [] ? null : $summary;
    }

    /**
     * @param  array<string,mixed>|null  $memoryQuality
     * @return array<int,string>
     */
    public static function memoryQualityWarnings(?array $memoryQuality): array
    {
        if ($memoryQuality === null) {
            return [];
        }

        $status = is_scalar($memoryQuality['status'] ?? null) ? (string) $memoryQuality['status'] : 'unknown';
        $score = isset($memoryQuality['score']) && is_numeric($memoryQuality['score']) ? (int) $memoryQuality['score'] : null;
        $warnings = [];

        if ($status === 'not_migrated') {
            $warnings[] = 'memory_quality_not_migrated';
        } elseif ($status === 'empty') {
            $warnings[] = 'memory_quality_empty';
        } elseif ($status === 'critical') {
            $warnings[] = 'memory_quality_critical';
        } elseif (in_array($status, ['needs_review', 'watch', 'unavailable'], true)) {
            $warnings[] = 'memory_quality_'.$status;
        }

        if ($score !== null && $score < 70) {
            $warnings[] = 'memory_quality_score_low';
        }

        $trendStatus = data_get($memoryQuality, 'trend.status');
        if ($trendStatus === 'regressed') {
            $warnings[] = 'memory_quality_trend_regressed';
        } elseif ($trendStatus === 'watch_regressed') {
            $warnings[] = 'memory_quality_trend_watch_regressed';
        }

        $issueCodes = collect((array) ($memoryQuality['issues'] ?? []))
            ->filter(fn (mixed $issue): bool => is_array($issue) && is_scalar($issue['code'] ?? null))
            ->map(fn (array $issue): string => (string) $issue['code'])
            ->values()
            ->all();

        foreach (['no_provider_safe_memory', 'accepted_learning_not_promoted', 'negative_memory_feedback'] as $issueCode) {
            if (in_array($issueCode, $issueCodes, true)) {
                $warnings[] = 'memory_quality_'.$issueCode;
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * @param  array<int,array<string,mixed>>  $codeGraphRefs
     * @param  array<int,array<string,mixed>>  $memoryRecallRefs
     * @param  array<int,array<string,mixed>>  $realityGraphRefs
     * @param  list<array<string,mixed>|mixed>  $candidates
     * @return array<int,array<string,mixed>>
     */
    public static function orderFusedSourceRefs(
        array $codeGraphRefs,
        array $memoryRecallRefs,
        array $realityGraphRefs,
        array $candidates,
    ): array {
        $pools = [
            'code' => $codeGraphRefs,
            'memory' => $memoryRecallRefs,
            'reality' => $realityGraphRefs,
        ];
        $ordered = [];
        $seen = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $source = (string) ($candidate['source'] ?? '');
            $ref = trim((string) ($candidate['ref'] ?? ''));
            if ($ref === '' || ! isset($pools[$source])) {
                continue;
            }
            foreach ($pools[$source] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $itemId = (string) ($item['id'] ?? '');
                if ($itemId === '' || isset($seen[$source.':'.$itemId])) {
                    continue;
                }
                if (! self::fusionCandidateMatchesRef($source, $ref, $itemId)) {
                    continue;
                }
                $ordered[] = $item;
                $seen[$source.':'.$itemId] = true;
                break;
            }
        }
        foreach (['code', 'memory', 'reality'] as $source) {
            foreach ($pools[$source] as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $itemId = (string) ($item['id'] ?? '');
                $key = $source.':'.($itemId !== '' ? $itemId : md5(json_encode($item) ?: ''));
                if (isset($seen[$key])) {
                    continue;
                }
                $ordered[] = $item;
                $seen[$key] = true;
            }
        }

        return $ordered;
    }

    public static function fusionCandidateMatchesRef(string $source, string $candidateRef, string $itemId): bool
    {
        if ($itemId === $candidateRef) {
            return true;
        }
        if ($source === 'memory') {
            $suffix = ':'.$candidateRef;

            return str_ends_with($itemId, $suffix);
        }

        return false;
    }

    /**
     * @param  array<int,array<string,mixed>>  ...$refGroups
     * @return array<int,array<string,mixed>>
     */
    public static function mergeRefs(array ...$refGroups): array
    {
        return collect($refGroups)
            ->flatten(1)
            ->filter(fn (mixed $ref): bool => is_array($ref))
            ->unique(fn (array $ref): string => (string) ($ref['type'] ?? 'unknown').':'.(string) ($ref['id'] ?? $ref['slug'] ?? $ref['canonical_path'] ?? $ref['root_path'] ?? md5(json_encode($ref) ?: '')))
            ->values()
            ->all();
    }

    /**
     * Hardening: a ref carrying any unsafe marker (quarantine, require_sanitization,
     * non_instructional_context, hostile_memory, raw_prompt_leakage, raw_prompt_detected)
     * or an explicit provider_safe===false must NEVER reach a rendered provider prompt
     * section. This is the single shared choke point all ref lists pass through before
     * being merged/rendered/hashed.
     */
    public static function isRefProviderSafe(array $ref): bool
    {
        foreach (['quarantine', 'require_sanitization', 'non_instructional_context', 'hostile_memory', 'raw_prompt_leakage', 'raw_prompt_detected'] as $marker) {
            if ((bool) ($ref[$marker] ?? false)) {
                return false;
            }
        }

        return ($ref['provider_safe'] ?? true) !== false;
    }

    /**
     * Filters a ref list through {@see isRefProviderSafe()}, recording which marker(s)
     * caused each omission into $omissionReasons (deduplicated by caller) and bumping
     * $omittedCount for every dropped ref.
     *
     * @param  array<int,array<string,mixed>>  $refs
     * @param  array<int,string>  $omissionReasons
     * @return array<int,array<string,mixed>>
     */
    public static function filterProviderUnsafeRefs(array $refs, array &$omissionReasons, int &$omittedCount): array
    {
        $markers = ['quarantine', 'require_sanitization', 'non_instructional_context', 'hostile_memory', 'raw_prompt_leakage', 'raw_prompt_detected'];

        return array_values(array_filter($refs, function (mixed $ref) use ($markers, &$omissionReasons, &$omittedCount): bool {
            if (! is_array($ref)) {
                return true;
            }

            $reasons = [];
            foreach ($markers as $marker) {
                if ((bool) ($ref[$marker] ?? false)) {
                    $reasons[] = $marker;
                }
            }
            if (($ref['provider_safe'] ?? true) === false) {
                $reasons[] = 'provider_safe_false';
            }

            if ($reasons === []) {
                return true;
            }

            $omittedCount++;
            $omissionReasons = [...$omissionReasons, ...$reasons];

            return false;
        }));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $pack
     * @return array<string,mixed>
     */
    public static function programmingContextSummary(array $payload, array $pack): array
    {
        $devPlan = (array) data_get($payload, 'dev_execution_plan', []);
        $messagePlan = (array) data_get($payload, 'programming_message_plan', []);
        $repair = (array) data_get($payload, 'programming_repair', []);
        $agenticRag = (array) (
            data_get($payload, 'agentic_rag_plan')
            ?: data_get($devPlan, 'agentic_rag_plan')
            ?: data_get($messagePlan, 'agentic_rag_plan')
            ?: []
        );

        $contract = (array) (data_get($devPlan, 'engineering_contract')
            ?: data_get($messagePlan, 'engineering_contract')
            ?: data_get($pack, 'engineering.contract')
            ?: []);

        $flow = self::lowerString(
            data_get($payload, 'programming_flow')
            ?: data_get($devPlan, 'programming_flow')
            ?: data_get($messagePlan, 'programming_flow')
            ?: data_get($payload, 'routing_task')
        );
        $profile = self::lowerString(
            data_get($payload, 'programming_profile')
            ?: data_get($devPlan, 'programming_profile')
            ?: data_get($messagePlan, 'programming_profile')
        );
        $selectedFiles = collect([
            ...(array) data_get($pack, 'selected_files', []),
            ...(array) data_get($contract, 'likely_files', []),
            ...(array) data_get($devPlan, 'selected_files', []),
        ])
            ->filter(fn (mixed $file): bool => is_string($file) && trim($file) !== '')
            ->map(fn (mixed $file): string => trim((string) $file))
            ->unique()
            ->values()
            ->take(20)
            ->all();
        $priorRuns = collect((array) data_get($pack, 'prior_runs', []))
            ->filter(fn (mixed $run): bool => is_array($run))
            ->values()
            ->take(5)
            ->all();
        $previousTraces = collect((array) data_get($pack, 'evidence.previous_traces', []))
            ->filter(fn (mixed $trace): bool => is_array($trace))
            ->values()
            ->take(5)
            ->all();
        $priorDecisions = collect([
            ...(array) data_get($pack, 'decisions', []),
            ...(array) data_get($pack, 'continuity.active_state.decisions', []),
            ...(array) data_get($devPlan, 'prior_decisions', []),
            ...(array) data_get($messagePlan, 'prior_decisions', []),
        ])
            ->filter(fn (mixed $decision): bool => is_array($decision) || (is_scalar($decision) && trim((string) $decision) !== ''))
            ->map(fn (mixed $decision): array => is_array($decision) ? $decision : ['text' => trim((string) $decision)])
            ->values()
            ->take(8)
            ->all();

        return [
            'schema_version' => 'atlas.programming.open_brain_context.v1',
            'flow' => $flow,
            'profile' => $profile,
            'intent' => self::lowerString(data_get($payload, 'programming_intent', data_get($devPlan, 'operator_options.programming_intent'))),
            'resume' => [
                'resumed' => (bool) data_get($devPlan, 'resumed_at') || (bool) data_get($messagePlan, 'resumed_at'),
                'parent_plan_id' => self::lowerString(data_get($devPlan, 'parent_plan_id', data_get($messagePlan, 'parent_plan_id'))),
                'plan_id' => self::lowerString(data_get($devPlan, 'plan_id', data_get($messagePlan, 'plan_id'))),
            ],
            'stage_contract' => [
                'plan' => true,
                'review' => in_array($flow, ['programming.review', 'programming.refactor', 'programming.forge'], true),
                'patch' => ! in_array($flow, ['programming.review'], true),
                'test' => (bool) data_get($devPlan, 'operator_options.auto_test', data_get($messagePlan, 'execution_profile.auto_test', false)),
                'repair' => $flow === 'programming.repair' || (bool) data_get($repair, 'enabled', false),
            ],
            'selected_files' => $selectedFiles,
            'selected_file_count' => count($selectedFiles),
            'prior_run_count' => count($priorRuns),
            'prior_runs' => $priorRuns,
            'previous_trace_count' => count($previousTraces),
            'previous_traces' => $previousTraces,
            'prior_decision_count' => count($priorDecisions),
            'prior_decisions' => $priorDecisions,
            'agentic_rag' => $agenticRag === [] ? null : [
                'schema_version' => data_get($agenticRag, 'schema_version'),
                'status' => data_get($agenticRag, 'status'),
                'required_sources' => (array) data_get($agenticRag, 'required_sources', []),
                'missing_required_sources' => (array) data_get($agenticRag, 'missing_required_sources', []),
                'retrieval_receipt_id' => data_get($agenticRag, 'retrieval_receipt.receipt_id'),
                'context_gate_status' => data_get($agenticRag, 'context_sufficiency_gate.status'),
                'semantic_node_count' => data_get($agenticRag, 'semantic_code_graph.node_count'),
                'semantic_edge_count' => data_get($agenticRag, 'semantic_code_graph.edge_count'),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     * @param  array<string,mixed>  $policy
     * @param  array<int,array<string,mixed>>  $knowledgeRefs
     * @param  array<int,array<string,mixed>>  $codeRefs
     * @param  array<int,array<string,mixed>>  $codeGraphRefs
     * @param  array<int,array<string,mixed>>  $memoryRecallRefs
     * @param  array<int,array<string,mixed>>  $realityGraphRefs
     * @param  array<string,mixed>|null  $contextDeliveryPolicy
     * @param  array<int,string>  $warnings
     */
    public static function promptSection(
        string $taskType,
        string $desiredMode,
        string $contextPackPromptSection,
        string $contextPackHash,
        array $summary,
        array $policy,
        ?array $memoryQuality,
        array $knowledgeRefs,
        array $codeRefs,
        array $codeGraphRefs,
        array $memoryRecallRefs,
        array $realityGraphRefs,
        ?array $contextDeliveryPolicy,
        array $warnings,
    ): string {
        $lines = [
            '# Atlas Open Brain Context',
            '',
            'Use este bloco como contexto provider-safe montado pelo Atlas. Ele e auditavel, pequeno e subordinado a privacy/redaction.',
            '- status: pending_audit',
            '- surface: '.($policy['surface'] ?? 'unknown'),
            '- task_type: '.$taskType,
            '- desired_mode: '.$desiredMode,
            '- context_pack_hash: '.$contextPackHash,
            '- refs: memory='.$summary['memory_refs'].'; verbatim='.$summary['verbatim_refs'].'; semantic='.$summary['semantic_refs'].'; operator='.$summary['operator_profile_refs'].'; knowledge='.$summary['knowledge_refs'].'; code='.$summary['code_refs'],
        ];

        if (is_array($summary['retrieval_plan'] ?? null)) {
            $retrieval = $summary['retrieval_plan'];
            $lines[] = '- retrieval_plan: mode='.($retrieval['mode'] ?? 'unknown')
                .'; selected='.implode(',', (array) ($retrieval['selected_sources'] ?? []))
                .'; required='.implode(',', (array) ($retrieval['required_sources'] ?? []));
        }

        if ($warnings !== []) {
            $lines[] = '- warnings: '.implode(', ', $warnings);
        }

        if ($contextDeliveryPolicy !== null) {
            $lines[] = '- context_delivery: mode='.($contextDeliveryPolicy['delivery_mode'] ?? 'unknown')
                .'; initial_tokens='.(int) ($contextDeliveryPolicy['initial_context_token_budget'] ?? 0)
                .'; expansion_reserve='.(int) ($contextDeliveryPolicy['expansion_token_reserve'] ?? 0)
                .'; handles='.(int) data_get($summary, 'context_delivery_policy.expansion_handle_count', 0);
        }

        if ($memoryQuality !== null) {
            $qualitySummary = self::memoryQualitySummary($memoryQuality);
            $lines[] = '';
            $lines[] = '## Memory Quality Gate';
            $lines[] = '- status: '.($qualitySummary['status'] ?? 'unknown').'; score='.($qualitySummary['score'] ?? 'n/a').'; ok='.(YesNo::trueFalse($qualitySummary['ok'] ?? false));
            $lines[] = '- active: '.($qualitySummary['active'] ?? 'n/a').'; provider_safe_active='.($qualitySummary['provider_safe_active'] ?? 'n/a');
            if (is_array($qualitySummary['latest_snapshot'] ?? null) && $qualitySummary['latest_snapshot'] !== []) {
                $snapshot = $qualitySummary['latest_snapshot'];
                $lines[] = '- latest_snapshot: status='.($snapshot['status'] ?? 'n/a').'; score='.($snapshot['score'] ?? 'n/a').'; at='.($snapshot['snapshot_at'] ?? 'n/a');
            }
            if (is_array($qualitySummary['trend'] ?? null) && $qualitySummary['trend'] !== []) {
                $trend = $qualitySummary['trend'];
                $lines[] = '- trend: status='.($trend['status'] ?? 'n/a')
                    .'; current_delta_from_latest='.($trend['current_delta_from_latest'] ?? 'n/a')
                    .'; latest_delta_from_previous='.($trend['latest_delta_from_previous'] ?? 'n/a')
                    .'; snapshots='.($trend['snapshot_count'] ?? 'n/a');
                $drivers = array_values((array) ($trend['drivers'] ?? []));
                if ($drivers !== []) {
                    $lines[] = '- trend_drivers: '.collect($drivers)
                        ->map(fn (array $driver): string => (string) ($driver['key'] ?? 'unknown').':'.(string) ($driver['delta'] ?? 'n/a'))
                        ->implode(', ');
                }
            }
            $issues = array_values((array) ($qualitySummary['issues'] ?? []));
            if ($issues !== []) {
                $lines[] = '- issues: '.collect($issues)
                    ->map(fn (array $issue): string => (string) ($issue['severity'] ?? 'info').':'.(string) ($issue['code'] ?? 'unknown'))
                    ->implode(', ');
            }
        }

        if (is_array($summary['self_reflection'] ?? null)) {
            $reflection = $summary['self_reflection'];
            $lines[] = '';
            $lines[] = '## Context Pack Self-Reflection Gate';
            $lines[] = '- schema: '.($reflection['schema_version'] ?? 'unknown');
            $lines[] = '- status: '.($reflection['status'] ?? 'unknown').'; recommended_action='.($reflection['recommended_action'] ?? 'n/a');
            $reasons = array_values((array) ($reflection['reasons'] ?? []));
            if ($reasons !== []) {
                $lines[] = '- reasons: '.implode(', ', $reasons);
            }
        }

        if (is_array($summary['programming_context'] ?? null)) {
            $programming = $summary['programming_context'];
            $lines[] = '';
            $lines[] = '## Programming Context';
            $lines[] = '- schema: '.($programming['schema_version'] ?? 'unknown');
            $lines[] = '- flow: '.($programming['flow'] ?: 'n/a').'; profile='.($programming['profile'] ?: 'n/a').'; intent='.($programming['intent'] ?: 'n/a');
            $lines[] = '- resume: '.(YesNo::trueFalse(data_get($programming, 'resume.resumed')))
                .'; plan_id='.(data_get($programming, 'resume.plan_id') ?: 'n/a')
                .'; parent_plan_id='.(data_get($programming, 'resume.parent_plan_id') ?: 'n/a');
            $stage = (array) ($programming['stage_contract'] ?? []);
            $lines[] = '- stages: plan='.(YesNo::trueFalse($stage['plan'] ?? false))
                .'; review='.(YesNo::trueFalse($stage['review'] ?? false))
                .'; patch='.(YesNo::trueFalse($stage['patch'] ?? false))
                .'; test='.(YesNo::trueFalse($stage['test'] ?? false))
                .'; repair='.(YesNo::trueFalse($stage['repair'] ?? false));
            if (is_array($programming['agentic_rag'] ?? null)) {
                $rag = $programming['agentic_rag'];
                $lines[] = '- agentic_rag: status='.($rag['status'] ?? 'unknown')
                    .'; context_gate='.($rag['context_gate_status'] ?? 'unknown')
                    .'; receipt='.($rag['retrieval_receipt_id'] ?? 'n/a');
                $required = array_values((array) ($rag['required_sources'] ?? []));
                if ($required !== []) {
                    $lines[] = '- agentic_rag_required_sources: '.implode(', ', $required);
                }
                $missing = array_values((array) ($rag['missing_required_sources'] ?? []));
                if ($missing !== []) {
                    $lines[] = '- agentic_rag_missing_sources: '.implode(', ', $missing);
                }
            }
            $selectedFiles = array_values((array) ($programming['selected_files'] ?? []));
            if ($selectedFiles !== []) {
                $lines[] = '- selected_files: '.implode(', ', array_slice($selectedFiles, 0, 12));
            }
            $lines[] = '- history: prior_runs='.(int) ($programming['prior_run_count'] ?? 0)
                .'; previous_traces='.(int) ($programming['previous_trace_count'] ?? 0)
                .'; prior_decisions='.(int) ($programming['prior_decision_count'] ?? 0);
        }

        if ($contextDeliveryPolicy !== null) {
            $lines[] = '';
            $lines[] = '## Context Delivery Policy';
            $lines[] = '- schema: '.($contextDeliveryPolicy['schema_version'] ?? 'unknown');
            $lines[] = '- mode: '.($contextDeliveryPolicy['delivery_mode'] ?? 'unknown')
                .'; status=active'
                .'; source='.($contextDeliveryPolicy['source'] ?? 'unknown')
                .'; advisory=true';
            $lines[] = '- initial: tokens='.(int) ($contextDeliveryPolicy['initial_context_token_budget'] ?? 0)
                .'; ref_limit='.(int) ($contextDeliveryPolicy['initial_ref_limit'] ?? 0)
                .'; expansion_reserve='.(int) ($contextDeliveryPolicy['expansion_token_reserve'] ?? 0);
            foreach ([
                'initial_source_types' => 'initial_sources',
                'deferred_source_types' => 'deferred_sources',
                'guarded_required_source_types' => 'guarded_required_sources',
                'expansion_triggers' => 'expansion_triggers',
            ] as $key => $label) {
                $values = array_values((array) ($contextDeliveryPolicy[$key] ?? []));
                if ($values !== []) {
                    $lines[] = '- '.$label.': '.implode(', ', array_slice($values, 0, 12));
                }
            }
            $lines[] = '- quality_gate_hint: '.($contextDeliveryPolicy['quality_gate_hint'] ?? 'feedback_guided_staging_allowed');
            $deferredHandles = array_map(static fn (mixed $source): string => 'expand:'.(string) $source, array_values((array) ($contextDeliveryPolicy['deferred_source_types'] ?? [])));
            $guardedHandles = array_map(static fn (mixed $source): string => 'recheck:'.(string) $source, array_values((array) ($contextDeliveryPolicy['guarded_required_source_types'] ?? [])));
            $expansionHandles = array_values(array_filter(array_merge($deferredHandles, $guardedHandles)));
            if ($expansionHandles !== []) {
                $lines[] = '- expansion_tool: mcp=atlas_context_expand; cli="./bin/atlas open-brain expand-context <handle> \"<objective>\" --json"';
                $lines[] = '- expansion_handles: '.implode(', ', array_slice($expansionHandles, 0, 16));
            }
            if ($guardedHandles !== []) {
                $lines[] = '- implementation_gate: expand guarded handles before code changes.';
            }
            $lines[] = '- policy: provider_safe_only=true; raw_text_exposed=false; providers_invoked=false; writes=false';
        }

        if (is_array($summary['operator_context'] ?? null)) {
            $operator = $summary['operator_context'];
            $lines[] = '';
            $lines[] = '## Operator Intelligence';
            $lines[] = '- schema: '.($operator['schema_version'] ?? 'unknown');
            $lines[] = '- status: '.($operator['status'] ?? 'unknown')
                .'; reason='.($operator['reason'] ?? 'unknown')
                .'; items='.(int) ($operator['item_count'] ?? 0)
                .'; omitted='.(int) ($operator['omitted_count'] ?? 0)
                .'; flow='.(($operator['flow'] ?? null) ?: 'n/a');
            $profileKeys = array_values((array) ($operator['profile_keys'] ?? []));
            if ($profileKeys !== []) {
                $lines[] = '- profile_keys: '.implode(', ', $profileKeys);
            }
            $effects = array_values((array) ($operator['effects'] ?? []));
            if ($effects !== []) {
                $lines[] = '- effects: '.implode(', ', $effects);
            }
            $operatorItems = collect((array) data_get($summary, 'operator_context_items', []))
                ->filter(fn (mixed $item): bool => is_array($item))
                ->take(8)
                ->values()
                ->all();
            foreach ($operatorItems as $item) {
                $lines[] = '- '.self::providerSafeOperatorItemLine($item);
            }
        }

        // F3/F5 (Salto 1 — AURG vivo): the brain's TOP cross-layer chains, one line per path —
        // the REAL node kinds + stored edge kinds as the chain label (read from the ref's
        // own fields, never the 'atlas_reality_path' envelope type), then the
        // human-readable node labels with [src=source_kind] provenance tags.
        // Empty (flag OFF or no reached paths) → nothing rendered → byte-identical prompt.
        // PLACEMENT IS LOAD-BEARING (F5 live-proof finding): this block renders BEFORE the
        // bulky knowledge/code/code-graph ref lists because the section budget truncates
        // from the TAIL (Str::limit) — at the old tail position a routine >budget section
        // (code-graph auto-context ON) silently dropped these ~6 compact lines every time,
        // making the include_reality_graph flag a no-op in exactly the prompts it serves.
        if ($realityGraphRefs !== []) {
            $lines[] = '';
            $lines[] = '## Atlas Unified Reality Graph';
            foreach ($realityGraphRefs as $ref) {
                $chain = collect((array) ($ref['nodes'] ?? []))
                    ->filter(fn (mixed $node): bool => is_array($node))
                    ->map(function (array $node): string {
                        $label = is_scalar($node['label'] ?? null) ? trim((string) $node['label']) : '';

                        return ($label !== '' ? Str::limit($label, 100, '...') : 'n/a')
                            .' [src='.(($node['source_kind'] ?? '') !== '' ? $node['source_kind'] : 'n/a').']';
                    })
                    ->implode(' -> ');
                $confidenceMin = $ref['confidence_min'] ?? null;
                $lines[] = '- '.(($ref['chain_label'] ?? '') !== '' ? $ref['chain_label'] : 'path').': '.$chain
                    .'; cross_layer='.(YesNo::trueFalse($ref['cross_layer'] ?? false))
                    .'; confidence_min='.(is_numeric($confidenceMin) ? (string) $confidenceMin : 'n/a');
            }
        }

        // R4 (PART A): the operator's accrued, provider-safe SEMANTIC memory recall
        // (decisions/learnings) ranked by AtlasHybridMemoryRetrievalService::recall.
        // Empty (flag OFF or no matched memory) → nothing rendered → byte-identical prompt.
        // PLACEMENT IS LOAD-BEARING (same F5 live-proof finding as the reality-graph block
        // above): this block renders BEFORE the bulky knowledge/code/code-graph ref lists
        // because the section budget truncates from the TAIL (Str::limit) — at the old tail
        // position a routine >budget section (code-graph auto-context ON) silently dropped
        // the recall block from every prompt, making the include_memory_recall flag a no-op
        // in exactly the prompts it serves.
        if ($memoryRecallRefs !== []) {
            $lines[] = '';
            $lines[] = '## Atlas Memory Recall';
            foreach ($memoryRecallRefs as $ref) {
                $title = is_scalar($ref['title'] ?? null) ? trim((string) $ref['title']) : '';
                $summaryText = is_scalar($ref['summary'] ?? null) ? trim((string) $ref['summary']) : '';
                // The recalled memory's REAL type (decision/learning/principle/...) is carried
                // in `memory_type`; `type` is the ref envelope ('atlas_memory_recall') and would
                // mislabel every line. Read memory_type first, fall back to the envelope type.
                $memoryType = is_scalar($ref['memory_type'] ?? null) && trim((string) $ref['memory_type']) !== ''
                    ? trim((string) $ref['memory_type'])
                    : (is_scalar($ref['type'] ?? null) ? trim((string) $ref['type']) : '');
                $lines[] = '- '.($title !== '' ? $title : 'memoria')
                    .' [type='.($memoryType !== '' ? $memoryType : 'n/a')
                    .'; scope='.(($ref['scope'] ?? '') !== '' ? $ref['scope'] : 'n/a').']'
                    .($summaryText !== '' ? ' - '.Str::limit($summaryText, 220, '...') : '')
                    .'; reason='.(($ref['reason'] ?? '') !== '' ? $ref['reason'] : 'recall provider-safe');
            }
        }

        if ($knowledgeRefs !== []) {
            $lines[] = '';
            $lines[] = '## Canonical Engineering Knowledge';
            foreach ($knowledgeRefs as $ref) {
                $lines[] = '- '.($ref['title'] ?? $ref['slug'] ?? 'doc').' ['.($ref['canonical_path'] ?? 'n/a').'] - '.($ref['summary'] ?? $ref['reason'] ?? 'canonical doc');
            }
        }

        if ($codeRefs !== []) {
            $lines[] = '';
            $lines[] = '## Code Intelligence Refs';
            foreach ($codeRefs as $ref) {
                $lines[] = '- '.($ref['name'] ?? $ref['slug'] ?? 'module').' ['.($ref['root_path'] ?? 'n/a').'] layer='.($ref['layer'] ?? 'n/a').'; symbols='.($ref['symbol_count'] ?? 0).'; tests='.($ref['test_count'] ?? 0).'; reason='.($ref['reason'] ?? 'code context');
            }
        }

        // AP-815 I-4 (Stage 2): the budgeted, BM25-ranked code-graph symbols for this task.
        // Empty (flag OFF or no matches) → nothing rendered → byte-identical prompt.
        if ($codeGraphRefs !== []) {
            $lines[] = '';
            $lines[] = '## Code Graph Context';
            foreach ($codeGraphRefs as $ref) {
                // Render the signature VERBATIM (case-preserving) — it is code, not a
                // normalisable token, so the lowercasing string() helper is NOT used here.
                $signature = is_scalar($ref['signature'] ?? null) ? trim((string) $ref['signature']) : '';
                $lines[] = '- '.($ref['id'] ?? 'symbol')
                    .' ['.($ref['file_path'] ?? 'n/a').']'
                    .' type='.(($ref['symbol_type'] ?? '') !== '' ? $ref['symbol_type'] : 'n/a')
                    .'; tokens='.(int) ($ref['tokens'] ?? 0)
                    .($signature !== '' ? '; sig='.Str::limit($signature, 200, '...') : '');
            }
        }

        $lines[] = '';
        $lines[] = $contextPackPromptSection;

        return implode("\n", $lines);
    }

    /**
     * @param  array<string,mixed>  $item
     */
    public static function providerSafeOperatorItemLine(array $item): string
    {
        $summary = Str::limit((string) ($item['summary'] ?? ''), 220, '...');

        return 'profile_key='.($item['profile_key'] ?? 'n/a')
            .'; taxonomy='.($item['taxonomy_item_id'] ?? 'n/a')
            .'; effect='.($item['effect'] ?? 'n/a')
            .'; confidence='.($item['confidence'] ?? 'n/a')
            .'; automation='.($item['automation_level'] ?? 'n/a')
            .'; summary='.$summary;
    }

    public static function lowerString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== ''
            ? Str::of((string) $value)->lower()->trim()->value()
            : null;
    }
}
