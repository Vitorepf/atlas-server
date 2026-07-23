<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Maturity;

use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Per-phase gate-signal evaluator for the AAEOS runbook phases.
 *
 * Replaces the runbook's hard-pass gates (P1 intent clarity, P7 spec-pack
 * acceptance criteria, P8 task-pack atomicity) with deterministic, pure
 * computation. No constructor, zero dependencies, no I/O: every returned
 * field is computed from the method inputs via explicit rules, and identical
 * inputs always yield identical output (including reason ordering).
 */
final class AtlasGateSignalEvaluator
{
    public const FIELD_GATE = 'gate';
    public const FIELD_INTENT = 'intent';
    public const SCHEMA_VERSION = 'atlas.aaeos.gate_signal.v1';

    public const GATE_INTENT_CLARITY = 'intent_clarity_score_min_0_8';

    public const GATE_SPEC_PACK = 'spec_pack_acceptance_criteria_min_3';

    public const GATE_TASK_PACK = 'task_pack_atomic_true_for_each';

    public const INTENT_CLARITY_THRESHOLD = 0.8;

    public const SPEC_PACK_MIN_CRITERIA = 3;

    /**
     * Weights for the four clarity sub-signals. They sum to 1.0 so that a
     * fully resolved, bounded, unambiguous, fully-answered intent computes a
     * clamped score of exactly 1.0.
     */
    public const WEIGHT_RESOLVED = 0.4;

    public const WEIGHT_BOUNDED = 0.3;

    public const WEIGHT_NO_AMBIGUITY = 0.2;

    public const WEIGHT_NO_MISSING = 0.1;

    /**
     * Ambiguity reaches a zero sub-score at this many tokens; below it the
     * penalty scales linearly.
     */
    public const AMBIGUITY_SATURATION = 2;

    /**
     * Missing answers reach a zero sub-score at this many; a single missing
     * answer fully zeroes that sub-signal.
     */
    public const MISSING_SATURATION = 1;

    /**
     * Connectors that mark a task scope as compound (non-atomic).
     *
     * @var list<string>
     */
    public const COMPOUND_CONNECTORS = [' and ', ' & ', ' then ', ' plus ', '; '];

    public const FIELD_PASSED = 'passed';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_GATES = 'gates';
    public const FIELD_ALL_PASSED = 'all_passed';
    public const FIELD_REASONS = 'reasons';
    public const FIELD_ACCEPTANCE = 'acceptance';
    public const FIELD_ACCEPTANCE_CRITERIA = 'acceptance_criteria';
    public const FIELD_AMBIGUITY_TOKENS = 'ambiguity_tokens';
    public const FIELD_COMPUTED_VALUE = 'computed_value';
    public const FIELD_MISSING_ANSWERS = 'missing_answers';
    public const FIELD_NO_PHASE_OUTPUTS = 'no_phase_outputs';
    public const FIELD_RESOLVED_TARGET = 'resolved_target';
    public const FIELD_SCOPE = 'scope';
    public const FIELD_SCOPE_BOUNDED = 'scope_bounded';
    public const FIELD_SPEC_PACK = 'spec_pack';
    public const FIELD_TASK_PACK = 'task_pack';
    public const FIELD_TASKS = 'tasks';
    public const FIELD_TASK_ = 'task_';
    public const FIELD_ALL_TASKS_ATOMIC = 'all_tasks_atomic';
    public const FIELD_INTENT_CLEAR = 'intent_clear';
    public const FIELD_RESOLVED_TARGET_MISSING = 'resolved_target_missing';
    public const FIELD_SCOPE_UNBOUNDED = 'scope_unbounded';
    public const FIELD_TASK_PACK_EMPTY = 'task_pack_empty';

