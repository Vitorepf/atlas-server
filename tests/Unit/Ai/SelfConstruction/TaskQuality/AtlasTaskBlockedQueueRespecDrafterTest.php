<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskBlockedPacketFamilyClassifier;
use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskBlockedQueueRespecDrafter;
use PHPUnit\Framework\TestCase;

final class AtlasTaskBlockedQueueRespecDrafterTest extends TestCase
{
    private function drafter(): AtlasTaskBlockedQueueRespecDrafter
    {
        return new AtlasTaskBlockedQueueRespecDrafter;
    }

    public function test_useful_proxy_families_become_implementation_drafts(): void
    {
        $result = $this->drafter()->draft([
            ['task_packet_id' => 'p1', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DORMANT_CLI_ARM_PROXY,
                'allowed_files' => ['app/Console/Commands/SomeCmd.php']],
            ['task_packet_id' => 'p2', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DORMANT_CLI_ARM_PROXY,
                'allowed_files' => ['app/Console/Commands/SomeCmd.php']],
        ]);

        $kinds = array_column($result['drafts'], 'kind');
        $this->assertContains('implementation_or_contract_task', $kinds, 'dormant_cli_arm_proxy must produce an implementation draft');

        $impls = array_values(array_filter($result['drafts'], fn ($d) => $d['kind'] === 'implementation_or_contract_task'));
        $this->assertCount(1, $impls, 'same allowed_files target must be deduped into one draft');
        $this->assertContains('p1', $impls[0]['source_packet_ids']);
        $this->assertContains('p2', $impls[0]['source_packet_ids']);
    }

    public function test_duplicate_already_done_becomes_retire_only_not_claimable_work(): void
    {
        $result = $this->drafter()->draft([
            ['task_packet_id' => 'done1', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DUPLICATE_ALREADY_DONE],
        ]);

        $this->assertCount(1, $result['drafts']);
        $this->assertSame('retire_only', $result['drafts'][0]['kind']);
        $this->assertContains('done1', $result['drafts'][0]['source_packet_ids']);
        $kinds = array_column($result['drafts'], 'kind');
        $this->assertNotContains('implementation_or_contract_task', $kinds, 'retire_only must not become claimable work');
    }

    public function test_singleton_microtest_padding_is_skipped_not_claimable(): void
    {
        $result = $this->drafter()->draft([
            ['task_packet_id' => 'mt1', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_TEST_ONLY_MICROTASK,
                'behavior_matrix_size' => 1],
            ['task_packet_id' => 'mt2', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_TEST_ONLY_MICROTASK],
        ]);

        $kinds = array_column($result['drafts'], 'kind');
        $this->assertNotContains('implementation_or_contract_task', $kinds, 'singleton microtest padding must not become claimable work');
        $this->assertSame(0, count($result['drafts']), 'no drafts emitted for pure padding');
        $this->assertSame(2, $result['summary']['skip'] ?? 0);
    }

    public function test_microtest_with_strong_behavior_matrix_becomes_implementation_draft(): void
    {
        $result = $this->drafter()->draft([
            ['task_packet_id' => 'mt_strong', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_TEST_ONLY_MICROTASK,
                'behavior_matrix_size' => 5],
        ]);

        $kinds = array_column($result['drafts'], 'kind');
        $this->assertContains('implementation_or_contract_task', $kinds, 'microtest with matrix>=3 must be promoted');
        $this->assertContains('mt_strong', $result['drafts'][0]['source_packet_ids']);
    }

    public function test_every_draft_carries_source_packet_ids(): void
    {
        $result = $this->drafter()->draft([
            ['task_packet_id' => 'a1', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DORMANT_CLI_ARM_PROXY],
            ['task_packet_id' => 'b1', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DUPLICATE_ALREADY_DONE],
            ['task_packet_id' => 'c1', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_RESPEC_CANDIDATE],
        ]);

        foreach ($result['drafts'] as $draft) {
            $this->assertArrayHasKey('source_packet_ids', $draft, 'every draft must carry source_packet_ids');
            $this->assertIsArray($draft['source_packet_ids']);
            $this->assertNotEmpty($draft['source_packet_ids'], 'source_packet_ids must not be empty');
        }
    }

    public function test_waves_are_sorted_and_depends_on_references_preceding_wave(): void
    {
        $result = $this->drafter()->draft([
            ['task_packet_id' => 'done1', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DUPLICATE_ALREADY_DONE],
            ['task_packet_id' => 'arm1', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DORMANT_CLI_ARM_PROXY],
            ['task_packet_id' => 'respec1', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_RESPEC_CANDIDATE],
        ]);

        $waveNums = array_keys($result['waves']);
        $sorted = $waveNums;
        sort($sorted);
        $this->assertSame($sorted, $waveNums, 'waves must be sorted ascending');

        // wave 2 drafts must depend on wave 1 draft_ids
        $wave1Ids = array_column($result['waves'][1], 'draft_id');
        foreach ($result['waves'][2] as $draft) {
            foreach ($wave1Ids as $w1id) {
                $this->assertContains($w1id, $draft['depends_on'], 'wave 2 draft must depend on wave 1 draft_id');
            }
        }

        // wave 1 drafts have empty depends_on
        foreach ($result['waves'][1] as $draft) {
            $this->assertSame([], $draft['depends_on'], 'wave 1 drafts must have no depends_on');
        }
    }

    public function test_proxy_different_targets_produce_separate_drafts(): void
    {
        $result = $this->drafter()->draft([
            ['task_packet_id' => 'p1', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DORMANT_CLI_ARM_PROXY,
                'allowed_files' => ['app/Console/Commands/CmdA.php']],
            ['task_packet_id' => 'p2', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DORMANT_CLI_ARM_PROXY,
                'allowed_files' => ['app/Console/Commands/CmdB.php']],
        ]);

        $impls = array_values(array_filter($result['drafts'], fn ($d) => $d['kind'] === 'implementation_or_contract_task'));
        $this->assertCount(2, $impls, 'different allowed_files targets must produce separate drafts');
    }

    public function test_draft_is_deterministic_for_identical_input(): void
    {
        $records = [
            ['task_packet_id' => 'done1', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DUPLICATE_ALREADY_DONE],
            ['task_packet_id' => 'arm1', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DORMANT_CLI_ARM_PROXY,
                'allowed_files' => ['app/Console/Commands/Foo.php']],
        ];

        $a = $this->drafter()->draft($records);
        $b = $this->drafter()->draft($records);

        $this->assertSame($a['drafts'], $b['drafts'], 'draft output must be deterministic');
    }
}
