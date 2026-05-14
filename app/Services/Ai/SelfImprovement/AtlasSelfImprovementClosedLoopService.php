<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasCodeForgeWorkIntakeService;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Atlas Self-Improvement Closed Loop v1 (Level 7).
 *
 * Pure projection layer. Given a `proposal_id` it rebuilds the complete
 * state across the closed loop:
 *
 *   proposal → power gate → activation → obra → forge → evidence →
 *   human review → delta scorecard → trust update → learning packet →
 *   next-cycle recommendation
 *
 * The service NEVER mutates state. All mutations remain on the source
 * services (`ProposalBacklogService::evaluateProposal`,
 * `ForgeActivationService::accept`, `ResultLedgerService::record`, etc).
 *
 * Hard rules:
 *   - Read-only projection.
 *   - NEVER calls a provider.
 *   - NEVER auto-executes Fast Path.
 *   - NEVER promotes completion claim.
 *
 * Schema: atlas.self_improvement.closed_loop.v1
 */
class AtlasSelfImprovementClosedLoopService
{
    public const SCHEMA_VERSION = 'atlas.self_improvement.closed_loop.v1';

    // 12 canonical loop stages (in order). The orchestrator computes the
    // operator's CURRENT stage (the furthest completed) and reports it
    // alongside the full stage map so the UI can render a pipeline.
    public const STAGE_PROPOSAL_CAPTURED = 'proposal_captured';
    public const STAGE_POWER_GATE_EVALUATED = 'power_gate_evaluated';
    public const STAGE_HUMAN_APPROVED = 'human_approved';
    public const STAGE_ACTIVATION_CREATED = 'activation_created';
    public const STAGE_OBRA_CREATED = 'obra_created';
    public const STAGE_FORGE_EXECUTED = 'forge_executed';
    public const STAGE_EVIDENCE_COLLECTED = 'evidence_collected';
    public const STAGE_HUMAN_REVIEWED = 'human_reviewed';
    public const STAGE_DELTA_MEASURED = 'delta_measured';
    public const STAGE_TRUST_UPDATED = 'trust_updated';
    public const STAGE_LEARNING_RECORDED = 'learning_recorded';
    public const STAGE_NEXT_CYCLE_RECOMMENDED = 'next_cycle_recommended';

    /** @var list<string> */
    public const STAGES = [
        self::STAGE_PROPOSAL_CAPTURED,
        self::STAGE_POWER_GATE_EVALUATED,
        self::STAGE_HUMAN_APPROVED,
        self::STAGE_ACTIVATION_CREATED,
        self::STAGE_OBRA_CREATED,
        self::STAGE_FORGE_EXECUTED,
        self::STAGE_EVIDENCE_COLLECTED,
        self::STAGE_HUMAN_REVIEWED,
        self::STAGE_DELTA_MEASURED,
        self::STAGE_TRUST_UPDATED,
        self::STAGE_LEARNING_RECORDED,
        self::STAGE_NEXT_CYCLE_RECOMMENDED,
    ];

    public function __construct(
        private readonly AtlasSelfImprovementProposalBacklogService $proposalBacklog,
        private readonly AtlasSelfImprovementForgeActivationService $forgeActivation,
        private readonly AtlasSelfImprovementResultLedgerService $resultLedger,
        private readonly AtlasSelfImprovementNextCycleRecommendationService $nextCycle,
        private readonly AtlasSelfImprovementHumanTrustLedgerService $humanTrustLedger,
        private readonly AtlasCodeForgeWorkIntakeService $forgeWorkIntake,
    ) {}

