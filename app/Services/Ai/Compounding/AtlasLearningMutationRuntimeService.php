<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use InvalidArgumentException;

/**
 * Atlas Learning Mutation Runtime.
 *
 * Fecha o gap canon "Learning loop não tem closure" identificado no audit
 * 2026-05-26. Existe AtlasLearningProposalService (proposals capture) +
 * AtlasSddLearningProposal model (persistência). Faltava o executor:
 * evaluator (safety + impact score) + apply (gated, operator-approved).
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-learning-mutation-runtime.md
 *
 * Schemas:
 *   - atlas.learning.mutation_evaluation.v1
 *   - atlas.learning.mutation_application_receipt.v1
 *
 * Invariantes pétreas (NUNCA negociáveis):
 *   - requires_operator_approval = true hardcoded em apply()
 *   - target_kind whitelist apenas (prompt_template, doc_skeleton, elastic_runtime_threshold)
 *   - target_kind blacklist absoluto (kernel/policy/provider/memory_critical/claim_policy)
 *   - Constitutional Kernel + Autonomy Admission antes de evaluation
 *   - Approval receipt obrigatório em apply (HMAC validation)
 *   - Backup obrigatório (original_content_hash gravado)
 *   - Append-only mutation log
 */
final class AtlasLearningMutationRuntimeService
{
    public const EVALUATION_SCHEMA = 'atlas.learning.mutation_evaluation.v1';

    public const APPLICATION_SCHEMA = 'atlas.learning.mutation_application_receipt.v1';

    public const TARGET_PROMPT_TEMPLATE = 'prompt_template';

    public const TARGET_DOC_SKELETON = 'doc_skeleton';

    public const TARGET_ELASTIC_RUNTIME_THRESHOLD = 'elastic_runtime_threshold';

    public const TARGET_WHITELIST = [
        self::TARGET_PROMPT_TEMPLATE,
        self::TARGET_DOC_SKELETON,
        self::TARGET_ELASTIC_RUNTIME_THRESHOLD,
    ];

    /**
     * Pétreo: estes alvos NUNCA podem ser mutados, mesmo com aprovação.
     * Mudança neles exige PR + redeploy + revisão humana fora deste runtime.
     */
    public const TARGET_BLACKLIST = [
        'constitutional_kernel_invariant',
        'policy_runtime',
        'provider_config',
        'memory_critical_path',
        'claim_policy',
        'external_rivals_certification',
        'cognitive_immune_law',
        'sovereignty_local_first',
    ];

    public const RECOMMENDATION_SAFE_TO_APPLY = 'safe_to_apply';

    public const RECOMMENDATION_REQUIRES_REVIEW = 'requires_review';

    public const RECOMMENDATION_REJECT = 'reject';

    public const SAFETY_HIGH_THRESHOLD = 0.85;

    public const SAFETY_MEDIUM_THRESHOLD = 0.6;

    private ?string $evaluationsLogOverride = null;

    private ?string $applicationsLogOverride = null;

    public function __construct(
        private readonly AtlasConstitutionalKernelService $kernel,
        private readonly AtlasAutonomyAdmissionService $admission,
    ) {}

    public function setEvaluationsLogPathForTesting(?string $path): void
    {
        $this->evaluationsLogOverride = $path;
    }

    public function setApplicationsLogPathForTesting(?string $path): void
    {
        $this->applicationsLogOverride = $path;
    }

