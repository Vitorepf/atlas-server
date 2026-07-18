<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Pure phase-advance verdict over a decoded atlas.aaeos.phase.v1 envelope.
 *
 * Live consumer: {@see AaeosHttpPathEnvelopeFactory::policyGateBlocked()} /
 * HTTP-path facade Phase 2+ policy-gate stop decision.
 */
final class PhaseAdvanceVerdictClassifier
{
    public const FIELD_ID = 'id';
    public const FIELD_OPERATOR_SIGNATURE = 'operator_signature';
    public const SCHEMA_VERSION = 'atlas.aaeos.phase_advance_verdict.v1';

    public const PHASE_POLICY_GATE = 'policy_gate';

    public const PHASE_RECEIPT = 'receipt';

    public const POLICY_GATE_TOKEN = 'policy_decision_allowed_true';

    public const VERDICT_ADVANCE = 'advance';

    public const VERDICT_REPAIR = 'repair';

    public const VERDICT_BLOCK = 'block';

    public const VERDICT_HALT = 'halt';

    public const FIELD_BLOCKED = 'blocked';
    public const FIELD_PHASE_OUT = 'phase_out';
    public const FIELD_BLOCKERS = 'blockers';
    public const FIELD_MISSING_GATES = 'missing_gates';
    public const FIELD_BLOCKED_GATES = 'blocked_gates';
    public const FIELD_GATES = 'gates';
    public const FIELD_HIGH_BLOCKER_IDS = 'high_blocker_ids';
    public const FIELD_PASSED = 'passed';
    public const FIELD_REASON = 'reason';
    public const FIELD_REQUIRED = 'required';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_VERDICT = 'verdict';

    /** @var list<string> */
    public const VERDICTS = [
        self::VERDICT_ADVANCE,
        self::VERDICT_REPAIR,
        self::VERDICT_BLOCK,
        self::VERDICT_HALT,
    ];

    /**
     * Ordered precedence rules applied top-to-bottom; the first match decides
     * the verdict. Block beats repair, policy halt and signature block are
     * decisive ahead of generic repair.
     *
     * @var list<string>
     */
    public const RULES = [
        'high_severity_blocker_block',
        'policy_decision_not_allowed_halt',
        'operator_signature_required',
        'open_blockers_repair',
        'missing_required_gates_repair',
        'blocked_gates_repair',
        'phase_advance_ready',
    ];

