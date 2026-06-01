<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Dev Efficient Programming Flow v1 · Parte 4 — pure, deterministic
 * decider for the "Sumario Operacional (Estado Real Implementado)" slice
 * (§26.4) plus §27 Regra Final.
 *
 * This service does NOT execute anything and touches no I/O. It encodes the
 * documented operational contract as a closed set of typed decisions so an
 * agent (or any caller) can ask, without re-reading prose:
 *   - given the three Atlas Dev Efficient flags, which endpoint is enabled and,
 *     when disabled, exactly which 503 code does the controller return? (§26.4
 *     "Flags Atlas Dev Efficient")
 *   - what is the unlock ORDER (Plan is safe first; Run only after Plan is green
 *     in prod; Desktop gates the surface)? (§26.4)
 *   - is this APP_KEY acceptable, or does Plan/Run fail closed with
 *     500 ATLAS_DEV_KEY_MISSING? (§26.4 "APP_KEY obrigatorio (fail-closed)")
 *   - is a given run-stream event sequence a valid snapshot-replay-then-close,
 *     and what is the canonical end marker / source of truth? (§26.4 "Stream
 *     snapshot-replay-then-close")
 *   - does an HTTP response body leak an absolute path, and how is a workspace
 *     path redacted into label/hash/refs? (§26.4 "Path redaction nas respostas")
 *   - which bundle hashes does the HMAC confirmation pin actually cover today,
 *     and which mismatch code fires? Which are documented follow-ups NOT yet
 *     delivered? (§26.4 "Follow-ups explicitos")
 *
 * Documented rules this code actually enforces (one-to-one with the doc):
 *   §26.4 Flags — three flags, ALL default false in production:
 *     ATLAS_DEV_EFFICIENT_PLAN_ENABLED    -> POST /ai/interactions/atlas-dev/plan (zero-provider, safe first)
 *     ATLAS_DEV_EFFICIENT_RUN_ENABLED     -> POST /ai/interactions/atlas-dev/run  (only after Plan green in prod)
 *     ATLAS_DEV_EFFICIENT_DESKTOP_ENABLED -> Desktop surface consumption
 *     Off => controller answers 503 ATLAS_DEV_PLAN_DISABLED / ATLAS_DEV_RUN_DISABLED, no side effect.
 *   §26.4 APP_KEY fail-closed — must be `base64:...` decoding to >= 32 bytes
 *     (canonical `php artisan key:generate`). Absent or short => Plan AND Run
 *     fail closed with 500 ATLAS_DEV_KEY_MISSING. No public fallback, no
 *     "default key"; operator must rotate before enabling runs.
 *   §26.4 Stream — GET .../runs/{run_id}/stream is snapshot-replay-then-close:
 *     deterministic replay of phase: events, then receipt: (if any), then a
 *     single final stream_closed:, then the connection closes. No long-lived
 *     keepalive in this phase. stream_closed is the canonical end; intermediate
 *     state / resume comes from GET /runs/{run_id} (REST = source of truth).
 *   §26.4 Path redaction — HttpResponseRedactor rewrites any string starting
 *     with the absolute workspace: workspace_label = basename(workspace) (no
 *     /Users/...), workspace_hash stays as the provider-safe identifier,
 *     persisted artifacts surface as receipts/<run_id>/<file>. A response body
 *     must NEVER contain "/Users/" nor "storage/atlas-dev/receipts/".
 *   §26.4 Follow-ups — the HMAC-keyed confirmation pin covers exactly
 *     {task_contract_hash, compact_sdd_hash} today; it does NOT yet cover
 *     envelope_hash or prompt_projection_hash. Mismatch => 422
 *     TASK_CONTRACT_HASH_MISMATCH / COMPACT_SDD_TAMPERED. A single full-bundle
 *     hash, live async stream, and App/public-API surfaces are documented
 *     follow-ups NOT yet delivered.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-04.md
 */
final class AtlasDevEfficientProgrammingFlowV1Part04Service
{
    /** Stable decision kind this decider emits. */
    public const DECISION_KIND = 'atlas_dev.efficient_programming_flow.v1.part_04';

    /** §26.4 — minimum decoded length (bytes) for a valid APP_KEY. */
    public const APP_KEY_MIN_DECODED_BYTES = 32;

    /** §26.4 — fail-closed error code when the key is missing/short. */
    public const KEY_MISSING_CODE = 'ATLAS_DEV_KEY_MISSING';
    public const KEY_MISSING_STATUS = 500;

    /** §26.4 — required final marker of a run stream; nothing follows it. */
    public const STREAM_CLOSED_MARKER = 'stream_closed';

    /** §26.4 — REST endpoint that is the source of truth for run state. */
    public const REST_SOURCE_OF_TRUTH = 'GET /runs/{run_id}';

    /** §26.4 — substrings that must NEVER appear in an HTTP response body. */
    public const FORBIDDEN_BODY_SUBSTRINGS = ['/Users/', 'storage/atlas-dev/receipts/'];

    /** §26.4 — the only bundle hashes the HMAC confirmation pin covers TODAY. */
    public const HMAC_PINNED_HASHES = ['task_contract_hash', 'compact_sdd_hash'];

    /** §26.4 — bundle hashes documented as NOT yet covered by the HMAC pin. */
    public const HMAC_UNPINNED_HASHES = ['envelope_hash', 'prompt_projection_hash'];

    /**
     * §26.4 — the three Atlas Dev Efficient flags, in unlock order, each with
     * its endpoint, default value (false in production), the 503 code emitted
     * when off, and its documented unlock precondition.
     *
     * @return array<string, array{
     *   flag:string, endpoint:string, default:bool, disabled_status:int,
     *   disabled_code:string|null, unlock_order:int, precondition:string
     * }>
     */
    public function flagMatrix(): array
    {
        return [
            'plan' => [
                'flag' => 'ATLAS_DEV_EFFICIENT_PLAN_ENABLED',
                'endpoint' => 'POST /ai/interactions/atlas-dev/plan',
                'default' => false,
                'disabled_status' => 503,
                'disabled_code' => 'ATLAS_DEV_PLAN_DISABLED',
                'unlock_order' => 1,
                'precondition' => 'zero-provider, seguro ligar primeiro',
            ],
            'run' => [
                'flag' => 'ATLAS_DEV_EFFICIENT_RUN_ENABLED',
                'endpoint' => 'POST /ai/interactions/atlas-dev/run',
                'default' => false,
                'disabled_status' => 503,
                'disabled_code' => 'ATLAS_DEV_RUN_DISABLED',
                'unlock_order' => 2,
                'precondition' => 'so apos Plan verde em prod',
            ],
            'desktop' => [
                'flag' => 'ATLAS_DEV_EFFICIENT_DESKTOP_ENABLED',
                'endpoint' => 'surface:desktop',
                'default' => false,
                'disabled_status' => 503,
                'disabled_code' => null,
                'unlock_order' => 3,
                'precondition' => 'consumo via surface Desktop',
            ],
        ];
    }

    /**
     * §26.4 — resolve whether a gated action is allowed for the given flag
     * value. Off => 503 with the documented code and no side effect.
     *
     * @return array{
     *   surface:string, flag:string, enabled:bool, allowed:bool,
     *   http_status:int, code:string|null, side_effect:bool, reason:string
     * }
     */
    public function resolveFlagGate(string $surface, bool $enabled): array
    {
        $matrix = $this->flagMatrix();
        $known = array_key_exists($surface, $matrix);
        $entry = $matrix[$surface] ?? [
            'flag' => 'unknown',
            'disabled_code' => null,
        ];

        if (! $known) {
            return [
                'surface' => 'unknown',
                'flag' => 'unknown',
                'enabled' => $enabled,
                'allowed' => false,
                'http_status' => 503,
                'code' => null,
                'side_effect' => false,
                'reason' => 'unknown_surface_fail_closed',
            ];
        }

        if ($enabled) {
            return [
                'surface' => $surface,
                'flag' => $entry['flag'],
                'enabled' => true,
                'allowed' => true,
                'http_status' => 200,
                'code' => null,
                'side_effect' => false,
                'reason' => 'flag_enabled',
            ];
        }

        return [
            'surface' => $surface,
            'flag' => $entry['flag'],
            'enabled' => false,
            'allowed' => false,
            'http_status' => 503,
            'code' => $entry['disabled_code'],
            'side_effect' => false,
            'reason' => 'flag_disabled_503_no_side_effect',
        ];
    }

    /**
     * §26.4 — is the documented unlock ORDER respected? Run may only be enabled
     * if Plan is already enabled (Plan green first). Desktop consumes a surface
     * and should not be enabled while Plan is off.
     *
     * @return array{
     *   plan:bool, run:bool, desktop:bool, order_respected:bool,
     *   violations:list<string>
     * }
     */
    public function unlockOrderRespected(bool $planEnabled, bool $runEnabled, bool $desktopEnabled): array
    {
        $violations = [];

        if ($runEnabled && ! $planEnabled) {
            $violations[] = 'run_enabled_before_plan';
        }
        if ($desktopEnabled && ! $planEnabled) {
            $violations[] = 'desktop_enabled_before_plan';
        }

        return [
            'plan' => $planEnabled,
            'run' => $runEnabled,
            'desktop' => $desktopEnabled,
            'order_respected' => $violations === [],
            'violations' => $violations,
        ];
    }

    /**
     * §26.4 — validate an APP_KEY string against the fail-closed contract.
     * Must be `base64:<payload>` decoding to >= 32 bytes. Anything else makes
     * Plan AND Run fail closed with 500 ATLAS_DEV_KEY_MISSING.
     *
     * @return array{
     *   valid:bool, decoded_bytes:int, min_required:int, fail_closed:bool,
     *   http_status:int|null, code:string|null, plan_blocked:bool,
     *   run_blocked:bool, reason:string
     * }
     */
    public function validateAppKey(?string $appKey): array
    {
        $reason = 'key_ok';
        $decodedBytes = 0;
        $valid = false;

        if ($appKey === null || $appKey === '') {
            $reason = 'key_absent';
        } elseif (! str_starts_with($appKey, 'base64:')) {
            $reason = 'key_not_base64_prefixed';
        } else {
            $payload = substr($appKey, strlen('base64:'));
            $decoded = base64_decode($payload, true);

            if ($decoded === false) {
                $reason = 'key_base64_undecodable';
            } else {
                $decodedBytes = strlen($decoded);
                if ($decodedBytes < self::APP_KEY_MIN_DECODED_BYTES) {
                    $reason = 'key_too_short';
                } else {
                    $valid = true;
                }
            }
        }

        $failClosed = ! $valid;

        return [
            'valid' => $valid,
            'decoded_bytes' => $decodedBytes,
            'min_required' => self::APP_KEY_MIN_DECODED_BYTES,
            'fail_closed' => $failClosed,
            'http_status' => $failClosed ? self::KEY_MISSING_STATUS : null,
            'code' => $failClosed ? self::KEY_MISSING_CODE : null,
            'plan_blocked' => $failClosed,
            'run_blocked' => $failClosed,
            'reason' => $reason,
        ];
    }

    /**
     * §26.4 — validate a run-stream event sequence as snapshot-replay-then-close.
     * Required shape: zero or more `phase:` events, then optional `receipt:`
     * events, then exactly ONE final `stream_closed:` which must be last and
     * nothing may follow it. No long-lived keepalive is part of this phase.
     *
     * @param  list<string>  $events  ordered event kinds, e.g. ['phase:plan','receipt:run','stream_closed']
     * @return array{
     *   valid:bool, terminal_marker:string, ends_with_close:bool,
     *   close_count:int, events_after_close:int, source_of_truth:string,
     *   keepalive_expected:bool, reason:string
     * }
     */
    public function validateStreamSequence(array $events): array
    {
        $events = array_values($events);
        $closeCount = 0;
        $eventsAfterClose = 0;
        $seenClose = false;

        foreach ($events as $event) {
            $isClose = $event === self::STREAM_CLOSED_MARKER
                || str_starts_with($event, self::STREAM_CLOSED_MARKER . ':');

            if ($seenClose) {
                $eventsAfterClose++;
            }
            if ($isClose) {
                $closeCount++;
                $seenClose = true;
            }
        }

        $last = $events === [] ? null : $events[count($events) - 1];
        $endsWithClose = $last === self::STREAM_CLOSED_MARKER
            || ($last !== null && str_starts_with($last, self::STREAM_CLOSED_MARKER . ':'));

        $valid = $closeCount === 1 && $endsWithClose && $eventsAfterClose === 0;

        if ($events === []) {
            $reason = 'empty_stream_missing_close';
        } elseif ($closeCount === 0) {
            $reason = 'missing_stream_closed_marker';
        } elseif ($closeCount > 1) {
            $reason = 'multiple_stream_closed_markers';
        } elseif (! $endsWithClose || $eventsAfterClose > 0) {
            $reason = 'events_after_stream_closed';
        } else {
            $reason = 'valid_snapshot_replay_then_close';
        }

        return [
            'valid' => $valid,
            'terminal_marker' => self::STREAM_CLOSED_MARKER,
            'ends_with_close' => $endsWithClose,
            'close_count' => $closeCount,
            'events_after_close' => $eventsAfterClose,
            'source_of_truth' => self::REST_SOURCE_OF_TRUTH,
            'keepalive_expected' => false,
            'reason' => $reason,
        ];
    }

    /**
     * §26.4 — redact a single workspace-absolute path into its provider-safe
     * projection: workspace_label = basename, the supplied workspace_hash is
     * kept, and a persisted artifact relative path surfaces as
     * receipts/<run_id>/<file>.
     *
     * @return array{
     *   input:string, workspace_label:string, workspace_hash:string,
     *   persisted_ref:string|null, leaks_absolute:bool
     * }
     */
    public function redactWorkspacePath(
        string $absolutePath,
        string $workspace,
        string $workspaceHash,
        string $runId,
        ?string $artifactFile = null
    ): array {
        $label = basename(rtrim($workspace, '/'));

        $persistedRef = null;
        if ($artifactFile !== null && $artifactFile !== '') {
            $persistedRef = 'receipts/' . $runId . '/' . ltrim($artifactFile, '/');
        }

        // After projection, the emitted projection (label/hash/ref) must not
        // re-expose the absolute prefix.
        $leaks = $this->bodyLeaksAbsolutePath($label)
            || $this->bodyLeaksAbsolutePath($workspaceHash)
            || ($persistedRef !== null && $this->bodyLeaksAbsolutePath($persistedRef));

        return [
            'input' => $absolutePath,
            'workspace_label' => $label,
            'workspace_hash' => $workspaceHash,
            'persisted_ref' => $persistedRef,
            'leaks_absolute' => $leaks,
        ];
    }

    /**
     * §26.4 — does a candidate HTTP response body violate the path-redaction
     * smoke (contains "/Users/" or "storage/atlas-dev/receipts/")?
     */
    public function bodyLeaksAbsolutePath(string $body): bool
    {
        foreach (self::FORBIDDEN_BODY_SUBSTRINGS as $needle) {
            if (str_contains($body, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * §26.4 — assert the redaction smoke over a response body, returning the
     * verdict and which forbidden substrings (if any) were found.
     *
     * @return array{passes:bool,found:list<string>,checked:list<string>,reason:string}
     */
    public function redactionSmoke(string $body): array
    {
        $found = [];
        foreach (self::FORBIDDEN_BODY_SUBSTRINGS as $needle) {
            if (str_contains($body, $needle)) {
                $found[] = $needle;
            }
        }

        return [
            'passes' => $found === [],
            'found' => $found,
            'checked' => self::FORBIDDEN_BODY_SUBSTRINGS,
            'reason' => $found === [] ? 'no_absolute_path_leak' : 'absolute_path_leak_detected',
        ];
    }

    /**
     * §26.4 Follow-ups — given the bundle hash that differs between Plan-time and
     * Run-time, decide whether the HMAC confirmation pin actually catches the
     * tamper today. Only task_contract_hash and compact_sdd_hash are pinned;
     * envelope_hash / prompt_projection_hash are documented as NOT yet covered.
     *
     * @return array{
     *   hash_name:string, pinned:bool, mismatch:bool, blocked:bool,
     *   http_status:int|null, code:string|null, reason:string
     * }
     */
    public function confirmationPinVerdict(string $hashName, bool $mismatch): array
    {
        $pinned = in_array($hashName, self::HMAC_PINNED_HASHES, true);

        $codeByHash = [
            'task_contract_hash' => 'TASK_CONTRACT_HASH_MISMATCH',
            'compact_sdd_hash' => 'COMPACT_SDD_TAMPERED',
        ];

        if (! $mismatch) {
            return [
                'hash_name' => $hashName,
                'pinned' => $pinned,
                'mismatch' => false,
                'blocked' => false,
                'http_status' => null,
                'code' => null,
                'reason' => 'no_mismatch',
            ];
        }

        if (! $pinned) {
            // Documented gap: a mismatch here is NOT caught by the current pin.
            return [
                'hash_name' => $hashName,
                'pinned' => false,
                'mismatch' => true,
                'blocked' => false,
                'http_status' => null,
                'code' => null,
                'reason' => 'mismatch_on_unpinned_hash_not_yet_covered',
            ];
        }

        return [
            'hash_name' => $hashName,
            'pinned' => true,
            'mismatch' => true,
            'blocked' => true,
            'http_status' => 422,
            'code' => $codeByHash[$hashName] ?? 'TASK_CONTRACT_HASH_MISMATCH',
            'reason' => 'pinned_hash_mismatch_blocks_run',
        ];
    }

    /**
     * §26.4 — the explicit follow-ups documented as NOT yet delivered. A caller
     * can use this to refuse to claim a capability the doc says is future work.
     *
     * @return array{
     *   delivered:list<string>, not_delivered:list<string>,
     *   hmac_pinned_hashes:list<string>, hmac_unpinned_hashes:list<string>
     * }
     */
    public function deliveryState(): array
    {
        return [
            'delivered' => [
                'zero_provider_plan',
                'app_key_fail_closed',
                'snapshot_replay_then_close_stream',
                'rest_source_of_truth_poll',
                'http_path_redaction',
                'hmac_pin_task_contract_and_compact_sdd',
                'desktop_surface_canonical',
            ],
            'not_delivered' => [
                'public_full_bundle_hash_for_operator',
                'live_async_stream',
                'app_surface',
                'public_api_surface',
                'hmac_pin_over_envelope_and_prompt_projection',
            ],
            'hmac_pinned_hashes' => self::HMAC_PINNED_HASHES,
            'hmac_unpinned_hashes' => self::HMAC_UNPINNED_HASHES,
        ];
    }

    /**
     * §27 Regra Final — the canonical operating maxims. "Atlas Dev wins by
     * SYSTEM, not by model." Returned as an ordered, stable list so a probe can
     * assert the rule survived intact.
     *
     * @return array{wins_by:string, maxims:list<string>, canonical_contract:bool}
     */
    public function finalRule(): array
    {
        return [
            'wins_by' => 'system_not_model',
            'maxims' => [
                'menos contexto inutil',
                'mais arquivo certo',
                'menos escopo errado',
                'mais teste focado',
                'mais evidence',
                'repair pequeno',
                'falha honesta',
                'forge quando precisa',
            ],
            'canonical_contract' => true,
        ];
    }

    /**
     * Stable manifest of the slice this decider governs (for the command/probe).
     *
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return [
            'decision_kind' => self::DECISION_KIND,
            'doc' => 'docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-04.md',
            'sections' => [
                '26_4_operational_summary',
                '26_4_flags',
                '26_4_app_key_fail_closed',
                '26_4_stream_snapshot_replay_then_close',
                '26_4_path_redaction',
                '26_4_followups_not_delivered',
                '27_final_rule',
            ],
            'app_key_min_decoded_bytes' => self::APP_KEY_MIN_DECODED_BYTES,
            'key_missing_code' => self::KEY_MISSING_CODE,
            'stream_closed_marker' => self::STREAM_CLOSED_MARKER,
            'rest_source_of_truth' => self::REST_SOURCE_OF_TRUTH,
            'forbidden_body_substrings' => self::FORBIDDEN_BODY_SUBSTRINGS,
            'hmac_pinned_hashes' => self::HMAC_PINNED_HASHES,
            'hmac_unpinned_hashes' => self::HMAC_UNPINNED_HASHES,
        ];
    }
}