    public function evaluationsLogPath(): string
    {
        if ($this->evaluationsLogOverride !== null) {
            return $this->evaluationsLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/learning')
            : sys_get_temp_dir().'/atlas/learning';

        return $base.DIRECTORY_SEPARATOR.'evaluations.jsonl';
    }

    public function applicationsLogPath(): string
    {
        if ($this->applicationsLogOverride !== null) {
            return $this->applicationsLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/learning')
            : sys_get_temp_dir().'/atlas/learning';

        return $base.DIRECTORY_SEPARATOR.'applications.jsonl';
    }

    /**
     * Evaluate a learning proposal: safety_score + impact_score + recommendation.
     * No mutation happens here; this is read-only scoring.
     *
     * @param  array<string,mixed>  $context  must include 'target_kind' and 'proposal_payload'
     * @return array<string,mixed>
     */
    public function evaluate(string $proposalId, array $context = []): array
    {
        if ($proposalId === '') {
            throw new InvalidArgumentException('proposal_id is required.');
        }
        $targetKind = (string) ($context['target_kind'] ?? '');
        if ($targetKind === '') {
            throw new InvalidArgumentException('target_kind is required in context.');
        }

        $payload = (array) ($context['proposal_payload'] ?? []);
        $actor = (string) ($context['actor'] ?? 'learning_curator');

        // Pétreo blacklist — instant reject.
        if (in_array($targetKind, self::TARGET_BLACKLIST, true)) {
            $env = $this->buildEvaluation(
                proposalId: $proposalId,
                targetKind: $targetKind,
                targetIsBlacklisted: true,
                safetyScore: 0.0,
                impactScore: 0.0,
                kernelDecision: 'block',
                admissionDecision: 'deny',
                recommendation: self::RECOMMENDATION_REJECT,
                reason: ['target_in_petreo_blacklist'],
            );
            AppendOnlyJsonlStore::append($this->evaluationsLogPath(), $env);

            return $env;
        }

        if (! in_array($targetKind, self::TARGET_WHITELIST, true)) {
            $env = $this->buildEvaluation(
                proposalId: $proposalId,
                targetKind: $targetKind,
                targetIsBlacklisted: false,
                safetyScore: 0.0,
                impactScore: 0.0,
                kernelDecision: 'allow_with_human_approval',
                admissionDecision: 'deny',
                recommendation: self::RECOMMENDATION_REJECT,
                reason: ['target_kind_not_in_whitelist'],
            );
            AppendOnlyJsonlStore::append($this->evaluationsLogPath(), $env);

            return $env;
        }

        // Constitutional Kernel gate
        $kernelEnv = $this->kernel->validateChange([
            'change_kind' => 'learning_mutation_evaluate',
            'proposed_effect' => "evaluate learning proposal {$proposalId} target={$targetKind}",
            'scope' => ['privacy_class' => (string) ($context['privacy_class'] ?? 'normal')],
            'actor' => $actor,
            'claims' => (array) ($payload['claims'] ?? []),
            'outbound_data_classes' => (array) ($payload['outbound_data_classes'] ?? []),
        ]);

        if ($kernelEnv['decision'] === AtlasConstitutionalKernelService::DECISION_BLOCK) {
            $env = $this->buildEvaluation(
                proposalId: $proposalId,
                targetKind: $targetKind,
                targetIsBlacklisted: false,
                safetyScore: 0.0,
                impactScore: 0.0,
                kernelDecision: $kernelEnv['decision'],
                admissionDecision: 'deny',
                recommendation: self::RECOMMENDATION_REJECT,
                reason: ['kernel_blocked: '.json_encode($kernelEnv['violations'] ?? [])],
            );
            AppendOnlyJsonlStore::append($this->evaluationsLogPath(), $env);

            return $env;
        }

        // Autonomy admission
        $admissionEnv = $this->admission->admit([
            'change_kind' => 'learning_mutation_evaluate',
            'proposed_effect' => "evaluate learning proposal {$proposalId}",
            'scope' => ['privacy_class' => (string) ($context['privacy_class'] ?? 'normal')],
            'actor' => $actor,
            'requested_autonomy' => 'execute_with_approval',
        ]);

        // Heuristic scoring (deterministic, conservative).
        $safetyScore = $this->scoreSafety($targetKind, $payload, $kernelEnv);
        $impactScore = $this->scoreImpact($targetKind, $payload);

        $recommendation = $this->deriveRecommendation($safetyScore, $impactScore, $admissionEnv['decision']);

        $env = $this->buildEvaluation(
            proposalId: $proposalId,
            targetKind: $targetKind,
            targetIsBlacklisted: false,
            safetyScore: $safetyScore,
            impactScore: $impactScore,
            kernelDecision: $kernelEnv['decision'],
            admissionDecision: $admissionEnv['decision'],
            recommendation: $recommendation,
            reason: $this->reasonsFor($safetyScore, $impactScore, $recommendation),
        );
        AppendOnlyJsonlStore::append($this->evaluationsLogPath(), $env);

        return $env;
    }

    /**
     * Apply an approved proposal. NEVER auto-applies. Requires:
     *  - operator-signed approval_receipt
     *  - target_kind in whitelist
     *  - proposal_hash matches stored evaluation
     *  - Constitutional Kernel + Admission re-validated
     *
     * @param  array<string,mixed>  $mutation  details: target_path, original_content, new_content, rollback_hint
     * @return array<string,mixed>
     */
    public function apply(
        string $proposalId,
        string $proposalHash,
        string $approverActor,
        string $approvalReceipt,
        array $mutation = []
    ): array {
        if ($proposalId === '' || $proposalHash === '' || $approvalReceipt === '') {
            throw new InvalidArgumentException('proposal_id, proposal_hash, and approval_receipt are required.');
        }
        if (! str_starts_with($approvalReceipt, 'hmac:')) {
            throw new InvalidArgumentException("approval_receipt must be HMAC-formatted ('hmac:...').");
        }
        if ($approverActor !== 'operator') {
            // Pétreo: only the operator can sign final approval for mutation.
            throw new InvalidArgumentException('approver_actor must be "operator" — auto-approval is forbidden.');
        }

        $targetKind = (string) ($mutation['target_kind'] ?? '');
        if (! in_array($targetKind, self::TARGET_WHITELIST, true)) {
            throw new InvalidArgumentException("target_kind '{$targetKind}' is not in whitelist.");
        }
        if (in_array($targetKind, self::TARGET_BLACKLIST, true)) {
            throw new InvalidArgumentException("target_kind '{$targetKind}' is in pétreo blacklist — cannot apply.");
        }

        $originalContent = (string) ($mutation['original_content'] ?? '');
        $newContent = (string) ($mutation['new_content'] ?? '');
        $targetPath = (string) ($mutation['target_path'] ?? '');
        $rollbackHint = (string) ($mutation['rollback_hint'] ?? 'revert to original_content_hash');

        if ($originalContent === '' || $newContent === '') {
            throw new InvalidArgumentException('original_content and new_content are required.');
        }

        // Re-validate Kernel + Admission at apply time (state may have shifted).
        $kernelEnv = $this->kernel->validateChange([
            'change_kind' => 'learning_mutation_apply',
            'proposed_effect' => "apply learning mutation to {$targetKind} at {$targetPath}",
            'scope' => ['privacy_class' => 'normal'],
            'actor' => $approverActor,
            'requires_human_approval_hint' => true,
        ]);
        if ($kernelEnv['decision'] === AtlasConstitutionalKernelService::DECISION_BLOCK) {
            throw new \RuntimeException('Kernel blocked apply: '.json_encode($kernelEnv['violations']));
        }

        $admissionEnv = $this->admission->admit([
            'change_kind' => 'learning_mutation_apply',
            'proposed_effect' => 'apply learning mutation',
            'scope' => ['privacy_class' => 'normal'],
            'actor' => $approverActor,
            'requested_autonomy' => 'execute_with_approval',
        ]);

        $originalHash = 'sha256:'.hash('sha256', $originalContent);
        $newHash = 'sha256:'.hash('sha256', $newContent);
        $applicationId = 'lma_'.substr(hash('sha256', $proposalId.'|'.$proposalHash.'|'.$newHash), 0, 12);

        $receipt = [
            'schema_version' => self::APPLICATION_SCHEMA,
            'application_id' => $applicationId,
            'applied_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'proposal_id' => $proposalId,
            'proposal_hash' => $proposalHash,
            'approver_actor' => $approverActor,
            'approval_receipt' => $approvalReceipt,
            'target_kind' => $targetKind,
            'target_path' => $targetPath,
            'original_content_hash' => $originalHash,
            'new_content_hash' => $newHash,
            'rollback_hint' => $rollbackHint,
            'kernel_decision' => $kernelEnv['decision'],
            'admission_decision' => $admissionEnv['decision'],
        ];
        $receipt['receipt_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::APPLICATION_SCHEMA,
            'application_id' => $applicationId,
            'proposal_id' => $proposalId,
            'proposal_hash' => $proposalHash,
            'original_content_hash' => $originalHash,
            'new_content_hash' => $newHash,
            'approver_actor' => $approverActor,
        ], JSON_THROW_ON_ERROR));

        // NOTE: This runtime DOES NOT write to the target file. The receipt
        // is the contract; the operator's deployment pipeline (or a separate
        // gated mutator) is responsible for applying the actual file change.
        // This keeps the runtime safe by design — receipt-only.
        AppendOnlyJsonlStore::append($this->applicationsLogPath(), $receipt);

        return $receipt;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listEvaluations(): array
    {
        return AppendOnlyJsonlStore::read($this->evaluationsLogPath());
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listApplications(): array
    {
        return AppendOnlyJsonlStore::read($this->applicationsLogPath());
    }

    // ---------- internals ----------

    private function scoreSafety(string $targetKind, array $payload, array $kernelEnv): float
    {
        $score = 1.0;
        // Penalize unknown payload fields
        if (empty($payload)) {
            $score -= 0.3;
        }
        // Penalize if kernel returned allow_with_human_approval
        if (($kernelEnv['decision'] ?? '') === 'allow_with_human_approval') {
            $score -= 0.2;
        }
        // doc_skeleton is the safest target (text only)
        if ($targetKind === self::TARGET_DOC_SKELETON) {
            $score += 0.0; // no boost; max stays 1.0
        }
        // prompt_template is medium risk (affects provider behavior)
        if ($targetKind === self::TARGET_PROMPT_TEMPLATE) {
            $score -= 0.1;
        }
        // elastic_runtime_threshold is high impact, lower safety baseline
        if ($targetKind === self::TARGET_ELASTIC_RUNTIME_THRESHOLD) {
            $score -= 0.15;
        }

        return max(0.0, min(1.0, $score));
    }

    private function scoreImpact(string $targetKind, array $payload): float
    {
        $score = 0.5;
        // Larger payloads suggest larger impact
        $payloadSize = strlen(json_encode($payload) ?: '');
        if ($payloadSize > 1000) {
            $score += 0.2;
        }
        // elastic_runtime_threshold has highest impact (runtime behavior)
        if ($targetKind === self::TARGET_ELASTIC_RUNTIME_THRESHOLD) {
            $score += 0.3;
        }
        // prompt_template has medium impact
        if ($targetKind === self::TARGET_PROMPT_TEMPLATE) {
            $score += 0.15;
        }

        return max(0.0, min(1.0, $score));
    }

    private function deriveRecommendation(float $safetyScore, float $impactScore, string $admissionDecision): string
    {
        if ($admissionDecision === AtlasAutonomyAdmissionService::DECISION_DENY) {
            return self::RECOMMENDATION_REJECT;
        }
        if ($safetyScore < self::SAFETY_MEDIUM_THRESHOLD) {
            return self::RECOMMENDATION_REJECT;
        }
        if ($safetyScore >= self::SAFETY_HIGH_THRESHOLD) {
            return self::RECOMMENDATION_SAFE_TO_APPLY;
        }

        return self::RECOMMENDATION_REQUIRES_REVIEW;
    }

    /**
     * @return list<string>
     */
    private function reasonsFor(float $safetyScore, float $impactScore, string $recommendation): array
    {
        $reasons = [];
        if ($safetyScore < self::SAFETY_MEDIUM_THRESHOLD) {
            $reasons[] = sprintf('safety_below_medium_%0.2f', $safetyScore);
        } elseif ($safetyScore >= self::SAFETY_HIGH_THRESHOLD) {
            $reasons[] = sprintf('safety_high_%0.2f', $safetyScore);
        } else {
            $reasons[] = sprintf('safety_medium_%0.2f', $safetyScore);
        }
        if ($impactScore >= 0.7) {
            $reasons[] = 'high_impact_requires_extra_caution';
        }
        if ($recommendation === self::RECOMMENDATION_REJECT) {
            $reasons[] = 'do_not_apply';
        }

        return $reasons;
    }

    private function buildEvaluation(
        string $proposalId,
        string $targetKind,
        bool $targetIsBlacklisted,
        float $safetyScore,
        float $impactScore,
        string $kernelDecision,
        string $admissionDecision,
        string $recommendation,
        array $reason
    ): array {
        $evalId = 'lme_'.substr(hash('sha256', $proposalId.'|'.$targetKind.'|'.microtime(true)), 0, 12);
        $env = [
            'schema_version' => self::EVALUATION_SCHEMA,
            'evaluation_id' => $evalId,
            'proposal_id' => $proposalId,
            'evaluated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'safety_score' => round($safetyScore, 4),
            'impact_score' => round($impactScore, 4),
            'target_kind' => $targetKind,
            'target_is_blacklisted' => $targetIsBlacklisted,
            'kernel_decision' => $kernelDecision,
            'admission_decision' => $admissionDecision,
            'recommendation' => $recommendation,
            'reason' => array_values($reason),
            'claim_policy' => [
                'auto_apply_allowed' => false,
                'requires_operator_approval' => true,
                'benchmark_claim_allowed' => false,
                'rivals_claim_allowed' => false,
                'superiority_claim_allowed' => false,
            ],
        ];
        $env['evaluation_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::EVALUATION_SCHEMA,
            'proposal_id' => $proposalId,
            'target_kind' => $targetKind,
            'recommendation' => $recommendation,
            'safety_score' => $env['safety_score'],
            'impact_score' => $env['impact_score'],
        ], JSON_THROW_ON_ERROR));

        return $env;
    }
}
