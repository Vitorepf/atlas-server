<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

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
        self::PHASE_INTENT_CAPTURE => ['surface_captured_intent'],
        self::PHASE_DISAMBIGUATION => ['intent_clarity_score_min_0_8'],
        self::PHASE_PLACEMENT => ['placement_decision_feature_path_valid'],
        self::PHASE_CLASSIFICATION => ['intent_classification_target_department_declared'],
        self::PHASE_POLICY_GATE => ['policy_decision_allowed_true'],
        self::PHASE_TOPOLOGY => ['topology_plan_providers_min_1_available'],
        self::PHASE_ROUTING => ['department_route_owner_confirmed'],
        self::PHASE_SPEC => ['spec_pack_acceptance_criteria_min_3'],
        self::PHASE_TASKS => ['task_pack_atomic_true_for_each'],
        self::PHASE_RECEIPT => ['decision_receipt_v2_signed'],
        self::PHASE_EXECUTION => ['execution_log_watchdog_ok'],
        self::PHASE_GATES => ['universal_15_gates_green_or_exception'],
        self::PHASE_EVIDENCE => ['evidence_pack_completeness_min_0_95'],
        self::PHASE_DELIVERY => ['delivery_pack_hash_signed'],
        self::PHASE_HUMAN_REVIEW => ['operator_decision_receipt_approved'],
        self::PHASE_CERTIFICATION => ['certification_severity_acceptable'],
        self::PHASE_LEARNING => ['learning_capsule_registered_in_acos'],
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
            if (! is_string($hash) || ! str_starts_with($hash, 'sha256:')) {
                throw new InvalidArgumentException('evidence_hashes must be sha256:* strings');
            }
        }
        $this->assertSignatureRequired($phaseOut, $autonomyLevel, $operatorSignature);

        $startedAt = $startedAt ?? gmdate('c');
        if ($nextPhase === null && $phaseOut !== self::PHASE_LEARNING) {
            $nextPhase = $this->canonicalNextPhase($phaseOut);
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'intent_id' => $intentId,
            'phase_in' => $phaseIn,
            'phase_out' => $phaseOut,
            'actor' => $actor,
            'inputs' => $inputs,
            'outputs' => $outputs,
            'evidence_hashes' => array_values($evidenceHashes),
            'gates' => [
                'required' => array_values($gates['required'] ?? self::PHASE_GATES_MAP[$phaseOut] ?? []),
                'passed' => array_values($gates['passed'] ?? []),
                'blocked' => array_values($gates['blocked'] ?? []),
            ],
            'blockers' => array_values($blockers),
            'operator_signature' => $operatorSignature,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'next_phase' => $nextPhase,
            'skip_reason' => $skipReason,
            'autonomy_level' => $autonomyLevel,
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
        if (($envelope['schema'] ?? null) !== self::SCHEMA_VERSION) {
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
        if (! isset($envelope['actor']) || ! is_array($envelope['actor']) || ! isset($envelope['actor']['kind'])) {
            $reasons[] = 'actor.kind required';
        } elseif (! in_array($envelope['actor']['kind'], ['agent', 'operator', 'system'], true)) {
            $reasons[] = 'actor.kind must be agent|operator|system';
        }
        if (! isset($envelope['gates']) || ! is_array($envelope['gates'])) {
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
        $this->assertPhase($phase, 'phase');
        if ($receiptId === '') {
            throw new InvalidArgumentException('skip requires receipt_id');
        }
        if ($reason === '') {
            throw new InvalidArgumentException('skip requires non-empty reason');
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'intent_id' => $intentId,
            'phase_in' => $phase,
            'phase_out' => $phase,
            'actor' => ['kind' => 'system', 'id' => 'aaeos.phase_skip', 'provider' => null],
            'inputs' => [],
            'outputs' => [],
            'evidence_hashes' => [],
            'gates' => ['required' => [], 'passed' => [], 'blocked' => []],
            'blockers' => [],
            'operator_signature' => null,
            'started_at' => gmdate('c'),
            'ended_at' => gmdate('c'),
            'next_phase' => $this->canonicalNextPhase($phase),
            'skip_reason' => $receiptId.': '.$reason,
            'autonomy_level' => $autonomyLevel,
        ];
    }

    /** @return list<string> */
    public function gatesForPhase(string $phase): array
    {
        $this->assertPhase($phase, 'phase');

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
        if (! isset($actor['kind']) || ! in_array($actor['kind'], ['agent', 'operator', 'system'], true)) {
            throw new InvalidArgumentException('actor.kind must be agent|operator|system');
        }
        if (! isset($actor['id']) || $actor['id'] === '') {
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
        $level = (int) ltrim($autonomyLevel, 'Ll');
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
