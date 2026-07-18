<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\Support\AiValueNormalizer;
use InvalidArgumentException;

/**
 * AAEOS Phase Handoff Service — emits and validates `atlas.aaeos.phase.v1`
 * envelopes for the 17 canonical phases (P0→P16) described in
 * `atlas-agentic-engineering-os-runbook.md`.
 *
 * This service is the central contract between phases: each transition
 * produces an envelope that records the actor, inputs, outputs, evidence
 * hashes, gates and (when autonomy >= L4) operator_signature.
 *
 * The service does NOT execute phase work — it stamps the canonical
 * handoff envelope, validates the next phase transition is legal, and
 * exposes the phase-by-phase gate vocabulary.
 *
 * Provider-safe by default: callers pass evidence as hashes (sha256:...),
 * never raw operator input. The envelope rejects raw payloads.
 */
final class AaeosPhaseHandoffService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.phase.v1';

    public const FIELD_BLOCKED = 'blocked';

    public const FIELD_PASSED = 'passed';
    public const FIELD_ACTOR = 'actor';
    public const FIELD_KIND = 'kind';
    public const FIELD_GATES = 'gates';
    public const FIELD_SCHEMA = 'schema';
    public const FIELD_REQUIRED = 'required';
    public const FIELD_ID = 'id';
    public const FIELD_STATUS = 'status';
    public const FIELD_REASON = 'reason';
    public const FIELD_INTENT_ID = 'intent_id';
    public const FIELD_PHASE_IN = 'phase_in';
    public const FIELD_PHASE_OUT = 'phase_out';
    public const FIELD_INPUTS = 'inputs';
    public const FIELD_OUTPUTS = 'outputs';
    public const FIELD_EVIDENCE_HASHES = 'evidence_hashes';
    public const FIELD_BLOCKERS = 'blockers';
    public const FIELD_OPERATOR_SIGNATURE = 'operator_signature';
    public const FIELD_STARTED_AT = 'started_at';
    public const FIELD_ENDED_AT = 'ended_at';
    public const FIELD_NEXT_PHASE = 'next_phase';
    public const FIELD_SKIP_REASON = 'skip_reason';
    public const FIELD_AUTONOMY_LEVEL = 'autonomy_level';
    public const FIELD_SURFACE_CAPTURED_INTENT = 'surface_captured_intent';
    public const FIELD_INTENT_CLARITY_SCORE_MIN_0_8 = 'intent_clarity_score_min_0_8';
    public const FIELD_UNIVERSAL_15_GATES_GREEN_OR_EXCEPTION = 'universal_15_gates_green_or_exception';
    public const FIELD_TOPOLOGY_PLAN_PROVIDERS_MIN_1_AVAILABLE = 'topology_plan_providers_min_1_available';
    public const FIELD_CERTIFICATION_SEVERITY_ACCEPTABLE = 'certification_severity_acceptable';
    public const FIELD_DECISION_RECEIPT_V2_SIGNED = 'decision_receipt_v2_signed';
    public const FIELD_DELIVERY_PACK_HASH_SIGNED = 'delivery_pack_hash_signed';
    public const FIELD_DEPARTMENT_ROUTE_OWNER_CONFIRMED = 'department_route_owner_confirmed';
    public const FIELD_EVIDENCE_PACK_COMPLETENESS_MIN_0_95 = 'evidence_pack_completeness_min_0_95';
    public const FIELD_EXECUTION_LOG_WATCHDOG_OK = 'execution_log_watchdog_ok';
    public const FIELD_LEARNING_CAPSULE_REGISTERED_IN_ACOS = 'learning_capsule_registered_in_acos';
    public const FIELD_OPERATOR_DECISION_RECEIPT_APPROVED = 'operator_decision_receipt_approved';
    public const FIELD_PLACEMENT_DECISION_FEATURE_PATH_VALID = 'placement_decision_feature_path_valid';
    public const FIELD_POLICY_DECISION_ALLOWED_TRUE = 'policy_decision_allowed_true';
    public const FIELD_PROVIDER = 'provider';
    public const FIELD_SPEC_PACK_ACCEPTANCE_CRITERIA_MIN_3 = 'spec_pack_acceptance_criteria_min_3';
    public const FIELD_TASK_PACK_ATOMIC_TRUE_FOR_EACH = 'task_pack_atomic_true_for_each';
    public const FIELD_OPERATOR = 'operator';
    public const FIELD_PHASE = 'phase';
    public const FIELD_SYSTEM = 'system';

    public static function requireIntentId(string $intentId): void
    {
        if ($intentId === '') {
            throw new InvalidArgumentException('intent_id required');
        }
    }

    /** Parse L0..L7 (or bare digits) into an int autonomy rung. */
    public static function autonomyLevelInt(string $autonomyLevel): int
    {
        return (int) ltrim(AiValueNormalizer::trimmedStringOrNull($autonomyLevel) ?? '', 'Ll');
    }

    public const PHASE_INTENT_CAPTURE = 'intent_capture';

    public const PHASE_DISAMBIGUATION = 'disambiguation';

    public const PHASE_PLACEMENT = 'placement';

    public const PHASE_CLASSIFICATION = 'classification';

    public const PHASE_POLICY_GATE = 'policy_gate';

    public const PHASE_TOPOLOGY = 'topology';

    public const PHASE_ROUTING = 'routing';

    public const PHASE_SPEC = 'spec';

    public const PHASE_TASKS = 'tasks';

    public const PHASE_RECEIPT = 'receipt';

    public const PHASE_EXECUTION = 'execution';

    public const PHASE_GATES = 'gates';

    public const PHASE_EVIDENCE = 'evidence';

    public const PHASE_DELIVERY = 'delivery';

    public const PHASE_HUMAN_REVIEW = 'human_review';

    public const PHASE_CERTIFICATION = 'certification';

    public const PHASE_LEARNING = 'learning';

    /** The 17 canonical phases in order. */
    public const PHASES = [
        self::PHASE_INTENT_CAPTURE,
        self::PHASE_DISAMBIGUATION,
        self::PHASE_PLACEMENT,
        self::PHASE_CLASSIFICATION,
        self::PHASE_POLICY_GATE,
        self::PHASE_TOPOLOGY,
        self::PHASE_ROUTING,
        self::PHASE_SPEC,
        self::PHASE_TASKS,
        self::PHASE_RECEIPT,
        self::PHASE_EXECUTION,
        self::PHASE_GATES,
        self::PHASE_EVIDENCE,
        self::PHASE_DELIVERY,
        self::PHASE_HUMAN_REVIEW,
        self::PHASE_CERTIFICATION,
        self::PHASE_LEARNING,
    ];

    /**
     * Canonical gate per phase (from runbook table "Gates por fase").
     *
     * @var array<string,list<string>>
     */
    public const PHASE_GATES_MAP = [
        self::PHASE_INTENT_CAPTURE => [self::FIELD_SURFACE_CAPTURED_INTENT],
        self::PHASE_DISAMBIGUATION => [self::FIELD_INTENT_CLARITY_SCORE_MIN_0_8],
        self::PHASE_PLACEMENT => [self::FIELD_PLACEMENT_DECISION_FEATURE_PATH_VALID],
        self::PHASE_CLASSIFICATION => ['intent_classification_target_department_declared'],
        self::PHASE_POLICY_GATE => [self::FIELD_POLICY_DECISION_ALLOWED_TRUE],
        self::PHASE_TOPOLOGY => [self::FIELD_TOPOLOGY_PLAN_PROVIDERS_MIN_1_AVAILABLE],
        self::PHASE_ROUTING => [self::FIELD_DEPARTMENT_ROUTE_OWNER_CONFIRMED],
        self::PHASE_SPEC => [self::FIELD_SPEC_PACK_ACCEPTANCE_CRITERIA_MIN_3],
        self::PHASE_TASKS => [self::FIELD_TASK_PACK_ATOMIC_TRUE_FOR_EACH],
        self::PHASE_RECEIPT => [self::FIELD_DECISION_RECEIPT_V2_SIGNED],
        self::PHASE_EXECUTION => [self::FIELD_EXECUTION_LOG_WATCHDOG_OK],
        self::PHASE_GATES => [self::FIELD_UNIVERSAL_15_GATES_GREEN_OR_EXCEPTION],
        self::PHASE_EVIDENCE => [self::FIELD_EVIDENCE_PACK_COMPLETENESS_MIN_0_95],
        self::PHASE_DELIVERY => [self::FIELD_DELIVERY_PACK_HASH_SIGNED],
        self::PHASE_HUMAN_REVIEW => [self::FIELD_OPERATOR_DECISION_RECEIPT_APPROVED],
        self::PHASE_CERTIFICATION => [self::FIELD_CERTIFICATION_SEVERITY_ACCEPTABLE],
        self::PHASE_LEARNING => [self::FIELD_LEARNING_CAPSULE_REGISTERED_IN_ACOS],
    ];

    /** Phases that require operator signature when autonomy_level >= L4. */
    public const PHASES_REQUIRING_SIGNATURE_AT_L4 = [
        self::PHASE_RECEIPT,
        self::PHASE_HUMAN_REVIEW,
        self::PHASE_DELIVERY,
    ];

    /**
     * Emit a canonical `atlas.aaeos.phase.v1` envelope.
     *
     * @param  array<string,mixed>  $inputs   Hashed payloads only (sha256:...)
     * @param  array<string,mixed>  $outputs  Hashed payloads only (sha256:...)
     * @param  list<string>         $evidenceHashes
     * @param  array{required?: list<string>, passed?: list<string>, blocked?: list<string>}  $gates
     * @param  list<array{id:string,severity:string,owner:string}>  $blockers
     * @return array<string,mixed>
     */
    public function emit(
        string $intentId,
        string $phaseIn,
        string $phaseOut,
        array $actor,
        array $inputs,
        array $outputs,
        array $evidenceHashes = [],
        array $gates = [],
        array $blockers = [],
        ?string $operatorSignature = null,
        ?string $startedAt = null,
        ?string $endedAt = null,
        ?string $nextPhase = null,
        ?string $skipReason = null,
        string $autonomyLevel = 'L1',
    ): array {
        $this->assertPhase($phaseIn, 'phase_in');
        $this->assertPhase($phaseOut, 'phase_out');
        if ($nextPhase !== null) {
            $this->assertPhase($nextPhase, 'next_phase');
        }
        $this->assertActor($actor);
        $this->assertProviderSafe($inputs, 'inputs');
        $this->assertProviderSafe($outputs, 'outputs');
        foreach ($evidenceHashes as $hash) {
            $hash = AiValueNormalizer::trimmedStringOrNull($hash);
            if ($hash === null || ! str_starts_with($hash, 'sha256:')) {
                throw new InvalidArgumentException('evidence_hashes must be sha256:* strings');
            }
        }
        $this->assertSignatureRequired($phaseOut, $autonomyLevel, $operatorSignature);

        $startedAt = $startedAt ?? gmdate('c');
        if ($nextPhase === null && $phaseOut !== self::PHASE_LEARNING) {
            $nextPhase = $this->canonicalNextPhase($phaseOut);
        }

        return [
            self::FIELD_SCHEMA => self::SCHEMA_VERSION,
            self::FIELD_INTENT_ID => $intentId,
            self::FIELD_PHASE_IN => $phaseIn,
            self::FIELD_PHASE_OUT => $phaseOut,
            self::FIELD_ACTOR => $actor,
            self::FIELD_INPUTS => $inputs,
            self::FIELD_OUTPUTS => $outputs,
            self::FIELD_EVIDENCE_HASHES => array_values($evidenceHashes),
            self::FIELD_GATES => [
                self::FIELD_REQUIRED => array_values(AiValueNormalizer::arrayOrEmpty($gates[self::FIELD_REQUIRED] ?? self::PHASE_GATES_MAP[$phaseOut] ?? null)),
                self::FIELD_PASSED => array_values(AiValueNormalizer::arrayOrEmpty($gates[self::FIELD_PASSED] ?? null)),
                self::FIELD_BLOCKED => array_values(AiValueNormalizer::arrayOrEmpty($gates[self::FIELD_BLOCKED] ?? null)),
            ],
            self::FIELD_BLOCKERS => array_values(AiValueNormalizer::arrayOrEmpty($blockers)),
            self::FIELD_OPERATOR_SIGNATURE => $operatorSignature,
            self::FIELD_STARTED_AT => $startedAt,
            self::FIELD_ENDED_AT => $endedAt,
            self::FIELD_NEXT_PHASE => $nextPhase,
            self::FIELD_SKIP_REASON => $skipReason,
            self::FIELD_AUTONOMY_LEVEL => $autonomyLevel,
        ];
    }

    /**
     * Validate an existing envelope structurally. Returns reasons (empty if ok).
     *
     * @param  array<string,mixed>  $envelope
     * @return list<string>
     */
    public function validate(array $envelope): array
    {
        $reasons = [];
        if (($envelope[self::FIELD_SCHEMA] ?? null) !== self::SCHEMA_VERSION) {
            $reasons[] = 'schema must be '.self::SCHEMA_VERSION;
        }
        foreach (['intent_id', 'phase_in', 'phase_out'] as $req) {
            if (! isset($envelope[$req]) || $envelope[$req] === '') {
                $reasons[] = "missing {$req}";
            }
        }
        foreach (['phase_in', 'phase_out'] as $f) {
            if (isset($envelope[$f]) && ! in_array($envelope[$f], self::PHASES, true)) {
                $reasons[] = "{$f} '{$envelope[$f]}' is not canonical";
            }
        }
        if (! isset($envelope[self::FIELD_ACTOR]) || ! is_array($envelope[self::FIELD_ACTOR]) || ! isset($envelope[self::FIELD_ACTOR][self::FIELD_KIND])) {
            $reasons[] = 'actor.kind required';
        } elseif (! in_array($envelope[self::FIELD_ACTOR][self::FIELD_KIND], ['agent', self::FIELD_OPERATOR, 'system'], true)) {
            $reasons[] = 'actor.kind must be agent|operator|system';
        }
        if (! isset($envelope[self::FIELD_GATES]) || ! is_array($envelope[self::FIELD_GATES])) {
            $reasons[] = 'gates required';
        }

        return $reasons;
    }

    /**
     * Compute the canonical next phase. Returns null on terminal (learning).
     */
    public function canonicalNextPhase(string $phase): ?string
    {
        $idx = array_search($phase, self::PHASES, true);
        if ($idx === false || $idx === count(self::PHASES) - 1) {
            return null;
        }

        return self::PHASES[$idx + 1];
    }

    /**
     * Stamp a "phase skipped" receipt. Skips ARE recorded — never silent.
     *
     * @return array<string,mixed>
     */
    public function skip(string $intentId, string $phase, string $receiptId, string $reason, string $autonomyLevel = 'L1'): array
    {
        $this->assertPhase($phase, self::FIELD_PHASE);
        if ($receiptId === '') {
            throw new InvalidArgumentException('skip requires receipt_id');
        }
        if ($reason === '') {
            throw new InvalidArgumentException('skip requires non-empty reason');
        }

        return [
            self::FIELD_SCHEMA => self::SCHEMA_VERSION,
            self::FIELD_INTENT_ID => $intentId,
            self::FIELD_PHASE_IN => $phase,
            self::FIELD_PHASE_OUT => $phase,
            self::FIELD_ACTOR => [self::FIELD_KIND => self::FIELD_SYSTEM, self::FIELD_ID => 'aaeos.phase_skip', self::FIELD_PROVIDER => null],
            self::FIELD_INPUTS => [],
            self::FIELD_OUTPUTS => [],
            self::FIELD_EVIDENCE_HASHES => [],
            self::FIELD_GATES => [self::FIELD_REQUIRED => [], self::FIELD_PASSED => [], self::FIELD_BLOCKED => []],
            self::FIELD_BLOCKERS => [],
            self::FIELD_OPERATOR_SIGNATURE => null,
            self::FIELD_STARTED_AT => gmdate('c'),
            self::FIELD_ENDED_AT => gmdate('c'),
            self::FIELD_NEXT_PHASE => $this->canonicalNextPhase($phase),
            self::FIELD_SKIP_REASON => $receiptId.': '.$reason,
            self::FIELD_AUTONOMY_LEVEL => $autonomyLevel,
        ];
    }

    /** @return list<string> */
    public function gatesForPhase(string $phase): array
    {
        $this->assertPhase($phase, self::FIELD_PHASE);

        return self::PHASE_GATES_MAP[$phase] ?? [];
    }

    public function phaseIndex(string $phase): int
    {
        $idx = array_search($phase, self::PHASES, true);
        if ($idx === false) {
            throw new InvalidArgumentException("unknown phase '{$phase}'");
        }

        return (int) $idx;
    }

    /** @param array<string,mixed> $actor */
    private function assertActor(array $actor): void
    {
        if (! isset($actor[self::FIELD_KIND]) || ! in_array($actor[self::FIELD_KIND], ['agent', self::FIELD_OPERATOR, 'system'], true)) {
            throw new InvalidArgumentException('actor.kind must be agent|operator|system');
        }
        if (! isset($actor[self::FIELD_ID]) || $actor[self::FIELD_ID] === '') {
            throw new InvalidArgumentException('actor.id required');
        }
    }

    private function assertPhase(string $phase, string $label): void
    {
        if (! in_array($phase, self::PHASES, true)) {
            throw new InvalidArgumentException("{$label} '{$phase}' is not a canonical AAEOS phase");
        }
    }

    /** @param array<string,mixed> $payload */
    private function assertProviderSafe(array $payload, string $label): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($value)) {
                $isHash = str_starts_with($value, 'sha256:') || str_starts_with($value, 'rcpt:') || str_starts_with($value, 'evidence:');
                if (! $isHash && strlen($value) > 256) {
                    throw new InvalidArgumentException("{$label}.{$key} looks like raw operator input; pass sha256:* hash");
                }
            }
        }
    }

    private function assertSignatureRequired(string $phase, string $autonomyLevel, ?string $signature): void
    {
        $level = self::autonomyLevelInt($autonomyLevel);
        if ($level < 4) {
            return;
        }
        if (! in_array($phase, self::PHASES_REQUIRING_SIGNATURE_AT_L4, true)) {
            return;
        }
        if ($signature === null || $signature === '') {
            throw new InvalidArgumentException(
                "phase '{$phase}' at autonomy {$autonomyLevel} requires operator_signature"
            );
        }
    }
}
