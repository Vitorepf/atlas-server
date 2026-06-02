<?php

namespace App\Services\Ai\Hermes;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Support\AtlasSecurity;
use Illuminate\Support\Str;
use Throwable;

/**
 * Loopback-only ingest for Hermes lifecycle hook events bridged by
 * {@see HermesHookBridge}.
 *
 * Hermes POSTs each hook event (`pre_tool_call`, `post_tool_call`, lifecycle
 * markers) to a token-guarded loopback route; the controller authenticates and
 * delegates here. This sink is the Atlas-sovereign decision point: it validates
 * the per-session token and ATLS trace, resolves the mission scope
 * (`allowed_tools` / `forbidden_paths` / `allowed_paths`) the operator authored,
 * REDACTS the `tool_input` before anything is recorded, records a sealed Atlas
 * Evidence Ledger event, and — for `pre_tool_call` — returns an allow/block
 * decision computed against that scope. It is fail-closed: a missing token,
 * missing trace, or missing scope yields `block`. The raw `tool_input` is NEVER
 * stored — only its redacted form and a sha256 digest reach the ledger and the
 * returned `atlas.hermes.hook_event_decision.v1` receipt.
 */
class HermesHookSink
{
    use HermesAdapterReceipt;

    private const SCHEMA_VERSION = 'atlas.hermes.hook_event_decision.v1';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @param  array<string,mixed>  $hookEvent
     * @param  array<string,mixed>  $sessionContext
     * @return array<string,mixed>
     */
    public function ingest(array $hookEvent, array $sessionContext): array
    {
        $eventName = $this->string($hookEvent['hook_event_name'] ?? null, 60) ?? 'unknown';
        $toolName = $this->string($hookEvent['tool_name'] ?? null, 160);
        $isPreTool = $eventName === 'pre_tool_call';

        $rawToolInput = is_array($hookEvent['tool_input'] ?? null) ? $hookEvent['tool_input'] : [];
        $redactedToolInput = AtlasSecurity::redactArray($rawToolInput);
        $toolInputHash = hash('sha256', json_encode($redactedToolInput, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]');

        $receipt = [
            'schema_version' => self::SCHEMA_VERSION,
            'adapter' => 'hermes_hook_sink',
            'hook_authority' => 'atlas',
            'event' => $eventName,
            'tool_name' => $toolName,
            'decision' => $isPreTool ? 'block' : 'observed',
            'reason' => null,
            'recorded' => false,
            'tool_input_hash' => $toolInputHash,
            'evidence_sink' => 'atlas_local_loopback',
        ];

        // Fail-closed: a session without a valid token cannot be trusted.
        if (! $this->tokenValid($hookEvent, $sessionContext)) {
            $receipt['decision'] = 'block';
            $receipt['reason'] = 'token_invalid';

            return $this->withReceiptHash($receipt);
        }

        $traceId = $this->traceId($hookEvent, $sessionContext);
        if ($traceId === null) {
            $receipt['decision'] = 'block';
            $receipt['reason'] = 'atls_trace_missing';

            return $this->withReceiptHash($receipt);
        }

        $scope = $this->resolveScope($sessionContext);
        if ($scope === null) {
            $receipt['decision'] = 'block';
            $receipt['reason'] = 'mission_scope_missing';

            return $this->withReceiptHash($receipt);
        }

        if ($isPreTool) {
            $verdict = $this->preToolDecision($toolName, $redactedToolInput, $scope);
            $receipt['decision'] = $verdict['decision'];
            $receipt['reason'] = $verdict['reason'];
        } else {
            $receipt['decision'] = 'observed';
            $receipt['reason'] = 'lifecycle_event_recorded';
        }

        $receipt['recorded'] = $this->record($eventName, $toolName, $toolInputHash, $redactedToolInput, $receipt['decision'], $receipt['reason'], $traceId, $sessionContext);

        return $this->withReceiptHash($receipt);
    }

    /**
     * @param  array<string,mixed>  $hookEvent
     * @param  array<string,mixed>  $sessionContext
     */
    private function tokenValid(array $hookEvent, array $sessionContext): bool
    {
        $expected = $this->string(
            data_get($sessionContext, 'sink.token')
                ?? data_get($sessionContext, 'hook_token')
                ?? data_get($sessionContext, 'token'),
            120,
        );

        if ($expected === null) {
            // No expected token configured for this session => fail-closed.
            return false;
        }

        $presented = $this->string(
            $hookEvent['token'] ?? data_get($hookEvent, 'extra.token') ?? data_get($sessionContext, 'presented_token'),
            120,
        );

        if ($presented === null) {
            return false;
        }

        return hash_equals($expected, $presented);
    }

    /**
     * @param  array<string,mixed>  $hookEvent
     * @param  array<string,mixed>  $sessionContext
     */
    private function traceId(array $hookEvent, array $sessionContext): ?string
    {
        return $this->string(data_get($sessionContext, 'trace_id'), 80)
            ?? $this->string($hookEvent['session_id'] ?? null, 80)
            ?? $this->string(data_get($hookEvent, 'extra.trace_id'), 80);
    }

    /**
     * Mission scope is required and must carry at least one positive constraint
     * (allowed_tools or allowed_paths) for the session to be considered scoped.
     *
     * @param  array<string,mixed>  $sessionContext
     * @return array{allowed_tools:array<int,string>,forbidden_paths:array<int,string>,allowed_paths:array<int,string>}|null
     */
    private function resolveScope(array $sessionContext): ?array
    {
        $scopeSource = data_get($sessionContext, 'mission_scope')
            ?? data_get($sessionContext, 'scope')
            ?? data_get($sessionContext, 'mission.scope');

        if (! is_array($scopeSource)) {
            return null;
        }

        $allowedTools = $this->stringList($scopeSource['allowed_tools'] ?? null);
        $forbiddenPaths = $this->stringList($scopeSource['forbidden_paths'] ?? null);
        $allowedPaths = $this->stringList($scopeSource['allowed_paths'] ?? null);

        if ($allowedTools === [] && $allowedPaths === []) {
            // A scope with no positive grants is not a usable scope => fail-closed.
            return null;
        }

        return [
            'allowed_tools' => $allowedTools,
            'forbidden_paths' => $forbiddenPaths,
            'allowed_paths' => $allowedPaths,
        ];
    }

    /**
     * @param  array<string,mixed>  $toolInput
     * @param  array{allowed_tools:array<int,string>,forbidden_paths:array<int,string>,allowed_paths:array<int,string>}  $scope
     * @return array{decision:string,reason:?string}
     */
    private function preToolDecision(?string $toolName, array $toolInput, array $scope): array
    {
        if ($toolName === null) {
            return ['decision' => 'block', 'reason' => 'tool_name_missing'];
        }

        if ($scope['allowed_tools'] !== [] && ! $this->toolAllowed($toolName, $scope['allowed_tools'])) {
            return ['decision' => 'block', 'reason' => 'tool_not_in_allowed_tools'];
        }

        $paths = $this->candidatePaths($toolInput);

        foreach ($paths as $path) {
            if ($this->pathMatchesAny($path, $scope['forbidden_paths'])) {
                return ['decision' => 'block', 'reason' => 'path_in_forbidden_paths'];
            }
        }

        if ($scope['allowed_paths'] !== []) {
            foreach ($paths as $path) {
                if (! $this->pathMatchesAny($path, $scope['allowed_paths'])) {
                    return ['decision' => 'block', 'reason' => 'path_outside_allowed_paths'];
                }
            }
        }

        return ['decision' => 'allow', 'reason' => 'in_scope'];
    }

    /**
     * @param  array<int,string>  $allowedTools
     */
    private function toolAllowed(string $toolName, array $allowedTools): bool
    {
        $needle = strtolower(trim($toolName));

        foreach ($allowedTools as $allowed) {
            $allowed = strtolower(trim($allowed));
            if ($allowed === '' ) {
                continue;
            }
            if ($allowed === '*' || $allowed === $needle) {
                return true;
            }
            // toolset prefix match (e.g. "bash" allows "bash:run").
            if (str_contains($needle, ':') && str_starts_with($needle, $allowed.':')) {
                return true;
            }
            if (str_contains($allowed, ':') && str_starts_with($allowed, $needle.':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $toolInput
     * @return array<int,string>
     */
    private function candidatePaths(array $toolInput): array
    {
        $paths = [];

        foreach (['path', 'file_path', 'filepath', 'file', 'target', 'cwd', 'directory', 'dir', 'output_path'] as $key) {
            $value = $toolInput[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $paths[] = trim($value);
            }
        }

        $list = $toolInput['paths'] ?? $toolInput['files'] ?? null;
        if (is_array($list)) {
            foreach ($list as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $paths[] = trim($value);
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  array<int,string>  $roots
     */
    private function pathMatchesAny(string $path, array $roots): bool
    {
        foreach ($roots as $root) {
            if (trim($root) === '') {
                continue;
            }

            if ($this->pathIsInside($path, $root)) {
                return true;
            }
        }

        return false;
    }

    private function pathIsInside(string $path, string $root): bool
    {
        if (class_exists(AtlasSecurity::class) && method_exists(AtlasSecurity::class, 'pathIsInside')) {
            try {
                return AtlasSecurity::pathIsInside($path, $root);
            } catch (Throwable) {
                // fall through to string prefix
            }
        }

        $pathWithSep = rtrim($path, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $rootWithSep = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return $pathWithSep === $rootWithSep || str_starts_with($pathWithSep, $rootWithSep);
    }

    /**
     * @param  array<string,mixed>  $redactedToolInput
     * @param  array<string,mixed>  $sessionContext
     */
    private function record(
        string $eventName,
        ?string $toolName,
        string $toolInputHash,
        array $redactedToolInput,
        string $decision,
        ?string $reason,
        string $traceId,
        array $sessionContext,
    ): bool {
        if (! method_exists($this->ledger, 'record')) {
            return false;
        }

        $type = $decision === 'block'
            ? LedgerEventType::ToolPlanned
            : ($eventName === 'pre_tool_call' ? LedgerEventType::ToolApproved : LedgerEventType::ToolEvidenceRecorded);

        $missionHash = $this->string(
            data_get($sessionContext, 'mission_hash') ?? data_get($sessionContext, 'mission.mission_hash'),
            64,
        );
        $missionId = $this->string(
            data_get($sessionContext, 'mission_id') ?? data_get($sessionContext, 'mission.mission_id'),
            120,
        );

        try {
            $event = $this->ledger->record($type, [
                'schema_version' => self::SCHEMA_VERSION,
                'source' => 'hermes_hook_sink',
                'hook_authority' => 'atlas',
                'provider_is_executor_only' => true,
                'event' => $eventName,
                'tool_name' => $toolName,
                'decision' => $decision,
                'reason' => $reason,
                'tool_input_hash' => $toolInputHash,
                'tool_input_redacted' => $redactedToolInput,
                'mission_id' => $missionId,
                'mission_hash' => $missionHash,
                'trace_id' => $traceId,
            ], [
                'tenant_id' => $this->string(data_get($sessionContext, 'tenant_id'), 120) ?? 'default',
                'operator_id' => $this->string(data_get($sessionContext, 'operator_id'), 120) ?? 'system',
                'envelope_id' => 'hermes_hook:'.$traceId,
                'trace_id' => $traceId,
                'correlation_id' => $missionId ?? $traceId,
                'emitter_stage' => 'atlas.hermes_hook_sink',
                'emitter_version' => 'atlas.hermes_hook_sink.v1',
            ]);

            return $event !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        $items = is_array($value) ? $value : (is_string($value) ? preg_split('/\s*,\s*/', $value) ?: [] : []);

        return collect($items)
            ->map(fn (mixed $item): ?string => $this->string($item, 4000))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function string(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
