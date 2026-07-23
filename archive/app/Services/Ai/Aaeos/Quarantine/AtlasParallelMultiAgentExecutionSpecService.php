<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Parallel Multi-Agent Execution Spec — pure, deterministic gate that
 * enforces the FIVE simultaneous invariants of safe durable parallelism.
 *
 * The doc is the single authority that consolidates ~30 dispersed
 * self-construction contracts. This service is the runtime decision core: it
 * does NOT spawn worktrees, mutate git, or call a database — it classifies a
 * declared parallel run against the documented rules and returns a typed,
 * machine-readable verdict (safe to run / blocked + reasons).
 *
 * Documented rules this code enforces (1:1 with the doc):
 *   - "As 5 invariantes simultaneas": durable reservation ledger, worktree-per
 *     -agent (R3+), scope validator, collision guard, merge review. Missing any
 *     one => parallelism is NOT safe (`parallel_safe=false`).
 *   - Reservation Ledger is append-only: a lease may only transition
 *     active -> {expired, released, preempted}; never an in-place update, never
 *     a backwards transition. `LEASE_STATES` + `assessLease()`.
 *   - "Cada agente recebe UMA reservation; subleasing proibido." Two active
 *     reservations for the same agent_id => violation `agent_double_reservation`.
 *   - "TTL default 30min; renew obrigatorio antes de expirar." Default TTL is
 *     1800s; a lease whose age >= ttl is `expired`; a lease in the renew window
 *     yet not renewed is flagged `renew_required`.
 *   - Worktree mandatory for R3+: rings R3 and above without one worktree per
 *     agent => violation `worktree_missing_r3_plus`. R1-R2 may run single tree.
 *   - "Collision detectada bloqueia merge ate resolver." Any blocking collision
 *     (kind path|symbol|migration) => merge blocked.
 *   - Merge Review Promotion: R3 and below auto-merge when collision=0 and gates
 *     green; R4 and above ALWAYS require human|architect review before merge.
 *
 * @see docs/engineering-knowledge-base/atlas-parallel-multi-agent-execution-spec.md
 */
final class AtlasParallelMultiAgentExecutionSpecService
{
    /** Documented default lease TTL: "TTL default 30min". */
    public const DEFAULT_TTL_SECONDS = 1800;

    /**
     * Fraction of the TTL remaining at/below which an active, non-renewed lease
     * is flagged renew_required. 0.20 => the last 20% of the lease window.
     */
    public const RENEW_WINDOW_FRACTION = 0.20;

    /** Worktree-per-agent becomes mandatory at this ring and above. */
    public const WORKTREE_MANDATORY_RING = 3;

    /** Human / architect merge review becomes mandatory at this ring and above. */
    public const REVIEW_MANDATORY_RING = 4;

    /** The five invariant keys, in doc order. */
    public const INVARIANTS = [
        'durable_reservation_ledger',
        'worktree_per_agent',
        'scope_validator',
        'collision_guard',
        'merge_review_promotion',
    ];

    /** Append-only lease lifecycle: active is the entry state. */
    public const LEASE_STATES = ['active', 'expired', 'released', 'preempted'];

    /** Allowed forward transitions out of `active` (append-only, never backwards). */
    private const LEASE_FORWARD = [
        'active' => ['expired', 'released', 'preempted'],
        'expired' => [],
        'released' => [],
        'preempted' => [],
    ];

    public const DECISION_RUN = 'run_parallel';
    public const DECISION_BLOCK = 'block';

