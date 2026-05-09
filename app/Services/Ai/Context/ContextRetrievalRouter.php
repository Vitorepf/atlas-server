<?php

namespace App\Services\Ai\Context;

use App\Services\Ai\ValueObjects\AiTaskRequest;
use Illuminate\Support\Str;

final class ContextRetrievalRouter
{
    public const SCHEMA_VERSION = 'atlas.context.retrieval_plan.v1';

    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function plan(string $input, AiTaskRequest $task, array $payload = [], array $options = []): array
    {
        $taskData = $task->toArray();
        $text = Str::lower($input.' '.json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $risk = (string) ($taskData['risk_level'] ?? 'low');
        $taskType = (string) ($taskData['task_type'] ?? 'direct');
        $domain = (string) ($taskData['domain'] ?? 'unknown');
        $mode = (string) ($taskData['desired_mode'] ?? 'direct');

        $sources = [
            $this->source('vector_retrieval', true, 'semantic_similarity_baseline', 10, false, 'implemented_partial', true, 'laravel_kernel', 'docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md'),
            $this->source('memory_signals', ($options['include_memory_registry'] ?? true) !== false, 'operator_memory_and_preferences', 8, false, 'implemented_ready', true, 'laravel_kernel', 'docs/engineering-knowledge-base/memory-core-contracts.md'),
            $this->source('code_intelligence', $this->needsCode($taskType, $domain, $mode, $text), 'programming_or_debug_context', 12, false, 'implemented_ready', true, 'laravel_kernel', 'docs/engineering-knowledge-base/code-intelligence.md'),
            $this->source('evidence_replay', $this->needsEvidence($payload, $taskType, $risk, $text), 'audit_replay_or_high_risk_context', 8, in_array($risk, ['high', 'irreversible'], true), 'implemented_ready', true, 'laravel_kernel', 'docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md'),
            $this->source('graph_retrieval', $this->needsGraph($taskType, $domain, $text), 'relationship_causality_dependency_context', 6, false, 'future_governed', false, 'python_ai_data_candidate', 'docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md'),
        ];

        $selected = array_values(array_filter($sources, fn (array $source): bool => (bool) $source['enabled']));
        usort($selected, fn (array $a, array $b): int => ((int) $a['priority']) <=> ((int) $b['priority']));
        $unavailableSelected = array_values(array_filter($selected, fn (array $source): bool => ! (bool) $source['available']));
        $requiredUnavailable = array_values(array_filter($unavailableSelected, fn (array $source): bool => (bool) $source['required']));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'query_hash' => hash('sha256', trim($input)),
            'mode' => $this->mode($selected, $risk, $taskType),
            'selected_sources' => $selected,
            'skipped_sources' => array_values(array_filter($sources, fn (array $source): bool => ! (bool) $source['enabled'])),
            'budgets' => [
                'max_sources' => count($selected),
                'max_context_refs' => max(8, array_sum(array_map(fn (array $source): int => (int) $source['limit'], $selected))),
                'provider_safe_only' => true,
            ],
            'policy' => [
                'provider_safe_only' => true,
                'do_not_create_parallel_memory' => true,
                'router_decides_sources_only' => true,
                'provider_bypass_allowed' => false,
            ],
            'readiness' => [
                'status' => $requiredUnavailable !== [] ? 'blocked' : ($unavailableSelected !== [] ? 'degraded' : 'ready'),
                'unavailable_selected_sources' => array_column($unavailableSelected, 'type'),
                'required_unavailable_sources' => array_column($requiredUnavailable, 'type'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function source(
        string $type,
        bool $enabled,
        string $reason,
        int $limit,
        bool $required,
        string $status,
        bool $available,
        string $runtime,
        string $ownerDoc,
    ): array {
        return [
            'type' => $type,
            'enabled' => $enabled,
            'available' => $available,
            'status' => $status,
            'runtime' => $runtime,
            'owner_doc' => $ownerDoc,
            'reason' => $reason,
            'priority' => match ($type) {
                'evidence_replay' => 10,
                'code_intelligence' => 20,
                'memory_signals' => 30,
                'graph_retrieval' => 40,
                default => 50,
            },
            'limit' => $limit,
            'required' => $required,
            'provider_bypass_allowed' => false,
            'unavailable_action' => $required ? 'fail_closed_or_request_review' : 'degrade_with_review_signal',
        ];
    }

    private function needsCode(string $taskType, string $domain, string $mode, string $text): bool
    {
        return in_array($taskType, ['dev', 'debug', 'review', 'quality_repair'], true)
            || in_array($mode, ['dev', 'debug', 'review', 'programming', 'quality_repair'], true)
            || in_array($domain, ['developer', 'programming', 'atlas_programming'], true)
            || Str::contains($text, ['codigo', 'código', 'repo', 'classe', 'teste', 'bug', 'patch']);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function needsEvidence(array $payload, string $taskType, string $risk, string $text): bool
    {
        return in_array($taskType, ['debug', 'review', 'quality_repair'], true)
            || in_array($risk, ['high', 'irreversible'], true)
            || data_get($payload, 'envelope_id') !== null
            || data_get($payload, 'trace_id') !== null
            || data_get($payload, 'receipt_id') !== null
            || data_get($payload, 'run_id') !== null
            || Str::contains($text, ['auditoria', 'replay', 'evidencia', 'evidência', 'falhou', 'regressao', 'regressão']);
    }

    private function needsGraph(string $taskType, string $domain, string $text): bool
    {
        return in_array($taskType, ['planning', 'decision', 'research'], true)
            || in_array($domain, ['finance', 'marketing', 'personal_development', 'self_improvement'], true)
            || Str::contains($text, ['arquitetura', 'dependencia', 'dependência', 'relacao', 'relação', 'causa', 'impacto', 'tradeoff', 'fluxo']);
    }

    /**
     * @param  array<int,array<string,mixed>>  $selected
     */
    private function mode(array $selected, string $risk, string $taskType): string
    {
        if (in_array($risk, ['high', 'irreversible'], true)) {
            return 'audit_heavy';
        }

        if (count($selected) >= 4 || in_array($taskType, ['planning', 'decision', 'research'], true)) {
            return 'deep';
        }

        return 'balanced';
    }
}
