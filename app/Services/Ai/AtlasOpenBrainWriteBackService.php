<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiLearningProposal;
use App\Services\Ai\Compounding\AtlasLearningProposalService;
use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use App\Support\AtlasSecurity;
use InvalidArgumentException;
use Throwable;

/**
 * AOBG N1.F2 — the governed WRITE-BACK door: the external AI feeds the brain.
 *
 * F1 is the PUSH surface (brain → external AI, read-only, provider-safe). F2 is the
 * other direction: an external session (Claude Code / Codex / Cursor, in ANY project)
 * reports what it did and proposes what it learned, so the brain compounds. The input
 * is UNTRUSTED and is treated as HOSTILE — it can NEVER write canonical memory
 * directly, NEVER auto-promote, NEVER exceed provider-safety. Every accepted write
 * lands as a brain node (mission/evidence) or a `proposed` learning awaiting human
 * review, and every write writes an append-only audit receipt.
 *
 * This builds NO new write engine — it ASSEMBLES the two proven, gated writers under
 * one hostile-input boundary:
 *
 *   1. record_outcome  → {@see AtlasRealityGraphIngestionService::recordMissionOutcome()}
 *      (the existing AURG mission/evidence node writer: ids/hashes/labels only,
 *      provider-safe by construction, idempotent via content hash, fail-open, and a
 *      BRANCH ref — NEVER a merge to main).
 *   2. propose_learning → {@see AtlasLearningProposalService::propose()}
 *      (the canonical compounding admission pipeline: runs the
 *      {@see \App\Services\Ai\Compounding\AtlasCaptureQualityGate} that rejects noise,
 *      dedups by content, and ALWAYS materialises status='proposed' — `apply`/
 *      `auto_apply` are rejected by that service, so this surface CANNOT auto-promote).
 *
 * HOSTILE-INPUT FLOOR (applied BEFORE delegating, so a malformed/oversized/sensitive
 * payload is rejected honestly without touching either store):
 *   - provider-safety: any declared privacy class other than `normal` (private,
 *     sensitive, secret, cyber, …) is REJECTED — untrusted input may not stamp itself
 *     provider-safe, and the gateway never lets non-normal content cross. The label
 *     fields written to the brain are additionally redacted via {@see AtlasSecurity}.
 *   - size: oversized payloads (over the configured caps) are rejected, not truncated
 *     silently — a runaway external session cannot flood the brain.
 *   - shape: required fields are enforced; missing/blank → honest validation error.
 *
 * NEVER throws to the caller: a store fault degrades to a `recorded:false` /
 * `ok:false` reason (fail-open), so a brain outage never breaks the external session.
 * Cost: local DB only, zero provider spend.
 */
class AtlasOpenBrainWriteBackService
{
    public const SCHEMA = 'atlas.aobg.write_back.v1';

    public const ACTION_RECORD_OUTCOME = 'record_outcome';

    public const ACTION_PROPOSE_LEARNING = 'propose_learning';

    /** Rejection reasons surfaced to the (untrusted) caller — stable, auditable. */
    public const REJECT_SENSITIVE = 'rejected_provider_unsafe';

    public const REJECT_OVERSIZED = 'rejected_oversized';

    public const REJECT_INVALID = 'rejected_invalid_input';

    public const REJECT_QUALITY = 'rejected_by_quality_gate';

    /**
     * Privacy classes that untrusted external input may NEVER push to the brain.
     * `normal` is the ONLY accepted class; everything else (incl. anything outside the
     * known set) is treated as blocked — fail-closed on privacy.
     */
    private const BLOCKED_DECLARED_CLASSES = ['private', 'sensitive', 'secret', 'cyber', 'classified', 'pii'];

    public function __construct(
        private readonly AtlasRealityGraphIngestionService $ingestion,
        private readonly AtlasLearningProposalService $proposals,
        private readonly CodeGraphWorkspaceIdentity $workspaceIdentity,
    ) {}