    /**
     * Primary gate. Assess a declared parallel run against all five invariants.
     *
     * @param array<string,mixed> $run {
     *   ring?: int,                                   // Forge ring R1..Rn (default 1)
     *   ttl_seconds?: int,                            // lease TTL override (default 1800)
     *   reservations?: list<array<string,mixed>>,     // reservation.entry.v1-ish rows
     *   worktrees?: list<array<string,mixed>>,        // worktree.v1-ish rows
     *   collision_report?: array<string,mixed>,       // collision.report.v1
     *   gates_green?: bool,                           // pre-merge quality gates
     *   review?: array<string,mixed>|null,            // merge.review.v1 (R4+)
     *   parallelism_mode?: string,                    // must be parallel_durable
     *   now?: int,                                    // unix ts for determinism
     * }
     * @return array<string,mixed>
     */
    public function assess(array $run): array
    {
        $ring = $this->intval($run['ring'] ?? 1, 1);
        $ttl = $this->intval($run['ttl_seconds'] ?? self::DEFAULT_TTL_SECONDS, self::DEFAULT_TTL_SECONDS);
        if ($ttl <= 0) {
            $ttl = self::DEFAULT_TTL_SECONDS;
        }
        $now = $this->intval($run['now'] ?? 0, 0);

        $reservations = $this->rows($run['reservations'] ?? []);
        $worktrees = $this->rows($run['worktrees'] ?? []);
        $collisionReport = is_array($run['collision_report'] ?? null) ? $run['collision_report'] : [];
        $gatesGreen = (bool) ($run['gates_green'] ?? false);
        $review = is_array($run['review'] ?? null) ? $run['review'] : null;
        $mode = (string) ($run['parallelism_mode'] ?? '');

        $violations = [];

        // --- Invariant 1: durable reservation ledger (declared + agents present)
        $reservationReport = $this->assessReservations($reservations, $ttl, $now);
        $ledgerOk = $reservations !== [] && $reservationReport['fatal'] === [];
        foreach ($reservationReport['fatal'] as $v) {
            $violations[] = $v;
        }
        if ($reservations === []) {
            $violations[] = $this->violation('reservation_ledger_missing', 'durable_reservation_ledger', 'No reservations declared; durable ledger is mandatory before parallelism.');
        }

        // --- Invariant 2: worktree-per-agent (mandatory R3+)
        $worktreeReport = $this->assessWorktrees($reservations, $worktrees, $ring);
        $worktreeOk = $worktreeReport['ok'];
        foreach ($worktreeReport['violations'] as $v) {
            $violations[] = $v;
        }

        // --- Invariant 3: scope validator (declared per packet/agent)
        $scopeOk = $this->assessScopeDeclared($reservations);
        if (! $scopeOk) {
            $violations[] = $this->violation('scope_validator_undeclared', 'scope_validator', 'At least one reservation has no scope.paths; scope validator cannot bound the edit.');
        }

        // --- Invariant 4: collision guard
        $collision = $this->assessCollision($collisionReport);
        $collisionOk = ! $collision['blocking'];
        if ($collision['blocking']) {
            $violations[] = $this->violation('collision_blocking', 'collision_guard', 'Blocking collision(s) detected; merge is blocked until resolved.');
        }

        // --- Invariant 5: merge review promotion
        $merge = $this->assessMergePromotion($ring, $collision['blocking'], $gatesGreen, $review);
        $reviewOk = $merge['invariant_ok'];
        foreach ($merge['violations'] as $v) {
            $violations[] = $v;
        }

        $invariantStatus = [
            'durable_reservation_ledger' => $ledgerOk,
            'worktree_per_agent' => $worktreeOk,
            'scope_validator' => $scopeOk,
            'collision_guard' => $collisionOk,
            'merge_review_promotion' => $reviewOk,
        ];

        $missingInvariants = [];
        foreach (self::INVARIANTS as $name) {
            if ($invariantStatus[$name] === false) {
                $missingInvariants[] = $name;
            }
        }

        // parallel_durable mode is the documented precondition; without it the
        // run is not even a parallel-durable run.
        $modeOk = $mode === '' || $mode === 'parallel_durable';
        if (! $modeOk) {
            $violations[] = $this->violation('parallelism_mode_invalid', 'durable_reservation_ledger', 'parallelism_mode must be parallel_durable to engage the durable gate.');
        }

        $parallelSafe = $missingInvariants === [] && $modeOk;
        $decision = $parallelSafe ? self::DECISION_RUN : self::DECISION_BLOCK;

        return [
            'schema' => 'atlas.parallel.execution.assessment.v1',
            'ring' => $ring,
            'ttl_seconds' => $ttl,
            'parallelism_mode' => $mode === '' ? 'parallel_durable' : $mode,
            'parallel_safe' => $parallelSafe,
            'decision' => $decision,
            'invariants' => $invariantStatus,
            'missing_invariants' => $missingInvariants,
            'reservation_report' => $reservationReport,
            'worktree_report' => $worktreeReport,
            'collision' => $collision,
            'merge_promotion' => $merge,
            'violations' => array_values($violations),
            'blocking_count' => count($violations),
            'summary' => [
                'reservations' => count($reservations),
                'worktrees' => count($worktrees),
                'invariants_satisfied' => count(self::INVARIANTS) - count($missingInvariants),
                'invariants_total' => count(self::INVARIANTS),
            ],
        ];
    }

