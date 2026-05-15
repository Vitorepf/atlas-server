<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

/**
 * Closes the Atlas Self-Construction blocker
 *   `human_signed_os_complete_receipt_present`
 * as a single read-only execution pack.
 *
 * It composes the existing audit/runbook/draft/dossier/blocker-explainer/
 * final-evidence-bundle/submission-preflight services, then derives the
 * exact ordered operator steps and exact commands the operator must run.
 *
 * It NEVER:
 *   - signs the receipt on behalf of the operator;
 *   - persists the receipt;
 *   - promotes completion;
 *   - calls a provider, spends tokens or dispatches work;
 *   - mutates Atlas state in any way.
 */
final class AtlasSelfConstructionHumanCompletionReceiptClosureExecutionPackService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.human_completion_receipt_closure_execution_pack.v1';

    public const MODE = 'read_only_human_completion_receipt_closure_execution_pack';

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(array $options = []): array
    {
        $completionAudit = (array) ($options['completion_audit']
            ?? (new AtlasSelfConstructionOsCompletionAuditService($this->readiness))->audit([
                'completion_receipt' => (array) ($options['completion_receipt'] ?? []),
                'real_provider_smoke' => (array) ($options['real_provider_smoke'] ?? []),
                'forge_self_improvement_smoke' => (array) ($options['forge_self_improvement_smoke'] ?? []),
            ]));
        $completionEvidence = (array) ($options['completion_evidence'] ?? []);

        $blockerExplainer = (array) ($options['completion_audit_blocker_explainer']
            ?? (new AtlasSelfConstructionCompletionAuditBlockerExplainerService)->build($completionAudit));
        $runbook = (array) ($options['human_completion_receipt_runbook']
            ?? (new AtlasSelfConstructionHumanCompletionReceiptRunbookService)->build(
                (array) data_get($completionAudit, 'operator_action_packet.human_completion_receipt_template', []),
            ));
        $draft = (array) ($options['human_completion_receipt_draft']
            ?? (new AtlasSelfConstructionHumanCompletionReceiptDraftService)->build(
                $completionAudit,
                $completionEvidence,
                ['persist_completion_evidence' => false],
            ));
        $dossier = (array) ($options['human_completion_receipt_dossier']
            ?? (new AtlasSelfConstructionHumanCompletionReceiptDossierService($this->readiness))->build([
                'completion_audit' => $completionAudit,
                'completion_receipt' => (array) ($options['completion_receipt'] ?? []),
            ]));
        $finalBundle = (array) ($options['final_evidence_bundle']
            ?? (new AtlasSelfConstructionFinalEvidenceBundleService($this->readiness))->build([
                'completion_audit' => $completionAudit,
                'completion_receipt' => (array) ($options['completion_receipt'] ?? []),
                'real_provider_smoke' => (array) ($options['real_provider_smoke'] ?? []),
                'runtime_promotion_receipt' => (array) ($options['runtime_promotion_receipt'] ?? []),
                'forge_self_improvement_smoke' => (array) ($options['forge_self_improvement_smoke'] ?? []),
            ]));
        $submissionPreflight = (array) ($options['completion_evidence_submission_preflight']
            ?? (new AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService)->build(
                $completionAudit,
                $completionEvidence,
                $blockerExplainer,
            ));

        $prereqs = $this->currentPrerequisites($completionAudit, $completionEvidence);
        $failedCriteria = (array) data_get($completionAudit, 'failed_criteria', []);

        $receiptInput = (array) ($options['completion_receipt'] ?? []);
        $receiptProvided = $receiptInput !== [];
        $verifierContext = $this->verifierContext($completionAudit, $completionEvidence);
        $verificationResult = (new AtlasSelfConstructionHumanCompletionReceiptPreSubmissionVerifierService)
            ->verify($receiptInput, $verifierContext + ['prerequisites' => $prereqs]);

        $persistencePreflight = $this->persistencePreflight(
            $prereqs,
            $verificationResult,
            $receiptInput,
        );
        $operatorSubmissionEnvelope = $this->operatorSubmissionEnvelope(
            $receiptInput,
            $verifierContext,
            $prereqs,
            $verificationResult,
            $persistencePreflight,
        );

        $receiptTemplate = $this->receiptTemplate($verifierContext);
        $receiptDraftPayload = (array) data_get($draft, 'receipt_payload', []);

        $status = $this->status($prereqs, $verificationResult, $persistencePreflight, $receiptProvided);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'blocker_id' => 'human_signed_os_complete_receipt_present',
            'completion_audit' => [
                'status' => (string) data_get($completionAudit, 'status'),
                'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
                'failed_criteria' => $failedCriteria,
                'failed_count' => (int) data_get($completionAudit, 'failed_count', count($failedCriteria)),
                'passed_count' => (int) data_get($completionAudit, 'passed_count', 0),
            ],
            'completion_evidence_status' => [
                'runtime_gap_matrix_status' => (string) data_get($completionEvidence, 'runtime_gap_matrix.status', ''),
                'runtime_gap_matrix_all_runtime_y' => (bool) data_get($completionEvidence, 'runtime_gap_matrix.all_runtime_y', false),
                'runtime_promotion_receipt_status' => (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.status', ''),
                'real_provider_smoke_status' => (string) data_get($completionEvidence, 'real_provider_smoke.status', ''),
                'human_signed_completion_receipt_status' => (string) data_get($completionEvidence, 'human_signed_completion_receipt.status', ''),
            ],
            'human_completion_receipt_runbook' => [
                'status' => (string) data_get($runbook, 'status'),
                'runbook_hash' => (string) data_get($runbook, 'runbook_hash', ''),
                'required_evidence_fields' => (array) data_get($runbook, 'required_evidence_fields', []),
                'required_acknowledgements' => (array) data_get($runbook, 'required_acknowledgements', []),
                'steps' => (array) data_get($runbook, 'steps', []),
            ],
            'human_completion_receipt_draft' => [
                'status' => (string) data_get($draft, 'status'),
                'draft_hash' => (string) data_get($draft, 'draft_hash', ''),
                'missing_operator_inputs' => (array) data_get($draft, 'missing_operator_inputs', []),
                'missing_evidence_hashes' => (array) data_get($draft, 'missing_evidence_hashes', []),
                'failed_prerequisites' => (array) data_get($draft, 'failed_prerequisites', []),
                'receipt_hash' => (string) data_get($draft, 'receipt_hash', ''),
            ],
            'human_completion_receipt_dossier' => [
                'status' => (string) data_get($dossier, 'status'),
                'dossier_hash' => (string) data_get($dossier, 'dossier_hash', ''),
                'completion_audit_snapshot' => (array) data_get($dossier, 'completion_audit_snapshot', []),
                'release_dossier_snapshot' => (array) data_get($dossier, 'release_dossier_snapshot', []),
                'certification_status_batch' => (array) data_get($dossier, 'certification_status_batch', []),
                'human_receipt_preimage' => (array) data_get($dossier, 'human_receipt_preimage', []),
                'operator_signing_checklist' => (array) data_get($dossier, 'operator_signing_checklist', []),
            ],
            'blocker_explainer' => [
                'status' => (string) data_get($blockerExplainer, 'status'),
                'explainer_hash' => (string) data_get($blockerExplainer, 'explainer_hash', ''),
                'remaining_blocker_count' => (int) data_get($blockerExplainer, 'remaining_blocker_count', 0),
                'known_blocker_ids' => (array) data_get($blockerExplainer, 'known_blocker_ids', []),
                'dependency_graph' => (array) data_get($blockerExplainer, 'dependency_graph', []),
                'human_signed_blocker' => $this->extractBlocker($blockerExplainer, 'human_signed_os_complete_receipt_present'),
            ],
            'final_evidence_bundle' => [
                'status' => (string) data_get($finalBundle, 'status'),
                'final_evidence_bundle_hash' => (string) data_get($finalBundle, 'final_evidence_bundle_hash', ''),
                'completion_audit_hash' => (string) data_get($finalBundle, 'completion_audit_hash', ''),
                'component_count' => (int) data_get($finalBundle, 'component_count', 0),
            ],
            'submission_preflight' => [
                'status' => (string) data_get($submissionPreflight, 'status'),
                'submission_preflight_hash' => (string) data_get($submissionPreflight, 'submission_preflight_hash', ''),
                'next_required_submission' => (string) data_get($submissionPreflight, 'next_required_submission', ''),
                'next_required_command' => (string) data_get($submissionPreflight, 'next_required_command', ''),
                'next_required_persist_command' => (string) data_get($submissionPreflight, 'next_required_persist_command', ''),
                'ready_step_count' => (int) data_get($submissionPreflight, 'ready_step_count', 0),
                'blocked_step_count' => (int) data_get($submissionPreflight, 'blocked_step_count', 0),
            ],
            'current_prerequisites' => $prereqs,
            'receipt_template' => $receiptTemplate,
            'receipt_draft' => $receiptDraftPayload,
            'verification_result' => $verificationResult,
            'persistence_preflight' => $persistencePreflight,
            'operator_submission_envelope' => $operatorSubmissionEnvelope,
            'ordered_operator_steps' => $this->orderedOperatorSteps($prereqs, $verificationResult),
            'exact_commands' => $this->exactCommands(),
            'anti_cheat_policy' => $this->antiCheatPolicy(),
            'non_execution_guarantees' => [
                'human_completion_receipt_closure_pack_does_not_sign_for_operator',
                'human_completion_receipt_closure_pack_does_not_persist_receipts',
                'human_completion_receipt_closure_pack_does_not_promote_completion',
                'human_completion_receipt_closure_pack_does_not_call_provider',
                'human_completion_receipt_closure_pack_does_not_spend_tokens',
                'human_completion_receipt_closure_pack_does_not_dispatch_work',
                'human_completion_receipt_closure_pack_does_not_enable_runtime',
                'human_completion_receipt_closure_pack_does_not_enable_self_programming',
                'human_completion_receipt_operator_submission_envelope_does_not_write_files_or_receipts',
            ],
            'safety_invariants' => [
                'human_completion_receipt_requires_human_signature' => true,
                'human_completion_receipt_requires_runtime_promotion_green' => true,
                'human_completion_receipt_requires_real_provider_smoke_green' => true,
                'completion_claim_allowed' => false,
            ],
            'completion_claim_allowed' => false,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
        ];

        $payload['closure_pack_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     * @param  array<string, mixed>  $completionEvidence
     * @return array<string, array<string, mixed>>
     */
    private function currentPrerequisites(array $completionAudit, array $completionEvidence): array
    {
        $criterionGreen = function (string $id) use ($completionAudit): bool {
            foreach ((array) data_get($completionAudit, 'criteria', []) as $criterion) {
                if ((string) ($criterion['id'] ?? '') === $id) {
                    return (bool) ($criterion['passed'] ?? false);
                }
            }

            return false;
        };

        $runtimeMatrixGreen = (bool) data_get($completionEvidence, 'runtime_gap_matrix.all_runtime_y', false)
            && $criterionGreen('runtime_gap_matrix_all_runtime_y');
        $runtimePromotionPresent = (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.status', '') === 'passed';
        $smokeGreen = $criterionGreen('end_to_end_real_provider_smoke_green')
            && (string) data_get($completionEvidence, 'real_provider_smoke.status', '') === 'passed';
        $dossierGreen = $criterionGreen('release_dossier_green');
        $replayGreen = $criterionGreen('replay_diff_against_completion_snapshot_green');
        $batchGreen = $criterionGreen('certification_status_batch_green');

        return [
            'runtime_gap_matrix_all_runtime_y' => $this->prereq($runtimeMatrixGreen, 'runtime_gap_matrix.runtime_gap_matrix_all_runtime_y_must_be_true'),
            'runtime_promotion_receipt_present' => $this->prereq($runtimePromotionPresent, 'runtime_gap_matrix.runtime_promotion_receipt.status_must_be_passed'),
            'end_to_end_real_provider_smoke_green' => $this->prereq($smokeGreen, 'real_provider_smoke.status_must_be_passed'),
            'release_dossier_green' => $this->prereq($dossierGreen, 'completion_audit.criteria.release_dossier_green_must_be_passed'),
            'replay_diff_green' => $this->prereq($replayGreen, 'completion_audit.criteria.replay_diff_against_completion_snapshot_green_must_be_passed'),
            'certification_status_batch_green' => $this->prereq($batchGreen, 'completion_audit.criteria.certification_status_batch_green_must_be_passed'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function prereq(bool $green, string $evidenceSource): array
    {
        return [
            'green' => $green,
            'status' => $green ? 'green' : 'blocked',
            'evidence_source' => $evidenceSource,
        ];
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     * @param  array<string, mixed>  $completionEvidence
     * @return array<string, string>
     */
    private function verifierContext(array $completionAudit, array $completionEvidence): array
    {
        $criterion = function (string $id) use ($completionAudit): array {
            foreach ((array) data_get($completionAudit, 'criteria', []) as $row) {
                if ((string) ($row['id'] ?? '') === $id) {
                    return (array) $row;
                }
            }

            return [];
        };

        $runtime = $criterion('runtime_gap_matrix_all_runtime_y');
        $release = $criterion('release_dossier_green');
        $replay = $criterion('replay_diff_against_completion_snapshot_green');
        $smoke = $criterion('end_to_end_real_provider_smoke_green');
        $batch = $criterion('certification_status_batch_green');

        return [
            'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
            'release_dossier_hash' => (string) data_get($release, 'evidence.hash', ''),
            'replay_diff_hash' => (string) data_get($replay, 'evidence.diff_hash', ''),
            'runtime_gap_matrix_hash' => (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_gap_matrix_hash', data_get($runtime, 'evidence.runtime_gap_matrix_hash', '')),
            'runtime_promotion_receipt_hash' => (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.receipt_hash', ''),
            'real_provider_smoke_hash' => (string) data_get($completionEvidence, 'real_provider_smoke.smoke_hash', data_get($smoke, 'evidence.smoke_hash', '')),
            'certification_status_batch_hash' => (string) data_get($batch, 'evidence.hash', data_get($completionAudit, 'operator_action_packet.human_completion_receipt_template.certification_status_batch_hash', '')),
        ];
    }

    /**
     * @param  array<string, string>  $context
     * @return array<string, mixed>
     */
    private function receiptTemplate(array $context): array
    {
        return [
            'receipt_id' => '<operator_os_completion_receipt_id>',
            'signed_by' => '<operator_name>',
            'reason' => '<operator_reason_minimum_32_chars>',
            'completion_audit_hash' => $context['completion_audit_hash'],
            'release_dossier_hash' => $context['release_dossier_hash'],
            'replay_diff_hash' => $context['replay_diff_hash'],
            'runtime_gap_matrix_hash' => $context['runtime_gap_matrix_hash'],
            'runtime_promotion_receipt_hash' => $context['runtime_promotion_receipt_hash'],
            'real_provider_smoke_hash' => $context['real_provider_smoke_hash'],
            'certification_status_batch_hash' => $context['certification_status_batch_hash'],
            'receipt_hash' => '<operator_generated_64_hex_receipt_hash>',
            'os_complete_approved' => true,
            'operator_reviewed_completion_audit' => true,
            'no_autopromotion_acknowledged' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'completion_autopromoted' => false,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $prereqs
     * @param  array<string, mixed>  $verificationResult
     * @return array<string, mixed>
     */
    private function persistencePreflight(array $prereqs, array $verificationResult, array $receipt): array
    {
        $prereqsGreen = array_values(array_filter(
            $prereqs,
            static fn (array $prereq): bool => (bool) ($prereq['green'] ?? false) !== true,
        )) === [];
        $verifierGreen = (string) data_get($verificationResult, 'status') === 'passed';
        $canPersist = $prereqsGreen && $verifierGreen;

        $blockers = [];
        if (! $prereqsGreen) {
            $blockers[] = 'prerequisites_not_green';
        }
        if (! $verifierGreen) {
            $blockers[] = 'pre_submission_verifier_not_passed';
        }
        if (! is_array($receipt) || $receipt === []) {
            $blockers[] = 'completion_receipt_payload_missing';
        }

        return [
            'can_persist' => $canPersist,
            'blockers' => $blockers,
            'blocker_count' => count($blockers),
            'persist_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@/path/to/completion-receipt.json --persist-completion-evidence --json',
            'persistence_blocker' => $blockers === [] ? '' : 'human_completion_receipt_persistence_blocked_by_'.$blockers[0],
        ];
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @param  array<string, string>  $context
     * @param  array<string, array<string, mixed>>  $prereqs
     * @param  array<string, mixed>  $verificationResult
     * @param  array<string, mixed>  $persistencePreflight
     * @return array<string, mixed>
     */
    private function operatorSubmissionEnvelope(
        array $receipt,
        array $context,
        array $prereqs,
        array $verificationResult,
        array $persistencePreflight,
    ): array {
        $receiptJson = $receipt === []
            ? ''
            : (string) json_encode($this->ksortRecursive($receipt), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $allPrereqsGreen = $this->allPrereqsGreen($prereqs);
        $verifierPassed = (string) data_get($verificationResult, 'status') === 'passed';
        $canPersist = (bool) data_get($persistencePreflight, 'can_persist', false);

        $status = match (true) {
            ! $allPrereqsGreen => 'blocked_until_runtime_smoke_and_evidence_context_are_green',
            $receipt === [] => 'blocked_until_human_completion_receipt_payload_exists',
            ! $verifierPassed => 'blocked_until_human_completion_receipt_verifier_passes',
            ! $canPersist => 'blocked_until_persistence_preflight_passes',
            default => 'ready_for_explicit_operator_persistence',
        };

        $envelope = [
            'schema_version' => 'atlas.self_construction.human_completion_receipt_operator_submission_envelope.v1',
            'mode' => 'read_only_operator_submission_envelope',
            'status' => $status,
            'receipt_payload_under_review' => $receipt,
            'receipt_json_sha256' => $receiptJson === '' ? '' : hash('sha256', $receiptJson),
            'receipt_hash' => (string) ($receipt['receipt_hash'] ?? ''),
            'current_evidence_context' => $context,
            'verification_status' => (string) data_get($verificationResult, 'status', ''),
            'persistence_preflight_status' => $canPersist ? 'passed' : 'blocked',
            'can_persist_after_operator_review' => $status === 'ready_for_explicit_operator_persistence',
            'persist_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@/path/to/completion-receipt.json --persist-completion-evidence --json',
            'pre_persist_operator_checks' => [
                'runtime_gap_matrix_all_runtime_y_is_green',
                'runtime_promotion_receipt_hash_matches_current_context',
                'real_provider_smoke_hash_matches_current_context',
                'completion_audit_hash_release_dossier_hash_replay_diff_hash_and_certification_batch_hash_match_current_context',
                'receipt_hash_matches_canonical_payload_hash',
                'signed_by_is_real_operator_not_placeholder_or_agent',
                'os_complete_approved_and_no_autopromotion_acknowledged_are_true',
                'execution_dispatch_provider_token_adapter_and_self_programming_flags_are_false',
                'persistence_flag_is_explicitly_present',
                'completion_audit_must_be_rerun_after_persistence',
            ],
            'post_persistence_rerun_commands' => [
                'completion_evidence_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
                'completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
                'finalization_gate' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-finalization-gate-status --json',
            ],
            'non_execution_guarantees' => [
                'envelope_does_not_sign_for_operator' => true,
                'envelope_does_not_persist_receipts' => true,
                'envelope_does_not_promote_completion' => true,
                'envelope_does_not_call_provider' => true,
                'envelope_does_not_spend_tokens' => true,
                'envelope_does_not_dispatch' => true,
                'envelope_does_not_enable_runtime' => true,
            ],
        ];
        $envelope['operator_submission_envelope_hash'] = $this->stableHash($envelope);

        return $envelope;
    }

    /**
     * @param  array<string, array<string, mixed>>  $prereqs
     * @param  array<string, mixed>  $verificationResult
     * @return array<int, array<string, mixed>>
     */
    private function orderedOperatorSteps(array $prereqs, array $verificationResult): array
    {
        return [
            [
                'order' => 1,
                'id' => 'refresh_completion_audit',
                'summary' => 'Re-run completion audit to capture fresh hashes.',
                'depends_on' => [],
                'ready' => true,
            ],
            [
                'order' => 2,
                'id' => 'ensure_runtime_promotion_receipt_persisted',
                'summary' => 'Persist runtime promotion receipt so runtime_gap_matrix flips to all_runtime_y.',
                'depends_on' => ['refresh_completion_audit'],
                'ready' => (bool) data_get($prereqs, 'runtime_gap_matrix_all_runtime_y.green', false)
                    && (bool) data_get($prereqs, 'runtime_promotion_receipt_present.green', false),
            ],
            [
                'order' => 3,
                'id' => 'ensure_real_provider_smoke_persisted',
                'summary' => 'Run end-to-end real-provider claim-to-completion smoke and persist evidence.',
                'depends_on' => ['ensure_runtime_promotion_receipt_persisted'],
                'ready' => (bool) data_get($prereqs, 'end_to_end_real_provider_smoke_green.green', false),
            ],
            [
                'order' => 4,
                'id' => 'review_release_dossier_replay_batch',
                'summary' => 'Review release dossier, replay diff and certification status batch greens.',
                'depends_on' => ['ensure_real_provider_smoke_persisted'],
                'ready' => (bool) data_get($prereqs, 'release_dossier_green.green', false)
                    && (bool) data_get($prereqs, 'replay_diff_green.green', false)
                    && (bool) data_get($prereqs, 'certification_status_batch_green.green', false),
            ],
            [
                'order' => 5,
                'id' => 'compose_human_completion_receipt',
                'summary' => 'Draft human completion receipt with operator signer, reason and canonical receipt_hash.',
                'depends_on' => ['review_release_dossier_replay_batch'],
                'ready' => $this->allPrereqsGreen($prereqs),
            ],
            [
                'order' => 6,
                'id' => 'verify_human_completion_receipt',
                'summary' => 'Run pre-submission verifier; fix every diagnostic violation.',
                'depends_on' => ['compose_human_completion_receipt'],
                'ready' => $this->allPrereqsGreen($prereqs),
            ],
            [
                'order' => 7,
                'id' => 'persist_human_completion_receipt',
                'summary' => 'Persist receipt through the existing verifier (only after verify status=passed).',
                'depends_on' => ['verify_human_completion_receipt'],
                'ready' => $this->allPrereqsGreen($prereqs)
                    && (string) data_get($verificationResult, 'status') === 'passed',
            ],
            [
                'order' => 8,
                'id' => 'rerun_completion_audit',
                'summary' => 'Re-run completion audit; human_signed_os_complete_receipt_present must flip to green.',
                'depends_on' => ['persist_human_completion_receipt'],
                'ready' => $this->allPrereqsGreen($prereqs)
                    && (string) data_get($verificationResult, 'status') === 'passed',
            ],
        ];
    }

    /** @param array<string, array<string, mixed>> $prereqs */
    private function allPrereqsGreen(array $prereqs): bool
    {
        foreach ($prereqs as $prereq) {
            if ((bool) ($prereq['green'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    private function exactCommands(): array
    {
        return [
            'refresh_completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            'check_completion_evidence' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
            'draft_human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason ≥32 chars>" --json',
            'verify_human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-pre-submission-verifier-status --completion-receipt-json=@/path/to/completion-receipt.json --json',
            'persist_human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@/path/to/completion-receipt.json --persist-completion-evidence --json',
            'closure_execution_pack_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-closure-execution-pack-status --json',
            'finalization_gate_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-finalization-gate-status --json',
        ];
    }

    /** @return array<string, mixed> */
    private function antiCheatPolicy(): array
    {
        return [
            'forbidden_signers' => ['', '<operator>', 'operator', 'human', 'codex', 'codex-autosigned', 'assistant', 'system', 'claude', 'atlas'],
            'forbidden_reason_patterns' => ['<...>', 'TODO', 'placeholder', 'autosigned'],
            'minimum_reason_length' => 32,
            'forbidden_flags' => [
                'execution_allowed',
                'dispatch_allowed',
                'provider_call_allowed',
                'token_spend_allowed',
                'adapter_execution_allowed',
                'self_programming_allowed',
                'completion_autopromoted',
                'persistence_without_operator_submission_envelope',
            ],
            'hash_invariants' => [
                'receipt_hash_must_be_64_hex',
                'receipt_hash_must_match_canonical_recomputed_hash',
                'evidence_hashes_must_match_current_audit_and_evidence_context',
            ],
            'persistence_invariants' => [
                'cannot_persist_until_prerequisites_green',
                'cannot_persist_until_verifier_status_passed',
                'cannot_persist_through_closure_pack_directly',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $blockerExplainer
     * @return array<string, mixed>
     */
    private function extractBlocker(array $blockerExplainer, string $id): array
    {
        foreach ((array) data_get($blockerExplainer, 'blockers', []) as $blocker) {
            if ((string) ($blocker['blocker_id'] ?? '') === $id) {
                return (array) $blocker;
            }
        }

        return [];
    }

    /**
     * @param  array<string, array<string, mixed>>  $prereqs
     * @param  array<string, mixed>  $verificationResult
     * @param  array<string, mixed>  $persistencePreflight
     */
    private function status(array $prereqs, array $verificationResult, array $persistencePreflight, bool $receiptProvided): string
    {
        $runtimeOrSmokeMissing = ! (bool) data_get($prereqs, 'runtime_gap_matrix_all_runtime_y.green', false)
            || ! (bool) data_get($prereqs, 'runtime_promotion_receipt_present.green', false)
            || ! (bool) data_get($prereqs, 'end_to_end_real_provider_smoke_green.green', false);

        if ($runtimeOrSmokeMissing) {
            return 'blocked_runtime_and_smoke_required';
        }

        if (! $this->allPrereqsGreen($prereqs) || ! $receiptProvided) {
            return 'blocked_human_signature_required';
        }

        $verifierStatus = (string) data_get($verificationResult, 'status');
        if ($verifierStatus !== 'passed') {
            return 'ready_to_verify_human_completion_receipt';
        }

        if (! (bool) data_get($persistencePreflight, 'can_persist', false)) {
            return 'ready_to_persist_human_completion_receipt';
        }

        return 'human_completion_receipt_verified';
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        $clean = $this->stripVolatileKeys($payload);
        unset($clean['generated_at'], $clean['closure_pack_hash']);

        return hash('sha256', (string) json_encode($this->ksortRecursive($clean), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stripVolatileKeys(array $payload): array
    {
        $volatile = [
            'generated_at',
            'verified_at',
            'audited_at',
            'persisted_at',
            'certified_at',
            'receipt_id',
            'receipt_hash',
            'draft_hash',
            'dossier_hash',
            'runbook_hash',
            'preflight_hash',
            'verification_hash',
            'verification_result',
            'pre_submission_verification_hash',
        ];
        foreach ($volatile as $key) {
            unset($payload[$key]);
        }
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->stripVolatileKeys($value);
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $value */
    private function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->ksortRecursive($entry);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}