    /**
     * Project the full closed-loop state for a proposal.
     *
     * @return array<string,mixed>
     */
    public function project(string $proposalId): array
    {
        $proposal = $this->proposalBacklog->getProposal($proposalId);
        if ($proposal === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'blocked',
                'proposal_id' => $proposalId,
                'current_loop_stage' => null,
                'loop_health' => 'blocked',
                'blockers' => ['proposal_not_found'],
                'next_safe_action' => 'list_backlog_to_find_valid_proposal_id',
                'stages' => $this->emptyStageMap(),
                'human_decision_required' => false,
                'can_activate' => false,
                'can_open_obra' => false,
                'can_measure_delta' => false,
                'can_record_learning' => false,
                'before_snapshot' => null,
                'after_snapshot' => null,
                'delta_scorecard' => null,
                'trust_update' => null,
                'learning_packet' => null,
                'evidence_summary' => null,
                'review_summary' => null,
                'next_cycle_recommendation' => null,
                'external_provider_call' => false,
                'provider_tokens_spent' => false,
                'auto_fast_path_executed' => false,
                'completion_claim_promoted' => false,
                'external_rivals_separated' => true,
                'separated_from' => 'external_rivals_certification',
                'is_read_model' => true,
            ];
        }

        $activationId = $this->stringOrNull($proposal['linked_activation_id'] ?? null);
        $obraId = $this->stringOrNull($proposal['linked_obra_id'] ?? null);
        $resultEntryId = $this->stringOrNull($proposal['linked_result_entry_id'] ?? null);

        $activation = $activationId !== null ? $this->forgeActivation->get($activationId) : null;

        $obraSnapshot = null;
        $workIntake = null;
        if ($obraId !== null) {
            try {
                $project = AtlasProject::query()->whereKey($obraId)->first();
                if ($project !== null) {
                    $obraSnapshot = [
                        'obra_id' => $obraId,
                        'title' => (string) ($project->title ?? ''),
                        'status' => (string) ($project->status ?? 'active'),
                        'domain' => (string) ($project->domain ?? 'programming'),
                        'metadata_keys' => array_keys(is_array($project->metadata ?? null) ? $project->metadata : []),
                    ];
                    try {
                        $workIntake = $this->forgeWorkIntake->get($project);
                    } catch (Throwable) {
                        $workIntake = null;
                    }
                }
            } catch (Throwable) {
                // best-effort projection only
            }
        }

        $entries = $resultEntryId !== null
            ? array_values(array_filter([$this->resultLedger->find($resultEntryId)], fn (mixed $e): bool => is_array($e)))
            : $this->resultLedger->listForProposal($proposalId);
        $latestEntry = $entries[0] ?? null;

        $trustSnapshot = null;
        if ($obraId !== null) {
            try {
                $project = isset($project) ? $project : AtlasProject::query()->whereKey($obraId)->first();
                $trustSnapshot = $this->humanTrustLedger->snapshot($project);
            } catch (Throwable) {
                $trustSnapshot = null;
            }
        }

        $nextRecommendation = $this->nextCycle->recommend($latestEntry, $proposal);

