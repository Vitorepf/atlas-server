<?php

declare(strict_types=1);

namespace App\Services\Ai\AgentGovernance;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Append-only history of fleet governance events — every desired-state flip, every start/stop/expire the
 * reconciler performs. This is the "histórico dos loops" the apps render, and the audit trail proving
 * nothing ran without an operator action behind it.
 *
 * Append is FAIL-SAFE: history must never break the control plane. A write that throws is swallowed
 * (governance correctness does not depend on the ledger succeeding).
 */
final class AtlasAgentEventLedger
{
    public const EVENT_DESIRED_ON = 'desired_on';
    public const EVENT_DESIRED_OFF = 'desired_off';
    public const EVENT_STARTED = 'started';
    public const EVENT_STOPPED = 'stopped';
    public const EVENT_EXPIRED = 'expired';

    /**
     * @param  array<string,mixed>|null  $detail
     */
    public function append(
        string $agentKey,
        string $event,
        ?string $by = null,
        ?string $reason = null,
        ?string $account = null,
        ?int $pid = null,
        ?int $durationSeconds = null,
        ?array $detail = null,
    ): void {
        try {
            DB::table('atlas_agent_events')->insert([
                'agent_key' => $agentKey,
                'event' => $event,
                'at' => now(),
                'by' => $by,
                'account' => $account,
                'pid' => $pid,
                'duration_seconds' => $durationSeconds,
                'reason' => $reason,
                'detail' => $detail !== null ? json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // history is best-effort; never let it break governance
        }
    }

    /**
     * Most-recent events first. Fail-safe: returns [] if the table is unavailable.
     *
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit = 100, ?string $agentKey = null): array
    {
        try {
            $q = DB::table('atlas_agent_events')->orderByDesc('at')->orderByDesc('id');
            if ($agentKey !== null) {
                $q->where('agent_key', $agentKey);
            }

            return $q->limit(max(1, $limit))->get()->map(static function (object $r): array {
                return [
                    'agent_key' => (string) $r->agent_key,
                    'event' => (string) $r->event,
                    'at' => (string) $r->at,
                    'by' => $r->by !== null ? (string) $r->by : null,
                    'account' => $r->account !== null ? (string) $r->account : null,
                    'pid' => $r->pid !== null ? (int) $r->pid : null,
                    'duration_seconds' => $r->duration_seconds !== null ? (int) $r->duration_seconds : null,
                    'reason' => $r->reason !== null ? (string) $r->reason : null,
                    'detail' => $r->detail !== null ? json_decode((string) $r->detail, true) : null,
                ];
            })->all();
        } catch (Throwable) {
            return [];
        }
    }
}
