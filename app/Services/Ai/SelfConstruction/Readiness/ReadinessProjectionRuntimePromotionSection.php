<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimeGapMatrixService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimePromotionClosureExecutionPackService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimePromotionDraftHashFinalizerService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimePromotionEndgameService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimePromotionEndgameVerifierService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimePromotionEvidenceDossierService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimePromotionOperatorRunbookExporterService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimePromotionReceiptDraftService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimePromotionReceiptRunbookService;
use Closure;

/**
 * ReadinessProjectionRuntimePromotionSection — Residual Elite Obra4.
 */
final class ReadinessProjectionRuntimePromotionSection
{
    private ?AtlasSelfConstructionReadinessService $mother = null;

    /** @param Closure(array<string,mixed>): string $stableHash */
    public function __construct(private readonly Closure $stableHash) {}

    public function setMother(AtlasSelfConstructionReadinessService $mother): self
    {
        $this->mother = $mother;
        return $this;
    }

    public function __call(string $name, array $arguments): mixed
    {
        if ($this->mother === null) {
            throw new \RuntimeException("ReadinessProjectionRuntimePromotionSection mother not bound for {$name}.");
        }
        $method = new \ReflectionMethod($this->mother, $name);
        return $method->invokeArgs($this->mother, $arguments);
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionClosureExecutionPackContract(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_closure_execution_pack', 'Atlas Self-Construction Runtime Promotion Closure Execution Pack', AtlasSelfConstructionRuntimePromotionClosureExecutionPackService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionClosureExecutionPackService::class, 'contract');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionClosureExecutionPackImplementationPacket(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_closure_execution_pack', 'Atlas Self-Construction Runtime Promotion Closure Execution Pack', AtlasSelfConstructionRuntimePromotionClosureExecutionPackService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionClosureExecutionPackService::class, 'implementation_packet');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionClosureExecutionPackPreflight(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_closure_execution_pack', 'Atlas Self-Construction Runtime Promotion Closure Execution Pack', AtlasSelfConstructionRuntimePromotionClosureExecutionPackService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionClosureExecutionPackService::class, 'preflight');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionDraftHashFinalizerContract(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_draft_hash_finalizer', 'Atlas Self-Construction Runtime Promotion Draft Hash Finalizer', AtlasSelfConstructionRuntimePromotionDraftHashFinalizerService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionDraftHashFinalizerService::class, 'contract');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionDraftHashFinalizerImplementationPacket(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_draft_hash_finalizer', 'Atlas Self-Construction Runtime Promotion Draft Hash Finalizer', AtlasSelfConstructionRuntimePromotionDraftHashFinalizerService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionDraftHashFinalizerService::class, 'implementation_packet');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionDraftHashFinalizerPreflight(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_draft_hash_finalizer', 'Atlas Self-Construction Runtime Promotion Draft Hash Finalizer', AtlasSelfConstructionRuntimePromotionDraftHashFinalizerService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionDraftHashFinalizerService::class, 'preflight');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionEndgameContract(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_endgame', 'Atlas Self-Construction Runtime Promotion Endgame', AtlasSelfConstructionRuntimePromotionEndgameService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionEndgameService::class, 'contract');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionEndgameImplementationPacket(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_endgame', 'Atlas Self-Construction Runtime Promotion Endgame', AtlasSelfConstructionRuntimePromotionEndgameService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionEndgameService::class, 'implementation_packet');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionEndgamePreflight(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_endgame', 'Atlas Self-Construction Runtime Promotion Endgame', AtlasSelfConstructionRuntimePromotionEndgameService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionEndgameService::class, 'preflight');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionEndgameVerifierContract(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_endgame_verifier', 'Atlas Self-Construction Runtime Promotion Endgame Verifier', AtlasSelfConstructionRuntimePromotionEndgameVerifierService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionEndgameVerifierService::class, 'contract');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionEndgameVerifierImplementationPacket(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_endgame_verifier', 'Atlas Self-Construction Runtime Promotion Endgame Verifier', AtlasSelfConstructionRuntimePromotionEndgameVerifierService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionEndgameVerifierService::class, 'implementation_packet');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionEndgameVerifierPreflight(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_endgame_verifier', 'Atlas Self-Construction Runtime Promotion Endgame Verifier', AtlasSelfConstructionRuntimePromotionEndgameVerifierService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionEndgameVerifierService::class, 'preflight');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionEvidenceDossierContract(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_evidence_dossier', 'Atlas Self-Construction Runtime Promotion Evidence Dossier', AtlasSelfConstructionRuntimePromotionEvidenceDossierService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionEvidenceDossierService::class, 'contract');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionEvidenceDossierImplementationPacket(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_evidence_dossier', 'Atlas Self-Construction Runtime Promotion Evidence Dossier', AtlasSelfConstructionRuntimePromotionEvidenceDossierService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionEvidenceDossierService::class, 'implementation_packet');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionEvidenceDossierPreflight(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_evidence_dossier', 'Atlas Self-Construction Runtime Promotion Evidence Dossier', AtlasSelfConstructionRuntimePromotionEvidenceDossierService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionEvidenceDossierService::class, 'preflight');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionOperatorRunbookExporterContract(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_operator_runbook_exporter', 'Atlas Self-Construction Runtime Promotion Operator Runbook Exporter', AtlasSelfConstructionRuntimePromotionOperatorRunbookExporterService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionOperatorRunbookExporterService::class, 'contract');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionOperatorRunbookExporterImplementationPacket(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_operator_runbook_exporter', 'Atlas Self-Construction Runtime Promotion Operator Runbook Exporter', AtlasSelfConstructionRuntimePromotionOperatorRunbookExporterService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionOperatorRunbookExporterService::class, 'implementation_packet');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionOperatorRunbookExporterPreflight(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_operator_runbook_exporter', 'Atlas Self-Construction Runtime Promotion Operator Runbook Exporter', AtlasSelfConstructionRuntimePromotionOperatorRunbookExporterService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionOperatorRunbookExporterService::class, 'preflight');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionReceiptDraftContract(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_receipt_draft', 'Atlas Self-Construction Runtime Promotion Receipt Draft', AtlasSelfConstructionRuntimePromotionReceiptDraftService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionReceiptDraftService::class, 'contract');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionReceiptDraftImplementationPacket(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_receipt_draft', 'Atlas Self-Construction Runtime Promotion Receipt Draft', AtlasSelfConstructionRuntimePromotionReceiptDraftService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionReceiptDraftService::class, 'implementation_packet');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionReceiptDraftPreflight(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_receipt_draft', 'Atlas Self-Construction Runtime Promotion Receipt Draft', AtlasSelfConstructionRuntimePromotionReceiptDraftService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionReceiptDraftService::class, 'preflight');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierContract(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_receipt_pre_submission_verifier', 'Atlas Self-Construction Runtime Promotion Receipt Pre-Submission Verifier', AtlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierService::class, 'contract');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierImplementationPacket(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_receipt_pre_submission_verifier', 'Atlas Self-Construction Runtime Promotion Receipt Pre-Submission Verifier', AtlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierService::class, 'implementation_packet');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierPreflight(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_receipt_pre_submission_verifier', 'Atlas Self-Construction Runtime Promotion Receipt Pre-Submission Verifier', AtlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierService::class, 'preflight');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionReceiptRunbookContract(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_receipt_runbook', 'Atlas Self-Construction Runtime Promotion Receipt Runbook', AtlasSelfConstructionRuntimePromotionReceiptRunbookService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionReceiptRunbookService::class, 'contract');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionReceiptRunbookImplementationPacket(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_receipt_runbook', 'Atlas Self-Construction Runtime Promotion Receipt Runbook', AtlasSelfConstructionRuntimePromotionReceiptRunbookService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionReceiptRunbookService::class, 'implementation_packet');
    }

