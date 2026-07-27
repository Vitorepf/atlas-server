<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Replenisher;

use App\Services\Ai\SelfConstruction\Replenisher\AtlasSelfConstructionNativeReplenisherPreflight;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionNativeReplenisherPreflight: an accepted packet (self_sufficient=true, no
 * blocking_deficiencies) goes into 'accepted'; a packet with hard-reject deficiency
 * (forbidden_self_target) goes into 'rejected'; a packet with repairable deficiency
 * (missing_acceptance_criteria) goes into 'repairable' with the right repair hint.
 */
final class AtlasSelfConstructionNativeReplenisherPreflightTest extends TestCase
{
    /**
     * Build a stub inspector — uses an anonymous class instead of extending the final inspector.
     * The preflight only calls $inspector->inspect($packet), so the stub satisfies the surface.
     */
    private function stubInspector(array $verdictsByFrontier): object
    {
        return new class($verdictsByFrontier)
        {
            public function __construct(private readonly array $verdicts) {}

            public function inspect(array $packet): array
            {
                $id = (string) ($packet['frontier_id'] ?? '');

                return $this->verdicts[$id] ?? ['self_sufficient' => true, 'blocking_deficiencies' => []];
            }
        };
    }

    public function test_accepted_packet_lands_in_accepted_bucket(): void
    {
        $packets = [['frontier_id' => 'f-clean']];
        $inspector = $this->stubInspector(['f-clean' => ['self_sufficient' => true, 'blocking_deficiencies' => []]]);
        $r = (new AtlasSelfConstructionNativeReplenisherPreflight($inspector))->preflight($packets);
        $this->assertCount(1, $r['accepted']);
        $this->assertSame([], $r['rejected']);
        $this->assertSame([], $r['repairable']);
    }

    public function test_packet_with_hard_reject_deficiency_lands_in_rejected_bucket(): void
    {
        $packets = [['frontier_id' => 'f-bad']];
        $inspector = $this->stubInspector(['f-bad' => ['self_sufficient' => false, 'blocking_deficiencies' => ['forbidden_self_target_in_allowed_files']]]);
        $r = (new AtlasSelfConstructionNativeReplenisherPreflight($inspector))->preflight($packets);
        $this->assertCount(1, $r['rejected']);
        $this->assertContains('forbidden_self_target_in_allowed_files', $r['rejected'][0]['rejection_reasons']);
    }

    public function test_packet_with_repairable_deficiency_lands_in_repairable_bucket_with_hint(): void
    {
        $packets = [['frontier_id' => 'f-rep']];
        $inspector = $this->stubInspector(['f-rep' => ['self_sufficient' => false, 'blocking_deficiencies' => ['missing_acceptance_criteria']]]);
        $r = (new AtlasSelfConstructionNativeReplenisherPreflight($inspector))->preflight($packets);
        $this->assertCount(1, $r['repairable']);
        $this->assertContains('add_at_least_one_acceptance_criterion', $r['repairable'][0]['repair_hints']);
    }

    public function test_multiple_packets_route_to_their_correct_buckets(): void
    {
        $packets = [
            ['frontier_id' => 'f-ok'],
            ['frontier_id' => 'f-rep'],
            ['frontier_id' => 'f-bad'],
        ];
        $inspector = $this->stubInspector([
            'f-ok' => ['self_sufficient' => true, 'blocking_deficiencies' => []],
            'f-rep' => ['self_sufficient' => false, 'blocking_deficiencies' => ['missing_required_evidence']],
            'f-bad' => ['self_sufficient' => false, 'blocking_deficiencies' => ['forbidden_self_target_in_allowed_files']],
        ]);
        $r = (new AtlasSelfConstructionNativeReplenisherPreflight($inspector))->preflight($packets);
        $this->assertCount(1, $r['accepted']);
        $this->assertCount(1, $r['repairable']);
        $this->assertCount(1, $r['rejected']);
    }

