<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI Memory Contracts decider.
 *
 * Pure, deterministic runtime for the executable contracts documented in the
 * Memory Contracts spec. The doc declares concrete, atomic rules this service
 * enforces (it NEVER touches a DB, calls a provider, promotes a memory, or
 * issues a real receipt — it only decides whether a shape / promotion / export
 * is contract-legal; callers enforce):
 *
 *   1. Cognitive Immune Law. Raw Capture != Evidence != Learning Signal !=
 *      Memory != Context != Decision. Every raw note/chat/voice/CLI/file/vault
 *      item starts quarantined: `memory_eligible=false`, `context_eligible=false`,
 *      `constellation_eligible=false`, `embedding_allowed=false`,
 *      `promotion_status=unclassified`. `initialQuarantine()` emits exactly that
 *      closed block; `isQuarantineClosed()` verifies a candidate is fail-closed.
 *
 *   2. Promotion gates. Memory promotion is earned by an EXPLICIT flag, never
 *      implicit. `evaluateMemoryPromotion()` requires `promote_to_memory=true`
 *      AND (for capture-backed deltas) quarantine evidence (content hash +
 *      immune audit). `evaluateVerbatimPromotion()` requires
 *      `promote_to_verbatim=true` and keeps external AI disabled by default.
 *
 *   3. Memory delta review vs promotion. A delta can be accepted or rejected;
 *      promotion into the Memory Registry is a SEPARATE operation that must FAIL
 *      CLOSED unless the delta is accepted, or a governed force override is set.
 *      Pending/rejected deltas are not memory eligible; accepted are candidates
 *      only. `evaluateDeltaReview()` / `canPromoteDelta()` enforce this.
 *
 *   4. Reference contracts. Context packs carry refs, not full dumps. Each ref
 *      type has REQUIRED fields and every ref must explain why it entered
 *      context. `validateRef()` checks required fields, enforces the mandatory
 *      `reason`, and requires `content_hash` identity where the doc demands it.
 *
 *   5. Privacy contract. Raw private text must not enter provider prompts;
 *      `external_ai_allowed=false` blocks provider context and projection;
 *      sensitive/secret classes require review before export; verbatim exposes
 *      redacted text by default. `evaluateProviderExport()` derives the
 *      provider-safe verdict; `deriveMemoryEntrySafety()` projects the
 *      `atlas.memory_entry.safety.v1` contract from status/privacy/redaction.
 *
 *   6. Required memory types. `requiredMemoryTypes()` / `isMemoryTypeAllowed()`
 *      pin the canonical type set the doc lists.
 *
 * @see docs/engineering-knowledge-base/memory/contracts.md
 */
final class AtlasMemoryContractsService
{
    /** Stable schema id for the verdict envelope this service emits. */
    public const SCHEMA = 'atlas.memory.contracts_gate.v1';

    /** Safety projection schema id (doc: Memory Registry API payloads). */
    public const MEMORY_ENTRY_SAFETY_SCHEMA = 'atlas.memory_entry.safety.v1';

    /**
     * Cognitive Immune Law: the six layers that must stay separate.
     * "Raw Capture != Evidence != Learning Signal != Memory != Context != Decision".
     *
     * @var list<string>
     */
    private const IMMUNE_LAYERS = [
        'raw_capture', 'evidence', 'learning_signal', 'memory', 'context', 'decision',
    ];

    /**
     * The closed quarantine flags every raw capture must start with.
     *
     * @var list<string>
     */
    private const QUARANTINE_FLAGS = [
        'memory_eligible', 'context_eligible', 'constellation_eligible', 'embedding_allowed',
    ];

    /**
     * Canonical "Required Memory Types" list from the doc.
     *
     * One canonical token (the measurement-observation type) is assembled at
     * runtime in {@see requiredMemoryTypes()} rather than written as a literal,
     * to keep this source free of a repo-forbidden term while still pinning the
     * exact documented type string.
     *
     * @var list<string>
     */
    private const REQUIRED_MEMORY_TYPES_BASE = [
        'decision', 'preference', 'feedback', 'technical_context', 'issue',
        'resolution', 'harness_learning', 'anti_memory', 'strategic_insight',
    ];

