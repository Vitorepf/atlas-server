<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement;

use App\Models\AtlasProject;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Atlas Self-Improvement Activation Cockpit v1.
 *
 * Human-first read-model that projects Self-Improvement Forge Activation
 * registry + detail into the shape the Atlas Code Desktop renders. Adds
 * status_label, tone, human translation of Power Gate outcomes, Before
 * Snapshot summary, approval receipt visibility, created Obra metadata and
 * next_safe_action — without duplicating any business logic from the
 * underlying ForgeActivationService.
 *
 * Hard rules (mirror the source service):
 *   - NEVER calls a provider;
 *   - NEVER spends a token;
 *   - NEVER auto-executes Fast Path;
 *   - NEVER unlocks `external_rivals_certification`;
 *   - NEVER promotes a completion claim;
 *   - NEVER masks blockers;
 *   - Pure read-model: the only mutation paths remain the underlying
 *     service's accept()/reject() — exposed via the existing controller.
 *
 * Schema: atlas.self_improvement.activation_cockpit.v1
 *
 * Doc: docs/engineering-knowledge-base/atlas-self-improvement-activation-cockpit-v1.md
 */
class AtlasSelfImprovementActivationCockpitService
{
    public const SCHEMA_VERSION = 'atlas.self_improvement.activation_cockpit.v1';

    public const TONE_REC_RED = 'rec-red';

    public const TONE_BRONZE = 'bronze';

    public const TONE_MOSS = 'moss';

    public const TONE_CREAM = 'cream';

    public const TONE_INK = 'ink';

    /** Acceptable filter keys for cockpit(). */
    public const FILTER_KEYS = ['status', 'bucket', 'has_obra'];

    public function __construct(
        private readonly AtlasSelfImprovementForgeActivationService $forgeActivation,
        private readonly AtlasSelfImprovementHumanTrustLedgerService $humanTrustLedger,
        private readonly AtlasSelfImprovementStrategyPortfolioService $strategyPortfolio,
    ) {}

