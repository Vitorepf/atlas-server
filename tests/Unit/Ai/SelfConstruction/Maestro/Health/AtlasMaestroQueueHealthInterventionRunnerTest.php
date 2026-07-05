<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Health;

use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroQueueHealthInterventionRunner;
use Tests\TestCase;

final class AtlasMaestroQueueHealthInterventionRunnerTest extends TestCase
{
    private function runner(): AtlasMaestroQueueHealthInterventionRunner
    {
        return new AtlasMaestroQueueHealthInterventionRunner;
    }

    // ── Acceptance: healthy queue yields empty intervention plan ──────────────

    public function test_healthy_queue_yields_empty_intervention_plan(): void
    {
        $snapshot = $this->emptySnapshot();

        $result = $this->runner()->run($snapshot);

        $this->assertSame(AtlasMaestroQueueHealthInterventionRunner::SCHEMA, $result['schema']);
        $this->assertTrue($result['healthy']);
        $this->assertSame(0, $result['intervention_count']);
        $this->assertSame([], $result['interventions']);
    }

    // ── Acceptance: starving queue with old head yields rotation intervention ─

    public function test_starving_queue_with_old_head_yields_rotation_intervention(): void
    {
        $snapshot = $this->starvingSnapshot();

        $result = $this->runner()->run($snapshot);

        $this->assertFalse($result['healthy']);
        $this->assertGreaterThan(0, $result['intervention_count']);

        $rotationTypes = array_filter(
            $result['interventions'],
            static fn (array $iv): bool => ($iv['type'] ?? '') === AtlasMaestroQueueHealthInterventionRunner::INTERVENTION_TYPE_ROTATION,
        );
        $this->assertGreaterThan(0, count($rotationTypes));

        $rotation = array_values($rotationTypes)[0];
        $this->assertStringContainsString('rotate_oldest', $rotation['summary']);
        $this->assertGreaterThan(0, $rotation['priority_score']);
        $this->assertArrayHasKey('details', $rotation);
    }

    // ── Acceptance: poison-risk packet yields poison-hold and quarantine burn-down ─

    public function test_poison_risk_packet_yields_poison_hold_and_quarantine_burn_down(): void
    {
        $snapshot = $this->poisonSnapshot();

        $result = $this->runner()->run($snapshot);

        $this->assertFalse($result['healthy']);
        $this->assertGreaterThan(0, $result['intervention_count']);

        // poison hold intervention exists
        $poisonTypes = array_filter(
            $result['interventions'],
            static fn (array $iv): bool => ($iv['type'] ?? '') === AtlasMaestroQueueHealthInterventionRunner::INTERVENTION_TYPE_POISON_HOLD,
        );
        $this->assertGreaterThan(0, count($poisonTypes));

        // quarantine burn-down intervention exists via the scheduler
        $quarantineTypes = array_filter(
            $result['interventions'],
            static fn (array $iv): bool => ($iv['type'] ?? '') === AtlasMaestroQueueHealthInterventionRunner::INTERVENTION_TYPE_QUARANTINE_BURN_DOWN,
        );
        $this->assertGreaterThan(0, count($quarantineTypes));

        // poison runner organ counts confirm the risk category
        $this->assertGreaterThan(0, $result['organs']['poison_warning']['medium_risk_count'] + $result['organs']['poison_warning']['high_risk_count']);
    }

    // ── Snapshot builders ─────────────────────────────────────────────────────

    /**
     * A snapshot with no data at all — every extractor returns empty, so
     * the runner yields zero interventions and healthy=true.
     *
     * @return array<string, mixed>
     */
    private function emptySnapshot(): array
    {
        return [
            'claimable_depth' => 0,
            'active_leases' => 0,
            'oldest_age_minutes' => 0,
            'blocked_packets' => [],
            'claimable_packets' => [],
            'quarantined_packets' => [],
            'stale_claimable_facts' => [],
            'decay_join_packets' => [],
        ];
    }

    /**
     * A starving queue with:
     *   - deep claimable backlog
     *   - high oldest-age p95 (wide of the 60min threshold)
     *   - no observed consumption
     *   - active oldest packet IDs to surface
     *
     * The rotation advisor should diagnose rotate_oldest, and the stale rescue
     * lens should also flag the stale drain. Both yield interventions.
     *
     * @return array<string, mixed>
     */
    private function starvingSnapshot(): array
    {
        return [
            'claimable_depth' => 35,
            'active_leases' => 0,
            'oldest_age_minutes' => 120.0,
            'serve_rate_per_minute' => 0.0,
            'oldest_packet_ids' => ['pk-stale-1', 'pk-stale-2'],
            'blocked_packets' => [],
            'claimable_packets' => [],
            'quarantined_packets' => [],
            'stale_claimable_facts' => [
                'claimable_depth' => 35,
                'oldest_age_p95_seconds' => 7200,
                'stale_threshold_seconds' => 3600,
                'observed_consumption_count' => 0,
                'active_workers' => 0,
                'suspected_stuck_lease_count' => 0,
                'near_expiry_lease_count' => 0,
                'avoided_family_signals' => [],
            ],
            'decay_join_packets' => [
                [
                    'task_packet_id' => 'pk-stale-1',
                    'packet_age_facts' => ['age_seconds' => 7200],
                    'packet_value_facts' => ['value_score' => 0.8],
                    'give_back_risk_facts' => ['give_back_count' => 0, 'malformed_count' => 0],
                    'blocked_family_facts' => ['is_blocked_family' => false],
                ],
            ],
        ];
    }

    /**
     * A queue snapshot with poison-risk packets:
     *   - claimable packets carrying poison signals (test_only_has_contract
     *     scores high, contradictory_acceptance scores medium)
     *   - quarantined packets from the scheduler run
     *
     * @return array<string, mixed>
     */
    private function poisonSnapshot(): array
    {
        return [
            'claimable_depth' => 5,
            'active_leases' => 2,
            'oldest_age_minutes' => 10.0,
            'serve_rate_per_minute' => 3.0,
            'oldest_packet_ids' => [],
            'blocked_packets' => [],
            'claimable_packets' => [
                [
                    'allowed_files' => ['tests/Unit/SomeTest.php'],
                    'quality_facts' => ['test_only_has_contract' => true],
                    'give_back_count' => 0,
                ],
                [
                    'allowed_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
                    'quality_facts' => [],
                    'blocking_deficiencies' => ['contradictory_acceptance'],
                    'give_back_count' => 0,
                ],
            ],
            'quarantined_packets' => [
                [
                    'packet_id' => 'pk-quar-1',
                    'is_self_target' => true,
                    'poison_attempt_count' => 0,
                    'requires_operator_decision' => false,
                    'has_sensitive_data' => false,
                    'spec_is_ambiguous' => false,
                    'out_of_scope_files' => false,
                    'blocker_description' => 'forbidden self-target',
                ],
            ],
            'stale_claimable_facts' => [],
            'decay_join_packets' => [],
        ];
    }
}
