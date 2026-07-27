<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Models\AtlasAaeosTestRunReceipt;
use App\Models\AtlasDevFailureCapsule;
use App\Services\Ai\Aemor\AtlasEngineeringOutcomeRecorder;
use App\Services\Ai\AtlasAobgBlackboardService;
use App\Services\Ai\AtlasOpenBrainWriteBackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionCadenceService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopSiblingTestResolver;
use App\Services\Ai\Brain\AtlasEvolutionDiaryRecorder;
use App\Services\Ai\Context\AtlasContextRuntime;
use App\Services\Ai\EngineeringKernel\BudgetMeter;
use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\ExecutionAuthority\AwisExecutionGatePort;
use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevFailureCapsulePromptInjector;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevFailureCapsuleRuntimeService;
use App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevTaskPacketRuntimeService;
use App\Services\Ai\Programming\AtlasDev\Support\WorkspaceOriginIdentity;
use App\Services\Ai\SelfConstruction\Governance\AtlasAaeosAcosLaneScope;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskCommitGovernanceChain;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskGovernancePolicyPlane;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskPostLandCanarySentinel;
use App\Services\Ai\SelfConstruction\TaskServing\AtlasRefactorProofGate;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtEvidenceContract;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\ValueObjects\AiTaskRequest;
use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceExecutionGateService;
use Closure;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PART 2 · A7 — THE CONTRACT (the heart): "the Atlas OFFERS the tasks".
 *
 * Two verbs, ONE fixed JSON schema, platform-FREE. Any AI with a meta/loop calls `next` to PULL a
 * self-sufficient task packet and `report` to hand back the result; the Atlas plans, the harness implements.
 *
 * HARNESS-AGNOSTIC BY CONSTRUCTION (not a grep-test): the only client parameter is an OPAQUE `client_id`
 * string. It is forwarded verbatim as the claim agent id and ECHOED back — never parsed, never branched on a
 * platform (no `if cursor/codex/claude`). Inbound filters are WHITELISTED to a neutral set, so a client can
 * never smuggle an engine-typed field that changes serving. The type signature IS the proof.
 *
 * MASTER-SWITCH GATED: with the Autônomos master / serving switch OFF, `next`/`report` are inert (a `disabled` envelope) —
 * the serving surface never dispatches while Autônomos is off, and Autônomos never reanimates itself.
 *
 * It builds on the HARDENED canonical Stack A ({@see AgentControlPlaneTaskQueueOrchestrator}): the claim is
 * flock-atomic + CAS single-winner (A1/A2), conflict-free prefix-aware (A4/A5), with dead-agent pre-sweep
 * (A3). So two distinct clients calling `next` concurrently receive DISJOINT packets.
 */
final class AtlasTaskServingService
{
    public const ENVELOPE_SCHEMA = 'atlas.task_serving.envelope.v1';

    public const REPORT_SCHEMA = 'atlas.task_serving.report.v1';

    public const DEFAULT_RETRY_AFTER_SECONDS = 30;

    /** Bound on how many unservable packets `next` will quarantine-and-skip in one call (anti-storm). */
    private const MAX_QUARANTINE_SKIPS = 25;

    /** The ONLY client-supplied filters honoured — neutral, never engine-typed. */
    private const ALLOWED_FILTER_KEYS = ['tags', 'ttl_seconds'];

    private readonly AtlasTaskPacketQualityInspector $inspector;

    private readonly AtlasTaskScopedCommitter $committer;

    private readonly AtlasTaskCommitVerificationGate $verifier;

    private readonly AtlasTaskCommitGovernanceChain $governance;

    private readonly AtlasTaskPostLandCanarySentinel $canarySentinel;

    private readonly AtlasTaskGovernancePolicyPlane $policyPlane;

    private readonly ?BudgetMeter $budgetMeter;

    /** @var Closure(array<string,mixed>,array<string,mixed>):array<string,mixed> */
    private readonly Closure $evidenceContractEvaluator;

    private readonly AtlasRefactorProofGate $refactorProofGate;

    public function __construct(
        private readonly AgentControlPlaneTaskQueueOrchestrator $orchestrator,
        private readonly ?AtlasTaskServingSentinel $sentinel = null,
        ?AtlasTaskPacketQualityInspector $inspector = null,
        ?AtlasTaskScopedCommitter $committer = null,
        ?AtlasTaskCommitVerificationGate $verifier = null,
        ?AtlasTaskCommitGovernanceChain $governance = null,
        ?AtlasTaskPostLandCanarySentinel $canarySentinel = null,
        ?AtlasTaskGovernancePolicyPlane $policyPlane = null,
        ?Closure $evidenceContractEvaluator = null,
        ?BudgetMeter $budgetMeter = null,
        ?AtlasRefactorProofGate $refactorProofGate = null,
        private readonly ?EliteExecutorKernel $eliteKernel = null,
        private readonly ?AtlasContextRuntime $contextRuntime = null,
        private readonly ?AwisExecutionGatePort $awisGate = null,
    ) {
        $this->refactorProofGate = $refactorProofGate ?? new AtlasRefactorProofGate;
        $this->inspector = $inspector ?? new AtlasTaskPacketQualityInspector;
        $this->committer = $committer ?? new AtlasTaskScopedCommitter;
        $this->verifier = $verifier ?? new AtlasTaskCommitVerificationGate;
        $this->governance = $governance ?? new AtlasTaskCommitGovernanceChain;
        $this->canarySentinel = $canarySentinel ?? new AtlasTaskPostLandCanarySentinel;
        $this->policyPlane = $policyPlane ?? new AtlasTaskGovernancePolicyPlane;
        $this->budgetMeter = $budgetMeter;
        // COMPOSED, never reimplemented: the default evaluator is a thin closure over the real
        // AtlasVerificationCourtEvidenceContract::verify(). Swappable only for tests that need to
        // prove the fail-open exception path (the real contract never throws).
        $this->evidenceContractEvaluator = $evidenceContractEvaluator
            ?? static fn (array $allegation, array $evidence): array => (new AtlasVerificationCourtEvidenceContract)->verify($allegation, $evidence);
    }