    /**
     * An external session records WHAT IT DID (files touched, mission/task ref, result)
     * → a provider-safe brain mission+evidence node via the existing recorder. NEVER a
     * merge. Idempotent (re-recording the same outcome collapses to the same nodes).
     *
     * @param  array<string,mixed>  $input  untrusted:
     *   - id        (required) external mission/task ref (the node identity).
     *   - request   (required) what the session was asked to do (label, redacted).
     *   - files     array<string> paths the session touched (bounded).
     *   - branch    optional branch ref (never a merge).
     *   - provider  optional provider/agent label.
     *   - delivered optional bool (default false).
     *   - result    optional {status|ok} — the test/measure result.
     *   - memory_refs array<string> cited existing memory node ids/source ids.
     *   - privacy_class optional self-declared class — MUST be `normal` or omitted.
     *   - workspace / cwd optional — resolved + recorded for audit (never leaked).
     * @return array<string,mixed>
     */
    public function recordOutcome(array $input): array
    {
        try {
            $guard = $this->providerSafetyGuard($input, self::ACTION_RECORD_OUTCOME);
            if ($guard !== null) {
                return $guard; // already a rejection envelope + receipt
            }

            $id = $this->string($input['id'] ?? null);
            $request = $this->string($input['request'] ?? null);
            if ($id === null || $request === null) {
                return $this->reject(self::ACTION_RECORD_OUTCOME, self::REJECT_INVALID, 'id_and_request_required', $input);
            }

            $caps = $this->caps();
            // Size floor: cap field lengths + the touched-file count BEFORE writing, so a
            // runaway external session cannot flood the brain. Oversized = rejected, not
            // silently truncated (an honest "no" beats a corrupted half-write).
            if (mb_strlen($request) > $caps['max_request_chars'] || mb_strlen($id) > $caps['max_id_chars']) {
                return $this->reject(self::ACTION_RECORD_OUTCOME, self::REJECT_OVERSIZED, 'request_or_id_too_long', $input);
            }
            $files = $this->boundedStringList($input['files'] ?? [], $caps['max_files']);
            if ($files === null) {
                return $this->reject(self::ACTION_RECORD_OUTCOME, self::REJECT_OVERSIZED, 'too_many_touched_files', $input);
            }
            $memoryRefs = $this->boundedStringList($input['memory_refs'] ?? [], $caps['max_memory_refs']) ?? [];

            $workspaceId = $this->resolveWorkspaceId($input);
            $result = $this->normalizeResult($input['result'] ?? []);

            // Delegate to the EXISTING, gated recorder. It is idempotent + fail-open and
            // writes ONLY provider-safe ids/hashes/labels + a BRANCH (never a merge). The
            // request label is redacted there too; we redact here as well (defence in depth
            // — untrusted input must not carry secrets into a label even by accident).
            $recorded = $this->ingestion->recordMissionOutcome([
                'id' => $id,
                'request' => AtlasSecurity::redactString($request),
                'branch' => $this->string($input['branch'] ?? null) ?? '',
                'delivered' => (bool) ($input['delivered'] ?? false),
                'provider' => $this->string($input['provider'] ?? null),
                'receipt' => $this->string($input['receipt'] ?? null),
                'files' => $files,
                'measure' => $result,
                'memory_refs' => $memoryRefs,
            ]);

            $ok = (bool) ($recorded['recorded'] ?? false);
            $envelope = [
                'ok' => $ok,
                'action' => self::ACTION_RECORD_OUTCOME,
                'schema' => self::SCHEMA,
                'workspace' => $workspaceId,
                // NEVER a merge — make the contract explicit in the response.
                'merged' => false,
                'mission_node' => $recorded['mission_node'] ?? null,
                'evidence_node' => $recorded['evidence_node'] ?? null,
                'edges' => (int) ($recorded['edges'] ?? 0),
                'reason' => $ok ? 'recorded' : (string) ($recorded['reason'] ?? 'store_unavailable'),
            ];

            $this->writeReceipt(self::ACTION_RECORD_OUTCOME, $envelope + [
                'id' => $id,
                'files' => count($files),
                'memory_refs' => count($memoryRefs),
            ]);

            return $envelope;
        } catch (Throwable $e) {
            // Fail-open: a store/DB fault never breaks the external session.
            return $this->failOpen(self::ACTION_RECORD_OUTCOME, $e);
        }
    }

