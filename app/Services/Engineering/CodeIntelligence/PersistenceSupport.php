<?php

namespace App\Services\Engineering\CodeIntelligence;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Database\QueryException;

/**
 * GOD-DEBULK FASE C - shared persistence/workspace plumbing extracted VERBATIM from
 * EngineeringCodeIntelligenceService. Holds the mutable workspace_id for the current
 * index() run plus the workspace-keying, JSON and deadlock-retry micro-helpers every
 * DB-writing section shares. The facade creates one instance and injects it into each
 * stateful section so they observe the same live workspace_id.
 */
class PersistenceSupport
{
    /** AP-815 W-1: resolved workspace_id for the current index() run (default = primary). */
    public string $workspaceId = 'atlas-server';

    /** @var array<string,bool> AP-815 W-1: memoized "is this table workspace-keyed?" checks. */
    private array $workspaceColumnCache = [];


    /**
     * AP-815 W-1 — whether the code-intel read-model is workspace-keyed (post-migration).
     * Memoized; when false the writers fall back to the pre-keying single-workspace path,
     * so legacy / manually-built schemas keep working byte-identically.
     */
    public function workspaceKeyed(string $table = 'atlas_engineering_code_symbols'): bool
    {
        return $this->workspaceColumnCache[$table] ??= DatabaseTableAvailability::hasColumn($table, 'workspace_id');
    }


    /**
     * AP-815 W-1 — prepend workspace_id to an upsert conflict key when the table is keyed.
     *
     * @param  array<int,string>  $key
     * @return array<int,string>
     */
    public function workspaceConflictKey(string $table, array $key): array
    {
        return $this->workspaceKeyed($table) ? array_merge(['workspace_id'], $key) : $key;
    }


    /**
     * @param  array<mixed>  $value
     */
    public function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    }


    /**
     * @param  array<string,mixed>  $row
     */
    public function estimatedRowBytes(array $row): int
    {
        $encoded = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? strlen($encoded) : strlen(serialize($row));
    }


    public function withDeadlockRetry(callable $operation): void
    {
        $attempt = 0;
        while (true) {
            try {
                $operation();

                return;
            } catch (QueryException $e) {
                $attempt++;
                if ($attempt >= 3 || ! $this->isDeadlock($e)) {
                    throw $e;
                }

                usleep(50_000 * $attempt);
            }
        }
    }


    public function isDeadlock(QueryException $e): bool
    {
        $code = (string) $e->getCode();

        return $code === '40P01'
            || str_contains(strtolower($e->getMessage()), 'deadlock detected');
    }
}
