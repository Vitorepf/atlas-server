<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasCodeForgeWorkIntakeService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Atlas Self-Improvement → Forge Activation v1.
 *
 * Closes the loop between the Self-Improvement Governance ladder (proposal +
 * power gate + delta + invariant lock + regression sentinel + maturity +
 * trust ledger + strategy portfolio) and a real Forge Obra. Approved
 * proposals can be turned into a governed Obra with full Intake; the service
 * NEVER executes Fast Path automatically and NEVER auto-promotes Obra when
 * the gate requires human review.
 *
 * Hard rules:
 *   - NEVER calls a provider;
 *   - NEVER spends a token;
 *   - NEVER auto-executes Fast Path;
 *   - NEVER unlocks `external_rivals_certification`;
 *   - NEVER promotes a completion claim;
 *   - NEVER masks blockers;
 *   - When the Power Gate returns `human_review_required`, the activation
 *     blocks (`pending_human_review`) until an explicit `accept` decision
 *     with reviewer + reason is received.
 *
 * Schemas:
 *   - atlas.self_improvement.forge_activation.v1
 *   - atlas.self_improvement.forge_activation_approval.v1
 *
 * Doc: docs/engineering-knowledge-base/atlas-self-improvement-forge-activation-v1.md
 */
class AtlasSelfImprovementForgeActivationService
{
    public const SCHEMA_VERSION = 'atlas.self_improvement.forge_activation.v1';

    public const APPROVAL_SCHEMA_VERSION = 'atlas.self_improvement.forge_activation_approval.v1';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_NEEDS_REVISION = 'needs_revision';

    public const STATUS_PENDING_HUMAN_REVIEW = 'pending_human_review';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_OBRA_CREATED = 'obra_created';

    public const STATUS_DRY_RUN = 'dry_run_planned';

    public const REQUIRED_DOCS = [
        'docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md',
        'docs/engineering-knowledge-base/atlas-forge-continuum-os.md',
        'docs/engineering-knowledge-base/atlas-programming-forge-flow.md',
    ];

    public const OPTIONAL_DOCS = [
        'docs/engineering-knowledge-base/atlas-code-forge-human-first-ux-orchestrator-v1.md',
    ];

    /** @var int Cap for activation history kept in metadata. */
    public const HISTORY_CAP = 25;

    /** @var string Storage disk used to persist activation snapshots between runs. */
    private const STORAGE_DISK = 'local';

    private const STORAGE_PREFIX = 'atlas/self-improvement/forge-activations';

    public function __construct(
        private readonly AtlasSelfImprovementProposalPacketService $proposalPacket,
        private readonly AtlasSelfImprovementProposalPowerGateService $proposalPowerGate,
        private readonly AtlasSelfImprovementCapabilityMaturityScoreService $capabilityMaturity,
        private readonly AtlasSelfImprovementInvariantLockService $invariantLock,
        private readonly AtlasSelfImprovementRegressionSentinelService $regressionSentinel,
        private readonly AtlasSelfImprovementHumanTrustLedgerService $humanTrustLedger,
        private readonly AtlasSelfImprovementStrategyPortfolioService $strategyPortfolio,
        private readonly AtlasCodeForgeWorkIntakeService $forgeWorkIntake,
    ) {}

