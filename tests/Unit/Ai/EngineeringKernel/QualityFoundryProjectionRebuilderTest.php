<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\Quality\QualityFoundryEventContract;
use App\Services\Ai\EngineeringKernel\Quality\QualityFoundryProjectionRebuilder;
use PHPUnit\Framework\TestCase;

final class QualityFoundryProjectionRebuilderTest extends TestCase
{
    /** @return array<string,mixed> */
    private function event(string $name, string $key, array $extra = []): array
    {
        return array_merge([
            'schema' => QualityFoundryEventContract::SCHEMA,
            'event_name' => $name,
            'run_id' => 'run-1',
            'delivery_id' => 'delivery-1',
            'occurred_at' => '2026-07-12T00:00:00Z',
            'provenance' => 'quality-court',
            'idempotency_key' => $key,
            'correlated_hashes' => ['order' => hash('sha256', 'order')],
        ], $extra);
    }

    public function test_missing_events_rebuild_to_honest_unknown_and_ineligible_defaults(): void
    {
        $projection = (new QualityFoundryProjectionRebuilder)->rebuild([]);

        $this->assertSame('unknown', $projection['acceptance']['status']);
        $this->assertSame('not_authorized', $projection['release']['status']);
        $this->assertSame('unknown', $projection['outcome']['status']);
        $this->assertFalse($projection['claim_eligibility']['claim_eligible']);
    }

    public function test_rebuilds_role_acceptance_release_outcome_and_claim_from_events(): void
    {
        $events = [
            $this->event('role.disposition.recorded', 'r1', ['role' => 'builder', 'status' => 'pass']),
            $this->event('acceptance.adjudicated', 'a1', ['verdict' => 'passed']),
            $this->event('release.authorized', 'ra'),
            $this->event('release.landed', 'rl'),
            $this->event('outcome.observed', 'o1', ['status' => 'positive']),
            $this->event('claim.issued', 'c1'),
        ];

        $projection = (new QualityFoundryProjectionRebuilder)->rebuild($events);

        $this->assertSame('pass', $projection['roles']['builder']['status']);
        $this->assertSame('passed', $projection['acceptance']['status']);
        $this->assertSame('landed', $projection['release']['status']);
        $this->assertSame('positive', $projection['outcome']['status']);
        $this->assertTrue($projection['claim_eligibility']['claim_eligible']);
        $this->assertSame('issued', $projection['claim_eligibility']['status']);
    }

    public function test_revoke_is_authoritative_and_projection_drift_is_visible(): void
    {
        $events = [$this->event('claim.issued', 'c1'), $this->event('claim.revoked', 'c2')];
        $rebuilder = new QualityFoundryProjectionRebuilder;
        $projection = $rebuilder->rebuild($events);

        $this->assertFalse($projection['claim_eligibility']['claim_eligible']);
        $this->assertSame('revoked', $projection['claim_eligibility']['status']);
        $this->assertSame('drift', $rebuilder->compare($events, ['claim_eligibility' => ['status' => 'issued']])['status']);
    }
}
