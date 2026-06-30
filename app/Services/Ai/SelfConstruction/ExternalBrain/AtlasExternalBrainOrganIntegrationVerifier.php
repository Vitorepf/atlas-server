<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure verifier: certifies an ExternalBrain organ as integrated only when it
 * sits in a full input → decision → output → learning CIRCUIT, never on
 * class existence, a passing unit test, or a single wiring point alone.
 *
 * The four circuit elements (ALL required for STATUS_INTEGRATED):
 *   input_source       — input_sources[organ_id] non-empty: something feeds the organ real data.
 *   decision_role       — decision_roles[organ_id] truthy: the organ's output is actually
 *                          consulted by a decision (not just computed and discarded).
 *   output_consumer     — flow_usage[organ_id] non-empty OR control_plane_exposure[organ_id]=true:
 *                          something downstream reads the organ's output.
 *   learning_feedback   — learning_feedback[organ_id] truthy: the organ's outcomes are tracked
 *                          back into compounding/outcome learning, closing the loop.
 *
 * Classification (first match wins):
 *   integrated               → all four circuit elements present.
 *   intentionally_standalone → not fully integrated, but a standalone_justifications entry exists.
 *   orphaned                 → neither of the above.
 *
 * has_tests=true and has_implementation=true are NEVER, by themselves or
 * together, sufficient for STATUS_INTEGRATED — they only feed
 * capability_island (an orphan that nonetheless "looks done").
 *
 * circuit_gaps lists the missing elements for an orphaned/standalone organ.
 * Three concrete remediation flags surface the specific failure shape:
 *   no_output_consumer   — true orphan: nothing downstream reads this organ at all.
 *   one_way_output       — has an output consumer but no learning_feedback: the organ
 *                          ships output but nothing ever reports back whether it helped.
 *   missing_failure_path — failure_handling[organ_id] is not true: the organ has no
 *                          defined behavior when its own operation fails.
 */
final class AtlasExternalBrainOrganIntegrationVerifier
{
    public const SCHEMA = 'atlas.external_brain.organ_integration_verifier.v1';

    public const STATUS_INTEGRATED = 'integrated';

    public const STATUS_ORPHANED = 'orphaned';

    public const STATUS_INTENTIONALLY_STANDALONE = 'intentionally_standalone';