    /**
     * An external session PROPOSES a learning/decision → the canonical capture pipeline
     * (quality gate + provider-safety) → lands as a PROPOSAL requiring human review.
     * NEVER auto-promotes, NEVER mutates canonical memory.
     *
     * @param  array<string,mixed>  $input  untrusted:
     *   - kind         (required) one of {@see AtlasLearningProposalService::ALLOWED_KINDS}.
     *   - summary      (required) the proposed learning, one sentence.
     *   - evidence_refs(required) array<string> file:line / id / hash citations.
     *   - scope        optional scope (default global).
     *   - current_state / proposed_state optional structured detail.
     *   - flow_id      optional audit ref.
     *   - privacy_class optional self-declared class — MUST be `normal` or omitted.
     *   - workspace / cwd optional — resolved + recorded for audit.
     * @return array<string,mixed>
     */
    public function proposeLearning(array $input): array
    {
        try {
            $guard = $this->providerSafetyGuard($input, self::ACTION_PROPOSE_LEARNING);
            if ($guard !== null) {
                return $guard;
            }

            $kind = $this->string($input['kind'] ?? null);
            $summary = $this->string($input['summary'] ?? null);
            $evidenceRefs = $this->boundedStringList($input['evidence_refs'] ?? [], $this->caps()['max_evidence_refs']);

            if ($kind === null || ! in_array($kind, AtlasLearningProposalService::ALLOWED_KINDS, true)) {
                return $this->reject(self::ACTION_PROPOSE_LEARNING, self::REJECT_INVALID, 'invalid_or_missing_kind', $input);
            }
            if ($summary === null) {
                return $this->reject(self::ACTION_PROPOSE_LEARNING, self::REJECT_INVALID, 'summary_required', $input);
            }
            if ($evidenceRefs === null) {
                return $this->reject(self::ACTION_PROPOSE_LEARNING, self::REJECT_OVERSIZED, 'too_many_evidence_refs', $input);
            }
            if ($evidenceRefs === []) {
                return $this->reject(self::ACTION_PROPOSE_LEARNING, self::REJECT_INVALID, 'evidence_refs_required', $input);
            }

            $caps = $this->caps();
            if (mb_strlen($summary) > $caps['max_summary_chars']) {
                return $this->reject(self::ACTION_PROPOSE_LEARNING, self::REJECT_OVERSIZED, 'summary_too_long', $input);
            }

            $workspaceId = $this->resolveWorkspaceId($input);

            // Delegate to the canonical admission pipeline. It runs the capture quality
            // gate (rejects noise + dedups) and ALWAYS lands status='proposed' — it throws
            // InvalidArgumentException on `apply`/`auto_apply` (we never pass them) and on
            // bad shape (caught below as an honest validation reject). NEVER auto-promotes.
            try {
                $proposal = $this->proposals->propose([
                    'kind' => $kind,
                    'summary' => AtlasSecurity::redactString($summary),
                    'scope' => $this->string($input['scope'] ?? null) ?? 'global',
                    'flow_id' => $this->string($input['flow_id'] ?? null),
                    'current_state' => $this->boundedArray($input['current_state'] ?? [], $caps['max_state_keys']),
                    'proposed_state' => $this->boundedArray($input['proposed_state'] ?? [], $caps['max_state_keys']),
                    'evidence_refs' => $evidenceRefs,
                    'payload' => [
                        'source' => 'aobg_write_back',
                        'untrusted_external_input' => true,
                        'provider' => $this->string($input['provider'] ?? null),
                        'workspace' => $workspaceId,
                    ],
                ]);
            } catch (InvalidArgumentException $e) {
                return $this->reject(self::ACTION_PROPOSE_LEARNING, self::REJECT_INVALID, $e->getMessage(), $input);
            }

            return $this->proposalEnvelope($proposal, $workspaceId, $input);
        } catch (Throwable $e) {
            return $this->failOpen(self::ACTION_PROPOSE_LEARNING, $e);
        }
    }

    // ------------------------------------------------------------------
    // hostile-input floor
    // ------------------------------------------------------------------