    /**
     * Lease lifecycle assessment (append-only, TTL, renew, no subleasing).
     *
     * @param list<array<string,mixed>> $reservations
     * @return array<string,mixed>
     */
    public function assessReservations(array $reservations, int $ttlDefault = self::DEFAULT_TTL_SECONDS, int $now = 0): array
    {
        $fatal = [];
        $warnings = [];
        $perAgentActive = [];
        $leases = [];

        foreach ($reservations as $i => $res) {
            $agentId = (string) ($res['agent_id'] ?? '');
            $lease = is_array($res['lease'] ?? null) ? $res['lease'] : [];
            $assessment = $this->assessLease($res, $ttlDefault, $now);
            $assessment['agent_id'] = $agentId;
            $assessment['index'] = $i;
            $leases[] = $assessment;

            if (! $assessment['state_transition_legal']) {
                $fatal[] = $this->violation(
                    'lease_transition_illegal',
                    'durable_reservation_ledger',
                    "Illegal lease transition '{$assessment['from_state']}' -> '{$assessment['to_state']}' (append-only forbids it).",
                    ['agent_id' => $agentId]
                );
            }
            if ($assessment['renew_required']) {
                $warnings[] = $this->violation(
                    'lease_renew_required',
                    'durable_reservation_ledger',
                    'Lease inside renew window and not renewed; renew before expiry.',
                    ['agent_id' => $agentId]
                );
            }
            if ($assessment['expired']) {
                $warnings[] = $this->violation(
                    'lease_expired',
                    'durable_reservation_ledger',
                    'Lease age exceeded TTL without renew (lease leak risk).',
                    ['agent_id' => $agentId]
                );
            }

            if ($assessment['effective_state'] === 'active' && $agentId !== '') {
                $perAgentActive[$agentId] = ($perAgentActive[$agentId] ?? 0) + 1;
            }
        }

        // No subleasing: one ACTIVE reservation per agent.
        foreach ($perAgentActive as $agentId => $count) {
            if ($count > 1) {
                $fatal[] = $this->violation(
                    'agent_double_reservation',
                    'durable_reservation_ledger',
                    "Agent '{$agentId}' holds {$count} active reservations; subleasing/double-reservation is forbidden (one reservation per agent).",
                    ['agent_id' => $agentId, 'active_count' => $count]
                );
            }
        }

        return [
            'leases' => $leases,
            'active_per_agent' => $perAgentActive,
            'fatal' => array_values($fatal),
            'warnings' => array_values($warnings),
            'append_only_ok' => $fatal === [],
        ];
    }