        $stages = $this->computeStages($proposal, $activation, $obraSnapshot, $workIntake, $latestEntry, $trustSnapshot, $nextRecommendation);
        $currentStage = $this->resolveCurrentStage($stages);
        $loopHealth = $this->resolveLoopHealth($stages, $latestEntry, (array) $proposal['blockers']);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'status' => $loopHealth === 'blocked' ? 'blocked' : 'projected',
            'proposal_id' => $proposalId,
            'activation_id' => $activationId,
            'obra_id' => $obraId,
            'result_entry_id' => $resultEntryId,
            'current_loop_stage' => $currentStage,
            'loop_health' => $loopHealth,
            'stages' => $stages,
            'human_decision_required' => $this->humanDecisionRequired($stages, $proposal, $latestEntry),
            'can_activate' => $this->canActivate($proposal, $activation),
            'can_open_obra' => $obraId !== null,
            'can_measure_delta' => $this->canMeasureDelta($proposal, $obraId, $latestEntry),
            'can_record_learning' => $latestEntry !== null,
            'before_snapshot' => is_array($activation['before_snapshot'] ?? null) ? $activation['before_snapshot'] : null,
            'after_snapshot' => $latestEntry !== null
                ? [
                    'hash' => $latestEntry['after_snapshot_hash'] ?? null,
                    'recorded_at' => $latestEntry['recorded_at'] ?? null,
                ]
                : null,
            'delta_scorecard' => is_array($latestEntry['delta_scorecard'] ?? null) ? $latestEntry['delta_scorecard'] : null,
            'trust_update' => $latestEntry !== null
                ? [
                    'outcome_recorded' => $latestEntry['trust_outcome_recorded'] ?? null,
                    'trust_delta' => $latestEntry['trust_delta'] ?? 0.0,
                    'current_band' => data_get($trustSnapshot, 'summary.trust_band') ?? 'insufficient_data',
                ]
                : null,
            'learning_packet' => is_array($latestEntry['learning_packet'] ?? null) ? $latestEntry['learning_packet'] : null,
            'evidence_summary' => $this->buildEvidenceSummary($proposal, $activation, $latestEntry),
            'review_summary' => $this->buildReviewSummary($activation, $latestEntry),
            'next_cycle_recommendation' => $nextRecommendation,
            'next_safe_action' => $this->resolveNextSafeAction($stages, $loopHealth, $proposal, $latestEntry),
            'blockers' => array_values((array) $proposal['blockers']),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'auto_fast_path_executed' => false,
            'completion_claim_promoted' => false,
            'external_rivals_separated' => true,
            'separated_from' => 'external_rivals_certification',
            'is_read_model' => true,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Stage computation

