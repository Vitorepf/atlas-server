<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Mission Control Cockpit — operator gesture + decision-receipt gate.
 *
 * Pure, deterministic implementation of the cockpit's mutation contract. The
 * doc defines a single operator cabine over 9+ AAEOS departments where the
 * read-side is a view (`atlas.mission_control.view.v1`) and the WRITE-side is
 * a closed set of operator gestures, each of which MUST crystallise into a
 * signed Operator Decision Receipt (`atlas.operator.decision_receipt.v1`).
 *
 * This service answers the one controlled question the cockpit asks before any
 * gesture takes effect: "is this operator gesture authorized, and does it carry
 * a valid receipt?" — returning the single verdict `authorize` or `block` with
 * a machine reason. It NEVER executes the gesture's effect (freeze runtime,
 * promote ladder, fire replay), never calls a provider and never touches the
 * database; the runtime executes the effect only after this gate authorizes.
 *
 * Contract enforced (from "Gestures canonicas", "Operator Decision Receipt
 * schema", "Regras para IA", "Riscos"):
 *   - Gestures are a closed set of 7 (table "Gestures canonicas").
 *   - "Toda gesture exige Operator Decision Receipt assinado; agente nunca
 *     emite gesture." -> agent actor blocks; missing/unsigned receipt blocks.
 *   - Receipt minimum fields per `atlas.operator.decision_receipt.v1`; an
 *     incomplete receipt blocks (governance theater, same posture as the
 *     decision_receipt guard).
 *   - "Operador aprova milestone sem ver evidence_hashes" is a failure mode;
 *     schema obliges `evidence_hashes_seen` -> empty evidence blocks for
 *     gestures whose pre-req is evidence (approve_milestone, force_replay).
 *   - Per-gesture pre-reqs from the table (e.g. veto_dept requires rationale;
 *     promote_ladder requires `autonomy.next_eligible` green).
 *   - "promote_ladder ... sim, dual signature se L4+": promoting INTO L4 or
 *     higher requires two distinct operator signatures.
 *   - Mobile surface is a subset: it may only carry approve/veto/pause/
 *     sign_intent_receipt; replay/handoff/promote from Mobile block.
 *   - "Operator Decision Receipt assinado nunca e revogado; correcao gera novo
 *     receipt." -> a gesture that tries to revoke an existing receipt blocks.
 *
 * @see docs/engineering-knowledge-base/atlas-mission-control-cockpit-spec.md
 */
final class AtlasMissionControlCockpitSurfaceService
{
    /** Stable schema id for the gate verdict this service emits. */
    public const SCHEMA = 'atlas.mission_control.gesture_gate.v1';

    /** Canonical view + receipt schema ids the doc names. */
    public const VIEW_SCHEMA = 'atlas.mission_control.view.v1';
    public const RECEIPT_SCHEMA = 'atlas.operator.decision_receipt.v1';

    /** Closed verdict set. */
    public const VERDICT_AUTHORIZE = 'authorize';
    public const VERDICT_BLOCK = 'block';

    /** The 7 canonical gestures (table "Gestures canonicas"). All require a receipt. */
    public const GESTURE_APPROVE_MILESTONE = 'approve_milestone';
    public const GESTURE_VETO_DEPT = 'veto_dept';
    public const GESTURE_PAUSE_OBRA = 'pause_obra';
    public const GESTURE_PROMOTE_LADDER = 'promote_ladder';
    public const GESTURE_FORCE_REPLAY = 'force_replay';
    public const GESTURE_FORCE_HANDOFF = 'force_handoff';
    public const GESTURE_SIGN_INTENT_RECEIPT = 'sign_intent_receipt';

    /** @var list<string> */
    public const GESTURES = [
        self::GESTURE_APPROVE_MILESTONE,
        self::GESTURE_VETO_DEPT,
        self::GESTURE_PAUSE_OBRA,
        self::GESTURE_PROMOTE_LADDER,
        self::GESTURE_FORCE_REPLAY,
        self::GESTURE_FORCE_HANDOFF,
        self::GESTURE_SIGN_INTENT_RECEIPT,
    ];

    /**
     * Subset of gestures the Mobile (iOS) surface is allowed to carry, per the
     * "Surface adapters" table: zonas 1..5 + 11 with gestures
     * approve/veto/pause/sign — explicitly NOT replay, NOT force_handoff,
     * NOT promote_ladder.
     *
     * @var list<string>
     */
    public const MOBILE_ALLOWED_GESTURES = [
        self::GESTURE_APPROVE_MILESTONE,
        self::GESTURE_VETO_DEPT,
        self::GESTURE_PAUSE_OBRA,
        self::GESTURE_SIGN_INTENT_RECEIPT,
    ];

    /** Surfaces that may execute the full gesture set. */
    public const SURFACE_DESKTOP = 'desktop';
    public const SURFACE_MOBILE = 'mobile';
    public const SURFACE_CLI = 'cli';

    /**
     * Minimum Operator Decision Receipt fields per `atlas.operator.decision_receipt.v1`.
     * `constraints_added` and `rollback_window_seconds` are present in the schema
     * but allowed to be empty/zero, so they are NOT required-non-empty here.
     *
     * @var list<string>
     */
    private const RECEIPT_REQUIRED_FIELDS = [
        'schema',
        'receipt_id',
        'operator_id',
        'session_id',
        'gesture',
        'target',
        'context_hash',
        'evidence_hashes_seen',
        'rationale',
        'operator_signature',
        'signed_at',
    ];

    /**
     * Gestures whose documented pre-req is "evidence_hashes assinaveis": the
     * receipt's `evidence_hashes_seen` must be non-empty or the gate blocks
     * (failure mode: "Operador aprova milestone sem ver evidence_hashes").
     *
     * @var list<string>
     */
    private const GESTURES_REQUIRING_EVIDENCE = [
        self::GESTURE_APPROVE_MILESTONE,
        self::GESTURE_FORCE_REPLAY,
    ];

    /**
     * The canonical target `kind` each gesture acts on (table column "target").
     *
     * @var array<string,string>
     */
    private const GESTURE_TARGET_KIND = [
        self::GESTURE_APPROVE_MILESTONE => 'obra',
        self::GESTURE_VETO_DEPT => 'department',
        self::GESTURE_PAUSE_OBRA => 'obra',
        self::GESTURE_PROMOTE_LADDER => 'ladder',
        self::GESTURE_FORCE_REPLAY => 'obra',
        self::GESTURE_FORCE_HANDOFF => 'intent',
        self::GESTURE_SIGN_INTENT_RECEIPT => 'intent',
    ];

    /**
     * Authorize (or block) a single operator gesture before its effect runs.
     *
     * @param  string  $gesture  one of self::GESTURES.
     * @param  array<string,mixed>|null  $receipt
     *         The Operator Decision Receipt the surface produced for this gesture.
     *         null / [] means "no receipt" — the strongest block, since every
     *         gesture requires one.
     * @param  array<string,mixed>  $context
     *         actor_kind          : 'operator'|'agent' (default 'operator'). Agents
     *                               never emit gestures.
     *         surface             : 'desktop'|'mobile'|'cli' (default 'desktop').
     *         autonomy_next_eligible : 'L<n>'|null — the view's `autonomy.next_eligible`
     *                               (pre-req for promote_ladder; must be green/non-null).
     *         promote_to_level    : 'L<n>' the ladder level being promoted INTO
     *                               (for promote_ladder; L4+ requires dual signature).
     *         milestone_ready     : bool — pre-req for approve_milestone.
     *         obra_active         : bool — pre-req for pause_obra / force_replay.
     *         evidence_intact     : bool — pre-req for force_replay.
     *         handoff_pack_ready  : bool — pre-req for force_handoff.
     *         pending_receipt     : bool — pre-req for sign_intent_receipt.
     *         revokes_receipt_id  : string|null — a signed receipt this gesture
     *                               attempts to revoke (always blocks).
     *
     * @return array<string,mixed> the verdict + audit envelope
     */
    public function authorizeGesture(string $gesture, ?array $receipt, array $context = []): array
    {
        $gesture = trim($gesture);

        // Rule 0 — gesture must be in the closed canonical set.
        if (! in_array($gesture, self::GESTURES, true)) {
            return $this->verdict(self::VERDICT_BLOCK, 'unknown_gesture', $gesture, [
                'known_gestures' => self::GESTURES,
            ]);
        }

        // Rule 1 — "agente nunca emite gesture": only the human operator acts.
        $actorKind = $this->stringOrNull($context['actor_kind'] ?? null) ?? 'operator';
        if ($actorKind !== 'operator') {
            return $this->verdict(self::VERDICT_BLOCK, 'agent_cannot_emit_gesture', $gesture, [
                'actor_kind' => $actorKind,
            ]);
        }

        // Rule 2 — a signed receipt that this gesture tries to revoke: never.
        // "Operator Decision Receipt assinado nunca e revogado; correcao gera novo receipt."
        $revokes = $this->stringOrNull($context['revokes_receipt_id'] ?? null);
        if ($revokes !== null) {
            return $this->verdict(self::VERDICT_BLOCK, 'receipt_revocation_forbidden', $gesture, [
                'revokes_receipt_id' => $revokes,
            ]);
        }

        // Rule 3 — surface capability: Mobile may only carry its subset.
        $surface = $this->stringOrNull($context['surface'] ?? null) ?? self::SURFACE_DESKTOP;
        if ($surface === self::SURFACE_MOBILE && ! in_array($gesture, self::MOBILE_ALLOWED_GESTURES, true)) {
            return $this->verdict(self::VERDICT_BLOCK, 'gesture_not_available_on_surface', $gesture, [
                'surface' => $surface,
                'surface_allows' => self::MOBILE_ALLOWED_GESTURES,
            ]);
        }

        // Rule 4 — every gesture requires a receipt.
        if ($receipt === null || $receipt === []) {
            return $this->verdict(self::VERDICT_BLOCK, 'receipt_required', $gesture, [
                'missing_fields' => self::RECEIPT_REQUIRED_FIELDS,
            ]);
        }

        // Rule 5 — receipt completeness against the canonical schema.
        $missing = $this->missingReceiptFields($receipt);
        if ($missing !== []) {
            return $this->verdict(self::VERDICT_BLOCK, 'receipt_incomplete', $gesture, [
                'missing_fields' => $missing,
            ]);
        }

        // Rule 6 — receipt schema must be the operator receipt, never the agent v2.
        if (($receipt['schema'] ?? null) !== self::RECEIPT_SCHEMA) {
            return $this->verdict(self::VERDICT_BLOCK, 'wrong_receipt_schema', $gesture, [
                'expected' => self::RECEIPT_SCHEMA,
                'got' => $this->stringOrNull($receipt['schema'] ?? null),
            ]);
        }

        // Rule 7 — the receipt's gesture must match the gesture being authorized.
        if ($this->stringOrNull($receipt['gesture'] ?? null) !== $gesture) {
            return $this->verdict(self::VERDICT_BLOCK, 'receipt_gesture_mismatch', $gesture, [
                'receipt_gesture' => $this->stringOrNull($receipt['gesture'] ?? null),
            ]);
        }

        // Rule 8 — target kind must be the canonical kind for this gesture.
        $targetKind = is_array($receipt['target'] ?? null)
            ? $this->stringOrNull($receipt['target']['kind'] ?? null)
            : null;
        if ($targetKind !== self::GESTURE_TARGET_KIND[$gesture]) {
            return $this->verdict(self::VERDICT_BLOCK, 'wrong_target_kind', $gesture, [
                'expected_kind' => self::GESTURE_TARGET_KIND[$gesture],
                'got_kind' => $targetKind,
            ]);
        }

        // Rule 9 — evidence pre-req: gestures that approve/replay must show hashes.
        if (in_array($gesture, self::GESTURES_REQUIRING_EVIDENCE, true)
            && $this->hashList($receipt['evidence_hashes_seen'] ?? []) === []) {
            return $this->verdict(self::VERDICT_BLOCK, 'evidence_hashes_required', $gesture, [
                'gesture_requires_evidence' => true,
            ]);
        }

        // Rule 10 — per-gesture documented pre-reqs from the table.
        $preReq = $this->checkPreReq($gesture, $context);
        if ($preReq !== null) {
            return $this->verdict(self::VERDICT_BLOCK, $preReq, $gesture, $this->preReqDetail($gesture, $context));
        }

        // Rule 11 — promote_ladder into L4+ requires a second distinct signature.
        if ($gesture === self::GESTURE_PROMOTE_LADDER) {
            $dual = $this->dualSignatureBlock($receipt, $context);
            if ($dual !== null) {
                return $dual;
            }
        }

        // All boundary conditions satisfied: the runtime may execute the effect.
        return $this->verdict(self::VERDICT_AUTHORIZE, 'gesture_authorized', $gesture, [
            'surface' => $surface,
            'target_kind' => $targetKind,
            'receipt_id' => $this->stringOrNull($receipt['receipt_id'] ?? null),
            'evidence_count' => count($this->hashList($receipt['evidence_hashes_seen'] ?? [])),
        ]);
    }

    /**
     * Validate ONLY the Operator Decision Receipt against its canonical schema,
     * independent of any gesture pre-req. Returns whether it is well-formed and
     * which fields (if any) are missing.
     *
     * @param  array<string,mixed>|null  $receipt
     *
     * @return array{schema:string,valid:bool,missing_fields:list<string>,reason:string}
     */
    public function validateReceipt(?array $receipt): array
    {
        if ($receipt === null || $receipt === []) {
            return [
                'schema' => self::SCHEMA,
                'valid' => false,
                'missing_fields' => self::RECEIPT_REQUIRED_FIELDS,
                'reason' => 'receipt_required',
            ];
        }
        $missing = $this->missingReceiptFields($receipt);
        if ($missing !== []) {
            return [
                'schema' => self::SCHEMA,
                'valid' => false,
                'missing_fields' => $missing,
                'reason' => 'receipt_incomplete',
            ];
        }
        if (($receipt['schema'] ?? null) !== self::RECEIPT_SCHEMA) {
            return [
                'schema' => self::SCHEMA,
                'valid' => false,
                'missing_fields' => [],
                'reason' => 'wrong_receipt_schema',
            ];
        }
        $gesture = $this->stringOrNull($receipt['gesture'] ?? null);
        if ($gesture === null || ! in_array($gesture, self::GESTURES, true)) {
            return [
                'schema' => self::SCHEMA,
                'valid' => false,
                'missing_fields' => [],
                'reason' => 'unknown_gesture',
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'valid' => true,
            'missing_fields' => [],
            'reason' => 'receipt_valid',
        ];
    }

    /**
     * Declarative gesture catalogue: the closed set with each gesture's target
     * kind, receipt-required flag (always true), evidence requirement, dual-sig
     * trigger and surface availability. Lets a surface render the gestures bar
     * (ZONE 11) without re-encoding the doc.
     *
     * @return array<string,mixed>
     */
    public function gestureCatalogue(): array
    {
        $rows = [];
        foreach (self::GESTURES as $gesture) {
            $rows[] = [
                'gesture' => $gesture,
                'target_kind' => self::GESTURE_TARGET_KIND[$gesture],
                'receipt_required' => true,
                'requires_evidence' => in_array($gesture, self::GESTURES_REQUIRING_EVIDENCE, true),
                'dual_signature_when_l4_plus' => $gesture === self::GESTURE_PROMOTE_LADDER,
                'available_on_mobile' => in_array($gesture, self::MOBILE_ALLOWED_GESTURES, true),
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'view_schema' => self::VIEW_SCHEMA,
            'receipt_schema' => self::RECEIPT_SCHEMA,
            'gesture_count' => count(self::GESTURES),
            'gestures' => $rows,
        ];
    }

    /**
     * Per-gesture documented pre-req. Returns a block reason string, or null when
     * the pre-req is satisfied.
     *
     * @param  array<string,mixed>  $context
     */
    private function checkPreReq(string $gesture, array $context): ?string
    {
        switch ($gesture) {
            case self::GESTURE_APPROVE_MILESTONE:
                // "milestone aguardando + evidence_hashes assinaveis" (evidence checked in Rule 9).
                return ($context['milestone_ready'] ?? false) === true ? null : 'milestone_not_ready';

            case self::GESTURE_VETO_DEPT:
                // "departamento ativo + rationale" — rationale presence is part of
                // receipt completeness; here we require a non-empty rationale value.
                return null; // department-active is a runtime fact; rationale is enforced by completeness.

            case self::GESTURE_PAUSE_OBRA:
                // "obra ativa".
                return ($context['obra_active'] ?? false) === true ? null : 'obra_not_active';

            case self::GESTURE_PROMOTE_LADDER:
                // "autonomy.next_eligible verde".
                return $this->stringOrNull($context['autonomy_next_eligible'] ?? null) !== null
                    ? null
                    : 'autonomy_next_not_eligible';

            case self::GESTURE_FORCE_REPLAY:
                // "obra completa com evidence intact" (evidence hashes checked in Rule 9).
                if (($context['obra_active'] ?? false) !== true) {
                    return 'obra_not_active';
                }

                return ($context['evidence_intact'] ?? false) === true ? null : 'evidence_not_intact';

            case self::GESTURE_FORCE_HANDOFF:
                // "sessao ativa + handoff pack pronto".
                return ($context['handoff_pack_ready'] ?? false) === true ? null : 'handoff_pack_not_ready';

            case self::GESTURE_SIGN_INTENT_RECEIPT:
                // "pending receipt aguardando".
                return ($context['pending_receipt'] ?? false) === true ? null : 'no_pending_receipt';
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $context
     *
     * @return array<string,mixed>
     */
    private function preReqDetail(string $gesture, array $context): array
    {
        return [
            'gesture' => $gesture,
            'milestone_ready' => (bool) ($context['milestone_ready'] ?? false),
            'obra_active' => (bool) ($context['obra_active'] ?? false),
            'evidence_intact' => (bool) ($context['evidence_intact'] ?? false),
            'handoff_pack_ready' => (bool) ($context['handoff_pack_ready'] ?? false),
            'pending_receipt' => (bool) ($context['pending_receipt'] ?? false),
            'autonomy_next_eligible' => $this->stringOrNull($context['autonomy_next_eligible'] ?? null),
        ];
    }

    /**
     * Dual-signature gate for ladder promotion into L4+. Returns a block verdict
     * or null when the signature requirement is met.
     *
     * @param  array<string,mixed>  $receipt
     * @param  array<string,mixed>  $context
     *
     * @return array<string,mixed>|null
     */
    private function dualSignatureBlock(array $receipt, array $context): ?array
    {
        $promoteTo = $this->levelInt($context['promote_to_level'] ?? null);
        if ($promoteTo === null || $promoteTo < 4) {
            return null; // L0..L3 promotions need only the single operator signature.
        }

        $signatures = $this->signatureList($receipt);
        $distinct = array_values(array_unique($signatures));
        if (count($distinct) < 2) {
            return $this->verdict(self::VERDICT_BLOCK, 'dual_signature_required', self::GESTURE_PROMOTE_LADDER, [
                'promote_to_level' => 'L'.$promoteTo,
                'distinct_signatures' => count($distinct),
                'required_signatures' => 2,
            ]);
        }

        return null;
    }

    /**
     * Collect the operator signatures on a receipt. The primary signature lives
     * in `operator_signature`; a co-signature (for L4+) lives in
     * `co_signatures` (list) or `dual_signatures` (list).
     *
     * @param  array<string,mixed>  $receipt
     *
     * @return list<string>
     */
    private function signatureList(array $receipt): array
    {
        $out = [];
        $primary = $this->stringOrNull($receipt['operator_signature'] ?? null);
        if ($primary !== null) {
            $out[] = $primary;
        }
        foreach (['co_signatures', 'dual_signatures'] as $key) {
            $extra = $receipt[$key] ?? null;
            if (is_array($extra)) {
                foreach ($extra as $sig) {
                    $s = $this->stringOrNull($sig);
                    if ($s !== null) {
                        $out[] = $s;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $receipt
     *
     * @return list<string>
     */
    private function missingReceiptFields(array $receipt): array
    {
        $missing = [];
        foreach (self::RECEIPT_REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $receipt) || $this->isEmptyValue($receipt[$field])) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function hashList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $v): ?string => $this->stringOrNull($v), $values),
            static fn (?string $v): bool => $v !== null,
        ));
    }

    private function levelInt(mixed $value): ?int
    {
        $s = $this->stringOrNull($value);
        if ($s === null) {
            return null;
        }
        $digits = ltrim($s, 'Ll');
        if ($digits === '' || ! ctype_digit($digits)) {
            return null;
        }

        return (int) $digits;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param  array<string,mixed>  $detail
     *
     * @return array<string,mixed>
     */
    private function verdict(string $verdict, string $reason, string $gesture, array $detail): array
    {
        return [
            'schema' => self::SCHEMA,
            'verdict' => $verdict,
            'authorized' => $verdict === self::VERDICT_AUTHORIZE,
            'reason' => $reason,
            'gesture' => $gesture,
            'detail' => $detail,
        ];
    }
}
