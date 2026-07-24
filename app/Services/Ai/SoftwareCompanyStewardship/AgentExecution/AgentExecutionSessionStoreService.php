<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AgentExecution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\Concerns\HasStewardshipStorageRoot;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use App\Support\AtlasSecurity;

/**
 * AP-795 · AP-793 Agent Execution Session Store (Required Port #5).
 *
 * Durable, append-only JSONL record of agent executions, keyed for idempotency
 * by a deterministic `session_hash`. It consumes the normalized provider facts
 * from {@see AgentExecutionProviderPortService} plus loop context (cycle/area/
 * focus) and persists a sanitized projection so a long-horizon loop can be
 * replayed and audited after a crash, timeout or restart.
 *
 * Hard invariants:
 *   - Append-only JSONL under storage/; the only side effect is the append.
 *   - Idempotent by `session_hash` (dedup; no duplicate append).
 *   - NEVER stores raw secrets: api keys, bearer tokens, raw resume tokens, env
 *     secrets and raw prompts/contexts are dropped. The record keeps only
 *     prompt_hash/context_hash/command_hash/worktree_hash, a redacted command
 *     argv, redacted stream excerpts, and resume_token_present + resume_token_hash.
 *     Every record is passed through {@see AtlasSecurity::redactArray} as a final
 *     net before it is written.
 *   - NEVER invokes a provider, opens a branch, merges, deploys or touches a real
 *     worktree. It records facts that already happened.
 */
final class AgentExecutionSessionStoreService
{
    use AgentExecutionClock;

    public const SCHEMA = 'atlas.agent_execution.session_store.v1';

    /** Keys that must never survive into a persisted record, even redacted. */
    private const DROP_KEYS = [
        'prompt', 'raw_prompt', 'context', 'raw_context', 'api_key', 'apikey',
        'bearer', 'authorization', 'resume_token', 'resume_handle',
        'continuation_token', 'secret', 'password', 'token', 'cookie', 'credential',
    ];

    use HasStewardshipStorageRoot;

    private const STORAGE_SUBPATH = 'atlas/software-company-stewardship/agent-execution';

    public function __construct(
        private readonly AgentExecutionProviderPortService $providerPort,
    ) {}

