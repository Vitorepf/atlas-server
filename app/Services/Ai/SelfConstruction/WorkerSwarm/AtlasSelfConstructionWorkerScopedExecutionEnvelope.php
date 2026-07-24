<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\WorkerSwarm;

use App\Support\CanonicalValue;
use App\Services\Ai\SelfConstruction\Support\WriteSetOverlap;

/**
 * Pure composer — produces a deterministic, FACTS-only execution envelope for a SCOPED worker packet.
 * NEVER runs shell commands, mutates files, claims tasks, or reports completion.
 *
 * Validates:
 *   - allowed_files non-empty
 *   - allowed_files ∩ forbidden_files == ∅
 *   - gates non-empty (at minimum acceptance verification)
 *   - lease_id present + non-empty
 *
 * Output:
 *   {schema_version, valid, task_id, lease_id, allowed_files, forbidden_files, gates,
 *    evidence_requirements, rollback_plan, worker_capability, envelope_hash, blockers}
 *
 *   envelope_hash = sha256 of canonical_json of the envelope minus envelope_hash itself.
 */
final class AtlasSelfConstructionWorkerScopedExecutionEnvelope
{
    public const SCHEMA = 'atlas.worker_swarm.scoped_execution_envelope.v1';

    /** Canonical set of manual git actions a scoped worker must never run — mirrors the operator loop contract. */
    public const FORBIDDEN_MANUAL_ACTIONS = [
        'git add -A',
        'git add .',
        'git commit',
        'git reset --hard',
        'git checkout .',
        'git stash',
        'git push --force',
        'git pull',
    ];

    /** Key-name substrings (case-insensitive) marking a field as raw provider/secret data — omitted from the payload. */
    private const SENSITIVE_KEY_MARKERS = ['secret', 'token', 'api_key', 'apikey', 'credential', 'password', 'bearer', 'auth_key'];

    /**
     * @param  array<string,mixed>  $input  {
     *     task_id:string,
     *     lease_id:string,
     *     allowed_files:list<string>,
     *     forbidden_files:list<string>,
     *     gates:list<string>,
     *     evidence_requirements:list<string>,
     *     rollback_plan:array<string,mixed>,
     *     worker_capability:array<string,mixed>,
     *   }
     * @return array<string,mixed>
     */
    public function compose(array $input): array
    {
        $taskId = (string) ($input['task_id'] ?? '');
        $leaseId = (string) ($input['lease_id'] ?? '');
        $allowed = $this->normalizePaths((array) ($input['allowed_files'] ?? []));
        $forbidden = $this->normalizePaths((array) ($input['forbidden_files'] ?? []));
        $gates = array_values((array) ($input['gates'] ?? []));
        $evidence = array_values((array) ($input['evidence_requirements'] ?? []));
        $rollback = $this->redactSensitive(is_array($input['rollback_plan'] ?? null) ? $input['rollback_plan'] : []);
        $worker = $this->redactSensitive(is_array($input['worker_capability'] ?? null) ? $input['worker_capability'] : []);

        $blockers = [];
        if ($leaseId === '') {
            $blockers[] = 'lease_id_missing';
        }
        if ($allowed === []) {
            $blockers[] = 'allowed_files_empty';
        }
        foreach (array_merge($allowed, $forbidden) as $path) {
            if (str_starts_with($path, '/') || str_contains($path, '../')) {
                $blockers[] = 'unsafe_path:'.$path;
            }
        }
        $overlap = WriteSetOverlap::collidingPaths($allowed, $forbidden);
        if ($overlap !== []) {
            $blockers[] = 'allowed_forbidden_overlap:'.implode(',', $overlap);
        }
        if ($gates === []) {
            $blockers[] = 'gates_missing';
        }
        $hasArtisan = false;
        $boundToAllowed = false;
        foreach (array_merge($gates, $evidence) as $item) {
            $item = (string) $item;
            if (! str_contains($item, 'php artisan')) {
                continue;
            }
            $hasArtisan = true;
            foreach ($allowed as $path) {
                if ($path !== '' && (str_contains($item, $path) || str_contains($item, basename($path, '.php')))) {
                    $boundToAllowed = true;
                    break 2;
                }
            }
        }
        if (! $hasArtisan) {
            $blockers[] = 'no_artisan_proof_in_gates_or_evidence';
        } elseif (! $boundToAllowed) {
            $blockers[] = 'proof_not_bound_to_allowed_files';
        }

        $envelope = [
            'schema_version' => self::SCHEMA,
            'valid' => $blockers === [],
            'task_id' => $taskId,
            'lease_id' => $leaseId,
            'allowed_files' => $allowed,
            'forbidden_files' => $forbidden,
            'gates' => $gates,
            'evidence_requirements' => $evidence,
            'rollback_plan' => $rollback,
            'worker_capability' => $worker,
            'forbidden_manual_actions' => self::FORBIDDEN_MANUAL_ACTIONS,
            'blockers' => $blockers,
        ];
        $envelope['envelope_hash'] = hash('sha256', $this->canonicalJson($envelope));

        return $envelope;
    }

    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    private function redactSensitive(array $value): array
    {
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($k) && $this->isSensitiveKey($k)) {
                continue;
            }
            $out[$k] = is_array($v) ? $this->redactSensitive($v) : $v;
        }

        return $out;
    }

    private function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::SENSITIVE_KEY_MARKERS as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function normalizePaths(array $paths): array
    {
        $paths = array_filter(array_map('strval', $paths), static fn (string $p): bool => $p !== '');
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        return $paths;
    }

    private function canonicalJson(mixed $value): string
    {
        return (string) json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function canonicalize(mixed $value): mixed
    {
        return CanonicalValue::canonicalize($value);
    }
}
