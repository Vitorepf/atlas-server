<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Atlas Self-Improvement Proposal Backlog v1.
 *
 * Persistent local backlog of self-improvement proposals BEFORE they become
 * activations. Each proposal is normalised through the existing Proposal
 * Packet service, optionally evaluated through the existing Power Gate,
 * optionally bucketed through Strategy Portfolio — but Obra creation
 * REMAINS exclusive to `AtlasSelfImprovementForgeActivationService::accept`.
 *
 * Hard rules:
 *   - NEVER creates an Obra. Only records linked_obra_id when activation
 *     materialises it elsewhere.
 *   - NEVER calls a provider.
 *   - NEVER spends a token.
 *   - NEVER auto-promotes completion claim.
 *   - NEVER unlocks `external_rivals_certification`.
 *
 * Schemas:
 *   - atlas.self_improvement.proposal_backlog.v1
 *   - atlas.self_improvement.proposal_backlog_item.v1
 *   - atlas.self_improvement.proposal_priority_decision.v1
 *
 * Storage: local disk at `atlas/self-improvement/proposal-backlog/`, mirrored
 * best-effort to the `atlas_ledger_events` table.
 *
 * Doc: docs/engineering-knowledge-base/atlas-self-improvement-closed-loop-level7-v1.md
 */
class AtlasSelfImprovementProposalBacklogService
{
    public const SCHEMA_VERSION = 'atlas.self_improvement.proposal_backlog.v1';

    public const ITEM_SCHEMA_VERSION = 'atlas.self_improvement.proposal_backlog_item.v1';

    public const DECISION_SCHEMA_VERSION = 'atlas.self_improvement.proposal_priority_decision.v1';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_EVALUATING = 'evaluating';

    public const STATUS_NEEDS_REVISION = 'needs_revision';

    public const STATUS_PENDING_HUMAN_REVIEW = 'pending_human_review';

    public const STATUS_APPROVED_FOR_ACTIVATION = 'approved_for_activation';

    public const STATUS_ACTIVATED = 'activated';

    public const STATUS_OBRA_CREATED = 'obra_created';

    public const STATUS_FORGE_RUNNING = 'forge_running';

    public const STATUS_AWAITING_REVIEW = 'awaiting_review';

    public const STATUS_MEASURING_DELTA = 'measuring_delta';