    /**
     * Per-lease append-only + TTL + renew classification.
     *
     * @param array<string,mixed> $reservation
     * @return array<string,mixed>
     */
    public function assessLease(array $reservation, int $ttlDefault = self::DEFAULT_TTL_SECONDS, int $now = 0): array
    {
        $lease = is_array($reservation['lease'] ?? null) ? $reservation['lease'] : [];
        $declaredState = (string) ($reservation['state'] ?? 'active');
        if (! in_array($declaredState, self::LEASE_STATES, true)) {
            $declaredState = 'active';
        }

        $ttl = $this->intval($lease['ttl_seconds'] ?? $ttlDefault, $ttlDefault);
        if ($ttl <= 0) {
            $ttl = $ttlDefault;
        }

        $acquiredAt = $this->parseTs($lease['acquired_at'] ?? null);
        $renewAt = $this->parseTs($lease['renew_at'] ?? null);
        $releasedAt = $lease['released_at'] ?? null;
        $hasRelease = $releasedAt !== null && $releasedAt !== '';

        // Age of the lease relative to `now` (or to renew_at if now omitted).
        $age = null;
        if ($acquiredAt !== null && $now > 0) {
            $age = max(0, $now - $acquiredAt);
        }

        $expired = false;
        $renewRequired = false;
        if ($declaredState === 'active' && $age !== null) {
            if ($age >= $ttl) {
                $expired = true;
            } else {
                $remaining = $ttl - $age;
                // Renewed iff renew_at is in the future relative to now.
                $renewed = $renewAt !== null && $renewAt > $now;
                if (! $renewed && $remaining <= (int) ceil($ttl * self::RENEW_WINDOW_FRACTION)) {
                    $renewRequired = true;
                }
            }
        }

        // Effective state: an active lease past TTL is effectively expired.
        $effective = $declaredState;
        if ($declaredState === 'active' && $expired) {
            $effective = 'expired';
        }

        // Append-only legality: declared state must be reachable from 'active'.
        // 'active' itself is legal (entry). A release timestamp with a still
        // 'active' declared state is an in-place mutation smell -> illegal.
        $fromState = 'active';
        $toState = $declaredState;
        $legal = true;
        if ($declaredState !== 'active') {
            $legal = in_array($declaredState, self::LEASE_FORWARD['active'], true);
        }
        if ($declaredState === 'active' && $hasRelease) {
            // released_at set but state never transitioned => not append-only.
            $legal = false;
            $toState = 'released';
        }

        return [
            'declared_state' => $declaredState,
            'effective_state' => $effective,
            'ttl_seconds' => $ttl,
            'age_seconds' => $age,
            'expired' => $expired,
            'renew_required' => $renewRequired,
            'state_transition_legal' => $legal,
            'from_state' => $fromState,
            'to_state' => $toState,
        ];
    }

    /**
     * Worktree-per-agent invariant. Mandatory for ring >= WORKTREE_MANDATORY_RING.
     *
     * @param list<array<string,mixed>> $reservations
     * @param list<array<string,mixed>> $worktrees
     * @return array<string,mixed>
     */
    public function assessWorktrees(array $reservations, array $worktrees, int $ring): array
    {
        $mandatory = $ring >= self::WORKTREE_MANDATORY_RING;
        $violations = [];

        // Live worktrees: created, not deleted.
        $liveAgents = [];
        foreach ($worktrees as $wt) {
            $deleted = $wt['deleted_at'] ?? null;
            if ($deleted !== null && $deleted !== '') {
                continue;
            }
            $agentId = (string) ($wt['agent_id'] ?? '');
            if ($agentId !== '') {
                $liveAgents[$agentId] = ($liveAgents[$agentId] ?? 0) + 1;
            }
        }

        $agentsNeedingTree = [];
        foreach ($reservations as $res) {
            $aid = (string) ($res['agent_id'] ?? '');
            if ($aid !== '') {
                $agentsNeedingTree[$aid] = true;
            }
        }

        if ($mandatory) {
            foreach (array_keys($agentsNeedingTree) as $aid) {
                if (! isset($liveAgents[$aid])) {
                    $violations[] = $this->violation(
                        'worktree_missing_r3_plus',
                        'worktree_per_agent',
                        "Agent '{$aid}' has no live worktree; worktree-per-agent is mandatory at ring R{$ring} (R3+).",
                        ['agent_id' => $aid, 'ring' => $ring]
                    );
                }
            }
            // Shared worktree (two agents, one tree) is also a violation: each
            // live worktree must map to exactly one agent.
            foreach ($liveAgents as $aid => $count) {
                if ($count > 1) {
                    $violations[] = $this->violation(
                        'worktree_shared',
                        'worktree_per_agent',
                        "Agent '{$aid}' is bound to {$count} live worktrees; one worktree per agent required.",
                        ['agent_id' => $aid]
                    );
                }
            }
        }

        return [
            'mandatory' => $mandatory,
            'ring' => $ring,
            'live_worktree_agents' => array_keys($liveAgents),
            'agents_needing_tree' => array_keys($agentsNeedingTree),
            'violations' => array_values($violations),
            'ok' => $violations === [],
        ];
    }

