<?php

declare(strict_types=1);

namespace App\Services\Ai\AgentGovernance;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * THE single source of run/respawn authority. The operator declares desired-state here; the reconciler reads
 * it; the keepalive reads it. Nothing else grants authority — a `status=running` DB row, a live process, a
 * launchd respawn all mean nothing without a matching desired-state ON.
 *
 * INVARIANTS:
 *   - DEFAULT OFF / FAIL-CLOSED. No row ⇒ OFF. A read that throws (missing table, DB blip) ⇒ OFF. Silence = off.
 *   - Mutation is OPERATOR-ONLY by construction: only {@see setOn}/{@see setOff} write, and they are called
 *     exclusively from operator-facing commands/endpoints — never from the loop/reconciler. The system
 *     converges TOWARD this state; it never authors it.
 *   - {@see authorizesCampaign} scopes loop respawn to the EXACT campaign the operator launched (target_ref).
 *     An orphan `running` row with a different id is never authorized — the precise cut of the root-cause bug.
 */
final class AtlasAgentDesiredStateStore
{
    public function __construct(private readonly ?AtlasAgentEventLedger $events = null) {}

    private function ledger(): AtlasAgentEventLedger
    {
        return $this->events ?? new AtlasAgentEventLedger();
    }

    /** Raw desired flag (ignores TTL/budget). Default false; fail-closed. */
    public function desired(string $key): bool
    {
        $record = $this->record($key);

        return $record !== null && $record->on;
    }

    /** Full desired-state record, or null when absent/unreadable. */
    public function record(string $key): ?AgentDesiredState
    {
        try {
            $row = DB::table('atlas_agent_desired_state')->where('agent_key', $key)->first();
            if ($row === null) {
                return null;
            }

            return new AgentDesiredState(
                agentKey: (string) $row->agent_key,
                on: (bool) $row->desired,
                setBy: $row->set_by !== null ? (string) $row->set_by : null,
                setAtEpoch: $row->set_at !== null ? strtotime((string) $row->set_at) : null,
                ttlExpiresAtEpoch: $row->ttl_expires_at !== null ? strtotime((string) $row->ttl_expires_at) : null,
                budgetLimitUsd: $row->budget_limit_usd !== null ? (float) $row->budget_limit_usd : null,
                targetRef: $row->target_ref !== null ? (string) $row->target_ref : null,
                reason: $row->reason !== null ? (string) $row->reason : null,
            );
        } catch (Throwable) {
            return null; // fail-CLOSED — an unreadable source means OFF, never "assume on"
        }
    }

    /**
     * Is this agent authorized to be running right now? ON and not braked (TTL/budget). Default false;
     * fail-closed. $spentUsd lets the caller (reconciler) enforce the budget FREIO with measured spend.
     */
    public function authorizes(string $key, float $spentUsd = 0.0): bool
    {
        $record = $this->record($key);
        if ($record === null) {
            return false;
        }

        return $record->effectivelyOn(now()->timestamp, $spentUsd);
    }

    /**
     * Respawn authority for a SPECIFIC loop campaign. True ONLY when the loop is desired-ON (not braked) AND
     * this campaign id is the one the operator explicitly launched (target_ref). A null target_ref authorizes
     * NO campaign — fail-closed, so a generic flag can never resurrect the campaign graveyard. THIS is what
     * the keepalive consults instead of "is the row status=running?".
     */
    public function authorizesCampaign(string $campaignId, float $spentUsd = 0.0): bool
    {
        $record = $this->record(AtlasFleetCatalog::LOOP);
        if ($record === null || ! $record->effectivelyOn(now()->timestamp, $spentUsd)) {
            return false;
        }

        return $record->targetRef !== null && $record->targetRef === $campaignId;
    }

    /**
     * Operator turns an agent ON. Records the FREIO (TTL/budget) and the exact target. Appends history.
     * Idempotent upsert on agent_key.
     */
    public function setOn(
        string $key,
        string $by = 'operator',
        ?int $ttlSeconds = null,
        ?float $budgetUsd = null,
        ?string $targetRef = null,
        ?string $reason = null,
    ): void {
        $now = now();
        $ttlExpiresAt = $ttlSeconds !== null && $ttlSeconds > 0 ? $now->copy()->addSeconds($ttlSeconds) : null;

        DB::table('atlas_agent_desired_state')->updateOrInsert(
            ['agent_key' => $key],
            [
                'desired' => true,
                'set_by' => $by,
                'set_at' => $now,
                'ttl_expires_at' => $ttlExpiresAt,
                'budget_limit_usd' => $budgetUsd,
                'target_ref' => $targetRef,
                'reason' => $reason,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        $this->ledger()->append(
            agentKey: $key,
            event: AtlasAgentEventLedger::EVENT_DESIRED_ON,
            by: $by,
            reason: $reason,
            account: AtlasFleetCatalog::get($key)?->account,
            detail: [
                'ttl_seconds' => $ttlSeconds,
                'ttl_expires_at' => $ttlExpiresAt?->toIso8601String(),
                'budget_limit_usd' => $budgetUsd,
                'target_ref' => $targetRef,
            ],
        );
    }

    /** Operator (or the FREIO, via the reconciler) turns an agent OFF. Clears the target. Appends history. */
    public function setOff(string $key, string $by = 'operator', ?string $reason = null): void
    {
        $now = now();

        DB::table('atlas_agent_desired_state')->updateOrInsert(
            ['agent_key' => $key],
            [
                'desired' => false,
                'set_by' => $by,
                'set_at' => $now,
                'ttl_expires_at' => null,
                'budget_limit_usd' => null,
                'target_ref' => null,
                'reason' => $reason,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        $this->ledger()->append(
            agentKey: $key,
            event: AtlasAgentEventLedger::EVENT_DESIRED_OFF,
            by: $by,
            reason: $reason,
            account: AtlasFleetCatalog::get($key)?->account,
        );
    }

    /** All desired-state records keyed by agent_key (only agents the operator has ever touched). */
    public function allRecords(): array
    {
        $out = [];
        foreach (AtlasFleetCatalog::keys() as $key) {
            $record = $this->record($key);
            if ($record !== null) {
                $out[$key] = $record;
            }
        }

        return $out;
    }
}
