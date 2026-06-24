<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Resilience;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;

/**
 * RESPAWN POLICY — pure decision logic that says WHETHER a campaign supervisor or grind should be respawned,
 * given the topology probe (packet 01), the hung-grind detector verdict (packet 02), and the zombie reaper
 * receipt (packet 03). It DOES NOT exec — execution stays in AtlasLoopKeepaliveCommand and the bash watchdog;
 * this class isolates POLICY from EXECUTION so the decision is deterministic, testable, and provider-free.
 *
 * GATES (every one is fail-closed; the first one that says no wins):
 *  - {@see AtlasLoopMasterSwitch::enabled()} OFF      ⇒ refuse  (matches bin/atlas-loop-watchdog.sh contract)
 *  - campaign.status != 'running'                     ⇒ refuse
 *  - campaign.budget_remaining_s <= 0                 ⇒ refuse  (no respawning a budget-exhausted campaign)
 *  - cooldown not elapsed since last respawn          ⇒ refuse  (anti-flap)
 *  - the supervisor pid is present AND not hung       ⇒ refuse  (nothing to respawn)
 *
 * Only when every gate passes AND the supervisor is absent or hung is `should_respawn = true`. The reply
 * always carries a `reasons` array (one slug per refusal) and an evidence vector — so the caller can audit the
 * decision without re-running it.
 */
final class AtlasLoopRespawnPolicy
{
    public const SCHEMA_VERSION = 'atlas.loop.respawn_policy.v1';

    public const REASON_MASTER_SWITCH_OFF = 'master_switch_off';

    public const REASON_STATUS_NOT_RUNNING = 'status_not_running';

    public const REASON_BUDGET_EXHAUSTED = 'budget_exhausted';

    public const REASON_COOLDOWN_NOT_ELAPSED = 'cooldown_not_elapsed';

    public const REASON_SUPERVISOR_ALIVE_AND_HEALTHY = 'supervisor_alive_and_healthy';

    public const REASON_ADMITTED = 'admitted';

    /** @param callable():bool|null $masterSwitchEnabled override for testing — defaults to the static gate */
    public function __construct(
        private readonly int $cooldownSeconds = 60,
        private $masterSwitchEnabled = null,
    ) {
    }

    /**
     * @param  array{
     *     campaign_id:string,
     *     status:string,
     *     budget_remaining_s:int,
     *     cooldown_elapsed_s:int,
     *     supervisor_pid:?int,
     *     supervisor_hung:bool
     * }  $campaign
     * @return array{
     *     schema_version:string,
     *     campaign_id:string,
     *     should_respawn:bool,
     *     reasons:list<string>,
     *     evidence:array{
     *         master_switch:string,
     *         status:string,
     *         budget_remaining_s:int,
     *         cooldown_elapsed_s:int,
     *         cooldown_required_s:int,
     *         supervisor_pid:?int,
     *         supervisor_hung:bool,
     *         probe_says_dead_or_hung:bool
     *     }
     * }
     */
    public function decide(array $campaign): array
    {
        $masterOn = $this->masterSwitchOn();
        $status = (string) ($campaign['status'] ?? '');
        $budgetRemaining = (int) ($campaign['budget_remaining_s'] ?? 0);
        $cooldownElapsed = (int) ($campaign['cooldown_elapsed_s'] ?? 0);
        $supervisorPid = $campaign['supervisor_pid'] ?? null;
        if ($supervisorPid !== null) {
            $supervisorPid = (int) $supervisorPid;
        }
        $supervisorHung = (bool) ($campaign['supervisor_hung'] ?? false);
        $probeSaysDeadOrHung = $supervisorPid === null || $supervisorHung;

        $reasons = [];
        if (! $masterOn) {
            $reasons[] = self::REASON_MASTER_SWITCH_OFF;
        }
        if ($status !== 'running') {
            $reasons[] = self::REASON_STATUS_NOT_RUNNING;
        }
        if ($budgetRemaining <= 0) {
            $reasons[] = self::REASON_BUDGET_EXHAUSTED;
        }
        if ($cooldownElapsed < $this->cooldownSeconds) {
            $reasons[] = self::REASON_COOLDOWN_NOT_ELAPSED;
        }
        if (! $probeSaysDeadOrHung) {
            $reasons[] = self::REASON_SUPERVISOR_ALIVE_AND_HEALTHY;
        }

        $shouldRespawn = $reasons === [];
        if ($shouldRespawn) {
            $reasons[] = self::REASON_ADMITTED;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'campaign_id' => (string) ($campaign['campaign_id'] ?? ''),
            'should_respawn' => $shouldRespawn,
            'reasons' => $reasons,
            'evidence' => [
                'master_switch' => $masterOn ? 'on' : 'off',
                'status' => $status,
                'budget_remaining_s' => $budgetRemaining,
                'cooldown_elapsed_s' => $cooldownElapsed,
                'cooldown_required_s' => $this->cooldownSeconds,
                'supervisor_pid' => $supervisorPid,
                'supervisor_hung' => $supervisorHung,
                'probe_says_dead_or_hung' => $probeSaysDeadOrHung,
            ],
        ];
    }

    private function masterSwitchOn(): bool
    {
        $override = $this->masterSwitchEnabled;
        if (is_callable($override)) {
            return (bool) $override();
        }

        return AtlasLoopMasterSwitch::enabled();
    }
}