    /**
     * P1 disambiguation gate: weighted + clamped 0..1 intent-clarity score.
     *
     * @param  array<string,mixed>  $disambiguationFeatures
     * @return array{schema_version:string, gate:string, passed:bool, computed_value:float, reasons:list<string>}
     */
    public function evaluateIntentClarity(array $disambiguationFeatures): array
    {
        $resolvedTarget = $this->stringOrNull($disambiguationFeatures[self::FIELD_RESOLVED_TARGET] ?? null);
        $scopeBounded = ($disambiguationFeatures[self::FIELD_SCOPE_BOUNDED] ?? false) === true;
        $ambiguityTokens = AiStringListNormalizer::strings($disambiguationFeatures[self::FIELD_AMBIGUITY_TOKENS] ?? []);
        $missingCount = $this->missingAnswersCount($disambiguationFeatures[self::FIELD_MISSING_ANSWERS] ?? []);

        $ambiguityCount = count($ambiguityTokens);

        $resolvedComponent = $resolvedTarget !== null ? self::WEIGHT_RESOLVED : 0.0;
        $boundedComponent = $scopeBounded ? self::WEIGHT_BOUNDED : 0.0;
        $ambiguityComponent = self::WEIGHT_NO_AMBIGUITY * $this->decay($ambiguityCount, self::AMBIGUITY_SATURATION);
        $missingComponent = self::WEIGHT_NO_MISSING * $this->decay($missingCount, self::MISSING_SATURATION);

        $score = AiValueNormalizer::clampUnit($resolvedComponent + $boundedComponent + $ambiguityComponent + $missingComponent);
        $passed = $score >= self::INTENT_CLARITY_THRESHOLD;

        $reasons = [];
        if ($resolvedTarget === null) {
            $reasons[] = self::FIELD_RESOLVED_TARGET_MISSING;
        }
        if (! $scopeBounded) {
            $reasons[] = self::FIELD_SCOPE_UNBOUNDED;
        }
        if ($ambiguityCount > 0) {
            $reasons[] = 'ambiguity_tokens_present:'.$ambiguityCount;
        }
        if ($missingCount > 0) {
            $reasons[] = 'missing_answers_present:'.$missingCount;
        }
        if ($reasons === []) {
            $reasons[] = self::FIELD_INTENT_CLEAR;
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
        $raw = AiStringListNormalizer::strings($specPack[self::FIELD_ACCEPTANCE_CRITERIA] ?? []);

        $distinct = [];
        $blankCount = 0;
        foreach ($raw as $criterion) {
            $trimmed = AiValueNormalizer::trimmedStringOrNull($criterion) ?? '';
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
        $tasks = $this->taskList($taskPack[self::FIELD_TASKS] ?? []);

        $reasons = [];
        if ($tasks === []) {
            $reasons[] = self::FIELD_TASK_PACK_EMPTY;

            return $this->gateResult(self::GATE_TASK_PACK, false, false, $reasons);
        }

        $allAtomic = true;
        foreach ($tasks as $index => $task) {
            $scope = $this->stringOrNull($task[self::FIELD_SCOPE] ?? null) ?? '';
            if ($this->isCompoundScope($scope)) {
                $reasons[] = self::FIELD_TASK_.$index.'_compound_scope';
                $allAtomic = false;
            }
            if (! $this->hasAcceptance($task)) {
                $reasons[] = self::FIELD_TASK_.$index.'_missing_acceptance';
                $allAtomic = false;
            }
        }

        if ($reasons === []) {
            $reasons[] = self::FIELD_ALL_TASKS_ATOMIC;
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

        if (array_key_exists(self::FIELD_INTENT, $phaseOutputs)) {
            $gates[] = $this->evaluateIntentClarity($this->asArray($phaseOutputs[self::FIELD_INTENT]));
        }
        if (array_key_exists(self::FIELD_SPEC_PACK, $phaseOutputs)) {
            $gates[] = $this->evaluateSpecPackAcceptanceCriteria($this->asArray($phaseOutputs[self::FIELD_SPEC_PACK]));
        }
        if (array_key_exists(self::FIELD_TASK_PACK, $phaseOutputs)) {
            $gates[] = $this->evaluateTaskPackAtomicity($this->asArray($phaseOutputs[self::FIELD_TASK_PACK]));
        }

        if ($gates === []) {
            return [
                self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                self::FIELD_GATES => [],
                self::FIELD_ALL_PASSED => false,
                self::FIELD_REASONS => [self::FIELD_NO_PHASE_OUTPUTS],
            ];
        }

        $allPassed = true;
        foreach ($gates as $gate) {
            if ($gate[self::FIELD_PASSED] !== true) {
                $allPassed = false;
            }
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_GATES => $gates,
            self::FIELD_ALL_PASSED => $allPassed,
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
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_GATE => $gate,
            self::FIELD_PASSED => $passed,
            self::FIELD_COMPUTED_VALUE => $computedValue,
            self::FIELD_REASONS => array_values($reasons),
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

        return AiValueNormalizer::clampUnit(1.0 - ($count / $saturation));
    }

    private function isCompoundScope(string $scope): bool
    {
        $normalized = ' '.AiValueNormalizer::lowerTrimmedString($scope).' ';
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
        $acceptance = $task[self::FIELD_ACCEPTANCE] ?? null;

        if (is_string($acceptance)) {
            return (AiValueNormalizer::trimmedStringOrNull($acceptance) ?? '') !== '';
        }

        if (is_array($acceptance)) {
            foreach ($acceptance as $entry) {
                if (is_string($entry) && (AiValueNormalizer::trimmedStringOrNull($entry) ?? '') !== '') {
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
        return AiValueNormalizer::trimmedStringOrNull($value);
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
        return AiValueNormalizer::arrayOrEmpty($value);
    }
}