    public function test_no_known_repair_hint_hard_rejects_as_unknown_deficiency(): void
    {
        $packets = [['frontier_id' => 'f-mystery']];
        $inspector = $this->stubInspector(['f-mystery' => ['self_sufficient' => false, 'blocking_deficiencies' => ['some_brand_new_one']]]);
        $r = (new AtlasSelfConstructionNativeReplenisherPreflight($inspector))->preflight($packets);
        $this->assertSame([], $r['repairable']);
        $this->assertCount(1, $r['rejected']);
        $this->assertContains('unknown_deficiency:some_brand_new_one', $r['rejected'][0]['rejection_reasons']);
    }

    public function test_bare_directory_and_scope_incoherent_repair_hints(): void
    {
        $packets = [['frontier_id' => 'f-dir'], ['frontier_id' => 'f-scope']];
        $inspector = $this->stubInspector([
            'f-dir' => ['self_sufficient' => false, 'blocking_deficiencies' => ['bare_directory_in_allowed_files']],
            'f-scope' => ['self_sufficient' => false, 'blocking_deficiencies' => ['scope_incoherent']],
        ]);
        $r = (new AtlasSelfConstructionNativeReplenisherPreflight($inspector))->preflight($packets);
        $this->assertCount(2, $r['repairable']);
        $this->assertContains('replace_bare_directory_with_concrete_file_paths', $r['repairable'][0]['repair_hints']);
        $this->assertContains('narrow_scope_in_to_match_allowed_files', $r['repairable'][1]['repair_hints']);
    }

    public function test_simplicity_contract_violation_and_isolation_violation_hard_reject(): void
    {
        $packets = [['frontier_id' => 'f-simplicity'], ['frontier_id' => 'f-isolation']];
        $inspector = $this->stubInspector([
            'f-simplicity' => ['self_sufficient' => false, 'blocking_deficiencies' => ['simplicity_contract_violation']],
            'f-isolation' => ['self_sufficient' => false, 'blocking_deficiencies' => ['isolation_violation']],
        ]);
        $r = (new AtlasSelfConstructionNativeReplenisherPreflight($inspector))->preflight($packets);
        $this->assertCount(2, $r['rejected']);
        $this->assertContains('isolation_violation', $r['rejected'][0]['rejection_reasons']);
        $this->assertContains('simplicity_contract_violation', $r['rejected'][1]['rejection_reasons']);
    }

    // ── AC: preflightWaitDecision — wait_override / queue_depth / worker_drain / quality_gate / duplicate_pressure / evidence_ready ──

    public function test_normal_wait_with_no_drain_risk_and_clean_quality_does_not_override(): void
    {
        $r = (new AtlasSelfConstructionNativeReplenisherPreflight)->preflightWaitDecision([]);

        $this->assertFalse($r['wait_override']);
        $this->assertTrue($r['quality_gate']);
        $this->assertSame(0, $r['queue_depth']);
        $this->assertFalse($r['worker_drain']);
    }

    public function test_worker_starvation_with_clean_quality_safely_overrides(): void
    {
        $r = (new AtlasSelfConstructionNativeReplenisherPreflight)->preflightWaitDecision([
            'worker_floor_breach' => true,
            'claimable_depth' => 1,
            'duplicate_pressure' => 0.1,
            'evidence_ready' => true,
        ]);

        $this->assertTrue($r['wait_override']);
        $this->assertTrue($r['worker_drain']);
        $this->assertTrue($r['quality_gate']);
        $this->assertSame(1, $r['queue_depth']);
    }

    public function test_duplicate_heavy_pressure_rejects_override_even_with_worker_drain(): void
    {
        $r = (new AtlasSelfConstructionNativeReplenisherPreflight)->preflightWaitDecision([
            'worker_floor_breach' => true,
            'duplicate_pressure' => 0.9,
        ]);

        $this->assertFalse($r['wait_override']);
        $this->assertFalse($r['quality_gate']);
        $this->assertContains(AtlasSelfConstructionNativeReplenisherPreflight::REASON_DUPLICATE_PRESSURE_TOO_HIGH, $r['reasons']);
    }