    /**
     * Plan an activation from a proposal payload (or pre-built packet).
     *
     * If the gate is `approved` AND no `human_review_required`, this method
     * may also create the Obra immediately (when `auto_create_when_approved`
     * is true; default false to keep the flow strictly human-driven).
     *
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function plan(array $options = []): array
    {
        $proposalPayload = is_array($options['proposal'] ?? null) ? $options['proposal'] : null;
        $proposalPacket = is_array($options['proposal_packet'] ?? null) ? $options['proposal_packet'] : null;
        $proposalId = $this->stringOrNull($options['proposal_id'] ?? null);
        $obraTitleOverride = $this->stringOrNull($options['obra_title'] ?? null);
        $autoCreate = (bool) ($options['auto_create_when_approved'] ?? false);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $workspaceRoot = $this->stringOrNull($options['workspace'] ?? null) ?? base_path();

        // 1. Resolve proposal — payload, prebuilt packet, or persisted by id.
        $packet = null;
        if ($proposalPacket !== null && ($proposalPacket['schema_version'] ?? null) === AtlasSelfImprovementProposalPacketService::SCHEMA_VERSION) {
            $packet = $proposalPacket;
        } elseif ($proposalPayload !== null) {
            $packet = $this->proposalPacket->build($proposalPayload);
        } elseif ($proposalId !== null) {
            $persisted = $this->loadActivation($proposalId);
            if ($persisted !== null) {
                $packet = $persisted['proposal_packet'] ?? null;
            }
        }

        if (! is_array($packet)) {
            return $this->blockedResponse(
                blocker: 'no_proposal_payload_provided',
                proposalId: $proposalId,
                obraTitleOverride: $obraTitleOverride,
                workspaceRoot: $workspaceRoot,
            );
        }

        // 2. Run / re-use Power Gate.
        $gate = $this->proposalPowerGate->evaluate($packet);

        // 3. Validate canonical docs.
        $docs = $this->collectDocHashes($workspaceRoot);
        $missingRequired = array_filter(
            $docs,
            fn (array $entry): bool => $entry['required'] && ! $entry['present'],
        );

        // 4. Build before snapshot (always — it is diagnostic).
        $beforeSnapshot = $this->buildBeforeSnapshot($workspaceRoot, $packet);

        // 5. Strategy portfolio classification.
        $portfolioFit = $this->classifyPortfolio($packet);

        $proposalHash = $this->hashJson($packet);
        $powerGateHash = $this->hashJson($gate);

        $activationId = 'act_'.(string) Str::ulid();
        $generatedAt = Carbon::now()->toIso8601String();

        $statusFromGate = $this->statusFromGate($gate);

        $blockers = [];
        if ($missingRequired !== []) {
            $blockers[] = 'blocked_missing_canonical_doc:'.implode(',', array_keys($missingRequired));
        }
        if ($statusFromGate === self::STATUS_BLOCKED) {
            $blockers[] = 'proposal_power_gate_rejected';
        }

        // Activation status before any human approval/rejection.
        $status = $this->resolveInitialStatus($statusFromGate, $blockers, $dryRun);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'activation_id' => $activationId,
            'generated_at' => $generatedAt,
            'status' => $status,
            'dry_run' => $dryRun,
            'auto_create_when_approved' => $autoCreate,
            'proposal_id' => $packet['proposal_id'] ?? null,
            'proposal_hash' => $proposalHash,
            'proposal_packet' => $packet,
            'power_gate' => $gate,
            'power_gate_hash' => $powerGateHash,
            'invariant_lock_hash' => $beforeSnapshot['invariant_lock']['hash'] ?? null,
            'regression_sentinel_hash' => $beforeSnapshot['regression_sentinel']['hash'] ?? null,
            'maturity_target' => $beforeSnapshot['maturity']['target_level'] ?? null,
            'strategy_bucket' => $portfolioFit['bucket'],
            'portfolio_deviation' => $portfolioFit['deviation'],
            'portfolio_reason' => $portfolioFit['reason'],
            'before_snapshot' => $beforeSnapshot,
            'after_snapshot' => null,
            'docs' => $docs,
            'missing_required_docs' => array_keys($missingRequired),
            'approval' => null,
            'rejection' => null,
            'created_obra_id' => null,
            'created_obra_title' => null,
            'work_intake' => null,
            'blockers' => array_values(array_unique($blockers)),
            'evidence_refs' => $this->evidenceRefs($docs, $packet, $gate, $beforeSnapshot, null, null),
            'next_action' => $this->resolveNextAction($status),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'auto_fast_path_executed' => false,
            'separated_from' => 'external_rivals_certification',
            'is_read_model' => false,
        ];

        // Honour explicit operator overrides (obra title)
        if ($obraTitleOverride !== null) {
            $payload['operator_obra_title'] = $obraTitleOverride;
        }

        // 6. Persist plan (so accept/reject can find it later).
        if (! $dryRun) {
            $this->saveActivation($payload);
            $this->updateGlobalRegistry($payload);
        }

        // 7. Optional auto-create (rare; defaults off).
        if ($autoCreate
            && $status === self::STATUS_ACCEPTED
            && ! $dryRun
            && ! ($gate['requires_human_review'] ?? true)
        ) {
            $payload = $this->materialiseObra($payload, reviewer: 'auto-acceptor', reason: 'auto_create_when_approved');
        }

        return $payload;
    }

    /**
     * Read a previously planned activation from the local persistence store.
     *
     * @return array<string,mixed>|null
     */
    public function get(string $activationId): ?array
    {
        return $this->loadActivation($activationId);
    }

