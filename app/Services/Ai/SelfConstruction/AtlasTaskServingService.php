<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Governance\AtlasTaskCommitGovernanceChain;

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

    public function __construct(
        private readonly AgentControlPlaneTaskQueueOrchestrator $orchestrator,
        private readonly ?AtlasTaskServingSentinel $sentinel = null,
        ?AtlasTaskPacketQualityInspector $inspector = null,
        ?AtlasTaskScopedCommitter $committer = null,
        ?AtlasTaskCommitVerificationGate $verifier = null,
        ?AtlasTaskCommitGovernanceChain $governance = null,
    ) {
        $this->inspector = $inspector ?? new AtlasTaskPacketQualityInspector;
        $this->committer = $committer ?? new AtlasTaskScopedCommitter;
        $this->verifier = $verifier ?? new AtlasTaskCommitVerificationGate;
        $this->governance = $governance ?? new AtlasTaskCommitGovernanceChain;
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

            // SPINE — the Merge Governor + Verification Court finally run on a LIVE delivery. In observe mode
            // (default) it RECORDS the verdict and NEVER blocks (the bootstrap swarm builds these very organs,
            // which score HIGH risk — enforcing here would self-lock the build). In enforce mode a non-admitted
            // decision refuses the commit, keeping the lease. Fail-open: a governance error never wedges a worker.
            $verificationFacts = isset($verification) && is_array($verification)
                ? ['passed' => ($verification['passed'] ?? false) === true, 'checks' => (array) ($verification['checks'] ?? [])]
                : ['passed' => false, 'checks' => []];
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

            return $this->reportEnvelope('resolved', $clientId, [
                'outcome' => 'success',
                'lease_closed' => (string) ($resolved['event'] ?? '') === 'task_resolved',
                'task_packet_id' => $taskPacketId,
                'lease_id' => $leaseId,
                'commit_sha' => (string) ($commit['commit_sha'] ?? ''),
                'files_committed' => array_values((array) ($commit['files_committed'] ?? [])),
                'governance' => $governance,
                'result' => $resolved,
            ]);
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

        // govA-cortex-cadence — outcome-triggered invalidation: a give_back/failure means the
        // worker's mental model of the scope diverged from reality. Drop the comprehension
        // snapshot so the next authoring round rebuilds against fresh inventory. Fail-open.
        try {
            $cadence = app()->bound(\App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionCadenceService::class)
                ? app(\App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionCadenceService::class)
                : new \App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionCadenceService();
            $cadence->invalidate('task_outcome_'.$outcome.':'.$taskPacketId);
        } catch (\Throwable) {
            // never let a comprehension hiccup wedge a give_back report
        }

        return $this->reportEnvelope('reported', $clientId, [
            'outcome' => $outcome,
            'lease_released' => in_array($event, ['given_back', 'give_back_quarantined'], true),
            'quarantined' => $event === 'give_back_quarantined',
            'give_back_count' => (int) ($result['give_back_count'] ?? 0),
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'result' => $result,
        ]);
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
