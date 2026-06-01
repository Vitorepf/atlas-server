<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs;

final class PhaseAdvanceVerdictClassifier
{
    private const SCHEMA_VERSION = 'atlas.aaeos.phase_advance_verdict.v1';

    private const PHASE_POLICY_GATE = 'policy_gate';

    private const PHASE_RECEIPT = 'receipt';

    private const POLICY_GATE_TOKEN = 'policy_decision_allowed_true';

    private const SEVERITY_HIGH = 'high';

    private const VERDICT_ADVANCE = 'advance';

    private const VERDICT_REPAIR = 'repair';

    private const VERDICT_BLOCK = 'block';

    private const VERDICT_HALT = 'halt';

    /**
     * Ordered precedence rules applied top-to-bottom; the first match decides
     * the verdict. Block beats repair, policy halt and signature block are
     * decisive ahead of generic repair.
     *
     * @var list<string>
     */
    private const RULES = [
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
        $phaseOut = $this->stringValue($envelope, 'phase_out');

        $gates = is_array($envelope['gates'] ?? null) ? $envelope['gates'] : [];
        $required = $this->normalizeList($gates['required'] ?? []);
        $passed = $this->normalizeList($gates['passed'] ?? []);
        $blockedGates = $this->normalizeList($gates['blocked'] ?? []);

        $blockers = is_array($envelope['blockers'] ?? null) ? $envelope['blockers'] : [];
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
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $verdict,
            'reason' => $reason,
            'missing_gates' => $missingGates,
            'blocked_gates' => $blockedGates,
            'high_blocker_ids' => $highBlockerIds,
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
        // Rule 1: a high-severity blocker outranks repair and forces a block.
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

            if ($this->severityOf($blocker) !== self::SEVERITY_HIGH) {
                continue;
            }

            $id = $blocker['id'] ?? null;
            if (! is_string($id)) {
                continue;
            }

            $trimmed = trim($id);
            if ($trimmed === '') {
                continue;
            }

            $ids[] = $trimmed;
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
     * @param  array<string, mixed>  $blocker
     */
    private function severityOf(array $blocker): string
    {
        $severity = $blocker['severity'] ?? null;

        if (! is_string($severity)) {
            return '';
        }

        return strtolower(trim($severity));
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function operatorSignature(array $envelope): ?string
    {
        $signature = $envelope['operator_signature'] ?? null;

        if (! is_string($signature)) {
            return null;
        }

        $trimmed = trim($signature);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringValue(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Trim non-empty string entries, drop non-string and blank values, keep first-seen order.
     *
     * @param  mixed  $values
     * @return list<string>
     */
    private function normalizeList($values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $normalized = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }

            $normalized[] = $trimmed;
        }

        return $normalized;
    }
}
