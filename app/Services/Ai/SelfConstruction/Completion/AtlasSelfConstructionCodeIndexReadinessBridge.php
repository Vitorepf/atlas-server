<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Completion;

/**
 * Self-Construction bridge that converts Code Intelligence status / readiness / automatic gate /
 * schema drift facts into a single final-autonomy evidence verdict.
 *
 * Pure, facts-only, deterministic. NEVER queries the DB or filesystem.
 * The final OS MUST NOT claim ready while code indexing is missing, stale, or schema-drifted.
 *
 * Status precedence:
 *   - blocked: schema drift / missing schema / missing automatic gate facts
 *   - hold:    index empty or stale (refreshable)
 *   - ready:   status=ready, readiness ∈ {ready, watch} with no blocking findings, gate ∈ {ready, watch},
 *              schema drift auditor verdict passed
 */
final class AtlasSelfConstructionCodeIndexReadinessBridge
{
    public const SCHEMA = 'atlas.self_construction.code_index_readiness_bridge.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_HOLD = 'hold';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * @param  array<string,mixed>  $facts  {code_status, readiness, automatic_gate, schema_drift}
     * @return array<string,mixed>
     */
    public function verify(array $facts): array
    {
        $blockers = [];
        $repairs = [];

        $codeStatus = (string) ($facts['code_status']['status'] ?? '');
        $readinessStatus = (string) ($facts['readiness']['status'] ?? '');
        $readinessFindings = (array) ($facts['readiness']['blocking_findings'] ?? []);
        $automaticGate = $facts['automatic_gate'] ?? null;
        $schemaDrift = $facts['schema_drift'] ?? null;
        $indexCount = (int) ($facts['code_status']['indexed_symbols'] ?? 0);
        $isStale = (bool) ($facts['code_status']['is_stale'] ?? false);

        $hold = false;

        // 1. Schema drift facts MUST be present and pass — otherwise BLOCKED.
        if (! is_array($schemaDrift)) {
            $blockers[] = 'schema_drift_facts_missing';
            $repairs[] = 'supply_schema_drift_audit_facts';
        } elseif (! (bool) ($schemaDrift['passed'] ?? false)) {
            $blockers[] = 'schema_drift_failed:'.(string) ($schemaDrift['status'] ?? 'unknown');
            $repairs[] = 'fix_schema_drift_before_promotion';
        }

        // 2. Automatic gate facts MUST be present.
        if (! is_array($automaticGate)) {
            $blockers[] = 'automatic_gate_facts_missing';
            $repairs[] = 'supply_automatic_gate_facts';
        } else {
            $gateStatus = (string) ($automaticGate['status'] ?? '');
            if (! in_array($gateStatus, ['ready', 'watch'], true)) {
                $blockers[] = 'automatic_gate_not_ready:'.$gateStatus;
                $repairs[] = 'address_automatic_gate_blockers';
            }
        }

        // 3. Code status / readiness — empty or stale ⇒ HOLD (refresh available).
        if ($indexCount <= 0) {
            $hold = true;
            $repairs[] = 'run_engineering_knowledge_index_code';
        }
        if ($isStale) {
            $hold = true;
            $repairs[] = 'rerun_engineering_knowledge_index_code_prune';
        }
        if ($codeStatus === '') {
            // absent status is never ready — force hold regardless of index count
            $hold = true;
            $repairs[] = 'investigate_code_intelligence_pipeline';
        } elseif ($codeStatus !== 'ready') {
            $blockers[] = 'code_status_not_ready:'.$codeStatus;
            $repairs[] = 'investigate_code_intelligence_pipeline';
        }

        // 4. Workspace binding facts — workspace_id, index_workspace_id, indexed_at_unix, changed_code_hash.
        $workspaceId = (string) ($facts['workspace_id'] ?? '');
        $indexWorkspaceId = (string) ($facts['code_status']['index_workspace_id'] ?? '');
        $indexedAtUnix = $facts['code_status']['indexed_at_unix'] ?? null;
        $changedCodeHash = (string) ($facts['changed_code_hash'] ?? '');
        $lastChangedCodeHash = (string) ($facts['code_status']['last_changed_code_hash'] ?? '');

        if ($workspaceId === '') {
            $blockers[] = 'workspace_id_missing';
            $repairs[] = 'supply_workspace_id_fact';
        } elseif ($indexWorkspaceId !== '' && $indexWorkspaceId !== $workspaceId) {
            $blockers[] = 'index_workspace_mismatch';
            $repairs[] = 'reindex_for_correct_workspace';
        }
        if ($indexedAtUnix === null || $indexedAtUnix === '') {
            $blockers[] = 'indexed_at_missing';
            $repairs[] = 'supply_indexed_at_unix_fact';
        }
        if ($changedCodeHash !== '' && $lastChangedCodeHash !== $changedCodeHash) {
            $blockers[] = 'changed_code_hash_not_represented';
            $repairs[] = 'rerun_engineering_knowledge_index_code_prune';
        }

        // 5. Readiness must be ready/watch and have no blocking findings.
        if ($readinessStatus !== '' && ! in_array($readinessStatus, ['ready', 'watch'], true)) {
            $blockers[] = 'readiness_not_ready:'.$readinessStatus;
        }
        if ($readinessFindings !== []) {
            $blockers[] = 'readiness_blocking_findings_present';
            $repairs[] = 'resolve_readiness_blocking_findings';
        }

        $status = $blockers !== []
            ? self::STATUS_BLOCKED
            : ($hold ? self::STATUS_HOLD : self::STATUS_READY);

        $passed = $status === self::STATUS_READY;
        $repairs = array_values(array_unique($repairs));

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'status' => $status,
            'passed' => $passed,
            'blockers' => $blockers,
            'code_index_facts' => [
                'code_status' => $codeStatus,
                'indexed_symbols' => $indexCount,
                'is_stale' => $isStale,
                'readiness_status' => $readinessStatus,
                'readiness_blocking_findings_count' => count($readinessFindings),
                'automatic_gate_status' => is_array($automaticGate) ? (string) ($automaticGate['status'] ?? '') : null,
                'schema_drift_status' => is_array($schemaDrift) ? (string) ($schemaDrift['status'] ?? '') : null,
                'schema_drift_passed' => is_array($schemaDrift) ? (bool) ($schemaDrift['passed'] ?? false) : null,
            ],
            'repair_actions' => $repairs,
            'proof_summary' => sprintf(
                'status=%s blockers=%d repairs=%d',
                $status,
                count($blockers),
                count($repairs),
            ),
        ];
    }
}
