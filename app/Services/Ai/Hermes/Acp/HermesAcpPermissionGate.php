<?php

declare(strict_types=1);

namespace App\Services\Ai\Hermes\Acp;

use App\Services\Ai\Hermes\HermesAdapterReceipt;
use App\Services\Ai\Hermes\HermesHookSink;
use App\Services\Ai\Hermes\Mesh\SharedHermesCheckpointPolicySeam;
use App\Services\Ai\Hermes\Support\HermesStringListNormalizer;
use App\Support\AtlasSecurity;
use Throwable;

/**
 * Atlas-sovereign gate for agent-initiated ACP `session/request_permission`.
 *
 * The ACP transport (`hermes acp`) unlocks a new human-in-the-loop power: mid
 * prompt, the agent can send a JSON-RPC REQUEST
 * `method="session/request_permission"` with `params.options[]` (each
 * `{optionId,name,kind}`) and a `params.toolCall{}` describing the action it
 * wants to take. The client MUST answer. This gate is the PURE decision core:
 * given the raw permission request, the mission scope and the Atlas permission
 * mode, it emits the sealed verdict the transport then maps onto the ACP
 * response (`{outcome:{outcome:"selected",optionId}}` for allow, or
 * `{outcome:{outcome:"cancelled"}}` for deny/escalate). It NEVER does I/O,
 * never talks to Hermes and never calls a model.
 *
 * Fail-closed invariants (default-deny — Atlas decides, Hermes executes):
 *  - The permission mode is normalized to read|write|danger; anything else =>
 *    `read`, the safest mode.
 *  - In `read` mode the verdict is ALWAYS `deny`: a read-only mission must not
 *    let the agent take a write/exec action mid-run, regardless of scope.
 *  - When the request carries no usable options the verdict is `deny`.
 *  - In `write`/`danger` mode the verdict is `allow` ONLY when the requested
 *    action is within the mission scope (every tool-target path is under
 *    `allowed_paths` and none is under `forbidden_paths`) AND an option clearly
 *    grants the action (an allow-once style option). Out-of-scope or
 *    no-clear-allow paths NEVER silently allow — they `escalate` to a human.
 *  - `authority` is always Atlas and `hermes_acp_can_decide` is always false.
 *  - The receipt records only HASHES of the toolCall and options (never the raw
 *    tool input or option names), sealed with a deterministic `receipt_hash` as
 *    the LAST statement on every path.
 */
class HermesAcpPermissionGate
{
    use HermesAdapterReceipt;

    /**
     * Decide how to answer one agent-initiated `session/request_permission`.
     * Side-effect free; returns a sealed
     * `atlas.hermes.acp_permission_decision.v1` receipt.
     *
     * @param  array<string,mixed>  $permissionRequest  The raw JSON-RPC request (or its `params`).
     * @param  array<string,mixed>  $missionScope  e.g. {allowed_paths:[...], forbidden_paths:[...]}.
     * @return array<string,mixed>
     */
    public function decide(array $permissionRequest, array $missionScope, string $permissionMode): array
    {
        $permissionMode = $this->permissionMode($permissionMode);

        $params = $this->params($permissionRequest);
        $options = $this->options($params);
        $toolCall = $this->toolCall($params);
        $paths = $this->candidatePaths($toolCall);
        $allowedPaths = HermesStringListNormalizer::csv($missionScope['allowed_paths'] ?? null, 4000);
        $forbiddenPaths = HermesStringListNormalizer::csv($missionScope['forbidden_paths'] ?? null, 4000);

        $receipt = [
            'schema_version' => 'atlas.hermes.acp_permission_decision.v1',
            'gate' => 'hermes_acp_permission_gate',
            'authority' => 'atlas',
            'hermes_acp_can_decide' => false,
            'permission_mode' => $permissionMode,
            'decision' => 'deny',
            'option_id' => null,
            'reason' => null,
            'request_id' => $this->requestId($permissionRequest),
            'option_count' => count($options),
            'tool_call_hash' => $toolCall === [] ? null : $this->hashValue($toolCall),
            'options_hash' => $options === [] ? null : $this->hashValue($options),
            'candidate_path_count' => count($paths),
            'path_in_scope' => false,
            'status' => 'denied',
        ];

        // 1. A read-only mission must never let the agent take an action mid-run.
        if ($permissionMode === 'read') {
            return $this->finalize($receipt, 'deny', null, 'permission_mode_read', 'denied');
        }

        // Defensive: any non read/write/danger value already folded to `read`
        // above; this keeps the gate fail-closed if the allow-list ever changes.
        if (! in_array($permissionMode, ['write', 'danger'], true)) {
            return $this->finalize($receipt, 'deny', null, 'permission_mode_not_write_or_danger', 'denied');
        }

        // 2. No options to choose from => nothing safe to select => deny.
        if ($options === []) {
            return $this->finalize($receipt, 'deny', null, 'no_permission_options', 'denied');
        }

        // 3. The requested action must be inside the mission scope. Out-of-scope
        // is NEVER silently allowed — it escalates to a human.
        $scopeReason = $this->scopeViolation($paths, $allowedPaths, $forbiddenPaths);
        if ($scopeReason !== null) {
            return $this->finalize($receipt, 'escalate', null, $scopeReason, 'escalated');
        }

        $receipt['path_in_scope'] = true;

        // 4. Pick an option that clearly grants the action (allow-once style).
        // If none clearly allows, escalate rather than guess.
        $optionId = $this->allowOptionId($options);
        if ($optionId === null) {
            return $this->finalize($receipt, 'escalate', null, 'no_clear_allow_option', 'escalated');
        }

        // Fully-approved path: write/danger mission, in-scope action, explicit
        // allow-once option selected.
        return $this->finalize($receipt, 'allow', $optionId, 'in_scope_allow_once', 'allowed');
    }

