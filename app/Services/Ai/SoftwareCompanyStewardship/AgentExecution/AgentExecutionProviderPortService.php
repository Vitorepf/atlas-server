<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AgentExecution;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\StewardshipStringListNormalizer;
use App\Support\AtlasSecurity;

/**
 * AP-795 · AP-793 Agent Execution Provider Port (Required Port #1).
 *
 * Pure normalizer: it takes the provider facts that AP-759 (owner sandbox
 * runtime command result) and AP-786 (autonomous evolution session cycle) — or
 * a flat provider-facts dictionary — already produce, and projects them into one
 * governed shape, {@see self::SCHEMA}.
 *
 * Hard invariants:
 *   - NEVER spawns a process, NEVER calls a provider router/driver, NEVER mutates
 *     a file. It only reads its argument and returns a normalized array.
 *   - `command_argv` is an ARGV ARRAY ONLY. A shell string is rejected, never run.
 *   - `auth_mode` is always one explicit value (local_account/api_key/local_model/
 *     blocked/unknown); it is never left blank.
 *   - `invocation_state=real` is never inferred from shape alone: it requires an
 *     explicit real-invocation signal. Plans/deferrals/fixtures stay
 *     planned/deferred/simulated, never real.
 *   - No raw secret is echoed: argv is redacted and stream excerpts are redacted
 *     and truncated. Durable persistence is the job of
 *     {@see AgentExecutionSessionStoreService}, not this port.
 */
final class AgentExecutionProviderPortService
{
    public const SCHEMA = 'atlas.agent_execution.provider_port.v1';

    public const PORT_NORMALIZED = 'normalized';

    public const PORT_REJECTED = 'rejected';

    // Canonical invocation states. `real` is the only one that may claim a
    // provider actually ran; the rest are honest non-runs.
    public const INVOCATION_REAL = 'real';

    public const INVOCATION_PLANNED = 'planned';

    public const INVOCATION_DEFERRED = 'deferred';

    public const INVOCATION_SIMULATED = 'simulated';

    /** @var list<string> */
    private const INVOCATION_STATES = [
        self::INVOCATION_REAL,
        self::INVOCATION_PLANNED,
        self::INVOCATION_DEFERRED,
        self::INVOCATION_SIMULATED,
    ];

    // Canonical auth modes.
    public const AUTH_LOCAL_ACCOUNT = 'local_account';

    public const AUTH_API_KEY = 'api_key';

    public const AUTH_LOCAL_MODEL = 'local_model';

    public const AUTH_BLOCKED = 'blocked';

    public const AUTH_UNKNOWN = 'unknown';

    /** @var list<string> */
    private const AUTH_MODES = [
        self::AUTH_LOCAL_ACCOUNT,
        self::AUTH_API_KEY,
        self::AUTH_LOCAL_MODEL,
        self::AUTH_BLOCKED,
        self::AUTH_UNKNOWN,
    ];

    public const VIOLATION_SHELL_STRING = 'shell_string_command_rejected';

    /** Provider ids that authenticate with a local model (no remote account/key). */
    private const LOCAL_MODEL_PROVIDERS = ['minimax_self_host', 'local_model', 'ollama'];

    private const MAX_STREAM_EVENTS = 50;

    private const MAX_EXCERPT_CHARS = 500;

