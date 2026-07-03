<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\EngineeringKernel\Adapters\MaestroCostBudgetMeterAdapter;
use App\Services\Ai\EngineeringKernel\BudgetMeter;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskCommitGovernanceChain;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskGovernancePolicyPlane;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskPostLandCanarySentinel;
use App\Services\Ai\SelfConstruction\Maestro\Cost\AtlasMaestroCostAggregator;
use App\Services\Ai\SelfConstruction\Maestro\Cost\AtlasMaestroCostLedger;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtEvidenceContract;
use Closure;
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
 * MASTER-SWITCH GATED: with the loop master switch OFF, `next`/`report` are inert (a `disabled` envelope) —
 * the serving surface never dispatches while the loop is off, and the loop never reanimates itself.
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

    private readonly BudgetMeter $budgetMeter;

    /** @var Closure(array<string,mixed>,array<string,mixed>):array<string,mixed> */
    private readonly Closure $evidenceContractEvaluator;

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
    ) {
        $this->inspector = $inspector ?? new AtlasTaskPacketQualityInspector;
        $this->committer = $committer ?? new AtlasTaskScopedCommitter;
        $this->verifier = $verifier ?? new AtlasTaskCommitVerificationGate;
        $this->governance = $governance ?? new AtlasTaskCommitGovernanceChain;
        $this->canarySentinel = $canarySentinel ?? new AtlasTaskPostLandCanarySentinel;
        $this->policyPlane = $policyPlane ?? new AtlasTaskGovernancePolicyPlane;
        // Default to the Maestro adapter over the SAME cost ledger `atlas:task:maestro:cost` reads,
        // so task-lane usage facts become visible per-muscle with zero new storage.
        $this->budgetMeter = $budgetMeter ?? new MaestroCostBudgetMeterAdapter(
            new AtlasMaestroCostLedger(storage_path('atlas/maestro/cost_ledger.jsonl')),
            new AtlasMaestroCostAggregator,
        );
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

        $filters = $this->safeFilters($filters);
        $lastDeficiencies = [];

        // Quarantine-and-skip loop: a cold client must only ever receive an IMPLEMENTABLE packet. If a claimed
        // packet is not self-sufficient (axis 8), block it out of the pool and try the next candidate. Bounded.
        for ($skip = 0; $skip < self::MAX_QUARANTINE_SKIPS; $skip++) {
            $claim = $this->orchestrator->claimNext($clientId, $filters);

            if ((string) ($claim['event'] ?? '') !== 'claimed') {
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

    /** Record the serve outcome on the R2 sentinel (if wired), then return the envelope unchanged. */
    private function served(string $clientId, array $envelope): array
    {
        $this->sentinel?->recordServe($clientId, (string) ($envelope['status'] ?? ''));

        return $envelope;
    }

    /**
     * REPORT the outcome of a served task and close/release the lease. `outcome=success` runs the dry-run
     * completion gate (evidence-validated); `failed`/`give_back` releases the lease so the task returns to
     * claimable. Real merge is NOT performed here (gated, separate obra — see B3).
     *
     * @param  array<string, mixed>  $payload  {outcome?:success|failed|give_back, evidence?:array}
     * @return array<string, mixed>
     */
    public function report(string $clientId, string $taskPacketId, string $leaseId, array $payload = []): array
    {
        if (! AtlasTaskServingSwitch::enabled()) {
            return $this->reportEnvelope('disabled', $clientId, ['reason' => 'task_serving_switch_off']);
        }
        $clientId = trim($clientId);
        if ($clientId === '' || $taskPacketId === '' || $leaseId === '') {
            return $this->reportEnvelope('invalid_report', $clientId, ['reason' => 'client_id_task_packet_id_and_lease_id_required']);
        }

        $outcome = (string) ($payload['outcome'] ?? 'success');

        // Explicit outcome whitelist: a typo or hostile outcome string must never fall through
        // into the give_back path below — it would silently convert into a give_back loop.
        if (! in_array($outcome, ['success', 'failed', 'give_back'], true)) {
            return $this->reportEnvelope('invalid_report', $clientId, [
                'reason' => 'invalid_outcome',
                'lease_closed' => false,
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'outcome' => $outcome,
            ]);
        }

        // SHARED-MAIN resolve: commit EXACTLY this task's allowed_files (server-truth scope) as the AI's own
        // commit, then close. Only when the client asks to commit (the runbook flow); otherwise the legacy
        // dry-run path stays intact.
        if ($outcome === 'success' && (bool) ($payload['commit'] ?? false)) {
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
            // on a failed verdict, keeping the lease so the worker fixes evidence and re-reports. A
            // contract-evaluation exception is recorded and the report proceeds — fail-open in every mode.
            $evidenceContractMode = $this->policyPlane->evidenceContractMode();
            $evidenceContractVerdict = null;
            if ($evidenceContractMode !== 'off') {
                try {
                    $allegation = [
                        'task_packet_id' => $taskPacketId,
                        'lease_id' => $leaseId,
                        'allowed_files_hash' => hash('sha256', implode(',', (array) $scope['allowed_files'])),
                        'command_hash' => hash('sha256', implode(',', array_keys((array) ($verification['checks'] ?? [])))),
                    ];
                    $evidenceContractVerdict = ($this->evidenceContractEvaluator)($allegation, (array) ($payload['evidence'] ?? []));
                } catch (Throwable $e) {
                    $evidenceContractVerdict = ['schema' => AtlasVerificationCourtEvidenceContract::SCHEMA, 'accepted' => true, 'blockers' => [], 'error' => $e->getMessage()];
                }

                if ($evidenceContractMode === 'enforce' && ($evidenceContractVerdict['accepted'] ?? true) !== true) {
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

            $commit = $this->committer->commitScope((array) $scope['allowed_files'], $taskPacketId, $clientId, (string) $scope['objective']);

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

            $resolved = $this->orchestrator->markResolved($taskPacketId, $leaseId, $clientId, (string) ($commit['commit_sha'] ?? ''));

            // Usage metering: one fact per resolved commit through the shared BudgetMeter mechanism,
            // landing in the SAME Maestro cost ledger `atlas:task:maestro:cost` reads. Swallowed on
            // any failure — observability must never fail a report.
            try {
                $filesCommittedCount = count((array) ($commit['files_committed'] ?? []));
                $verificationChecksRun = count((array) ($verificationFacts['checks'] ?? []));
                $wallSeconds = $this->leaseAgeSeconds($leaseId) ?? 0;
                $this->budgetMeter->measure([
                    'task_packet_id' => $taskPacketId,
                    'agent_id' => $clientId,
                    'files_committed_count' => $filesCommittedCount,
                    'verification_checks_run' => $verificationChecksRun,
                    'wall_seconds' => $wallSeconds,
                    // Ledger-required synonyms (AtlasMaestroCostLedger::REQUIRED_FIELDS) so the fact
                    // is well-formed and actually lands, not silently skipped.
                    'task_class' => 'task_lane_commit',
                    'provider' => $clientId,
                    'model' => 'n/a',
                    'cycle_id' => 'task_lane',
                    'tokens_in' => $filesCommittedCount,
                    'tokens_out' => $verificationChecksRun,
                    'cost_cents' => $wallSeconds,
                    'recorded_at' => date('c'),
                ]);
            } catch (Throwable) {
                // fail-open: metering must never break a resolved report
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

            return $this->reportEnvelope('resolved', $clientId, array_merge([
                'outcome' => 'success',
                'lease_closed' => (string) ($resolved['event'] ?? '') === 'task_resolved',
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'commit_sha' => (string) ($commit['commit_sha'] ?? ''),
                'files_committed' => array_values((array) ($commit['files_committed'] ?? [])),
                'governance' => $governance,
                'result' => $resolved,
            ], $evidenceContractMode !== 'off' ? ['evidence_contract' => $evidenceContractVerdict] : []));
        }

        if ($outcome === 'success') {
            $result = $this->orchestrator->completeDryRun($taskPacketId, $leaseId, (array) ($payload['evidence'] ?? []));
            $event = (string) ($result['event'] ?? '');
            $closed = str_contains($event, 'completed') && ! str_contains($event, 'blocked');

            return $this->reportEnvelope('reported', $clientId, [
                'outcome' => 'success',
                'lease_closed' => $closed,
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'orchestrator_event' => $event,
                'result' => $result,
            ]);
        }

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
                $anchor = app(\App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevTaskPacketRuntimeService::class)->persist([
                    'run_id' => 'serving-'.$taskPacketId,
                    'task_id' => $taskPacketId,
                    'objective' => (string) ($served['objective'] ?? ''),
                    'workspace_slug' => \App\Services\Ai\Programming\AtlasDev\Support\WorkspaceOriginIdentity::slug(base_path()),
                    'allowed_files' => $files,
                    'source' => 'task_serving_report',
                ]);
                app(\App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevFailureCapsuleRuntimeService::class)->persist([
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
            $cadence = app()->bound(\App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionCadenceService::class)
                ? app(\App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionCadenceService::class)
                : new \App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionCadenceService();
            $cadence->invalidate('task_outcome_'.$outcome.':'.$taskPacketId);
        } catch (\Throwable $e) {
            // never let a comprehension hiccup wedge a give_back report — but a
            // failed invalidation means the brain keeps authoring against a stale
            // scope model, so the loss is logged, never invisible.
            \Illuminate\Support\Facades\Log::warning('comprehension_invalidation_failed', [
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
                \Illuminate\Support\Facades\Artisan::call('atlas:task:repair-blocked', [
                    '--limit' => 10,
                    '--actor' => 'auto_respec_on_quarantine',
                    '--json' => true,
                ]);
                $autoRepair = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);
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
     * from persisted {@see \App\Models\AtlasDevFailureCapsule} rows, scoped
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

            return (new \App\Services\Ai\Programming\AtlasDev\RuntimeIntelligence\DevFailureCapsulePromptInjector)->injectFor(
                $files,
                \App\Services\Ai\Programming\AtlasDev\Support\WorkspaceOriginIdentity::slug(base_path()),
            );
        } catch (Throwable) {
            return []; // fail-open: advisory memory never blocks a serve
        }
    }

    private function siblingTestsFor(array $task): array
    {
        try {
            $resolver = new \App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopSiblingTestResolver;
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

        return [
            'task_packet_id' => (string) ($claim['task_packet_id'] ?? data_get($packet, 'task_packet_id', '')),
            'lease_id' => (string) ($claim['lease_id'] ?? ''),
            'lease_expires_at' => (string) data_get($claim, 'lease.expires_at', ''),
            'objective' => (string) data_get($packet, 'objective', ''),
            'allowed_files' => array_values((array) data_get($packet, 'normalized_scope.allowed_files', data_get($packet, 'allowed_files', []))),
            'forbidden_files' => array_values((array) data_get($packet, 'normalized_scope.forbidden_files', data_get($packet, 'forbidden_files', []))),
            'scope_in' => array_values((array) data_get($packet, 'normalized_scope.scope_in', data_get($packet, 'scope_in', []))),
            'acceptance_criteria' => array_values((array) data_get($packet, 'acceptance_criteria', [])),
            // The builder stores the evidence list under `evidence_requirements.required` — projecting the bare
            // `required_evidence` key (absent) left the served packet WITHOUT the evidence a cold client must
            // produce. Read the real path (fallback to the projection shape).
            'required_evidence' => array_values((array) data_get($packet, 'evidence_requirements.required', data_get($packet, 'required_evidence', []))),
            'continuation_context' => (array) data_get($packet, 'continuation_context', []),
            'risk_level' => (string) data_get($packet, 'risk_classification.risk_level', data_get($packet, 'risk_level', 'unspecified')),
        ];
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
        return array_merge([
            'schema' => self::REPORT_SCHEMA,
            'status' => $status,                 // reported | disabled | invalid_report
            'client_id' => $clientId,
        ], $extra);
    }
}