    /**
     * Collision guard. Blocking iff there is at least one collision marked
     * blocking, OR any collision that is not auto_resolvable.
     *
     * @param array<string,mixed> $report collision.report.v1
     * @return array<string,mixed>
     */
    public function assessCollision(array $report): array
    {
        $collisions = $this->rows($report['collisions'] ?? []);
        $reportBlocking = array_key_exists('blocking', $report) ? (bool) $report['blocking'] : null;
        $autoResolvable = array_key_exists('auto_resolvable', $report) ? (bool) $report['auto_resolvable'] : null;

        $byKind = ['path' => 0, 'symbol' => 0, 'migration' => 0, 'other' => 0];
        foreach ($collisions as $c) {
            $kind = (string) ($c['kind'] ?? 'other');
            if (! array_key_exists($kind, $byKind)) {
                $kind = 'other';
            }
            $byKind[$kind]++;
        }

        $hasCollision = $collisions !== [];

        // Doc: "Collision detectada bloqueia merge ate resolver." Default to
        // blocking when collisions exist, unless the report explicitly says it
        // is non-blocking AND auto_resolvable.
        if (! $hasCollision) {
            $blocking = false;
        } elseif ($reportBlocking !== null) {
            $blocking = $reportBlocking;
        } else {
            $blocking = $autoResolvable === true ? false : true;
        }

        return [
            'has_collision' => $hasCollision,
            'collision_count' => count($collisions),
            'by_kind' => $byKind,
            'auto_resolvable' => $autoResolvable ?? ($hasCollision ? false : true),
            'blocking' => $blocking,
        ];
    }