    /**
     * @param  array<string,mixed>  $proposal
     * @param  array<string,mixed>|null  $activation
     * @param  array<string,mixed>|null  $obraSnapshot
     * @param  array<string,mixed>|null  $workIntake
     * @param  array<string,mixed>|null  $resultEntry
     * @param  array<string,mixed>|null  $trustSnapshot
     * @param  array<string,mixed>  $nextRecommendation
     * @return array<string,array<string,mixed>>
     */
    private function computeStages(array $proposal, ?array $activation, ?array $obraSnapshot, ?array $workIntake, ?array $resultEntry, ?array $trustSnapshot, array $nextRecommendation): array
    {
        $stages = [];
        foreach (self::STAGES as $stage) {
            $stages[$stage] = [
                'stage' => $stage,
                'status' => 'todo',
                'label' => $this->stageLabel($stage),
                'tone' => 'ink',
                'evidence' => null,
                'completed_at' => null,
            ];
        }

        // 1. proposal_captured — always done if proposal exists.
        $stages[self::STAGE_PROPOSAL_CAPTURED]['status'] = 'done';
        $stages[self::STAGE_PROPOSAL_CAPTURED]['tone'] = 'moss';
        $stages[self::STAGE_PROPOSAL_CAPTURED]['completed_at'] = $this->stringOrNull($proposal['created_at'] ?? null);
        $stages[self::STAGE_PROPOSAL_CAPTURED]['evidence'] = 'proposal:'.($proposal['proposal_id'] ?? '');

        // 2. power_gate_evaluated.
        $gate = is_array($proposal['power_gate'] ?? null) ? $proposal['power_gate'] : null;
        if ($gate !== null) {
            $outcome = (string) ($gate['outcome'] ?? 'unknown');
            $stages[self::STAGE_POWER_GATE_EVALUATED]['status'] = 'done';
            $stages[self::STAGE_POWER_GATE_EVALUATED]['tone'] = match ($outcome) {
                'approved' => 'moss',
                'human_review_required', 'needs_revision' => 'bronze',
                'rejected' => 'rec-red',
                default => 'ink',
            };
            $stages[self::STAGE_POWER_GATE_EVALUATED]['evidence'] = 'power_gate:'.($gate['gate_id'] ?? 'unknown');
            $stages[self::STAGE_POWER_GATE_EVALUATED]['completed_at'] = $this->stringOrNull($proposal['updated_at'] ?? null);

            if (in_array($outcome, ['rejected', 'needs_revision'], true)) {
                $stages[self::STAGE_POWER_GATE_EVALUATED]['status'] = 'blocked';
            }
        }

        // 3. human_approved — true when proposal is APPROVED_FOR_ACTIVATION
        //    or beyond (activated, obra_created, etc).
        $status = (string) $proposal['status'];
        if (in_array($status, [
            AtlasSelfImprovementProposalBacklogService::STATUS_APPROVED_FOR_ACTIVATION,
            AtlasSelfImprovementProposalBacklogService::STATUS_ACTIVATED,
            AtlasSelfImprovementProposalBacklogService::STATUS_OBRA_CREATED,
            AtlasSelfImprovementProposalBacklogService::STATUS_FORGE_RUNNING,
            AtlasSelfImprovementProposalBacklogService::STATUS_AWAITING_REVIEW,
            AtlasSelfImprovementProposalBacklogService::STATUS_MEASURING_DELTA,
            AtlasSelfImprovementProposalBacklogService::STATUS_LEARNED,
        ], true)) {
            $stages[self::STAGE_HUMAN_APPROVED]['status'] = 'done';
            $stages[self::STAGE_HUMAN_APPROVED]['tone'] = 'moss';
            $stages[self::STAGE_HUMAN_APPROVED]['completed_at'] = $this->stringOrNull($proposal['updated_at'] ?? null);
        }

        // 4. activation_created.
        if ($activation !== null) {
            $stages[self::STAGE_ACTIVATION_CREATED]['status'] = 'done';
            $stages[self::STAGE_ACTIVATION_CREATED]['tone'] = 'moss';
            $stages[self::STAGE_ACTIVATION_CREATED]['evidence'] = 'activation:'.($activation['activation_id'] ?? '');
            $stages[self::STAGE_ACTIVATION_CREATED]['completed_at'] = $this->stringOrNull($activation['generated_at'] ?? null);
        }

        // 5. obra_created.
        if ($obraSnapshot !== null) {
            $stages[self::STAGE_OBRA_CREATED]['status'] = 'done';
            $stages[self::STAGE_OBRA_CREATED]['tone'] = 'moss';
            $stages[self::STAGE_OBRA_CREATED]['evidence'] = 'obra:'.$obraSnapshot['obra_id'];
        }

        // 6. forge_executed — heuristic: linked_fast_path_run_id present.
        if ($this->stringOrNull($proposal['linked_fast_path_run_id'] ?? null) !== null) {
            $stages[self::STAGE_FORGE_EXECUTED]['status'] = 'done';
            $stages[self::STAGE_FORGE_EXECUTED]['tone'] = 'moss';
            $stages[self::STAGE_FORGE_EXECUTED]['evidence'] = 'fast_path_run:'.$proposal['linked_fast_path_run_id'];
        }

        // 7. evidence_collected — workIntake AND/OR evidence_refs.
        $evidenceCount = count((array) ($proposal['evidence_refs'] ?? []));
        if ($workIntake !== null || $evidenceCount > 0) {
            $stages[self::STAGE_EVIDENCE_COLLECTED]['status'] = 'done';
            $stages[self::STAGE_EVIDENCE_COLLECTED]['tone'] = 'moss';
            $stages[self::STAGE_EVIDENCE_COLLECTED]['evidence'] = 'evidence_refs_count:'.$evidenceCount;
        }

        // 8. human_reviewed — heuristic: linked_completion_claim_id present OR
        //    review_outcome in resultEntry.
        $reviewOutcome = $resultEntry !== null
            ? $this->stringOrNull($resultEntry['human_review_outcome'] ?? null)
            : null;
        $completionClaimId = $this->stringOrNull($proposal['linked_completion_claim_id'] ?? null);
        if ($reviewOutcome !== null || $completionClaimId !== null) {
            $stages[self::STAGE_HUMAN_REVIEWED]['status'] = 'done';
            $stages[self::STAGE_HUMAN_REVIEWED]['tone'] = 'moss';
            $stages[self::STAGE_HUMAN_REVIEWED]['evidence'] = $completionClaimId !== null
                ? 'completion_claim:'.$completionClaimId
                : 'review_outcome:'.$reviewOutcome;
        }

        // 9. delta_measured.
        if ($resultEntry !== null) {
            $grade = (string) ($resultEntry['delta_grade'] ?? 'unknown');
            $stages[self::STAGE_DELTA_MEASURED]['status'] = 'done';
            $stages[self::STAGE_DELTA_MEASURED]['tone'] = match ($grade) {
                'major_improvement', 'improved' => 'moss',
                'neutral' => 'bronze',
                'regressed', 'invalid' => 'rec-red',
                default => 'ink',
            };
            $stages[self::STAGE_DELTA_MEASURED]['evidence'] = 'result_entry:'.($resultEntry['result_entry_id'] ?? '');
            $stages[self::STAGE_DELTA_MEASURED]['completed_at'] = $this->stringOrNull($resultEntry['recorded_at'] ?? null);
        }

        // 10. trust_updated.
        if ($resultEntry !== null && $this->stringOrNull($resultEntry['trust_outcome_recorded'] ?? null) !== null) {
            $stages[self::STAGE_TRUST_UPDATED]['status'] = 'done';
            $stages[self::STAGE_TRUST_UPDATED]['tone'] = 'moss';
            $stages[self::STAGE_TRUST_UPDATED]['evidence'] = 'trust_outcome:'.$resultEntry['trust_outcome_recorded'];
        }

        // 11. learning_recorded.
        if (is_array($resultEntry['learning_packet'] ?? null)) {
            $stages[self::STAGE_LEARNING_RECORDED]['status'] = 'done';
            $stages[self::STAGE_LEARNING_RECORDED]['tone'] = 'moss';
            $stages[self::STAGE_LEARNING_RECORDED]['evidence'] = 'learning_packet:'.(data_get($resultEntry, 'learning_packet.what_changed') ?? 'unspecified');
        }

        // 12. next_cycle_recommended.
        if ($this->stringOrNull($nextRecommendation['recommendation'] ?? null) !== null) {
            $stages[self::STAGE_NEXT_CYCLE_RECOMMENDED]['status'] = 'done';
            $stages[self::STAGE_NEXT_CYCLE_RECOMMENDED]['tone'] = 'moss';
            $stages[self::STAGE_NEXT_CYCLE_RECOMMENDED]['evidence'] = 'recommendation:'.$nextRecommendation['recommendation'];
        }

        return $stages;
    }

