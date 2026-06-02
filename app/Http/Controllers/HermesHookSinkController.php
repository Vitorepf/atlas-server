<?php

namespace App\Http\Controllers;

use App\Services\Ai\Hermes\HermesHookSink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Loopback-only ingress for Hermes lifecycle hook events.
 *
 * Hermes' Atlas-managed hooks POST each lifecycle event to this route carrying a
 * per-session token in `X-Atlas-Hook-Token`. The controller is the boundary
 * guard: it refuses any non-loopback caller and any request whose token does not
 * match the per-session token (constant-time `hash_equals`), returning 403
 * WITHOUT recording the raw payload. Only authenticated, loopback requests are
 * delegated to {@see HermesHookSink::ingest}, which redacts the tool input,
 * records the sealed Atlas Evidence Ledger event, and computes the allow/block
 * decision. Blocks come back as 200 `{"decision":"block","reason":...}` so
 * Hermes' `pre_tool_call` hook can read the verdict from stdout; allow/observe
 * decisions return the full `atlas.hermes.hook_event_decision.v1` receipt.
 */
class HermesHookSinkController extends Controller
{
    public function __invoke(Request $request, string $trace, HermesHookSink $sink): JsonResponse
    {
        $traceId = trim($trace);

        if (! $this->isLoopback($request)) {
            return response()->json([
                'decision' => 'block',
                'reason' => 'non_loopback_origin',
            ], 403);
        }

        $expectedToken = $this->expectedToken($traceId);
        $presentedToken = (string) $request->header('X-Atlas-Hook-Token', '');

        if ($traceId === '' || $presentedToken === '' || ! hash_equals($expectedToken, $presentedToken)) {
            // Fail-closed: never touch the body, never record on a bad token.
            return response()->json([
                'decision' => 'block',
                'reason' => 'hook_token_invalid',
            ], 403);
        }

        $hookEvent = $this->hookEvent($request, $traceId);
        $sessionContext = $this->sessionContext($request, $traceId, $expectedToken, $presentedToken);

        $decision = $sink->ingest($hookEvent, $sessionContext);

        return response()->json($decision, 200);
    }

    private function isLoopback(Request $request): bool
    {
        $ip = (string) $request->ip();

        if (in_array($ip, ['127.0.0.1', '::1', 'localhost'], true)) {
            return true;
        }

        if (str_starts_with($ip, '127.')) {
            return true;
        }

        // CLI / test harness has no remote address.
        return $ip === '';
    }

    /**
     * Deterministic per-session token, identical to the derivation used by the
     * bridge that wrote the managed hook command. Not persisted; never logged.
     */
    private function expectedToken(string $traceId): string
    {
        return substr(hash('sha256', 'hermes_hook_token|'.$traceId), 0, 48);
    }

    /**
     * @return array<string,mixed>
     */
    private function hookEvent(Request $request, string $traceId): array
    {
        $payload = $request->json()->all();
        $payload = is_array($payload) ? $payload : [];

        if (! isset($payload['session_id']) || ! is_string($payload['session_id'])) {
            $payload['session_id'] = $traceId;
        }

        return $payload;
    }

    /**
     * The session context carries the trace, the expected token (so the sink can
     * re-validate with `hash_equals`), the presented token, and the mission
     * scope. Scope is resolved from the Atlas-injected `extra.mission_scope` of
     * the posted event (the bridge embeds it); absence means the sink fails
     * closed and blocks.
     *
     * @return array<string,mixed>
     */
    private function sessionContext(Request $request, string $traceId, string $expectedToken, string $presentedToken): array
    {
        $payload = $request->json()->all();
        $payload = is_array($payload) ? $payload : [];
        $extra = is_array($payload['extra'] ?? null) ? $payload['extra'] : [];

        $scope = $this->resolveScope($extra);

        return [
            'trace_id' => $traceId,
            'sink' => ['token' => $expectedToken],
            'presented_token' => $presentedToken,
            'mission_scope' => $scope,
            'mission_id' => $this->nullableString($extra['mission_id'] ?? null),
            'mission_hash' => $this->nullableString($extra['mission_hash'] ?? null),
            'tenant_id' => $this->nullableString($extra['tenant_id'] ?? null),
            'operator_id' => $this->nullableString($extra['operator_id'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>|null
     */
    private function resolveScope(array $extra): ?array
    {
        $scope = $extra['mission_scope'] ?? $extra['scope'] ?? null;

        // No scope injected => null so the sink fails closed. We never
        // synthesize a permissive scope.
        return is_array($scope) ? $scope : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, 120, '');
    }
}
