<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Turns a high-level origination mission into a deterministic step-by-step runbook that a
 * non-frontier model can follow to produce high-quality task candidates without skipping
 * evidence, grep, critique, or validation.
 *
 * FIXED RUNBOOK STEPS (in order):
 *   1. context_read           — load context pack, evidence, and scope description
 *   2. duplicate_search       — grep queue + done-set for targets matching the mission
 *   3. candidate_drafting     — draft task candidates from evidence only (no invention)
 *   4. anti_goodhart_critique — challenge every candidate (is it proxy/cleanup/fake work?)
 *   5. evidence_proof         — prove each surviving candidate with file:line grep
 *   6. final_enqueue_readiness — gate: check runnable acceptance + final_batch alignment
 *
 * STOP CONDITIONS (when present, the model must abort rather than proceed empty-handed):
 *   context_read      → "no_evidence_available"
 *   duplicate_search  → "all_candidates_duplicate_existing_work"
 *   evidence_proof    → "no_files_match_grep_pattern"
 *
 * OUTPUT:
 *   { schema, mission, scope, runbook_steps, mandatory_artifacts,
 *     stop_conditions, frontier_optional_deepening_steps }
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainSmallModelRunbookCompiler
{
    public const SCHEMA = 'atlas.external_brain.small_model_runbook_compiler.v1';

    private const STEPS = [
        [
            'step_id'           => 'context_read',
            'order'             => 1,
            'instruction'       => 'Load the context pack for this scope. List every evidence item with its freshness timestamp. If no evidence is available, emit stop: no_evidence_available and do not continue.',
            'mandatory_artifact' => 'evidence_list',
            'stop_if_missing'   => ['no_evidence_available'],
        ],
        [
            'step_id'           => 'duplicate_search',
            'order'             => 2,
            'instruction'       => 'For each candidate target file, grep the task queue and done-set. Record which targets already have an active or completed task. Remove those targets before drafting. If all targets are duplicates, emit stop: all_candidates_duplicate_existing_work.',
            'mandatory_artifact' => 'dedup_proof',
            'stop_if_missing'   => ['all_candidates_duplicate_existing_work'],
        ],
        [
            'step_id'           => 'candidate_drafting',
            'order'             => 3,
            'instruction'       => 'Draft task candidates strictly from evidence gathered in context_read. Each candidate must name: target file, objective, expected value, estimated worker-minutes, and the evidence item that motivates it. Do not invent candidates not grounded in evidence.',
            'mandatory_artifact' => 'candidate_list',
            'stop_if_missing'   => [],
        ],
        [
            'step_id'           => 'anti_goodhart_critique',
            'order'             => 4,
            'instruction'       => 'Challenge every candidate with: (1) Is this proxy work — cleanup, formatting, renaming — that produces no exponential value? (2) Does it require operator decision or out-of-scope files? (3) Is the acceptance criterion vague? Eliminate any candidate that fails. Record the critique for each candidate.',
            'mandatory_artifact' => 'critique_report',
            'stop_if_missing'   => [],
        ],
        [
            'step_id'           => 'evidence_proof',
            'order'             => 5,
            'instruction'       => 'For each surviving candidate, run a grep/search to confirm the target file exists and the gap is real. Record the file:line reference. If no file matches the grep pattern, emit stop: no_files_match_grep_pattern.',
            'mandatory_artifact' => 'grep_evidence',
            'stop_if_missing'   => ['no_files_match_grep_pattern'],
        ],
        [
            'step_id'           => 'final_enqueue_readiness',
            'order'             => 6,
            'instruction'       => 'For each candidate: (a) write a runnable acceptance criterion (must contain phpunit, artisan, or vendor/bin); (b) confirm allowed_files does not exceed the per-task limit. Produce the final_batch list. Only candidates passing both gates may be enqueued.',
            'mandatory_artifact' => 'final_batch',
            'stop_if_missing'   => [],
        ],
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
        $scope   = (string) ($input['scope'] ?? '');

        $runbookSteps      = [];
        $mandatoryArtifacts = [];
        $stopConditions    = [];

        foreach (self::STEPS as $step) {
            $runbookSteps[] = [
                'step_id'           => $step['step_id'],
                'order'             => $step['order'],
                'instruction'       => $step['instruction'],
                'mandatory_artifact' => $step['mandatory_artifact'],
                'stop_if_missing'   => $step['stop_if_missing'],
            ];

            $mandatoryArtifacts[] = $step['mandatory_artifact'];

            if ($step['stop_if_missing'] !== []) {
                $stopConditions[$step['step_id']] = $step['stop_if_missing'];
            }
        }

        return [
            'schema'                          => self::SCHEMA,
            'mission'                         => $mission,
            'scope'                           => $scope,
            'runbook_steps'                   => $runbookSteps,
            'mandatory_artifacts'             => $mandatoryArtifacts,
            'stop_conditions'                 => $stopConditions,
            'frontier_optional_deepening_steps' => self::FRONTIER_DEEPENING,
        ];
    }
}