    /**
     * Operator accepts the activation. Required to materialise the Obra when
     * the proposal Power Gate marked it as `human_review_required`.
     *
     * @param  array{reviewer?: ?string, reason?: ?string}  $payload
     * @return array<string,mixed>
     */
    public function accept(string $activationId, array $payload = []): array
    {
        $activation = $this->loadActivation($activationId);
        if ($activation === null) {
            return $this->blockedResponse('activation_not_found', null, null, base_path());
        }

        $reviewer = $this->stringOrNull($payload['reviewer'] ?? null);
        $reason = $this->stringOrNull($payload['reason'] ?? null);
        if ($reviewer === null || $reason === null) {
            $activation['blockers'] = array_values(array_unique(array_merge(
                $activation['blockers'] ?? [],
                ['accept_requires_reviewer_and_reason'],
            )));
            $activation['next_action'] = 'provide_reviewer_and_reason_then_retry';

            return $activation;
        }

        if (($activation['created_obra_id'] ?? null) !== null) {
            $activation['next_action'] = 'open_atlas_code_forge';

            return $activation;
        }

        if (! $this->activationCanBeAccepted($activation)) {
            $activation['status'] = self::STATUS_BLOCKED;
            $activation['blockers'] = array_values(array_unique(array_merge(
                $activation['blockers'] ?? [],
                ['cannot_accept_blocked_activation'],
            )));
            $activation['next_action'] = 'resolve_blockers_then_replan';
            $this->saveActivation($activation);
            $this->updateGlobalRegistry($activation);

            return $activation;
        }

        // Cannot accept proposals that are rejected/blocked at gate level.
        $gate = $activation['power_gate'] ?? [];
        $gateOutcome = (string) ($gate['outcome'] ?? '');
        if (in_array($gateOutcome, [
            AtlasSelfImprovementProposalPowerGateService::OUTCOME_REJECTED,
            AtlasSelfImprovementProposalPowerGateService::OUTCOME_NEEDS_REVISION,
        ], true)) {
            $activation['status'] = self::STATUS_REJECTED;
            $activation['blockers'] = array_values(array_unique(array_merge(
                $activation['blockers'] ?? [],
                ['cannot_accept_rejected_or_needs_revision_gate'],
            )));
            $activation['next_action'] = 'rewrite_proposal_and_replan_activation';
            $this->saveActivation($activation);

            return $activation;
        }

        $approval = $this->buildApprovalReceipt($activationId, $reviewer, $reason, $activation);
        $activation['approval'] = $approval;
        $activation['status'] = self::STATUS_ACCEPTED;
        $activation = $this->materialiseObra($activation, reviewer: $reviewer, reason: $reason);

        $activation['next_action'] = 'open_atlas_code_forge';
        $activation['evidence_refs'] = $this->evidenceRefs(
            $activation['docs'] ?? [],
            $activation['proposal_packet'] ?? [],
            $activation['power_gate'] ?? [],
            $activation['before_snapshot'] ?? [],
            $activation['approval'] ?? null,
            $activation['created_obra_id'] ?? null,
        );

        $this->saveActivation($activation);
        $this->updateGlobalRegistry($activation);

        // Trust ledger: record canonical accept outcome (no token spend).
        $this->recordTrustLedger(
            $activation['created_obra_id'] ?? null,
            'proposal_accepted_for_forge',
            $activation,
            $reviewer,
            $reason,
        );

        return $activation;
    }

    /**
     * Operator rejects the activation. Records reason; never creates Obra.
     *
     * @param  array{reviewer?: ?string, reason?: ?string}  $payload
     * @return array<string,mixed>
     */
    public function reject(string $activationId, array $payload = []): array
    {
        $activation = $this->loadActivation($activationId);
        if ($activation === null) {
            return $this->blockedResponse('activation_not_found', null, null, base_path());
        }

        $reviewer = $this->stringOrNull($payload['reviewer'] ?? null);
        $reason = $this->stringOrNull($payload['reason'] ?? null);
        if ($reviewer === null || $reason === null) {
            $activation['blockers'] = array_values(array_unique(array_merge(
                $activation['blockers'] ?? [],
                ['reject_requires_reviewer_and_reason'],
            )));
            $activation['next_action'] = 'provide_reviewer_and_reason_then_retry';

            return $activation;
        }

        $activation['rejection'] = [
            'reviewer' => $reviewer,
            'reason' => $reason,
            'rejected_at' => Carbon::now()->toIso8601String(),
            'silent' => false,
        ];
        $activation['status'] = self::STATUS_REJECTED;
        $activation['next_action'] = 'rewrite_proposal_and_replan_activation_or_close';
        $activation['evidence_refs'] = $this->evidenceRefs(
            $activation['docs'] ?? [],
            $activation['proposal_packet'] ?? [],
            $activation['power_gate'] ?? [],
            $activation['before_snapshot'] ?? [],
            null,
            null,
        );

        $this->saveActivation($activation);
        $this->updateGlobalRegistry($activation);

        $this->recordTrustLedger(
            null,
            'proposal_rejected_for_forge',
            $activation,
            $reviewer,
            $reason,
        );

        return $activation;
    }

