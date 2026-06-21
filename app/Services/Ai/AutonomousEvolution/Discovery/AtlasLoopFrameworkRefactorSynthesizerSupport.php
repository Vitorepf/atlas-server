<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasLoopDecompositionOutcomeRecorder;

final class AtlasLoopFrameworkRefactorSynthesizerSupport
{
    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $perMethod
     * @return array{0: array<string,mixed>, 1: array{target_method?:string,target_method_bare?:string,cyclomatic?:int}|null, 2: int}
     */
    public function applyExtractSequenceMetadata(array $payload, AtlasLoopExtractSequencePlanner $planner, array $perMethod, int $threshold, string $targetRepoRelPath, int $maxSteps): array
    {
        $payload['extract_sequence_id'] = $planner->sequenceId($targetRepoRelPath);
        $payload['extract_sequence_plan'] = $planner->plan($perMethod, $threshold, $maxSteps);
        $payload['extract_sequence_tractable_cyclomatic'] = $threshold;
        $step = $planner->nextStep($perMethod, $threshold);
        if ($step !== null) {
            $payload['extract_sequence_step'] = $step;
        }

        return [$payload, $step, $threshold];
    }

    /**
     * @param  array<string,mixed>  $perMethod
     */
    public function resolveExtractSequenceMaxSteps(AtlasLoopExtractSequencePlanner $planner, array $perMethod, int $threshold, int $maxSteps, bool $priorReadEnabled, int $priorMinSamples, float $priorTargetRate): int
    {
        if (! $priorReadEnabled) {
            return $maxSteps;
        }

        $fullPlan = $planner->plan($perMethod, $threshold, $maxSteps);
        if ($fullPlan === []) {
            return $maxSteps;
        }

        $hash = (string) (new AtlasLoopDecompositionShapeFingerprinter)->fingerprint(['nodes' => $fullPlan])['hash'];
        $hist = (new AtlasLoopDecompositionOutcomeRecorder)->history($hash);
        if (AtlasLoopExtractSequencePlanner::priorBacksOff(
            (int) ($hist['certified'] ?? 0),
            (int) ($hist['total'] ?? 0),
            $priorMinSamples,
            $priorTargetRate,
        )) {
            return 1;
        }

        return $maxSteps;
    }

    /**
     * @param  array{target_method?:string,target_method_bare?:string,cyclomatic?:int}|null  $sequenceStep
     */
    public function buildObjectiveText(string $targetRepoRelPath, int $cyclomatic, ?string $worstMethod, ?array $sequenceStep = null, ?int $sequenceThreshold = null): string
    {
        $base = basename($targetRepoRelPath);
        $parts = $this->objectiveParts($base, $cyclomatic, $worstMethod, $sequenceStep, $sequenceThreshold);
        $where = $parts['where'];
        $methodCyclomatic = $parts['method_cyclomatic'];
        $sequenceInstruction = $parts['sequence_instruction'];

        return 'Refactor '.$base.' to REDUCE the cyclomatic complexity of its worst method, '.$where
            .' (cyclomatic '.$cyclomatic.', the file max). Drive DOWN the decision/branch count of THAT '
            .'method specifically. Prefer genuine simplifications that REMOVE decision points: replace long literal '
            .'value-mapping if/elseif or switch chains with a lookup/dispatch table, merge duplicated branch bodies, '
            .'or extract helper methods only when they are branch-free or remove enough existing branches to keep total complexity flat. '
            .'Do NOT hide boolean guard chains in arrays, lookup sets, or membership checks (`in_array(true|false, [...])`, '
            .'`[condition, ...] === [true, ...]`, or equivalent condition arrays); that is complexity metric laundering and will be rejected. '
            .'When simplifying guards that use truthiness or null-coalescing (`??`), preserve the exact falsey behavior — so the file\'s AST '
            .'max-per-method drops below '.$cyclomatic.'. '.$sequenceInstruction
            .'CRITICAL CONSTRAINT: do NOT increase the file\'s TOTAL decision/branch count. Do not add new '
            .'conditionals, guard clauses, loops, ternaries, or && / || beyond those already present — only '
            .'collapse if/elseif chains into a single lookup/dispatch table or move branches only when the '
            .'same change removes at least as much total complexity. The file\'s total cyclomatic count and '
            .'total number of branches must stay flat or fall; '
            .'only the worst method\'s SHARE of them should shrink. A version that lowers the max but adds '
            .'net branches will be REJECTED. '
            .'Edit ONLY '.$base.'; do not modify any other '
            .'file. PRESERVE behavior exactly — the existing tests must stay green.';
    }

    /**
     * @param  array{target_method?:string,target_method_bare?:string,cyclomatic?:int}|null  $sequenceStep
     * @return array{where:string,method_cyclomatic:int,sequence_instruction:string}
     */
    public function objectiveParts(string $base, int $cyclomatic, ?string $worstMethod, ?array $sequenceStep = null, ?int $sequenceThreshold = null): array
    {
        $stepMethod = is_string($sequenceStep['target_method_bare'] ?? null) && trim((string) $sequenceStep['target_method_bare']) !== ''
            ? trim((string) $sequenceStep['target_method_bare'])
            : null;
        $stepCyclomatic = isset($sequenceStep['cyclomatic']) ? max(1, (int) $sequenceStep['cyclomatic']) : null;
        $method = $stepMethod ?? $worstMethod;
        $methodCyclomatic = $stepCyclomatic ?? $cyclomatic;
        $where = $method !== null ? $base.'::'.$method.'()' : 'the file\'s most complex method';

        return [
            'where' => $where,
            'method_cyclomatic' => $methodCyclomatic,
            'sequence_instruction' => $this->sequenceInstruction($sequenceStep, $sequenceThreshold, $where, $methodCyclomatic),
        ];
    }

    /**
     * @param  array{target_method?:string,target_method_bare?:string,cyclomatic?:int}|null  $sequenceStep
     */
    public function sequenceInstruction(?array $sequenceStep, ?int $sequenceThreshold, string $where, int $methodCyclomatic): string
    {
        if ($sequenceStep === null) {
            return '';
        }

        $target = $sequenceThreshold !== null ? ' toward the tractable threshold '.$sequenceThreshold : '';

        return 'This is ONE bounded extract-sequence step'.$target.': do not redesign the whole file. '
            .'Land the smallest certifiable behavior-preserving edit that lowers '.$where.' below its current '
            .'cyclomatic '.$methodCyclomatic.' while keeping the file total cyclomatic/branch count flat or lower. ';
    }
}
