<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Replenisher;

use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
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
}
