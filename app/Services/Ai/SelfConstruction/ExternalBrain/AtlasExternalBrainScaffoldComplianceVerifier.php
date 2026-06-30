<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Verifies whether an external brain run actually followed the required scaffold before
 * crediting its produced tasks as high-quality.
 *
 * MANDATORY SCAFFOLD STEPS (all must pass for compliant=true):
 *   evidence_intake              — artifact "evidence_list" must be non-empty
 *   duplicate_search             — artifact "dedup_proof" must be present
 *   semantic_dedup_proof         — artifact "semantic_dedup_proof" must be non-empty
 *   critique_pass                — artifact "critique_report" must be present
 *   implementability_simulation  — artifact "implementability_simulation" must be non-empty
 *   runnable_acceptance_proof    — every credited task must have ≥1 runnable criterion
 *   final_queue_validation       — artifact "final_batch" must be present and non-empty
 *
 * RUNNABLE CRITERION: acceptance criterion string must contain at least one of:
 *   phpunit, artisan, vendor/bin, ./vendor
 *
 * WEAK ARTIFACT: artifact present but empty (empty list, empty string, empty array)
 *
 * TASK CREDIT LOGIC:
 *   credited  — task has ≥1 runnable acceptance criterion AND is present in final_batch
 *   refused   — task lacks runnable proof OR is absent from final_batch
 *
 * OUTPUT:
 *   { schema, compliant, missing_steps, weak_artifacts, credited_tasks, refused_tasks }
 *
 * PURE / DETERMINISTIC / NO I/O.
 */
final class AtlasExternalBrainScaffoldComplianceVerifier
{
    public const SCHEMA = 'atlas.external_brain.scaffold_compliance_verifier.v1';

    private const STEP_EVIDENCE_INTAKE             = 'evidence_intake';
    private const STEP_DUPLICATE_SEARCH            = 'duplicate_search';
    private const STEP_SEMANTIC_DEDUP_PROOF        = 'semantic_dedup_proof';
    private const STEP_CRITIQUE_PASS               = 'critique_pass';
    private const STEP_IMPLEMENTABILITY_SIMULATION = 'implementability_simulation';
    private const STEP_RUNNABLE_ACCEPTANCE_PROOF   = 'runnable_acceptance_proof';
    private const STEP_FINAL_QUEUE_VALIDATION      = 'final_queue_validation';

    private const RUNNABLE_INDICATORS = ['phpunit', 'artisan', 'vendor/bin', './vendor'];

    /** Mandatory sections for a small-model amplification scaffold to be trusted. */
    private const REQUIRED_AMPLIFICATION_SECTIONS = ['replay', 'critique', 'anti_proxy', 'escalation', 'evidence_capture'];