    /** @param array<string,mixed> $options @return array<string,mixed> */
    public function atlasSelfConstructionRuntimePromotionReceiptRunbookPreflight(array $options = []): array
    {
        return $this->buildCertificationWorkbenchQuartet('atlas_self_construction_runtime_promotion_receipt_runbook', 'Atlas Self-Construction Runtime Promotion Receipt Runbook', AtlasSelfConstructionRuntimePromotionReceiptRunbookService::SCHEMA_VERSION, AtlasSelfConstructionRuntimePromotionReceiptRunbookService::class, 'preflight');
    }










    public function atlasSelfConstructionRuntimePromotionReceiptDraftStatus(array $options = []): array
    {
        $runtimeGapMatrix = (new AtlasSelfConstructionRuntimeGapMatrixService($this->mother ?? throw new \RuntimeException("mother unbound")))->matrix();
        $result = (new AtlasSelfConstructionRuntimePromotionReceiptDraftService)->build($runtimeGapMatrix, [
            'signed_by' => (string) ($options['signed_by'] ?? ''),
            'reason' => (string) ($options['reason'] ?? ''),
            'persist_runtime_promotion_receipt' => (bool) ($options['persist_runtime_promotion_receipt'] ?? false),
        ]);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_runtime_promotion_receipt_draft',
            label: 'Atlas Self-Construction Runtime Promotion Receipt Draft',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'draft_hash' => (string) data_get($result, 'draft_hash'),
                'receipt_hash' => (string) data_get($result, 'receipt_hash'),
                'candidate_count' => (int) data_get($result, 'candidate_count', 0),
                'candidate_gap_ids' => (array) data_get($result, 'candidate_gap_ids', []),
                'blocked_gap_ids' => (array) data_get($result, 'blocked_gap_ids', []),
                'blocked_gap_count' => (int) data_get($result, 'blocked_gap_count', 0),
                'missing_operator_input_count' => count((array) data_get($result, 'missing_operator_inputs', [])),
                'missing_operator_inputs' => (array) data_get($result, 'missing_operator_inputs', []),
                'placeholder_operator_input_count' => (int) data_get($result, 'placeholder_operator_input_count', 0),
                'placeholder_operator_inputs' => (array) data_get($result, 'placeholder_operator_inputs', []),
                'verification_status' => (string) data_get($result, 'verification.status'),
                'verification_violation_count' => (int) data_get($result, 'verification.violation_count', 0),
                'verification_violations' => (array) data_get($result, 'verification.violations', []),
                'runtime_promotion_allowed' => (bool) data_get($result, 'verification.runtime_promotion_allowed', false),
                'runtime_gap_matrix_hash' => (string) data_get($result, 'runtime_gap_matrix_hash', ''),
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => (string) data_get($result, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
                'runtime_promotion_basis_hash' => (string) data_get($result, 'runtime_promotion_basis_hash', ''),
                'runtime_promotion_closure_basis_hash' => (string) data_get($result, 'runtime_promotion_closure_basis_hash', ''),
                'persistence_command' => (string) data_get($result, 'persistence_command', ''),
                'next_action' => (string) data_get($result, 'next_action', ''),
                'persistence_requested' => (bool) data_get($result, 'persistence_requested', false),
                'persisted' => (bool) data_get($result, 'persisted', false),
            ],
        );
    }

    public function atlasSelfConstructionRuntimePromotionDraftHashFinalizerStatus(array $options = []): array
    {
        $result = (new AtlasSelfConstructionRuntimePromotionDraftHashFinalizerService)->finalize([
            'operator_draft_workspace_path' => (string) ($options['operator_draft_workspace_path'] ?? ''),
            'write_computed_runtime_promotion_receipt_hash' => (bool) ($options['write_computed_runtime_promotion_receipt_hash'] ?? false),
        ]);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_runtime_promotion_draft_hash_finalizer',
            label: 'Atlas Self-Construction Runtime Promotion Draft Hash Finalizer',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'finalizer_hash' => (string) data_get($result, 'finalizer_hash'),
                'draft_path' => (string) data_get($result, 'draft_path'),
                'computed_receipt_hash' => (string) data_get($result, 'computed_receipt_hash'),
                'can_write_hash_to_draft' => (bool) data_get($result, 'can_write_hash_to_draft', false),
                'write_requested' => (bool) data_get($result, 'write_requested', false),
                'written' => (bool) data_get($result, 'written', false),
                'write_blocker' => (string) data_get($result, 'write_blocker'),
                'completion_allowed' => false,
            ],
        );
    }

    public function atlasSelfConstructionRuntimePromotionEvidenceDossierStatus(array $options = []): array
    {
        $result = (new AtlasSelfConstructionRuntimePromotionEvidenceDossierService($this->mother ?? throw new \RuntimeException("mother unbound")))->build($options);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_runtime_promotion_evidence_dossier',
            label: 'Atlas Self-Construction Runtime Promotion Evidence Dossier',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'dossier_hash' => (string) data_get($result, 'machine_verification.dossier_hash'),
                'gap_count' => (int) data_get($result, 'machine_verification.gap_count', 0),
                'candidate_count' => (int) data_get($result, 'machine_verification.candidate_count', 0),
                'runtime_enabled_count' => (int) data_get($result, 'machine_verification.runtime_enabled_count', 0),
                'promotion_receipt_required' => (bool) data_get($result, 'machine_verification.promotion_receipt_required', false),
            ],
        );
    }

    public function atlasSelfConstructionRuntimePromotionReceiptRunbookStatus(array $options = []): array
    {
        $evidence = $this->atlasSelfConstructionOsCompletionEvidenceStatus($options);
        $result = (array) data_get($evidence, 'operator_action_packet.runtime_promotion_receipt_runbook', []);
        $completionClaimAuthorityAliases = $this->completionClaimAuthorityAliases(
            failedCriteria: (array) data_get($evidence, 'completion_audit_failed_criteria', []),
            currentRequiredOperatorArtifact: (string) data_get($evidence, 'current_required_operator_artifact', 'runtime_promotion_receipt'),
        );

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_runtime_promotion_receipt_runbook',
            label: 'Atlas Self-Construction Runtime Promotion Receipt Runbook',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'runbook_hash' => (string) data_get($result, 'runbook_hash'),
                'required_evidence_field_count' => count((array) data_get($result, 'required_evidence_fields', [])),
                'required_acknowledgement_count' => count((array) data_get($result, 'required_acknowledgements', [])),
                'forbidden_flag_count' => count((array) data_get($result, 'forbidden_flags', [])),
                'persist_runtime_promotion_receipt_command' => (string) data_get($result, 'commands.persist_runtime_promotion_receipt', ''),
                'verify_completion_evidence_command' => (string) data_get($result, 'commands.verify_completion_evidence', ''),
                'refresh_terminal_loop_operational_proof_command' => (string) data_get($result, 'commands.refresh_terminal_loop_operational_proof', ''),
                'run_completion_audit_diagnostic_command' => (string) data_get($result, 'commands.run_completion_audit_diagnostic', ''),
                'terminal_loop_operational_proof_canonical_binding_path' => (string) data_get($result, 'commands.terminal_loop_operational_proof_canonical_binding_path'),
                'run_completion_audit_with_canonical_terminal_loop_operational_proof' => (string) data_get($result, 'commands.run_completion_audit_with_canonical_terminal_loop_operational_proof'),
                ...$completionClaimAuthorityAliases,
                'completion_claim_allowed' => false,
                'self_programming_allowed' => false,
            ],
        );
    }

    public function atlasSelfConstructionRuntimePromotionClosureExecutionPackStatus(array $options = []): array
    {
        $result = (new AtlasSelfConstructionRuntimePromotionClosureExecutionPackService($this->mother ?? throw new \RuntimeException("mother unbound")))->build([
            'signed_by' => (string) ($options['signed_by'] ?? ''),
            'reason' => (string) ($options['reason'] ?? ''),
            'runtime_promotion_receipt' => (array) ($options['runtime_promotion_receipt'] ?? $this->decodeJsonOption($options['runtime_promotion_receipt_json'] ?? null)),
        ]);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_runtime_promotion_closure_execution_pack',
            label: 'Atlas Self-Construction Runtime Promotion Closure Execution Pack',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'closure_pack_hash' => (string) data_get($result, 'closure_pack_hash'),
                'runtime_gap_count' => (int) data_get($result, 'runtime_gap_count', 0),
                'blocked_gap_ids' => (array) data_get($result, 'blocked_gap_ids', []),
                'promoted_gap_ids' => (array) data_get($result, 'promoted_gap_ids', []),
                'receipt_verification_status' => (string) data_get($result, 'receipt_verification.status'),
                'can_persist' => (bool) data_get($result, 'receipt_verification.can_persist', false),
                'completion_allowed' => false,
                'completion_claim_allowed' => false,
            ],
        );
    }

    public function atlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierStatus(array $options = []): array
    {
        $receipt = (array) ($options['runtime_promotion_receipt'] ?? $this->decodeJsonOption($options['runtime_promotion_receipt_json'] ?? null));
        $matrix = (new AtlasSelfConstructionRuntimeGapMatrixService($this->mother ?? throw new \RuntimeException("mother unbound")))->matrix([
            'runtime_promotion_receipt' => $receipt,
            'persist_runtime_promotion_receipt' => false,
        ]);
        $verifier = new AtlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierService;
        $result = $receipt === [] ? $verifier->emptyVerification() : $verifier->verify($receipt, $matrix);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_runtime_promotion_receipt_pre_submission_verifier',
            label: 'Atlas Self-Construction Runtime Promotion Receipt Pre-Submission Verifier',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'verification_hash' => (string) data_get($result, 'verification_hash'),
                'can_persist' => (bool) data_get($result, 'can_persist', false),
                'violation_count' => (int) data_get($result, 'violation_count', 0),
                'placeholder_signer' => (bool) data_get($result, 'placeholder_signer', false),
                'stale_runtime_gap_matrix_hash' => (bool) data_get($result, 'stale_runtime_gap_matrix_hash', false),
                'stale_runtime_promotion_closure_basis_hash' => (bool) data_get($result, 'stale_runtime_promotion_closure_basis_hash', false),
                'promoted_gap_drift' => (bool) data_get($result, 'promoted_gap_drift', false),
                'completion_allowed' => false,
                'completion_claim_allowed' => false,
            ],
        );
    }

    public function atlasSelfConstructionRuntimePromotionEndgameStatus(array $options = []): array
    {
        $result = (new AtlasSelfConstructionRuntimePromotionEndgameService($this->mother ?? throw new \RuntimeException("mother unbound")))->build([
            'signed_by' => (string) ($options['signed_by'] ?? ''),
            'reason' => (string) ($options['reason'] ?? ''),
            'runtime_promotion_receipt' => (array) ($options['runtime_promotion_receipt'] ?? $this->decodeJsonOption($options['runtime_promotion_receipt_json'] ?? null)),
            'persist_runtime_promotion_receipt' => (bool) ($options['persist_runtime_promotion_receipt'] ?? false),
            'operator_draft_workspace_path' => (string) ($options['operator_draft_workspace_path'] ?? ''),
        ]);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_runtime_promotion_endgame',
            label: 'Atlas Self-Construction Runtime Promotion Endgame',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'endgame_hash' => (string) data_get($result, 'endgame_hash'),
                'runtime_gap_count' => (int) data_get($result, 'runtime_gap_count', 0),
                'blocked_gap_ids' => (array) data_get($result, 'blocked_gap_ids', []),
                'promoted_gap_ids' => (array) data_get($result, 'promoted_gap_ids', []),
                'current_required_operator_artifact' => 'runtime_promotion_receipt',
                'next_required_command' => (string) data_get($result, 'operator_next_action_shell_packet.command_to_copy', ''),
                'next_required_persist_command' => (string) data_get($result, 'operator_next_action_shell_packet.canonical_persist_command', data_get($result, 'persistence_preflight.persist_command', '')),
                'runtime_gap_matrix_hash' => (string) data_get($result, 'current_runtime_gap_matrix_hash', ''),
                'current_runtime_gap_matrix_hash' => (string) data_get($result, 'current_runtime_gap_matrix_hash', ''),
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => (string) data_get($result, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
                'runtime_promotion_basis_hash' => (string) data_get($result, 'runtime_promotion_basis_hash', ''),
                'runtime_promotion_closure_basis_hash' => (string) data_get($result, 'runtime_promotion_closure_basis_hash', ''),
                'receipt_pre_submission_verification_status' => (string) data_get($result, 'receipt_pre_submission_verification.status'),
                'receipt_pre_submission_verification_violation_count' => (int) data_get($result, 'receipt_pre_submission_verification.violation_count', 0),
                'receipt_pre_submission_verification_violations' => (array) data_get($result, 'receipt_pre_submission_verification.violations', []),
                'placeholder_signer' => (bool) data_get($result, 'receipt_pre_submission_verification.placeholder_signer', false),
                'reason_invalid' => (bool) data_get($result, 'receipt_pre_submission_verification.reason_invalid', false),
                'receipt_hash_mismatch' => (bool) data_get($result, 'receipt_pre_submission_verification.receipt_hash_mismatch', false),
                'promoted_gap_id_drift' => (bool) data_get($result, 'receipt_pre_submission_verification.promoted_gap_id_drift', false),
                'verifier_violation_codes' => (array) data_get($result, 'verifier_violation_codes', []),
                'stale_runtime_promotion_receipt_detected' => (bool) data_get($result, 'stale_runtime_promotion_receipt_detected', false),
                'fresh_runtime_promotion_receipt_required' => (bool) data_get($result, 'fresh_runtime_promotion_receipt_required', false),
                'fresh_runtime_promotion_receipt_recovery_command' => (string) data_get($result, 'fresh_runtime_promotion_receipt_recovery_command', ''),
                'fresh_runtime_promotion_receipt_recovery_file_command' => (string) data_get($result, 'operator_next_action_shell_packet.fresh_runtime_promotion_receipt_recovery_file_command', ''),
                'fresh_runtime_promotion_receipt_recovery_file_command_hash' => (string) data_get($result, 'operator_next_action_shell_packet.fresh_runtime_promotion_receipt_recovery_file_command_hash', ''),
                'fresh_runtime_promotion_receipt_recovery_file_command_payload_path' => (string) data_get($result, 'operator_next_action_shell_packet.fresh_runtime_promotion_receipt_recovery_file_command_payload_path', ''),
                'fresh_runtime_promotion_receipt_recovery_file_command_placeholder_fields' => (array) data_get($result, 'operator_next_action_shell_packet.fresh_runtime_promotion_receipt_recovery_file_command_placeholder_fields', []),
                'fresh_runtime_promotion_receipt_recovery_file_command_copy_safe' => (bool) data_get($result, 'operator_next_action_shell_packet.fresh_runtime_promotion_receipt_recovery_file_command_copy_safe', false),
                'receipt_pre_submission_missing_field_count' => count((array) data_get($result, 'receipt_pre_submission_verification.missing_fields', [])),
                'receipt_pre_submission_missing_fields' => (array) data_get($result, 'receipt_pre_submission_verification.missing_fields', []),
                'receipt_pre_submission_acknowledgement_missing_count' => count((array) data_get($result, 'receipt_pre_submission_verification.acknowledgement_missing', [])),
                'receipt_pre_submission_acknowledgement_missing' => (array) data_get($result, 'receipt_pre_submission_verification.acknowledgement_missing', []),
                'can_persist' => (bool) data_get($result, 'receipt_pre_submission_verification.can_persist', false),
                'persistence_preflight_persist_requested' => (bool) data_get($result, 'persistence_preflight.persist_requested', false),
                'persistence_preflight_blocked_reason' => (string) data_get($result, 'persistence_preflight.persistence_blocked_reason', ''),
                'persistence_preflight_persist_command' => (string) data_get($result, 'persistence_preflight.persist_command', ''),
                'persistence_preflight_rerun_matrix_command' => (string) data_get($result, 'persistence_preflight.rerun_matrix_command', ''),
                'persistence_preflight_terminal_loop_operational_proof_command' => (string) data_get($result, 'persistence_preflight.terminal_loop_operational_proof_command', ''),
                'persistence_preflight_terminal_loop_operational_proof_canonical_binding_path' => (string) data_get($result, 'persistence_preflight.terminal_loop_operational_proof_canonical_binding_path', ''),
                'persistence_preflight_rerun_audit_with_terminal_loop_operational_proof_command' => (string) data_get($result, 'persistence_preflight.rerun_audit_with_terminal_loop_operational_proof_command', ''),
                'persistence_preflight_effective_rerun_audit_with_canonical_terminal_loop_operational_proof_command' => (string) data_get($result, 'persistence_preflight.effective_rerun_audit_with_canonical_terminal_loop_operational_proof_command', ''),
                'operator_decision_checklist' => (array) data_get($result, 'operator_decision_checklist', []),
                'operator_decision_checklist_count' => count((array) data_get($result, 'operator_decision_checklist', [])),
                'operator_decision_checklist_passed_count' => count(array_filter((array) data_get($result, 'operator_decision_checklist', []), static fn (mixed $item): bool => (bool) data_get($item, 'passed', false))),
                'post_persistence_next_commands' => (array) data_get($result, 'post_persistence_next_commands', []),
                'operator_next_action_shell_packet' => (array) data_get($result, 'operator_next_action_shell_packet', []),
                'operator_next_action_shell_packet_status' => (string) data_get($result, 'operator_next_action_shell_packet.status', ''),
                'operator_next_action_shell_packet_hash' => (string) data_get($result, 'operator_next_action_shell_packet.shell_packet_hash', ''),
                'operator_next_action_command_to_copy' => (string) data_get($result, 'operator_next_action_shell_packet.command_to_copy', ''),
                'operator_next_action_command_to_copy_hash' => (string) data_get($result, 'operator_next_action_shell_packet.command_to_copy_hash', ''),
                'operator_next_action_shell_packet_placeholder_count' => (int) data_get($result, 'operator_next_action_shell_packet.placeholder_count', 0),
                'operator_next_action_shell_packet_copy_safe' => (bool) data_get($result, 'operator_next_action_shell_packet.copy_safe', false),
                'persisted' => (bool) data_get($result, 'persisted', false),
                'completion_allowed' => false,
                'completion_claim_allowed' => false,
                'self_programming_allowed' => false,
                'terminal_loop_operational_proof_required_before_completion_claim' => true,
                'completion_audit_without_terminal_loop_operational_proof_is_diagnostic_only' => true,
            ],
        );
    }

    public function atlasSelfConstructionRuntimePromotionEndgameVerifierStatus(array $options = []): array
    {
        $receipt = (array) ($options['runtime_promotion_receipt'] ?? $this->decodeJsonOption($options['runtime_promotion_receipt_json'] ?? null));
        $matrix = (new AtlasSelfConstructionRuntimeGapMatrixService($this->mother ?? throw new \RuntimeException("mother unbound")))->matrix([
            'runtime_promotion_receipt' => $receipt,
            'persist_runtime_promotion_receipt' => false,
        ]);
        $verifier = new AtlasSelfConstructionRuntimePromotionEndgameVerifierService;
        $result = $receipt === [] ? $verifier->emptyVerification() : $verifier->verify($receipt, $matrix);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_runtime_promotion_endgame_verifier',
            label: 'Atlas Self-Construction Runtime Promotion Endgame Verifier',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'verifier_hash' => (string) data_get($result, 'verifier_hash'),
                'can_persist' => (bool) data_get($result, 'can_persist', false),
                'violation_count' => (int) data_get($result, 'violation_count', 0),
                'placeholder_signer' => (bool) data_get($result, 'placeholder_signer', false),
                'stale_runtime_gap_matrix_hash' => (bool) data_get($result, 'stale_runtime_gap_matrix_hash', false),
                'stale_runtime_promotion_closure_basis_hash' => (bool) data_get($result, 'stale_runtime_promotion_closure_basis_hash', false),
                'promoted_gap_id_drift' => (bool) data_get($result, 'promoted_gap_id_drift', false),
                'completion_allowed' => false,
                'completion_claim_allowed' => false,
            ],
        );
    }

    public function atlasSelfConstructionRuntimePromotionOperatorRunbookExporterStatus(array $options = []): array
    {
        $result = (new AtlasSelfConstructionRuntimePromotionOperatorRunbookExporterService($this->mother ?? throw new \RuntimeException("mother unbound")))->build([
            'signed_by' => (string) ($options['signed_by'] ?? ''),
            'reason' => (string) ($options['reason'] ?? ''),
            'runtime_promotion_receipt' => (array) ($options['runtime_promotion_receipt'] ?? $this->decodeJsonOption($options['runtime_promotion_receipt_json'] ?? null)),
            'persist_export' => (bool) ($options['persist_export'] ?? false),
        ]);

        return $this->wrapCertificationWorkbenchStatus(
            keyPrefix: 'atlas_self_construction_runtime_promotion_operator_runbook_exporter',
            label: 'Atlas Self-Construction Runtime Promotion Operator Runbook Exporter',
            payload: $result,
            statusKey: 'status',
            extraStatusFields: [
                'exporter_hash' => (string) data_get($result, 'exporter_hash'),
                'markdown_hash' => (string) data_get($result, 'markdown_hash'),
                'machine_summary_hash' => (string) data_get($result, 'machine_summary_hash'),
                'persist' => (bool) data_get($result, 'persist', false),
                'export_path' => (string) data_get($result, 'export_path', ''),
                'endgame_status' => (string) data_get($result, 'endgame_status', ''),
                'completion_allowed' => false,
                'completion_claim_allowed' => false,
            ],
        );
    }
}