    /**
     * Normalize one provider fact payload into the AP-793 provider port shape.
     *
     * Accepts an AP-786 cycle (`{cycle: {...}}` or a cycle dict carrying
     * `provider_result`/`owner_flow`), an AP-759 owner command result, or a flat
     * provider-facts dictionary. The result is deterministic except for nothing —
     * there is no wall-clock in the port (the session store owns timestamps).
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function normalize(array $payload): array
    {
        $src = $this->source($payload);

        [$commandArgv, $violations] = $this->commandArgv($src);

        $invocationState = $this->invocationState($src);
        $providerInvoked = $invocationState === self::INVOCATION_REAL;

        $record = [
            'schema_version' => self::SCHEMA,
            'ap_contract' => 'AP-795',
            'substrate_contract' => 'AP-793',
            'port' => 'agent_execution.provider_port.v1',
            'port_status' => $violations === [] ? self::PORT_NORMALIZED : self::PORT_REJECTED,
            'port_violations' => $violations,
            'provider_id' => $this->providerId($src),
            'model_family' => $this->modelFamily($src),
            'command_argv' => $commandArgv,
            'accepts_shell_string' => false,
            'working_directory' => $this->workingDirectory($src),
            'session_id' => $this->scalarString($src, ['session_id', 'provider_session_id', 'owner_sandbox_run_id', 'run_id']),
            'resume_token' => $this->resumeToken($src),
            'stream_events' => $this->streamEvents($src),
            'usage' => $this->usage($src),
            'auth_mode' => $this->authMode($src),
            'permission_mode' => $this->permissionMode($src),
            'exit_status' => $this->exitStatus($src),
            'timeout_state' => $this->timeoutState($src),
            'rate_limit_state' => $this->rateLimitState($src),
            'invocation_state' => $invocationState,
            'provider_invoked' => $providerInvoked,
            'claim_policy' => [
                'normalizer_only' => true,
                'provider_invoked_by_port' => false,
                'executes_provider' => false,
                'accepts_shell_string' => false,
                'writes_durable_state' => false,
                'is_provider_router' => false,
                'real_invocation_requires_explicit_signal' => true,
            ],
        ];

        $record['port_hash'] = 'sha256:'.MissionCanonicalHash::sha256($this->identity($record));

        return $record;
    }

    // ---------- source extraction ----------

    /**
     * Flatten the accepted payload shapes into one lookup map. A nested `cycle`
     * (AP-786) is merged so its provider_result/owner_flow are reachable.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function source(array $payload): array
    {
        $cycle = is_array($payload['cycle'] ?? null) ? $payload['cycle'] : [];

        return array_merge($cycle, $payload);
    }

    /**
     * @param  array<string,mixed>  $src
     * @return array{0:list<string>,1:list<string>}
     */
    private function commandArgv(array $src): array
    {
        $violations = [];

        // A shell string is never acceptable, whether passed as `command_argv` or
        // `command`. Reject it explicitly instead of trying to split it.
        foreach (['command_argv', 'command'] as $key) {
            if (is_string($src[$key] ?? null) && trim((string) $src[$key]) !== '') {
                $violations[] = self::VIOLATION_SHELL_STRING;

                return [[], StewardshipStringListNormalizer::uniqueStrings($violations)];
            }
        }

        $raw = null;
        foreach (['command_argv', 'command', 'argv'] as $key) {
            if (is_array($src[$key] ?? null)) {
                $raw = $src[$key];
                break;
            }
        }
        if ($raw === null) {
            $nested = data_get($src, 'command.argv');
            if (is_array($nested)) {
                $raw = $nested;
            }
        }
        if (! is_array($raw)) {
            return [[], $violations];
        }

        $argv = array_values(array_map(static fn (mixed $p): string => (string) $p, $raw));

        return [AtlasSecurity::redactCommand($argv), $violations];
    }

    /**
     * @param  array<string,mixed>  $src
     */
    private function providerId(array $src): string
    {
        $value = $this->scalarString($src, ['provider_id', 'provider'])
            ?: (string) data_get($src, 'provider_result.provider', '')
            ?: (string) data_get($src, 'owner_flow.provider', '');

        return $value !== '' ? $value : 'unknown';
    }

    /**
     * @param  array<string,mixed>  $src
     */
    private function modelFamily(array $src): string
    {
        return $this->scalarString($src, ['model_family', 'model', 'resolved_model_id'])
            ?: (string) data_get($src, 'provider_result.model', '')
            ?: (string) data_get($src, 'owner_flow.model', '');
    }

    /**
     * @param  array<string,mixed>  $src
     */
    private function workingDirectory(array $src): string
    {
        return $this->scalarString($src, ['working_directory', 'worktree_path', 'cwd', 'workspace_path'])
            ?: (string) data_get($src, 'workspace.path', '');
    }

