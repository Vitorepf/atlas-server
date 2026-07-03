<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use App\Services\Ai\SelfConstruction\Support\HashesKsortedPayloadCanonically;
use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;

/**
 * Validates an Agent Control Plane task packet before its scope lock is
 * persisted as a real claim. This is the runtime gate that decides whether
 * a task can enter the queue/lease layer at all.
 *
 * Runtime-safe: never starts processes, never calls Codex CLI/app, never
 * spawns subprocesses, never invokes adapters, never dispatches work,
 * never spends tokens, never advances the next required slice, never
 * enables self-programming and never writes the evidence ledger.
 */
final class AgentControlPlaneScopeLockRuntimeValidator
{
    use RecursivelyKsortsArrays;
    use HashesKsortedPayloadCanonically;
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_scope_lock_runtime_validation.v1';

    public const MODE = 'persistent_local_agent_control_plane_scope_lock_runtime_validator';

    public const FORBIDDEN_AXES = [
        'self_improvement' => 'app/Services/Ai/SelfImprovement/',
        'programming' => 'app/Services/Ai/Programming/',
        'atlas_code_controllers' => 'app/Http/Controllers/AtlasCode',
        'routes_api' => 'routes/api.php',
        'atlas_desktop' => 'atlas-desktop/',
        'forge' => 'forge/',
        'rivals' => 'rivals/',
        'cartografia' => 'cartografia/',
        'voice' => 'voice/',
    ];

    public const TRAVERSAL_NEEDLES = ['../', '..\\', '/..'];

    public const DEFAULT_MAX_FILES = 25;

    public const ALLOWED_RISK_LEVELS = ['low', 'medium', 'high', 'critical'];

    public const ALLOWED_RISK_LEVELS_BLOCKED = ['high', 'critical'];

    public const COMMIT_DECISION_VALID          = 'valid';
    public const COMMIT_DECISION_REPAIR_REQUIRED = 'repair_required';
    public const COMMIT_DECISION_REJECT_COMMIT   = 'reject_commit';

    /**
     * Hard blockers force reject_commit (security/integrity violations); everything else is
     * recoverable by the packet author and only forces repair_required.
     */
    public const HARD_BLOCKERS = [
        'forbidden_overlap',
        'path_traversal',
        'forbidden_axis',
        'risk_level_too_high_for_runtime_claim',
        'task_packet_not_planned',
        'files_edited_outside_allowed_scope',
        'active_lease_scope_overlap',
        'missing_lock_proof',
    ];