    /**
     * Provider-safety guard: untrusted input may NEVER push non-`normal` content to the
     * brain, and may NEVER stamp itself provider-safe over a blocked class. A declared
     * privacy_class other than `normal` (or an external_ai_allowed=false flag) → reject.
     * Returns a rejection envelope when blocked, or null when the payload may proceed.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>|null
     */
    private function providerSafetyGuard(array $input, string $action): ?array
    {
        $declared = $this->string($input['privacy_class'] ?? null);
        if ($declared !== null) {
            $declared = strtolower($declared);
            if ($declared !== 'normal' || in_array($declared, self::BLOCKED_DECLARED_CLASSES, true)) {
                return $this->reject($action, self::REJECT_SENSITIVE, 'declared_privacy_class_not_provider_safe', $input);
            }
        }

        // An explicit external_ai_allowed=false is an honest signal that this content is
        // NOT for a provider — honour it (the gateway only writes provider-safe nodes).
        if (array_key_exists('external_ai_allowed', $input)
            && filter_var($input['external_ai_allowed'], FILTER_VALIDATE_BOOL) === false) {
            return $this->reject($action, self::REJECT_SENSITIVE, 'external_ai_allowed_false', $input);
        }

        // Also reject when the configured sensitivity blocklist names a class the input
        // declared (defence in depth against config-extended classes like 'cyber').
        $blocked = array_map('strtolower', array_values(array_filter(array_merge(
            (array) config('atlas.privacy.block_external_ai_for_sensitivity', []),
            ['secret'],
        ), 'is_string')));
        if ($declared !== null && in_array($declared, $blocked, true)) {
            return $this->reject($action, self::REJECT_SENSITIVE, 'declared_privacy_class_blocked_by_config', $input);
        }

        return null;
    }

