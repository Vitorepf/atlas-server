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
 *
 * orphan_reason, required_wiring and evidence_refs are emitted on every organ
 * result so callers can diagnose WHY an organ is orphaned and WHAT wiring paths
 * need to be established.
 */
final class AtlasExternalBrainOrganIntegrationVerifier
{
    public const SCHEMA = 'atlas.external_brain.organ_integration_verifier.v1';

    public const STATUS_INTEGRATED = 'integrated';

    public const STATUS_ORPHANED = 'orphaned';

    public const STATUS_INTENTIONALLY_STANDALONE = 'intentionally_standalone';

    /** AC2: an organ with SOME but not all circuit legs wired — more than an orphan, less than integrated. */
    public const STATUS_PARTIALLY_INTEGRATED = 'partially_integrated';

    /** AC2: emits something a consumer reads, but has no real input and gates no decision — a facade. */
    public const STATUS_PROXY_ONLY = 'proxy_only';

    /** AC2: caller-flagged stale evidence — was integrated once, but its proof is no longer current. */
    public const STATUS_STALE = 'stale';

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
        $proofPaths = is_array($input['proof_paths'] ?? null) ? $input['proof_paths'] : [];
        $knowledgeSync = is_array($input['knowledge_sync'] ?? null) ? $input['knowledge_sync'] : [];
        $staleEvidence = is_array($input['stale_evidence'] ?? null) ? $input['stale_evidence'] : [];
        // Opt-in: existing callers that don't yet supply proof_paths/knowledge_sync keep the
        // original 5-leg circuit definition of "integrated" unchanged.
        $requireProofAndKnowledgeSync = (bool) ($input['require_proof_and_knowledge_sync'] ?? false);

        $results = [];
        $integratedIds = [];
        $orphanedIds = [];
        $standaloneIds = [];
        $partiallyIntegratedIds = [];
        $proxyOnlyIds = [];
        $staleIds = [];

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
            $hasProofPath = ! empty($proofPaths[$id]);
            $hasKnowledgeSync = ! empty($knowledgeSync[$id]);
            $isStale = ! empty($staleEvidence[$id]);

            // Build evidence_refs (AC3/AC4): concrete references to wiring paths that exist.
            $evidenceRefs = [];
            if ($hasInputSource && is_array($inputSources[$id])) {
                foreach ($inputSources[$id] as $src) {
                    $evidenceRefs[] = "input_source:{$src}";
                }
            }
            if ($hasDecisionRole) {
                $evidenceRefs[] = "decision_role:{$decisionRoles[$id]}";
            }
            if ($hasOutputConsumer) {
                foreach ($consumers as $c) {
                    $evidenceRefs[] = "flow_usage:{$c}";
                }
                if ($hasControlPlane) {
                    $evidenceRefs[] = 'control_plane_exposure:true';
                }
            }
            if ($hasLearningFeedback) {
                $evidenceRefs[] = 'learning_feedback:present';
            }
            if ($hasFailureHandling) {
                $evidenceRefs[] = 'failure_handling:present';
            }
            if ($hasProofPath) {
                $evidenceRefs[] = "proof_path:{$proofPaths[$id]}";
            }
            if ($hasKnowledgeSync) {
                $evidenceRefs[] = "knowledge_sync:{$knowledgeSync[$id]}";
            }

            $coreCircuitOk = $hasInputSource && $hasDecisionRole && $hasOutputConsumer && $hasLearningFeedback && $hasFailureHandling;
            $fullyIntegrated = $coreCircuitOk && (! $requireProofAndKnowledgeSync || ($hasProofPath && $hasKnowledgeSync));

            $presentLegCount = count(array_filter([
                $hasInputSource, $hasDecisionRole, $hasOutputConsumer, $hasLearningFeedback, $hasFailureHandling,
            ]));
            // AC2: a proxy is a facade — it emits something a consumer reads, but has no real
            // input and gates no decision, so its "output" is never actually earned.
            $isProxyOnly = ! $fullyIntegrated && $hasOutputConsumer && ! $hasInputSource && ! $hasDecisionRole;

