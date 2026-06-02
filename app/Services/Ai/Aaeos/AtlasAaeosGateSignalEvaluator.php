<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

/**
 * Per-phase gate-signal evaluator for the AAEOS runbook phases.
 *
 * Replaces the runbook's hard-pass gates (P1 intent clarity, P7 spec-pack
 * acceptance criteria, P8 task-pack atomicity) with deterministic, pure
 * computation. No constructor, zero dependencies, no I/O: every returned
 * field is computed from the method inputs via explicit rules, and identical
 * inputs always yield identical output (including reason ordering).
 */
final class AtlasAaeosGateSignalEvaluator
{
    private const SCHEMA_VERSION = 'atlas.aaeos.gate_signal.v1';

    private const GATE_INTENT_CLARITY = 'intent_clarity_score_min_0_8';

    private const GATE_SPEC_PACK = 'spec_pack_acceptance_criteria_min_3';

    private const GATE_TASK_PACK = 'task_pack_atomic_true_for_each';

    private const INTENT_CLARITY_THRESHOLD = 0.8;

    private const SPEC_PACK_MIN_CRITERIA = 3;

    /**
     * Weights for the four clarity sub-signals. They sum to 1.0 so that a
     * fully resolved, bounded, unambiguous, fully-answered intent computes a
     * clamped score of exactly 1.0.
     */
    private const WEIGHT_RESOLVED = 0.4;

    private const WEIGHT_BOUNDED = 0.3;

    private const WEIGHT_NO_AMBIGUITY = 0.2;

    private const WEIGHT_NO_MISSING = 0.1;

    /**
     * Ambiguity reaches a zero sub-score at this many tokens; below it the
     * penalty scales linearly.
     */
    private const AMBIGUITY_SATURATION = 2;

    /**
     * Missing answers reach a zero sub-score at this many; a single missing
     * answer fully zeroes that sub-signal.
     */
    private const MISSING_SATURATION = 1;

    /**
     * Connectors that mark a task scope as compound (non-atomic).
     */
    private const COMPOUND_CONNECTORS = [' and ', ' & ', ' then ', ' plus ', '; '];

    /**
     * P1 disambiguation gate: weighted + clamped 0..1 intent-clarity score.
     *
     * @param  array<string,mixed>  $disambiguationFeatures
     * @return array{schema_version:string, gate:string, passed:bool, computed_value:float, reasons:list<string>}
     */
    public function evaluateIntentClarity(array $disambiguationFeatures): array
    {
        $resolvedTarget = $this->stringOrNull($disambiguationFeatures['resolved_target'] ?? null);
        $scopeBounded = ($disambiguationFeatures['scope_bounded'] ?? false) === true;
        $ambiguityTokens = $this->stringList($disambiguationFeatures['ambiguity_tokens'] ?? []);
        $missingCount = $this->missingAnswersCount($disambiguationFeatures['missing_answers'] ?? []);

        $ambiguityCount = count($ambiguityTokens);

        $resolvedComponent = $resolvedTarget !== null ? self::WEIGHT_RESOLVED : 0.0;
        $boundedComponent = $scopeBounded ? self::WEIGHT_BOUNDED : 0.0;
        $ambiguityComponent = self::WEIGHT_NO_AMBIGUITY * $this->decay($ambiguityCount, self::AMBIGUITY_SATURATION);
        $missingComponent = self::WEIGHT_NO_MISSING * $this->decay($missingCount, self::MISSING_SATURATION);

        $score = $this->clampUnit($resolvedComponent + $boundedComponent + $ambiguityComponent + $missingComponent);
        $passed = $score >= self::INTENT_CLARITY_THRESHOLD;

        $reasons = [];
        if ($resolvedTarget === null) {
            $reasons[] = 'resolved_target_missing';
        }
        if (! $scopeBounded) {
            $reasons[] = 'scope_unbounded';
        }
        if ($ambiguityCount > 0) {
            $reasons[] = 'ambiguity_tokens_present:'.$ambiguityCount;
        }
        if ($missingCount > 0) {
            $reasons[] = 'missing_answers_present:'.$missingCount;
        }
        if ($reasons === []) {
            $reasons[] = 'intent_clear';
        }

        return $this->gateResult(self::GATE_INTENT_CLARITY, $passed, $score, $reasons);
    }

    /**
     * P7 spec gate: computed_value is the COUNT of distinct non-blank
     * acceptance criteria; passes when that count is at least 3.
     *
     * @param  array<string,mixed>  $specPack
     * @return array{schema_version:string, gate:string, passed:bool, computed_value:int, reasons:list<string>}
     */
    public function evaluateSpecPackAcceptanceCriteria(array $specPack): array
    {
        $raw = $this->stringList($specPack['acceptance_criteria'] ?? []);

        $distinct = [];
        $blankCount = 0;
        foreach ($raw as $criterion) {
            $trimmed = trim($criterion);
            if ($trimmed === '') {
                $blankCount++;

                continue;
            }
            $distinct[$trimmed] = true;
        }

        $count = count($distinct);
        $passed = $count >= self::SPEC_PACK_MIN_CRITERIA;

        $reasons = [];
        if (! $passed) {
            $reasons[] = 'acceptance_criteria_below_min:'.$count.'<'.self::SPEC_PACK_MIN_CRITERIA;
        } else {
            $reasons[] = 'acceptance_criteria_met:'.$count;
        }
        if ($blankCount > 0) {
            $reasons[] = 'blank_criteria_ignored:'.$blankCount;
        }

        return $this->gateResult(self::GATE_SPEC_PACK, $passed, $count, $reasons);
    }

