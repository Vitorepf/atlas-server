<?php

namespace App\Services\Ai\SelfConstruction\Support;

/**
 * Builds a deterministic, ordered validation plan from a context describing
 * an agent's allowed_files, changed_files and requested gates.
 *
 * The plan is metadata only. It says which gates SHOULD run, in what order,
 * with what dependencies and what abort conditions. It does NOT execute any
 * gate and does NOT produce a real validation result.
 *
 * Read-only: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming, never writes the ledger.
 */
final class AgentValidationGatePlanBuilder
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_validation_gate_plan.v1';

    public const MODE = 'read_only_agent_validation_gate_plan';

    /** Canonical execution order (descending blast radius first). */
    public const CANONICAL_ORDER = [
        'scope_check',
        'rollback_plan_check',
        'evidence_check',
        'continuation_summary_check',
        'php_lint',
        'diff_check',
        'docs_health',
        'architecture_validate',
        'focused_tests',
        'unit_tests',
    ];

    public function __construct(private readonly AgentValidationGateCatalog $catalog = new AgentValidationGateCatalog) {}

    /**
     * @param  array<string, mixed>  $context  allowed_files, forbidden_files, changed_files, requested_gates, focused_filter
     * @return array<string, mixed>
     */
    public function buildPlan(array $context = []): array
    {
        $allowedFiles = $this->normalizeFiles($context['allowed_files'] ?? []);
        $forbiddenFiles = $this->normalizeFiles($context['forbidden_files'] ?? []);
        $changedFiles = $this->normalizeFiles($context['changed_files'] ?? []);
        $requested = $this->normalizeRequestedGates($context['requested_gates'] ?? null);
        $focusedFilter = isset($context['focused_filter']) ? trim((string) $context['focused_filter']) : '';

        $gates = $this->catalog->gates();
        $orderedGateIds = [];
        $unknownRequested = [];
        $included = $requested === null ? array_keys($gates) : [];

        if ($requested !== null) {
            foreach ($requested as $id) {
                if (! isset($gates[$id])) {
                    $unknownRequested[] = $id;

                    continue;
                }
                $included[] = $id;
            }
        }
        $included = array_values(array_unique($included));

        foreach (self::CANONICAL_ORDER as $id) {
            if (in_array($id, $included, true)) {
                $orderedGateIds[] = $id;
            }
        }

        $orderedRuns = [];
        $previous = null;
        $abortConditions = [];
        foreach ($orderedGateIds as $position => $id) {
            $gate = $gates[$id];
            $deps = $previous === null ? [] : [$previous];
            $skipUnless = $this->skipUnless($id, $context);
            $orderedRuns[] = [
                'position' => $position,
                'gate_id' => $id,
                'gate_name' => $gate['name'],
                'gate_type' => $gate['type'],
                'severity' => $gate['severity'],
                'blocking' => $gate['blocking'],
                'requires_command' => $gate['requires_command'],
                'command' => $this->renderCommand($gate, $focusedFilter),
                'expected_artifact' => $gate['expected_artifact'],
                'dependencies' => $deps,
                'skip_unless' => $skipUnless,
                'abort_on_failure' => $gate['blocking'] === true,
            ];
            if ($gate['blocking'] === true) {
                $abortConditions[] = [
                    'gate_id' => $id,
                    'reason' => 'blocking_gate_failure_aborts_plan',
                ];
            }
            $previous = $id;
        }

        $forbiddenIntersection = WriteSetOverlap::collidingPaths($changedFiles, $forbiddenFiles);
        $unknownChanged = $allowedFiles === []
            ? []
            : array_values(array_diff($changedFiles, $allowedFiles, $forbiddenFiles));

        $scopeViolation = $forbiddenIntersection !== [] || $unknownChanged !== [];

        $planQuality = match (true) {
            $unknownRequested !== [] => 'blocked_unknown_gate_request',
            $scopeViolation => 'blocked_scope_violation',
            default => 'ok',
        };

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $orderedRuns === [] ? 'empty_plan' : 'plan_ready',
            'plan_quality' => $planQuality,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'plan_id' => $this->planId($orderedGateIds, $context),
            'requested_gate_ids' => $requested === null ? array_keys($gates) : $requested,
            'unknown_requested_gate_ids' => $unknownRequested,
            'ordered_gate_ids' => $orderedGateIds,
            'ordered_runs' => $orderedRuns,
            'abort_conditions' => $abortConditions,
            'context_summary' => [
                'allowed_file_count' => count($allowedFiles),
                'forbidden_file_count' => count($forbiddenFiles),
                'changed_file_count' => count($changedFiles),
                'focused_filter_present' => $focusedFilter !== '',
                'focused_filter' => $focusedFilter,
                'scope_violation_detected' => $scopeViolation,
                'forbidden_files_touched' => $forbiddenIntersection,
                'unknown_files_touched' => $unknownChanged,
            ],
            'safety_invariants' => [
                'no_real_command_execution',
                'no_provider_call',
                'no_token_spend',
                'no_self_programming',
                'no_ledger_write',
                'plan_does_not_authorize_runtime',
            ],
            'plan_hash' => $this->planHash($orderedGateIds, $context, $focusedFilter),
            'runtime_safety' => [
                'runtime_safety_all_false' => true,
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'self_programming_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
            ],
        ];

        return $payload;
    }

    /**
     * @param  mixed  $files
     * @return array<int, string>
     */
    private function normalizeFiles($files): array
    {
        if (! is_array($files)) {
            return [];
        }
        $out = [];
        foreach ($files as $f) {
            if (is_string($f) && $f !== '') {
                $out[] = $f;
            }
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /**
     * @param  mixed  $requested
     * @return array<int, string>|null  null = all gates
     */
    private function normalizeRequestedGates($requested): ?array
    {
        if ($requested === null) {
            return null;
        }
        if (! is_array($requested)) {
            return [];
        }
        $out = [];
        foreach ($requested as $r) {
            if (is_string($r) && $r !== '') {
                $out[] = $r;
            }
        }

        return array_values(array_unique($out));
    }

    /** @param array<string, mixed> $gate */
    private function renderCommand(array $gate, string $focusedFilter): string
    {
        $cmd = (string) $gate['command'];
        if ($gate['id'] === 'focused_tests' && $focusedFilter !== '') {
            return str_replace('<focused_filter>', $focusedFilter, $cmd);
        }

        return $cmd;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, string>
     */
    private function skipUnless(string $id, array $context): array
    {
        return match ($id) {
            'focused_tests' => ['focused_filter_present'],
            'docs_health' => ['docs_changed_or_added'],
            'architecture_validate' => ['architecture_or_governance_changed'],
            'diff_check' => ['working_tree_has_changes'],
            default => [],
        };
    }

    /**
     * @param  array<int, string>  $orderedIds
     * @param  array<string, mixed>  $context
     */
    private function planId(array $orderedIds, array $context): string
    {
        $payload = [
            'ordered' => $orderedIds,
            'allowed' => $this->normalizeFiles($context['allowed_files'] ?? []),
            'forbidden' => $this->normalizeFiles($context['forbidden_files'] ?? []),
            'changed' => $this->normalizeFiles($context['changed_files'] ?? []),
        ];

        return 'plan-'.substr(hash('sha256', (string) json_encode($payload)), 0, 16);
    }

    /**
     * @param  array<int, string>  $orderedIds
     * @param  array<string, mixed>  $context
     */
    private function planHash(array $orderedIds, array $context, string $focusedFilter): string
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'ordered' => $orderedIds,
            'allowed' => $this->normalizeFiles($context['allowed_files'] ?? []),
            'forbidden' => $this->normalizeFiles($context['forbidden_files'] ?? []),
            'changed' => $this->normalizeFiles($context['changed_files'] ?? []),
            'focused_filter' => $focusedFilter,
            'catalog_hash' => $this->catalog->hash(),
        ];

        return hash('sha256', (string) json_encode($payload));
    }
}
