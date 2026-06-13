<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Runtime Packets contract guard.
 *
 * Pure, deterministic implementation of the runtime-packets doc. In the current
 * Atlas a "packet" is only a projection or DTO: the auditable truth lives in the
 * Operation Envelope + Decision Receipt + Evidence Ledger. This service answers
 * two documented questions without ever touching the database, a provider, the
 * shell or the filesystem:
 *
 *   1. resolveMapping($legacyType)  — map a legacy `Atlas_CLI_Packets_v1` type to
 *      its canonical current contract (the "Mapping Canonico" table).
 *
 *   2. validate($packet)            — enforce the closed "Invariantes" set on a
 *      packet/projection and return the single controlled verdict `accept` or
 *      `reject` with a machine reason list.
 *
 * Invariantes enforced verbatim from the doc:
 *   - Todo packet/projecao deve ter `trace_id`, `envelope_id` ou evidence ref.
 *   - Shell mutavel, file write, network ou tool T2/T3 precisam evidence e policy.
 *   - Memory delta nao entra direto em memoria ativa sem review/provider-safety.
 *   - Router decision deve distinguir domain/flow, provider, executor preference
 *     e safety/autonomy.
 *   - Completion packet nao substitui ledger replay.
 *
 * The service NEVER promotes a packet, applies a delta, executes a tool or writes
 * anything. It returns the verdict plus an audit receipt; callers decide whether
 * the projection may be trusted, must gain evidence/policy, or must be rejected.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-runtime-packets.md
 */
final class AtlasAiRuntimePacketsService
{
    /** Stable schema id for the verdict this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.runtime.packets_guard.v1';

    /** Canonical verdicts (closed set). */
    public const VERDICT_ACCEPT = 'accept';
    public const VERDICT_REJECT = 'reject';

    /**
     * "Mapping Canonico": legacy CLI packet type => canonical current contract.
     * A legacy packet must be projected onto these contracts, never resurrected
     * as a parallel architecture ("Packets legados devem ser mapeados para
     * contratos kernel atuais, nao reintroduzidos como arquitetura paralela").
     *
     * @var array<string,string>
     */
    private const MAPPING = [
        'dev_execution' => 'operation_envelope+decision_receipt+engineering_blueprint',
        'tool_event' => 'evidence_ledger_event+super_tool_runtime_evidence',
        'permission_session' => 'receipt_policy_scope+tool_gate_approval',
        'memory_delta' => 'memory_core_delta+provider_safe_review',
        'router_decision' => 'domain_flow_selection+provider_driver_plan+decision_receipt',
    ];

    /**
     * Tool tiers that the doc flags as side-effecting ("tool T2/T3"). Together
     * with shell/file-write/network these are the operation kinds that may never
     * be represented by an evidence-less, policy-less packet.
     *
     * @var list<string>
     */
    private const SIDE_EFFECT_TIERS = ['t2', 't3'];

    /**
     * Operation kinds that, like a side-effecting tool tier, demand both evidence
     * and policy on the packet.
     *
     * @var list<string>
     */
    private const SIDE_EFFECT_KINDS = ['shell_mutate', 'file_write', 'network'];

    /**
     * Fields a `router_decision` packet must distinguish, per the doc:
     * "domain/flow, provider, executor preference e safety/autonomy".
     *
     * @var list<string>
     */
    private const ROUTER_REQUIRED_FIELDS = [
        'domain',
        'flow',
        'provider',
        'executor_preference',
        'safety_autonomy',
    ];