    public function test_missing_evidence_rejects_override_even_with_worker_drain(): void
    {
        $r = (new AtlasSelfConstructionNativeReplenisherPreflight)->preflightWaitDecision([
            'replenish_soon' => true,
            'evidence_ready' => false,
        ]);

        $this->assertFalse($r['wait_override']);
        $this->assertFalse($r['quality_gate']);
        $this->assertContains(AtlasSelfConstructionNativeReplenisherPreflight::REASON_EVIDENCE_NOT_READY, $r['reasons']);
    }

    public function test_worker_starvation_override_reports_queue_and_drain_facts(): void
    {
        $r = (new AtlasSelfConstructionNativeReplenisherPreflight)->preflightWaitDecision([
            'replenish_soon' => true,
            'claimable_depth' => 2,
        ]);

        $this->assertTrue($r['wait_override']);
        $this->assertTrue($r['worker_drain']);
        $this->assertSame(2, $r['queue_depth']);
        $this->assertTrue($r['evidence_ready']);
    }

    // ── healthAwareAdmission: enqueue_allowed, blocking_deficiencies, health_warnings ──

    public function test_valid_spec_allowed_with_health_warning(): void
    {
        $r = (new AtlasSelfConstructionNativeReplenisherPreflight)->healthAwareAdmission([
            'self_sufficient' => true,
            'blocking_deficiencies' => [],
            'health_warnings' => ['lease_mismatch_nonblocking'],
            'duplicate_target' => false,
            'malformed' => false,
            'low_value' => false,
        ]);
        $this->assertTrue($r['enqueue_allowed']);
        $this->assertSame([], $r['blocking_deficiencies']);
        $this->assertSame(['lease_mismatch_nonblocking'], $r['health_warnings']);
    }

    public function test_malformed_spec_blocked(): void
    {
        $r = (new AtlasSelfConstructionNativeReplenisherPreflight)->healthAwareAdmission([
            'self_sufficient' => true,
            'blocking_deficiencies' => [],
            'health_warnings' => [],
            'duplicate_target' => false,
            'malformed' => true,
            'low_value' => false,
        ]);
        $this->assertFalse($r['enqueue_allowed']);
        $this->assertContains('malformed_spec', $r['blocking_deficiencies']);
    }

    public function test_duplicate_target_blocked(): void
    {
        $r = (new AtlasSelfConstructionNativeReplenisherPreflight)->healthAwareAdmission([
            'self_sufficient' => true,
            'blocking_deficiencies' => [],
            'health_warnings' => [],
            'duplicate_target' => true,
            'malformed' => false,
            'low_value' => false,
        ]);
        $this->assertFalse($r['enqueue_allowed']);
        $this->assertContains('duplicate_target', $r['blocking_deficiencies']);
    }

    public function test_low_value_blocked(): void
    {
        $r = (new AtlasSelfConstructionNativeReplenisherPreflight)->healthAwareAdmission([
            'self_sufficient' => true,
            'blocking_deficiencies' => [],
            'health_warnings' => [],
            'duplicate_target' => false,
            'malformed' => false,
            'low_value' => true,
        ]);
        $this->assertFalse($r['enqueue_allowed']);
        $this->assertContains('low_value_spec', $r['blocking_deficiencies']);
    }

    public function test_not_self_sufficient_blocked(): void
    {
        $r = (new AtlasSelfConstructionNativeReplenisherPreflight)->healthAwareAdmission([
            'self_sufficient' => false,
            'blocking_deficiencies' => [],
            'health_warnings' => [],
            'duplicate_target' => false,
            'malformed' => false,
            'low_value' => false,
        ]);
        $this->assertFalse($r['enqueue_allowed']);
        $this->assertContains('not_self_sufficient', $r['blocking_deficiencies']);
    }
}