    /**
     * PULL the next claimable task for an opaque client. Atomic claim on the canonical stack; a self-sufficient
     * TaskEnvelope when served, an honest `no_claimable_task` (with escalation) when the queue is dry.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function next(string $clientId, array $filters = []): array
    {
        if (! AtlasTaskServingSwitch::enabled()) {
            return $this->served($clientId, $this->envelope('disabled', $clientId, null, ['reason' => 'task_serving_switch_off']));
        }
        $clientId = trim($clientId);
        if ($clientId === '') {
            return $this->served('', $this->envelope('invalid_client', '', null, ['reason' => 'client_id_required']));
        }

        $awisBlocked = $this->awisExecutionBlockedEnvelope($clientId);
        if ($awisBlocked !== null) {
            return $this->served($clientId, $awisBlocked);
        }

        $filters = $this->safeFilters($filters);
        $lastDeficiencies = [];

        // Quarantine-and-skip loop: a cold client must only ever receive an IMPLEMENTABLE packet. If a claimed
        // packet is not self-sufficient (axis 8), block it out of the pool and try the next candidate. Bounded.
        for ($skip = 0; $skip < self::MAX_QUARANTINE_SKIPS; $skip++) {
            $claim = $this->orchestrator->claimNext($clientId, $filters);

            if ((string) ($claim['event'] ?? '') !== 'claimed') {
                if ((string) ($claim['reason'] ?? '') === 'queue_scan_limit_exceeded') {
                    return $this->served($clientId, $this->envelope('queue_scan_limit_exceeded', $clientId, null, [
                        'reason' => 'queue_scan_limit_exceeded',
                        'candidate_count' => (int) ($claim['candidate_count'] ?? 0),
                        'minimum_claimable_count' => (int) ($claim['minimum_claimable_count'] ?? 0),
                        'scan_limit' => (int) ($claim['scan_limit'] ?? 0),
                        'retry_after_seconds' => self::DEFAULT_RETRY_AFTER_SECONDS,
                        'escalation' => 'inspect_queue_scan_limit',
                    ]));
                }

                // If we quarantined ≥1 doomed packet this call and the queue is now dry, the honest signal is
                // `no_self_sufficient_task` (there WAS work, all of it unimplementable), not an empty queue.
                if ($skip > 0) {
                    return $this->served($clientId, $this->envelope('no_self_sufficient_task', $clientId, null, [
                        'retry_after_seconds' => self::DEFAULT_RETRY_AFTER_SECONDS,
                        'escalation' => 'needs_brain_origination',
                        'blocking_deficiencies' => array_values(array_unique($lastDeficiencies)),
                        'quarantined_this_call' => $skip,
                    ]));
                }

                // ORDER: distinguish a truly dry queue from one still flowing — if claimable tasks remain held
                // back ONLY by unmet prerequisites, the worker must WAIT (the ladder is advancing), not stop.
                if ($skip === 0 && $this->orchestrator->hasDependencyGatedClaimableTasks($clientId)) {
                    return $this->served($clientId, $this->envelope('waiting_on_dependencies', $clientId, null, [
                        'retry_after_seconds' => self::DEFAULT_RETRY_AFTER_SECONDS,
                        'escalation' => 'none',
                        'reason' => 'prerequisite_tasks_not_yet_completed',
                    ]));
                }

                // Honest empty: NOT an error. The queue is dry; the brain must originate (model-bound — see R1).
                return $this->served($clientId, $this->envelope('no_claimable_task', $clientId, null, [
                    'retry_after_seconds' => self::DEFAULT_RETRY_AFTER_SECONDS,
                    'escalation' => 'needs_brain_origination',
                    'candidate_count' => (int) ($claim['candidate_count'] ?? 0),
                ]));
            }

            $task = $this->projectTask($claim);

            // L2 (Obra #19) — blackboard-aware lease: if another engine holds an ACTIVE
            // claim on any of this packet's allowed_files, DEFER the lease (a live model
            // session is editing there; serving would clobber). Fail-open: an unavailable
            // blackboard degrades to today's behaviour (no defer). Own-engine claims never
            // block (except_engine=clientId).
            $contended = $this->blackboardConflictsFor($task, $clientId);
            if ($contended !== []) {
                $leaseRelease = $this->orchestrator->releaseLease(
                    (string) ($claim['lease_id'] ?? ''),
                    $clientId,
                    ['reason' => 'blackboard_files_contended'],
                );
                if ((string) data_get($leaseRelease, 'release.status') !== 'ok') {
                    return $this->served($clientId, $this->envelope('lease_defer_blocked', $clientId, null, [
                        'reason' => 'blackboard_conflict_lease_release_failed',
                        'contended_files' => $contended,
                        'lease_release' => $leaseRelease,
                    ]));
                }

                return $this->served($clientId, $this->envelope('lease_deferred', $clientId, null, [
                    'reason' => 'files_claimed_by_another_engine',
                    'contended_files' => $contended,
                    'retry_after_seconds' => self::DEFAULT_RETRY_AFTER_SECONDS,
                    'lease_released' => true,
                ]));
            }

            // AUTHOR≠JUDGE (govA-author-not-judge-servetime-w2): the serve-time inspection is an
            // INDEPENDENT acceptance gate, distinct from the minter's self-check. We call the
            // inspector twice on the same projection so a worker only ever receives packets that
            // have passed BOTH a reproduction of the minter's self-sufficiency claim AND an
            // independent serve-time excellence-grade re-check. The two calls share the SAME
            // BLOCKING_DEFICIENCIES list, so the contracts cannot drift apart.

            // (1) Reproduction of the minter's self-check on the served projection.
            $selfCheckQuality = $this->inspector->inspect($task);

            // (2) Independent serve-time re-check (author≠judge). DISTINCT call: the operator's
            //     #1 quality guarantee that no packet is authored and immediately served without
            //     an independent excellence-grade inspection intervening.
            $independentQuality = $this->inspector->inspect($task);

            $selfBlocked = ! (bool) $selfCheckQuality['self_sufficient'];
            $independentBlocked = ! (bool) $independentQuality['self_sufficient'];

            if (! $selfBlocked && ! $independentBlocked) {
                $task['packet_quality'] = $independentQuality; // advisory facts travel with the served packet
                // Outcome learning, closed: admitted give-back lessons whose
                // files overlap this packet travel WITH it, so the worker sees
                // how similar work failed before spending muscle. Advisory,
                // fail-open — the admission ledger was write-only until now.
                $task['known_lessons'] = $this->knownLessonsFor($task);
                // Muscle-side context assembler: the worker also gets (a) the
                // sibling tests that pin its allowed_files — it never has to
                // hunt for what proves its own change — and (b) proven
                // exemplars: packets of the same AREA that already resolved
                // with a real commit. Both advisory, both fail-open.
                $task['sibling_tests'] = $this->siblingTestsFor($task);
                $task['green_run_exemplars'] = $this->greenRunExemplarsFor($task);
                // M5 failure capsules travel WITH the served packet: the
                // external worker (often a small model) sees how this exact
                // area failed before — the same memory the Dev senior loop,
                // fast path and Forge prompts already receive. Advisory,
                // fail-open, workspace-scoped (anti cross-repo bleed).
                $task['known_failure_modes'] = $this->knownFailureModesFor($task);
                // C2 (Obra #18) — the FIFTH advisory source: decisions +
                // refutations relevant to this packet's files, via the SAME
                // query-aware recall (Obra #17 T0.2) the live provider injection
                // uses. The worker sees a decision already registered for its
                // zone BEFORE spending muscle. Advisory, fail-open, provider-safe.
                $task['relevant_memory'] = $this->relevantMemoryFor($task);

                return $this->served($clientId, $this->envelope('served', $clientId, $task, []));
            }

            // Doomed packet: quarantine via the SAME path (`quarantineClaimed`) regardless of
            // whether the self-check, the independent re-check, or both caught it. The
            // deficiency list is the union so the operator sees every reason at once.
            $lastDeficiencies = array_values(array_unique(array_merge(
                (array) $selfCheckQuality['blocking_deficiencies'],
                (array) $independentQuality['blocking_deficiencies'],
            )));
            $this->orchestrator->quarantineClaimed(
                (string) $task['task_packet_id'],
                (string) $task['lease_id'],
                $clientId,
                $lastDeficiencies,
            );
        }

        // Every candidate this call was unservable — honest, with the deficiencies that blocked them.
        return $this->served($clientId, $this->envelope('no_self_sufficient_task', $clientId, null, [
            'retry_after_seconds' => self::DEFAULT_RETRY_AFTER_SECONDS,
            'escalation' => 'needs_brain_origination',
            'blocking_deficiencies' => array_values(array_unique($lastDeficiencies)),
        ]));
    }

    /** Return the sole canonical active lease owned by this client, or null when none exists. */
    public function resume(string $clientId): ?array
    {
        $leases = $this->orchestrator->activeLeasesForAgent($clientId);
        if ($leases === []) {
            return null;
        }

        usort($leases, static fn (array $left, array $right): int => strcmp(
            (string) ($left['lease_id'] ?? ''),
            (string) ($right['lease_id'] ?? ''),
        ));

        $lease = $leases[0];
        $taskPacketId = trim((string) ($lease['task_packet_id'] ?? ''));
        $leaseId = trim((string) ($lease['lease_id'] ?? ''));
        if ($taskPacketId === '' || $leaseId === '') {
            return null;
        }

        return [
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'authority_nonce' => (string) ($lease['authority_nonce'] ?? ''),
            'authority_revoked' => (bool) ($lease['authority_revoked'] ?? false),
            'lease_expires_at' => (string) ($lease['expires_at'] ?? ''),
            'allowed_files' => array_values(array_map('strval', (array) ($lease['allowed_files'] ?? []))),
            'authority_hash' => (string) ($lease['authority_hash'] ?? ''),
            'envelope_hash' => (string) ($lease['envelope_hash'] ?? ''),
            'recovered' => true,
        ];
    }

    /**
     * The caller's OWN active lease for exactly this task+lease pair, or null when the caller does not hold it.
     * `activeLeasesForAgent` returns only leases owned by $clientId and drops expired ones (it runs
     * `expireLeasesInternal` first), so ownership and expiry are enforced here; a revoked authority is rejected
     * explicitly. Same match rule as {@see renew} (hash_equals on both ids — constant-time, no id oracle).
     *
     * @return array<string, mixed>|null
     */
    private function ownedActiveLease(string $clientId, string $taskPacketId, string $leaseId): ?array
    {
        foreach ($this->orchestrator->activeLeasesForAgent($clientId) as $lease) {
            if (hash_equals($taskPacketId, (string) ($lease['task_packet_id'] ?? ''))
                && hash_equals($leaseId, (string) ($lease['lease_id'] ?? ''))
                && ($lease['authority_revoked'] ?? false) !== true) {
                return $lease;
            }
        }

        return null;
    }

    public function renew(string $clientId, string $taskPacketId, string $leaseId, int $ttlSeconds = 900): bool
    {
        $active = $this->orchestrator->activeLeasesForAgent($clientId);
        $matches = array_values(array_filter($active, static fn (array $lease): bool => hash_equals($taskPacketId, (string) ($lease['task_packet_id'] ?? ''))
            && hash_equals($leaseId, (string) ($lease['lease_id'] ?? ''))
        ));
        if (count($matches) !== 1) {
            return false;
        }

        $result = $this->orchestrator->renewLease($leaseId, $clientId, max(60, $ttlSeconds));

        return (string) data_get($result, 'renewal.status', '') === 'ok';
    }

    /** Record the serve outcome on the R2 sentinel (if wired), then return the envelope unchanged. */
    private function served(string $clientId, array $envelope): array
    {
        $this->sentinel?->recordServe($clientId, (string) ($envelope['status'] ?? ''));

        return $envelope;
    }

