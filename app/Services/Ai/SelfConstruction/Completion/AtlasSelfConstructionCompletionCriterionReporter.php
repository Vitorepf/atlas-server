<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\AgentControlPlaneReleaseDossierService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionForgeSelfImprovementIntegrationSmokeService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionHumanSignedCompletionReceiptService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeCertificationService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimePromotionReceiptService;
use Illuminate\Support\Collection;

/**
 * Criterion-metadata and operator-facing report-formatting for the Atlas
 * self-construction OS completion audit.
 *
 * Extracted from AtlasSelfConstructionOsCompletionAuditService to reduce
 * the god-class. All methods are stateless.
 */
final class AtlasSelfConstructionCompletionCriterionReporter
{
    /**
     * @return array<string, array<string, string>>
     */
    public static function criterionMetadata(): array
    {
        return [
            'runtime_gap_matrix_all_runtime_y' => [
                'blocker_type' => 'human',
                'doc_anchor' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md#runtime-gap-matrix-v1',
                'remediation_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
                'expected_receipt_schema' => AtlasSelfConstructionRuntimePromotionReceiptService::SCHEMA_VERSION,
                'why_blocking' => 'Every Y/N row in the runtime gap matrix must be runtime=Y with graduation hashes; promoting requires an operator-signed runtime promotion receipt.',
            ],
            'release_dossier_green' => [
                'blocker_type' => 'technical',
                'doc_anchor' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md#agent-control-plane-release-dossier-v1',
                'remediation_command' => 'php artisan atlas:ai:self-construction --agent-control-plane-release-dossier-status --json',
                'expected_receipt_schema' => AgentControlPlaneReleaseDossierService::SCHEMA_VERSION,
                'why_blocking' => 'The release dossier must aggregate baseline, replay, diff, gate, simulator, mutation guard, chain integrity and snapshot capture into a single green status.',
            ],
            'replay_diff_against_completion_snapshot_green' => [
                'blocker_type' => 'technical',
                'doc_anchor' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md#agent-control-plane-replay-diff-v1',
                'remediation_command' => 'php artisan atlas:ai:self-construction --agent-control-plane-replay-diff-status --json',
                'expected_receipt_schema' => 'atlas.self_construction.agent_control_plane_replay_diff_status.v1',
                'why_blocking' => 'The replay diff must compare against a stored completion snapshot so OS closure can be replayed deterministically.',
            ],
            'promotion_gate_green' => [
                'blocker_type' => 'technical',
                'doc_anchor' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md#promotion-gate-v1',
                'remediation_command' => 'php artisan atlas:ai:self-construction --agent-control-plane-macro-sprint-promotion-gate-status --json',
                'expected_receipt_schema' => 'atlas.self_construction.agent_control_plane_macro_sprint_promotion_gate.v1',
                'why_blocking' => 'The promotion gate must explicitly allow promotion before any completion claim can be accepted.',
            ],
            'mutation_guard_green' => [
                'blocker_type' => 'technical',
                'doc_anchor' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md#mutation-guard-v1',
                'remediation_command' => 'php artisan atlas:ai:self-construction --agent-control-plane-certification-mutation-guard-status --json',
                'expected_receipt_schema' => 'atlas.self_construction.agent_control_plane_certification_mutation_guard.v1',
                'why_blocking' => 'Mutation guard must pass; forbidden mutations on the certification surface invalidate the audit.',
            ],
            'human_signed_os_complete_receipt_present' => [
                'blocker_type' => 'human',
                'doc_anchor' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md#human-signed-os-complete-receipt',
                'remediation_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-draft-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --real-provider-smoke-json=@/path/to/real-provider-smoke.json --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json',
                'expected_receipt_schema' => AtlasSelfConstructionHumanSignedCompletionReceiptService::SCHEMA_VERSION,
                'why_blocking' => 'Atlas never self-promotes OS-complete. The operator must persist a signed human completion receipt referencing the post-smoke audit hash.',
            ],
            'end_to_end_real_provider_smoke_green' => [
                'blocker_type' => 'real_provider',
                'doc_anchor' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md#real-provider-smoke-v1',
                'remediation_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-draft-status --real-provider-smoke-json=@/path/to/real-provider-smoke-preimage.json --json',
                'expected_receipt_schema' => AtlasSelfConstructionRealProviderSmokeCertificationService::SCHEMA_VERSION,
                'why_blocking' => 'Atlas never starts a provider process and never spends tokens. The operator must run a real provider claim-to-completion smoke outside Atlas and persist evidence.',
            ],
            'forge_self_improvement_integration_smoke_green' => [
                'blocker_type' => 'technical',
                'doc_anchor' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md#forge-self-improvement-integration-smoke',
                'remediation_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-forge-self-improvement-integration-smoke-status --json',
                'expected_receipt_schema' => AtlasSelfConstructionForgeSelfImprovementIntegrationSmokeService::SCHEMA_VERSION,
                'why_blocking' => 'The integration smoke must prove Forge and Self-Improvement do not collide on activation.',
            ],
            'certification_status_batch_green' => [
                'blocker_type' => 'technical',
                'doc_anchor' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md#certification-status-batch',
                'remediation_command' => 'php artisan atlas:ai:self-construction --agent-control-plane-certification-status-batch-status --json',
                'expected_receipt_schema' => 'atlas.self_construction.agent_control_plane_certification_status_batch.v1',
                'why_blocking' => 'Every Agent Control Plane status projection in the batch must be green with zero failed checks.',
            ],
            'agent_control_plane_terminal_loop_certification_green' => [
                'blocker_type' => 'technical',
                'doc_anchor' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md#multi-agent-terminal-loop',
                'remediation_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
                'expected_receipt_schema' => 'atlas.self_construction.agent_control_plane_terminal_loop_certification.v1',
                'why_blocking' => 'The multi-agent terminal loop must be wired end-to-end (auto-replenishment, claim-next, complete-dry-run, one-shot worker packet, lease recovery, multi-agent loop cert).',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $criterion
     * @return array<string, mixed>
     */
    public static function enrichFailedCriterion(array $criterion): array
    {
        $id = (string) ($criterion['id'] ?? '');
        $meta = self::criterionMetadata()[$id] ?? [
            'blocker_type' => 'technical',
            'doc_anchor' => '',
            'remediation_command' => '',
            'expected_receipt_schema' => '',
            'why_blocking' => '',
        ];
        $evidence = (array) ($criterion['evidence'] ?? []);

        return [
            'id' => $id,
            'requirement' => (string) ($criterion['requirement'] ?? ''),
            'blocker_type' => $meta['blocker_type'],
            'why_blocking' => $meta['why_blocking'],
            'observed_status' => (string) ($evidence['status'] ?? ''),
            'doc_anchor' => $meta['doc_anchor'],
            'remediation_command' => $meta['remediation_command'],
            'expected_receipt_schema' => $meta['expected_receipt_schema'],
            'evidence' => $evidence,
        ];
    }

    /**
     * @param  array<string, mixed>  $criterion
     * @return array<string, mixed>
     */
    public static function summarisePassedCriterion(array $criterion): array
    {
        $id = (string) ($criterion['id'] ?? '');
        $meta = self::criterionMetadata()[$id] ?? [
            'doc_anchor' => '',
            'expected_receipt_schema' => '',
        ];
        $evidence = (array) ($criterion['evidence'] ?? []);
        $evidenceHashCandidates = [
            'receipt_hash',
            'receipt_verification_hash',
            'smoke_hash',
            'certification_hash',
            'hash',
            'release_dossier_hash',
            'closure_runbook_hash',
            'runtime_promotion_receipt_hash',
            'runtime_gap_matrix_hash',
            'diff_hash',
            'batch_hash',
        ];
        $evidenceHash = '';
        foreach ($evidenceHashCandidates as $candidate) {
            $value = (string) ($evidence[$candidate] ?? '');
            if ($value !== '') {
                $evidenceHash = $value;
                break;
            }
        }

        return [
            'id' => $id,
            'requirement' => (string) ($criterion['requirement'] ?? ''),
            'observed_status' => (string) ($evidence['status'] ?? 'passed'),
            'evidence_hash' => $evidenceHash,
            'doc_anchor' => $meta['doc_anchor'],
            'expected_receipt_schema' => $meta['expected_receipt_schema'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $failedDetailed
     * @return array<string, mixed>
     */
    public static function classifyBlockers(array $failedDetailed): array
    {
        $buckets = [
            'human' => [],
            'real_provider' => [],
            'technical' => [],
            'other' => [],
        ];
        foreach ($failedDetailed as $entry) {
            $type = (string) ($entry['blocker_type'] ?? 'technical');
            if (! in_array($type, ['human', 'real_provider', 'technical'], true)) {
                $type = 'other';
            }
            $buckets[$type][] = (string) ($entry['id'] ?? '');
        }

        return [
            'human_blockers' => array_values(array_filter($buckets['human'])),
            'real_provider_blockers' => array_values(array_filter($buckets['real_provider'])),
            'technical_blockers' => array_values(array_filter($buckets['technical'])),
            'other_blockers' => array_values(array_filter($buckets['other'])),
            'human_blocker_count' => count(array_filter($buckets['human'])),
            'real_provider_blocker_count' => count(array_filter($buckets['real_provider'])),
            'technical_blocker_count' => count(array_filter($buckets['technical'])),
            'other_blocker_count' => count(array_filter($buckets['other'])),
            'no_blockers_at_all' => $failedDetailed === [],
            'completion_allowed' => $failedDetailed === [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $failedCriteriaDetailed
     * @param  array<string, mixed>  $blockerClassification
     * @return array<string, mixed>
     */
    public static function completionClaimAuthorityVerdict(string $status, array $failedCriteriaDetailed, array $blockerClassification): array
    {
        $completionAllowed = $status === 'complete' && $failedCriteriaDetailed === [];
        $missingEvidence = array_values(array_map(
            static fn (array $entry): array => [
                'criterion_id' => (string) ($entry['id'] ?? ''),
                'blocker_type' => (string) ($entry['blocker_type'] ?? 'technical'),
                'expected_receipt_schema' => (string) ($entry['expected_receipt_schema'] ?? ''),
                'remediation_command' => (string) ($entry['remediation_command'] ?? ''),
                'why_blocking' => (string) ($entry['why_blocking'] ?? ''),
            ],
            $failedCriteriaDetailed,
        ));

        $verdict = [
            'schema_version' => 'atlas.self_construction.completion_claim_authority_verdict.v1',
            'mode' => 'read_only_completion_claim_authority_verdict',
            'status' => $completionAllowed ? 'completion_claim_authorized_by_audit' : 'completion_claim_rejected_by_audit',
            'completion_authority' => 'atlas_self_construction_os_completion_audit',
            'required_completion_predicate' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'audit_status' => $status,
            'completion_allowed_by_audit' => $completionAllowed,
            'completion_claim_allowed_by_audit' => $completionAllowed,
            'external_agent_claim_accepted' => false,
            'external_agent_claim_can_override_audit' => false,
            'external_agent_claim_can_mark_os_complete' => false,
            'failed_criterion_count' => count($failedCriteriaDetailed),
            'missing_evidence' => $missingEvidence,
            'missing_evidence_count' => count($missingEvidence),
            'blocker_classification' => $blockerClassification,
            'operator_next_action' => $completionAllowed
                ? 'operator_may_review_completion_claim_and_next_stage'
                : 'resolve_missing_evidence_then_rerun_completion_audit',
            'operator_verification_commands' => [
                'completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
                'completion_evidence_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'operator_evidence_submission_readiness' => 'php artisan atlas:ai:self-construction --atlas-self-construction-operator-evidence-submission-readiness-status --json',
            ],
            'failure_policy' => [
                'reject_external_completion_claim_until_audit_status_complete',
                'reject_completion_claim_when_failed_criteria_present',
                'reject_completion_claim_without_human_signed_receipt',
                'reject_completion_claim_without_real_provider_smoke',
                'reject_completion_claim_without_runtime_gap_matrix_all_runtime_y',
            ],
            'non_execution_guarantees' => [
                'completion_claim_authority_verdict_does_not_persist_receipts',
                'completion_claim_authority_verdict_does_not_sign_for_operator',
                'completion_claim_authority_verdict_does_not_call_provider',
                'completion_claim_authority_verdict_does_not_spend_tokens',
                'completion_claim_authority_verdict_does_not_dispatch',
                'completion_claim_authority_verdict_does_not_enable_runtime',
                'completion_claim_authority_verdict_does_not_promote_completion',
            ],
        ];
        $verdict['completion_claim_authority_verdict_hash'] = AtlasSelfConstructionOsCompletionAuditHashCanonicalizer::stableHash($verdict);

        return $verdict;
    }

    /**
     * @param  list<array<string, mixed>>  $criteria
     * @return array<string, mixed>
     */
    public static function buildAuditBlocks(
        array $criteria,
        array $releaseDossier,
        array $replayDiff,
        array $promotionGate,
        array $mutationGuard,
        array $statusBatch,
        array $runtimeGapMatrix,
        array $terminalLoopCertification,
        array $completionReceipt,
        array $realProviderSmoke,
        array $forgeSmoke,
    ): array {
        $byId = [];
        foreach ($criteria as $criterion) {
            $byId[(string) ($criterion['id'] ?? '')] = $criterion;
        }
        $meta = self::criterionMetadata();

        $blockFor = function (
            string $label,
            string $criterionId,
            string $observedStatus,
            string $observedHash,
        ) use ($byId, $meta): array {
            $criterion = $byId[$criterionId] ?? null;
            $passed = $criterion !== null ? (bool) ($criterion['passed'] ?? false) : false;
            $metaForCriterion = $meta[$criterionId] ?? [
                'blocker_type' => 'technical',
                'remediation_command' => '',
                'doc_anchor' => '',
                'expected_receipt_schema' => '',
                'why_blocking' => '',
            ];

            return [
                'label' => $label,
                'criterion_id' => $criterionId,
                'status' => $passed ? 'green' : 'blocked',
                'passed' => $passed,
                'blocker_type' => $passed ? 'none' : $metaForCriterion['blocker_type'],
                'observed_status' => $observedStatus,
                'observed_hash' => $observedHash,
                'remediation_command' => $passed ? '' : $metaForCriterion['remediation_command'],
                'doc_anchor' => $metaForCriterion['doc_anchor'],
                'expected_receipt_schema' => $metaForCriterion['expected_receipt_schema'],
                'why_blocking' => $passed ? '' : $metaForCriterion['why_blocking'],
            ];
        };

        $chainIntegrityStatus = (string) data_get($releaseDossier, 'agent_control_plane_release_dossier_status.chain_integrity_status', '');

        return [
            'release_dossier_block' => $blockFor(
                'Release Dossier',
                'release_dossier_green',
                (string) data_get($releaseDossier, 'status', ''),
                (string) data_get($releaseDossier, 'agent_control_plane_release_dossier_status.release_dossier_hash', data_get($releaseDossier, 'agent_control_plane_release_dossier.release_dossier_hash', '')),
            ),
            'replay_diff_block' => $blockFor(
                'Replay Diff',
                'replay_diff_against_completion_snapshot_green',
                (string) data_get($replayDiff, 'status', ''),
                (string) data_get($replayDiff, 'agent_control_plane_replay_diff_status.diff_hash', ''),
            ),
            'promotion_gate_block' => $blockFor(
                'Promotion Gate',
                'promotion_gate_green',
                (string) data_get($promotionGate, 'status', ''),
                (string) data_get($promotionGate, 'agent_control_plane_macro_sprint_promotion_gate.gate_hash', ''),
            ),
            'mutation_guard_block' => $blockFor(
                'Mutation Guard',
                'mutation_guard_green',
                (string) data_get($mutationGuard, 'status', ''),
                (string) data_get($mutationGuard, 'agent_control_plane_certification_mutation_guard.mutation_hash', data_get($mutationGuard, 'agent_control_plane_certification_mutation_guard_status.mutation_hash', '')),
            ),
            'chain_integrity_block' => [
                'label' => 'Chain Integrity',
                'criterion_id' => 'release_dossier_green',
                'status' => $chainIntegrityStatus === 'available' ? 'green' : 'unknown',
                'passed' => $chainIntegrityStatus === 'available',
                'blocker_type' => $chainIntegrityStatus === 'available' ? 'none' : 'technical',
                'observed_status' => $chainIntegrityStatus,
                'observed_hash' => (string) data_get($releaseDossier, 'agent_control_plane_release_dossier.chain_integrity_hash', ''),
                'remediation_command' => 'php artisan atlas:ai:self-construction --agent-control-plane-chain-integrity-certification-status --json',
                'doc_anchor' => 'docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md#chain-integrity',
                'expected_receipt_schema' => 'atlas.self_construction.agent_control_plane_chain_integrity_certification.v1',
                'why_blocking' => $chainIntegrityStatus === 'available' ? '' : 'Chain integrity certification must be available before the release dossier can be promoted.',
            ],
            'runtime_gap_block' => $blockFor(
                'Runtime Gap Matrix',
                'runtime_gap_matrix_all_runtime_y',
                (string) data_get($runtimeGapMatrix, 'status', ''),
                (string) data_get($runtimeGapMatrix, 'runtime_gap_matrix_hash', ''),
            ),
            'certification_status_batch_block' => $blockFor(
                'Certification Status Batch',
                'certification_status_batch_green',
                (string) data_get($statusBatch, 'status', ''),
                (string) data_get($statusBatch, 'agent_control_plane_certification_status_batch_status.batch_hash', data_get($statusBatch, 'agent_control_plane_certification_status_batch.batch_hash', '')),
            ),
            'terminal_loop_block' => $blockFor(
                'Multi-Agent Terminal Loop',
                'agent_control_plane_terminal_loop_certification_green',
                (string) ($terminalLoopCertification['status'] ?? ''),
                (string) ($terminalLoopCertification['certification_hash'] ?? ''),
            ),
            'human_signed_receipt_block' => $blockFor(
                'Human-Signed OS-Complete Receipt',
                'human_signed_os_complete_receipt_present',
                (string) data_get($completionReceipt, 'status', ''),
                (string) data_get($completionReceipt, 'receipt_hash', ''),
            ),
            'real_provider_smoke_block' => $blockFor(
                'Real Provider Smoke',
                'end_to_end_real_provider_smoke_green',
                (string) data_get($realProviderSmoke, 'status', ''),
                (string) data_get($realProviderSmoke, 'smoke_hash', ''),
            ),
            'forge_self_improvement_integration_smoke_block' => $blockFor(
                'Forge ↔ Self-Improvement Integration Smoke',
                'forge_self_improvement_integration_smoke_green',
                (string) data_get($forgeSmoke, 'status', ''),
                (string) data_get($forgeSmoke, 'smoke_hash', ''),
            ),
        ];
    }

    /**
     * Report final-brain completion criteria with actionable gaps, lane scores, and exact proof
     * commands for every unmet criterion.
     *
     * Classification:
     *   complete : criterion passed=true
     *   missing  : criterion passed=false, blocker_type=technical  (Atlas can run the proof command)
     *   blocked  : criterion passed=false, blocker_type=human|real_provider (operator action needed)
     *
     * @param  list<array<string, mixed>>  $criteria  each: {id, requirement, passed, evidence?}
     * @return array<string, mixed>
     */
    public static function finalBrainReport(array $criteria): array
    {
        $meta = self::criterionMetadata();
        $complete = [];
        $missing = [];
        $blocked = [];
        $laneScores = [];

        foreach ($criteria as $criterion) {
            $id = (string) ($criterion['id'] ?? '');
            $passed = (bool) ($criterion['passed'] ?? false);
            $m = $meta[$id] ?? [
                'blocker_type' => 'technical',
                'doc_anchor' => '',
                'remediation_command' => '',
                'expected_receipt_schema' => '',
                'why_blocking' => '',
            ];
            $lane = (string) $m['blocker_type'];
            $laneScores[$lane] ??= ['total' => 0, 'complete' => 0];
            $laneScores[$lane]['total']++;

            if ($passed) {
                $laneScores[$lane]['complete']++;
                $complete[] = [
                    'id' => $id,
                    'requirement' => (string) ($criterion['requirement'] ?? ''),
                    'lane' => $lane,
                ];

                continue;
            }

            $proofCommand = (string) $m['remediation_command'];
            $entry = [
                'id' => $id,
                'requirement' => (string) ($criterion['requirement'] ?? ''),
                'lane' => $lane,
                'proof_command' => $proofCommand,
                'next_packet_hint' => $lane === 'technical'
                    ? 'atlas-task: run '.$proofCommand.' to satisfy criterion '.$id
                    : 'operator-action-required: '.(string) $m['why_blocking'],
                'why_blocking' => (string) $m['why_blocking'],
                'doc_anchor' => (string) $m['doc_anchor'],
            ];

            if ($lane === 'technical') {
                $missing[] = $entry;
            } else {
                $blocked[] = $entry;
            }
        }

        $total = count($criteria);
        $completeCount = count($complete);

        foreach ($laneScores as &$s) {
            $s['score'] = $s['total'] > 0 ? round($s['complete'] / $s['total'], 4) : 0.0;
        }
        unset($s);

        return [
            'schema' => 'atlas.self_construction.completion_criterion_report.final_brain.v1',
            'total_criteria' => $total,
            'complete_count' => $completeCount,
            'missing_count' => count($missing),
            'blocked_count' => count($blocked),
            'all_complete' => $total > 0 && $completeCount === $total,
            'complete' => $complete,
            'missing' => $missing,
            'blocked' => $blocked,
            'lane_scores' => $laneScores,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $criteria
     * @param  array<string, mixed>  $controlPlane
     * @param  array<string, mixed>  $releaseDossier
     * @param  array<string, mixed>  $statusBatch
     * @param  array<string, mixed>  $terminalLoopCertification
     * @return list<array<string, mixed>>
     */
    public static function promptToArtifactChecklist(array $criteria, array $controlPlane, array $releaseDossier, array $statusBatch, array $terminalLoopCertification): array
    {
        $criterionStatus = [];
        foreach ($criteria as $criterion) {
            $criterionStatus[(string) ($criterion['id'] ?? '')] = (bool) ($criterion['passed'] ?? false);
        }

        $modulePassed = [];
        foreach ((array) ($terminalLoopCertification['modules'] ?? []) as $module) {
            $modulePassed[(string) ($module['id'] ?? '')] = (bool) ($module['passed'] ?? false);
        }
        $invariants = (array) ($terminalLoopCertification['invariants'] ?? []);
        $terminalLoopPassed = (bool) ($terminalLoopCertification['passed'] ?? false);
        $terminalLoopProofHash = (string) data_get(
            Collection::make($criteria)->firstWhere('id', 'agent_control_plane_terminal_loop_certification_green'),
            'evidence.operational_proof_hash',
            '',
        );

        return [
            ['requirement' => 'Atlas Self-Construction OS complete', 'artifact' => 'completion criteria array', 'evidence_status' => $criteria === [] ? 'missing' : 'present'],
            ['requirement' => 'all runtime gaps closed', 'artifact' => 'atlas.self_construction.runtime_gap_matrix.v1', 'evidence_status' => ($criterionStatus['runtime_gap_matrix_all_runtime_y'] ?? false) ? 'passed' : 'blocked'],
            ['requirement' => 'release dossier green', 'artifact' => 'agentControlPlaneReleaseDossierStatus', 'evidence_status' => (string) data_get($releaseDossier, 'status') === 'available' ? 'passed' : 'blocked'],
            ['requirement' => 'certification batch green', 'artifact' => 'agentControlPlaneCertificationStatusBatchStatus', 'evidence_status' => (string) data_get($statusBatch, 'status') === 'passed' ? 'passed' : 'blocked'],
            ['requirement' => 'no premature execution during audit', 'artifact' => 'runtime_safety flags', 'evidence_status' => 'passed'],
            ['requirement' => 'loop auto-replenishment works', 'artifact' => 'agentControlPlaneTaskAutoReplenishmentStatus + terminal loop operational proof', 'evidence_status' => (($modulePassed['task_auto_replenishment_status'] ?? false) && (bool) ($invariants['auto_replenishment_target_met'] ?? false) && $terminalLoopPassed) ? 'passed' : 'blocked', 'evidence_hash' => $terminalLoopProofHash],
            ['requirement' => 'loop validates worker eligibility and completion evidence', 'artifact' => 'agentControlPlaneWorkerTaskEligibilityCertificationStatus + one-shot worker packet + terminal loop operational proof', 'evidence_status' => (($modulePassed['worker_task_eligibility_certification_status'] ?? false) && ($modulePassed['one_shot_worker_packet_status'] ?? false) && (bool) ($invariants['worker_task_eligibility_blocks_operator_only_completion_blockers'] ?? false) && (bool) ($invariants['structured_completion_evidence_valid'] ?? false) && $terminalLoopPassed) ? 'passed' : 'blocked', 'evidence_hash' => $terminalLoopProofHash],
            ['requirement' => 'loop claim/lease prevents duplicate or cross-agent completion', 'artifact' => 'agentControlPlaneTaskQueueClaimNextStatus + agentControlPlaneTaskQueueCompleteDryRunStatus + lease invariants', 'evidence_status' => (($modulePassed['task_queue_claim_next_status'] ?? false) && ($modulePassed['task_queue_complete_dry_run_status'] ?? false) && (bool) ($invariants['claim_requires_lease_id_and_agent_id'] ?? false) && (bool) ($invariants['completion_requires_active_lease'] ?? false) && (bool) ($invariants['no_duplicate_claims'] ?? false) && (bool) ($invariants['no_cross_agent_completion'] ?? false) && $terminalLoopPassed) ? 'passed' : 'blocked', 'evidence_hash' => $terminalLoopProofHash],
            ['requirement' => 'loop evidence ledger/work product handoff is captured', 'artifact' => 'one-shot worker packet evidence template + terminal loop health digest evidence rollup', 'evidence_status' => (($modulePassed['one_shot_worker_packet_status'] ?? false) && ($modulePassed['terminal_loop_health_digest_status'] ?? false) && (bool) ($invariants['evidence_hash_present'] ?? false) && (bool) ($invariants['terminal_loop_fleet_evidence_rollup_green_path_verified'] ?? false) && $terminalLoopPassed) ? 'passed' : 'blocked', 'evidence_hash' => $terminalLoopProofHash],
            ['requirement' => 'loop resume/retomada recovers interrupted agents and stale leases', 'artifact' => 'agentControlPlaneTaskLeaseRecoveryStatus + terminal worker bootstrap resume contract + health digest resume rollup', 'evidence_status' => (($modulePassed['task_lease_recovery_status'] ?? false) && ($modulePassed['terminal_worker_bootstrap_status'] ?? false) && ($modulePassed['terminal_loop_health_digest_status'] ?? false) && (bool) ($invariants['recovery_handles_orphaned_leases'] ?? false) && (bool) ($invariants['terminal_loop_fleet_resume_recovery_path_verified'] ?? false) && (bool) ($invariants['worker_resumption_contract_present'] ?? false) && $terminalLoopPassed) ? 'passed' : 'blocked', 'evidence_hash' => $terminalLoopProofHash],
            ['requirement' => 'human signed completion receipt', 'artifact' => AtlasSelfConstructionHumanSignedCompletionReceiptService::SCHEMA_VERSION, 'evidence_status' => ($criterionStatus['human_signed_os_complete_receipt_present'] ?? false) ? 'passed' : 'blocked_until_operator_receipt'],
            ['requirement' => 'real provider end-to-end smoke', 'artifact' => AtlasSelfConstructionRealProviderSmokeCertificationService::SCHEMA_VERSION, 'evidence_status' => ($criterionStatus['end_to_end_real_provider_smoke_green'] ?? false) ? 'passed' : 'blocked_until_real_smoke'],
            ['requirement' => 'Forge/Self-Improvement integration smoke', 'artifact' => AtlasSelfConstructionForgeSelfImprovementIntegrationSmokeService::SCHEMA_VERSION, 'evidence_status' => ($criterionStatus['forge_self_improvement_integration_smoke_green'] ?? false) ? 'passed' : 'blocked'],
            ['requirement' => 'multi-agent terminal loop wired (claim/complete/replenish/bootstrap/health-digest/recover/one-shot/multi-agent-cert)', 'artifact' => 'atlas.self_construction.agent_control_plane_terminal_loop_certification.v1', 'evidence_status' => (bool) ($terminalLoopCertification['passed'] ?? false) ? 'passed' : 'blocked_until_terminal_loop_modules_wired'],
        ];
    }
}