    /**
     * @param  array<string, mixed>  $taskPacket
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function validate(array $taskPacket, array $options = []): array
    {
        $blockers = [];
        $warnings = [];

        $allowed = $this->normalize((array) data_get($taskPacket, 'normalized_scope.allowed_files', []));
        $forbidden = $this->normalize((array) data_get($taskPacket, 'normalized_scope.forbidden_files', []));
        $scopeIn = $this->normalize((array) data_get($taskPacket, 'normalized_scope.scope_in', []));
        $scopeOut = $this->normalize((array) data_get($taskPacket, 'normalized_scope.scope_out', []));

        if ($allowed === []) {
            $blockers[] = 'allowed_files_empty';
        }

        // AC: an allowed_files entry that names a bare directory root (not a concrete file) is
        // a broad-scope claim -- opt-in via options so existing callers whose allowed_files are
        // already builder-normalized concrete paths are never newly blocked.
        $broadRootScope = [];
        if ((bool) ($options['enforce_narrow_scope'] ?? false)) {
            foreach ($allowed as $path) {
                if ($path === '' || str_ends_with($path, '/') || ! str_contains(basename($path), '.')) {
                    $broadRootScope[] = $path;
                }
            }
            if ($broadRootScope !== []) {
                $blockers[] = 'broad_root_scope';
            }
        }

        $forbiddenInAllowed = WriteSetOverlap::collidingPaths($allowed, $forbidden); // A5/MF-12: prefix-aware dir-vs-file
        if ($forbiddenInAllowed !== []) {
            $blockers[] = 'forbidden_overlap';
        }

        $traversal = [];
        foreach (array_merge($allowed, $forbidden, $scopeIn, $scopeOut) as $path) {
            foreach (self::TRAVERSAL_NEEDLES as $needle) {
                if (str_contains($path, $needle)) {
                    $traversal[] = ['path' => $path, 'needle' => $needle];
                    break;
                }
            }
        }
        if ($traversal !== []) {
            $blockers[] = 'path_traversal';
        }

        // AC: a packet whose payload carries axis_exception_granted, stamped ONLY by
        // autonomous-gov-bootstrap, exempts the exact FORBIDDEN_AXES prefixes it names — a
        // grant stamped by any other source is disregarded entirely (fail-closed).
        $axisExceptionGranted = (array) data_get($taskPacket, 'axis_exception_granted', []);
        $axisExceptionSource = (string) ($axisExceptionGranted['source'] ?? '');
        $grantedAxisPrefixes = $axisExceptionSource === 'autonomous-gov-bootstrap'
            ? array_values(array_map('strval', (array) ($axisExceptionGranted['axes'] ?? [])))
            : [];

        $axisHits = [];
        $axisExceptionHonored = [];
        foreach ($allowed as $path) {
            foreach (self::FORBIDDEN_AXES as $axis => $prefix) {
                if (! str_starts_with($path, $prefix)) {
                    continue;
                }
                if (in_array($prefix, $grantedAxisPrefixes, true)) {
                    $axisExceptionHonored[] = $prefix;

                    continue;
                }
                $axisHits[] = ['axis' => $axis, 'path' => $path, 'prefix' => $prefix];
            }
        }
        if ($axisHits !== []) {
            $blockers[] = 'forbidden_axis';
        }
        $axisExceptionHonored = array_values(array_unique($axisExceptionHonored));
        sort($axisExceptionHonored);

        $maxFiles = isset($options['max_files']) ? (int) $options['max_files'] : self::DEFAULT_MAX_FILES;
        if ($maxFiles > 0 && count($allowed) > $maxFiles) {
            $blockers[] = 'too_many_allowed_files';
        }

        $riskLevel = strtolower((string) data_get($taskPacket, 'risk_classification.risk_level', 'low'));
        if (! in_array($riskLevel, self::ALLOWED_RISK_LEVELS, true)) {
            $warnings[] = 'risk_level_unknown_defaulting_low';
            $riskLevel = 'low';
        }
        if (in_array($riskLevel, self::ALLOWED_RISK_LEVELS_BLOCKED, true)) {
            $blockers[] = 'risk_level_too_high_for_runtime_claim';
        }

        $rollbackStrategy = (string) data_get($taskPacket, 'rollback_requirements.rollback_strategy', '');
        if ($rollbackStrategy === '') {
            $blockers[] = 'rollback_strategy_missing';
        }

        $continuationRequired = (bool) data_get($taskPacket, 'continuation_requirements.continuation_required', false);
        if (! $continuationRequired) {
            $blockers[] = 'continuation_summary_required';
        }

        $evidenceRequired = (array) data_get($taskPacket, 'evidence_requirements.required', []);
        if ($evidenceRequired === []) {
            $blockers[] = 'evidence_requirements_missing';
        }

        $writeSet = $this->normalize((array) ($options['write_set'] ?? $allowed));
        $readSet = $this->normalize((array) ($options['read_set'] ?? array_values(array_unique(array_merge($allowed, $scopeIn)))));

        $packetStatus = (string) ($taskPacket['status'] ?? 'unknown');
        if ($packetStatus !== 'planned') {
            $blockers[] = 'task_packet_not_planned';
        }

        // AC1: runtime drift detection — only evaluated when the caller supplies what was
        // actually edited / who else is active, so the pre-commit-only validation path stays
        // unchanged when those options are absent (AC4: preserve existing commit model).
        $editedFilesProvided = array_key_exists('edited_files', $options);
        $driftedFiles = [];
        if ($editedFilesProvided) {
            $editedFiles = $this->normalize((array) $options['edited_files']);
            $driftedFiles = array_values(array_diff($editedFiles, $allowed));
            if ($driftedFiles !== []) {
                $blockers[] = 'files_edited_outside_allowed_scope';
            }
        }

        $activeLeaseOverlaps = [];
        foreach ((array) ($options['active_lease_scopes'] ?? []) as $lease) {
            $leaseId = (string) ($lease['lease_id'] ?? '');
            $leaseWriteSet = $this->normalize((array) ($lease['write_set'] ?? []));
            $overlap = array_values(array_intersect($allowed, $leaseWriteSet));
            if ($overlap !== []) {
                $activeLeaseOverlaps[] = ['lease_id' => $leaseId, 'overlapping_files' => $overlap];
            }
        }
        if ($activeLeaseOverlaps !== []) {
            $blockers[] = 'active_lease_scope_overlap';
        }

        $requireLockProof = (bool) ($options['require_lock_proof'] ?? false);
        $lockProof = isset($options['lock_proof']) ? (string) $options['lock_proof'] : null;
        $hasValidLockProof = $lockProof !== null && $lockProof !== '' && $lockProof === (string) ($taskPacket['task_packet_id'] ?? '');
        if ($requireLockProof && ! $hasValidLockProof) {
            $blockers[] = 'missing_lock_proof';
        }

        $staleScopeLockThresholdSeconds = isset($options['stale_scope_lock_threshold_seconds'])
            ? (int) $options['stale_scope_lock_threshold_seconds']
            : 3600;
        $scopeLockAgeSeconds = isset($options['scope_lock_age_seconds']) ? (int) $options['scope_lock_age_seconds'] : null;
        $staleScopeLock = $scopeLockAgeSeconds !== null && $scopeLockAgeSeconds > $staleScopeLockThresholdSeconds;
        if ($staleScopeLock) {
            $blockers[] = 'stale_scope_lock';
        }

        $status = $blockers === [] ? 'valid' : 'blocked';

        $hasHardBlocker = array_intersect($blockers, self::HARD_BLOCKERS) !== [];
        $commitDecision = match (true) {
            $blockers === [] => self::COMMIT_DECISION_VALID,
            $hasHardBlocker => self::COMMIT_DECISION_REJECT_COMMIT,
            default => self::COMMIT_DECISION_REPAIR_REQUIRED,
        };

        $normalizedScopeLock = [
            'allowed_files' => $allowed,
            'forbidden_files' => $forbidden,
            'scope_in' => $scopeIn,
            'scope_out' => $scopeOut,
            'write_set' => $writeSet,
            'read_set' => $readSet,
            'risk_level' => $riskLevel,
            'rollback_strategy' => $rollbackStrategy,
            'continuation_required' => $continuationRequired,
            'evidence_requirements' => $evidenceRequired,
            'forbidden_axis_hits' => $axisHits,
            'path_traversal' => $traversal,
            'forbidden_in_allowed' => $forbiddenInAllowed,
        ];

        $scopeLockHash = $this->stableHash($normalizedScopeLock);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'status' => $status,
            'passed' => $status === 'valid',
            'commit_decision' => $commitDecision,
            'task_packet_id' => (string) ($taskPacket['task_packet_id'] ?? ''),
            'task_packet_hash' => (string) ($taskPacket['task_packet_hash'] ?? ''),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'drifted_files' => $driftedFiles,
            'observed_out_of_scope_files' => $driftedFiles,
            'stale_scope_lock' => $staleScopeLock,
            'repair_action' => $this->repairAction($blockers),
            'active_lease_scope_overlaps' => $activeLeaseOverlaps,
            'lock_proof_valid' => $requireLockProof ? $hasValidLockProof : null,
            'normalized_scope_lock' => $normalizedScopeLock,
            'scope_lock_hash' => $scopeLockHash,
            'axis_exception_honored' => $axisExceptionHonored,
            'forbidden_axis_count' => count($axisHits),
            'traversal_count' => count($traversal),
            'forbidden_overlap_count' => count($forbiddenInAllowed),
            'allowed_file_count' => count($allowed),
            'write_set_count' => count($writeSet),
            'read_set_count' => count($readSet),
            'max_files' => $maxFiles,
            'runtime_execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'self_programming_allowed' => false,
            'ledger_write_allowed' => false,
            'completion_real_allowed' => false,
            'non_execution_guarantees' => [
                'scope_lock_runtime_validator_does_not_start_codex',
                'scope_lock_runtime_validator_does_not_call_codex_cli_or_app',
                'scope_lock_runtime_validator_does_not_spawn_subprocess',
                'scope_lock_runtime_validator_does_not_invoke_adapter',
                'scope_lock_runtime_validator_does_not_call_provider',
                'scope_lock_runtime_validator_does_not_dispatch_work',
                'scope_lock_runtime_validator_does_not_spend_tokens',
                'scope_lock_runtime_validator_does_not_enable_self_programming',
                'scope_lock_runtime_validator_does_not_write_ledger',
                'scope_lock_runtime_validator_does_not_persist_lease',
                'scope_lock_runtime_validator_does_not_mutate_pointer',
            ],
            'human_summary' => sprintf(
                'Scope lock runtime validation %s (%d blockers, %d axis hits, %d traversal hits).',
                $status,
                count($blockers),
                count($axisHits),
                count($traversal),
            ),
        ];

        $payload['validation_hash'] = $this->stableHash($this->normalizeForHash($payload));

        return $payload;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * @param  array<int|string, mixed>  $paths
     * @return list<string>
     */
    private function normalize(array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            $value = trim((string) $path);
            if ($value === '') {
                continue;
            }
            $value = str_replace('\\', '/', $value);
            $normalized = preg_replace('#/{2,}#', '/', $value);
            if ($normalized === null) {
                // preg_replace failure — fail closed: skip this path entirely.
                continue;
            }
            $out[] = ltrim($normalized, '/');
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /** @param  list<string>  $blockers */
    private function repairAction(array $blockers): string
    {
        if ($blockers === []) {
            return 'none';
        }

        return match ($blockers[0]) {
            'allowed_files_empty' => 'declare_concrete_allowed_files',
            'broad_root_scope' => 'narrow_allowed_files_to_concrete_paths',
            'forbidden_overlap' => 'remove_forbidden_files_from_allowed_scope',
            'path_traversal' => 'remove_path_traversal_sequences',
            'forbidden_axis' => 'remove_targets_inside_forbidden_axis',
            'too_many_allowed_files' => 'split_into_smaller_scope',
            'risk_level_too_high_for_runtime_claim' => 'route_to_operator_review',
            'rollback_strategy_missing' => 'declare_rollback_strategy',
            'continuation_summary_required' => 'declare_continuation_requirements',
            'evidence_requirements_missing' => 'declare_required_evidence',
            'task_packet_not_planned' => 'return_packet_to_planned_status',
            'files_edited_outside_allowed_scope' => 'respec_allowed_files_or_revert_out_of_scope_edits',
            'active_lease_scope_overlap' => 'wait_for_conflicting_lease_release',
            'missing_lock_proof' => 'supply_valid_lock_proof',
            'stale_scope_lock' => 'refresh_scope_lock',
            default => 'investigate_blocker:'.$blockers[0],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeForHash(array $payload): array
    {
        $clone = $payload;
        unset($clone['generated_at'], $clone['validation_hash'], $clone['human_summary']);

        return $this->recursivelyKsort($clone);
    }


}
