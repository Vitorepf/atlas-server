<?php

declare(strict_types=1);

namespace Tests\Support\AgentGovernance;

use App\Services\Ai\AgentGovernance\FleetDriver;

/**
 * Deterministic in-memory {@see FleetDriver} for proving the reconciler/registry logic WITHOUT touching a
 * real process — the whole point of the seam: the invariant ("OFF resists respawn; ON keeps alive; FREIO
 * brakes") is proven by phpunit, never by launching a campaign that would burn provider quota.
 */
final class FakeFleetDriver implements FleetDriver
{
    /** @var array<string,bool> */
    public array $alive = [];

    /** @var array<string,float> */
    public array $spent = [];

    /** @var list<array{key:string,target:?string}> */
    public array $startCalls = [];

    /** @var list<string> */
    public array $stopCalls = [];

    public function isAlive(string $agentKey): bool
    {
        return $this->alive[$agentKey] ?? false;
    }

    public function pids(string $agentKey): array
    {
        return ($this->alive[$agentKey] ?? false) ? [4242] : [];
    }

    public function startedAtEpoch(string $agentKey): ?int
    {
        return ($this->alive[$agentKey] ?? false) ? now()->timestamp - 120 : null;
    }

    public function spentUsd(string $agentKey): float
    {
        return $this->spent[$agentKey] ?? 0.0;
    }

    public function start(string $agentKey, ?string $targetRef): void
    {
        $this->startCalls[] = ['key' => $agentKey, 'target' => $targetRef];
        $this->alive[$agentKey] = true;
    }

    public function stop(string $agentKey): void
    {
        $this->stopCalls[] = $agentKey;
        $this->alive[$agentKey] = false;
    }

    public function startedKeys(): array
    {
        return array_map(static fn (array $c): string => $c['key'], $this->startCalls);
    }
}
