<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;
use App\Services\Ai\SelfConstruction\Support\HashesKsortedPayloadCanonically;

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

        $axisHits = [];
        foreach ($allowed as $path) {
            foreach (self::FORBIDDEN_AXES as $axis => $prefix) {
                if (str_starts_with($path, $prefix)) {
                    $axisHits[] = ['axis' => $axis, 'path' => $path, 'prefix' => $prefix];
                }
            }
        }
        if ($axisHits !== []) {
            $blockers[] = 'forbidden_axis';
        }

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

        $status = $blockers === [] ? 'valid' : 'blocked';

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
            'task_packet_id' => (string) ($taskPacket['task_packet_id'] ?? ''),
            'task_packet_hash' => (string) ($taskPacket['task_packet_hash'] ?? ''),
            'blockers' => $blockers,
            'warnings' => $warnings,
            'normalized_scope_lock' => $normalizedScopeLock,
            'scope_lock_hash' => $scopeLockHash,
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
            $value = preg_replace('#/{2,}#', '/', $value) ?? $value;
            $out[] = ltrim($value, '/');
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
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

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<mixed, mixed>
     */
    private function recursivelyKsort(array $value): array
    {
        $isAssoc = $value !== [] && array_keys($value) !== range(0, count($value) - 1);
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->recursivelyKsort($entry);
            }
        }
        if ($isAssoc) {
            ksort($value);
        }

        return $value;
    }

}
