<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\ProviderNegotiation;

use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\AtlasMaestroProviderBidProposer;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\InvalidProviderException;
use App\Services\Ai\SelfConstruction\Maestro\ProviderNegotiation\ProviderBid;
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
            extras: ['tier' => 'hard'], // meets tier requirement so only the 4 target reasons fire
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

    public function test_tier_fit_ineligible_when_provider_tier_below_task_minimum(): void
    {
        $task = new TaskEnvelope(
            taskId: 't-tier',
            kind: 'loop',            // loop requires min tier=hard
            requiredCapabilities: ['php'],
            deadline: '2026-06-30T10:00:00Z',
            localOnly: false,
            sensitivityClass: 'public',
        );
        $easyProfile = new ProviderProfile(
            providerId: 'easy-worker',
            declaredCapabilities: ['php'],
            observedCostPerTokenIn: 1.0,
            observedCostPerTokenOut: 1.0,
            observedP50LatencyMs: 100,
            currentLoadPct: 0,
            locality: 'local',
            sensitivityAllowed: ['public'],
            extras: ['tier' => 'easy'],  // easy < hard → mismatch
        );

        $bid = (new AtlasMaestroProviderBidProposer)->propose($task, [$easyProfile])->bids[0];

        $this->assertFalse($bid->eligibilityBool);
        $this->assertContains('tier_mismatch', $bid->ineligibilityReasons);
    }

    public function test_evidence_burden_ineligible_when_provider_capacity_below_task_need(): void
    {
        $task = new TaskEnvelope(
            taskId: 't-ev',
            kind: 'loop',    // loop burden=80
            requiredCapabilities: ['php'],
            deadline: '2026-06-30T10:00:00Z',
            localOnly: false,
            sensitivityClass: 'public',
        );
        $lowCapProfile = new ProviderProfile(
            providerId: 'light-worker',
            declaredCapabilities: ['php'],
            observedCostPerTokenIn: 1.0,
            observedCostPerTokenOut: 1.0,
            observedP50LatencyMs: 100,
            currentLoadPct: 0,
            locality: 'local',
            sensitivityAllowed: ['public'],
            extras: ['tier' => 'hard', 'evidence_burden_max' => 50],  // 50 < 80 → exceeded
        );

        $bid = (new AtlasMaestroProviderBidProposer)->propose($task, [$lowCapProfile])->bids[0];

        $this->assertFalse($bid->eligibilityBool);
        $this->assertContains('evidence_burden_exceeded', $bid->ineligibilityReasons);
    }

    public function test_recent_failure_penalty_increases_declared_cost(): void
    {
        $task = new TaskEnvelope(
            taskId: 't-fail',
            kind: 'loop',
            requiredCapabilities: ['php'],
            deadline: '2026-06-30T10:00:00Z',
            localOnly: false,
            sensitivityClass: 'public',
        );
        $clean = new ProviderProfile(
            providerId: 'clean',
            declaredCapabilities: ['php'],
            observedCostPerTokenIn: 1.0,
            observedCostPerTokenOut: 1.0,
            observedP50LatencyMs: 100,
            currentLoadPct: 0,
            locality: 'local',
            sensitivityAllowed: ['public'],
            extras: ['tier' => 'hard'],
        );
        $flaky = new ProviderProfile(
            providerId: 'flaky',
            declaredCapabilities: ['php'],
            observedCostPerTokenIn: 1.0,
            observedCostPerTokenOut: 1.0,
            observedP50LatencyMs: 100,
            currentLoadPct: 0,
            locality: 'local',
            sensitivityAllowed: ['public'],
            extras: ['tier' => 'hard', 'recent_failure_count' => 3],  // +30% cost
        );

        $cleanBid = (new AtlasMaestroProviderBidProposer)->propose($task, [$clean])->bids[0];
        $flakyBid = (new AtlasMaestroProviderBidProposer)->propose($task, [$flaky])->bids[0];

        $this->assertGreaterThan($cleanBid->declaredCostUnits, $flakyBid->declaredCostUnits, 'failure penalty must inflate cost');
    }

    public function test_no_eligible_worker_when_all_providers_ineligible(): void
    {
        $task = new TaskEnvelope(
            taskId: 't-noeleg',
            kind: 'loop',
            requiredCapabilities: ['php'],
            deadline: '2026-06-30T10:00:00Z',
            localOnly: true,   // force locality violation for remote provider
            sensitivityClass: 'public',
        );
        $ineligible = new ProviderProfile(
            providerId: 'remote-only',
            declaredCapabilities: ['php'],
            observedCostPerTokenIn: 1.0,
            observedCostPerTokenOut: 1.0,
            observedP50LatencyMs: 100,
            currentLoadPct: 0,
            locality: 'remote',
            sensitivityAllowed: ['public'],
            extras: ['tier' => 'hard'],
        );

        $bidSet = (new AtlasMaestroProviderBidProposer)->propose($task, [$ineligible]);

        $this->assertCount(1, $bidSet->bids);
        $this->assertFalse($bidSet->bids[0]->eligibilityBool);
        $eligible = array_filter($bidSet->bids, fn (ProviderBid $b) => $b->eligibilityBool);
        $this->assertCount(0, $eligible, 'no eligible worker must surface as empty eligible set');
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