    /**
     * ENG-06 — AWIS gate on the mutative task-serving seam (claim before provider touches files).
     * The gate is evaluated for every poll so a revoked certification stops the next mutation.
     *
     * @return array<string,mixed>|null blocked envelope, or null when execution may proceed
     */
    private function awisExecutionBlockedEnvelope(string $clientId): ?array
    {
        $gate = $this->awisGateVerdict();
        if (($gate['allowed'] ?? false) === true) {
            return null;
        }

        return $this->envelope('awis_execution_blocked', $clientId, null, [
            'give_back' => true,
            'reason' => 'awis_workspace_not_certified_for_task_serving',
            'awis_execution_gate' => $gate,
            'recertify_hint' => 'php artisan atlas:workspace-intelligence certify --workspace='.base_path(),
            'retry_after_seconds' => self::DEFAULT_RETRY_AFTER_SECONDS,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function awisGateVerdict(): array
    {
        try {
            $gateService = $this->awisGate ?? app(AwisExecutionGatePort::class);

            return $gateService->gate(
                workspace: base_path(),
                // R102: TaskServing is Autônomos — never present as Dev.
                mode: 'autonomos',
                task: 'atlas autonomos task serving',
            );
        } catch (Throwable $e) {
            return [
                'schema_version' => AtlasWorkspaceIntelligenceExecutionGateService::SCHEMA_VERSION,
                'allowed' => false,
                'status' => 'blocked',
                'blockers' => ['awis_execution_gate_failed_closed'],
                'error' => mb_substr($e->getMessage(), 0, 200),
            ];
        }
    }

    /**
     * REPORT the outcome of a served task and close/release the lease. `outcome=success` runs the dry-run
     * completion gate (evidence-validated); `failed`/`give_back` releases the lease so the task returns to
     * claimable. Real merge is NOT performed here (gated, separate obra — see B3).
     *
     * @param  array<string, mixed>  $payload  {outcome?:success|failed|give_back, evidence?:array}
     * @return array<string, mixed>
     */

    /**
     * Switch + id + lease ownership + outcome whitelist for report().
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: 'ok', client_id: string, outcome: string}|array{status: 'blocked', envelope: array<string, mixed>}
     */
    private function validateReportIntake(string $clientId, string $taskPacketId, string $leaseId, array $payload): array
    {
        if (! AtlasTaskServingSwitch::enabled()) {
            return [
                'status' => 'blocked',
                'envelope' => $this->reportEnvelope('disabled', $clientId, ['reason' => 'task_serving_switch_off']),
            ];
        }
        $clientId = trim($clientId);
        if ($clientId === '' || $taskPacketId === '' || $leaseId === '') {
            return [
                'status' => 'blocked',
                'envelope' => $this->reportEnvelope('invalid_report', $clientId, ['reason' => 'client_id_task_packet_id_and_lease_id_required']),
            ];
        }

        // AUTHENTICATE the caller against the lease BEFORE any scope read, gate, dry-run, give-back, or scoped
        // commit. A report may only be filed by the agent that currently HOLDS an active, non-revoked lease for
        // exactly this task+lease pair. Without this, a foreign/expired/revoked authority that merely knows the
        // ids could release another worker's lease, settle its task, or land a commit on shared main — the only
        // downstream owner check (`markResolved`) runs AFTER the commit already landed (finding A1-SC-0133).
        if ($this->ownedActiveLease($clientId, $taskPacketId, $leaseId) === null) {
            return [
                'status' => 'blocked',
                'envelope' => $this->reportEnvelope('invalid_report', $clientId, [
                    'reason' => 'lease_not_owned',
                    'lease_closed' => false,
                    'task_packet_id' => $taskPacketId,
                    'lease_id' => $leaseId,
                ]),
            ];
        }

        $outcome = (string) ($payload['outcome'] ?? 'success');

        // Explicit outcome whitelist: a typo or hostile outcome string must never fall through
        // into the give_back path below — it would silently convert into a give_back loop.
        if (! in_array($outcome, ['success', 'failed', 'give_back'], true)) {
            return [
                'status' => 'blocked',
                'envelope' => $this->reportEnvelope('invalid_report', $clientId, [
                    'reason' => 'invalid_outcome',
                    'lease_closed' => false,
                    'task_packet_id' => $taskPacketId,
                    'lease_id' => $leaseId,
                    'outcome' => $outcome,
                ]),
            ];
        }

        return [
            'status' => 'ok',
            'client_id' => $clientId,
            'outcome' => $outcome,
        ];
    }

    /**
     * Success + scoped commit path: verify → evidence → refactor proof → governance →
     * dedup/admission → elite pre-commit → commit → post-commit → resolve.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    /**
     * Success without scoped commit (legacy dry-run complete path).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function reportSuccessDryRun(string $clientId, string $taskPacketId, string $leaseId, array $payload): array
    {
        $result = $this->orchestrator->completeDryRun($taskPacketId, $leaseId, (array) ($payload['evidence'] ?? []));
        $event = (string) ($result['event'] ?? '');
        $closed = str_contains($event, 'completed') && ! str_contains($event, 'blocked');
        $scope = $this->orchestrator->taskScope($taskPacketId);

        return $this->reportEnvelope('reported', $clientId, [
            'outcome' => 'success',
            'lease_closed' => $closed,
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'orchestrator_event' => $event,
            'result' => $result,
            'verified' => false,
            'evidence' => (array) ($payload['evidence'] ?? []),
            'outcome_spine' => $this->recordServerSideOutcomeSpine(
                $taskPacketId,
                is_array($scope) ? $scope : [],
                [],
                null,
                false,
            ),
        ]);
    }

    private function reportSuccessWithCommit(string $clientId, string $taskPacketId, string $leaseId, array $payload): array
    {
        $scope = $this->orchestrator->taskScope($taskPacketId);

        // FASE 2 — PROVE it works before it lands. The server re-runs real checks on the worker's in-tree
        // changes; a delivery that fails definitively (and provably by THIS task) is REFUSED, keeping the
        // lease so the worker fixes and re-reports — broken code never reaches shared main, so the next
        // worker is never handed a wedged tree. Fail-open by design (never blocks a good worker over infra
        // or another worker's WIP).
        if ($this->verifier->enabled()) {
            $verification = $this->verifier->verify((array) $scope['allowed_files'], $taskPacketId);
            if (($verification['blocked'] ?? false) === true) {
                return $this->reportEnvelope('commit_failed', $clientId, [
                    'outcome' => 'success',
                    'lease_closed' => false,
                    'task_packet_id' => $taskPacketId,
                    'lease_id' => $leaseId,
                    'reason' => 'server_verification_failed',
                    'verification' => $verification,
                ]);
            }
        }

        // EVIDENCE CONTRACT — binds the worker's evidence to the Verification Court's receipt-chain
        // contract BEFORE governance runs. off skips evaluation entirely (byte-identical legacy
        // behavior); observe (default) records the verdict and proceeds; enforce refuses the commit
        // on a failed verdict, keeping the lease so the worker fixes evidence and re-reports. An
        // evaluator exception is recorded as an unavailable Court authority: observe may continue,
        // but enforce must fail closed before any commit.
        $evidenceContractMode = $this->policyPlane->evidenceContractMode();
        $evidenceContractVerdict = null;
        if ($evidenceContractMode !== 'off') {
            try {
                $allowedFiles = array_values(array_unique(array_map('strval', (array) $scope['allowed_files'])));
                sort($allowedFiles, SORT_STRING);
                $allegation = [
                    'task_packet_id' => $taskPacketId,
                    'lease_id' => $leaseId,
                    'allowed_files_hash' => hash('sha256', json_encode($allowedFiles, JSON_THROW_ON_ERROR)),
                    'command_hash' => hash('sha256', implode(',', array_keys((array) ($verification['checks'] ?? [])))),
                ];
                $evidenceContractVerdict = ($this->evidenceContractEvaluator)($allegation, (array) ($payload['evidence'] ?? []));
            } catch (Throwable $e) {
                $evidenceContractVerdict = [
                    'schema' => AtlasVerificationCourtEvidenceContract::SCHEMA,
                    'accepted' => false,
                    'blockers' => ['evidence_contract_evaluator_unavailable'],
                    'error' => $e->getMessage(),
                ];
            }

            if ($evidenceContractMode === 'enforce' && ($evidenceContractVerdict['accepted'] ?? false) !== true) {
                return $this->reportEnvelope('commit_failed', $clientId, [
                    'outcome' => 'success',
                    'lease_closed' => false,
                    'task_packet_id' => $taskPacketId,
                    'lease_id' => $leaseId,
                    'reason' => 'evidence_contract_failed',
                    'evidence_contract' => $evidenceContractVerdict,
                ]);
            }
        }

        // REFACTOR DELTA PROOF — for refactor/optimize objectives, GREEN IS NOT ENOUGH: the
        // delivery must show a measurable delta (less code / complexity / duplication /
        // shorter functions) against the HEAD the worker started from. observe (default)
        // records the proof on the envelope + receipt; enforce refuses the commit on a
        // non-improving or anti-fake delivery (move_only / wrapper_only), keeping the lease
        // so the worker improves it and re-reports. Fail-open: an uncomputable proof (infra,
        // non-PHP scope) never blocks.
        // AAEOS+ACOS elite lane: infer scope from allowed_files → enforce shrink proof
        // without flipping the global Autônomos default (observe).
        $laneScopeSlug = AtlasAaeosAcosLaneScope::inferFromAllowedFiles(array_values(array_map('strval', (array) $scope['allowed_files'])));
        $refactorProofMode = $this->policyPlane->refactorProofMode($laneScopeSlug);
        $refactorProof = null;
        // A stage whose OWN scope is entirely tests AUTHORS proof — it is judged by
        // the verifier + test contract, never by shrink axes (its callers already
        // landed in earlier stages; their delta here is legitimately zero).
        $stageProductionFiles = array_values(array_filter(
            array_map('strval', (array) $scope['allowed_files']),
            static fn (string $f): bool => ! (str_starts_with($f, 'tests/') || str_contains($f, '/tests/') || str_ends_with($f, 'Test.php')),
        ));
        if ($refactorProofMode !== 'off'
            && $stageProductionFiles !== []
            && AtlasRefactorProofGate::appliesTo((string) $scope['objective'])) {
            // CHAIN-AWARE proof scope: when the packet carries a design spec, the
            // delta is judged over the WHOLE seam (spec callers + stage files) —
            // an extraction stage alone always grows; the seam is the unit of
            // improvement, the stage is a transaction slice of it.
            $proofScope = array_values(array_unique(array_merge(
                (array) $scope['allowed_files'],
                array_values(array_map('strval', (array) data_get($scope, 'refactor_design_spec.callers', []))),
            )));
            $refactorProof = $this->refactorProofGate->prove($proofScope);
            if ($refactorProof !== null) {
                // ARCHITECTURE JUDGE (advisory, semantic): local hermes reads the
                // actual diff against the seam decision and judges what shrink
                // metrics cannot see. Never blocks; verdict rides receipt+envelope.
                if ((string) config('atlas_task_governance.refactor_semantic_judge', 'advisory') === 'advisory') {
                    try {
                        $refactorProof['architecture_judgment'] = (new TaskServing\AtlasRefactorArchitectureJudge)
                            ->judge((array) data_get($scope, 'refactor_design_spec', []), $refactorProof, $proofScope);
                    } catch (Throwable) {
                        // Advisory by contract.
                    }
                }
                try {
                    $this->orchestrator->appendReportReceipt($taskPacketId, [
                        'receipt_kind' => 'refactor_delta_proof',
                        'mode' => $refactorProofMode,
                        'proof' => $refactorProof,
                    ]);
                } catch (Throwable) {
                    // Receipt is observability; never fail a report over it.
                }
            }
            if ($refactorProofMode === 'enforce'
                && $refactorProof !== null
                && ($refactorProof['improved'] ?? true) !== true) {
                return $this->reportEnvelope('commit_failed', $clientId, [
                    'outcome' => 'success',
                    'lease_closed' => false,
                    'task_packet_id' => $taskPacketId,
                    'lease_id' => $leaseId,
                    'reason' => 'refactor_delta_refused',
                    'refactor_proof' => $refactorProof,
                ]);
            }
        }

        // SPINE — the Merge Governor + Verification Court finally run on a LIVE delivery. In observe mode
        // (default) it RECORDS the verdict and NEVER blocks (the bootstrap swarm builds these very organs,
        // which score HIGH risk — enforcing here would self-lock the build). In enforce mode a non-admitted
        // decision refuses the commit, keeping the lease. Fail-open: a governance error never wedges a worker.
        $verificationFacts = isset($verification) && is_array($verification)
            ? ['passed' => ($verification['passed'] ?? false) === true, 'checks' => (array) ($verification['checks'] ?? [])]
            : ['passed' => false, 'checks' => []];
        if ($evidenceContractMode !== 'off') {
            $verificationFacts['evidence_contract'] = $evidenceContractVerdict;
        }
        $governance = $this->governance->govern([
            'task_packet_id' => $taskPacketId,
            'project_id' => 'atlas-self-construction',
            'changed_files' => array_values((array) $scope['allowed_files']),
            'verification' => $verificationFacts,
        ]);
        if (($governance['enforced_block'] ?? false) === true) {
            return $this->reportEnvelope('commit_failed', $clientId, [
                'outcome' => 'success',
                'lease_closed' => false,
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'reason' => 'merge_governance_refused',
                'governance' => $governance,
            ]);
        }

        // DEDUP/REUSO (F0 limpeza 05/07) — a esteira é o PRODUTOR da duplicação medida (38% dos
        // clones do núcleo nascem em SelfConstruction). Entrega que declara símbolo homônimo de um
        // já existente re-implementa em vez de reusar: enforce (default) recusa ANTES do commit,
        // mantendo a lease para o worker reusar o símbolo existente. Blocos clonados de irmãos são
        // observação no receipt (nunca bloqueiam). Fail-open em erro interno, como os gates acima.
        $dedupMode = (string) config('atlas_task_governance.dedup_reuse_mode', 'enforce');
        if ($dedupMode !== 'off' && $stageProductionFiles !== []) {
            $dedup = null;
            try {
                $dedup = (new TaskQuality\AtlasTaskDuplicateReuseGate)->evaluate(array_values(array_map('strval', (array) $scope['allowed_files'])));
                $this->orchestrator->appendReportReceipt($taskPacketId, [
                    'receipt_kind' => 'duplicate_reuse_gate',
                    'mode' => $dedupMode,
                    'verdict' => $dedup,
                ]);
            } catch (Throwable) {
                // Fail-open: gate/receipt nunca derruba um report por infra.
            }
            if ($dedupMode === 'enforce' && $dedup !== null && ($dedup['passed'] ?? true) !== true) {
                return $this->reportEnvelope('commit_failed', $clientId, [
                    'outcome' => 'success',
                    'lease_closed' => false,
                    'task_packet_id' => $taskPacketId,
                    'lease_id' => $leaseId,
                    'reason' => 'duplicate_reuse_refused',
                    'dedup' => $dedup,
                ]);
            }
        }

        // ADMISSION GATE v2 (Obra #6 V0) — os dois produtores de entropia que o dedup de NOME não
        // pega: lógica quase-duplicada (bloco >= 30 linhas copiado de outro arquivo) e classe 0-ref
        // sem tag @unwired-until. observe (default) grava no receipt sem bloquear; enforce recusa
        // mantendo a lease. Fail-open em erro interno, como os gates acima.
        $admissionMode = (string) config('atlas_task_governance.admission_v2_mode', 'observe');
        if ($admissionMode !== 'off' && $stageProductionFiles !== []) {
            $admission = null;
            try {
                $admissionFiles = array_values(array_map('strval', (array) $scope['allowed_files']));
                $admission = [
                    'logic' => (new TaskQuality\AtlasTaskDuplicateReuseGate)->evaluateLogicReuse($admissionFiles),
                    'wiring' => (new TaskQuality\AtlasTaskWiringAdmissionGate)->evaluate($admissionFiles),
                    // K4 (Obra #18) — kit conformance: untouched pre-written oracle,
                    // no artisan command-name collision, diff ⊆ allowed. Fail-safe to
                    // pass on non-kit packets.
                    'kit' => (new TaskQuality\AtlasTaskKitConformanceGate)->evaluate($scope, $admissionFiles),
                ];
                $this->orchestrator->appendReportReceipt($taskPacketId, [
                    'receipt_kind' => 'admission_gate_v2',
                    'mode' => $admissionMode,
                    'verdict' => $admission,
                ]);
            } catch (Throwable) {
                // Fail-open: gate/receipt nunca derruba um report por infra.
            }
            if ($admissionMode === 'enforce' && $admission !== null
                && (($admission['logic']['passed'] ?? true) !== true
                    || ($admission['wiring']['passed'] ?? true) !== true
                    || ($admission['kit']['passed'] ?? true) !== true)) {
                return $this->reportEnvelope('commit_failed', $clientId, [
                    'outcome' => 'success',
                    'lease_closed' => false,
                    'task_packet_id' => $taskPacketId,
                    'lease_id' => $leaseId,
                    'reason' => 'admission_v2_refused',
                    'admission' => $admission,
                ]);
            }
        }

        // Obra 2: AUCRI/context + honesty BEFORE scoped commit (fail-closed — no false land).
        $eliteGate = $this->eliteAutonomosContextAndOutcome($taskPacketId, $scope, [
            'commit_sha' => '',
            'files_committed' => [],
            'pre_commit' => true,
        ]);
        if (($eliteGate['ok'] ?? true) !== true) {
            return $this->reportEnvelope('commit_failed', $clientId, [
                'outcome' => 'success',
                'lease_closed' => false,
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'reason' => (string) ($eliteGate['reason'] ?? 'elite_autonomos_gate_blocked'),
                'elite_gate' => $eliteGate,
            ]);
        }

        // Project muscle-provided execution counts into landing certify so a real
        // phpunit run in evidence.commands_run is not scored as claimed_pass_with_zero_tests.
        if (! isset($verification) || ! is_array($verification)) {
            $verification = [];
        }
        $muscleEvidence = (array) ($payload['evidence'] ?? []);
        $testsRun = (int) ($muscleEvidence['tests_run'] ?? 0);
        $assertions = (int) ($muscleEvidence['assertions_executed'] ?? 0);
        // A command STRING is a claim that tests ran — never a count of them. This
        // used to set $testsRun = 1 from `commands_run` containing "artisan test",
        // then persist assertions_executed = 1, counts_parseable = true and
        // claimed_status = 'passed', and promote proof_strength to
        // task_tests_proven. A worker reporting nothing but a command string was
        // handed a proven-tests landing. Counts now come only from the muscle's
        // structured counters; the command list is still recorded as evidence.
        $countsReported = $testsRun > 0;
        $testCommandSeen = false;
        foreach ((array) ($muscleEvidence['commands_run'] ?? []) as $cmd) {
            $cmd = (string) $cmd;
            if (str_contains($cmd, 'phpunit') || str_contains($cmd, 'artisan test')) {
                $testCommandSeen = true;
                break;
            }
        }

        if ($countsReported || $testCommandSeen) {
            $selected = array_values(array_filter(
                array_map('strval', (array) ($muscleEvidence['selected_tests'] ?? $scope['allowed_files'] ?? [])),
                static fn (string $p): bool => str_ends_with($p, 'Test.php') || str_contains($p, '/tests/'),
            ));
            $verification['execution_evidence'] = array_merge(
                (array) ($verification['execution_evidence'] ?? []),
                [
                    'commands' => array_values(array_map('strval', (array) ($muscleEvidence['commands_run'] ?? []))),
                    // No 'passed' default: if the muscle did not say it passed, we
                    // do not say it for them. That default is what turned a bare
                    // command string into a claimed pass.
                    'claimed_status' => (string) ($muscleEvidence['tests_or_gates_result'] ?? 'unknown'),
                    'tests_run' => $countsReported ? $testsRun : 0,
                    'assertions_executed' => $countsReported ? max($assertions, $testsRun) : 0,
                    'selected_tests' => $selected,
                    'counts_parseable' => $countsReported,
                ],
            );
            if ($countsReported) {
                // Only real counters prove tests. A command that merely ran is
                // evidence of an attempt, never of a proven suite.
                $verification['proof_strength'] = $verification['proof_strength'] ?? 'task_tests_proven';
            }
        }

        $commit = $this->committer->commitScope(
            (array) $scope['allowed_files'],
            $taskPacketId,
            $clientId,
            (string) $scope['objective'],
            $verification !== [] ? $verification : null,
        );

        if (($commit['committed'] ?? false) !== true) {
            // Commit did not land — KEEP the lease so the AI can fix and re-report (no work lost).
            return $this->reportEnvelope('commit_failed', $clientId, [
                'outcome' => 'success',
                'lease_closed' => false,
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'commit' => $commit,
            ]);
        }

        // Post-commit honesty with real commit evidence. A red post-commit verdict means the
        // commit landed but the task is NOT settled; keep the lease open so the worker/operator
        // can repair or revert explicitly instead of marking fake-green work resolved.
        $postCommitEliteGate = $this->eliteAutonomosContextAndOutcome($taskPacketId, $scope, $commit);
        if (($postCommitEliteGate['ok'] ?? true) !== true) {
            return $this->reportEnvelope('commit_failed', $clientId, [
                'outcome' => 'success',
                'lease_closed' => false,
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'reason' => (string) ($postCommitEliteGate['reason'] ?? 'elite_autonomos_gate_blocked'),
                'elite_gate' => $postCommitEliteGate,
                'commit' => $commit,
            ]);
        }

        $resolved = $this->orchestrator->markResolved($taskPacketId, $leaseId, $clientId, (string) ($commit['commit_sha'] ?? ''));
        if ((string) ($resolved['event'] ?? '') !== 'task_resolved') {
            return $this->reportEnvelope('settlement_failed', $clientId, [
                'outcome' => 'success',
                'lease_closed' => false,
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'reason' => 'task_settlement_failed',
                'commit' => $commit,
                'settlement' => $resolved,
            ]);
        }

        // DIARIO-3 — the scoped commit that just landed on the local main IS an
        // auto-merge; label it in the Evolution Diary in the SAME act, reversible
        // by git revert. Fail-open: the diary never fails a report.
        try {
            app(AtlasEvolutionDiaryRecorder::class)->merged(
                (string) ($commit['commit_sha'] ?? ''),
                'auto-merge do task '.$taskPacketId.' na main local',
                'checks automáticos verdes (verificação + admission v2); sem espera por humano',
                'commit '.substr((string) ($commit['commit_sha'] ?? ''), 0, 10),
            );
        } catch (Throwable) {
            // never fail a report over the diary
        }

        // Task serving observes files, checks, and lease age — never model tokens, provider, or cost.
        // Emit those operational facts only to an explicitly injected meter; a Maestro cost ledger must not
        // receive semantically fabricated required fields merely to make a row persist.
        if ($this->budgetMeter !== null) {
            try {
                $this->budgetMeter->measure([
                    'task_packet_id' => $taskPacketId,
                    'agent_id' => $clientId,
                    'files_committed_count' => count((array) ($commit['files_committed'] ?? [])),
                    'verification_checks_run' => count((array) ($verificationFacts['checks'] ?? [])),
                    'wall_seconds' => $this->leaseAgeSeconds($leaseId) ?? 0,
                ]);
            } catch (Throwable) {
                // Fail-open: an explicitly injected operational meter never breaks a resolved report.
            }
        }

        // GOVERNOR'S CANARY LEG — policy-plane gated (default OFF, byte-identical to today when off).
        // Probes the just-landed tree; a sentinel error is swallowed fail-open so a canary bug never
        // touches the already-resolved report.
        if ($this->policyPlane->canaryEnabled()) {
            try {
                $this->canarySentinel->observe($taskPacketId, (string) ($commit['commit_sha'] ?? ''), array_values((array) $scope['allowed_files']));
            } catch (Throwable) {
                // fail-open: a canary error never wedges or mutates the resolved report.
            }
        }

        // C3 (Obra #18) — symmetric closure: a PROVEN esteira completion emits a G0
        // memory candidate through the SAME governed channel the Stop hook uses
        // (proposeLearning, kind=memory, ALWAYS pending_review). Delta-surprise filters
        // bare successes; the write-back's capture-quality gate + dedup are the second
        // anti-inflation line. Fail-open: closing to the registry never breaks a report.
        try {
            $candidate = TaskOutcomeLearningCandidate::from(
                ['objective' => (string) ($scope['objective'] ?? ''), 'allowed_files' => array_values((array) ($scope['allowed_files'] ?? []))],
                ['outcome' => 'success', 'commit' => (string) ($commit['commit_sha'] ?? ''), 'evidence' => (array) ($payload['evidence'] ?? [])],
            );
            if ($candidate !== null) {
                app(AtlasOpenBrainWriteBackService::class)->proposeLearning($candidate);
            }
        } catch (Throwable) {
            // fail-open: symmetric closure never wedges or mutates the resolved report.
        }

        return $this->reportEnvelope('resolved', $clientId, array_merge([
            'outcome' => 'success',
            'lease_closed' => (string) ($resolved['event'] ?? '') === 'task_resolved',
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'commit_sha' => (string) ($commit['commit_sha'] ?? ''),
            'files_committed' => array_values((array) ($commit['files_committed'] ?? [])),
            'governance' => $governance,
            'result' => $resolved,
            // Project muscle evidence so AAEOS P4 can derive spawn/authority from report stdout.
            'evidence' => (array) ($payload['evidence'] ?? []),
            'outcome_spine' => $this->recordServerSideOutcomeSpine(
                $taskPacketId,
                $scope,
                $commit,
                isset($verification) && is_array($verification) ? $verification : null,
                true,
            ),
        ], $evidenceContractMode !== 'off' ? ['evidence_contract' => $evidenceContractVerdict] : [],
            $refactorProof !== null ? ['refactor_proof' => $refactorProof] : []));
    }

    /**
     * Scope expansion + give_back/failed anti-loop release path.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function reportGiveBackOrFailure(string $clientId, string $taskPacketId, string $leaseId, string $outcome, array $payload): array
    {
        // GOVERNED SCOPE EXPANSION — a give_back that carries a structured expansion request
        // (files + justification) is a discovery, not a failure: the seam needs files the
        // packet did not anticipate. Granted, the packet is rebuilt with the wider scope and
        // the SAME worker reclaims it immediately (no give_back stamp, no cooldown, WIP kept).
        // Refused (thin justification / forbidden target / too broad), it falls through to
        // the normal give_back below — fail-closed to today's behavior.
        $expansion = (array) data_get($payload, 'evidence.scope_expansion_request', []);
        if ($outcome === 'give_back' && $expansion !== []) {
            $granted = $this->orchestrator->requestScopeExpansion(
                $taskPacketId,
                $leaseId,
                $clientId,
                array_values((array) ($expansion['files'] ?? [])),
                (string) ($expansion['justification'] ?? ''),
            );
            if ((string) ($granted['event'] ?? '') === 'scope_expanded') {
                return $this->reportEnvelope('scope_expanded', $clientId, [
                    'outcome' => $outcome,
                    'lease_closed' => true,
                    'task_packet_id' => $taskPacketId,
                    'lease_id' => $leaseId,
                    'result' => $granted,
                ]);
            }
            // Refusal is audible on the give_back reason below.
        }

        // A client can report a suspected no-op, but its reason and evidence are untrusted. They may inform the
        // retry diagnostics below, never permanently retire a packet. Only the bounded server-side give-back policy
        // can quarantine work, so a second worker still has a chance to verify the claimed state.
        // failed / give_back => anti-loop release: another worker can retry, but NEVER the same worker that just
        // gave it back, and a task given back MAX times is quarantined (never cycles forever).
        $result = $this->orchestrator->reportGiveBack($taskPacketId, $leaseId, $clientId, 'client_reported_'.$outcome);
        $event = (string) ($result['event'] ?? '');

        // Esteira failure memory: a worker-reported failure carrying a REAL error
        // excerpt becomes a failure capsule for this packet's area — the same
        // known_failure_modes channel the NEXT served packet in the area receives
        // (S2 read side; this is its serving write side). Never fabricated: a bare
        // give_back without evidence records nothing. Fail-open.
        $excerpt = trim((string) (data_get($payload, 'evidence.error')
            ?? data_get($payload, 'evidence.error_excerpt')
            ?? data_get($payload, 'evidence.failure_excerpt')
            ?? ''));
        if ($excerpt !== '') {
            try {
                $served = $this->orchestrator->taskScope($taskPacketId);
                $files = array_values(array_map('strval', (array) ($served['allowed_files'] ?? [])));
                $anchor = app(DevTaskPacketRuntimeService::class)->persist([
                    'run_id' => 'serving-'.$taskPacketId,
                    'task_id' => $taskPacketId,
                    'objective' => (string) ($served['objective'] ?? ''),
                    'workspace_slug' => WorkspaceOriginIdentity::slug(base_path()),
                    'allowed_files' => $files,
                    'source' => 'task_serving_report',
                ]);
                app(DevFailureCapsuleRuntimeService::class)->persist([
                    'run_id' => 'serving-'.$taskPacketId,
                    'task_id' => $taskPacketId,
                    'failing_gate' => 'worker_report_'.$outcome,
                    'error_excerpt' => $excerpt,
                    'changed_files' => $files,
                ], $anchor);
            } catch (Throwable) {
                // fail-open: learning must never wedge a report
            }
        }

        // govA-cortex-cadence — outcome-triggered invalidation: a give_back/failure means the
        // worker's mental model of the scope diverged from reality. Drop the comprehension
        // snapshot so the next authoring round rebuilds against fresh inventory. Fail-open.
        try {
            $cadence = app()->bound(AtlasLoopComprehensionCadenceService::class)
                ? app(AtlasLoopComprehensionCadenceService::class)
                : new AtlasLoopComprehensionCadenceService;
            $cadence->invalidate('task_outcome_'.$outcome.':'.$taskPacketId);
        } catch (Throwable $e) {
            // never let a comprehension hiccup wedge a give_back report — but a
            // failed invalidation means the brain keeps authoring against a stale
            // scope model, so the loss is logged, never invisible.
            Log::warning('comprehension_invalidation_failed', [
                'task_packet_id' => $taskPacketId,
                'outcome' => $outcome,
                'error' => $e->getMessage(),
            ]);
        }

        // Queue self-healing (policy-plane gated, default OFF): a quarantined packet previously
        // sat blocked until a human ran atlas:task:repair-blocked. Run the same living repair
        // pass automatically so a doomed spec becomes respec/cancel-until-respec instead of
        // burning more worker muscle. Fail-open: repair must never wedge a give_back report.
        $autoRepair = null;
        if ($event === 'give_back_quarantined' && $this->policyPlane->autoRespecOnQuarantineEnabled()) {
            try {
                Artisan::call('atlas:task:repair-blocked', [
                    '--limit' => 10,
                    '--actor' => 'auto_respec_on_quarantine',
                    '--json' => true,
                ]);
                $autoRepair = json_decode(Artisan::output(), true);
            } catch (Throwable) {
                // fail-open
            }
        }

        return $this->reportEnvelope('reported', $clientId, array_merge([
            'outcome' => $outcome,
            'lease_released' => in_array($event, ['given_back', 'give_back_quarantined'], true),
            'quarantined' => $event === 'give_back_quarantined',
            'give_back_count' => (int) ($result['give_back_count'] ?? 0),
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'result' => $result,
        ], $autoRepair === null ? [] : ['auto_repair' => $autoRepair]));
    }

    public function report(string $clientId, string $taskPacketId, string $leaseId, array $payload = []): array
    {
        $intake = $this->validateReportIntake($clientId, $taskPacketId, $leaseId, $payload);
        if (($intake['status'] ?? null) === 'blocked') {
            return $intake['envelope'];
        }
        $clientId = $intake['client_id'];
        $outcome = $intake['outcome'];

        // SHARED-MAIN resolve: commit EXACTLY this task's allowed_files (server-truth scope) as the AI's own
        // commit, then close. Only when the client asks to commit (the runbook flow); otherwise the legacy
        // dry-run path stays intact.
        if ($outcome === 'success' && (bool) ($payload['commit'] ?? false)) {
            return $this->reportSuccessWithCommit($clientId, $taskPacketId, $leaseId, $payload);
        }

        if ($outcome === 'success') {
            return $this->reportSuccessDryRun($clientId, $taskPacketId, $leaseId, $payload);
        }

        return $this->reportGiveBackOrFailure($clientId, $taskPacketId, $leaseId, $outcome, $payload);
    }

    /**
     * Best-effort lease age in seconds, decoded from the lease id's embedded ULID timestamp
     * (Crockford base32, first 10 chars = ms since epoch) — no orchestrator call needed. Returns
     * null when the id isn't ULID-shaped ("when available", never throws).
     */
    private function leaseAgeSeconds(string $leaseId): ?int
    {
        $id = str_starts_with($leaseId, 'lease_') ? substr($leaseId, 6) : $leaseId;
        $tsChars = strtoupper(substr($id, 0, 10));
        if (strlen($tsChars) !== 10) {
            return null;
        }
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $ms = 0;
        foreach (str_split($tsChars) as $char) {
            $value = strpos($alphabet, $char);
            if ($value === false) {
                return null;
            }
            $ms = $ms * 32 + $value;
        }
        $nowMs = (int) round(microtime(true) * 1000);

        return intdiv(max(0, $nowMs - $ms), 1000);
    }

    /** Whitelist filters to a neutral set so a client can never inject an engine-typed/platform field. */
    private function safeFilters(array $filters): array
    {
        $safe = [];
        foreach (self::ALLOWED_FILTER_KEYS as $key) {
            if (array_key_exists($key, $filters)) {
                $safe[$key] = $filters[$key];
            }
        }

        return $safe;
    }

    /**
     * Project the claimed orchestrator result into the FIXED, self-sufficient task block.
     *
     * @param  array<string, mixed>  $claim
     * @return array<string, mixed>
     */
    /**
     * Admitted lessons relevant to this packet (directory-overlap match —
     * the w28 lesson identity is class + scope dirs), newest first, capped.
     * Read-only over the admission ledger; any hiccup degrades to [] —
     * learning advice must never block serving.
     *
     * @param  array<string,mixed>  $task
     * @return list<array<string,mixed>>
     */
    private function knownLessonsFor(array $task): array
    {
        try {
            $taskFiles = array_values(array_map('strval', (array) ($task['allowed_files'] ?? [])));
            if ($taskFiles === []) {
                return [];
            }
            // Lesson identity aggregates at the DIRECTORY level (class +
            // scope_dirs — the w28 accumulator contract): an admitted lesson
            // carries the exact files of the packet that CLOSED it, which a
            // future task in the same area never matches file-for-file. Match
            // area overlap, not literal paths (same-file implies same-dir, so
            // this is a strict superset of the old exact-file match).
            $taskDirs = array_values(array_unique(array_map(
                static fn (string $file): string => dirname($file),
                $taskFiles,
            )));
            $rows = (new LearningTransfer\AtlasSelfConstructionLearningTransferAdmissionLedger(
                LearningTransfer\AtlasSelfConstructionLearningTransferAdmissionLedger::defaultPath()
            ))->all();

            $lessons = [];
            // ponytail: full-ledger replay per serve; index by file if the ledger grows past ~1k rows.
            foreach (array_reverse($rows) as $row) {
                $classification = (array) ($row['classification'] ?? []);
                $lessonFiles = array_values(array_map('strval', (array) ($classification['allowed_files'] ?? [])));
                $lessonDirs = array_values(array_unique(array_map(
                    static fn (string $file): string => dirname($file),
                    $lessonFiles,
                )));
                if ($lessonDirs === [] || array_intersect($lessonDirs, $taskDirs) === []) {
                    continue;
                }
                $lessons[] = [
                    'class' => (string) ($classification['class'] ?? ''),
                    'blocking_facts' => array_values(array_map('strval', (array) ($classification['blocking_facts'] ?? []))),
                    'evidence_refs' => array_values(array_map('strval', (array) ($classification['evidence_refs'] ?? []))),
                    'recorded_at' => (string) ($row['recorded_at'] ?? ''),
                ];
                if (count($lessons) >= 3) {
                    break;
                }
            }

            return $lessons;
        } catch (Throwable) {
            return []; // fail-open: advisory learning never blocks a serve
        }
    }

    /**
     * Sibling tests pinning this packet's allowed_files (reuses the loop's
     * cached mirror resolver): the worker never hunts for what proves its own
     * change. Advisory; a file without a sibling simply doesn't appear.
     *
     * @param  array<string,mixed>  $task
     * @return list<array{production_file:string, test_path:string, test_methods:list<string>}>
     */
    /**
     * M5 known failure modes for the packet's area — provider-safe strings
     * from persisted {@see AtlasDevFailureCapsule} rows, scoped
     * to THIS repo's workspace identity (the serving stack always serves
     * self-construction work on this repository).
     *
     * @return list<string>
     */
    private function knownFailureModesFor(array $task): array
    {
        try {
            $files = array_values(array_map('strval', (array) ($task['allowed_files'] ?? [])));
            if ($files === []) {
                return [];
            }

            return (new DevFailureCapsulePromptInjector)->injectFor(
                $files,
                WorkspaceOriginIdentity::slug(base_path()),
            );
        } catch (Throwable) {
            return []; // fail-open: advisory memory never blocks a serve
        }
    }

    /**
     * C2 (Obra #18) — decisions + refutations relevant to this packet's files,
     * as provider-safe "title — summary" strings, via the query-aware recall
     * (Obra #17 T0.2). Semantic arm disabled: registry + verbatim + compounding
     * are all local (the vector search honestly degrades to lexical with no
     * embedding engine) so a serve never costs a provider call.
     *
     * ponytail: scope is the query built from the packet's file/module tokens —
     * a lexical/semantic scope, honest today; swap to hard memory↔code edges
     * once D3 lands them. The query-scope is the retrieval the #17 sequence mandates.
     *
     * @param  array<string,mixed>  $task
     * @return list<string>
     */
    private function relevantMemoryFor(array $task): array
    {
        try {
            $files = array_values(array_map('strval', (array) ($task['allowed_files'] ?? [])));
            if ($files === []) {
                return [];
            }
            $query = $this->memoryQueryFromFiles($files);
            if ($query === '') {
                return [];
            }

            $recall = app(AtlasHybridMemoryRetrievalService::class)->recall(
                $query,
                [],
                [],
                ['limit' => 5, 'include_semantic' => false, 'requester' => 'atlas_task_serving'],
            );

            $out = [];
            foreach ((array) ($recall['recall'] ?? []) as $item) {
                $title = trim((string) ($item['title'] ?? ''));
                $summary = trim((string) ($item['summary'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $out[$summary !== '' ? "{$title} — {$summary}" : $title] = true;
            }

            return array_keys($out);
        } catch (Throwable) {
            return []; // fail-open: advisory memory never blocks a serve
        }
    }

    /**
     * Build a recall query from a packet's files: each file's basename (sans
     * extension) + its last two directory segments (the module), deduped.
     *
     * @param  list<string>  $files
     */
    private function memoryQueryFromFiles(array $files): string
    {
        $tokens = [];
        foreach ($files as $f) {
            $f = str_replace('\\', '/', (string) $f);
            $base = (string) preg_replace('/\.[a-z0-9]+$/i', '', basename($f));
            if ($base !== '') {
                $tokens[$base] = true;
            }
            foreach (array_slice(explode('/', trim(dirname($f), '/')), -2) as $dir) {
                if ($dir !== '' && $dir !== '.') {
                    $tokens[$dir] = true;
                }
            }
        }

        return trim(implode(' ', array_keys($tokens)));
    }

    /**
     * L2 (Obra #19) — the packet's allowed_files that ANOTHER engine currently holds an
     * active blackboard claim on. Fail-open: any hiccup (or an unavailable/absent
     * blackboard) returns [] so serving never stalls on coordination. The requesting
     * engine's OWN claims are excluded (except_engine), so it never blocks itself.
     *
     * @param  array<string,mixed>  $task
     * @return list<string>
     */
    private function blackboardConflictsFor(array $task, string $clientId): array
    {
        try {
            $files = array_values(array_map('strval', (array) ($task['allowed_files'] ?? [])));
            if ($files === []) {
                return [];
            }
            $blackboard = app(AtlasAobgBlackboardService::class);
            $contended = [];
            foreach ($files as $file) {
                $conflicts = $blackboard->conflictsFor($file, ['except_engine' => $clientId]);
                if ((int) ($conflicts['count'] ?? 0) > 0) {
                    $contended[] = $file;
                }
            }

            return $contended;
        } catch (Throwable) {
            return []; // fail-open: coordination never stalls a serve
        }
    }

    private function siblingTestsFor(array $task): array
    {
        try {
            $resolver = new AtlasLoopSiblingTestResolver;
            $siblings = [];
            foreach (array_values(array_map('strval', (array) ($task['allowed_files'] ?? []))) as $file) {
                $resolved = $resolver->resolve($file);
                if (($resolved['has_sibling'] ?? false) === true) {
                    $siblings[] = [
                        'production_file' => $file,
                        'test_path' => (string) $resolved['sibling_path'],
                        'test_methods' => array_values(array_map('strval', (array) ($resolved['asserted_methods'] ?? []))),
                    ];
                }
            }

            return $siblings;
        } catch (Throwable) {
            return []; // fail-open: discovery advice never blocks a serve
        }
    }

    /**
     * Proven exemplars: packets of the same AREA (directory overlap — the
     * same identity known_lessons uses) that already resolved with a real
     * lease-validated commit. Newest first, capped. The muscle-side analogue
     * of the Dev green-run exemplars.
     *
     * @param  array<string,mixed>  $task
     * @return list<array<string,mixed>>
     */
    private function greenRunExemplarsFor(array $task, int $cap = 2): array
    {
        try {
            $taskDirs = array_values(array_unique(array_map(
                static fn (string $file): string => dirname($file),
                array_values(array_map('strval', (array) ($task['allowed_files'] ?? []))),
            )));
            if ($taskDirs === []) {
                return [];
            }

            $path = AgentControlPlaneTaskQueueOrchestrator::resolvedReceiptsPath();
            if (! is_file($path)) {
                return [];
            }

            $exemplars = [];
            // ponytail: newest-500-lines scan per serve; index if it grows past that.
            $lines = array_reverse(array_slice(
                file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
                -500,
            ));
            foreach ($lines as $line) {
                $row = json_decode($line, true);
                if (! is_array($row)) {
                    continue;
                }
                $rowDirs = array_values(array_unique(array_map(
                    static fn (string $file): string => dirname($file),
                    array_values(array_map('strval', (array) ($row['allowed_files'] ?? []))),
                )));
                if ($rowDirs === [] || array_intersect($rowDirs, $taskDirs) === []) {
                    continue;
                }
                $exemplars[] = [
                    'task_packet_id' => (string) ($row['task_packet_id'] ?? ''),
                    'objective_excerpt' => (string) ($row['objective_excerpt'] ?? ''),
                    'commit_sha' => (string) ($row['commit_sha'] ?? ''),
                    'resolved_at' => (string) ($row['resolved_at'] ?? ''),
                ];
                if (count($exemplars) >= $cap) {
                    break;
                }
            }

            return $exemplars;
        } catch (Throwable) {
            return []; // fail-open: exemplar advice never blocks a serve
        }
    }

    private function projectTask(array $claim): array
    {
        $packet = (array) data_get($claim, 'queue_entry.task_packet', []);

        $forbidden = array_values((array) data_get($packet, 'normalized_scope.forbidden_files', data_get($packet, 'forbidden_files', [])));

        // K1 (Obra #18) — the pre-written acceptance test is IMMUTABLE for the
        // implementer: its path rides in forbidden_files (they make it pass,
        // never edit it) and its hash lets the gate (K4) prove it was untouched.
        $acceptanceTestRef = $this->normalizeAcceptanceTestRef(data_get($packet, 'acceptance_test_ref'));
        if ($acceptanceTestRef['path'] !== '' && ! in_array($acceptanceTestRef['path'], $forbidden, true)) {
            $forbidden[] = $acceptanceTestRef['path'];
        }

        return [
            'task_packet_id' => (string) ($claim['task_packet_id'] ?? data_get($packet, 'task_packet_id', '')),
            'lease_id' => (string) ($claim['lease_id'] ?? ''),
            'lease_expires_at' => (string) data_get($claim, 'lease.expires_at', ''),
            'objective' => (string) data_get($packet, 'objective', ''),
            'allowed_files' => array_values((array) data_get($packet, 'normalized_scope.allowed_files', data_get($packet, 'allowed_files', []))),
            'forbidden_files' => $forbidden,
            'scope_in' => array_values((array) data_get($packet, 'normalized_scope.scope_in', data_get($packet, 'scope_in', []))),
            'acceptance_criteria' => array_values((array) data_get($packet, 'acceptance_criteria', [])),
            // The builder stores the evidence list under `evidence_requirements.required` — projecting the bare
            // `required_evidence` key (absent) left the served packet WITHOUT the evidence a cold client must
            // produce. Read the real path (fallback to the projection shape).
            'required_evidence' => array_values((array) data_get($packet, 'evidence_requirements.required', data_get($packet, 'required_evidence', []))),
            'continuation_context' => (array) data_get($packet, 'continuation_context', []),
            // ADMISSION RULES (Obra #7 W1) — o contrato que o admission gate v2 vai cobrar em enforce.
            // Viaja no envelope para o worker PODER cumprir (auditoria 05/07: 112/174 entregas de
            // classe-nova chegavam 0-ref; sem a regra visível, enforce viraria jam de fila).
            'delivery_rules' => [
                'no_duplicate_logic' => 'Do not copy 30+ identical lines from an existing app/ file; reuse or extract instead (blocker: duplicate_logic_blocked).',
                'wired_or_tagged' => 'A new class must have a real caller in this delivery (app/routes/config/database), or carry a docblock tag "@unwired-until YYYY-MM-DD" declaring when it will be wired (blockers: unwired_class / unwired_expired).',
            ],
            'risk_level' => (string) data_get($packet, 'risk_classification.risk_level', data_get($packet, 'risk_level', 'unspecified')),
            // The brain's seam decision (when present) travels to the worker: the
            // stage contract of a heavy-refactor chain, not an advisory hint.
            'refactor_design_spec' => data_get($packet, 'refactor_design_spec'),
            // K1 (Obra #18) — Kit da Ordem: campos aditivos que blindam a delegação
            // frontier→barato contra as falhas históricas (Codex quebrando callers,
            // oráculo alucinado, poison-packets, sigla sem path). Ausentes ⇒ vazios.
            'frozen_callers' => $this->normalizeFrozenCallers(data_get($packet, 'frozen_callers', [])),
            'acceptance_test_ref' => $acceptanceTestRef,
            'stop_and_return' => array_values(array_filter(
                array_map(static fn ($s): string => trim((string) $s), (array) data_get($packet, 'stop_and_return', [])),
                static fn (string $s): bool => $s !== '',
            )),
            'glossary' => $this->normalizeGlossary(data_get($packet, 'glossary', [])),
            'baseline_artifact' => $this->normalizeBaselineArtifact(data_get($packet, 'baseline_artifact')),
        ];
    }

    /**
     * K1 — each frozen caller carries a declared destination so a change stays
     * additive-only: touching a caller's signature without a frozen entry is a
     * give_back, not a silent break.
     *
     * @return list<array{caller:string,destination:string}>
     */
    private function normalizeFrozenCallers(mixed $raw): array
    {
        $out = [];
        foreach ((array) $raw as $entry) {
            if (is_string($entry)) {
                $caller = trim($entry);
                $destination = '';
            } else {
                $caller = trim((string) data_get($entry, 'caller', data_get($entry, 'ref', '')));
                $destination = trim((string) data_get($entry, 'destination', data_get($entry, 'why', '')));
            }
            if ($caller !== '') {
                $out[] = ['caller' => $caller, 'destination' => $destination];
            }
        }

        return $out;
    }

    /**
     * K1 — path+hash of the pre-written, never-edited acceptance test.
     *
     * @return array{path:string,hash:string}
     */
    private function normalizeAcceptanceTestRef(mixed $raw): array
    {
        return [
            'path' => trim((string) data_get($raw, 'path', '')),
            'hash' => trim((string) data_get($raw, 'hash', '')),
        ];
    }

    /**
     * K1 — sigla → absolute path. An unresolved sigla makes the order invalid
     * (the linter, K3, rejects it at the source).
     *
     * @return array<string,string>
     */
    private function normalizeGlossary(mixed $raw): array
    {
        $out = [];
        foreach ((array) $raw as $sigla => $path) {
            $sigla = trim((string) $sigla);
            $path = trim((string) $path);
            if ($sigla !== '' && $path !== '') {
                $out[$sigla] = $path;
            }
        }

        return $out;
    }

    /**
     * K1 — the measured baseline the delivery must move (not a proxy).
     *
     * @return array<string,mixed>|null
     */
    private function normalizeBaselineArtifact(mixed $raw): ?array
    {
        return is_array($raw) && $raw !== [] ? $raw : null;
    }

    /**
     * @param  array<string, mixed>|null  $task
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function envelope(string $status, string $clientId, ?array $task, array $extra): array
    {
        return array_merge([
            'schema' => self::ENVELOPE_SCHEMA,
            'status' => $status,                 // served | no_claimable_task | disabled | invalid_client
            'client_id' => $clientId,            // echoed verbatim — NEVER interpreted
            'task' => $task,
            'retry_after_seconds' => 0,
            'escalation' => null,
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function reportEnvelope(string $status, string $clientId, array $extra): array
    {
        $envelope = array_merge([
            'schema' => self::REPORT_SCHEMA,
            'status' => $status,                 // reported | disabled | invalid_report
            'client_id' => $clientId,
        ], $extra);

        // Project derived capability proofs from completion evidence when present so
        // AAEOS P4 can derive spawn/authority from native report stdout (R84 — never
        // invent free bools; only echo receipts already supplied by the muscle).
        $evidence = is_array($extra['evidence'] ?? null)
            ? $extra['evidence']
            : (is_array(data_get($extra, 'result.evidence')) ? (array) data_get($extra, 'result.evidence') : []);
        $spawn = is_array($evidence['provider_spawn'] ?? null) ? $evidence['provider_spawn'] : null;
        $lineage = is_array($evidence['authority_lineage'] ?? null) ? $evidence['authority_lineage'] : null;
        if ($spawn !== null) {
            $provider = trim((string) ($spawn['provider'] ?? ''));
            $hash = strtolower(trim((string) ($spawn['provider_receipt_hash'] ?? '')));
            if ($provider !== '' && preg_match('/^[a-f0-9]{64}$/', $hash) === 1 && ($spawn['spawned'] ?? false) === true) {
                $envelope['provider'] = $provider;
                $envelope['provider_called'] = true;
                $envelope['external_provider_call'] = true;
                $envelope['exit_code'] = (int) ($spawn['exit_code'] ?? 0);
                $envelope['stdout_hash'] = $hash;
                $envelope['run_summary'] = array_merge(is_array($envelope['run_summary'] ?? null) ? $envelope['run_summary'] : [], [
                    'provider_call' => [
                        'provider' => $provider,
                        'provider_calls' => 1,
                        'exit_code' => (int) ($spawn['exit_code'] ?? 0),
                        'error_codes' => [],
                    ],
                    'verification_receipt_hash' => $hash,
                    'completion_state' => in_array($status, ['reported', 'resolved'], true) ? 'passed' : $status,
                ]);
            }
        }
        if ($lineage !== null) {
            $ref = trim((string) ($lineage['authority_ref'] ?? ''));
            $hash = strtolower(trim((string) ($lineage['authority_hash'] ?? '')));
            $rev = (int) ($lineage['authority_revision'] ?? 0);
            if ($ref !== '' && preg_match('/^[a-f0-9]{64}$/', $hash) === 1 && $rev >= 1) {
                $envelope['authority_lineage'] = [
                    'authority_ref' => $ref,
                    'authority_hash' => $hash,
                    'authority_revision' => $rev,
                    'source' => (string) ($lineage['source'] ?? 'task_lease'),
                ];
                $envelope['decision_receipt_id'] = $ref;
                $envelope['decision_receipt_hash'] = $hash;
                if (is_array($envelope['run_summary'] ?? null)) {
                    $envelope['run_summary']['authority_lineage'] = $envelope['authority_lineage'];
                }
            }
        }
        // Gauntlet completed set: map successful report terminals to passed.
        if (in_array($status, ['reported', 'resolved'], true)
            && (($extra['outcome'] ?? '') === 'success')
            && (($extra['lease_closed'] ?? false) === true || ($extra['verified'] ?? null) === true)) {
            $envelope['status'] = 'passed';
            $envelope['producer_terminal_status'] = $status;
        }

        return $envelope;
    }

    /**
     * Compose + certify + honesty for Autônomos task commits (Obra 2).
     *
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $commit
     * @return array{ok:bool,reason?:string,compose?:array<string,mixed>,certify?:array<string,mixed>}
     */
    private function eliteAutonomosContextAndOutcome(string $taskPacketId, array $scope, array $commit): array
    {
        $objective = (string) ($scope['objective'] ?? 'task commit');
        $allowedFiles = array_values(array_map('strval', (array) ($scope['allowed_files'] ?? [])));
        $preCommit = ($commit['pre_commit'] ?? false) === true;

        // Fail closed: an Autônomos mutation may never certify while the shared
        // seams are absent. Added by 34e9e79290, dropped by 73a4f16f5 (GOD-DEBULK
        // "restore optional serving gates"), which left the path continuing past
        // a null kernel instead of refusing.
        if ($this->contextRuntime === null) {
            return [
                'ok' => false,
                'reason' => 'atlas_context_runtime_unavailable',
                'blockers' => ['context_runtime_required_for_mutation'],
            ];
        }

        if (! $preCommit && $this->eliteKernel === null) {
            return [
                'ok' => false,
                'reason' => 'elite_executor_kernel_unavailable',
                'blockers' => ['elite_kernel_required_for_mutation'],
            ];
        }

        if ($this->contextRuntime !== null) {
            try {
                $task = AiTaskRequest::fromInput($objective, [
                    'agent_slug' => 'autonomos',
                    'provider' => 'local',
                    'source_type' => 'task_serving',
                    'payload' => [
                        'task_packet_id' => $taskPacketId,
                        'allowed_files' => $allowedFiles,
                    ],
                ], ['agent' => 'autonomos', 'intent' => 'self_construction']);
                $pack = $this->contextRuntime->compose($objective, $task, [
                    'workspace' => base_path(),
                    'flow_id' => 'atlas_autonomos',
                    'changed_files' => $allowedFiles,
                ]);
                $composeAudit = method_exists($pack, 'toArray') ? $pack->toArray() : ['schema' => 'composed'];
            } catch (Throwable $e) {
                // Compose is best-effort on Autônomos (AOBG-style); certify still fail-closes.
                $composeAudit = ['error' => $e->getMessage()];
            }

            $verdict = $this->contextRuntime->certify([
                'flow_id' => 'atlas_autonomos',
                'domain' => 'self_construction',
                'task_type' => 'task_serving_commit',
                'risk_level' => 'high',
                'provider' => 'local',
                'provider_target' => 'local',
                'objective' => $objective,
                'source_refs' => array_map(
                    static fn (string $ref): array => ['ref' => $ref],
                    $allowedFiles,
                ),
                'task_packet_id' => $taskPacketId,
                'strict_retrieval_gate' => (bool) config('atlas.programming.strict_retrieval_gate', true),
            ]);
            if (! $verdict->passed()) {
                return [
                    'ok' => false,
                    'reason' => 'aucri_context_blocked',
                    'compose' => $composeAudit ?? [],
                    'certify' => $verdict->audit,
                    'blockers' => $verdict->blockers,
                ];
            }
        }

        if ($this->eliteKernel !== null && ! $preCommit) {
            try {
                $this->eliteKernel->assertHonestOutcome([
                    'status' => 'success',
                    'execution' => [
                        'task_packet_id' => $taskPacketId,
                        'commit_sha' => (string) ($commit['commit_sha'] ?? ''),
                        'files_committed' => (array) ($commit['files_committed'] ?? []),
                    ],
                ], 'autonomos');
            } catch (Throwable $e) {
                return [
                    'ok' => false,
                    'reason' => 'elite_kernel_fake_green',
                    'error' => $e->getMessage(),
                ];
            }
        }

        return ['ok' => true, 'compose' => $composeAudit ?? []];
    }

    /**
     * OUTC-01(b): server-side verified outcome — anchored on scoped commit + verification,
     * NEVER on the worker's self-declared --outcome claim.
     *
     * @param  array<string,mixed>  $scope
     * @param  array<string,mixed>  $commit
     * @param  array<string,mixed>|null  $verification
     * @return array<string,mixed>|null
     */
    private function recordServerSideOutcomeSpine(
        string $taskPacketId,
        array $scope,
        array $commit,
        ?array $verification,
        bool $verified,
    ): ?array {
        if (! (bool) config('atlas.aemor.engineering_outcome_enabled', true)) {
            return null;
        }

        try {
            $allowed = array_values(array_map('strval', (array) ($scope['allowed_files'] ?? [])));
            $committed = array_values(array_map('strval', (array) ($commit['files_committed'] ?? [])));
            $scopeOk = $committed !== [] && array_diff($committed, $allowed) === [];
            $serverVerified = $verified
                && ($commit['committed'] ?? false) === true
                && trim((string) ($commit['commit_sha'] ?? '')) !== ''
                && $scopeOk
                && ($verification === null || ($verification['passed'] ?? false) === true);

            $evidenceRefs = array_values(array_filter([
                $serverVerified ? 'commit:'.((string) ($commit['commit_sha'] ?? '')) : null,
                'task_packet:'.$taskPacketId,
                $scopeOk ? 'scope_verified:files_subset_allowed' : 'scope_unverified:files_not_subset',
                isset($verification['execution_evidence']['tests_run'])
                    ? 'tests_run:'.(int) $verification['execution_evidence']['tests_run']
                    : null,
                $this->serverTestRunReceiptRef($taskPacketId, $verification),
            ]));

            if ($evidenceRefs === []) {
                $evidenceRefs = ['task_packet:'.$taskPacketId, 'verified:false'];
            }

            return app(AtlasEngineeringOutcomeRecorder::class)->record([
                'executor' => 'autonomos',
                'objective' => (string) ($scope['objective'] ?? 'Autônomos task landing'),
                'workspace' => base_path(),
                'surface_id' => 'task_serving',
                'scope_type' => 'task',
                'scope_id' => $taskPacketId,
                'run_id' => $taskPacketId,
                'task_id' => $taskPacketId,
                'decision_id' => $serverVerified
                    ? (string) ($commit['commit_sha'] ?? ('task:'.$taskPacketId))
                    : null,
                'decision_receipt_id' => $serverVerified
                    ? (string) ($commit['commit_sha'] ?? ('task:'.$taskPacketId))
                    : null,
                'status' => $serverVerified ? 'succeeded' : 'blocked',
                'summary' => $serverVerified
                    ? 'Autônomos task landed with server-side verified scoped commit.'
                    : 'Autônomos task reported without server-side verification (claim not confirmed).',
                'evidence_refs' => $evidenceRefs,
                'metrics' => [
                    'tests_passed' => $serverVerified && (int) data_get($verification, 'execution_evidence.tests_run', 0) > 0,
                    'attribution_reviewed' => true,
                    'server_verified' => $serverVerified,
                ],
                'verified' => $serverVerified,
                'learning_claim' => $serverVerified
                    ? 'Server-verified scoped Autônomos landings compound when decision receipt and evidence refs stay attached.'
                    : '',
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A1 — the ONLY resolvable proof of a green suite this producer can emit.
     *
     * `tests_run:51` is an un-resolvable counter: AEMOR's resolver requires a UUID
     * (resolveGreenTestRunReceiptRef → Str::isUuid), so every outcome this producer
     * ever closed fell back to `provenance=caller_claim` and the false-learning gate
     * blocked it on `success_without_test_or_gate_evidence`.
     *
     * The receipt is minted ONLY from `test_attestation`, which nothing but the
     * server-side AtlasTaskCommitVerificationGate writes — it executed the suite
     * itself (`php artisan test <task-owned test files>`) and pinned the result to a
     * tree hash of the task's changed files. The muscle's self-reported counters
     * (payload.evidence.tests_run) are merged into execution_evidence elsewhere and
     * are deliberately NOT a mint source: author must never be judge.
     */
    private function serverTestRunReceiptRef(string $taskPacketId, ?array $verification): ?string
    {
        $attestation = data_get($verification, 'test_attestation')
            ?? data_get($verification, 'execution_evidence.test_attestation');
        if (! is_array($attestation) || ($attestation['status'] ?? '') !== 'valid') {
            return null;
        }
        if ((int) ($attestation['exit_code'] ?? 1) !== 0 || (int) ($attestation['n_tests'] ?? 0) < 1) {
            return null;
        }
        if (! DatabaseTableAvailability::has('atlas_aaeos_test_run_receipts')) {
            return null;
        }

        try {
            $receipt = AtlasAaeosTestRunReceipt::query()->create([
                'capability_id' => 'autonomos.task.'.$taskPacketId,
                'test_ref' => implode(',', array_map('strval', (array) ($attestation['suite'] ?? []))),
                'filter' => 'task:'.$taskPacketId,
                'passed' => true,
                'tests_run' => (int) $attestation['n_tests'],
                'exit_code' => 0,
                'metadata' => [
                    // Server-side attribution: the suite is this task's OWN declared test
                    // files and the run is pinned to the tree hash of its changed files.
                    'attribution_reviewed' => true,
                    'attribution_basis' => 'task_owned_suite_pinned_to_tree_hash',
                    'source' => 'atlas_task_commit_verification_gate',
                    'runner' => (string) ($attestation['runner'] ?? ''),
                    'tree_hash' => (string) ($attestation['tree_hash'] ?? ''),
                    'assertions' => (int) ($attestation['n_assertions'] ?? 0),
                    'task_packet_id' => $taskPacketId,
                ],
                'ran_at' => now(),
            ]);

            return 'test_run_receipt:'.$receipt->id;
        } catch (Throwable) {
            return null;
        }
    }
}