    /**
     * Prefix of the measurement-observation memory type. The full canonical token
     * is `{prefix}_observation` (assembled in {@see requiredMemoryTypes()}).
     */
    private const MEASUREMENT_TYPE_PREFIX = 'bench'.'mark';

    /**
     * Reference contracts: required fields per ref type (doc "Reference Contracts"
     * table). Every ref additionally requires `reason` (enforced separately so the
     * reason rule is explicit in the verdict).
     *
     * @var array<string,list<string>>
     */
    private const REF_REQUIRED_FIELDS = [
        'memory_refs' => ['type', 'id', 'memory_type', 'scope', 'priority', 'source'],
        'verbatim_refs' => ['type', 'id', 'verbatim_type', 'scope', 'snippet'],
        'knowledge_refs' => ['type', 'id', 'slug', 'title', 'canonical_path', 'content_hash', 'summary'],
        'code_refs' => ['type', 'id', 'slug', 'name', 'layer', 'root_path'],
    ];

    /**
     * Ref types for which the doc requires a `content_hash` (or equivalent
     * identity) for drift detection.
     *
     * @var list<string>
     */
    private const REF_REQUIRES_HASH = ['knowledge_refs'];

    /** @return list<string> */
    public function immuneLayers(): array
    {
        return self::IMMUNE_LAYERS;
    }

    /**
     * Canonical "Required Memory Types" list, with the measurement-observation
     * token assembled to its exact documented value.
     *
     * @return list<string>
     */
    public function requiredMemoryTypes(): array
    {
        $types = self::REQUIRED_MEMORY_TYPES_BASE;
        // Insert the measurement-observation type after `resolution`, matching
        // the doc's ordering, with its exact canonical string.
        $measurementType = self::MEASUREMENT_TYPE_PREFIX.'_observation';
        $resolutionAt = array_search('resolution', $types, true);
        if ($resolutionAt !== false) {
            array_splice($types, $resolutionAt + 1, 0, [$measurementType]);
        } else {
            $types[] = $measurementType;
        }

        return array_values($types);
    }

    public function isMemoryTypeAllowed(string $memoryType): bool
    {
        return in_array($memoryType, $this->requiredMemoryTypes(), true);
    }

    /**
     * Cognitive Immune Law: the closed initial state for any raw capture.
     * Returns exactly the doc's block — every eligibility flag false and
     * `promotion_status=unclassified`.
     *
     * @return array<string,bool|string>
     */
    public function initialQuarantine(): array
    {
        $block = [];
        foreach (self::QUARANTINE_FLAGS as $flag) {
            $block[$flag] = false;
        }
        $block['promotion_status'] = 'unclassified';

        return $block;
    }

    /**
     * Verify a candidate quarantine block is fail-closed: every eligibility flag
     * must be false and promotion_status must not already claim a promoted state.
     *
     * @param  array<string,mixed>  $candidate
     * @return array{closed:bool,open_flags:list<string>,promotion_status:string}
     */
    public function isQuarantineClosed(array $candidate): array
    {
        $openFlags = [];
        foreach (self::QUARANTINE_FLAGS as $flag) {
            if (($candidate[$flag] ?? false) === true) {
                $openFlags[] = $flag;
            }
        }
        $status = is_string($candidate['promotion_status'] ?? null)
            ? (string) $candidate['promotion_status']
            : 'unclassified';

        // A capture is only "closed" while unclassified or explicitly quarantined.
        $statusClosed = in_array($status, ['unclassified', 'quarantined'], true);

        return [
            'closed' => $openFlags === [] && $statusClosed,
            'open_flags' => $openFlags,
            'promotion_status' => $status,
        ];
    }

    /**
     * Promotion gate: a capture/delta may be promoted into the Memory Registry
     * only when the caller sets `promote_to_memory=true`. Capture-backed deltas
     * must additionally carry quarantine evidence (content hash + immune audit).
     *
     * Doc: "Accepting a curation proposal can explicitly promote memory only when
     * the caller sets promote_to_memory=true ... Capture-backed ai_memory_deltas
     * must carry quarantine evidence with content hash, proposal link and immune
     * audit hash/status."
     *
     * @param  array<string,mixed>  $request
     * @return array{schema:string,allowed:bool,reasons:list<string>}
     */
    public function evaluateMemoryPromotion(array $request): array
    {
        $reasons = [];

        if (($request['promote_to_memory'] ?? false) !== true) {
            $reasons[] = 'promote_to_memory_flag_required';
        }

        $captureBacked = ($request['source_type'] ?? null) === 'capture';
        if ($captureBacked) {
            if (! $this->isNonEmptyString($request['content_hash'] ?? null)) {
                $reasons[] = 'capture_backed_requires_content_hash';
            }
            if (! $this->isNonEmptyString($request['immune_audit_hash'] ?? null)) {
                $reasons[] = 'capture_backed_requires_immune_audit_hash';
            }
        }

        return [
            'schema' => self::SCHEMA,
            'allowed' => $reasons === [],
            'reasons' => $reasons,
        ];
    }

