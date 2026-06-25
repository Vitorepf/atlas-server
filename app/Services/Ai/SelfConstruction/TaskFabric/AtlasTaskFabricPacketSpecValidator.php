<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;

/**
 * Task Fabric PREFLIGHT — validates draft packet specs BEFORE they reach the existing
 * {@see AtlasTaskPacketQualityInspector}. Returns FACTS only — no queue mutation.
 *
 * REQUIRED SPEC FIELDS:
 *   { objective, allowed_files:list<string>, scope_in:list<string>, acceptance_criteria:list<string>,
 *     required_evidence:list<string>, rollback_hint:string, workspace_policy:array, simplicity_contract:string }
 *
 * BLOCKER FAMILIES:
 *   - missing_objective / missing_acceptance_criteria / missing_required_evidence / missing_rollback_hint
 *   - allowed_files:broad_directory:<path>   (entries without basename '.')
 *   - workspace_policy:non_shared_main       (workspace_policy.execution_topology must be
 *                                             'shared_local_main_with_scope_lock')
 *   - ownership:non_atlas_native             (simplicity_contract must be 'atlas_native')
 *   - missing_gates                          (no acceptance_criteria entry mentions a gate)
 *
 * INVARIANTS:
 *   - DETERMINISTIC: identical input ⇒ byte-identical envelope.
 *   - NEVER mutates the queue. Optionally DELEGATES to AtlasTaskPacketQualityInspector::inspect()
 *     when a full packet shape is supplied (containing 'task_packet_id') — the inspector is read-only.
 */
final class AtlasTaskFabricPacketSpecValidator
{
    public const SCHEMA = 'atlas.taskfabric.packet_spec_validation.v1';

    public const REQUIRED_TOPOLOGY = 'shared_local_main_with_scope_lock';

    public const REQUIRED_SIMPLICITY = 'atlas_native';

    /** @param null|AtlasTaskPacketQualityInspector|object{inspect:callable} $inspector */
    public function __construct(private readonly ?object $inspector = null) {}

    /**
     * @param  array<string,mixed>  $spec
     * @return array{schema:string, self_sufficient:bool, blockers:list<string>, delegated_inspector:array<string,mixed>|null}
     */
    public function validate(array $spec): array
    {
        $blockers = [];

        if (trim((string) ($spec['objective'] ?? '')) === '') {
            $blockers[] = 'missing_objective';
        }

        $allowed = is_array($spec['allowed_files'] ?? null) ? array_values($spec['allowed_files']) : null;
        if ($allowed === null || $allowed === []) {
            $blockers[] = 'missing_allowed_files';
        } else {
            foreach ($allowed as $path) {
                $p = (string) $path;
                if ($p === '' || str_ends_with($p, '/') || ! str_contains(basename($p), '.')) {
                    $blockers[] = 'allowed_files:broad_directory:'.$p;
                }
            }
        }

        $scopeIn = is_array($spec['scope_in'] ?? null) ? array_values($spec['scope_in']) : null;
        if ($scopeIn === null || $scopeIn === []) {
            $blockers[] = 'missing_scope_in';
        }

        $acceptance = is_array($spec['acceptance_criteria'] ?? null) ? array_values(array_map('strval', $spec['acceptance_criteria'])) : null;
        if ($acceptance === null || $acceptance === []) {
            $blockers[] = 'missing_acceptance_criteria';
        } elseif (! $this->mentionsGate($acceptance)) {
            $blockers[] = 'missing_gates';
        }

        $evidence = is_array($spec['required_evidence'] ?? null) ? array_values($spec['required_evidence']) : null;
        if ($evidence === null || $evidence === []) {
            $blockers[] = 'missing_required_evidence';
        }

        if (trim((string) ($spec['rollback_hint'] ?? '')) === '') {
            $blockers[] = 'missing_rollback_hint';
        }

        $wp = is_array($spec['workspace_policy'] ?? null) ? $spec['workspace_policy'] : null;
        if ($wp === null) {
            $blockers[] = 'missing_workspace_policy';
        } elseif ((string) ($wp['execution_topology'] ?? '') !== self::REQUIRED_TOPOLOGY) {
            $blockers[] = 'workspace_policy:non_shared_main';
        }

        $simplicity = (string) ($spec['simplicity_contract'] ?? '');
        if ($simplicity !== self::REQUIRED_SIMPLICITY) {
            $blockers[] = 'ownership:non_atlas_native';
        }

        $delegated = null;
        if ($this->inspector !== null && isset($spec['task_packet_id'])) {
            // Delegate to the existing inspector for the full quality check. Read-only.
            $delegated = $this->inspector->inspect($spec);
        }

        sort($blockers, SORT_STRING);

        return [
            'schema' => self::SCHEMA,
            'self_sufficient' => $blockers === [],
            'blockers' => $blockers,
            'delegated_inspector' => $delegated,
        ];
    }

    /**
     * @param  list<string>  $acceptance
     */
    private function mentionsGate(array $acceptance): bool
    {
        foreach ($acceptance as $line) {
            if (preg_match('/phpunit|pint|pest|test|gate|verify|assert/i', $line)) {
                return true;
            }
        }

        return false;
    }
}