    /**
     * @param  array<string,mixed>  $src
     */
    private function resumeToken(array $src): string
    {
        $token = $this->scalarString($src, ['resume_token', 'resume_handle', 'continuation_token']);

        // Even on the runtime-facing port we redact obvious secret shapes; the
        // durable store keeps only a hash.
        return $token === '' ? '' : AtlasSecurity::redactString($token);
    }

    // ---------- normalized fact blocks ----------

    /**
     * @param  array<string,mixed>  $src
     * @return list<array<string,string>>
     */
    private function streamEvents(array $src): array
    {
        $raw = $src['stream_events'] ?? data_get($src, 'provider_result.stream_events');
        if (! is_array($raw)) {
            return [];
        }

        $events = [];
        foreach (array_slice(array_values(array_filter($raw, 'is_array')), 0, self::MAX_STREAM_EVENTS) as $event) {
            $kind = $this->streamKind((string) ($event['kind'] ?? $event['type'] ?? 'other'));
            $excerptSource = (string) ($event['text'] ?? $event['content'] ?? $event['message'] ?? '');
            $events[] = [
                'kind' => $kind,
                'excerpt' => substr(AtlasSecurity::redactString($excerptSource), 0, self::MAX_EXCERPT_CHARS),
            ];
        }

        return $events;
    }

    private function streamKind(string $kind): string
    {
        $kind = strtolower(trim($kind));

        return in_array($kind, ['text', 'tool', 'error', 'usage'], true) ? $kind : 'other';
    }

    /**
     * @param  array<string,mixed>  $src
     * @return array<string,mixed>
     */
    private function usage(array $src): array
    {
        $usage = is_array($src['usage'] ?? null) ? $src['usage'] : (is_array(data_get($src, 'provider_result.usage')) ? data_get($src, 'provider_result.usage') : []);

        return [
            'input_tokens' => $this->intOrNull($usage['input_tokens'] ?? null),
            'output_tokens' => $this->intOrNull($usage['output_tokens'] ?? null),
            'total_tokens' => $this->intOrNull($usage['total_tokens'] ?? null),
            'cost_proxy' => $this->floatOrNull($usage['cost_proxy'] ?? $usage['cost'] ?? null),
            'duration_ms' => $this->intOrNull($src['duration_ms'] ?? data_get($src, 'provider_result.duration_ms') ?? ($usage['duration_ms'] ?? null)),
            'limit_state' => (string) ($usage['limit_state'] ?? 'unknown'),
        ];
    }

    /**
     * Always one of the five canonical auth modes. Explicit input wins; otherwise
     * it is derived from honest hints and falls back to `unknown`.
     *
     * @param  array<string,mixed>  $src
     */
    private function authMode(array $src): string
    {
        $explicit = strtolower(trim((string) ($src['auth_mode'] ?? data_get($src, 'auth.mode', ''))));
        if (in_array($explicit, self::AUTH_MODES, true)) {
            return $explicit;
        }

        $providerAuthorized = $src['provider_authorized'] ?? data_get($src, 'owner_flow.provider_invoked');
        if ($providerAuthorized === false) {
            return self::AUTH_BLOCKED;
        }
        if ($this->truthy($src['blocked'] ?? null) || $this->truthy(data_get($src, 'authority_blocked'))) {
            return self::AUTH_BLOCKED;
        }

        if ($this->truthy($src['api_key_present'] ?? null) || $this->hasNonEmpty($src, ['api_key']) || $this->hasNonEmpty($src, ['auth.api_key'])) {
            return self::AUTH_API_KEY;
        }

        if ($this->truthy($src['local_model'] ?? null) || in_array($this->providerId($src), self::LOCAL_MODEL_PROVIDERS, true)) {
            return self::AUTH_LOCAL_MODEL;
        }

        if ($this->truthy($src['local_login'] ?? null) || $this->truthy($src['local_account'] ?? null) || $this->truthy($src['account_login'] ?? null)) {
            return self::AUTH_LOCAL_ACCOUNT;
        }

        return self::AUTH_UNKNOWN;
    }

    /**
     * @param  array<string,mixed>  $src
     */
    private function permissionMode(array $src): string
    {
        $value = $this->scalarString($src, ['permission_mode', 'sandbox_permission_mode', 'tool_permission_mode']);

        return $value !== '' ? $value : 'unknown';
    }