    private const AMPLIFICATION_REPAIR_HINTS = [
        'replay' => 'add_replay_section_that_re-runs_the_attempt_deterministically_against_recorded_inputs',
        'critique' => 'add_critique_section_with_at_least_one_independent_adversarial_reviewer',
        'anti_proxy' => 'add_anti_proxy_section_that_checks_for_proxy_metric_gaming_not_just_green_status',
        'escalation' => 'add_escalation_section_naming_the_frontier_tier_fallback_and_its_trigger',
        'evidence_capture' => 'add_evidence_capture_section_that_records_runnable_proof_for_every_credited_task',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function verify(array $input): array
    {
        $run            = is_array($input['run'] ?? null) ? $input['run'] : [];
        $artifacts      = is_array($run['artifacts'] ?? null) ? $run['artifacts'] : [];
        $producedTasks  = is_array($run['produced_tasks'] ?? null) ? $run['produced_tasks'] : [];

        $missingSteps  = [];
        $weakArtifacts = [];

        // evidence_intake: evidence_list must exist and be non-empty.
        $evidenceList = $artifacts['evidence_list'] ?? null;
        if ($evidenceList === null) {
            $missingSteps[] = self::STEP_EVIDENCE_INTAKE;
        } elseif ($this->isEmpty($evidenceList)) {
            $weakArtifacts[] = ['artifact_name' => 'evidence_list', 'reason' => 'evidence_list is present but empty'];
        }

        // duplicate_search: dedup_proof must exist.
        $dedupProof = $artifacts['dedup_proof'] ?? null;
        if ($dedupProof === null) {
            $missingSteps[] = self::STEP_DUPLICATE_SEARCH;
        } elseif ($this->isEmpty($dedupProof)) {
            $weakArtifacts[] = ['artifact_name' => 'dedup_proof', 'reason' => 'dedup_proof is present but empty'];
        }

        // semantic_dedup_proof: semantic_dedup_proof must exist and be non-empty.
        $semanticDedupProof = $artifacts['semantic_dedup_proof'] ?? null;
        if ($semanticDedupProof === null) {
            $missingSteps[] = self::STEP_SEMANTIC_DEDUP_PROOF;
        } elseif ($this->isEmpty($semanticDedupProof)) {
            $weakArtifacts[] = ['artifact_name' => 'semantic_dedup_proof', 'reason' => 'semantic_dedup_proof is present but empty'];
        }

        // critique_pass: critique_report must exist.
        $critiqueReport = $artifacts['critique_report'] ?? null;
        if ($critiqueReport === null) {
            $missingSteps[] = self::STEP_CRITIQUE_PASS;
        } elseif ($this->isEmpty($critiqueReport)) {
            $weakArtifacts[] = ['artifact_name' => 'critique_report', 'reason' => 'critique_report is present but empty'];
        }

        // implementability_simulation: must exist and be non-empty.
        $implSimulation = $artifacts['implementability_simulation'] ?? null;
        if ($implSimulation === null) {
            $missingSteps[] = self::STEP_IMPLEMENTABILITY_SIMULATION;
        } elseif ($this->isEmpty($implSimulation)) {
            $weakArtifacts[] = ['artifact_name' => 'implementability_simulation', 'reason' => 'implementability_simulation is present but empty'];
        }

        // final_queue_validation: final_batch must exist and be non-empty.
        $finalBatch = is_array($artifacts['final_batch'] ?? null) ? $artifacts['final_batch'] : null;
        if ($finalBatch === null) {
            $missingSteps[] = self::STEP_FINAL_QUEUE_VALIDATION;
        } elseif ($finalBatch === []) {
            $weakArtifacts[] = ['artifact_name' => 'final_batch', 'reason' => 'final_batch is present but contains no tasks'];
        }

        // Build final_batch index for O(1) lookup.
        $finalBatchIds = [];
        if ($finalBatch !== null) {
            foreach ($finalBatch as $batchItem) {
                if (isset($batchItem['task_id'])) {
                    $finalBatchIds[(string) $batchItem['task_id']] = true;
                } elseif (is_string($batchItem)) {
                    $finalBatchIds[$batchItem] = true;
                }
            }
        }

        // Evaluate each produced task.
        $creditedTasks = [];
        $refusedTasks  = [];
        $anyTaskRefused = false;

        foreach ($producedTasks as $task) {
            if (! is_array($task) || ! isset($task['task_id'])) {
                continue;
            }

            $taskId   = (string) $task['task_id'];
            $criteria = is_array($task['acceptance_criteria'] ?? null) ? $task['acceptance_criteria'] : [];

            $hasRunnable = false;
            foreach ($criteria as $criterion) {
                if ($this->isRunnableCriterion((string) $criterion)) {
                    $hasRunnable = true;
                    break;
                }
            }

            $inFinalBatch = $finalBatch === null || isset($finalBatchIds[$taskId]);

            if ($hasRunnable && $inFinalBatch) {
                $creditedTasks[] = $taskId;
            } else {
                $reasons = [];
                if (! $hasRunnable) {
                    $reasons[] = 'no runnable acceptance criterion (must contain phpunit/artisan/vendor/bin)';
                }
                if (! $inFinalBatch) {
                    $reasons[] = 'task not present in final_batch';
                }
                $refusedTasks[]  = ['task_id' => $taskId, 'reason' => implode('; ', $reasons)];
                $anyTaskRefused  = true;
            }
        }

        // runnable_acceptance_proof step fails if any task was refused for lack of runnable proof.
        $hasRunnableStepFailure = false;
        foreach ($refusedTasks as $rt) {
            if (str_contains($rt['reason'], 'runnable')) {
                $hasRunnableStepFailure = true;
                break;
            }
        }
        if ($hasRunnableStepFailure) {
            $missingSteps[] = self::STEP_RUNNABLE_ACCEPTANCE_PROOF;
        }

        $compliant = $missingSteps === [] && $weakArtifacts === [] && ! $anyTaskRefused;

        return [
            'schema'          => self::SCHEMA,
            'compliant'       => $compliant,
            'missing_steps'   => $missingSteps,
            'weak_artifacts'  => $weakArtifacts,
            'credited_tasks'  => $creditedTasks,
            'refused_tasks'   => $refusedTasks,
        ];
    }

    /**
     * Verifies a small-model amplification scaffold definition has all five
     * mandatory sections (replay, critique, anti_proxy, escalation,
     * evidence_capture) before it can be trusted. A missing section is
     * always blocking — there is no advisory tier.
     *
     * @param  array<string,mixed>  $scaffold
     * @return array<string,mixed>
     */
    public function verifyAmplificationScaffold(array $scaffold): array
    {
        $sections = is_array($scaffold['sections'] ?? null) ? $scaffold['sections'] : [];

        $missingSections = [];
        $repairHints = [];
        foreach (self::REQUIRED_AMPLIFICATION_SECTIONS as $section) {
            $present = array_key_exists($section, $sections) && ! $this->isEmpty($sections[$section]);
            if (! $present) {
                $missingSections[] = $section;
                $repairHints[$section] = self::AMPLIFICATION_REPAIR_HINTS[$section];
            }
        }

        $complianceStatus = $missingSections === [] ? 'trusted' : 'blocked';

        return [
            'schema' => self::SCHEMA,
            'compliance_status' => $complianceStatus,
            'trusted' => $complianceStatus === 'trusted',
            'required_sections' => self::REQUIRED_AMPLIFICATION_SECTIONS,
            'missing_sections' => $missingSections,
            'repair_hints' => $repairHints,
        ];
    }

    private function isEmpty(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        return (string) $value === '';
    }

    private function isRunnableCriterion(string $criterion): bool
    {
        $lower = strtolower($criterion);
        foreach (self::RUNNABLE_INDICATORS as $indicator) {
            if (str_contains($lower, $indicator)) {
                return true;
            }
        }

        return false;
    }
}