    // ------------------------------------------------------------------
    // envelopes + receipts
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function proposalEnvelope(AiLearningProposal $proposal, string $workspaceId, array $input): array
    {
        $status = (string) ($proposal->status ?? 'proposed');
        // The capture gate's 'enforce' mode returns a TRANSIENT model with
        // status='rejected_by_quality_gate' that was NOT persisted — surface that as an
        // honest quality rejection (no id, not pending review) so the caller can tell a
        // dropped-as-noise proposal from a real one.
        $gateRejected = $status === 'rejected_by_quality_gate';
        $persisted = ! $gateRejected && $proposal->exists;

        $envelope = [
            'ok' => ! $gateRejected,
            'action' => self::ACTION_PROPOSE_LEARNING,
            'schema' => self::SCHEMA,
            'workspace' => $workspaceId,
            // The two hard guarantees, made explicit in the response.
            'applied' => false,
            'auto_promoted' => false,
            'requires_human_review' => true,
            'proposal_id' => $persisted ? (string) $proposal->id : null,
            'status' => $gateRejected ? self::REJECT_QUALITY : 'pending_review',
            'kind' => (string) ($proposal->kind ?? ''),
            'quality' => (array) data_get($proposal->payload, 'quality', []),
            'reason' => $gateRejected
                ? (string) data_get($proposal->payload, 'quality.reason', 'low_quality')
                : 'pending_review',
        ];

        $this->writeReceipt(self::ACTION_PROPOSE_LEARNING, $envelope);

        return $envelope;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function reject(string $action, string $status, string $reason, array $input): array
    {
        $envelope = [
            'ok' => false,
            'action' => $action,
            'schema' => self::SCHEMA,
            'status' => $status,
            'reason' => $reason,
            'applied' => false,
            'auto_promoted' => false,
            'merged' => false,
        ];

        $this->writeReceipt($action, $envelope + [
            'rejected' => true,
            // identity-only audit of the rejected payload (never the raw content).
            'id' => $this->string($input['id'] ?? null),
            'kind' => $this->string($input['kind'] ?? null),
        ]);

        return $envelope;
    }

    private function failOpen(string $action, Throwable $e): array
    {
        $envelope = [
            'ok' => false,
            'action' => $action,
            'schema' => self::SCHEMA,
            'status' => 'fail_open',
            'reason' => 'store_unavailable',
            'applied' => false,
            'auto_promoted' => false,
            'merged' => false,
            'exception' => class_basename($e),
        ];

        $this->writeReceipt($action, $envelope);

        return $envelope;
    }

    /**
     * Append-only audit receipt for EVERY write attempt (accepted or rejected), so the
     * write-back surface is fully auditable. Best-effort — never affects the decision.
     *
     * @param  array<string,mixed>  $entry
     */
    private function writeReceipt(string $action, array $entry): void
    {
        try {
            $base = function_exists('storage_path')
                ? storage_path('atlas/governance')
                : sys_get_temp_dir().'/atlas/governance';
            AppendOnlyJsonlStore::appendUsingFilePutContents(
                $base.DIRECTORY_SEPARATOR.'aobg_write_back.jsonl',
                array_merge(['schema' => self::SCHEMA, 'action' => $action, 'recorded_at' => now()->toJSON()], $entry),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                FILE_APPEND,
            );
        } catch (Throwable) {
            // audit logging is best-effort; the write decision never depends on it.
        }
    }

    // ------------------------------------------------------------------
    // caps + helpers
    // ------------------------------------------------------------------

    /**
     * @return array{max_request_chars:int,max_id_chars:int,max_summary_chars:int,max_files:int,max_memory_refs:int,max_evidence_refs:int,max_state_keys:int}
     */
    private function caps(): array
    {
        return [
            'max_request_chars' => (int) config('atlas.aobg.write_back.max_request_chars', 2000),
            'max_id_chars' => (int) config('atlas.aobg.write_back.max_id_chars', 256),
            'max_summary_chars' => (int) config('atlas.aobg.write_back.max_summary_chars', 1000),
            'max_files' => (int) config('atlas.aobg.write_back.max_files', 50),
            'max_memory_refs' => (int) config('atlas.aobg.write_back.max_memory_refs', 25),
            'max_evidence_refs' => (int) config('atlas.aobg.write_back.max_evidence_refs', 25),
            'max_state_keys' => (int) config('atlas.aobg.write_back.max_state_keys', 50),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function resolveWorkspaceId(array $input): string
    {
        try {
            $explicit = $this->string($input['workspace'] ?? null);
            if ($explicit !== null) {
                // "path OR id": resolve an existing path to its id; pass a stable id
                // through verbatim (audit-scopes to the SAME workspace). `cwd` is a path.
                return $this->workspaceIdentity->resolveWorkspaceOrId($explicit);
            }
            $cwd = $this->string($input['cwd'] ?? null);
            if ($cwd !== null) {
                return $this->workspaceIdentity->resolve($cwd);
            }

            return $this->workspaceIdentity->default();
        } catch (Throwable) {
            try {
                return $this->workspaceIdentity->default();
            } catch (Throwable) {
                return 'atlas-server';
            }
        }
    }

    /**
     * @return array{status?:string,ok?:bool}
     */
    private function normalizeResult(mixed $result): array
    {
        if (is_string($result)) {
            $result = trim($result);

            return $result !== '' ? ['status' => mb_substr($result, 0, 60)] : [];
        }
        if (! is_array($result)) {
            return [];
        }
        $out = [];
        if (isset($result['status']) && is_scalar($result['status'])) {
            $out['status'] = mb_substr((string) $result['status'], 0, 60);
        }
        if (array_key_exists('ok', $result)) {
            $out['ok'] = (bool) $result['ok'];
        }

        return $out;
    }

    /**
     * A string list capped at $max. Returns null when the input EXCEEDS the cap (so the
     * caller can reject-as-oversized instead of silently dropping entries).
     *
     * @return array<int,string>|null
     */
    private function boundedStringList(mixed $value, int $max): ?array
    {
        $values = is_array($value) ? $value : ($value === null ? [] : [$value]);
        $clean = [];
        foreach ($values as $item) {
            $s = $this->string($item);
            if ($s !== null) {
                $clean[] = $s;
            }
        }
        $clean = array_values(array_unique($clean));
        if (count($clean) > max(0, $max)) {
            return null;
        }

        return $clean;
    }

    /**
     * A bounded associative payload — caps the key count (untrusted structured state
     * cannot be unbounded). Over-cap is trimmed (state is detail, not the load-bearing
     * field; summary+evidence are validated separately).
     *
     * @return array<int|string,mixed>
     */
    private function boundedArray(mixed $value, int $maxKeys): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_slice($value, 0, max(0, $maxKeys), true);
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