    /**
     * Public registry of recently planned activations (capped).
     *
     * @return array<string,mixed>
     */
    public function registry(): array
    {
        $disk = Storage::disk(self::STORAGE_DISK);
        $path = self::STORAGE_PREFIX.'/_registry.json';
        if (! $disk->exists($path)) {
            return [
                'schema_version' => 'atlas.self_improvement.forge_activation_registry.v1',
                'activations' => [],
                'updated_at' => null,
            ];
        }
        try {
            $decoded = json_decode((string) $disk->get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [
                'schema_version' => 'atlas.self_improvement.forge_activation_registry.v1',
                'activations' => [],
                'updated_at' => null,
            ];
        }

        return is_array($decoded) ? $decoded : [
            'schema_version' => 'atlas.self_improvement.forge_activation_registry.v1',
            'activations' => [],
            'updated_at' => null,
        ];
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function blockedResponse(
        string $blocker,
        ?string $proposalId,
        ?string $obraTitleOverride,
        string $workspaceRoot,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'activation_id' => 'act_'.(string) Str::ulid(),
            'generated_at' => Carbon::now()->toIso8601String(),
            'status' => self::STATUS_BLOCKED,
            'dry_run' => false,
            'proposal_id' => $proposalId,
            'proposal_hash' => null,
            'proposal_packet' => null,
            'power_gate' => null,
            'power_gate_hash' => null,
            'invariant_lock_hash' => null,
            'regression_sentinel_hash' => null,
            'maturity_target' => null,
            'strategy_bucket' => null,
            'portfolio_deviation' => false,
            'portfolio_reason' => null,
            'before_snapshot' => null,
            'after_snapshot' => null,
            'docs' => $this->collectDocHashes($workspaceRoot),
            'missing_required_docs' => [],
            'approval' => null,
            'rejection' => null,
            'created_obra_id' => null,
            'created_obra_title' => $obraTitleOverride,
            'work_intake' => null,
            'blockers' => [$blocker],
            'evidence_refs' => [],
            'next_action' => 'provide_proposal_payload_or_persisted_id',
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'auto_fast_path_executed' => false,
            'separated_from' => 'external_rivals_certification',
            'is_read_model' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    private function buildBeforeSnapshot(string $workspaceRoot, array $packet): array
    {
        $maturity = $this->capabilityMaturity->score([
            'capability' => (string) ($packet['target_capability'] ?? 'unspecified'),
            'doc' => 'docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md',
            'service_class' => AtlasSelfImprovementProposalPacketService::class,
            'command_signature' => 'atlas:self-improvement:proposal-gate',
            'api_route' => '/self-improvement/proposal-gate',
            'test_class' => 'tests/Feature/Ai/SelfImprovement/AtlasSelfImprovementGovernanceTest.php',
            'state_field' => 'self_improvement_governance',
            'audit_block' => 'atlas_self_improvement_governance_certification',
        ], context: ['workspace' => $workspaceRoot]);

        $invariantLock = $this->invariantLock->evaluate(
            $this->canonicalAfterSnapshot(),
            implementationDiff: [],
            proposal: $packet,
        );

        $regressionSentinel = $this->regressionSentinel->scan(
            $this->canonicalAfterSnapshot(),
            $this->canonicalAfterSnapshot(),
            implementationDiff: [],
        );

        $portfolio = $this->strategyPortfolio->snapshot([]);
        $trustLedger = $this->humanTrustLedger->snapshot(null);

        return [
            'schema_version' => 'atlas.self_improvement.forge_activation_baseline.v1',
            'captured_at' => Carbon::now()->toIso8601String(),
            'maturity' => [
                'snapshot' => $maturity,
                'target_level' => $maturity['expected_level'] ?? null,
                'achieved_level' => $maturity['achieved_level'] ?? null,
                'hash' => $this->hashJson($maturity),
            ],
            'invariant_lock' => [
                'snapshot' => $invariantLock,
                'hash' => $this->hashJson($invariantLock),
            ],
            'regression_sentinel' => [
                'snapshot' => $regressionSentinel,
                'hash' => $this->hashJson($regressionSentinel),
            ],
            'strategy_portfolio' => [
                'snapshot' => $portfolio,
                'hash' => $this->hashJson($portfolio),
            ],
            'trust_ledger' => [
                'snapshot' => $trustLedger,
                'hash' => $this->hashJson($trustLedger),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function canonicalAfterSnapshot(): array
    {
        return [
            'completion_audit' => [
                'atlas_forge_continuum_certification' => [
                    'invariants' => [
                        'no_silent_obra_creation' => true,
                    ],
                    'no_silent_fallback' => true,
                    'separated_from_external_rivals' => true,
                    'external_provider_call' => false,
                ],
                'atlas_forge_provider_capacity_certification' => [
                    'invariants' => [
                        'static_policy_does_not_dispatch' => true,
                    ],
                ],
                'atlas_code_forge_review_completion_certification' => [
                    'lifecycle_invariants' => [
                        'no_auto_completion_without_human_review' => true,
                    ],
                ],
                'rules' => [
                    'synthetic_scores_allowed' => false,
                ],
            ],
            'docs_health' => [
                'oversized_count' => 0,
                'violations_count' => 0,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array{bucket: ?string, deviation: bool, reason: string}
     */
    private function classifyPortfolio(array $packet): array
    {
        $explicit = $this->stringOrNull(data_get($packet, 'strategy_bucket'));
        if ($explicit !== null && in_array($explicit, AtlasSelfImprovementStrategyPortfolioService::BUCKETS, true)) {
            return [
                'bucket' => $explicit,
                'deviation' => false,
                'reason' => 'explicit_proposal_bucket',
            ];
        }

        $title = strtolower((string) ($packet['title'] ?? ''));
        $target = strtolower((string) ($packet['target_capability'] ?? ''));
        $needle = $title.' '.$target;

        $bucket = match (true) {
            str_contains($needle, 'security') || str_contains($needle, 'governance') => AtlasSelfImprovementStrategyPortfolioService::BUCKET_SECURITY_GOVERNANCE,
            str_contains($needle, 'rivals') || str_contains($needle, 'evaluation') => AtlasSelfImprovementStrategyPortfolioService::BUCKET_RIVALS_EVALUATION,
            str_contains($needle, 'self_construction') || str_contains($needle, 'self-construction') => AtlasSelfImprovementStrategyPortfolioService::BUCKET_SELF_CONSTRUCTION,
            str_contains($needle, 'provider') => AtlasSelfImprovementStrategyPortfolioService::BUCKET_PROVIDER_INTELLIGENCE,
            str_contains($needle, 'cockpit') || str_contains($needle, 'operator') || preg_match('/\bux\b/u', $needle) === 1 => AtlasSelfImprovementStrategyPortfolioService::BUCKET_OPERATOR_EXPERIENCE,
            str_contains($needle, 'reliability') || str_contains($needle, 'enterprise') => AtlasSelfImprovementStrategyPortfolioService::BUCKET_ENTERPRISE_RELIABILITY,
            str_contains($needle, 'doc') || str_contains($needle, 'lint') || str_contains($needle, 'typo') => AtlasSelfImprovementStrategyPortfolioService::BUCKET_QUICK_WINS,
            default => AtlasSelfImprovementStrategyPortfolioService::BUCKET_CORE_RUNTIME,
        };

        // Portfolio deviation: compare against the recommended next bucket
        // returned by an empty portfolio (low-signal). Our heuristic is:
        // if `bucket` differs from `core_runtime` AND the portfolio prefers
        // the underweight bucket pulled from `recommended_next_bucket`, we
        // mark deviation true so the operator sees the mismatch — but never
        // block automatically.
        $portfolio = $this->strategyPortfolio->snapshot([]);
        $recommended = $this->stringOrNull($portfolio['recommended_next_bucket'] ?? null);
        $deviation = $recommended !== null && $recommended !== $bucket;

        return [
            'bucket' => $bucket,
            'deviation' => $deviation,
            'reason' => $deviation
                ? 'portfolio_recommends_'.$recommended.'_but_proposal_targets_'.$bucket
                : 'aligned_with_portfolio',
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function collectDocHashes(string $workspaceRoot): array
    {
        $docs = [];
        foreach (self::REQUIRED_DOCS as $relative) {
            $docs[$relative] = $this->docEntry($workspaceRoot, $relative, required: true);
        }
        foreach (self::OPTIONAL_DOCS as $relative) {
            $docs[$relative] = $this->docEntry($workspaceRoot, $relative, required: false);
        }

        return $docs;
    }

    /**
     * @return array<string,mixed>
     */
    private function docEntry(string $workspaceRoot, string $relative, bool $required): array
    {
        $absolute = $workspaceRoot.DIRECTORY_SEPARATOR.$relative;
        $present = is_file($absolute);
        $hash = null;
        if ($present) {
            try {
                $hash = hash_file('sha256', $absolute);
            } catch (Throwable) {
                $hash = null;
            }
        }

        return [
            'path' => $relative,
            'required' => $required,
            'present' => $present,
            'hash' => $hash,
        ];
    }

    /**
     * @param  array<string,mixed>  $gate
     */
    private function statusFromGate(array $gate): string
    {
        return match ((string) ($gate['outcome'] ?? '')) {
            AtlasSelfImprovementProposalPowerGateService::OUTCOME_APPROVED => self::STATUS_ACCEPTED,
            AtlasSelfImprovementProposalPowerGateService::OUTCOME_HUMAN_REVIEW_REQUIRED => self::STATUS_PENDING_HUMAN_REVIEW,
            AtlasSelfImprovementProposalPowerGateService::OUTCOME_NEEDS_REVISION => self::STATUS_NEEDS_REVISION,
            AtlasSelfImprovementProposalPowerGateService::OUTCOME_REJECTED => self::STATUS_BLOCKED,
            default => self::STATUS_BLOCKED,
        };
    }

    /**
     * @param  list<string>  $blockers
     */
    private function resolveInitialStatus(string $statusFromGate, array $blockers, bool $dryRun): string
    {
        if ($blockers !== []) {
            return self::STATUS_BLOCKED;
        }
        if ($dryRun) {
            return self::STATUS_DRY_RUN;
        }

        return $statusFromGate;
    }

    private function resolveNextAction(string $status): string
    {
        return match ($status) {
            self::STATUS_BLOCKED => 'resolve_blockers_then_replan',
            self::STATUS_NEEDS_REVISION => 'rewrite_proposal_and_replan',
            self::STATUS_PENDING_HUMAN_REVIEW => 'await_explicit_accept_with_reviewer_and_reason',
            self::STATUS_DRY_RUN => 'review_dry_run_then_replan_without_dry_run',
            self::STATUS_ACCEPTED => 'open_atlas_code_forge',
            self::STATUS_OBRA_CREATED => 'open_atlas_code_forge',
            self::STATUS_REJECTED => 'rewrite_proposal_and_replan_activation_or_close',
            default => 'inspect_status',
        };
    }

    /**
     * @param  array<string,mixed>  $activation
     * @return array<string,mixed>
     */
    private function materialiseObra(array $activation, string $reviewer, string $reason): array
    {
        $packet = $activation['proposal_packet'] ?? [];
        $title = $this->stringOrNull($activation['operator_obra_title'] ?? null)
            ?? $this->stringOrNull($packet['title'] ?? null)
            ?? 'Self-improvement Obra';

        $obra = AtlasProject::create([
            'id' => (string) Str::uuid(),
            'title' => $title,
            'description' => (string) ($packet['problem_statement'] ?? ''),
            'status' => 'active',
            'domain' => 'programming',
            'goal' => (string) ($packet['target_capability'] ?? ''),
            'desired_outcome' => (string) ($packet['expected_power_gain'] ?? ''),
            'priority' => 'normal',
            'metadata' => [
                'workspace_path' => $this->safeWorkspacePath($activation),
                'origin' => 'atlas-self-improvement-forge-activation',
                'self_improvement_activation' => [
                    'activation_id' => $activation['activation_id'],
                    'proposal_id' => $packet['proposal_id'] ?? null,
                    'proposal_hash' => $activation['proposal_hash'] ?? null,
                    'power_gate_hash' => $activation['power_gate_hash'] ?? null,
                    'invariant_lock_hash' => $activation['invariant_lock_hash'] ?? null,
                    'regression_sentinel_hash' => $activation['regression_sentinel_hash'] ?? null,
                    'maturity_target' => $activation['maturity_target'] ?? null,
                    'strategy_bucket' => $activation['strategy_bucket'] ?? null,
                    'portfolio_deviation' => $activation['portfolio_deviation'] ?? false,
                    'reviewer' => $reviewer,
                    'reason' => $reason,
                    'approved_at' => $activation['approval']['approved_at'] ?? Carbon::now()->toIso8601String(),
                ],
            ],
        ]);

        // Populate the canonical work intake.
        $intake = $this->forgeWorkIntake->save($obra, [
            'objective' => (string) ($packet['target_capability'] ?? ''),
            'business_rule' => (string) ($packet['business_rule'] ?? ''),
            'acceptance_criteria' => (array) ($packet['acceptance_gates'] ?? []),
            'canonical_docs' => array_values(array_filter(array_merge(
                (array) ($packet['canonical_docs'] ?? []),
                ['docs/engineering-knowledge-base/atlas-self-improvement-forge-activation-v1.md'],
            ))),
            'scope_in' => (array) ($packet['allowed_paths'] ?? []),
            'scope_out' => (array) ($packet['forbidden_paths'] ?? []),
            'risk_level' => (string) data_get($packet, 'risk_classification.risk_level', 'medium'),
            'expected_outputs' => [(string) ($packet['expected_power_gain'] ?? '')],
            'constraints' => array_values(array_filter([
                'never_call_provider_without_explicit_approval',
                'never_auto_execute_fast_path_from_self_improvement',
                'never_promote_completion_claim_from_activation',
            ])),
            'operator_notes' => 'Created from Self-Improvement Forge Activation '.$activation['activation_id'],
        ]);

        $activation['created_obra_id'] = (string) $obra->getKey();
        $activation['created_obra_title'] = $title;
        $activation['work_intake'] = $intake;
        $activation['status'] = self::STATUS_OBRA_CREATED;
        $activation['next_action'] = 'open_atlas_code_forge';

        return $activation;
    }

    /**
     * @param  array<string,mixed>  $activation
     */
    private function safeWorkspacePath(array $activation): string
    {
        $base = sys_get_temp_dir().'/atlas-self-improvement/'.(string) ($activation['activation_id'] ?? Str::ulid());

        return $base;
    }

    /**
     * Build a deterministic approval receipt.
     *
     * @param  array<string,mixed>  $activation
     * @return array<string,mixed>
     */
    private function buildApprovalReceipt(
        string $activationId,
        string $reviewer,
        string $reason,
        array $activation,
    ): array {
        $approvedAt = Carbon::now()->toIso8601String();
        $receipt = [
            'schema_version' => self::APPROVAL_SCHEMA_VERSION,
            'activation_id' => $activationId,
            'reviewer' => $reviewer,
            'reason' => $reason,
            'approved_at' => $approvedAt,
            'proposal_hash' => $activation['proposal_hash'] ?? null,
            'power_gate_hash' => $activation['power_gate_hash'] ?? null,
            'invariant_lock_hash' => $activation['invariant_lock_hash'] ?? null,
            'regression_sentinel_hash' => $activation['regression_sentinel_hash'] ?? null,
            'maturity_target' => $activation['maturity_target'] ?? null,
            'silent' => false,
            'auto_promotes_completion_claim' => false,
            'auto_executes_fast_path' => false,
            'external_provider_call' => false,
        ];
        $receipt['receipt_hash'] = $this->hashJson($receipt);

        return $receipt;
    }

    /**
     * @param  array<string,array<string,mixed>>  $docs
     * @param  array<string,mixed>|null  $packet
     * @param  array<string,mixed>|null  $gate
     * @param  array<string,mixed>|null  $beforeSnapshot
     * @param  array<string,mixed>|null  $approval
     * @return list<string>
     */
    private function evidenceRefs(
        array $docs,
        ?array $packet,
        ?array $gate,
        ?array $beforeSnapshot,
        ?array $approval,
        ?string $createdObraId,
    ): array {
        $refs = [];
        foreach ($docs as $relative => $entry) {
            if (($entry['present'] ?? false) && is_string($entry['hash'] ?? null)) {
                $refs[] = 'doc:'.$relative.'@'.$entry['hash'];
            }
        }
        if (is_array($packet) && isset($packet['proposal_id'])) {
            $refs[] = 'proposal:'.$packet['proposal_id'];
        }
        if (is_array($gate) && isset($gate['gate_id'])) {
            $refs[] = 'power_gate:'.$gate['gate_id'];
        }
        if (is_array($beforeSnapshot)) {
            foreach (['maturity', 'invariant_lock', 'regression_sentinel', 'strategy_portfolio', 'trust_ledger'] as $kind) {
                $hash = data_get($beforeSnapshot, $kind.'.hash');
                if (is_string($hash) && $hash !== '') {
                    $refs[] = 'baseline:'.$kind.'@'.$hash;
                }
            }
        }
        if (is_array($approval)) {
            $refs[] = 'approval:'.($approval['receipt_hash'] ?? 'unknown');
        }
        if ($createdObraId !== null) {
            $refs[] = 'obra:'.$createdObraId;
        }

        return array_values(array_unique($refs));
    }

    /**
     * @param  array<string,mixed>  $activation
     */
    private function recordTrustLedger(
        ?string $obraId,
        string $outcome,
        array $activation,
        string $reviewer,
        string $reason,
    ): void {
        $project = null;
        if ($obraId !== null) {
            try {
                $project = AtlasProject::query()->whereKey($obraId)->first();
            } catch (Throwable) {
                $project = null;
            }
        }
        if ($project === null) {
            try {
                $this->humanTrustLedger->record(null, [
                    'outcome' => $outcome,
                    'proposal_id' => $activation['proposal_id'] ?? null,
                    'reviewer' => $reviewer,
                    'reason' => $reason,
                    'area' => $activation['strategy_bucket'] ?? null,
                ]);
            } catch (InvalidArgumentException) {
                // Outcome may not yet exist in the ledger; safe to ignore.
            } catch (Throwable) {
                // best-effort.
            }

            return; // memory is per-Obra; global evidence is ledger-only.
        }

        try {
            $this->humanTrustLedger->record($project, [
                'outcome' => $outcome,
                'proposal_id' => $activation['proposal_id'] ?? null,
                'reviewer' => $reviewer,
                'reason' => $reason,
                'area' => $activation['strategy_bucket'] ?? null,
            ]);
        } catch (InvalidArgumentException) {
            // Outcome may not yet exist in the ledger; safe to ignore — the
            // trust ledger never auto-fills synthetic outcomes.
        } catch (Throwable) {
            // best-effort.
        }
    }

    /**
     * @param  array<string,mixed>  $activation
     */
    private function activationCanBeAccepted(array $activation): bool
    {
        if (($activation['status'] ?? null) === self::STATUS_BLOCKED) {
            return false;
        }
        if (($activation['blockers'] ?? []) !== []) {
            return false;
        }
        if (($activation['missing_required_docs'] ?? []) !== []) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string,mixed>  $activation
     */
    private function saveActivation(array $activation): void
    {
        $disk = Storage::disk(self::STORAGE_DISK);
        $path = self::STORAGE_PREFIX.'/'.$activation['activation_id'].'.json';
        $disk->put($path, (string) json_encode(
            $activation,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        // Mirror to ledger if available (best effort).
        if (Schema::hasTable('atlas_ledger_events')) {
            try {
                DB::table('atlas_ledger_events')->insert([
                    'event_id' => (string) Str::ulid(),
                    'schema_version' => self::SCHEMA_VERSION,
                    'event_type' => 'SELF_IMPROVEMENT_FORGE_ACTIVATION_'.strtoupper($activation['status']),
                    'emitter_stage' => 'self_improvement_forge_activation',
                    'emitter_version' => 'v1',
                    'payload' => json_encode($activation, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'occurred_at' => $activation['generated_at'] ?? now()->toIso8601String(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (Throwable) {
                // ledger is best-effort.
            }
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadActivation(string $activationId): ?array
    {
        $disk = Storage::disk(self::STORAGE_DISK);
        $path = self::STORAGE_PREFIX.'/'.$activationId.'.json';
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
     * @param  array<string,mixed>  $activation
     */
    private function updateGlobalRegistry(array $activation): void
    {
        $disk = Storage::disk(self::STORAGE_DISK);
        $path = self::STORAGE_PREFIX.'/_registry.json';
        $current = ['activations' => []];
        if ($disk->exists($path)) {
            try {
                $decoded = json_decode((string) $disk->get($path), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $current = $decoded;
                }
            } catch (Throwable) {
                $current = ['activations' => []];
            }
        }

        $entries = is_array($current['activations'] ?? null) ? $current['activations'] : [];
        $entry = [
            'activation_id' => $activation['activation_id'],
            'status' => $activation['status'],
            'proposal_id' => $activation['proposal_id'] ?? null,
            'strategy_bucket' => $activation['strategy_bucket'] ?? null,
            'created_obra_id' => $activation['created_obra_id'] ?? null,
            'updated_at' => Carbon::now()->toIso8601String(),
        ];
        // Replace existing same-id entry; otherwise prepend.
        $entries = array_values(array_filter(
            $entries,
            fn (mixed $row): bool => is_array($row) && (string) ($row['activation_id'] ?? '') !== (string) $activation['activation_id'],
        ));
        array_unshift($entries, $entry);
        $entries = array_slice($entries, 0, self::HISTORY_CAP);

        $disk->put($path, (string) json_encode([
            'schema_version' => 'atlas.self_improvement.forge_activation_registry.v1',
            'activations' => $entries,
            'updated_at' => Carbon::now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hashJson(array $payload): string
    {
        return hash('sha256', (string) json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
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