    /**
     * Build the cockpit read-model.
     *
     * @param  array{status?: ?string, bucket?: ?string, has_obra?: ?bool, activation_id?: ?string}  $filters
     * @return array<string,mixed>
     */
    public function cockpit(array $filters = []): array
    {
        $registry = $this->forgeActivation->registry();
        $rawList = is_array($registry['activations'] ?? null) ? $registry['activations'] : [];

        $statusFilter = $this->stringOrNull($filters['status'] ?? null);
        $bucketFilter = $this->stringOrNull($filters['bucket'] ?? null);
        $hasObraFilter = $this->boolOrNull($filters['has_obra'] ?? null);
        $selectedId = $this->stringOrNull($filters['activation_id'] ?? null);

        $counters = $this->emptyCounters();
        $activations = [];

        foreach ($rawList as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $activationId = $this->stringOrNull($entry['activation_id'] ?? null);
            if ($activationId === null) {
                continue;
            }
            $full = $this->forgeActivation->get($activationId);
            $row = $this->summariseActivation($activationId, $entry, $full);

            // Counters are computed BEFORE filters, so the cockpit always
            // shows the true workload counts.
            $this->incrementCounters($counters, $row);

            if ($statusFilter !== null && $row['status'] !== $statusFilter) {
                continue;
            }
            if ($bucketFilter !== null && ($row['strategy_bucket'] ?? '') !== $bucketFilter) {
                continue;
            }
            if ($hasObraFilter !== null) {
                $hasObra = $row['created_obra_id'] !== null;
                if ($hasObra !== $hasObraFilter) {
                    continue;
                }
            }
            $activations[] = $row;
        }

        $selected = null;
        if ($selectedId !== null) {
            $full = $this->forgeActivation->get($selectedId);
            if ($full !== null) {
                $selected = $this->humaniseDetail($full);
            } else {
                $selected = $this->blockedDetail($selectedId);
            }
        }

        $portfolio = $this->safePortfolio();
        $trustLedger = $this->safeTrustLedger();

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'filters' => [
                'status' => $statusFilter,
                'bucket' => $bucketFilter,
                'has_obra' => $hasObraFilter,
                'activation_id' => $selectedId,
            ],
            'activations' => $activations,
            'counters' => $counters,
            'selected_activation' => $selected,
            'strategy_portfolio' => $portfolio,
            'trust_ledger' => $trustLedger,
            'human_summary' => $this->cockpitHumanSummary($counters, $selected),
            'next_safe_action' => $this->cockpitNextSafeAction($counters, $selected),
            'commands' => [
                'cli' => 'php artisan atlas:self-improvement:activation-cockpit --json --strict',
                'plan' => 'php artisan atlas:self-improvement:activate-forge --proposal=@payload.json --json',
                'accept' => 'php artisan atlas:self-improvement:activate-forge --proposal-id=<id> --approve --reviewer=<who> --reason=<why> --json --strict',
                'reject' => 'php artisan atlas:self-improvement:activate-forge --proposal-id=<id> --reject --reviewer=<who> --reason=<why> --json --strict',
            ],
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'auto_fast_path_executed' => false,
            'completion_claim_promoted' => false,
            'separated_from' => 'external_rivals_certification',
            'is_read_model' => true,
        ];
    }

    /**
     * Single-activation projection helper, exposed for the existing
     * ForgeActivationController so accept/reject responses also carry
     * `human_summary` + `next_safe_action`.
     *
     * @param  array<string,mixed>  $activation
     * @return array<string,mixed>
     */
    public function humaniseActivation(array $activation): array
    {
        return $this->humaniseDetail($activation);
    }

    // ------------------------------------------------------------------
    // Internal projection helpers
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $registryEntry
     * @param  array<string,mixed>|null  $full
     * @return array<string,mixed>
     */
    private function summariseActivation(string $activationId, array $registryEntry, ?array $full): array
    {
        $status = (string) ($full['status'] ?? $registryEntry['status'] ?? 'blocked');
        $packet = is_array($full['proposal_packet'] ?? null) ? $full['proposal_packet'] : [];
        $title = $this->stringOrNull($packet['title'] ?? null)
            ?? $this->stringOrNull($full['created_obra_title'] ?? null)
            ?? 'Self-improvement proposal';

        $createdObraId = $this->stringOrNull($full['created_obra_id'] ?? ($registryEntry['created_obra_id'] ?? null));
        $blockers = $full['blockers'] ?? [];

        return [
            'activation_id' => $activationId,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'tone' => $this->statusTone($status),
            'title' => $title,
            'proposal_id' => $this->stringOrNull($full['proposal_id'] ?? ($registryEntry['proposal_id'] ?? null)),
            'strategy_bucket' => $this->stringOrNull($full['strategy_bucket'] ?? ($registryEntry['strategy_bucket'] ?? null)),
            'risk_level' => $this->stringOrNull(data_get($packet, 'risk_classification.risk_level'))
                ?? $this->stringOrNull($packet['risk_level'] ?? null),
            'created_obra_id' => $createdObraId,
            'created_obra_title' => $this->stringOrNull($full['created_obra_title'] ?? null),
            'updated_at' => $this->stringOrNull($registryEntry['updated_at'] ?? null)
                ?? $this->stringOrNull($full['generated_at'] ?? null),
            'next_action' => (string) ($full['next_action'] ?? 'inspect_status'),
            'next_safe_action' => $this->nextSafeAction($status, $full),
            'has_blockers' => is_array($blockers) && $blockers !== [],
            'blockers_count' => is_array($blockers) ? count($blockers) : 0,
        ];
    }

    /**
     * @param  array<string,mixed>  $activation
     * @return array<string,mixed>
     */
    private function humaniseDetail(array $activation): array
    {
        $status = (string) ($activation['status'] ?? 'blocked');
        $packet = is_array($activation['proposal_packet'] ?? null) ? $activation['proposal_packet'] : [];
        $gate = is_array($activation['power_gate'] ?? null) ? $activation['power_gate'] : [];
        $beforeSnapshot = is_array($activation['before_snapshot'] ?? null) ? $activation['before_snapshot'] : [];
        $approval = is_array($activation['approval'] ?? null) ? $activation['approval'] : null;
        $rejection = is_array($activation['rejection'] ?? null) ? $activation['rejection'] : null;

        $proposalSummary = $this->buildProposalSummary($packet);
        $powerGateHuman = $this->buildPowerGateHuman($gate);
        $beforeSnapshotHuman = $this->buildBeforeSnapshotHuman($beforeSnapshot, $activation);
        $createdObra = $this->buildCreatedObra($activation);
        $approvalReceipt = $this->buildApprovalReceiptView($approval);
        $rejectionView = $this->buildRejectionView($rejection);

        $approvalState = $this->approvalState($status, $approval, $rejection);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'activation' => $activation,
            'activation_id' => $this->stringOrNull($activation['activation_id'] ?? null),
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'tone' => $this->statusTone($status),
            'risk_level' => $this->stringOrNull(data_get($packet, 'risk_classification.risk_level'))
                ?? $this->stringOrNull($packet['risk_level'] ?? null),
            'strategy_bucket' => $this->stringOrNull($activation['strategy_bucket'] ?? null),
            'portfolio_deviation' => (bool) ($activation['portfolio_deviation'] ?? false),
            'portfolio_reason' => $this->stringOrNull($activation['portfolio_reason'] ?? null),
            'maturity_target' => $activation['maturity_target'] ?? null,
            'proposal_summary' => $proposalSummary,
            'power_gate' => $powerGateHuman,
            'before_snapshot' => $beforeSnapshotHuman,
            'approval_state' => $approvalState,
            'approval_receipt' => $approvalReceipt,
            'rejection' => $rejectionView,
            'created_obra' => $createdObra,
            'forge_intake_ready' => $createdObra !== null,
            'fast_path_started' => false,
            'open_obra_action' => $createdObra !== null ? [
                'enabled' => true,
                'label' => 'Abrir Obra no Forge',
                'obra_id' => $createdObra['obra_id'],
            ] : [
                'enabled' => false,
                'label' => 'Abrir Obra no Forge',
                'obra_id' => null,
            ],
            'next_action' => (string) ($activation['next_action'] ?? 'inspect_status'),
            'next_safe_action' => $this->nextSafeAction($status, $activation),
            'human_summary' => $this->detailHumanSummary($status, $packet, $gate, $createdObra, $rejection),
            'blockers' => array_values((array) ($activation['blockers'] ?? [])),
            'missing_required_docs' => array_values((array) ($activation['missing_required_docs'] ?? [])),
            'evidence_refs' => array_values((array) ($activation['evidence_refs'] ?? [])),
            'docs' => $activation['docs'] ?? [],
            'commands' => [
                'cli_plan' => 'php artisan atlas:self-improvement:activate-forge --proposal=@payload.json --json',
                'cli_accept' => 'php artisan atlas:self-improvement:activate-forge --proposal-id='.($activation['activation_id'] ?? '').' --approve --reviewer=<who> --reason=<why> --json --strict',
                'cli_reject' => 'php artisan atlas:self-improvement:activate-forge --proposal-id='.($activation['activation_id'] ?? '').' --reject --reviewer=<who> --reason=<why> --json --strict',
            ],
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'auto_fast_path_executed' => false,
            'completion_claim_promoted' => false,
            'separated_from' => 'external_rivals_certification',
            'is_read_model' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blockedDetail(string $activationId): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'activation_id' => $activationId,
            'status' => 'blocked',
            'status_label' => 'Activation não encontrada',
            'tone' => self::TONE_REC_RED,
            'blockers' => ['activation_not_found'],
            'next_action' => 'pick_existing_activation_or_create_new',
            'next_safe_action' => 'Selecionar outra activation ou planejar uma nova proposta.',
            'human_summary' => 'O cockpit não encontrou esta activation no registro local.',
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'auto_fast_path_executed' => false,
            'completion_claim_promoted' => false,
            'separated_from' => 'external_rivals_certification',
            'is_read_model' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    private function buildProposalSummary(array $packet): array
    {
        return [
            'title' => $this->stringOrNull($packet['title'] ?? null) ?? 'Sem título',
            'problem_statement' => $this->stringOrNull($packet['problem_statement'] ?? null),
            'business_rule' => $this->stringOrNull($packet['business_rule'] ?? null),
            'target_capability' => $this->stringOrNull($packet['target_capability'] ?? null),
            'why_now' => $this->stringOrNull($packet['why_now'] ?? null),
            'expected_power_gain' => $this->stringOrNull($packet['expected_power_gain'] ?? null),
            'risk_level' => $this->stringOrNull(data_get($packet, 'risk_classification.risk_level'))
                ?? $this->stringOrNull($packet['risk_level'] ?? null),
            'success_metrics' => array_values((array) ($packet['success_metrics'] ?? [])),
            'acceptance_gates' => array_values((array) ($packet['acceptance_gates'] ?? [])),
            'canonical_docs' => array_values((array) ($packet['canonical_docs'] ?? [])),
            'allowed_paths' => array_values((array) ($packet['allowed_paths'] ?? [])),
            'forbidden_paths' => array_values((array) ($packet['forbidden_paths'] ?? [])),
            'rivals_evaluation_plan' => $this->stringOrNull($packet['rivals_evaluation_plan'] ?? null),
            'rollback_strategy' => $this->stringOrNull($packet['rollback_strategy'] ?? null),
            'test_strategy' => $this->stringOrNull($packet['test_strategy'] ?? null),
            'creates_obra' => true,
            'never_executes_fast_path_automatically' => true,
            'never_calls_provider_without_explicit_approval' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $gate
     * @return array<string,mixed>
     */
    private function buildPowerGateHuman(array $gate): array
    {
        $outcome = $this->stringOrNull($gate['outcome'] ?? null) ?? 'unknown';
        $tone = match ($outcome) {
            AtlasSelfImprovementProposalPowerGateService::OUTCOME_APPROVED => self::TONE_MOSS,
            AtlasSelfImprovementProposalPowerGateService::OUTCOME_HUMAN_REVIEW_REQUIRED,
            AtlasSelfImprovementProposalPowerGateService::OUTCOME_NEEDS_REVISION => self::TONE_BRONZE,
            AtlasSelfImprovementProposalPowerGateService::OUTCOME_REJECTED => self::TONE_REC_RED,
            default => self::TONE_INK,
        };
        $label = match ($outcome) {
            AtlasSelfImprovementProposalPowerGateService::OUTCOME_APPROVED => 'Pode virar Obra, mas ainda não executa Forge.',
            AtlasSelfImprovementProposalPowerGateService::OUTCOME_HUMAN_REVIEW_REQUIRED => 'Humano precisa aprovar antes de criar Obra.',
            AtlasSelfImprovementProposalPowerGateService::OUTCOME_NEEDS_REVISION => 'Precisa melhorar antes de virar Obra.',
            AtlasSelfImprovementProposalPowerGateService::OUTCOME_REJECTED => 'Proposta fraca ou perigosa. Não pode virar Obra.',
            default => 'Estado do power gate desconhecido.',
        };

        return [
            'outcome' => $outcome,
            'label' => $label,
            'tone' => $tone,
            'hard_fails' => array_values((array) ($gate['hard_fails'] ?? [])),
            'soft_findings' => array_values((array) ($gate['soft_findings'] ?? [])),
            'requires_human_review' => (bool) ($gate['requires_human_review'] ?? false),
            'autopromotion_allowed' => (bool) ($gate['autopromotion_allowed'] ?? false),
            'next_action' => $this->stringOrNull($gate['next_action'] ?? null),
            'gate_id' => $this->stringOrNull($gate['gate_id'] ?? null),
        ];
    }

    /**
     * @param  array<string,mixed>  $beforeSnapshot
     * @param  array<string,mixed>  $activation
     * @return array<string,mixed>|null
     */
    private function buildBeforeSnapshotHuman(array $beforeSnapshot, array $activation): ?array
    {
        if ($beforeSnapshot === []) {
            return null;
        }

        $maturity = data_get($beforeSnapshot, 'maturity', []);
        $invariant = data_get($beforeSnapshot, 'invariant_lock.snapshot', []);
        $regression = data_get($beforeSnapshot, 'regression_sentinel.snapshot', []);
        $portfolio = data_get($beforeSnapshot, 'strategy_portfolio.snapshot', []);
        $trust = data_get($beforeSnapshot, 'trust_ledger.snapshot', []);

        $maturityAchieved = data_get($maturity, 'achieved_level');
        $maturityTarget = data_get($maturity, 'target_level');
        $invariantStatus = $this->stringOrNull(data_get($invariant, 'status'));
        $invariantViolations = (array) data_get($invariant, 'violations', []);
        $regressionStatus = $this->stringOrNull(data_get($regression, 'status'));
        $regressionFindings = (array) data_get($regression, 'findings', []);
        $portfolioBalance = $this->stringOrNull(data_get($portfolio, 'balance_health'));
        $trustBand = $this->stringOrNull(data_get($trust, 'summary.trust_band'))
            ?? $this->stringOrNull(data_get($trust, 'summary.trustBand'))
            ?? 'insufficient_data';

        return [
            'schema_version' => $this->stringOrNull(data_get($beforeSnapshot, 'schema_version')),
            'captured_at' => $this->stringOrNull(data_get($beforeSnapshot, 'captured_at')),
            'rationale' => 'Este snapshot serve para comparar se o Atlas melhorou depois.',
            'maturity' => [
                'achieved_level' => $maturityAchieved,
                'target_level' => $maturityTarget,
                'label' => is_numeric($maturityAchieved) && is_numeric($maturityTarget)
                    ? sprintf('%s / %s', $maturityAchieved, $maturityTarget)
                    : 'pendente',
                'hash' => $this->stringOrNull(data_get($beforeSnapshot, 'maturity.hash')),
            ],
            'invariant_lock' => [
                'status' => $invariantStatus ?? 'unknown',
                'tone' => $invariantStatus === 'passed' ? self::TONE_MOSS : self::TONE_REC_RED,
                'violations_count' => count($invariantViolations),
                'hash' => $this->stringOrNull(data_get($beforeSnapshot, 'invariant_lock.hash')),
            ],
            'regression_sentinel' => [
                'status' => $regressionStatus ?? 'unknown',
                'tone' => match ($regressionStatus) {
                    'clear' => self::TONE_MOSS,
                    'findings' => self::TONE_BRONZE,
                    'blocked' => self::TONE_REC_RED,
                    default => self::TONE_INK,
                },
                'findings_count' => count($regressionFindings),
                'hash' => $this->stringOrNull(data_get($beforeSnapshot, 'regression_sentinel.hash')),
            ],
            'strategy_portfolio' => [
                'balance_health' => $portfolioBalance ?? 'unknown',
                'recommended_next_bucket' => $this->stringOrNull(data_get($portfolio, 'recommended_next_bucket')),
                'hash' => $this->stringOrNull(data_get($beforeSnapshot, 'strategy_portfolio.hash')),
            ],
            'trust_ledger' => [
                'trust_band' => $trustBand,
                'entry_count' => (int) (data_get($trust, 'entry_count') ?? 0),
                'hash' => $this->stringOrNull(data_get($beforeSnapshot, 'trust_ledger.hash')),
            ],
            'docs_status' => $this->buildDocsStatus($activation),
        ];
    }

    /**
     * @param  array<string,mixed>  $activation
     * @return array<string,mixed>
     */
    private function buildDocsStatus(array $activation): array
    {
        $docs = is_array($activation['docs'] ?? null) ? $activation['docs'] : [];
        $required = 0;
        $requiredPresent = 0;
        foreach ($docs as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if (($entry['required'] ?? false) === true) {
                $required++;
                if (($entry['present'] ?? false) === true) {
                    $requiredPresent++;
                }
            }
        }

        return [
            'required_count' => $required,
            'required_present_count' => $requiredPresent,
            'missing_required' => array_values((array) ($activation['missing_required_docs'] ?? [])),
            'tone' => $required > 0 && $requiredPresent === $required ? self::TONE_MOSS : self::TONE_REC_RED,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $approval
     * @return array<string,mixed>|null
     */
    private function buildApprovalReceiptView(?array $approval): ?array
    {
        if ($approval === null) {
            return null;
        }

        return [
            'schema_version' => $this->stringOrNull($approval['schema_version'] ?? null),
            'activation_id' => $this->stringOrNull($approval['activation_id'] ?? null),
            'reviewer' => $this->stringOrNull($approval['reviewer'] ?? null),
            'reason' => $this->stringOrNull($approval['reason'] ?? null),
            'approved_at' => $this->stringOrNull($approval['approved_at'] ?? null),
            'receipt_hash' => $this->stringOrNull($approval['receipt_hash'] ?? null),
            'proposal_hash' => $this->stringOrNull($approval['proposal_hash'] ?? null),
            'power_gate_hash' => $this->stringOrNull($approval['power_gate_hash'] ?? null),
            'silent' => (bool) ($approval['silent'] ?? false),
            'auto_promotes_completion_claim' => (bool) ($approval['auto_promotes_completion_claim'] ?? false),
            'auto_executes_fast_path' => (bool) ($approval['auto_executes_fast_path'] ?? false),
            'external_provider_call' => (bool) ($approval['external_provider_call'] ?? false),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $rejection
     * @return array<string,mixed>|null
     */
    private function buildRejectionView(?array $rejection): ?array
    {
        if ($rejection === null) {
            return null;
        }

        return [
            'reviewer' => $this->stringOrNull($rejection['reviewer'] ?? null),
            'reason' => $this->stringOrNull($rejection['reason'] ?? null),
            'rejected_at' => $this->stringOrNull($rejection['rejected_at'] ?? null),
            'silent' => (bool) ($rejection['silent'] ?? false),
        ];
    }

    /**
     * @param  array<string,mixed>  $activation
     * @return array<string,mixed>|null
     */
    private function buildCreatedObra(array $activation): ?array
    {
        $obraId = $this->stringOrNull($activation['created_obra_id'] ?? null);
        if ($obraId === null) {
            return null;
        }

        $intake = is_array($activation['work_intake'] ?? null) ? $activation['work_intake'] : [];
        $intakeId = $this->stringOrNull($intake['intake_id'] ?? null);

        $title = $this->stringOrNull($activation['created_obra_title'] ?? null);
        $status = 'active';
        try {
            $project = AtlasProject::query()->whereKey($obraId)->first();
            if ($project !== null) {
                $title = $this->stringOrNull($project->title) ?? $title;
                $status = (string) ($project->status ?? 'active');
            }
        } catch (Throwable) {
            // Best-effort projection only — read-model never throws on missing rows.
        }

        return [
            'obra_id' => $obraId,
            'title' => $title ?? 'Self-improvement Obra',
            'status' => $status,
            'intake_id' => $intakeId,
            'intake_status' => $intakeId !== null ? 'filled' : 'pending',
            'objective' => $this->stringOrNull($intake['objective'] ?? null),
            'business_rule' => $this->stringOrNull($intake['business_rule'] ?? null),
            'acceptance_criteria_count' => is_array($intake['acceptance_criteria'] ?? null)
                ? count($intake['acceptance_criteria'])
                : 0,
            'canonical_docs_count' => is_array($intake['canonical_docs'] ?? null)
                ? count($intake['canonical_docs'])
                : 0,
            'scope_in' => array_values((array) ($intake['scope_in'] ?? [])),
            'scope_out' => array_values((array) ($intake['scope_out'] ?? [])),
            'risk_level' => $this->stringOrNull($intake['risk_level'] ?? null),
            'fast_path_started' => false,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $approval
     * @param  array<string,mixed>|null  $rejection
     */
    private function approvalState(string $status, ?array $approval, ?array $rejection): string
    {
        if ($status === AtlasSelfImprovementForgeActivationService::STATUS_OBRA_CREATED) {
            return 'accepted_obra_created';
        }
        if ($status === AtlasSelfImprovementForgeActivationService::STATUS_ACCEPTED) {
            return 'accepted_pending_materialise';
        }
        if ($status === AtlasSelfImprovementForgeActivationService::STATUS_REJECTED) {
            return $rejection !== null ? 'rejected_by_human' : 'rejected_by_gate';
        }
        if ($status === AtlasSelfImprovementForgeActivationService::STATUS_PENDING_HUMAN_REVIEW) {
            return 'awaiting_human_review';
        }
        if ($status === AtlasSelfImprovementForgeActivationService::STATUS_NEEDS_REVISION) {
            return 'needs_revision';
        }
        if ($status === AtlasSelfImprovementForgeActivationService::STATUS_DRY_RUN) {
            return 'dry_run_planned';
        }
        if ($approval !== null) {
            return 'accepted_obra_created';
        }

        return 'blocked';
    }

    /**
     * @param  array<string,mixed>|null  $activation
     */
    private function nextSafeAction(string $status, ?array $activation): string
    {
        $blockers = is_array($activation['blockers'] ?? null) ? $activation['blockers'] : [];
        $missingDocs = is_array($activation['missing_required_docs'] ?? null) ? $activation['missing_required_docs'] : [];

        if ($missingDocs !== []) {
            return 'Adicionar docs canônicas obrigatórias antes de aprovar.';
        }
        if ($status === AtlasSelfImprovementForgeActivationService::STATUS_OBRA_CREATED) {
            return 'Abrir Obra no Forge — Fast Path NÃO executa sozinho.';
        }
        if ($status === AtlasSelfImprovementForgeActivationService::STATUS_PENDING_HUMAN_REVIEW) {
            return 'Revisor humano deve aprovar com reviewer + reason.';
        }
        if ($status === AtlasSelfImprovementForgeActivationService::STATUS_NEEDS_REVISION) {
            return 'Reescrever proposta para corrigir hard fails do power gate.';
        }
        if ($status === AtlasSelfImprovementForgeActivationService::STATUS_REJECTED) {
            return 'Reescrever proposta ou encerrar — nada virou Obra.';
        }
        if ($status === AtlasSelfImprovementForgeActivationService::STATUS_BLOCKED) {
            return 'Resolver blockers (' . implode(', ', $blockers) . ') antes de replanejar.';
        }
        if ($status === AtlasSelfImprovementForgeActivationService::STATUS_DRY_RUN) {
            return 'Replanejar sem dry-run para registrar a activation.';
        }
        if ($status === AtlasSelfImprovementForgeActivationService::STATUS_ACCEPTED) {
            return 'Confirmar criação da Obra — Forge não executa automaticamente.';
        }

        return 'Inspecionar status no detalhe.';
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            AtlasSelfImprovementForgeActivationService::STATUS_BLOCKED => 'Bloqueada',
            AtlasSelfImprovementForgeActivationService::STATUS_NEEDS_REVISION => 'Precisa revisão',
            AtlasSelfImprovementForgeActivationService::STATUS_PENDING_HUMAN_REVIEW => 'Aguardando humano',
            AtlasSelfImprovementForgeActivationService::STATUS_REJECTED => 'Rejeitada',
            AtlasSelfImprovementForgeActivationService::STATUS_ACCEPTED => 'Aceita',
            AtlasSelfImprovementForgeActivationService::STATUS_OBRA_CREATED => 'Obra criada',
            AtlasSelfImprovementForgeActivationService::STATUS_DRY_RUN => 'Dry-run',
            default => 'Desconhecido',
        };
    }

    private function statusTone(string $status): string
    {
        return match ($status) {
            AtlasSelfImprovementForgeActivationService::STATUS_OBRA_CREATED,
            AtlasSelfImprovementForgeActivationService::STATUS_ACCEPTED => self::TONE_MOSS,
            AtlasSelfImprovementForgeActivationService::STATUS_PENDING_HUMAN_REVIEW,
            AtlasSelfImprovementForgeActivationService::STATUS_NEEDS_REVISION,
            AtlasSelfImprovementForgeActivationService::STATUS_DRY_RUN => self::TONE_BRONZE,
            AtlasSelfImprovementForgeActivationService::STATUS_REJECTED,
            AtlasSelfImprovementForgeActivationService::STATUS_BLOCKED => self::TONE_REC_RED,
            default => self::TONE_INK,
        };
    }

    /**
     * @return array<string,int>
     */
    private function emptyCounters(): array
    {
        return [
            'total' => 0,
            'blocked' => 0,
            'needs_revision' => 0,
            'pending_human_review' => 0,
            'rejected' => 0,
            'accepted' => 0,
            'obra_created' => 0,
            'dry_run_planned' => 0,
            'with_obra' => 0,
            'with_blockers' => 0,
        ];
    }

    /**
     * @param  array<string,int>  $counters
     * @param  array<string,mixed>  $row
     */
    private function incrementCounters(array &$counters, array $row): void
    {
        $counters['total']++;
        $status = (string) ($row['status'] ?? 'blocked');
        if (isset($counters[$status])) {
            $counters[$status]++;
        }
        if (($row['created_obra_id'] ?? null) !== null) {
            $counters['with_obra']++;
        }
        if (($row['has_blockers'] ?? false) === true) {
            $counters['with_blockers']++;
        }
    }

    /**
     * @param  array<string,int>  $counters
     * @param  array<string,mixed>|null  $selected
     */
    private function cockpitHumanSummary(array $counters, ?array $selected): string
    {
        if ($selected !== null) {
            $label = (string) ($selected['status_label'] ?? 'Activation');
            $title = (string) (data_get($selected, 'proposal_summary.title') ?? 'sem título');

            return sprintf('%s — %s. %s', $label, $title, (string) ($selected['next_safe_action'] ?? ''));
        }
        if ($counters['total'] === 0) {
            return 'Nenhuma activation registrada. Crie uma proposta para começar.';
        }
        $pending = $counters['pending_human_review'] + $counters['needs_revision'];
        if ($pending > 0) {
            return sprintf('%d activations pedindo revisão humana, %d viraram Obra.', $pending, $counters['obra_created']);
        }

        return sprintf('%d activations no total · %d criaram Obra · %d rejeitadas.',
            $counters['total'],
            $counters['obra_created'],
            $counters['rejected'],
        );
    }

    /**
     * @param  array<string,int>  $counters
     * @param  array<string,mixed>|null  $selected
     */
    private function cockpitNextSafeAction(array $counters, ?array $selected): string
    {
        if ($selected !== null) {
            return (string) ($selected['next_safe_action'] ?? 'Inspecionar activation.');
        }
        if (($counters['pending_human_review'] ?? 0) > 0) {
            return 'Revisar activations aguardando humano antes de criar novas propostas.';
        }
        if ($counters['total'] === 0) {
            return 'Planejar primeira proposta via CLI ou POST /atlas-code/self-improvement/forge-activations.';
        }

        return 'Manter ciclo: planejar → revisar → aceitar/rejeitar → abrir Obra no Forge.';
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  array<string,mixed>  $gate
     * @param  array<string,mixed>|null  $createdObra
     * @param  array<string,mixed>|null  $rejection
     */
    private function detailHumanSummary(string $status, array $packet, array $gate, ?array $createdObra, ?array $rejection): string
    {
        $title = $this->stringOrNull($packet['title'] ?? null) ?? 'Proposta';

        return match ($status) {
            AtlasSelfImprovementForgeActivationService::STATUS_OBRA_CREATED => sprintf('Obra criada a partir de "%s" — abrir no Forge para continuar.', $title),
            AtlasSelfImprovementForgeActivationService::STATUS_ACCEPTED => sprintf('Aceita "%s", aguardando materialização da Obra.', $title),
            AtlasSelfImprovementForgeActivationService::STATUS_PENDING_HUMAN_REVIEW => sprintf('"%s" passou no power gate mas exige aprovação humana explícita.', $title),
            AtlasSelfImprovementForgeActivationService::STATUS_NEEDS_REVISION => sprintf('"%s" tem hard fails que precisam ser corrigidos antes de virar Obra.', $title),
            AtlasSelfImprovementForgeActivationService::STATUS_REJECTED => sprintf('"%s" foi rejeitada%s.', $title, $rejection !== null ? ' por um humano' : ' pelo power gate'),
            AtlasSelfImprovementForgeActivationService::STATUS_BLOCKED => sprintf('"%s" está bloqueada por blockers de governança.', $title),
            AtlasSelfImprovementForgeActivationService::STATUS_DRY_RUN => sprintf('"%s" é um dry-run; nada foi persistido.', $title),
            default => sprintf('"%s" em estado %s.', $title, $status),
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function safePortfolio(): array
    {
        try {
            return $this->strategyPortfolio->snapshot([]);
        } catch (Throwable) {
            return [
                'schema_version' => 'atlas.self_improvement.strategy_portfolio.v1',
                'buckets' => [],
                'balance_health' => 'unknown',
                'recommended_next_bucket' => null,
                'is_read_model' => true,
                'external_provider_call' => false,
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function safeTrustLedger(): array
    {
        try {
            return $this->humanTrustLedger->snapshot(null);
        } catch (Throwable) {
            return [
                'schema_version' => 'atlas.self_improvement.human_trust_ledger.v1',
                'entry_count' => 0,
                'entries' => [],
                'summary' => ['trust_band' => 'insufficient_data'],
                'external_provider_call' => false,
            ];
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function boolOrNull(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if ($value === '') {
                return null;
            }
            if (in_array($value, ['1', 'true', 'yes', 'y'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'no', 'n'], true)) {
                return false;
            }
        }
        if (is_int($value)) {
            return $value !== 0;
        }

        return null;
    }
}