    /**
     * @param  array<string,mixed>  $src
     * @return array<string,mixed>
     */
    private function exitStatus(array $src): array
    {
        $explicit = is_array($src['exit_status'] ?? null) ? $src['exit_status'] : [];
        $code = $explicit['code'] ?? $src['exit_code'] ?? data_get($src, 'provider_result.exit_code');
        $state = (string) ($explicit['state'] ?? '');
        if ($state === '') {
            $state = $code === null ? 'unknown' : ((int) $code === 0 ? 'succeeded' : 'failed');
        }

        return [
            'state' => $state,
            'code' => $this->intOrNull($code),
            'signal' => $this->scalarString($explicit, ['signal']) ?: null,
        ];
    }

    /**
     * @param  array<string,mixed>  $src
     * @return array<string,mixed>
     */
    private function timeoutState(array $src): array
    {
        $timedOut = $this->truthy($src['timed_out'] ?? data_get($src, 'exit_status.timed_out') ?? data_get($src, 'provider_result.timed_out'));

        return [
            'timed_out' => $timedOut,
            'timeout_seconds' => $this->intOrNull($src['timeout_seconds'] ?? data_get($src, 'provider_result.timeout_seconds')),
        ];
    }

    /**
     * @param  array<string,mixed>  $src
     * @return array<string,mixed>
     */
    private function rateLimitState(array $src): array
    {
        $rate = is_array($src['rate_limit_state'] ?? null) ? $src['rate_limit_state'] : [];
        $limited = $this->truthy($rate['limited'] ?? $src['rate_limited'] ?? data_get($src, 'provider_result.rate_limited'));

        return [
            'limited' => $limited,
            'backoff_seconds' => $this->intOrNull($rate['backoff_seconds'] ?? $src['backoff_seconds'] ?? null),
            'retry_count' => $this->intOrNull($rate['retry_count'] ?? $src['retry_count'] ?? null),
        ];
    }

    /**
     * `real` is only returned when an explicit real-invocation signal is present.
     * Everything else stays an honest non-run.
     *
     * @param  array<string,mixed>  $src
     */
    private function invocationState(array $src): string
    {
        $explicit = strtolower(trim((string) ($src['invocation_state'] ?? '')));
        if (in_array($explicit, self::INVOCATION_STATES, true)) {
            return $explicit;
        }

        $realSignal = $this->truthy($src['provider_invoked'] ?? null)
            || $this->truthy($src['provider_called'] ?? null)
            || $this->truthy(data_get($src, 'provider_result.provider_called'))
            || $this->truthy(data_get($src, 'owner_flow.provider_invoked'));
        if ($realSignal) {
            return self::INVOCATION_REAL;
        }

        if ($this->truthy($src['simulated'] ?? null) || $this->truthy($src['fake_run'] ?? null) || $this->truthy($src['fixture'] ?? null)) {
            return self::INVOCATION_SIMULATED;
        }

        $status = strtolower((string) ($src['final_status'] ?? $src['status'] ?? data_get($src, 'owner_flow.status', '')));
        if ($this->truthy($src['deferred'] ?? null) || str_contains($status, 'deferred') || str_contains($status, 'bridge_missing')) {
            return self::INVOCATION_DEFERRED;
        }

        // dry-run, planned, runtime-dispatch plans and unknown all resolve to the
        // safe non-claim state: planned. They never become `real`.
        return self::INVOCATION_PLANNED;
    }

    // ---------- helpers ----------

    /**
     * @param  array<string,mixed>  $src
     * @param  list<string>  $keys
     */
    private function scalarString(array $src, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $src[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $src
     * @param  list<string>  $paths
     */
    private function hasNonEmpty(array $src, array $paths): bool
    {
        foreach ($paths as $path) {
            $value = data_get($src, $path);
            if (is_scalar($value) && (string) $value !== '') {
                return true;
            }
        }

        return false;
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return is_int($value) ? $value === 1 : false;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    private function identity(array $record): array
    {
        $copy = $record;
        unset($copy['port_hash']);

        return $copy;
    }
}