    /**
     * P8 tasks gate: computed_value is an all-atomic bool. A task is
     * non-atomic when its scope is compound (e.g. "build X and deploy Y") or
     * when it carries no acceptance. Reasons name the offending task index.
     *
     * @param  array<string,mixed>  $taskPack
     * @return array{schema_version:string, gate:string, passed:bool, computed_value:bool, reasons:list<string>}
     */
    public function evaluateTaskPackAtomicity(array $taskPack): array
    {
        $tasks = $this->taskList($taskPack['tasks'] ?? []);

        $reasons = [];
        if ($tasks === []) {
            $reasons[] = 'task_pack_empty';

            return $this->gateResult(self::GATE_TASK_PACK, false, false, $reasons);
        }

        $allAtomic = true;
        foreach ($tasks as $index => $task) {
            $scope = $this->stringOrNull($task['scope'] ?? null) ?? '';
            if ($this->isCompoundScope($scope)) {
                $reasons[] = 'task_'.$index.'_compound_scope';
                $allAtomic = false;
            }
            if (! $this->hasAcceptance($task)) {
                $reasons[] = 'task_'.$index.'_missing_acceptance';
                $allAtomic = false;
            }
        }

        if ($reasons === []) {
            $reasons[] = 'all_tasks_atomic';
        }

        return $this->gateResult(self::GATE_TASK_PACK, $allAtomic, $allAtomic, $reasons);
    }

    /**
     * Fan the per-phase evaluators across whichever of the intent / spec_pack
     * / task_pack outputs are present, then AND their pass states.
     *
     * @param  array<string,mixed>  $phaseOutputs
     * @return array{schema_version:string, gates:list<array{schema_version:string, gate:string, passed:bool, computed_value:bool|float|int, reasons:list<string>}>, all_passed:bool}
     */
    public function evaluatePhaseGates(array $phaseOutputs): array
    {
        $gates = [];

        if (array_key_exists('intent', $phaseOutputs)) {
            $gates[] = $this->evaluateIntentClarity($this->asArray($phaseOutputs['intent']));
        }
        if (array_key_exists('spec_pack', $phaseOutputs)) {
            $gates[] = $this->evaluateSpecPackAcceptanceCriteria($this->asArray($phaseOutputs['spec_pack']));
        }
        if (array_key_exists('task_pack', $phaseOutputs)) {
            $gates[] = $this->evaluateTaskPackAtomicity($this->asArray($phaseOutputs['task_pack']));
        }

        if ($gates === []) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'gates' => [],
                'all_passed' => false,
                'reasons' => ['no_phase_outputs'],
            ];
        }

        $allPassed = true;
        foreach ($gates as $gate) {
            if ($gate['passed'] !== true) {
                $allPassed = false;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'gates' => $gates,
            'all_passed' => $allPassed,
        ];
    }

    /**
     * @param  list<string>  $reasons
     * @return array{schema_version:string, gate:string, passed:bool, computed_value:bool|float|int, reasons:list<string>}
     */
    private function gateResult(string $gate, bool $passed, bool|float|int $computedValue, array $reasons): array
    {
        sort($reasons, SORT_STRING);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'gate' => $gate,
            'passed' => $passed,
            'computed_value' => $computedValue,
            'reasons' => array_values($reasons),
        ];
    }

    /**
     * Linear decay from 1.0 at zero to 0.0 at the saturation point.
     */
    private function decay(int $count, int $saturation): float
    {
        if ($saturation <= 0) {
            return $count > 0 ? 0.0 : 1.0;
        }

        $ratio = 1.0 - ($count / $saturation);

        return $ratio < 0.0 ? 0.0 : ($ratio > 1.0 ? 1.0 : $ratio);
    }

    private function clampUnit(float $value): float
    {
        if ($value < 0.0) {
            return 0.0;
        }

        return $value > 1.0 ? 1.0 : $value;
    }

    private function isCompoundScope(string $scope): bool
    {
        $normalized = ' '.strtolower(trim($scope)).' ';
        foreach (self::COMPOUND_CONNECTORS as $connector) {
            if (str_contains($normalized, $connector)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $task
     */
    private function hasAcceptance(array $task): bool
    {
        $acceptance = $task['acceptance'] ?? null;

        if (is_string($acceptance)) {
            return trim($acceptance) !== '';
        }

        if (is_array($acceptance)) {
            foreach ($acceptance as $entry) {
                if (is_string($entry) && trim($entry) !== '') {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    private function missingAnswersCount(mixed $value): int
    {
        if (is_int($value)) {
            return $value < 0 ? 0 : $value;
        }

        if (is_array($value)) {
            return count($value);
        }

        return 0;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return trim($value) === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            if (is_string($entry)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function taskList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            if (is_array($entry)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function asArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