            if ($isStale) {
                // Explicit stale-evidence flag always wins — a circuit that LOOKS wired can no
                // longer be trusted as "integrated" once its proof is known to be out of date.
                $status = self::STATUS_STALE;
                $staleIds[] = $id;
            } elseif ($fullyIntegrated) {
                $status = self::STATUS_INTEGRATED;
                $integratedIds[] = $id;
            } elseif ($standaloneReason !== '') {
                $status = self::STATUS_INTENTIONALLY_STANDALONE;
                $standaloneIds[] = $id;
            } elseif ($isProxyOnly) {
                $status = self::STATUS_PROXY_ONLY;
                $proxyOnlyIds[] = $id;
            } elseif ($presentLegCount > 0) {
                $status = self::STATUS_PARTIALLY_INTEGRATED;
                $partiallyIntegratedIds[] = $id;
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
            if ($requireProofAndKnowledgeSync && ! $hasProofPath) {
                $circuitGaps[] = 'no_proof_path';
            }
            if ($requireProofAndKnowledgeSync && ! $hasKnowledgeSync) {
                $circuitGaps[] = 'no_knowledge_sync';
            }

            // orphan_reason (AC4): human-readable explanation when the organ is not integrated.
            $orphanReason = null;
            if ($status !== self::STATUS_INTEGRATED) {
                $gapList = implode(', ', $circuitGaps);
                $orphanReason = $gapList !== ''
                    ? "Organ '{$id}' is not wired into a real decision path, learning loop, or control plane. Missing: {$gapList}."
                    : "Organ '{$id}' has no circuit gaps but is not integrated (status={$status}).";
            }

            // required_wiring (AC4): static list of wiring paths the verifier checks for integration.
            $requiredWiring = $requireProofAndKnowledgeSync
                ? ['input_source', 'decision_role', 'output_consumer', 'learning_feedback', 'failure_handling', 'proof_path', 'knowledge_sync']
                : ['input_source', 'decision_role', 'output_consumer', 'learning_feedback', 'failure_handling'];

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

            // AC4: an orphan/proxy_only organ with NO implementation and NO tests behind it isn't
            // worth repairing — it's dead scaffolding. Recommend retirement instead of wiring work.
            $retirementCandidate = in_array($status, [self::STATUS_ORPHANED, self::STATUS_PROXY_ONLY], true)
                && ! $hasTests && ! $hasImpl;
            $retirementReason = null;
            if ($retirementCandidate) {
                $retirementReason = "{$id} has no implementation, no tests, and no wiring — retire it rather than repair it.";
                $remediation = [$retirementReason];
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
                'has_proof_path' => $hasProofPath,
                'has_knowledge_sync' => $hasKnowledgeSync,
                'retirement_candidate' => $retirementCandidate,
                'retirement_reason' => $retirementReason,
                'has_failure_handling' => $hasFailureHandling,
                'circuit_gaps' => $circuitGaps,
                'orphan' => $orphanFlag,
                'one_way_output' => $oneWayOutputFlag,
                'missing_failure_path' => $missingFailurePathFlag,
                'remediation' => $remediation,
                // New AC4 fields
                'orphan_reason' => $orphanReason,
                'required_wiring' => $requiredWiring,
                'evidence_refs' => $evidenceRefs,
            ];
        }

        return [
            'schema_version' => self::SCHEMA,
            'total_organs' => count($inventory),
            'results' => $results,
            'integrated_ids' => $integratedIds,
            'orphaned_ids' => $orphanedIds,
            'intentionally_standalone_ids' => $standaloneIds,
            'partially_integrated_ids' => $partiallyIntegratedIds,
            'proxy_only_ids' => $proxyOnlyIds,
            'stale_ids' => $staleIds,
            'has_orphans' => $orphanedIds !== [],
        ];
    }
}
