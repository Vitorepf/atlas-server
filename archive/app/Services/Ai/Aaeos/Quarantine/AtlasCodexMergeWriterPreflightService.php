<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Codex Merge Post-Execution Action Persistence Writer Preflight — pure,
 * deterministic, read-only blocker-reporting logic.
 *
 * This surface answers exactly one human question (doc "Human Meaning"):
 *
 *     "What still blocks a real append-only persistence writer?"
 *
 * and explicitly does NOT answer:
 *
 *     "Should the writer be released or invoked now?"
 *
 * It is the read-only PREFLIGHT for a FUTURE append-only writer that may persist
 * signed post-execution Codex merge action receipts. It is distinct from the
 * writer CONTRACT (which declares the pre-write proof gate). The preflight only
 * inspects payload readiness and lists the standing writer blockers; it
 * implements no writer, writes no ledger event, persists no receipt, accepts or
 * validates no signature, records no decision, approves nothing, merges nothing
 * and dispatches nothing.
 *
 * Documented contract this code enforces (not just documents):
 *
 *   Boundary (doc "Boundary") -> boundary(): the eight keys must stay false —
 *     execution_allowed, ledger_write_allowed, dispatch_allowed,
 *     approval_granted, merge_allowed, signature_valid, receipt_persisted,
 *     receipt_signed. The preflight embeds them verbatim and assertBoundaryHeld()
 *     proves they never flipped.
 *
 *   Readiness Rule (doc "Readiness Rule") -> preflight(): the preflight is READY
 *     only when the append-only event payload template is ready. Ready status is
 *     `..._writer_preflight_ready`; otherwise `..._writer_preflight_blocked`.
 *     "Ready means blocker reporting is complete. It does not authorize a writer."
 *     => `writer_authorized` is ALWAYS false, even when ready.
 *
 *   Writer Capabilities Required (doc "Writer Capabilities Required") ->
 *     requiredCapabilities() / blockers(): the seven behaviours a future writer
 *     must PROVE. Until a capability is proven present it is reported as a
 *     standing blocker. The preflight performs none of them.
 *
 *   Future Release Conditions (doc "Future Release Conditions") ->
 *     releaseConditions(): the six conditions a future writer needs before it can
 *     even be CONSIDERED. Each unmet condition is reported as a standing blocker.
 *     `writer_may_be_considered` becomes true ONLY when every release condition is
 *     met AND every required capability is proven AND the boundary held — and even
 *     then it is consideration, never authorization.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-preflight.md
 */