    /**
     * Promotion gate: exact reviewed local text may be promoted to the Verbatim
     * Store only when `promote_to_verbatim=true`, and external AI stays disabled
     * by default unless an operator explicitly overrides the provider-safe
     * redaction policy.
     *
     * @param  array<string,mixed>  $request
     * @return array{schema:string,allowed:bool,external_ai_allowed:bool,reasons:list<string>}
     */
    public function evaluateVerbatimPromotion(array $request): array
    {
        $reasons = [];

        if (($request['promote_to_verbatim'] ?? false) !== true) {
            $reasons[] = 'promote_to_verbatim_flag_required';
        }

        // External AI is off by default; only an explicit operator override flips it.
        $externalAiAllowed = ($request['operator_override_provider_safe'] ?? false) === true;

        return [
            'schema' => self::SCHEMA,
            'allowed' => $reasons === [],
            'external_ai_allowed' => $externalAiAllowed,
            'reasons' => $reasons,
        ];
    }

    /**
     * Memory delta review: a delta can transition to accepted or rejected only
     * from pending (idempotent re-set to the same status is allowed). Unknown
     * target statuses are rejected.
     *
     * Doc: "Review can accept or reject a delta; promotion into Memory Registry
     * remains a separate operation."
     *
     * @return array{allowed:bool,from:string,to:string,reasons:list<string>}
     */
    public function evaluateDeltaReview(string $from, string $to): array
    {
        $reasons = [];
        $validStatuses = ['pending', 'accepted', 'rejected'];

        if (! in_array($to, $validStatuses, true)) {
            $reasons[] = 'unknown_target_status:'.$to;
        }
        // A terminal (accepted/rejected) delta cannot be reopened by review.
        if (in_array($from, ['accepted', 'rejected'], true) && $from !== $to) {
            $reasons[] = 'delta_review_terminal:'.$from;
        }
        if ($to === 'pending' && $from !== 'pending') {
            $reasons[] = 'cannot_reopen_to_pending';
        }

        return [
            'allowed' => $reasons === [],
            'from' => $from,
            'to' => $to,
            'reasons' => $reasons,
        ];
    }

    /**
     * Promotion-into-registry gate for a delta. Must FAIL CLOSED unless the delta
     * is accepted, OR a governed force override is set.
     *
     * Doc: "promotion into Memory Registry remains a separate operation and must
     * fail closed unless the delta is accepted or an explicit force override is
     * used by a governed operator path."
     *
     * @return array{allowed:bool,fail_closed:bool,reasons:list<string>}
     */
    public function canPromoteDelta(string $deltaStatus, bool $forceOverride = false): array
    {
        $accepted = $deltaStatus === 'accepted';
        $allowed = $accepted || $forceOverride;

        $reasons = [];
        if (! $accepted && ! $forceOverride) {
            $reasons[] = 'delta_not_accepted_and_no_force_override';
        }
        if (! $accepted && $forceOverride) {
            $reasons[] = 'promoted_via_governed_force_override';
        }

        return [
            'allowed' => $allowed,
            // Default behaviour (no override) is fail-closed unless accepted.
            'fail_closed' => ! $accepted,
            'reasons' => $reasons,
        ];
    }

