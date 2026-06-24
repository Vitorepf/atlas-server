<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Resilience\AtlasLoopRespawnPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Proves the respawn policy: refuses on every individual gate (master switch off, status≠running, budget
 * exhausted, cooldown not elapsed, supervisor alive+healthy), admits only when ALL pass AND the probe says
 * dead-or-hung, and never spawns a process — pure decision logic.
 */
final class AtlasLoopRespawnPolicyTest extends TestCase
{
    /**
     * A campaign that satisfies EVERY gate; tests override one key at a time to prove each refusal.
     *
     * @return array{
     *     campaign_id:string,status:string,budget_remaining_s:int,cooldown_elapsed_s:int,
     *     supervisor_pid:?int,supervisor_hung:bool
     * }
     */
    private function campaign(array $overrides = []): array
    {
        return array_merge([
            'campaign_id' => 'camp-1',
            'status' => 'running',
            'budget_remaining_s' => 3600,
            'cooldown_elapsed_s' => 9999,
            'supervisor_pid' => null,           // probe says dead
            'supervisor_hung' => false,
        ], $overrides);
    }

    private function policy(bool $masterOn = true, int $cooldown = 60): AtlasLoopRespawnPolicy
    {
        return new AtlasLoopRespawnPolicy(
            cooldownSeconds: $cooldown,
            masterSwitchEnabled: static fn (): bool => $masterOn,
        );
    }

    public function test_admits_when_every_gate_passes_and_supervisor_is_dead(): void
    {
        $decision = $this->policy()->decide($this->campaign());

        $this->assertTrue($decision['should_respawn']);
        $this->assertSame([AtlasLoopRespawnPolicy::REASON_ADMITTED], $decision['reasons']);
        $this->assertSame('atlas.loop.respawn_policy.v1', $decision['schema_version']);
        $this->assertSame('on', $decision['evidence']['master_switch']);
        $this->assertTrue($decision['evidence']['probe_says_dead_or_hung']);
    }

    public function test_refuses_when_master_switch_is_off(): void
    {
        $decision = $this->policy(masterOn: false)->decide($this->campaign());

        $this->assertFalse($decision['should_respawn']);
        $this->assertContains(AtlasLoopRespawnPolicy::REASON_MASTER_SWITCH_OFF, $decision['reasons']);
        $this->assertSame('off', $decision['evidence']['master_switch']);
    }

    public function test_refuses_when_status_is_not_running(): void
    {
        $decision = $this->policy()->decide($this->campaign(['status' => 'parked']));

        $this->assertFalse($decision['should_respawn']);
        $this->assertContains(AtlasLoopRespawnPolicy::REASON_STATUS_NOT_RUNNING, $decision['reasons']);
    }

    public function test_refuses_when_budget_is_exhausted(): void
    {
        $decision = $this->policy()->decide($this->campaign(['budget_remaining_s' => 0]));

        $this->assertFalse($decision['should_respawn']);
        $this->assertContains(AtlasLoopRespawnPolicy::REASON_BUDGET_EXHAUSTED, $decision['reasons']);
    }

    public function test_refuses_when_cooldown_window_has_not_elapsed(): void
    {
        $decision = $this->policy(cooldown: 60)->decide($this->campaign(['cooldown_elapsed_s' => 30]));

        $this->assertFalse($decision['should_respawn']);
        $this->assertContains(AtlasLoopRespawnPolicy::REASON_COOLDOWN_NOT_ELAPSED, $decision['reasons']);
    }

    public function test_refuses_when_supervisor_is_alive_and_healthy(): void
    {
        $decision = $this->policy()->decide($this->campaign(['supervisor_pid' => 1234, 'supervisor_hung' => false]));

        $this->assertFalse($decision['should_respawn']);
        $this->assertContains(AtlasLoopRespawnPolicy::REASON_SUPERVISOR_ALIVE_AND_HEALTHY, $decision['reasons']);
    }

    public function test_admits_when_supervisor_is_present_but_hung(): void
    {
        $decision = $this->policy()->decide($this->campaign(['supervisor_pid' => 1234, 'supervisor_hung' => true]));

        $this->assertTrue($decision['should_respawn'], 'a hung supervisor IS dead-or-hung — admit a respawn');
        $this->assertTrue($decision['evidence']['probe_says_dead_or_hung']);
    }

    public function test_accumulates_multiple_refusal_reasons(): void
    {
        $decision = $this->policy(masterOn: false)->decide($this->campaign([
            'status' => 'parked',
            'budget_remaining_s' => 0,
        ]));

        $this->assertFalse($decision['should_respawn']);
        $this->assertContains(AtlasLoopRespawnPolicy::REASON_MASTER_SWITCH_OFF, $decision['reasons']);
        $this->assertContains(AtlasLoopRespawnPolicy::REASON_STATUS_NOT_RUNNING, $decision['reasons']);
        $this->assertContains(AtlasLoopRespawnPolicy::REASON_BUDGET_EXHAUSTED, $decision['reasons']);
        $this->assertNotContains(AtlasLoopRespawnPolicy::REASON_ADMITTED, $decision['reasons']);
    }

    public function test_policy_source_never_launches_a_process(): void
    {
        $reflection = new ReflectionClass(AtlasLoopRespawnPolicy::class);
        $source = (string) file_get_contents($reflection->getFileName());

        foreach (['shell_exec', 'exec(', 'proc_open', 'popen(', 'passthru', 'system(', 'Symfony\\Component\\Process', 'Process::fromShellCommandline', 'pcntl_fork'] as $banned) {
            $this->assertStringNotContainsString($banned, $source, "respawn policy must NOT use $banned (it is pure decision logic)");
        }
    }
}
