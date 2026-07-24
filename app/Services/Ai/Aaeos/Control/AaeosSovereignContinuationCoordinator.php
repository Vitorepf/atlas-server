<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control;

/**
 * P2e / R81+R100: async H1–H7 sovereign continuation (path-core).
 *
 * Pure in-process coordinator — no second queue/wait organ. Proves that while
 * work-item A awaits sovereign attention (H1–H7), unrelated B can be claimed,
 * executed and settled; A resumes exactly once under original action_hash /
 * continuation_ref after DecisionIssued. Waiting never becomes a global halt.
 */
final class AaeosSovereignContinuationCoordinator
{
    public const SCHEMA = 'atlas.aaeos.sovereign_continuation.v1';

    public const STATUS_OPEN = 'open';

    public const STATUS_AWAITING_SOVEREIGN = 'awaiting_sovereign';

    public const STATUS_CLAIMED = 'claimed';

    public const STATUS_EXECUTED = 'executed';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_RESUMED = 'resumed';

    public const STATUS_BLOCKED = 'blocked';

    /** @var list<string> Sovereign human gates (attention only — not eng truth). */
    public const H_GATES = ['H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'H7'];

    /** @var array<string,array<string,mixed>> */
    private array $items = [];

    /** @var list<array<string,mixed>> */
    private array $journal = [];

    /**
     * Open work-item A into H1–H7 wait. Produces no reserved effect and must not
     * freeze the rest of the system (global_halt=false).
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function awaitSovereign(string $workItemId, array $context = []): array
    {
        $workItemId = $this->requireId($workItemId, 'work_item_id');
        if (isset($this->items[$workItemId])) {
            return $this->fail($workItemId, 'work_item_already_exists');
        }

        $hGates = $this->normalizeHGates($context['h_gates'] ?? self::H_GATES);
        if ($hGates === []) {
            return $this->fail($workItemId, 'h_gates_required');
        }

        $actionHash = $this->requireHash((string) ($context['action_hash'] ?? ''), 'action_hash');
        $continuationRef = trim((string) ($context['continuation_ref'] ?? ''));
        if ($continuationRef === '') {
            $continuationRef = 'cont:'.$workItemId.':'.substr($actionHash, 0, 12);
        }

        $item = [
            'work_item_id' => $workItemId,
            'status' => self::STATUS_AWAITING_SOVEREIGN,
            'h_gates' => $hGates,
            'action_hash' => $actionHash,
            'continuation_ref' => $continuationRef,
            'reserved_effect' => false,
            'global_halt' => false,
            'decision_event_id' => null,
            'resume_count' => 0,
            'settled' => false,
            'claim_worker' => null,
            'progress_witnesses' => [],
        ];
        $this->items[$workItemId] = $item;
        $this->journal[] = [
            'event' => 'awaiting_sovereign',
            'work_item_id' => $workItemId,
            'reserved_effect' => false,
            'global_halt' => false,
        ];

        return [
            'schema' => self::SCHEMA,
            'ok' => true,
            'status' => self::STATUS_AWAITING_SOVEREIGN,
            'work_item' => $item,
            'blocks_unrelated_work' => false,
            'produces_reserved_effect' => false,
        ];
    }

    /**
     * Claim an unrelated work item while another awaits sovereign attention.
     *
     * @return array<string,mixed>
     */
    public function claim(string $workItemId, string $workerId, array $context = []): array
    {
        $workItemId = $this->requireId($workItemId, 'work_item_id');
        $workerId = trim($workerId);
        if ($workerId === '') {
            return $this->fail($workItemId, 'worker_id_required');
        }
        if ($this->hasGlobalHalt()) {
            return $this->fail($workItemId, 'global_halt_forbidden');
        }

        if (! isset($this->items[$workItemId])) {
            $actionHash = $this->requireHash((string) ($context['action_hash'] ?? hash('sha256', $workItemId.'|open')), 'action_hash');
            $this->items[$workItemId] = [
                'work_item_id' => $workItemId,
                'status' => self::STATUS_OPEN,
                'h_gates' => [],
                'action_hash' => $actionHash,
                'continuation_ref' => (string) ($context['continuation_ref'] ?? 'cont:'.$workItemId),
                'reserved_effect' => false,
                'global_halt' => false,
                'decision_event_id' => null,
                'resume_count' => 0,
                'settled' => false,
                'claim_worker' => null,
                'progress_witnesses' => [],
            ];
        }

        $item = $this->items[$workItemId];
        if (in_array($item['status'], [self::STATUS_AWAITING_SOVEREIGN, self::STATUS_SETTLED, self::STATUS_RESUMED], true)) {
            return $this->fail($workItemId, 'work_item_not_claimable:'.$item['status']);
        }
        if ($item['status'] === self::STATUS_CLAIMED || $item['status'] === self::STATUS_EXECUTED) {
            return $this->fail($workItemId, 'work_item_already_in_flight');
        }

        $item['status'] = self::STATUS_CLAIMED;
        $item['claim_worker'] = $workerId;
        $item['progress_witnesses'][] = 'claimed';
        $this->items[$workItemId] = $item;
        $this->journal[] = ['event' => 'claimed', 'work_item_id' => $workItemId, 'worker_id' => $workerId];

        return ['schema' => self::SCHEMA, 'ok' => true, 'status' => self::STATUS_CLAIMED, 'work_item' => $item];
    }

    /**
     * @return array<string,mixed>
     */
    public function execute(string $workItemId, string $workerId): array
    {
        $item = $this->items[$workItemId] ?? null;
        if ($item === null) {
            return $this->fail($workItemId, 'work_item_unknown');
        }
        if ($item['status'] !== self::STATUS_CLAIMED || ($item['claim_worker'] ?? null) !== $workerId) {
            return $this->fail($workItemId, 'execute_requires_active_claim');
        }
        $item['status'] = self::STATUS_EXECUTED;
        $item['progress_witnesses'][] = 'executed';
        $this->items[$workItemId] = $item;
        $this->journal[] = ['event' => 'executed', 'work_item_id' => $workItemId, 'worker_id' => $workerId];

        return ['schema' => self::SCHEMA, 'ok' => true, 'status' => self::STATUS_EXECUTED, 'work_item' => $item];
    }

    /**
     * Settle completed work (B path) — observable progress witness for R100.
     *
     * @param  array<string,mixed>  $settlement
     * @return array<string,mixed>
     */
    public function settle(string $workItemId, string $workerId, array $settlement = []): array
    {
        $item = $this->items[$workItemId] ?? null;
        if ($item === null) {
            return $this->fail($workItemId, 'work_item_unknown');
        }
        if ($item['status'] !== self::STATUS_EXECUTED || ($item['claim_worker'] ?? null) !== $workerId) {
            return $this->fail($workItemId, 'settle_requires_executed_claim');
        }
        $landedSha = trim((string) ($settlement['landed_sha'] ?? ''));
        if ($landedSha === '' || preg_match('/^[a-f0-9]{40,64}$/', $landedSha) !== 1) {
            return $this->fail($workItemId, 'settlement_landed_sha_required');
        }

        $item['status'] = self::STATUS_SETTLED;
        $item['settled'] = true;
        $item['settlement'] = [
            'landed_sha' => $landedSha,
            'settlement_event_id' => (string) ($settlement['settlement_event_id'] ?? 'settle:'.$workItemId),
            'observer_identity' => (string) ($settlement['observer_identity'] ?? 'path_core.settler'),
        ];
        $item['progress_witnesses'][] = 'settled';
        $this->items[$workItemId] = $item;
        $this->journal[] = [
            'event' => 'settled',
            'work_item_id' => $workItemId,
            'landed_sha' => $landedSha,
            'progress_witness' => true,
        ];

        return ['schema' => self::SCHEMA, 'ok' => true, 'status' => self::STATUS_SETTLED, 'work_item' => $item];
    }

    /**
     * DecisionIssued for a waiting item — enables exactly-once resume.
     *
     * @return array<string,mixed>
     */
    public function issueDecision(string $workItemId, string $decisionEventId): array
    {
        $item = $this->items[$workItemId] ?? null;
        if ($item === null) {
            return $this->fail($workItemId, 'work_item_unknown');
        }
        if ($item['status'] !== self::STATUS_AWAITING_SOVEREIGN) {
            return $this->fail($workItemId, 'decision_requires_awaiting_sovereign');
        }
        $decisionEventId = trim($decisionEventId);
        if ($decisionEventId === '') {
            return $this->fail($workItemId, 'decision_event_id_required');
        }
        $item['decision_event_id'] = $decisionEventId;
        $item['decision_issued'] = true;
        $item['progress_witnesses'][] = 'decision_issued';
        $this->items[$workItemId] = $item;
        $this->journal[] = [
            'event' => 'decision_issued',
            'work_item_id' => $workItemId,
            'decision_event_id' => $decisionEventId,
            'ledger_event_type' => 'DECISION_ISSUED',
        ];

        return ['schema' => self::SCHEMA, 'ok' => true, 'status' => self::STATUS_AWAITING_SOVEREIGN, 'work_item' => $item];
    }

    /**
     * Resume A exactly once under original action_hash + continuation_ref.
     *
     * @return array<string,mixed>
     */
    public function resume(string $workItemId, string $continuationRef, string $actionHash): array
    {
        $item = $this->items[$workItemId] ?? null;
        if ($item === null) {
            return $this->fail($workItemId, 'work_item_unknown');
        }
        if ($item['status'] !== self::STATUS_AWAITING_SOVEREIGN) {
            return $this->fail($workItemId, 'resume_requires_awaiting_sovereign');
        }
        if (! (bool) ($item['decision_issued'] ?? false) || trim((string) ($item['decision_event_id'] ?? '')) === '') {
            return $this->fail($workItemId, 'resume_requires_decision_issued');
        }
        if (! hash_equals((string) $item['continuation_ref'], $continuationRef)) {
            return $this->fail($workItemId, 'continuation_ref_mismatch');
        }
        if (! hash_equals((string) $item['action_hash'], $actionHash)) {
            return $this->fail($workItemId, 'action_hash_mismatch');
        }
        if ((int) $item['resume_count'] >= 1) {
            return $this->fail($workItemId, 'resume_exactly_once_violated');
        }

        $item['resume_count'] = 1;
        $item['status'] = self::STATUS_RESUMED;
        $item['progress_witnesses'][] = 'resumed_once';
        $this->items[$workItemId] = $item;
        $this->journal[] = [
            'event' => 'resumed',
            'work_item_id' => $workItemId,
            'continuation_ref' => $continuationRef,
            'action_hash' => $actionHash,
            'resume_count' => 1,
        ];

        return [
            'schema' => self::SCHEMA,
            'ok' => true,
            'status' => self::STATUS_RESUMED,
            'work_item' => $item,
            'resumed_exactly_once' => true,
        ];
    }

    /**
     * R100 two-item scenario driver (pure): A waits → B settles → A resumes once.
     *
     * @param  array<string,mixed>  $aContext
     * @param  array<string,mixed>  $bSettlement
     * @return array<string,mixed>
     */
    public function proveR100TwoWorkItemProgress(
        string $workItemA,
        string $workItemB,
        array $aContext,
        string $workerB,
        array $bSettlement,
        string $decisionEventIdForA,
    ): array {
        $aWait = $this->awaitSovereign($workItemA, $aContext);
        if (! ($aWait['ok'] ?? false)) {
            return ['ok' => false, 'phase' => 'await_a', 'result' => $aWait];
        }

        // While A waits: B must progress fully (claim → execute → settle).
        $bClaim = $this->claim($workItemB, $workerB, ['action_hash' => hash('sha256', $workItemB.'|b')]);
        $bExec = ($bClaim['ok'] ?? false) ? $this->execute($workItemB, $workerB) : $bClaim;
        $bSettle = ($bExec['ok'] ?? false) ? $this->settle($workItemB, $workerB, $bSettlement) : $bExec;
        if (! ($bSettle['ok'] ?? false)) {
            return ['ok' => false, 'phase' => 'progress_b', 'result' => $bSettle, 'a' => $aWait];
        }

        // A still waiting, no global halt, no reserved effect.
        $a = $this->items[$workItemA];
        if ($a['status'] !== self::STATUS_AWAITING_SOVEREIGN || $a['reserved_effect'] || $a['global_halt']) {
            return ['ok' => false, 'phase' => 'a_still_waiting', 'work_item_a' => $a];
        }

        $issued = $this->issueDecision($workItemA, $decisionEventIdForA);
        if (! ($issued['ok'] ?? false)) {
            return ['ok' => false, 'phase' => 'decision_a', 'result' => $issued];
        }

        $resume = $this->resume($workItemA, (string) $a['continuation_ref'], (string) $a['action_hash']);
        $second = $this->resume($workItemA, (string) $a['continuation_ref'], (string) $a['action_hash']);

        return [
            'schema' => self::SCHEMA,
            'ok' => ($resume['ok'] ?? false) === true && ($second['ok'] ?? true) === false,
            'rule' => 'R100',
            'a_wait' => $aWait,
            'b_settled' => $bSettle,
            'a_resumed' => $resume,
            'a_second_resume_blocked' => $second,
            'global_halt' => false,
            'progress_witness' => [
                'b_settled_before_a_decision' => true,
                'a_resumed_exactly_once' => ($resume['resumed_exactly_once'] ?? false) === true,
                'second_resume_refused' => ($second['ok'] ?? true) === false,
            ],
            'journal' => $this->journal,
        ];
    }

    /** @return array<string,mixed>|null */
    public function item(string $workItemId): ?array
    {
        return $this->items[$workItemId] ?? null;
    }

    /** @return list<array<string,mixed>> */
    public function journal(): array
    {
        return $this->journal;
    }

    public function hasGlobalHalt(): bool
    {
        foreach ($this->items as $item) {
            if ((bool) ($item['global_halt'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  mixed  $gates
     * @return list<string>
     */
    private function normalizeHGates(mixed $gates): array
    {
        if (! is_array($gates)) {
            return [];
        }
        $out = [];
        foreach ($gates as $g) {
            $g = strtoupper(trim((string) $g));
            if (in_array($g, self::H_GATES, true)) {
                $out[] = $g;
            }
        }

        return array_values(array_unique($out));
    }

    private function requireId(string $id, string $field): string
    {
        $id = trim($id);
        if ($id === '') {
            throw new \InvalidArgumentException($field.'_required');
        }

        return $id;
    }

    private function requireHash(string $hash, string $field): string
    {
        $hash = strtolower(trim($hash));
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            throw new \InvalidArgumentException($field.'_invalid');
        }

        return $hash;
    }

    /** @return array<string,mixed> */
    private function fail(string $workItemId, string $reason): array
    {
        $this->journal[] = ['event' => 'blocked', 'work_item_id' => $workItemId, 'reason' => $reason];

        return [
            'schema' => self::SCHEMA,
            'ok' => false,
            'status' => self::STATUS_BLOCKED,
            'work_item_id' => $workItemId,
            'reason' => $reason,
        ];
    }
}