    /**
     * Pure verdict classifier over a decoded atlas.aaeos.phase.v1 envelope.
     *
     * Reads phase_out (string), gates.{required,passed,blocked} (list<string>),
     * blockers (list<array{id,severity,owner}>) and operator_signature (?string),
     * then walks the ordered precedence in self::RULES and returns the first
     * decisive verdict together with the computed gate/blocker projections.
     *
     * @param  array<string, mixed>  $envelope
     * @return array{
     *     schema_version: string,
     *     verdict: string,
     *     reason: string,
     *     missing_gates: list<string>,
     *     blocked_gates: list<string>,
     *     high_blocker_ids: list<string>
     * }
     */
    public function classify(array $envelope): array
    {
        $phaseOut = AiValueNormalizer::trimmedStringOrNull($envelope[self::FIELD_PHASE_OUT] ?? null) ?? '';

        $gates = AiValueNormalizer::arrayOrEmpty($envelope[self::FIELD_GATES] ?? null);
        $required = AiStringListNormalizer::trimmedStrings($gates[self::FIELD_REQUIRED] ?? []);
        $passed = AiStringListNormalizer::trimmedStrings($gates[self::FIELD_PASSED] ?? []);
        $blockedGates = AiStringListNormalizer::trimmedStrings($gates[self::FIELD_BLOCKED] ?? []);

        $blockers = AiValueNormalizer::arrayOrEmpty($envelope[self::FIELD_BLOCKERS] ?? null);
        $hasOpenBlockers = $this->hasOpenBlockers($blockers);
        $highBlockerIds = $this->highBlockerIds($blockers);

        $missingGates = $this->missingGates($required, $passed);
        $operatorSignature = $this->operatorSignature($envelope);

        [$verdict, $reason] = $this->resolveVerdict(
            $phaseOut,
            $passed,
            $missingGates,
            $blockedGates,
            $hasOpenBlockers,
            $highBlockerIds,
            $operatorSignature,
        );

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_VERDICT => $verdict,
            self::FIELD_REASON => $reason,
            self::FIELD_MISSING_GATES => $missingGates,
            self::FIELD_BLOCKED_GATES => $blockedGates,
            self::FIELD_HIGH_BLOCKER_IDS => $highBlockerIds,
        ];
    }

    /**
     * @param  list<string>  $passed
     * @param  list<string>  $missingGates
     * @param  list<string>  $blockedGates
     * @param  list<string>  $highBlockerIds
     * @return array{0: string, 1: string}
     */
    private function resolveVerdict(
        string $phaseOut,
        array $passed,
        array $missingGates,
        array $blockedGates,
        bool $hasOpenBlockers,
        array $highBlockerIds,
        ?string $operatorSignature,
    ): array {
        // Rule 1: a high/critical blocker outranks repair and forces a block
        // (aligned with {@see AaeosBlockerSeverityGate} blocked signal).
        if ($highBlockerIds !== []) {
            return [self::VERDICT_BLOCK, 'high_severity_blocker_block'];
        }

        // Rule 2: a policy gate whose decision token is not passed halts (no high blocker).
        if ($phaseOut === self::PHASE_POLICY_GATE && ! in_array(self::POLICY_GATE_TOKEN, $passed, true)) {
            return [self::VERDICT_HALT, 'policy_decision_not_allowed_halt'];
        }

        // Rule 3: a receipt phase without an operator signature blocks decisively.
        if ($phaseOut === self::PHASE_RECEIPT && $operatorSignature === null) {
            return [self::VERDICT_BLOCK, 'operator_signature_required'];
        }

        // Rule 4: remaining (non-high) open blockers require repair.
        if ($hasOpenBlockers) {
            return [self::VERDICT_REPAIR, 'open_blockers_repair'];
        }

        // Rule 5: any missing required gate requires repair.
        if ($missingGates !== []) {
            return [self::VERDICT_REPAIR, 'missing_required_gates_repair'];
        }

        // Rule 6: explicitly blocked gates require repair.
        if ($blockedGates !== []) {
            return [self::VERDICT_REPAIR, 'blocked_gates_repair'];
        }

        // Rule 7: nothing outstanding, the phase is clear to advance.
        return [self::VERDICT_ADVANCE, 'phase_advance_ready'];
    }

    /**
     * required minus passed, required order preserved, no duplicates.
     *
     * @param  list<string>  $required
     * @param  list<string>  $passed
     * @return list<string>
     */
    private function missingGates(array $required, array $passed): array
    {
        $passedLookup = array_fill_keys($passed, true);

        $missing = [];
        $seen = [];
        foreach ($required as $gate) {
            if (isset($passedLookup[$gate]) || isset($seen[$gate])) {
                continue;
            }

            $seen[$gate] = true;
            $missing[] = $gate;
        }

        return $missing;
    }

    /**
     * Decisive (high or critical) blocker ids — schema key remains
     * `high_blocker_ids` for envelope stability.
     *
     * @param  array<int|string, mixed>  $blockers
     * @return list<string>
     */
    private function highBlockerIds(array $blockers): array
    {
        $ids = [];

        foreach ($blockers as $blocker) {
            if (! is_array($blocker)) {
                continue;
            }

            $severity = AaeosBlockerSeverity::of($blocker);
            if (! AaeosBlockerSeverity::isDecisive($severity)) {
                continue;
            }

            $id = AiValueNormalizer::trimmedStringOrNull($blocker[self::FIELD_ID] ?? null);
            if ($id === null) {
                continue;
            }

            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @param  array<int|string, mixed>  $blockers
     */
    private function hasOpenBlockers(array $blockers): bool
    {
        foreach ($blockers as $blocker) {
            if (is_array($blocker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function operatorSignature(array $envelope): ?string
    {
        return AiValueNormalizer::trimmedStringOrNull($envelope[self::FIELD_OPERATOR_SIGNATURE] ?? null);
    }
}
