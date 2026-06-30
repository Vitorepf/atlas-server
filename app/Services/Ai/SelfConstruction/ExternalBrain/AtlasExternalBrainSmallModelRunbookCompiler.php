<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Turns a high-level origination mission into a deterministic step-by-step runbook that a
 * non-frontier model can follow to produce frontier-like task candidates without skipping
 * evidence, dedup, critique, repair, or escalation.
 *
 * CANONICAL RUNBOOK STEPS (in order):
 *   1. read_state   — load context pack, evidence, and scope description
 *   2. dedup        — grep queue + done-set for targets matching the mission
 *   3. propose       — draft task candidates from evidence only (no invention)
 *   4. critique      — challenge every candidate (is it proxy/cleanup/fake work?)
 *   5. repair        — fix a candidate that failed critique for a recoverable reason
 *                       instead of silently discarding it
 *   6. evidence_proof — confirm each surviving candidate with a file:line grep
 *   7. escalate      — hand off to a stronger model/operator when repair cannot
 *                       recover the candidate or risk exceeds this model's authority
 *   8. final_enqueue_readiness — gate: runnable acceptance + final_batch alignment
 *
 * STOP CONDITIONS (the model must abort rather than proceed/pad empty-handed):
 *   read_state               → "no_evidence_available"
 *   dedup                    → "all_candidates_duplicate_existing_work"
 *   evidence_proof           → "no_files_match_grep_pattern"
 *   final_enqueue_readiness  → "no_candidates_passed_gates_do_not_pad_with_weak_work"
 *
 * ADAPTATION (AC2 — this is not one generic prompt):
 *   risk_level (medium/high) tightens the critique instruction with a mandatory
 *   collision-sweep clause and makes the escalate step MANDATORY rather than
 *   conditional.
 *   model_weaknesses (e.g. template_farming, weak_acceptance, missing_code_search)
 *   each inject a targeted guard clause into the critique/repair instructions, so
 *   the runbook compensates for the SPECIFIC failure mode this model is prone to.
 *
 * OUTPUT:
 *   { schema, mission, scope, risk_level, model_weaknesses, runbook_steps,
 *     mandatory_artifacts, stop_conditions, escalate_mandatory,
 *     frontier_optional_deepening_steps }
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainSmallModelRunbookCompiler
{
    public const SCHEMA = 'atlas.external_brain.small_model_runbook_compiler.v1';

    private const WEAKNESS_GUARDS = [
        'template_farming' => 'Every acceptance criterion must name a concrete class/method and assertion — reject any candidate whose criteria are interchangeable templates.',
        'weak_acceptance' => 'Reject "should work"/"must pass" style phrasing; require a measurable input→output assertion.',
        'missing_code_search' => 'Do not draft a candidate until you cite at least one file:line or grep hit proving the gap is real.',
        'shallow_duplication' => 'Re-run the dedup grep against allowed_files specifically before drafting; a fresh class name over an existing file is still a duplicate.',
        'fake_confidence' => 'Strip "ensure/verify/confirm/guarantee" language; replace with the literal command or assertion that proves it.',
    ];

    private const FRONTIER_DEEPENING = [
        'What would make the Atlas loop fundamentally smarter rather than merely producing more tasks?',
        'Are there wiring gaps where built organs are never called — higher value than new organ creation?',
        'Which evidence items point to a recurring failure that no existing task has addressed in the last N cycles?',
        'If this runbook found zero net-new candidates, what does that reveal about the origination frontier?',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function compile(array $input): array
    {
        $mission = (string) ($input['mission'] ?? '');
        $scope = (string) ($input['scope'] ?? '');
        $riskLevel = (string) ($input['risk_level'] ?? 'low');
        $modelWeaknesses = is_array($input['model_weaknesses'] ?? null) ? array_map('strval', $input['model_weaknesses']) : [];

        $isElevatedRisk = $riskLevel === 'medium' || $riskLevel === 'high';

        $weaknessGuards = [];
        foreach ($modelWeaknesses as $weakness) {
            if (isset(self::WEAKNESS_GUARDS[$weakness])) {
                $weaknessGuards[] = self::WEAKNESS_GUARDS[$weakness];
            }
        }
        $weaknessClause = $weaknessGuards === [] ? '' : ' Known weakness guards for this model: '.implode(' ', $weaknessGuards);

        $critiqueInstruction = 'Challenge every candidate with: (1) Is this proxy work — cleanup, formatting, renaming — that produces no exponential value? (2) Does it require operator decision or out-of-scope files? (3) Is the acceptance criterion vague? Eliminate any candidate that fails. Record the critique for each candidate.'
            . ($isElevatedRisk ? ' Because risk_level is elevated, also run a collision sweep against sibling callers before accepting any candidate.' : '')
            . $weaknessClause;

        $repairInstruction = 'For each candidate that failed critique for a RECOVERABLE reason (vague acceptance, missing file:line, fixable scope), repair it in place: tighten the acceptance criterion, add the missing evidence reference, or trim allowed_files. Do not repair candidates that are duplicates, out-of-scope, or fundamentally proxy work — those stay eliminated.'
            . $weaknessClause;

        $escalateInstruction = $isElevatedRisk
            ? 'risk_level is medium/high: escalation to a stronger model or the operator is MANDATORY before this candidate may be enqueued, regardless of how clean repair made it look.'
            : 'If repair could not recover a candidate, or critique surfaced a judgment call this model is not authorized to make, escalate to a stronger model or the operator instead of forcing a guess.';

        $steps = [
            [
                'step_id' => 'read_state',
                'order' => 1,
                'instruction' => 'Load the context pack for this scope. List every evidence item with its freshness timestamp. If no evidence is available, emit stop: no_evidence_available and do not continue.',
                'mandatory_artifact' => 'evidence_list',
                'stop_if_missing' => ['no_evidence_available'],
            ],
            [
                'step_id' => 'dedup',
                'order' => 2,
                'instruction' => 'For each candidate target file, grep the task queue and done-set. Record which targets already have an active or completed task. Remove those targets before drafting. If all targets are duplicates, emit stop: all_candidates_duplicate_existing_work.',
                'mandatory_artifact' => 'dedup_proof',
                'stop_if_missing' => ['all_candidates_duplicate_existing_work'],
            ],
            [
                'step_id' => 'propose',
                'order' => 3,
                'instruction' => 'Draft task candidates strictly from evidence gathered in read_state. Each candidate must name: target file, objective, expected value, estimated worker-minutes, and the evidence item that motivates it. Do not invent candidates not grounded in evidence.',
                'mandatory_artifact' => 'candidate_list',
                'stop_if_missing' => [],
            ],
            [
                'step_id' => 'critique',
                'order' => 4,
                'instruction' => $critiqueInstruction,
                'mandatory_artifact' => 'critique_report',
                'stop_if_missing' => [],
            ],
            [
                'step_id' => 'repair',
                'order' => 5,
                'instruction' => $repairInstruction,
                'mandatory_artifact' => 'repair_log',
                'stop_if_missing' => [],
            ],
            [
                'step_id' => 'evidence_proof',
                'order' => 6,
                'instruction' => 'For each surviving candidate, run a grep/search to confirm the target file exists and the gap is real. Record the file:line reference. If no file matches the grep pattern, emit stop: no_files_match_grep_pattern.',
                'mandatory_artifact' => 'grep_evidence',
                'stop_if_missing' => ['no_files_match_grep_pattern'],
            ],
            [
                'step_id' => 'escalate',
                'order' => 7,
                'instruction' => $escalateInstruction,
                'mandatory_artifact' => 'escalation_decision',
                'stop_if_missing' => [],
            ],
            [
                'step_id' => 'final_enqueue_readiness',
                'order' => 8,
                'instruction' => 'For each candidate: (a) write a runnable acceptance criterion (must contain phpunit, artisan, or vendor/bin); (b) confirm allowed_files does not exceed the per-task limit. Produce the final_batch list. Only candidates passing both gates may be enqueued. If nothing survives, emit stop: no_candidates_passed_gates_do_not_pad_with_weak_work — never pad the batch with weak/borderline candidates just to produce output.',
                'mandatory_artifact' => 'final_batch',
                'stop_if_missing' => ['no_candidates_passed_gates_do_not_pad_with_weak_work'],
            ],
        ];

        $runbookSteps = [];
        $mandatoryArtifacts = [];
        $stopConditions = [];

        foreach ($steps as $step) {
            $runbookSteps[] = $step;
            $mandatoryArtifacts[] = $step['mandatory_artifact'];
            if ($step['stop_if_missing'] !== []) {
                $stopConditions[$step['step_id']] = $step['stop_if_missing'];
            }
        }

        return [
            'schema' => self::SCHEMA,
            'mission' => $mission,
            'scope' => $scope,
            'risk_level' => $riskLevel,
            'model_weaknesses' => $modelWeaknesses,
            'runbook_steps' => $runbookSteps,
            'mandatory_artifacts' => $mandatoryArtifacts,
            'stop_conditions' => $stopConditions,
            'escalate_mandatory' => $isElevatedRisk,
            'frontier_optional_deepening_steps' => self::FRONTIER_DEEPENING,
        ];
    }
}