    /**
     * @param  array<string,mixed>  $input  organ_inventory, input_sources, decision_roles, flow_usage,
     *                                       learning_feedback, failure_handling, control_plane_exposure,
     *                                       standalone_justifications
     * @return array<string,mixed>
     */
    public function verify(array $input): array
    {
        $inventory = is_array($input['organ_inventory'] ?? null) ? $input['organ_inventory'] : [];
        $inputSources = is_array($input['input_sources'] ?? null) ? $input['input_sources'] : [];
        $decisionRoles = is_array($input['decision_roles'] ?? null) ? $input['decision_roles'] : [];
        $flowUsage = is_array($input['flow_usage'] ?? null) ? $input['flow_usage'] : [];
        $learningFeedback = is_array($input['learning_feedback'] ?? null) ? $input['learning_feedback'] : [];
        $failureHandling = is_array($input['failure_handling'] ?? null) ? $input['failure_handling'] : [];
        $controlPlane = is_array($input['control_plane_exposure'] ?? null) ? $input['control_plane_exposure'] : [];
        $justifications = is_array($input['standalone_justifications'] ?? null) ? $input['standalone_justifications'] : [];

        $results = [];
        $integratedIds = [];
        $orphanedIds = [];
        $standaloneIds = [];

        foreach ($inventory as $organ) {
            $id = (string) ($organ['organ_id'] ?? '');
            $hasTests = (bool) ($organ['has_tests'] ?? false);
            $hasImpl = (bool) ($organ['has_implementation'] ?? false);

            $consumers = is_array($flowUsage[$id] ?? null)
                ? array_values(array_map('strval', $flowUsage[$id]))
                : [];
            $hasControlPlane = (bool) ($controlPlane[$id] ?? false);
            $standaloneReason = trim((string) ($justifications[$id] ?? ''));

            $hasInputSource = (is_array($inputSources[$id] ?? null) && $inputSources[$id] !== []);
            $hasDecisionRole = ! empty($decisionRoles[$id]);
            $hasOutputConsumer = $consumers !== [] || $hasControlPlane;
            $hasLearningFeedback = ! empty($learningFeedback[$id]);
            $hasFailureHandling = ! empty($failureHandling[$id]);

            $fullyIntegrated = $hasInputSource && $hasDecisionRole && $hasOutputConsumer && $hasLearningFeedback && $hasFailureHandling;

            if ($fullyIntegrated) {
                $status = self::STATUS_INTEGRATED;
                $integratedIds[] = $id;
            } elseif ($standaloneReason !== '') {
                $status = self::STATUS_INTENTIONALLY_STANDALONE;
                $standaloneIds[] = $id;
            } else {
                $status = self::STATUS_ORPHANED;
                $orphanedIds[] = $id;
            }

            $circuitGaps = [];
            if (! $hasInputSource) {
                $circuitGaps[] = 'no_input_source';
            }
            if (! $hasDecisionRole) {
                $circuitGaps[] = 'no_decision_role';
            }
            if (! $hasOutputConsumer) {
                $circuitGaps[] = 'no_output_consumer';
            }
            if (! $hasLearningFeedback) {
                $circuitGaps[] = 'no_learning_feedback';
            }
            if (! $hasFailureHandling) {
                $circuitGaps[] = 'no_failure_handling';
            }

            $orphanFlag = $status !== self::STATUS_INTEGRATED && ! $hasOutputConsumer;
            $oneWayOutputFlag = $status !== self::STATUS_INTEGRATED && $hasOutputConsumer && ! $hasLearningFeedback;
            $missingFailurePathFlag = $status !== self::STATUS_INTEGRATED && ! $hasFailureHandling;

            $remediation = [];
            if ($orphanFlag) {
                $remediation[] = "Wire {$id}'s output into at least one consumer (flow_usage) or expose it on the control plane.";
            }
            if ($oneWayOutputFlag) {
                $remediation[] = "Report {$id}'s outcomes back into outcome/compounding learning so the loop closes.";
            }
            if ($missingFailurePathFlag) {
                $remediation[] = "Define a failure path for {$id} (give_back, fallback, or explicit error handling) before certifying it.";
            }
            if (! $hasInputSource && $status !== self::STATUS_INTEGRATED) {
                $remediation[] = "Connect {$id} to a real input source instead of synthetic/self-generated data.";
            }
            if (! $hasDecisionRole && $status !== self::STATUS_INTEGRATED) {
                $remediation[] = "Make a decision point actually consult {$id}'s output, not just compute and discard it.";
            }

            $results[] = [
                'organ_id' => $id,
                'status' => $status,
                'has_tests' => $hasTests,
                'has_implementation' => $hasImpl,
                'capability_island' => $status === self::STATUS_ORPHANED && $hasTests && $hasImpl,
                'consumers' => $consumers,
                'control_plane_exposed' => $hasControlPlane,
                'standalone_reason' => $standaloneReason !== '' ? $standaloneReason : null,
                'has_input_source' => $hasInputSource,
                'has_decision_role' => $hasDecisionRole,
                'has_output_consumer' => $hasOutputConsumer,
                'has_learning_feedback' => $hasLearningFeedback,
                'has_failure_handling' => $hasFailureHandling,
                'circuit_gaps' => $circuitGaps,
                'orphan' => $orphanFlag,
                'one_way_output' => $oneWayOutputFlag,
                'missing_failure_path' => $missingFailurePathFlag,
                'remediation' => $remediation,
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'total_organs' => count($inventory),
            'results' => $results,
            'integrated_ids' => $integratedIds,
            'orphaned_ids' => $orphanedIds,
            'intentionally_standalone_ids' => $standaloneIds,
            'has_orphans' => $orphanedIds !== [],
        ];
    }
}