    public const STATUS_LEARNED = 'learned';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ARCHIVED = 'archived';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_EVALUATING,
        self::STATUS_NEEDS_REVISION,
        self::STATUS_PENDING_HUMAN_REVIEW,
        self::STATUS_APPROVED_FOR_ACTIVATION,
        self::STATUS_ACTIVATED,
        self::STATUS_OBRA_CREATED,
        self::STATUS_FORGE_RUNNING,
        self::STATUS_AWAITING_REVIEW,
        self::STATUS_MEASURING_DELTA,
        self::STATUS_LEARNED,
        self::STATUS_REJECTED,
        self::STATUS_ARCHIVED,
    ];

    public const SOURCES = [
        'manual',
        'chat',
        'activation',
        'postmortem',
        'rivals',
        'regression_sentinel',
        'trust_ledger',
        'operator',
    ];

    public const HISTORY_CAP = 100;

    private const STORAGE_DISK = 'local';

    private const STORAGE_PREFIX = 'atlas/self-improvement/proposal-backlog';

    public function __construct(
        private readonly AtlasSelfImprovementProposalPacketService $proposalPacket,
        private readonly AtlasSelfImprovementProposalPowerGateService $proposalPowerGate,
        private readonly AtlasSelfImprovementStrategyPortfolioService $strategyPortfolio,
    ) {}

    /**
     * Create a new backlog proposal. The payload is normalised via the
     * existing Proposal Packet service before persistence; the backlog item
     * remains in `draft` until the operator runs `evaluateProposal`.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function createProposal(array $payload): array
    {
        $rawPacket = is_array($payload['proposal'] ?? null) ? $payload['proposal'] : $payload;
        $source = $this->stringOrNull($payload['source'] ?? null);
        if ($source === null || ! in_array($source, self::SOURCES, true)) {
            $source = 'manual';
        }

        // Run Proposal Packet build (normalises + extracts blockers); we
        // tolerate a `blocked` packet so the backlog can house drafts.
        try {
            $packet = $this->proposalPacket->build($rawPacket);
        } catch (Throwable) {
            $packet = [
                'schema_version' => AtlasSelfImprovementProposalPacketService::SCHEMA_VERSION,
                'status' => 'blocked',
                'title' => (string) ($rawPacket['title'] ?? 'untitled proposal'),
                'blockers' => ['proposal_packet_build_failed'],
            ];
        }

        $proposalId = 'prop_'.(string) Str::ulid();
        $now = Carbon::now()->toIso8601String();

        $item = [
            'schema_version' => self::ITEM_SCHEMA_VERSION,
            'proposal_id' => $proposalId,
            'title' => (string) ($packet['title'] ?? 'untitled proposal'),
            'summary' => $this->stringOrNull($packet['problem_statement'] ?? null) ?? '',
            'source' => $source,
            'status' => self::STATUS_DRAFT,
            'risk_level' => $this->stringOrNull(data_get($packet, 'risk_classification.risk_level'))
                ?? $this->stringOrNull($packet['risk_level'] ?? null)
                ?? 'medium',
            'expected_power_gain' => $this->stringOrNull($packet['expected_power_gain'] ?? null),
            'target_capability' => $this->stringOrNull($packet['target_capability'] ?? null),
            'affected_domains' => array_values((array) ($payload['affected_domains'] ?? [])),
            'canonical_docs' => array_values((array) ($packet['canonical_docs'] ?? [])),
            'business_rule' => $this->stringOrNull($packet['business_rule'] ?? null),
            'acceptance_criteria' => array_values((array) ($packet['acceptance_gates'] ?? [])),
            'constraints' => array_values((array) ($payload['constraints'] ?? [])),
            'strategy_bucket' => null,
            'priority_score' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'last_decision' => null,
            'linked_activation_id' => null,
            'linked_obra_id' => null,
            'linked_fast_path_run_id' => null,
            'linked_completion_claim_id' => null,
            'linked_result_entry_id' => null,
            'evidence_refs' => [],
            'blockers' => array_values(array_unique((array) ($packet['blockers'] ?? []))),
            'next_safe_action' => $this->resolveNextSafeAction(self::STATUS_DRAFT, []),
            'proposal_packet' => $packet,
            'power_gate' => null,
            'priority_decision' => null,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'auto_fast_path_executed' => false,
            'completion_claim_promoted' => false,
            'separated_from' => 'external_rivals_certification',
            'is_read_model' => false,
        ];

        $this->saveItem($item);
        $this->updateRegistry($item);

        return $item;
    }

    /**
     * Evaluate a proposal through the existing Power Gate. Updates status
     * to one of needs_revision/pending_human_review/approved_for_activation/rejected.
     * Reuses logic that already lives in
     * `AtlasSelfImprovementProposalPowerGateService::evaluate`.
     *
     * @return array<string,mixed>
     */
    public function evaluateProposal(string $proposalId): array
    {
        $item = $this->loadItem($proposalId);
        if ($item === null) {
            return $this->notFoundResponse($proposalId);
        }
        $packet = is_array($item['proposal_packet'] ?? null) ? $item['proposal_packet'] : [];
        $gate = $this->proposalPowerGate->evaluate($packet);

        $outcome = (string) ($gate['outcome'] ?? 'rejected');
        $status = match ($outcome) {
            AtlasSelfImprovementProposalPowerGateService::OUTCOME_APPROVED => self::STATUS_APPROVED_FOR_ACTIVATION,
            AtlasSelfImprovementProposalPowerGateService::OUTCOME_HUMAN_REVIEW_REQUIRED => self::STATUS_PENDING_HUMAN_REVIEW,
            AtlasSelfImprovementProposalPowerGateService::OUTCOME_NEEDS_REVISION => self::STATUS_NEEDS_REVISION,
            AtlasSelfImprovementProposalPowerGateService::OUTCOME_REJECTED => self::STATUS_REJECTED,
            default => self::STATUS_NEEDS_REVISION,
        };

        $item['status'] = $status;
        $item['power_gate'] = $gate;
        $item['updated_at'] = Carbon::now()->toIso8601String();
        $item['last_decision'] = [
            'kind' => 'power_gate_evaluation',
            'outcome' => $outcome,
            'decided_at' => $item['updated_at'],
        ];
        $item['blockers'] = $status === self::STATUS_REJECTED
            ? array_values(array_unique(array_merge((array) $item['blockers'], ['proposal_power_gate_rejected'])))
            : (array) $item['blockers'];
        $item['next_safe_action'] = $this->resolveNextSafeAction($status, (array) $item['blockers']);

        $this->saveItem($item);
        $this->updateRegistry($item);

        return $item;
    }

    /**
     * Compute a strategy bucket + priority score (deterministic, never
     * blocking). Reuses the Strategy Portfolio service for bucket
     * classification + target weight.
     *
     * @param  array<string,mixed>  $payload  Optional override: ['strategy_bucket' => '...']
     * @return array<string,mixed>
     */
    public function prioritize(string $proposalId, array $payload = []): array
    {
        $item = $this->loadItem($proposalId);
        if ($item === null) {
            return $this->notFoundResponse($proposalId);
        }
        $packet = is_array($item['proposal_packet'] ?? null) ? $item['proposal_packet'] : [];

        $explicit = $this->stringOrNull($payload['strategy_bucket'] ?? null);
        $bucket = $explicit !== null
            && in_array($explicit, AtlasSelfImprovementStrategyPortfolioService::BUCKETS, true)
            ? $explicit
            : $this->bucketFromPacket($packet);

        $portfolio = $this->strategyPortfolio->snapshot([]);
        $targets = AtlasSelfImprovementStrategyPortfolioService::TARGET_WEIGHTS;
        $targetPercent = (float) ($targets[$bucket] ?? 10.0);
        $recommended = $this->stringOrNull($portfolio['recommended_next_bucket'] ?? null);
        $aligned = $recommended === null || $recommended === $bucket;

        // Priority score: higher is more urgent. Simple deterministic blend
        // of (a) risk weight, (b) alignment with portfolio recommendation
        // and (c) inverse of current backlog density for this bucket.
        $riskWeight = match ($item['risk_level']) {
            'critical' => 4.0,
            'high' => 3.0,
            'medium' => 2.0,
            'low' => 1.0,
            default => 1.5,
        };
        $alignWeight = $aligned ? 2.0 : 0.5;
        $score = round(($riskWeight * 2.0) + $alignWeight, 2);

        $decision = [
            'schema_version' => self::DECISION_SCHEMA_VERSION,
            'decided_at' => Carbon::now()->toIso8601String(),
            'strategy_bucket' => $bucket,
            'target_percent' => $targetPercent,
            'portfolio_aligned' => $aligned,
            'portfolio_recommends' => $recommended,
            'priority_score' => $score,
            'reason' => $aligned
                ? 'aligned_with_portfolio_or_no_recommendation'
                : 'portfolio_recommends_'.($recommended ?? 'unknown').'_but_proposal_targets_'.$bucket,
            'auto_activation_allowed' => false,
            'human_approval_required' => true,
        ];

        $item['strategy_bucket'] = $bucket;
        $item['priority_score'] = $score;
        $item['priority_decision'] = $decision;
        $item['updated_at'] = $decision['decided_at'];
        $item['last_decision'] = [
            'kind' => 'priority_decision',
            'outcome' => $decision['portfolio_aligned'] ? 'aligned' : 'misaligned',
            'decided_at' => $decision['decided_at'],
        ];
        $item['next_safe_action'] = $this->resolveNextSafeAction($item['status'], (array) $item['blockers']);

        $this->saveItem($item);
        $this->updateRegistry($item);

        return $item;
    }

    /**
     * Mark proposal as activated. Called by the activation service when an
     * approved proposal becomes an `act_*` activation.
     *
     * @return array<string,mixed>|null
     */
    public function markActivated(string $proposalId, string $activationId): ?array
    {
        $item = $this->loadItem($proposalId);
        if ($item === null) {
            return null;
        }
        $item['linked_activation_id'] = $activationId;
        $item['status'] = self::STATUS_ACTIVATED;
        $item['updated_at'] = Carbon::now()->toIso8601String();
        $item['evidence_refs'] = array_values(array_unique(array_merge(
            (array) $item['evidence_refs'],
            ['activation:'.$activationId],
        )));
        $item['next_safe_action'] = $this->resolveNextSafeAction($item['status'], (array) $item['blockers']);
        $this->saveItem($item);
        $this->updateRegistry($item);

        return $item;
    }

    /**
     * Link a created Obra to a proposal (idempotent).
     *
     * @return array<string,mixed>|null
     */
    public function linkObra(string $proposalId, string $obraId, ?string $activationId = null): ?array
    {
        $item = $this->loadItem($proposalId);
        if ($item === null) {
            return null;
        }
        $item['linked_obra_id'] = $obraId;
        if ($activationId !== null && ($item['linked_activation_id'] ?? null) === null) {
            $item['linked_activation_id'] = $activationId;
        }
        $item['status'] = self::STATUS_OBRA_CREATED;
        $item['updated_at'] = Carbon::now()->toIso8601String();
        $item['evidence_refs'] = array_values(array_unique(array_merge(
            (array) $item['evidence_refs'],
            ['obra:'.$obraId],
        )));
        $item['next_safe_action'] = $this->resolveNextSafeAction($item['status'], (array) $item['blockers']);
        $this->saveItem($item);
        $this->updateRegistry($item);

        return $item;
    }

    /**
     * Annotate the current Forge state for the proposal (forge_running,
     * awaiting_review, etc).
     *
     * @return array<string,mixed>|null
     */
    public function markForgeState(string $proposalId, string $status, ?string $fastPathRunId = null, ?string $completionClaimId = null): ?array
    {
        if (! in_array($status, self::STATUSES, true)) {
            return null;
        }
        $item = $this->loadItem($proposalId);
        if ($item === null) {
            return null;
        }
        $item['status'] = $status;
        if ($fastPathRunId !== null) {
            $item['linked_fast_path_run_id'] = $fastPathRunId;
            $item['evidence_refs'] = array_values(array_unique(array_merge(
                (array) $item['evidence_refs'],
                ['fast_path_run:'.$fastPathRunId],
            )));
        }
        if ($completionClaimId !== null) {
            $item['linked_completion_claim_id'] = $completionClaimId;
            $item['evidence_refs'] = array_values(array_unique(array_merge(
                (array) $item['evidence_refs'],
                ['completion_claim:'.$completionClaimId],
            )));
        }
        $item['updated_at'] = Carbon::now()->toIso8601String();
        $item['next_safe_action'] = $this->resolveNextSafeAction($status, (array) $item['blockers']);
        $this->saveItem($item);
        $this->updateRegistry($item);

        return $item;
    }

    /**
     * Mark proposal as having had its delta measured.
     *
     * @return array<string,mixed>|null
     */
    public function markDeltaMeasured(string $proposalId, string $resultEntryId, string $deltaGrade): ?array
    {
        $item = $this->loadItem($proposalId);
        if ($item === null) {
            return null;
        }
        $item['linked_result_entry_id'] = $resultEntryId;
        $item['status'] = self::STATUS_LEARNED;
        $item['updated_at'] = Carbon::now()->toIso8601String();
        $item['last_decision'] = [
            'kind' => 'delta_measured',
            'outcome' => $deltaGrade,
            'decided_at' => $item['updated_at'],
        ];
        $item['evidence_refs'] = array_values(array_unique(array_merge(
            (array) $item['evidence_refs'],
            ['result_entry:'.$resultEntryId],
        )));
        $item['next_safe_action'] = $this->resolveNextSafeAction($item['status'], (array) $item['blockers']);
        $this->saveItem($item);
        $this->updateRegistry($item);

        return $item;
    }

    /**
     * Archive a proposal (low value / superseded). Idempotent.
     *
     * @return array<string,mixed>|null
     */
    public function archive(string $proposalId, ?string $reason = null): ?array
    {
        $item = $this->loadItem($proposalId);
        if ($item === null) {
            return null;
        }
        $item['status'] = self::STATUS_ARCHIVED;
        $item['updated_at'] = Carbon::now()->toIso8601String();
        $item['last_decision'] = [
            'kind' => 'archive',
            'outcome' => $reason ?? 'archived_by_operator',
            'decided_at' => $item['updated_at'],
        ];
        $item['next_safe_action'] = 'closed';
        $this->saveItem($item);
        $this->updateRegistry($item);

        return $item;
    }

    /**
     * Reject a proposal explicitly (without re-running gate). Reviewer +
     * reason required. Never creates an Obra.
     *
     * @return array<string,mixed>|null
     */
    public function reject(string $proposalId, ?string $reviewer, ?string $reason): ?array
    {
        $item = $this->loadItem($proposalId);
        if ($item === null) {
            return null;
        }
        if ($reviewer === null || trim($reviewer) === '' || $reason === null || trim($reason) === '') {
            $item['blockers'] = array_values(array_unique(array_merge(
                (array) $item['blockers'],
                ['reject_requires_reviewer_and_reason'],
            )));
            $item['next_safe_action'] = 'provide_reviewer_and_reason_then_retry';

            return $item;
        }
        $item['status'] = self::STATUS_REJECTED;
        $item['updated_at'] = Carbon::now()->toIso8601String();
        $item['last_decision'] = [
            'kind' => 'reject',
            'outcome' => 'rejected_by_human',
            'decided_at' => $item['updated_at'],
            'reviewer' => $reviewer,
            'reason' => $reason,
        ];
        $item['next_safe_action'] = $this->resolveNextSafeAction(self::STATUS_REJECTED, (array) $item['blockers']);
        $this->saveItem($item);
        $this->updateRegistry($item);

        return $item;
    }

    /**
     * Read a backlog item by id.
     *
     * @return array<string,mixed>|null
     */
    public function getProposal(string $proposalId): ?array
    {
        return $this->loadItem($proposalId);
    }

    /**
     * List backlog items with optional filters.
     *
     * @param  array{status?: ?string, source?: ?string, bucket?: ?string, linked_obra?: ?bool}  $filters
     * @return array<string,mixed>
     */
    public function listBacklog(array $filters = []): array
    {
        $registry = $this->loadRegistry();
        $entries = is_array($registry['proposals'] ?? null) ? $registry['proposals'] : [];

        $statusFilter = $this->stringOrNull($filters['status'] ?? null);
        $sourceFilter = $this->stringOrNull($filters['source'] ?? null);
        $bucketFilter = $this->stringOrNull($filters['bucket'] ?? null);
        $linkedObra = $filters['linked_obra'] ?? null;

        $filtered = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if ($statusFilter !== null && ($entry['status'] ?? null) !== $statusFilter) {
                continue;
            }
            if ($sourceFilter !== null && ($entry['source'] ?? null) !== $sourceFilter) {
                continue;
            }
            if ($bucketFilter !== null && ($entry['strategy_bucket'] ?? null) !== $bucketFilter) {
                continue;
            }
            if (is_bool($linkedObra)) {
                $hasObra = ($entry['linked_obra_id'] ?? null) !== null;
                if ($hasObra !== $linkedObra) {
                    continue;
                }
            }
            $filtered[] = $entry;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => Carbon::now()->toIso8601String(),
            'filters' => [
                'status' => $statusFilter,
                'source' => $sourceFilter,
                'bucket' => $bucketFilter,
                'linked_obra' => is_bool($linkedObra) ? $linkedObra : null,
            ],
            'proposals' => $filtered,
            'counters' => $this->computeCounters($entries),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'auto_fast_path_executed' => false,
            'completion_claim_promoted' => false,
            'separated_from' => 'external_rivals_certification',
            'is_read_model' => true,
        ];
    }

    /**
     * Aggregate strategy portfolio view across all backlog items.
     *
     * @return array<string,mixed>
     */
    public function summarizePortfolio(): array
    {
        $registry = $this->loadRegistry();
        $entries = is_array($registry['proposals'] ?? null) ? $registry['proposals'] : [];

        $proposalsForPortfolio = array_values(array_map(static function (array $e): array {
            return [
                'proposal_id' => (string) ($e['proposal_id'] ?? ''),
                'strategy_bucket' => (string) ($e['strategy_bucket'] ?? 'core_runtime'),
                'status' => (string) ($e['status'] ?? 'draft'),
                'risk_level' => (string) ($e['risk_level'] ?? 'medium'),
            ];
        }, array_filter($entries, fn (mixed $e): bool => is_array($e) && ($e['strategy_bucket'] ?? null) !== null)));

        return $this->strategyPortfolio->snapshot($proposalsForPortfolio);
    }

    // ──────────────────────────────────────────────────────────────────
    // Internals

    /**
     * @param  array<string,mixed>  $packet
     */
    private function bucketFromPacket(array $packet): string
    {
        $explicit = $this->stringOrNull(data_get($packet, 'strategy_bucket'));
        if ($explicit !== null && in_array($explicit, AtlasSelfImprovementStrategyPortfolioService::BUCKETS, true)) {
            return $explicit;
        }
        $title = strtolower((string) ($packet['title'] ?? ''));
        $target = strtolower((string) ($packet['target_capability'] ?? ''));
        $needle = $title.' '.$target;

        return match (true) {
            str_contains($needle, 'security') || str_contains($needle, 'governance') => AtlasSelfImprovementStrategyPortfolioService::BUCKET_SECURITY_GOVERNANCE,
            str_contains($needle, 'rivals') || str_contains($needle, 'evaluation') => AtlasSelfImprovementStrategyPortfolioService::BUCKET_RIVALS_EVALUATION,
            str_contains($needle, 'self_construction') || str_contains($needle, 'self-construction') => AtlasSelfImprovementStrategyPortfolioService::BUCKET_SELF_CONSTRUCTION,
            str_contains($needle, 'provider') => AtlasSelfImprovementStrategyPortfolioService::BUCKET_PROVIDER_INTELLIGENCE,
            str_contains($needle, 'cockpit') || str_contains($needle, 'operator') || preg_match('/\bux\b/u', $needle) === 1 => AtlasSelfImprovementStrategyPortfolioService::BUCKET_OPERATOR_EXPERIENCE,
            str_contains($needle, 'reliability') || str_contains($needle, 'enterprise') => AtlasSelfImprovementStrategyPortfolioService::BUCKET_ENTERPRISE_RELIABILITY,
            str_contains($needle, 'doc') || str_contains($needle, 'lint') || str_contains($needle, 'typo') => AtlasSelfImprovementStrategyPortfolioService::BUCKET_QUICK_WINS,
            default => AtlasSelfImprovementStrategyPortfolioService::BUCKET_CORE_RUNTIME,
        };
    }

    /**
     * @param  list<string>  $blockers
     */
    private function resolveNextSafeAction(string $status, array $blockers): string
    {
        if ($blockers !== []) {
            return 'resolve_blockers:'.implode(',', array_slice($blockers, 0, 3));
        }

        return match ($status) {
            self::STATUS_DRAFT => 'evaluate_proposal_through_power_gate',
            self::STATUS_EVALUATING => 'await_power_gate_outcome',
            self::STATUS_NEEDS_REVISION => 'rewrite_proposal_and_re_evaluate',
            self::STATUS_PENDING_HUMAN_REVIEW => 'await_explicit_human_approval',
            self::STATUS_APPROVED_FOR_ACTIVATION => 'activate_via_atlas:self-improvement:activate-forge',
            self::STATUS_ACTIVATED => 'open_atlas_code_forge_to_materialise_obra',
            self::STATUS_OBRA_CREATED => 'open_obra_in_forge_to_decide_fast_path',
            self::STATUS_FORGE_RUNNING => 'await_forge_completion_or_review',
            self::STATUS_AWAITING_REVIEW => 'review_completion_claim',
            self::STATUS_MEASURING_DELTA => 'await_measure_result_outcome',
            self::STATUS_LEARNED => 'review_next_cycle_recommendation',
            self::STATUS_REJECTED => 'rewrite_proposal_or_archive',
            self::STATUS_ARCHIVED => 'closed',
            default => 'inspect_status',
        };
    }

    private function notFoundResponse(string $proposalId): array
    {
        return [
            'schema_version' => self::ITEM_SCHEMA_VERSION,
            'proposal_id' => $proposalId,
            'status' => 'blocked',
            'blockers' => ['proposal_not_found'],
            'next_safe_action' => 'list_backlog_to_find_valid_id',
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'auto_fast_path_executed' => false,
            'completion_claim_promoted' => false,
            'separated_from' => 'external_rivals_certification',
            'is_read_model' => true,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return array<string,int>
     */
    private function computeCounters(array $entries): array
    {
        $counters = [
            'total' => 0,
            'draft' => 0,
            'evaluating' => 0,
            'needs_revision' => 0,
            'pending_human_review' => 0,
            'approved_for_activation' => 0,
            'activated' => 0,
            'obra_created' => 0,
            'forge_running' => 0,
            'awaiting_review' => 0,
            'measuring_delta' => 0,
            'learned' => 0,
            'rejected' => 0,
            'archived' => 0,
            'with_obra' => 0,
            'with_blockers' => 0,
        ];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $counters['total']++;
            $status = (string) ($entry['status'] ?? 'draft');
            if (isset($counters[$status])) {
                $counters[$status]++;
            }
            if (($entry['linked_obra_id'] ?? null) !== null) {
                $counters['with_obra']++;
            }
            $blockers = (array) ($entry['blockers'] ?? []);
            if ($blockers !== []) {
                $counters['with_blockers']++;
            }
        }

        return $counters;
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function saveItem(array $item): void
    {
        $disk = Storage::disk(self::STORAGE_DISK);
        $path = self::STORAGE_PREFIX.'/'.$item['proposal_id'].'.json';
        $disk->put($path, (string) json_encode(
            $item,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        if (Schema::hasTable('atlas_ledger_events')) {
            try {
                DB::table('atlas_ledger_events')->insert([
                    'event_id' => (string) Str::ulid(),
                    'schema_version' => self::ITEM_SCHEMA_VERSION,
                    'event_type' => 'SELF_IMPROVEMENT_PROPOSAL_BACKLOG_'.strtoupper((string) $item['status']),
                    'emitter_stage' => 'self_improvement_proposal_backlog',
                    'emitter_version' => 'v1',
                    'payload' => json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'occurred_at' => (string) ($item['updated_at'] ?? Carbon::now()->toIso8601String()),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            } catch (Throwable) {
                // best-effort
            }
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadItem(string $proposalId): ?array
    {
        $disk = Storage::disk(self::STORAGE_DISK);
        $path = self::STORAGE_PREFIX.'/'.$proposalId.'.json';
        if (! $disk->exists($path)) {
            return null;
        }
        try {
            $decoded = json_decode((string) $disk->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function updateRegistry(array $item): void
    {
        $disk = Storage::disk(self::STORAGE_DISK);
        $path = self::STORAGE_PREFIX.'/_registry.json';
        $current = ['proposals' => []];
        if ($disk->exists($path)) {
            try {
                $decoded = json_decode((string) $disk->get($path), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $current = $decoded;
                }
            } catch (Throwable) {
                $current = ['proposals' => []];
            }
        }
        $entries = is_array($current['proposals'] ?? null) ? $current['proposals'] : [];
        $entries = array_values(array_filter(
            $entries,
            fn (mixed $row): bool => is_array($row) && (string) ($row['proposal_id'] ?? '') !== (string) $item['proposal_id'],
        ));
        array_unshift($entries, $item);
        $entries = array_slice($entries, 0, self::HISTORY_CAP);

        $disk->put($path, (string) json_encode([
            'schema_version' => self::SCHEMA_VERSION,
            'proposals' => $entries,
            'updated_at' => Carbon::now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string,mixed>
     */
    private function loadRegistry(): array
    {
        $disk = Storage::disk(self::STORAGE_DISK);
        $path = self::STORAGE_PREFIX.'/_registry.json';
        if (! $disk->exists($path)) {
            return ['proposals' => []];
        }
        try {
            $decoded = json_decode((string) $disk->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return ['proposals' => []];
        }

        return is_array($decoded) ? $decoded : ['proposals' => []];
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