    /**
     * Seal the receipt as the last statement on every path.
     *
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function finalize(array $receipt, string $decision, ?string $optionId, string $reason, string $status): array
    {
        $receipt['decision'] = $decision;
        $receipt['option_id'] = $optionId;
        $receipt['reason'] = $reason;
        $receipt['status'] = $status;

        return $this->withReceiptHash($receipt);
    }

    private function permissionMode(string $permissionMode): string
    {
        $mode = strtolower(trim($permissionMode));

        return in_array($mode, ['read', 'write', 'danger'], true) ? $mode : 'read';
    }

    /**
     * Accept either the full JSON-RPC request `{...,params:{...}}` or a bare
     * `params` array, so the gate is robust to however the transport hands it
     * the request.
     *
     * @param  array<string,mixed>  $permissionRequest
     * @return array<string,mixed>
     */
    private function params(array $permissionRequest): array
    {
        $params = $permissionRequest['params'] ?? null;
        if (is_array($params)) {
            return $params;
        }

        return $permissionRequest;
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<int,array<string,mixed>>
     */
    private function options(array $params): array
    {
        $options = $params['options'] ?? null;
        if (! is_array($options)) {
            return [];
        }

        $out = [];
        foreach ($options as $option) {
            if (! is_array($option)) {
                continue;
            }

            $optionId = SharedHermesCheckpointPolicySeam::boundedString($option['optionId'] ?? null, 190);
            if ($optionId === null) {
                // An option with no usable id can never be selected safely.
                continue;
            }

            $out[] = [
                'optionId' => $optionId,
                'name' => SharedHermesCheckpointPolicySeam::boundedString($option['name'] ?? null, 190),
                'kind' => SharedHermesCheckpointPolicySeam::boundedString($option['kind'] ?? null, 80),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function toolCall(array $params): array
    {
        $toolCall = $params['toolCall'] ?? $params['tool_call'] ?? null;

        return is_array($toolCall) ? $toolCall : [];
    }

    /**
     * Extract every filesystem target the requested tool call would touch so the
     * scope check can prove it is inside `allowed_paths`. Mirrors the canonical
     * {@see HermesHookSink} path harvesting and adds the
     * ACP `toolCall.locations[].path` array.
     *
     * @param  array<string,mixed>  $toolCall
     * @return array<int,string>
     */
    private function candidatePaths(array $toolCall): array
    {
        $paths = [];

        $locations = $toolCall['locations'] ?? null;
        if (is_array($locations)) {
            foreach ($locations as $location) {
                $value = is_array($location) ? ($location['path'] ?? null) : $location;
                if (is_string($value) && trim($value) !== '') {
                    $paths[] = trim($value);
                }
            }
        }

        // ACP carries the concrete tool input under `rawInput` (and some agents
        // mirror it as `input`); scan both plus the toolCall root for path-ish
        // keys so a write target can never slip past the scope check unseen.
        foreach ([$toolCall, $toolCall['rawInput'] ?? null, $toolCall['input'] ?? null] as $bag) {
            if (! is_array($bag)) {
                continue;
            }

            foreach (['path', 'file_path', 'filepath', 'file', 'target', 'cwd', 'directory', 'dir', 'output_path', 'destination'] as $key) {
                $value = $bag[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $paths[] = trim($value);
                }
            }

            $list = $bag['paths'] ?? $bag['files'] ?? null;
            if (is_array($list)) {
                foreach ($list as $value) {
                    if (is_string($value) && trim($value) !== '') {
                        $paths[] = trim($value);
                    }
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Decide whether the requested action is out of scope. Returns the
     * fail-closed reason to escalate on, or null when fully in scope.
     *
     * Fail-closed: when the mission scope declares NO positive grant
     * (`allowed_paths` empty) the action cannot be proven in-scope, so it
     * escalates. A path inside any `forbidden_paths` always escalates. When the
     * tool call exposes no inspectable path at all but the scope is path-gated,
     * we cannot prove the action is in-scope => escalate.
     *
     * @param  array<int,string>  $paths
     * @param  array<int,string>  $allowedPaths
     * @param  array<int,string>  $forbiddenPaths
     */
    private function scopeViolation(array $paths, array $allowedPaths, array $forbiddenPaths): ?string
    {
        foreach ($paths as $path) {
            if ($this->pathMatchesAny($path, $forbiddenPaths)) {
                return 'path_in_forbidden_paths';
            }
        }

        if ($allowedPaths === []) {
            return 'out_of_scope_no_allowed_paths';
        }

        if ($paths === []) {
            // Scope is path-gated but the action exposes no path to verify; we
            // cannot prove it is in-scope, so a human must decide.
            return 'tool_call_has_no_inspectable_path';
        }

        foreach ($paths as $path) {
            if (! $this->pathMatchesAny($path, $allowedPaths)) {
                return 'path_outside_allowed_paths';
            }
        }

        return null;
    }

    /**
     * Pick the option that clearly grants the action once. Prefers an explicit
     * allow-once `kind`, then an `optionId`/`name` that says "allow". Anything
     * that looks like reject/deny/cancel/always is NEVER treated as allow.
     *
     * @param  array<int,array<string,mixed>>  $options
     */
    private function allowOptionId(array $options): ?string
    {
        // First pass: the ACP-canonical machine-readable signal — kind.
        foreach ($options as $option) {
            $kind = strtolower((string) ($option['kind'] ?? ''));
            if ($kind === 'allow_once' || $kind === 'allow-once' || $kind === 'allowonce') {
                return $option['optionId'];
            }
        }

        // Second pass: a single-grant allow expressed via id/name. We deliberately
        // skip "allow always"/"allow all" — a per-run gate grants once, not
        // standing permission — and skip any reject/deny/cancel option.
        foreach ($options as $option) {
            $optionId = strtolower((string) $option['optionId']);
            $name = strtolower((string) ($option['name'] ?? ''));
            $haystack = $optionId.' '.$name;

            if ($this->looksLikeReject($haystack)) {
                continue;
            }
            if (str_contains($haystack, 'always') || str_contains($haystack, 'all_') || str_contains($haystack, 'all ')) {
                continue;
            }
            if (str_contains($haystack, 'allow') || str_contains($haystack, 'approve') || str_contains($haystack, 'accept') || str_contains($haystack, 'yes')) {
                return $option['optionId'];
            }
        }

        return null;
    }

    private function looksLikeReject(string $haystack): bool
    {
        foreach (['reject', 'deny', 'denied', 'cancel', 'decline', 'forbid', 'block', 'no_', 'never'] as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
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

    /**
     * Canonical containment check: prefer {@see AtlasSecurity::pathIsInside} and
     * fall back to a deterministic separator-aware string prefix so the gate
     * stays pure/testable without touching the filesystem. Mirrors
     * {@see HermesHookSink}.
     */
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
     * @param  array<string,mixed>  $permissionRequest
     */
    private function requestId(array $permissionRequest): ?string
    {
        $id = $permissionRequest['id'] ?? null;
        if (is_int($id)) {
            return (string) $id;
        }

        return SharedHermesCheckpointPolicySeam::boundedString($id, 190);
    }
}