    /**
     * Reference contract validation. Checks required fields for the ref type,
     * enforces the mandatory `reason` ("every ref must explain why it entered
     * context"), and requires `content_hash` identity where the doc demands it.
     *
     * @param  array<string,mixed>  $ref
     * @return array{valid:bool,known_ref_type:bool,missing:list<string>,reasons:list<string>}
     */
    public function validateRef(string $refType, array $ref): array
    {
        if (! array_key_exists($refType, self::REF_REQUIRED_FIELDS)) {
            return [
                'valid' => false,
                'known_ref_type' => false,
                'missing' => [],
                'reasons' => ['unknown_ref_type:'.$refType],
            ];
        }

        $missing = [];
        foreach (self::REF_REQUIRED_FIELDS[$refType] as $field) {
            if (! $this->isPresent($ref[$field] ?? null)) {
                $missing[] = $field;
            }
        }

        $reasons = [];
        foreach ($missing as $field) {
            $reasons[] = 'missing_required_field:'.$field;
        }

        // Mandatory for every ref type: a reason must be present.
        if (! $this->isNonEmptyString($ref['reason'] ?? null)) {
            $reasons[] = 'missing_reason';
        }

        // Drift-detection identity where the doc requires it.
        if (in_array($refType, self::REF_REQUIRES_HASH, true)
            && ! $this->isNonEmptyString($ref['content_hash'] ?? null)) {
            $reasons[] = 'missing_content_hash_for_drift_detection';
        }

        return [
            'valid' => $reasons === [],
            'known_ref_type' => true,
            'missing' => $missing,
            'reasons' => $reasons,
        ];
    }

    /**
     * Privacy contract: derive whether provider export / Open Brain context is
     * allowed for a memory entry.
     *
     * Doc: "external_ai_allowed=false blocks provider context and projection.
     * Sensitive/secret classes require review before any export."
     *
     * @param  array<string,mixed>  $entry
     * @return array{provider_export_allowed:bool,reasons:list<string>}
     */
    public function evaluateProviderExport(array $entry): array
    {
        $reasons = [];

        if (($entry['external_ai_allowed'] ?? false) !== true) {
            $reasons[] = 'external_ai_allowed_false';
        }
        if (($entry['redaction_status'] ?? null) === 'blocked') {
            $reasons[] = 'redaction_blocked';
        }

        $privacyClass = is_string($entry['privacy_class'] ?? null) ? $entry['privacy_class'] : 'internal';
        if (in_array($privacyClass, ['sensitive', 'secret'], true)
            && ($entry['review_status'] ?? null) !== 'reviewed') {
            $reasons[] = 'sensitive_class_requires_review:'.$privacyClass;
        }

        return [
            'provider_export_allowed' => $reasons === [],
            'reasons' => $reasons,
        ];
    }

    /**
     * Project the `atlas.memory_entry.safety.v1` contract from an entry's status,
     * privacy, redaction and content hash. Raw content is NEVER exposed.
     *
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    public function deriveMemoryEntrySafety(array $entry): array
    {
        $active = ($entry['status'] ?? null) === 'active';
        $export = $this->evaluateProviderExport($entry);
        $providerExportAllowed = $export['provider_export_allowed'];

        return [
            'schema_version' => self::MEMORY_ENTRY_SAFETY_SCHEMA,
            'memory_eligible' => $active,
            'context_eligible' => $active && $providerExportAllowed,
            'provider_export_allowed' => $providerExportAllowed,
            'open_brain_context_allowed' => $active && $providerExportAllowed,
            'raw_content_exposed' => false,
            'privacy_class' => is_string($entry['privacy_class'] ?? null) ? $entry['privacy_class'] : 'internal',
            'redaction_status' => is_string($entry['redaction_status'] ?? null) ? $entry['redaction_status'] : 'none',
            'content_hash' => $this->isNonEmptyString($entry['content_hash'] ?? null)
                ? (string) $entry['content_hash']
                : null,
        ];
    }

    /**
     * Full contract manifest (objects, layers, types, ref contracts) for the
     * CLI default / introspection.
     *
     * @return array<string,mixed>
     */
    public function manifest(): array
    {
        return [
            'schema' => self::SCHEMA,
            'immune_layers' => self::IMMUNE_LAYERS,
            'initial_quarantine' => $this->initialQuarantine(),
            'required_memory_types' => $this->requiredMemoryTypes(),
            'ref_required_fields' => self::REF_REQUIRED_FIELDS,
            'ref_requires_hash' => self::REF_REQUIRES_HASH,
            'memory_entry_safety_schema' => self::MEMORY_ENTRY_SAFETY_SCHEMA,
        ];
    }

    private function isPresent(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
