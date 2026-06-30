<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskBlockedPacketFamilyClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Pure family classifier for blocked queue packets. Deterministic: same fixture in → same family+action out.
 */
final class AtlasTaskBlockedPacketFamilyClassifierTest extends TestCase
{
    private function svc(): AtlasTaskBlockedPacketFamilyClassifier
    {
        return new AtlasTaskBlockedPacketFamilyClassifier;
    }

    private function packet(string $id, array $overrides = []): array
    {
        return array_merge(['task_packet_id' => $id, 'status' => 'queued', 'give_back_count' => 0], $overrides);
    }

    public function test_classifies_dormant_cli_arm_proxy(): void
    {
        $p = $this->packet('p-cli-arm', ['packet_quality' => ['facts' => ['dormant_cli_arm_proxy' => true], 'blocking_deficiencies' => []]]);
        $result = $this->svc()->classifyOne($p);

        $this->assertSame(AtlasTaskBlockedPacketFamilyClassifier::SCHEMA, $result['schema_version']);
        $this->assertSame('p-cli-arm', $result['task_packet_id']);
        $this->assertSame(AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DORMANT_CLI_ARM_PROXY, $result['family']);
        $this->assertStringContainsString('dormant_cli_arm_proxy', $result['rationale']);
    }

    public function test_classifies_test_only_microtask_requires_contract(): void
    {
        $p = $this->packet('p-contract', ['packet_quality' => ['facts' => ['test_only_has_contract' => true], 'blocking_deficiencies' => []]]);
        $result = $this->svc()->classifyOne($p);

        $this->assertSame(AtlasTaskBlockedPacketFamilyClassifier::FAMILY_TEST_ONLY_MICROTASK, $result['family']);
        $this->assertStringContainsString('test_only_has_contract', $result['rationale']);
    }

    public function test_classifies_repeated_give_back_at_threshold(): void
    {
        $p = $this->packet('p-giveback', ['give_back_count' => 8]);
        $result = $this->svc()->classifyOne($p);

        $this->assertSame(AtlasTaskBlockedPacketFamilyClassifier::FAMILY_REPEATED_GIVE_BACK, $result['family']);
        $this->assertStringContainsString('8', $result['rationale']);
        $this->assertSame('respec_or_retire', $result['recommended_action']);
    }

    public function test_give_back_below_threshold_does_not_classify_as_repeated(): void
    {
        $p = $this->packet('p-giveback-low', ['give_back_count' => 7]);
        $result = $this->svc()->classifyOne($p);

        $this->assertNotSame(AtlasTaskBlockedPacketFamilyClassifier::FAMILY_REPEATED_GIVE_BACK, $result['family']);
    }

    public function test_classifies_duplicate_already_done_via_fact(): void
    {
        $p = $this->packet('p-done', ['packet_quality' => ['facts' => ['already_done' => true], 'blocking_deficiencies' => []]]);
        $result = $this->svc()->classifyOne($p);

        $this->assertSame(AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DUPLICATE_ALREADY_DONE, $result['family']);
        $this->assertSame('retire', $result['recommended_action']);
    }

    public function test_classifies_duplicate_already_done_via_status(): void
    {
        $p = $this->packet('p-completed', ['status' => 'completed']);
        $result = $this->svc()->classifyOne($p);

        $this->assertSame(AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DUPLICATE_ALREADY_DONE, $result['family']);
    }

    public function test_classifies_respec_candidate_from_blocking_deficiencies(): void
    {
        $p = $this->packet('p-respec', ['packet_quality' => ['facts' => [], 'blocking_deficiencies' => ['vague_objective', 'missing_scope']]]);
        $result = $this->svc()->classifyOne($p);

        $this->assertSame(AtlasTaskBlockedPacketFamilyClassifier::FAMILY_RESPEC_CANDIDATE, $result['family']);
        $this->assertSame('respec', $result['recommended_action']);
        $this->assertStringContainsString('vague_objective', $result['rationale']);
    }

    public function test_classifies_unknown_when_no_rule_matches(): void
    {
        $result = $this->svc()->classifyOne($this->packet('p-unknown'));

        $this->assertSame(AtlasTaskBlockedPacketFamilyClassifier::FAMILY_UNKNOWN, $result['family']);
        $this->assertSame('manual_review', $result['recommended_action']);
    }

    public function test_classify_batch_returns_one_record_per_packet(): void
    {
        $packets = [
            $this->packet('b-1', ['packet_quality' => ['facts' => ['dormant_cli_arm_proxy' => true], 'blocking_deficiencies' => []]]),
            $this->packet('b-2', ['give_back_count' => 8]),
            $this->packet('b-3', ['packet_quality' => ['facts' => ['already_done' => true], 'blocking_deficiencies' => []]]),
        ];
        $results = $this->svc()->classifyBatch($packets);

        $this->assertCount(3, $results);
        $this->assertSame(['b-1', 'b-2', 'b-3'], array_column($results, 'task_packet_id'));
        $families = array_column($results, 'family');
        $this->assertSame(AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DORMANT_CLI_ARM_PROXY, $families[0]);
        $this->assertSame(AtlasTaskBlockedPacketFamilyClassifier::FAMILY_REPEATED_GIVE_BACK, $families[1]);
        $this->assertSame(AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DUPLICATE_ALREADY_DONE, $families[2]);
    }

    public function test_all_records_carry_schema_version_and_rationale(): void
    {
        $packets = [
            $this->packet('s-1', ['packet_quality' => ['facts' => ['dormant_cli_arm_proxy' => true], 'blocking_deficiencies' => []]]),
            $this->packet('s-2', ['packet_quality' => ['facts' => ['test_only_has_contract' => true], 'blocking_deficiencies' => []]]),
            $this->packet('s-3', ['give_back_count' => 8]),
            $this->packet('s-4', ['packet_quality' => ['facts' => ['already_done' => true], 'blocking_deficiencies' => []]]),
            $this->packet('s-5', ['packet_quality' => ['facts' => [], 'blocking_deficiencies' => ['vague_objective']]]),
            $this->packet('s-6'),
        ];
        foreach ($this->svc()->classifyBatch($packets) as $r) {
            $this->assertSame(AtlasTaskBlockedPacketFamilyClassifier::SCHEMA, $r['schema_version']);
            $this->assertNotEmpty($r['rationale'], 'every record must carry a rationale');
            $this->assertNotEmpty($r['recommended_action']);
        }
    }

    public function test_is_deterministic(): void
    {
        $p = $this->packet('det', ['give_back_count' => 9]);
        $a = $this->svc()->classifyOne($p);
        $b = $this->svc()->classifyOne($p);

        $this->assertSame($a, $b);
    }
}
