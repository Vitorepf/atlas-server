<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure joiner — bridges an authored task SPEC with its muscle OUTCOME into one compact learning record.
 * Answers "which specs actually deliver", not just "we created tasks". Never reads files, git, the
 * queue, or calls a provider; everything is supplied as input facts.
 *
 * INPUT:
 *   $spec    = { task_packet_id, task_family, allowed_files:list<string>, acceptance_criteria:list<string>,
 *                worker?:string, model?:string }
 *   $outcome = { status?:'success'|'give_back'|null, commit_sha?:string, evidence?:array<string,mixed>,
 *                give_back_reason?:string, root_cause_hint?:string, decision_changed?:bool }
 *
 * OUTPUT (FACTS only):
 *   pending  — outcome status missing → { status:'pending_outcome', task_family, task_shape }
 *   success  — { status:'success', success:true, evidence_strength:float, task_shape, commit_evidence,
 *                worker, model, decision_changed }
 *   give_back — { status:'give_back', success:false, give_back_reason, root_cause_hint, repair_candidate,
 *                 task_shape, worker, model, decision_changed }
 *
 * task_shape: { task_family, allowed_files_count, has_test_file, acceptance_criteria_count }
 *
 * repair_candidate: true when the give_back root_cause_hint/give_back_reason names a SPEC-shape defect
 * (scope, acceptance, objective, ambiguity, missing test path, contradiction) rather than a worker error.
 *
 * Pure: no I/O, no provider calls, no git/queue access.
 */
final class AtlasExternalBrainSpecOutcomeTraceJoiner
{
    public const SCHEMA = 'atlas.self_construction.external_brain.spec_outcome_trace_joiner.v1';

    public const STATUS_PENDING = 'pending_outcome';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_GIVE_BACK = 'give_back';

    private const SPEC_SHAPE_DEFECT_PATTERN = '/scope|acceptance|objective|ambig|missing_test|contradict|malformed/i';

    /** Evidence keys whose presence increases the strength of a success record. */
    private const EVIDENCE_KEYS = ['tests_or_gates_result', 'implementation_notes', 'commit_sha'];

    /**
     * @param  array<string,mixed>  $spec
     * @param  array<string,mixed>  $outcome
     * @return array<string,mixed>
     */
    public function join(array $spec, array $outcome): array
    {
        $taskShape = $this->taskShape($spec);
        $worker = (string) ($outcome['worker'] ?? $spec['worker'] ?? '');
        $model = (string) ($outcome['model'] ?? $spec['model'] ?? '');
        $decisionChanged = (bool) ($outcome['decision_changed'] ?? false);

        $status = $outcome['status'] ?? null;
        if ($status === null || $status === '') {
            return [
                'schema' => self::SCHEMA,
                'status' => self::STATUS_PENDING,
                'task_family' => $taskShape['task_family'],
                'task_shape' => $taskShape,
            ];
        }

        $status = (string) $status;

        if ($status === self::STATUS_SUCCESS) {
            $evidence = is_array($outcome['evidence'] ?? null) ? $outcome['evidence'] : [];
            $commitSha = (string) ($outcome['commit_sha'] ?? '');
            if ($commitSha !== '') {
                $evidence['commit_sha'] = $commitSha;
            }

            return [
                'schema' => self::SCHEMA,
                'status' => self::STATUS_SUCCESS,
                'success' => true,
                'evidence_strength' => $this->evidenceStrength($evidence),
                'commit_evidence' => $commitSha,
                'task_shape' => $taskShape,
                'worker' => $worker,
                'model' => $model,
                'decision_changed' => $decisionChanged,
            ];
        }

        if ($status === self::STATUS_GIVE_BACK) {
            $giveBackReason = (string) ($outcome['give_back_reason'] ?? '');
            $rootCauseHint = (string) ($outcome['root_cause_hint'] ?? '');
            $repairCandidate = $this->isSpecShapeDefect($giveBackReason) || $this->isSpecShapeDefect($rootCauseHint);

            return [
                'schema' => self::SCHEMA,
                'status' => self::STATUS_GIVE_BACK,
                'success' => false,
                'give_back_reason' => $giveBackReason,
                'root_cause_hint' => $rootCauseHint,
                'repair_candidate' => $repairCandidate,
                'task_shape' => $taskShape,
                'worker' => $worker,
                'model' => $model,
                'decision_changed' => $decisionChanged,
            ];
        }

        // Unrecognized status — treat as pending so an unknown shape never gets silently judged success/fail.
        return [
            'schema' => self::SCHEMA,
            'status' => self::STATUS_PENDING,
            'task_family' => $taskShape['task_family'],
            'task_shape' => $taskShape,
        ];
    }

    /**
     * @param  array<string,mixed>  $spec
     * @return array{task_family:string, allowed_files_count:int, has_test_file:bool, acceptance_criteria_count:int}
     */
    private function taskShape(array $spec): array
    {
        $allowedFiles = array_values(array_map('strval', (array) ($spec['allowed_files'] ?? [])));
        $hasTestFile = false;
        foreach ($allowedFiles as $f) {
            $norm = ltrim(str_replace('\\', '/', trim($f)), '/');
            if (str_starts_with($norm, 'tests/') || str_contains($norm, '/tests/') || str_ends_with($norm, 'Test.php')) {
                $hasTestFile = true;
                break;
            }
        }

        return [
            'task_family' => (string) ($spec['task_family'] ?? ''),
            'allowed_files_count' => count($allowedFiles),
            'has_test_file' => $hasTestFile,
            'acceptance_criteria_count' => count((array) ($spec['acceptance_criteria'] ?? [])),
        ];
    }

    /**
     * @param  array<string,mixed>  $evidence
     */
    private function evidenceStrength(array $evidence): float
    {
        $present = 0;
        foreach (self::EVIDENCE_KEYS as $key) {
            if (! empty($evidence[$key])) {
                $present++;
            }
        }

        return count(self::EVIDENCE_KEYS) > 0 ? round($present / count(self::EVIDENCE_KEYS), 2) : 0.0;
    }

    private function isSpecShapeDefect(string $text): bool
    {
        return $text !== '' && preg_match(self::SPEC_SHAPE_DEFECT_PATTERN, $text) === 1;
    }
}
