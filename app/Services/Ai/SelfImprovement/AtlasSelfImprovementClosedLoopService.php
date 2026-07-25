<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasCodeForgeWorkIntakeService;
use App\Services\Ai\SelfImprovement\Support\ClosedLoopStageProjector;
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
 *
 * Stage-map / health / capability flags are pure in
 * {@see ClosedLoopStageProjector}; this class owns I/O assembly only.
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
                'stages' => ClosedLoopStageProjector::emptyStageMap(),
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

        $stages = ClosedLoopStageProjector::computeStages(
            $proposal,
            $activation,
            $obraSnapshot,
            $workIntake,
            $latestEntry,
            $trustSnapshot,
            $nextRecommendation,
        );
        $currentStage = ClosedLoopStageProjector::resolveCurrentStage($stages);
        $loopHealth = ClosedLoopStageProjector::resolveLoopHealth($stages, $latestEntry, (array) $proposal['blockers']);

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
            'human_decision_required' => ClosedLoopStageProjector::humanDecisionRequired($stages, $proposal, $latestEntry),
            'can_activate' => ClosedLoopStageProjector::canActivate($proposal, $activation),
            'can_open_obra' => $obraId !== null,
            'can_measure_delta' => ClosedLoopStageProjector::canMeasureDelta($proposal, $obraId, $latestEntry),
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
    // Summary builders (service-local; stage pure logic lives on projector)

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

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