final class AtlasCodexMergeWriterPreflightService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA_WRITER_PREFLIGHT = 'atlas.self_construction_codex_review_merge_post_execution_action_persistence_writer_preflight.v1';

    /** Surface label (closed set of one). */
    public const SURFACE_WRITER_PREFLIGHT = 'persistence_writer_preflight';

    /** Documented status when blocker reporting is not yet complete. */
    public const STATUS_BLOCKED = 'merge_post_execution_action_signed_receipt_persistence_writer_preflight_blocked';

    /** Documented status when blocker reporting is complete (NOT authorization). */
    public const STATUS_READY = 'merge_post_execution_action_signed_receipt_persistence_writer_preflight_ready';

    /**
     * The eight boundary keys (doc "Boundary"): the preflight must keep all false.
     *
     * @var list<string>
     */
    public const BOUNDARY_KEYS = [
        'execution_allowed',
        'ledger_write_allowed',
        'dispatch_allowed',
        'approval_granted',
        'merge_allowed',
        'signature_valid',
        'receipt_persisted',
        'receipt_signed',
    ];

    /**
     * Writer capabilities a FUTURE writer must prove (doc "Writer Capabilities
     * Required"). Order preserved. Until proven, each is a standing blocker; the
     * preflight performs none of them.
     *
     * @var list<string>
     */
    public const REQUIRED_CAPABILITIES = [
        'append_only_ledger_write_only_behavior',
        'payload_hash_recomputation',
        'source_hash_match_enforcement',
        'hot_scope_recheck_enforcement',
        'human_confirmation_hash_enforcement',
        'no_merge_authority',
        'no_dispatch_authority',
    ];

    /**
     * Future release conditions (doc "Future Release Conditions"). A future
     * writer can only be CONSIDERED when every one of these is met. Order
     * preserved; each maps to a boolean signal (missing => unmet, fail-closed).
     *
     * @var list<string>
     */
    public const RELEASE_CONDITIONS = [
        'writer_surface_implemented',
        'writer_surface_separately_authorized',
        'all_payload_fields_non_null',
        'all_writer_contract_capabilities_present',
        'all_blocking_conditions_resolved',
        'writer_preflight_hash_bound_to_writer_contract',
    ];

    /**
     * The single readiness gate (doc "Readiness Rule"): the preflight is ready
     * only when the append-only event payload template is ready.
     */
    public const READINESS_GATE = 'append_only_event_payload_template_ready';

    /**
     * The eight documented boundary keys, all forced false.
     *
     * @return array<string,false>
     */
    public function boundary(): array
    {
        $out = [];
        foreach (self::BOUNDARY_KEYS as $key) {
            $out[$key] = false;
        }

        return $out;
    }

    /**
     * Required-capabilities surface. Declares the seven behaviours a future
     * writer must prove and reports which are still unproven (standing blockers).
     * Implements none of them.
     *
     * @param array<string,mixed> $proven boolean "capability proven present"
     *   signals keyed by REQUIRED_CAPABILITIES entries. Missing/non-true =>
     *   unproven => blocker.
     * @return array{
     *   surface:string, schema:string,
     *   required_capabilities:list<string>, count:int,
     *   proven:array<string,bool>, unproven_capabilities:list<string>,
     *   boundary:array<string,false>, implements_writer:false
     * }
     */
    public function requiredCapabilities(array $proven = []): array
    {
        $state = [];
        $unproven = [];
        foreach (self::REQUIRED_CAPABILITIES as $capability) {
            $ok = $this->signal($proven, $capability);
            $state[$capability] = $ok;
            if (! $ok) {
                $unproven[] = $capability;
            }
        }

        return [
            'surface' => self::SURFACE_WRITER_PREFLIGHT,
            'schema' => self::SCHEMA_WRITER_PREFLIGHT,
            'required_capabilities' => self::REQUIRED_CAPABILITIES,
            'count' => count(self::REQUIRED_CAPABILITIES),
            'proven' => $state,
            'unproven_capabilities' => $unproven,
            'boundary' => $this->boundary(),
            'implements_writer' => false,
        ];
    }

    /**
     * Future-release-conditions surface. Computes, fail-closed, which of the six
     * documented conditions are met and which still block. `all_conditions_met`
     * is true ONLY when every documented condition is met.
     *
     * @param array<string,mixed> $input boolean condition signals keyed by
     *   RELEASE_CONDITIONS entries. Missing/non-true => unmet.
     * @return array{
     *   surface:string, schema:string,
     *   release_conditions:list<string>,
     *   conditions:array<string,bool>, unmet_conditions:list<string>,
     *   all_conditions_met:bool,
     *   boundary:array<string,false>
     * }
     */
    public function releaseConditions(array $input = []): array
    {
        $conditions = [];
        $unmet = [];
        foreach (self::RELEASE_CONDITIONS as $condition) {
            $met = $this->signal($input, $condition);
            $conditions[$condition] = $met;
            if (! $met) {
                $unmet[] = $condition;
            }
        }

        return [
            'surface' => self::SURFACE_WRITER_PREFLIGHT,
            'schema' => self::SCHEMA_WRITER_PREFLIGHT,
            'release_conditions' => self::RELEASE_CONDITIONS,
            'conditions' => $conditions,
            'unmet_conditions' => $unmet,
            'all_conditions_met' => $unmet === [],
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Primary entrypoint: run the read-only preflight.
     *
     * Decides READY vs BLOCKED purely on the documented readiness rule (the
     * append-only event payload template is ready), then enumerates EVERY standing
     * blocker — the readiness gate itself when unmet, each unproven required
     * capability, and each unmet future-release condition.
     *
     * `writer_may_be_considered` is true ONLY when payload-template is ready AND
     * every required capability is proven AND every release condition is met AND
     * the boundary held across all surfaces. Per the doc's "Human Meaning", even
     * a fully-unblocked preflight is NOT authorization: `writer_authorized` stays
     * false unconditionally.
     *
     * @param array{
     *   payload_template_ready?:mixed,
     *   capabilities_proven?:array<string,mixed>,
     *   release_conditions?:array<string,mixed>
     * } $input
     * @return array{
     *   schema:string, surface:string, status:string,
     *   payload_template_ready:bool, ready:bool,
     *   required_capabilities:array<string,mixed>,
     *   release_conditions:array<string,mixed>,
     *   standing_blockers:list<string>, blocker_count:int,
     *   boundary_held:bool, boundary_violations:list<string>,
     *   writer_may_be_considered:bool, writer_authorized:false,
     *   human_question:string, does_not_answer:string
     * }
     */
    public function preflight(array $input = []): array
    {
        $payloadReady = ($input['payload_template_ready'] ?? null) === true;

        $capabilities = $this->requiredCapabilities($input['capabilities_proven'] ?? []);
        $conditions = $this->releaseConditions($input['release_conditions'] ?? []);

        $violations = $this->assertBoundaryHeld([$capabilities, $conditions]);

        $blockers = [];
        if (! $payloadReady) {
            // Readiness Rule: not ready until the payload template is ready.
            $blockers[] = 'readiness_gate_unmet:'.self::READINESS_GATE;
        }
        foreach ($capabilities['unproven_capabilities'] as $capability) {
            $blockers[] = 'capability_unproven:'.$capability;
        }
        foreach ($conditions['unmet_conditions'] as $condition) {
            $blockers[] = 'release_condition_unmet:'.$condition;
        }
        foreach ($violations as $violation) {
            $blockers[] = 'boundary_violation:'.$violation;
        }

        // "Ready means blocker reporting is complete" — gated solely on the
        // payload template per the Readiness Rule. It does NOT authorize a writer.
        $ready = $payloadReady;

        // Consideration (never authorization) needs the full picture clean.
        $mayBeConsidered = $payloadReady
            && $capabilities['unproven_capabilities'] === []
            && $conditions['unmet_conditions'] === []
            && $violations === [];

        return [
            'schema' => self::SCHEMA_WRITER_PREFLIGHT,
            'surface' => self::SURFACE_WRITER_PREFLIGHT,
            'status' => $ready ? self::STATUS_READY : self::STATUS_BLOCKED,
            'payload_template_ready' => $payloadReady,
            'ready' => $ready,
            'required_capabilities' => $capabilities,
            'release_conditions' => $conditions,
            'standing_blockers' => $blockers,
            'blocker_count' => count($blockers),
            'boundary_held' => $violations === [],
            'boundary_violations' => $violations,
            'writer_may_be_considered' => $mayBeConsidered,
            // Doc "Human Meaning": the preflight never answers "release/invoke now".
            'writer_authorized' => false,
            'human_question' => 'What still blocks a real append-only persistence writer?',
            'does_not_answer' => 'Should the writer be released or invoked now?',
        ];
    }

    /**
     * Standing-blockers convenience: just the flat list a future writer must
     * clear, derived from the same preflight logic.
     *
     * @param array{
     *   payload_template_ready?:mixed,
     *   capabilities_proven?:array<string,mixed>,
     *   release_conditions?:array<string,mixed>
     * } $input
     * @return list<string>
     */
    public function blockers(array $input = []): array
    {
        return $this->preflight($input)['standing_blockers'];
    }

    /**
     * Prove that no surface ever flipped a boundary key to a truthy value.
     * Returns the list of "surface.key" violations (empty = boundary intact).
     *
     * @param list<array<string,mixed>> $surfaces
     * @return list<string>
     */
    public function assertBoundaryHeld(array $surfaces): array
    {
        $violations = [];
        foreach ($surfaces as $surface) {
            $label = is_string($surface['surface'] ?? null) ? $surface['surface'] : 'unknown';
            $boundary = is_array($surface['boundary'] ?? null) ? $surface['boundary'] : [];
            foreach (self::BOUNDARY_KEYS as $key) {
                // Missing key OR truthy value both count as a breach.
                if (! array_key_exists($key, $boundary) || $boundary[$key] !== false) {
                    $violations[] = $label.'.'.$key;
                }
            }
        }

        return $violations;
    }

    /**
     * Read a boolean signal. Strict: only an exact boolean true clears it;
     * missing keys and any non-true value count as unset (fail-closed).
     *
     * @param array<string,mixed> $input
     */
    private function signal(array $input, string $key): bool
    {
        return ($input[$key] ?? null) === true;
    }
}
