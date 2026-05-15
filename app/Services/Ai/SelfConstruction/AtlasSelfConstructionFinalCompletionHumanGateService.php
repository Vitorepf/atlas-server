<?php

namespace App\Services\Ai\SelfConstruction;

use Carbon\CarbonImmutable;

/**
 * Final read-only human gate for the Atlas Self-Construction OS-complete
 * receipt. Composes the existing audit/runbook/draft/verifier/dossier/final
 * bundle/submission preflight/certification batch services and exposes
 * exactly which prerequisites are green, which are blocked, and what the
 * operator must do next.
 *
 * It NEVER:
 *   - signs the receipt on behalf of the operator;
 *   - persists the receipt or any evidence;
 *   - promotes completion;
 *   - calls a provider, dispatches work, spends tokens, enables runtime or
 *     self-programming;
 *   - mutates Atlas state.
 */
final class AtlasSelfConstructionFinalCompletionHumanGateService
{
    public const SCHEMA_VERSION = 'atlas.self_construction.final_completion_human_gate.v1';

    public const MODE = 'read_only_final_completion_human_gate';

    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $readiness,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function build(array $options = []): array
    {
        $completionReceipt = (array) ($options['completion_receipt'] ?? []);
        $runtimePromotionReceipt = (array) ($options['runtime_promotion_receipt'] ?? []);
        $realProviderSmoke = (array) ($options['real_provider_smoke'] ?? []);
        $forgeSmoke = (array) ($options['forge_self_improvement_smoke'] ?? []);
        $completionEvidence = (array) ($options['completion_evidence'] ?? []);

        $completionAudit = (array) ($options['completion_audit']
            ?? (new AtlasSelfConstructionOsCompletionAuditService($this->readiness))->audit([
                'completion_receipt' => $completionReceipt,
                'real_provider_smoke' => $realProviderSmoke,
                'forge_self_improvement_smoke' => $forgeSmoke,
            ]));

        $blockerExplainer = (array) ($options['completion_audit_blocker_explainer']
            ?? (new AtlasSelfConstructionCompletionAuditBlockerExplainerService)->build($completionAudit));
        $submissionPreflight = (array) ($options['completion_evidence_submission_preflight']
            ?? (new AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService)->build(
                $completionAudit,
                $completionEvidence,
                $blockerExplainer,
            ));
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
                'completion_receipt' => $completionReceipt,
            ]));
        $finalBundle = (array) ($options['final_evidence_bundle']
            ?? (new AtlasSelfConstructionFinalEvidenceBundleService($this->readiness))->build([
                'completion_audit' => $completionAudit,
                'completion_receipt' => $completionReceipt,
                'runtime_promotion_receipt' => $runtimePromotionReceipt,
                'real_provider_smoke' => $realProviderSmoke,
                'forge_self_improvement_smoke' => $forgeSmoke,
            ]));

        $verifierContext = $this->verifierContext($completionAudit, $completionEvidence);
        $prereqMatrix = $this->prerequisiteMatrix($completionAudit, $completionEvidence);
        $receiptProvided = $completionReceipt !== [];

        $endgameVerifier = (new AtlasSelfConstructionHumanCompletionReceiptEndgameVerifierService)
            ->verify($completionReceipt, $verifierContext + ['prerequisites' => $prereqMatrix]);

        $persistencePreflight = $this->persistencePreflight($prereqMatrix, $endgameVerifier, $receiptProvided);
        $receiptTemplate = $this->receiptTemplate($verifierContext);

        $status = $this->status($prereqMatrix, $endgameVerifier, $persistencePreflight, $receiptProvided, $completionAudit);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => $status,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'blocker_id' => 'human_signed_os_complete_receipt_present',
            'completion_audit' => [
                'status' => (string) data_get($completionAudit, 'status'),
                'completion_audit_hash' => (string) data_get($completionAudit, 'completion_audit_hash', ''),
                'failed_criteria' => (array) data_get($completionAudit, 'failed_criteria', []),
                'failed_count' => (int) data_get($completionAudit, 'failed_count', 0),
                'passed_count' => (int) data_get($completionAudit, 'passed_count', 0),
            ],
            'completion_evidence_status' => [
                'runtime_gap_matrix_status' => (string) data_get($completionEvidence, 'runtime_gap_matrix.status', ''),
                'runtime_gap_matrix_all_runtime_y' => (bool) data_get($completionEvidence, 'runtime_gap_matrix.all_runtime_y', false),
                'runtime_promotion_receipt_status' => (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.status', ''),
                'real_provider_smoke_status' => (string) data_get($completionEvidence, 'real_provider_smoke.status', ''),
                'human_signed_completion_receipt_status' => (string) data_get($completionEvidence, 'human_signed_completion_receipt.status', ''),
            ],
            'submission_preflight' => [
                'status' => (string) data_get($submissionPreflight, 'status'),
                'submission_preflight_hash' => (string) data_get($submissionPreflight, 'submission_preflight_hash', ''),
                'next_required_submission' => (string) data_get($submissionPreflight, 'next_required_submission', ''),
                'next_required_command' => (string) data_get($submissionPreflight, 'next_required_command', ''),
                'ready_step_count' => (int) data_get($submissionPreflight, 'ready_step_count', 0),
                'blocked_step_count' => (int) data_get($submissionPreflight, 'blocked_step_count', 0),
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
                'operator_signing_checklist' => (array) data_get($dossier, 'operator_signing_checklist', []),
            ],
            'final_evidence_bundle' => [
                'status' => (string) data_get($finalBundle, 'status'),
                'final_evidence_bundle_hash' => (string) data_get($finalBundle, 'final_evidence_bundle_hash', ''),
                'completion_audit_hash' => (string) data_get($finalBundle, 'completion_audit_hash', ''),
                'component_count' => (int) data_get($finalBundle, 'component_count', 0),
            ],
            'certification_status_batch' => (array) data_get($dossier, 'certification_status_batch', []),
            'prerequisite_matrix' => $prereqMatrix,
            'human_receipt_template' => $receiptTemplate,
            'human_receipt_draft' => (array) data_get($draft, 'receipt_payload', []),
            'human_receipt_verification' => $endgameVerifier,
            'persistence_preflight' => $persistencePreflight,
            'ordered_operator_steps' => $this->orderedOperatorSteps($prereqMatrix, $endgameVerifier, $receiptProvided),
            'exact_commands' => $this->exactCommands(),
            'anti_cheat_policy' => $this->antiCheatPolicy(),
            'non_execution_guarantees' => [
                'final_completion_human_gate_does_not_sign_for_operator',
                'final_completion_human_gate_does_not_persist_receipts',
                'final_completion_human_gate_does_not_persist_evidence',
                'final_completion_human_gate_does_not_promote_completion',
                'final_completion_human_gate_does_not_call_provider',
                'final_completion_human_gate_does_not_spend_tokens',
                'final_completion_human_gate_does_not_dispatch_work',
                'final_completion_human_gate_does_not_enable_runtime',
                'final_completion_human_gate_does_not_enable_self_programming',
                'final_completion_human_gate_does_not_start_codex',
            ],
            'safety_invariants' => [
                'requires_runtime_promotion_receipt_persisted' => true,
                'requires_real_provider_smoke_persisted' => true,
                'requires_human_signature_on_complete_receipt' => true,
                'requires_audit_status_complete_for_completion_claim' => true,
                'no_autopromotion' => true,
            ],
            'completion_claim_allowed' => false,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
        ];

        $payload['human_gate_hash'] = $this->stableHash($payload);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $completionAudit
     * @param  array<string, mixed>  $completionEvidence
     * @return array<string, array<string, mixed>>
     */
    private function prerequisiteMatrix(array $completionAudit, array $completionEvidence): array
    {
        $criterionGreen = function (string $id) use ($completionAudit): bool {
            foreach ((array) data_get($completionAudit, 'criteria', []) as $criterion) {
                if ((string) ($criterion['id'] ?? '') === $id) {
                    return (bool) ($criterion['passed'] ?? false);
                }
            }

            return false;
        };

        $runtimeAllY = (bool) data_get($completionEvidence, 'runtime_gap_matrix.all_runtime_y', false)
            && $criterionGreen('runtime_gap_matrix_all_runtime_y');
        $runtimePromotion = (string) data_get($completionEvidence, 'runtime_gap_matrix.runtime_promotion_receipt.status', '') === 'passed';
        $smoke = $criterionGreen('end_to_end_real_provider_smoke_green')
            && (string) data_get($completionEvidence, 'real_provider_smoke.status', '') === 'passed';
        $dossier = $criterionGreen('release_dossier_green');
        $replay = $criterionGreen('replay_diff_against_completion_snapshot_green');
        $batch = $criterionGreen('certification_status_batch_green');
        $mutation = $criterionGreen('mutation_guard_green');
        $promotion = $criterionGreen('promotion_gate_green');

        return [
            'runtime_gap_matrix_all_runtime_y' => $this->prereq($runtimeAllY, 'runtime_gap_matrix.all_runtime_y_must_be_true_and_audit_criterion_passed'),
            'runtime_promotion_receipt_present' => $this->prereq($runtimePromotion, 'runtime_gap_matrix.runtime_promotion_receipt.status_must_be_passed'),
            'real_provider_smoke_green' => $this->prereq($smoke, 'real_provider_smoke.status_must_be_passed_and_audit_criterion_passed'),
            'release_dossier_green' => $this->prereq($dossier, 'completion_audit.criteria.release_dossier_green_must_be_passed'),
            'replay_diff_green' => $this->prereq($replay, 'completion_audit.criteria.replay_diff_against_completion_snapshot_green_must_be_passed'),
            'certification_status_batch_green' => $this->prereq($batch, 'completion_audit.criteria.certification_status_batch_green_must_be_passed'),
            'mutation_guard_green' => $this->prereq($mutation, 'completion_audit.criteria.mutation_guard_green_must_be_passed'),
            'promotion_gate_green' => $this->prereq($promotion, 'completion_audit.criteria.promotion_gate_green_must_be_passed'),
        ];
    }

    /** @return array<string, mixed> */
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
            'receipt_id' => '<operator_final_os_completion_receipt_id>',
            'signed_by' => '<operator_full_name>',
            'reason' => '<operator_reason_minimum_32_chars_describing_what_was_reviewed>',
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
     * @param  array<string, mixed>  $endgame
     * @return array<string, mixed>
     */
    private function persistencePreflight(array $prereqs, array $endgame, bool $receiptProvided): array
    {
        $blockers = [];
        if (! $this->allGreen($prereqs)) {
            $blockers[] = 'prerequisites_not_green';
        }
        if (! $receiptProvided) {
            $blockers[] = 'completion_receipt_payload_missing';
        } elseif ((string) data_get($endgame, 'status') !== 'passed') {
            $blockers[] = 'human_completion_receipt_endgame_verifier_blocked';
        }
        $canPersist = $blockers === [];

        return [
            'can_persist' => $canPersist,
            'blockers' => $blockers,
            'blocker_count' => count($blockers),
            'persist_command' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@/path/to/completion-receipt.json --persist-completion-evidence --json',
            'persistence_blocker' => $blockers === []
                ? ''
                : 'final_completion_human_gate_persistence_blocked_by_'.$blockers[0],
        ];
    }

    /** @param array<string, array<string, mixed>> $prereqs */
    private function allGreen(array $prereqs): bool
    {
        foreach ($prereqs as $prereq) {
            if ((bool) ($prereq['green'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, array<string, mixed>>  $prereqs
     * @param  array<string, mixed>  $endgame
     * @param  array<string, mixed>  $persistencePreflight
     * @param  array<string, mixed>  $completionAudit
     */
    private function status(array $prereqs, array $endgame, array $persistencePreflight, bool $receiptProvided, array $completionAudit): string
    {
        $runtimeMatrix = (bool) data_get($prereqs, 'runtime_gap_matrix_all_runtime_y.green', false);
        $runtimeReceipt = (bool) data_get($prereqs, 'runtime_promotion_receipt_present.green', false);
        $smoke = (bool) data_get($prereqs, 'real_provider_smoke_green.green', false);

        if (! $runtimeMatrix || ! $runtimeReceipt) {
            return 'blocked_runtime_promotion_required';
        }
        if (! $smoke) {
            return 'blocked_real_provider_smoke_required';
        }
        if (! $this->allGreen($prereqs) || ! $receiptProvided) {
            return 'blocked_human_signature_required';
        }
        if ((string) data_get($endgame, 'status') !== 'passed') {
            return 'ready_to_verify_human_receipt';
        }
        if (! (bool) data_get($persistencePreflight, 'can_persist', false)) {
            return 'ready_to_verify_human_receipt';
        }
        if ((string) data_get($completionAudit, 'status') !== 'complete') {
            return 'verifier_passed_ready_for_explicit_persistence';
        }

        return 'complete_candidate_after_audit_rerun';
    }

    /**
     * @param  array<string, array<string, mixed>>  $prereqs
     * @param  array<string, mixed>  $endgame
     * @return array<int, array<string, mixed>>
     */
    private function orderedOperatorSteps(array $prereqs, array $endgame, bool $receiptProvided): array
    {
        $runtimeMatrix = (bool) data_get($prereqs, 'runtime_gap_matrix_all_runtime_y.green', false);
        $runtimeReceipt = (bool) data_get($prereqs, 'runtime_promotion_receipt_present.green', false);
        $smoke = (bool) data_get($prereqs, 'real_provider_smoke_green.green', false);
        $allGreen = $this->allGreen($prereqs);
        $verifierPassed = (string) data_get($endgame, 'status') === 'passed';

        return [
            [
                'order' => 1,
                'id' => 'refresh_completion_audit',
                'summary' => 'Re-run completion audit to capture fresh hashes for runtime gap matrix, release dossier, replay diff and certification status batch.',
                'ready' => true,
                'depends_on' => [],
            ],
            [
                'order' => 2,
                'id' => 'persist_runtime_promotion_receipt',
                'summary' => 'Draft and persist runtime promotion receipt until runtime_gap_matrix.all_runtime_y is true.',
                'ready' => $runtimeMatrix && $runtimeReceipt,
                'depends_on' => ['refresh_completion_audit'],
            ],
            [
                'order' => 3,
                'id' => 'persist_real_provider_smoke',
                'summary' => 'Run claim-to-completion smoke through a real provider with operator-observed cost/work-product evidence; persist the certification.',
                'ready' => $smoke,
                'depends_on' => ['persist_runtime_promotion_receipt'],
            ],
            [
                'order' => 4,
                'id' => 'verify_release_dossier_replay_batch_mutation_promotion',
                'summary' => 'Confirm release dossier, replay diff, certification status batch, mutation guard and promotion gate are all green.',
                'ready' => $allGreen,
                'depends_on' => ['persist_real_provider_smoke'],
            ],
            [
                'order' => 5,
                'id' => 'draft_human_completion_receipt',
                'summary' => 'Draft the human completion receipt with a real operator name (no placeholder) and a ≥32-char reason; recompute receipt_hash via the canonical hash service.',
                'ready' => $allGreen,
                'depends_on' => ['verify_release_dossier_replay_batch_mutation_promotion'],
            ],
            [
                'order' => 6,
                'id' => 'run_endgame_verifier',
                'summary' => 'Run the endgame verifier; iterate until status=passed and can_persist=true.',
                'ready' => $allGreen && $receiptProvided,
                'depends_on' => ['draft_human_completion_receipt'],
            ],
            [
                'order' => 7,
                'id' => 'persist_human_completion_receipt',
                'summary' => 'Persist the receipt only through the canonical verifier with --persist-completion-evidence; never persist through the gate or exporter.',
                'ready' => $allGreen && $verifierPassed,
                'depends_on' => ['run_endgame_verifier'],
            ],
            [
                'order' => 8,
                'id' => 'rerun_audit_until_complete',
                'summary' => 'Re-run completion audit; status must flip to complete with zero failed criteria before completion_claim_allowed can flip true.',
                'ready' => $allGreen && $verifierPassed,
                'depends_on' => ['persist_human_completion_receipt'],
            ],
            [
                'order' => 9,
                'id' => 'run_final_completion_readiness_gate',
                'summary' => 'Run the final completion readiness gate; only it may declare status=complete and next_stage_allowed=true.',
                'ready' => $allGreen && $verifierPassed,
                'depends_on' => ['rerun_audit_until_complete'],
            ],
        ];
    }

    /** @return array<string, string> */
    private function exactCommands(): array
    {
        return [
            'refresh_completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            'check_completion_evidence' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json',
            'draft_runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason ≥32 chars>" --json',
            'persist_runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion-receipt.json --persist-runtime-promotion-receipt --json',
            'draft_real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-draft-status --json',
            'persist_real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --real-provider-smoke-json=@/path/to/real-provider-smoke.json --persist-completion-evidence --json',
            'draft_human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason ≥32 chars>" --json',
            'run_endgame_verifier' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-endgame-verifier-status --completion-receipt-json=@/path/to/completion-receipt.json --json',
            'persist_human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --completion-receipt-json=@/path/to/completion-receipt.json --persist-completion-evidence --json',
            'final_completion_human_gate_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-final-completion-human-gate-status --json',
            'final_completion_readiness_gate_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-final-completion-readiness-gate-status --json',
            'final_completion_dossier_exporter_status' => 'php artisan atlas:ai:self-construction --atlas-self-construction-final-completion-dossier-exporter-status --json',
        ];
    }

    /** @return array<string, mixed> */
    private function antiCheatPolicy(): array
    {
        return [
            'forbidden_signers' => ['', '<operator>', '<operator_name>', '<operator_full_name>', 'operator', 'human', 'codex', 'codex-autosigned', 'assistant', 'system', 'claude', 'atlas'],
            'forbidden_reason_patterns' => ['<...>', 'TODO', 'placeholder', 'autosigned', 'lorem'],
            'minimum_reason_length' => 32,
            'forbidden_flags' => [
                'execution_allowed',
                'dispatch_allowed',
                'provider_call_allowed',
                'token_spend_allowed',
                'adapter_execution_allowed',
                'self_programming_allowed',
                'completion_autopromoted',
            ],
            'hash_invariants' => [
                'receipt_hash_must_be_64_hex',
                'receipt_hash_must_match_canonical_recomputed_hash',
                'evidence_hashes_must_match_current_audit_and_evidence_context',
            ],
            'persistence_invariants' => [
                'cannot_persist_without_prerequisites_green',
                'cannot_persist_without_endgame_verifier_status_passed',
                'cannot_persist_through_human_gate',
                'cannot_persist_through_dossier_exporter',
                'persistence_only_through_canonical_human_completion_receipt_verifier',
            ],
            'gate_invariants' => [
                'gate_never_signs_for_operator',
                'gate_never_promotes_completion',
                'gate_never_calls_provider',
                'gate_never_dispatches_work',
                'gate_never_spends_tokens',
                'gate_never_enables_runtime',
                'gate_never_enables_self_programming',
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function stableHash(array $payload): string
    {
        $clean = $this->stripVolatileKeys($payload);
        unset($clean['generated_at'], $clean['human_gate_hash']);

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
            'human_receipt_verification',
            'pre_submission_verification_hash',
            'endgame_verification_hash',
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