    /**
     * Resolve the canonical contract a legacy packet type must project onto.
     *
     * @return array<string,mixed> {
     *   schema, legacy_type, known:bool, canonical_contract:?string,
     *   note:string
     * }
     */
    public function resolveMapping(string $legacyType): array
    {
        $key = strtolower(trim($legacyType));
        $known = array_key_exists($key, self::MAPPING);

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'legacy_type' => $key,
            'known' => $known,
            'canonical_contract' => $known ? self::MAPPING[$key] : null,
            'note' => $known
                ? 'legacy_packet_is_projection_onto_canonical_contract'
                : 'unknown_legacy_type_has_no_canonical_projection',
        ];
    }

    /**
     * Validate one packet/projection against the closed invariant set.
     *
     * @param  array<string,mixed>  $packet
     *   type            : string  packet kind (dev_execution|tool_event|
     *                     permission_session|memory_delta|router_decision|
     *                     completion|...)
     *   trace_id        : string  (default '')
     *   envelope_id     : string  (default '')
     *   evidence_refs   : list    evidence references (default [])
     *   tool_tier       : string  t0|t1|t2|t3 for tool/shell ops (default '')
     *   operation_kind  : string  shell_mutate|file_write|network|... (default '')
     *   has_policy      : bool    a policy/permission scope is attached (default false)
     *   reviewed        : bool    memory_delta passed review (default false)
     *   provider_safe   : bool    memory_delta cleared provider-safety (default false)
     *   ledger_replayable : bool  completion packet is backed by a replayable
     *                     ledger and does not claim to replace it (default false)
     *   ... router_decision fields (see ROUTER_REQUIRED_FIELDS) ...
     *
     * @return array<string,mixed> the verdict + audit receipt
     */
    public function validate(array $packet): array
    {
        $type = $this->normalizeType($packet['type'] ?? null);
        $reasons = [];

        $traceId = $this->stringOrEmpty($packet['trace_id'] ?? null);
        $envelopeId = $this->stringOrEmpty($packet['envelope_id'] ?? null);
        $evidence = $this->refList($packet['evidence_refs'] ?? []);
        $hasEvidence = $evidence !== [];

        // Invariant 1 — every packet must carry trace_id OR envelope_id OR an
        // evidence ref. A projection with no anchor is untraceable and rejected.
        $anchored = $traceId !== '' || $envelopeId !== '' || $hasEvidence;
        if (! $anchored) {
            $reasons[] = 'missing_trace_envelope_or_evidence';
        }

        // Invariant 2 — side-effecting operations (shell mutate / file write /
        // network / tool T2|T3) require BOTH evidence and policy. No silent
        // privileged action behind a bare packet.
        $tier = strtolower($this->stringOrEmpty($packet['tool_tier'] ?? null));
        $kind = strtolower($this->stringOrEmpty($packet['operation_kind'] ?? null));
        $isSideEffect = in_array($tier, self::SIDE_EFFECT_TIERS, true)
            || in_array($kind, self::SIDE_EFFECT_KINDS, true);
        if ($isSideEffect) {
            $hasPolicy = ($packet['has_policy'] ?? false) === true;
            if (! $hasEvidence) {
                $reasons[] = 'side_effect_requires_evidence';
            }
            if (! $hasPolicy) {
                $reasons[] = 'side_effect_requires_policy';
            }
        }

        // Invariant 3 — a memory_delta may not enter active memory directly: it
        // must be reviewed AND provider-safe before promotion.
        if ($type === 'memory_delta') {
            if (! (bool) ($packet['reviewed'] ?? false)) {
                $reasons[] = 'memory_delta_needs_review';
            }
            if (! (bool) ($packet['provider_safe'] ?? false)) {
                $reasons[] = 'memory_delta_needs_provider_safety';
            }
        }

        // Invariant 4 — a router_decision must distinguish domain/flow, provider,
        // executor preference and safety/autonomy. A missing axis means the
        // routing decision is ambiguous and is rejected.
        if ($type === 'router_decision') {
            foreach (self::ROUTER_REQUIRED_FIELDS as $field) {
                if ($this->isEmptyValue($packet[$field] ?? null)) {
                    $reasons[] = "router_decision_missing:{$field}";
                }
            }
        }

        // Invariant 5 — a completion packet never substitutes a ledger replay:
        // it must declare that it is backed by a replayable ledger.
        if ($type === 'completion') {
            if (! (bool) ($packet['ledger_replayable'] ?? false)) {
                $reasons[] = 'completion_packet_does_not_replace_ledger_replay';
            }
        }

        $verdict = $reasons === [] ? self::VERDICT_ACCEPT : self::VERDICT_REJECT;
        if ($verdict === self::VERDICT_ACCEPT) {
            $reasons[] = 'all_invariants_satisfied';
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'verdict' => $verdict,
            'accepted' => $verdict === self::VERDICT_ACCEPT,
            'type' => $type,
            'anchored' => $anchored,
            'side_effecting' => $isSideEffect,
            'has_evidence' => $hasEvidence,
            'evidence_count' => count($evidence),
            'auditable' => true,
            'reasons' => $reasons,
        ];
    }

    /**
     * Convenience predicate: may this projection be trusted as-is?
     */
    public function isAcceptable(array $packet): bool
    {
        return $this->validate($packet)['verdict'] === self::VERDICT_ACCEPT;
    }

    private function normalizeType(mixed $type): string
    {
        if (! is_string($type) || trim($type) === '') {
            return 'unknown';
        }

        return strtolower(trim($type));
    }

    private function stringOrEmpty(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return list<string>
     */
    private function refList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $clean = [];
        foreach ($values as $ref) {
            if (is_string($ref) && trim($ref) !== '') {
                $clean[] = trim($ref);
            }
        }

        return array_values($clean);
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
}
