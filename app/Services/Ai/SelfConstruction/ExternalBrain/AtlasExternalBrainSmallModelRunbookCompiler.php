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
 * ESCALATION TRIGGERS (AC3 — explicit, not left to the escalate step's judgment call):
 *   ambiguous_architecture      — candidate requires a design decision this model cannot resolve
 *                                 from evidence alone (multiple plausible implementation paths).
 *   missing_evidence            — read_state or evidence_replay could not produce a file:line
 *                                 reference to ground the candidate.
 *   repeated_low_yield_outputs  — this model has produced N consecutive batches with near-zero
 *                                 surviving candidates; escalate the origination strategy itself.
 *
 * PROVIDER-INDEPENDENCE (AC4): this runbook names no external paid provider anywhere in its
 * instructions — every step is followable by a local/self-hosted model. provider_independent
 * is always true; steady-state operation never requires an external paid provider call.
 *
 * OUTPUT:
 *   { schema, mission, scope, risk_level, model_weaknesses, runbook_steps,
 *     mandatory_artifacts, stop_conditions, escalate_mandatory,
 *     frontier_optional_deepening_steps, escalation_triggers, provider_independent }
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

    private const ESCALATION_TRIGGERS = [
        [
            'trigger_id' => 'ambiguous_architecture',
            'description' => 'A candidate requires a design decision this model cannot resolve from evidence alone (multiple plausible implementation paths) — escalate to a stronger model or the operator instead of guessing.',
        ],
        [
            'trigger_id' => 'missing_evidence',
            'description' => 'read_state or evidence_replay could not produce a file:line reference to ground the candidate — escalate rather than proceed on an ungrounded claim.',
        ],
        [
            'trigger_id' => 'repeated_low_yield_outputs',
            'description' => 'This model has produced consecutive batches with near-zero surviving candidates — escalate the origination strategy itself, not just the current candidate.',
        ],
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
                'step_id' => 'design_path_selection',
                'order' => 4,
                'instruction' => 'For each drafted candidate, deliberately name the ONE implementation design path you will pursue (which class, which method, which existing pattern to follow) before critiquing it. If you cannot articulate a concrete design path and are only re-searching because you ran out of ideas, that is lazy_no_more_ideas — run another evidence search or design-path pass instead of emitting a weak candidate or stopping early.',
                'mandatory_artifact' => 'design_path_selection_log',
                'stop_if_missing' => ['lazy_no_more_ideas_requires_another_search_or_design_path_pass'],
            ],
            [
                'step_id' => 'critique',
                'order' => 5,
                'instruction' => $critiqueInstruction,
                'mandatory_artifact' => 'critique_report',
                'stop_if_missing' => [],
            ],
            [
                'step_id' => 'anti_proxy_repair',
                'order' => 6,
                'instruction' => $repairInstruction,
                'mandatory_artifact' => 'repair_log',
                'stop_if_missing' => [],
            ],
            [
                'step_id' => 'evidence_replay',
                'order' => 7,
                'instruction' => 'For each surviving candidate, run a grep/search to confirm the target file exists and the gap is real. Record the file:line reference. If no file matches the grep pattern, emit stop: no_files_match_grep_pattern.',
                'mandatory_artifact' => 'grep_evidence',
                'stop_if_missing' => ['no_files_match_grep_pattern'],
            ],
            [
                'step_id' => 'escalate',
                'order' => 8,
                'instruction' => $escalateInstruction,
                'mandatory_artifact' => 'escalation_decision',
                'stop_if_missing' => [],
            ],
            // AC2: research_deepening — a required step before final_enqueue_readiness
            // that forces non-frontier models to explore alternate surfaces, retry design
            // paths, and produce explicit exhaustion_evidence before stopping with no
            // candidates. Prevents first-pass-low-yield silent exits.
            [
                'step_id' => 'research_deepening',
                'order' => 9,
                'instruction' => 'Before making the final batch decision, run a research deepening pass: (1) name at least two alternate_surfaces or design paths you did NOT try in the first pass; (2) record which design paths were retried and why they were blocked (design_path_retry_log); (3) if no candidates survived, produce exhaustion_evidence listing every surface explored, every path blocked, and why no further recovery is possible. Only after exhaustion_evidence is recorded may you proceed to the final batch gate.',
                'mandatory_artifact' => 'exhaustion_evidence',
                'stop_if_missing' => ['no_candidates_passed_gates_requires_exhaustion_evidence'],
            ],
            [
                'step_id' => 'final_enqueue_readiness',
                'order' => 10,
                'instruction' => 'For each candidate: (a) write a runnable acceptance criterion (must contain phpunit, artisan, or vendor/bin); (b) confirm allowed_files does not exceed the per-task limit. Produce the final_batch list. Only candidates passing both gates may be enqueued. If nothing survives, emit stop: no_candidates_passed_gates_do_not_pad_with_weak_work — never pad the batch with weak/borderline candidates just to produce output.',
                'mandatory_artifact' => 'final_batch',
                'stop_if_missing' => ['no_candidates_passed_gates_do_not_pad_with_weak_work'],
            ],
            [
                'step_id' => 'final_batch_self_audit',
                'order' => 11,
                'instruction' => 'Before enqueue, self-audit the assembled final_batch as a whole: confirm no two candidates target the same file, none reintroduces a proxy/cleanup pattern eliminated in critique, and every candidate still carries its design_path_selection and evidence_replay references. Record the audit outcome.',
                'mandatory_artifact' => 'final_batch_self_audit_log',
                'stop_if_missing' => [],
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
            'escalation_triggers' => self::ESCALATION_TRIGGERS,
            'provider_independent' => true,
        ];
    }
}