    public function sessionsFilePath(): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.'sessions.jsonl';
    }

    /**
     * Record one agent execution. `$input` may carry already-normalized provider
     * port facts under `provider_port`, or raw facts that this store will run
     * through the port. Loop context (`cycle_id`, `area_id`, `focus`) and the
     * sensitive inputs to hash (`prompt`, `context`, `worktree_path`) are read
     * from `$input` too. Idempotent: an already-recorded execution (same
     * `session_hash`) is returned as-is and not appended again.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function record(array $input): array
    {
        $port = is_array($input['provider_port'] ?? null)
            ? $input['provider_port']
            : $this->providerPort->normalize($input);

        $cycleId = (string) ($input['cycle_id'] ?? '');
        // The provider port always carries a `session_id` key (the provider
        // session id, '' when absent). Prefer it only when non-empty, otherwise
        // fall back to the caller-supplied loop session id so replay() can group
        // every lane of a cycle by the loop session.
        $sessionId = (string) ($port['session_id'] ?? '');
        if ($sessionId === '') {
            $sessionId = (string) ($input['session_id'] ?? '');
        }

        $resumeToken = (string) ($input['resume_token'] ?? '');
        $resumeHash = $resumeToken === '' ? '' : 'sha256:'.MissionCanonicalHash::sha256($resumeToken);

        $core = [
            'schema_version' => self::SCHEMA,
            'ap_contract' => 'AP-795',
            'substrate_contract' => 'AP-793',
            'port' => 'agent_execution.session_store.v1',
            'cycle_id' => $cycleId,
            'session_id' => $sessionId,
            // AP-797/AP-801: the lane this execution belongs to (context_scout,
            // architect, implementer, reviewer, repair_agent, judge) when the
            // execution is one lane of a multi-agent workcell. Included in the
            // hashed identity so distinct lanes of one cycle are distinct records
            // while replay-by-session_id and latestByCycleId still group them.
            'lane' => (string) ($input['lane'] ?? ''),
            'area_id' => (string) ($input['area_id'] ?? ''),
            'focus' => (string) ($input['focus'] ?? ''),
            'provider_id' => (string) ($port['provider_id'] ?? 'unknown'),
            'model_family' => (string) ($port['model_family'] ?? ''),
            'invocation_state' => (string) ($port['invocation_state'] ?? AgentExecutionProviderPortService::INVOCATION_PLANNED),
            'provider_invoked' => (bool) ($port['provider_invoked'] ?? false),
            'auth_mode' => (string) ($port['auth_mode'] ?? AgentExecutionProviderPortService::AUTH_UNKNOWN),
            'permission_mode' => (string) ($port['permission_mode'] ?? 'unknown'),
            'working_directory' => (string) ($port['working_directory'] ?? ''),
            'port_status' => (string) ($port['port_status'] ?? ''),
            'port_violations' => array_values(array_filter((array) ($port['port_violations'] ?? []), 'is_string')),
            'command_argv' => array_values(array_map(static fn (mixed $p): string => AtlasSecurity::redactString((string) $p), (array) ($port['command_argv'] ?? []))),
            'exit_status' => is_array($port['exit_status'] ?? null) ? $port['exit_status'] : [],
            'timeout_state' => is_array($port['timeout_state'] ?? null) ? $port['timeout_state'] : [],
            'rate_limit_state' => is_array($port['rate_limit_state'] ?? null) ? $port['rate_limit_state'] : [],
            'usage' => is_array($port['usage'] ?? null) ? $port['usage'] : [],
            'stream_events' => $this->streamSummary(is_array($port['stream_events'] ?? null) ? $port['stream_events'] : []),
            'resume_token_present' => $resumeToken !== '' || (string) ($port['resume_token'] ?? '') !== '',
            'resume_token_hash' => $resumeHash,
            'prompt_hash' => $this->hashOf($input, 'prompt'),
            'context_hash' => $this->hashOf($input, 'context'),
            'command_hash' => 'sha256:'.MissionCanonicalHash::sha256((array) ($port['command_argv'] ?? [])),
            'worktree_hash' => $this->worktreeHash($input, $port),
            'port_hash' => (string) ($port['port_hash'] ?? ''),
            'claim_policy' => $this->claimPolicy(),
        ];

        $core['session_hash'] = 'sha256:'.MissionCanonicalHash::sha256($core);
        $core['agent_session_id'] = 'ases_'.substr(str_replace('sha256:', '', $core['session_hash']), 0, 18);

        // Idempotent: return the already-recorded execution without re-appending.
        $existing = $this->findBySessionHash($core['session_hash']);
        if ($existing !== null) {
            return $existing;
        }

        $record = $this->sanitize($core);
        $record['generated_at'] = $this->now();
        $record['recorded_at'] = $this->now();

        AppendOnlyJsonlStore::append($this->sessionsFilePath(), $record);

        return $record;
    }

    /**
     * Replay every recorded execution for a provider session id (or agent session
     * id), oldest first. Read-only.
     *
     * @return list<array<string,mixed>>
     */
    public function replay(string $sessionId): array
    {
        if ($sessionId === '') {
            return [];
        }

        $matches = [];
        foreach ($this->readAll() as $record) {
            if ((string) ($record['session_id'] ?? '') === $sessionId
                || (string) ($record['agent_session_id'] ?? '') === $sessionId) {
                $matches[] = $record;
            }
        }

        return $matches;
    }

    /**
     * The most recent recorded execution for a loop cycle, or null when none.
     *
     * @return array<string,mixed>|null
     */
    public function latestByCycleId(string $cycleId): ?array
    {
        if ($cycleId === '') {
            return null;
        }

        $latest = null;
        foreach ($this->readAll() as $record) {
            if ((string) ($record['cycle_id'] ?? '') === $cycleId) {
                $latest = $record;
            }
        }

        return $latest;
    }

    /**
     * All recorded executions (oldest first), corruption-tolerant.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return $this->readAll();
    }

    // ---------- sanitization ----------

    /**
     * Drop sensitive keys entirely, then redact secret-shaped strings everywhere
     * as a final net. Redaction is VALUE-level (via AtlasSecurity::redactString),
     * not key-name-level: the deliberately-safe fields this store builds
     * (resume_token_hash, *_hash, *_present) must survive, while any embedded
     * api key / bearer / jwt / sk- token in a string value is scrubbed.
     *
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    private function sanitize(array $record): array
    {
        foreach (self::DROP_KEYS as $key) {
            unset($record[$key]);
        }

        return $this->redactValues($record);
    }

    /**
     * Recursively apply AtlasSecurity::redactString to every string value while
     * leaving keys, bools and ints untouched.
     */
    private function redactValues(mixed $value): mixed
    {
        if (is_string($value)) {
            return AtlasSecurity::redactString($value);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = $this->redactValues($item);
            }

            return $out;
        }

        return $value;
    }

    /**
     * @param  list<array<string,string>>  $events
     * @return array<string,mixed>
     */
    private function streamSummary(array $events): array
    {
        $byKind = [];
        $excerpts = [];
        foreach ($events as $event) {
            $kind = (string) ($event['kind'] ?? 'other');
            $byKind[$kind] = ($byKind[$kind] ?? 0) + 1;
            $excerpts[] = [
                'kind' => $kind,
                'excerpt' => substr(AtlasSecurity::redactString((string) ($event['excerpt'] ?? '')), 0, 500),
            ];
        }

        return [
            'event_count' => count($events),
            'by_kind' => $byKind,
            'excerpts' => array_slice($excerpts, 0, 20),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function hashOf(array $input, string $key): string
    {
        $explicit = (string) ($input[$key.'_hash'] ?? '');
        if ($explicit !== '') {
            return str_starts_with($explicit, 'sha256:') ? $explicit : 'sha256:'.$explicit;
        }
        if (! array_key_exists($key, $input)) {
            return '';
        }

        return 'sha256:'.MissionCanonicalHash::sha256($input[$key]);
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $port
     */
    private function worktreeHash(array $input, array $port): string
    {
        $explicit = (string) ($input['worktree_hash'] ?? '');
        if ($explicit !== '') {
            return str_starts_with($explicit, 'sha256:') ? $explicit : 'sha256:'.$explicit;
        }
        $path = (string) ($input['worktree_path'] ?? $port['working_directory'] ?? '');

        return $path === '' ? '' : 'sha256:'.MissionCanonicalHash::sha256($path);
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only_over_repo' => true,
            'writes_repo' => false,
            'mutates_target_repo' => false,
            'writes_local_state' => true,
            'persistence' => 'jsonl_append_only',
            'idempotent_by_session_hash' => true,
            'provider_invoked' => false,
            'opens_branch' => false,
            'merges' => false,
            'deploys' => false,
            'touches_secrets' => false,
            'secrets_in_payload' => false,
            'is_new_os' => false,
            'parallel_runtime_created' => false,
        ];
    }

    // ---------- jsonl io ----------

    /**
     * @return array<string,mixed>|null
     */
    private function findBySessionHash(string $sessionHash): ?array
    {
        foreach ($this->readAll() as $record) {
            if ((string) ($record['session_hash'] ?? '') === $sessionHash) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readAll(): array
    {
        return array_values(array_filter(
            AppendOnlyJsonlStore::read($this->sessionsFilePath()),
            static fn (array $record): bool => isset($record['session_hash']) && is_string($record['session_hash']),
        ));
    }
}