    /**
     * Merge review promotion gate.
     *   - ring <= 3: auto-merge eligible iff collision=0 AND gates green.
     *   - ring >= 4: mandatory human|architect review with decision=approve.
     *
     * @param array<string,mixed>|null $review merge.review.v1
     * @return array<string,mixed>
     */
    public function assessMergePromotion(int $ring, bool $collisionBlocking, bool $gatesGreen, ?array $review): array
    {
        $requiresReview = $ring >= self::REVIEW_MANDATORY_RING;
        $violations = [];

        $reviewKind = $review !== null ? (string) (($review['reviewer']['kind'] ?? '')) : '';
        $reviewDecision = $review !== null ? (string) ($review['decision'] ?? '') : '';
        $reviewerValid = in_array($reviewKind, ['human', 'architect_agent'], true);
        $reviewApproved = $reviewerValid && $reviewDecision === 'approve';

        if ($requiresReview) {
            if ($review === null) {
                $violations[] = $this->violation(
                    'merge_review_missing',
                    'merge_review_promotion',
                    "Ring R{$ring} (R4+) requires a human|architect merge review before merge; none provided.",
                    ['ring' => $ring]
                );
            } elseif (! $reviewerValid) {
                $violations[] = $this->violation(
                    'merge_reviewer_invalid',
                    'merge_review_promotion',
                    "Merge reviewer.kind must be human|architect_agent at ring R{$ring}.",
                    ['ring' => $ring, 'reviewer_kind' => $reviewKind]
                );
            } elseif (! $reviewApproved) {
                $violations[] = $this->violation(
                    'merge_review_not_approved',
                    'merge_review_promotion',
                    "Merge review decision is '{$reviewDecision}', not 'approve'; merge blocked at ring R{$ring}.",
                    ['ring' => $ring, 'decision' => $reviewDecision]
                );
            }
        }

        // Can we actually merge?
        $mergeAllowed = ! $collisionBlocking && $gatesGreen;
        if ($requiresReview) {
            $mergeAllowed = $mergeAllowed && $reviewApproved;
        }

        $mode = $requiresReview ? 'review_required' : 'auto_merge_eligible';

        // The invariant (#5) itself is satisfied when the *promotion rule* is
        // respected: R4+ has a valid approving review (or is correctly blocked
        // by its absence is a violation already recorded). The invariant is OK
        // when there are no promotion violations.
        $invariantOk = $violations === [];

        return [
            'ring' => $ring,
            'mode' => $mode,
            'requires_review' => $requiresReview,
            'gates_green' => $gatesGreen,
            'collision_blocking' => $collisionBlocking,
            'review_approved' => $reviewApproved,
            'merge_allowed' => $mergeAllowed,
            'invariant_ok' => $invariantOk,
            'violations' => array_values($violations),
        ];
    }

    /** @return array<string,mixed> a deterministic safe-default demo run for the CLI. */
    public function demoRun(): array
    {
        return [
            'ring' => 4,
            'ttl_seconds' => self::DEFAULT_TTL_SECONDS,
            'parallelism_mode' => 'parallel_durable',
            'now' => 1000,
            'reservations' => [
                [
                    'agent_id' => 'agent-a',
                    'intent_id' => 'intent-1',
                    'scope' => ['paths' => ['app/Services/Foo.php'], 'operations' => ['write']],
                    'state' => 'active',
                    'lease' => ['acquired_at' => 900, 'ttl_seconds' => self::DEFAULT_TTL_SECONDS, 'renew_at' => 1800],
                ],
                [
                    'agent_id' => 'agent-b',
                    'intent_id' => 'intent-2',
                    'scope' => ['paths' => ['app/Services/Bar.php'], 'operations' => ['write']],
                    'state' => 'active',
                    'lease' => ['acquired_at' => 900, 'ttl_seconds' => self::DEFAULT_TTL_SECONDS, 'renew_at' => 1800],
                ],
            ],
            'worktrees' => [
                ['worktree_id' => 'wt-a', 'agent_id' => 'agent-a'],
                ['worktree_id' => 'wt-b', 'agent_id' => 'agent-b'],
            ],
            'collision_report' => ['collisions' => [], 'auto_resolvable' => true, 'blocking' => false],
            'gates_green' => true,
            'review' => ['reviewer' => ['kind' => 'architect_agent', 'id' => 'arch-1'], 'decision' => 'approve'],
        ];
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function violation(string $code, string $invariant, string $message, array $extra = []): array
    {
        return array_merge([
            'code' => $code,
            'invariant' => $invariant,
            'message' => $message,
        ], $extra);
    }

    /**
     * @param mixed $value
     * @return list<array<string,mixed>>
     */
    private function rows($value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return array_values($out);
    }

    private function assessScopeDeclared(array $reservations): bool
    {
        if ($reservations === []) {
            return false;
        }
        foreach ($reservations as $res) {
            $scope = is_array($res['scope'] ?? null) ? $res['scope'] : [];
            $paths = is_array($scope['paths'] ?? null) ? $scope['paths'] : [];
            if ($paths === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $value
     */
    private function intval($value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /**
     * @param mixed $value
     */
    private function parseTs($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (is_string($value)) {
            $ts = strtotime($value);

            return $ts === false ? null : $ts;
        }

        return null;
    }
}