    /**
     * @param  array<string,array<string,mixed>>  $stages
     */
    private function resolveCurrentStage(array $stages): string
    {
        $current = self::STAGE_PROPOSAL_CAPTURED;
        foreach (self::STAGES as $stage) {
            if (($stages[$stage]['status'] ?? 'todo') === 'done') {
                $current = $stage;
            }
        }

        return $current;
    }

    /**
     * @param  array<string,array<string,mixed>>  $stages
     * @param  array<string,mixed>|null  $latestEntry
     * @param  list<string>  $blockers
     */
    private function resolveLoopHealth(array $stages, ?array $latestEntry, array $blockers): string
    {
        if ($blockers !== []) {
            return 'blocked';
        }
        foreach ($stages as $stage) {
            if (($stage['status'] ?? 'todo') === 'blocked') {
                return 'blocked';
            }
        }
        if ($latestEntry !== null && in_array((string) ($latestEntry['delta_grade'] ?? ''), ['regressed', 'invalid'], true)) {
            return 'regressed';
        }
        if (($stages[self::STAGE_NEXT_CYCLE_RECOMMENDED]['status'] ?? 'todo') === 'done') {
            return 'closed_loop_complete';
        }
        if (($stages[self::STAGE_DELTA_MEASURED]['status'] ?? 'todo') === 'done') {
            return 'measured';
        }
        if (($stages[self::STAGE_OBRA_CREATED]['status'] ?? 'todo') === 'done') {
            return 'in_flight';
        }
        if (($stages[self::STAGE_HUMAN_APPROVED]['status'] ?? 'todo') === 'done') {
            return 'approved_pending_activation';
        }
        if (($stages[self::STAGE_POWER_GATE_EVALUATED]['status'] ?? 'todo') === 'done') {
            return 'evaluating';
        }

        return 'draft';
    }

