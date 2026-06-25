<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\ProviderNegotiation;

use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\AtlasMaestroProviderBidProposer;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\InvalidProviderException;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\ProviderProfile;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\TaskEnvelope;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroProviderBidProposerTest extends TestCase
{
    public function test_propose_returns_one_deterministic_bid_per_profile(): void
    {
        $task = $this->taskEnvelope();
        $profiles = [$this->profile('local-a'), $this->profile('remote-b', locality: 'remote')];

        $first = (new AtlasMaestroProviderBidProposer)->propose($task, $profiles);
        $second = (new AtlasMaestroProviderBidProposer)->propose($task, $profiles);

        $this->assertCount(2, $first->bids);
        $this->assertSame($first->toArray(), $second->toArray());
        $this->assertSame($first->bids[0]->bidHash, $second->bids[0]->bidHash);
    }

    public function test_ineligibility_reasons_cover_sensitivity_locality_capability_and_load(): void
    {
        $task = new TaskEnvelope(
            taskId: 't-secret',
            kind: 'loop',
            requiredCapabilities: ['php', 'rollback'],
            deadline: '2026-06-24T08:00:00Z',
            localOnly: true,
            sensitivityClass: 'secret',
        );

        $profile = new ProviderProfile(
            providerId: 'remote-public',
            declaredCapabilities: ['php'],
            observedCostPerTokenIn: 1.0,
            observedCostPerTokenOut: 1.0,
            observedP50LatencyMs: 100,
            currentLoadPct: 100,
            locality: 'remote',
            sensitivityAllowed: ['public'],
        );

        $bid = (new AtlasMaestroProviderBidProposer)->propose($task, [$profile])->bids[0];

        $this->assertFalse($bid->eligibilityBool);
        $this->assertSame(50, $bid->capabilityScore);
        $this->assertSame(
            ['locality_violation', 'sensitivity_violation', 'capability_missing', 'load_saturated'],
            $bid->ineligibilityReasons,
        );
    }

    public function test_capability_score_ignores_self_declared_strength_fields(): void
    {
        $task = new TaskEnvelope(
            taskId: 't-score',
            kind: 'maestro',
            requiredCapabilities: ['rollback'],
            deadline: '2026-06-24T08:00:00Z',
            localOnly: false,
            sensitivityClass: 'public',
        );

        $profile = new ProviderProfile(
            providerId: 'boaster',
            declaredCapabilities: ['other'],
            observedCostPerTokenIn: 1.0,
            observedCostPerTokenOut: 1.0,
            observedP50LatencyMs: 100,
            currentLoadPct: 0,
            locality: 'remote',
            sensitivityAllowed: ['public'],
            extras: ['strength' => 99],
        );

        $bid = (new AtlasMaestroProviderBidProposer)->propose($task, [$profile])->bids[0];

        $this->assertSame(0, $bid->capabilityScore);
        $this->assertFalse($bid->eligibilityBool);
    }

    public function test_unknown_provider_reference_throws_invalid_provider_exception(): void
    {
        $task = new TaskEnvelope(
            taskId: 't-specific',
            kind: 'loop',
            requiredCapabilities: ['php'],
            deadline: '2026-06-24T08:00:00Z',
            localOnly: false,
            sensitivityClass: 'public',
            providerIds: ['missing-provider'],
        );

        $this->expectException(InvalidProviderException::class);

        (new AtlasMaestroProviderBidProposer)->propose($task, [$this->profile('known-provider')]);
    }

    private function taskEnvelope(): TaskEnvelope
    {
        return new TaskEnvelope(
            taskId: 't-1',
            kind: 'loop',
            requiredCapabilities: ['php', 'tests'],
            deadline: '2026-06-24T08:00:00Z',
            localOnly: false,
            sensitivityClass: 'public',
        );
    }

    private function profile(string $providerId, string $locality = 'local'): ProviderProfile
    {
        return new ProviderProfile(
            providerId: $providerId,
            declaredCapabilities: ['php', 'tests'],
            observedCostPerTokenIn: 2.0,
            observedCostPerTokenOut: 1.0,
            observedP50LatencyMs: 100,
            currentLoadPct: 25,
            locality: $locality,
            sensitivityAllowed: ['public', 'secret'],
        );
    }
}