    /**
     * @param  array<string,array<string,mixed>>  $stages
     * @param  array<string,mixed>  $proposal
     * @param  array<string,mixed>|null  $latestEntry
     */
    private function humanDecisionRequired(array $stages, array $proposal, ?array $latestEntry): bool
    {
        $status = (string) $proposal['status'];
        if ($status === AtlasSelfImprovementProposalBacklogService::STATUS_PENDING_HUMAN_REVIEW) {
            return true;
        }
        if ($status === AtlasSelfImprovementProposalBacklogService::STATUS_APPROVED_FOR_ACTIVATION) {
            return true;
        }
        if (($stages[self::STAGE_OBRA_CREATED]['status'] ?? 'todo') === 'done'
            && ($stages[self::STAGE_DELTA_MEASURED]['status'] ?? 'todo') !== 'done') {
            return true;
        }
        if ($latestEntry !== null && in_array((string) ($latestEntry['delta_grade'] ?? ''), ['regressed', 'invalid'], true)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @param  array<string,mixed>|null  $activation
     */
    private function canActivate(array $proposal, ?array $activation): bool
    {
        $status = (string) $proposal['status'];
        if (in_array($status, [
            AtlasSelfImprovementProposalBacklogService::STATUS_REJECTED,
            AtlasSelfImprovementProposalBacklogService::STATUS_ARCHIVED,
        ], true)) {
            return false;
        }
        if ($activation !== null && $this->stringOrNull($activation['created_obra_id'] ?? null) !== null) {
            return false;
        }
        if (! in_array($status, [
            AtlasSelfImprovementProposalBacklogService::STATUS_APPROVED_FOR_ACTIVATION,
            AtlasSelfImprovementProposalBacklogService::STATUS_PENDING_HUMAN_REVIEW,
        ], true)) {
            return false;
        }

        return ((array) ($proposal['blockers'] ?? [])) === [];
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @param  array<string,mixed>|null  $latestEntry
     */
    private function canMeasureDelta(array $proposal, ?string $obraId, ?array $latestEntry): bool
    {
        if ($obraId === null) {
            return false;
        }
        if ($latestEntry !== null) {
            return false;
        }
        if (((array) ($proposal['blockers'] ?? [])) !== []) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @param  array<string,mixed>|null  $activation
     * @param  array<string,mixed>|null  $latestEntry
     * @return array<string,mixed>
     */
    private function buildEvidenceSummary(array $proposal, ?array $activation, ?array $latestEntry): array
    {
        $refs = array_values(array_unique(array_merge(
            (array) ($proposal['evidence_refs'] ?? []),
            (array) ($activation['evidence_refs'] ?? []),
            (array) ($latestEntry['evidence_refs'] ?? []),
        )));

        return [
            'evidence_ref_count' => count($refs),
            'evidence_refs' => $refs,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $activation
     * @param  array<string,mixed>|null  $latestEntry
     * @return array<string,mixed>
     */
    private function buildReviewSummary(?array $activation, ?array $latestEntry): array
    {
        return [
            'activation_approval_present' => is_array($activation['approval'] ?? null),
            'activation_reviewer' => $this->stringOrNull(data_get($activation, 'approval.reviewer')),
            'result_entry_reviewer' => $this->stringOrNull($latestEntry['reviewer'] ?? null),
            'result_entry_grade' => $this->stringOrNull($latestEntry['delta_grade'] ?? null),
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $stages
     * @param  array<string,mixed>  $proposal
     * @param  array<string,mixed>|null  $latestEntry
     */
    private function resolveNextSafeAction(array $stages, string $loopHealth, array $proposal, ?array $latestEntry): string
    {
        if ($loopHealth === 'blocked') {
            $blockers = (array) ($proposal['blockers'] ?? []);
            if ($blockers !== []) {
                return 'resolve_blockers:'.implode(',', array_slice($blockers, 0, 3));
            }

            return 'fix_failed_power_gate_or_archive_proposal';
        }
        if (($stages[self::STAGE_POWER_GATE_EVALUATED]['status'] ?? 'todo') !== 'done') {
            return 'evaluate_proposal_through_power_gate';
        }
        if (($stages[self::STAGE_HUMAN_APPROVED]['status'] ?? 'todo') !== 'done') {
            return 'human_approve_or_request_revision';
        }
        if (($stages[self::STAGE_ACTIVATION_CREATED]['status'] ?? 'todo') !== 'done') {
            return 'create_activation_via_atlas:self-improvement:activate-forge';
        }
        if (($stages[self::STAGE_OBRA_CREATED]['status'] ?? 'todo') !== 'done') {
            return 'accept_activation_to_materialise_obra';
        }
        if (($stages[self::STAGE_FORGE_EXECUTED]['status'] ?? 'todo') !== 'done') {
            return 'execute_forge_in_atlas_code_when_ready';
        }
        if (($stages[self::STAGE_HUMAN_REVIEWED]['status'] ?? 'todo') !== 'done') {
            return 'review_completion_claim_in_forge';
        }
        if (($stages[self::STAGE_DELTA_MEASURED]['status'] ?? 'todo') !== 'done') {
            return 'measure_result_via_atlas:self-improvement:measure-result';
        }
        if ($latestEntry !== null && in_array((string) ($latestEntry['delta_grade'] ?? ''), ['regressed', 'invalid'], true)) {
            return 'follow_recommendation:repair_regression_or_gather_more_evidence';
        }
        if (($stages[self::STAGE_NEXT_CYCLE_RECOMMENDED]['status'] ?? 'todo') !== 'done') {
            return 'review_next_cycle_recommendation';
        }

        return 'closed_loop_complete_for_this_proposal';
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function emptyStageMap(): array
    {
        $map = [];
        foreach (self::STAGES as $stage) {
            $map[$stage] = [
                'stage' => $stage,
                'status' => 'todo',
                'label' => $this->stageLabel($stage),
                'tone' => 'ink',
                'evidence' => null,
                'completed_at' => null,
            ];
        }

        return $map;
    }

    private function stageLabel(string $stage): string
    {
        return match ($stage) {
            self::STAGE_PROPOSAL_CAPTURED => 'Proposta capturada',
            self::STAGE_POWER_GATE_EVALUATED => 'Power Gate avaliado',
            self::STAGE_HUMAN_APPROVED => 'Humano aprovou',
            self::STAGE_ACTIVATION_CREATED => 'Activation criada',
            self::STAGE_OBRA_CREATED => 'Obra materializada',
            self::STAGE_FORGE_EXECUTED => 'Forge executou',
            self::STAGE_EVIDENCE_COLLECTED => 'Evidências coletadas',
            self::STAGE_HUMAN_REVIEWED => 'Humano revisou',
            self::STAGE_DELTA_MEASURED => 'Delta medido',
            self::STAGE_TRUST_UPDATED => 'Trust ledger atualizado',
            self::STAGE_LEARNING_RECORDED => 'Aprendizado registrado',
            self::STAGE_NEXT_CYCLE_RECOMMENDED => 'Próximo ciclo recomendado',
            default => $stage,
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
