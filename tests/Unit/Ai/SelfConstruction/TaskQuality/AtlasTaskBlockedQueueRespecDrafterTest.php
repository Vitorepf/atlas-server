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
                'allowed_files' => ['app/Console/Commands/SomeCmd.php', 'tests/Unit/Console/SomeCmdTest.php'],
                'acceptance_criteria' => ['Running /opt/homebrew/bin/php artisan test tests/Unit/Console/SomeCmdTest.php exits 0.']],
            ['task_packet_id' => 'p2', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DORMANT_CLI_ARM_PROXY,
                'allowed_files' => ['app/Console/Commands/SomeCmd.php', 'tests/Unit/Console/SomeCmdTest.php'],
                'acceptance_criteria' => ['Running /opt/homebrew/bin/php artisan test tests/Unit/Console/SomeCmdTest.php exits 0.']],
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
                'behavior_matrix_size' => 5,
                'allowed_files' => ['app/Foo/Bar.php', 'tests/Unit/Foo/BarTest.php'],
                'acceptance_criteria' => ['Running /opt/homebrew/bin/php artisan test tests/Unit/Foo/BarTest.php exits 0.']],
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
            ['task_packet_id' => 'arm1', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DORMANT_CLI_ARM_PROXY,
                'allowed_files' => ['app/Console/Commands/WaveCmd.php', 'tests/Unit/Console/WaveCmdTest.php'],
                'acceptance_criteria' => ['Running /opt/homebrew/bin/php artisan test tests/Unit/Console/WaveCmdTest.php exits 0.']],
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
                'allowed_files' => ['app/Console/Commands/CmdA.php', 'tests/Unit/Console/CmdATest.php'],
                'acceptance_criteria' => ['Running /opt/homebrew/bin/php artisan test tests/Unit/Console/CmdATest.php exits 0.']],
            ['task_packet_id' => 'p2', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DORMANT_CLI_ARM_PROXY,
                'allowed_files' => ['app/Console/Commands/CmdB.php', 'tests/Unit/Console/CmdBTest.php'],
                'acceptance_criteria' => ['Running /opt/homebrew/bin/php artisan test tests/Unit/Console/CmdBTest.php exits 0.']],
        ]);

        $impls = array_values(array_filter($result['drafts'], fn ($d) => $d['kind'] === 'implementation_or_contract_task'));
        $this->assertCount(2, $impls, 'different allowed_files targets must produce separate drafts');
    }

    // ── AC1: newly actionable families merge by family, not explode into singletons ──

    public function test_multiple_records_in_same_actionable_family_merge_into_one_draft(): void
    {
        $result = $this->drafter()->draft([
            ['task_packet_id' => 'sm1', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_CODEX_META_SCHEMA_MIGRATION,
                'recommended_action' => 'respec', 'allowed_files' => ['app/Migrations/SchemaA.php', 'tests/Unit/Migrations/SchemaATest.php'],
                'acceptance_criteria' => ['Running /opt/homebrew/bin/php artisan test tests/Unit/Migrations/SchemaATest.php exits 0.']],
            ['task_packet_id' => 'sm2', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_CODEX_META_SCHEMA_MIGRATION,
                'recommended_action' => 'respec', 'allowed_files' => ['app/Migrations/SchemaB.php', 'tests/Unit/Migrations/SchemaBTest.php'],
                'acceptance_criteria' => ['Running /opt/homebrew/bin/php artisan test tests/Unit/Migrations/SchemaBTest.php exits 0.']],
            ['task_packet_id' => 'sm3', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_CODEX_META_SCHEMA_MIGRATION,
                'recommended_action' => 'respec', 'allowed_files' => ['app/Migrations/SchemaC.php', 'tests/Unit/Migrations/SchemaCTest.php'],
                'acceptance_criteria' => ['Running /opt/homebrew/bin/php artisan test tests/Unit/Migrations/SchemaCTest.php exits 0.']],
        ]);

        $impls = array_values(array_filter($result['drafts'], fn ($d) => $d['kind'] === 'implementation_or_contract_task'));
        $this->assertCount(1, $impls, 'three records sharing a family must merge into one draft, not three singletons');
        $this->assertSame(['sm1', 'sm2', 'sm3'], $impls[0]['source_packet_ids']);
    }

    public function test_different_actionable_families_produce_separate_drafts(): void
    {
        $result = $this->drafter()->draft([
            ['task_packet_id' => 'sm1', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_CODEX_META_SCHEMA_MIGRATION,
                'recommended_action' => 'respec', 'allowed_files' => ['app/Migrations/SchemaA.php', 'tests/Unit/Migrations/SchemaATest.php'],
                'acceptance_criteria' => ['Running /opt/homebrew/bin/php artisan test tests/Unit/Migrations/SchemaATest.php exits 0.']],
            ['task_packet_id' => 'rv1', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_CODEX_META_RECEIPT_VERIFIER,
                'recommended_action' => 'respec', 'allowed_files' => ['app/Verifier/Receipt.php', 'tests/Unit/Verifier/ReceiptTest.php'],
                'acceptance_criteria' => ['Running /opt/homebrew/bin/php artisan test tests/Unit/Verifier/ReceiptTest.php exits 0.']],
        ]);

        $impls = array_values(array_filter($result['drafts'], fn ($d) => $d['kind'] === 'implementation_or_contract_task'));
        $this->assertCount(2, $impls, 'different families must not be merged together');
    }

    public function test_manual_review_family_goes_to_review_recommended_not_implementation(): void
    {
        $result = $this->drafter()->draft([
            ['task_packet_id' => 'ac1', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_CODEX_META_AUTOPOIETIC_CONSTITUTION,
                'recommended_action' => 'manual_review'],
        ]);

        $kinds = array_column($result['drafts'], 'kind');
        $this->assertNotContains('implementation_or_contract_task', $kinds);
        $this->assertContains('review_recommended', $kinds);
    }

    public function test_truly_unknown_family_remains_review_recommended(): void
    {
        $result = $this->drafter()->draft([
            ['task_packet_id' => 'u1', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_UNKNOWN,
                'recommended_action' => 'manual_review'],
        ]);

        $this->assertCount(1, $result['drafts']);
        $this->assertSame('review_recommended', $result['drafts'][0]['kind']);
        $this->assertContains('u1', $result['drafts'][0]['source_packet_ids']);
    }

    public function test_actionable_family_drafts_land_in_wave_two(): void
    {
        $result = $this->drafter()->draft([
            ['task_packet_id' => 'sf1', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_MISSING_SCOPE_FIELDS,
                'recommended_action' => 'respec', 'allowed_files' => ['app/Foo/Bar.php', 'tests/Unit/Foo/BarTest.php'],
                'acceptance_criteria' => ['Running /opt/homebrew/bin/php artisan test tests/Unit/Foo/BarTest.php exits 0.']],
        ]);

        $impls = array_values(array_filter($result['drafts'], fn ($d) => $d['kind'] === 'implementation_or_contract_task'));
        $this->assertSame(2, $impls[0]['wave']);
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

    // ── AC: muscle-ready replacement_spec, prevented_give_back_reason, source_blocker_family ──

    public function test_valid_replacement_emits_replacement_spec_with_prevention_metadata(): void
    {
        $result = $this->drafter()->draft([
            ['task_packet_id' => 'arm1', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DORMANT_CLI_ARM_PROXY,
                'allowed_files' => ['app/Console/Commands/FooCmd.php', 'tests/Unit/Console/FooCmdTest.php'],
                'acceptance_criteria' => ['Running /opt/homebrew/bin/php artisan test tests/Unit/Console/FooCmdTest.php exits 0.']],
        ]);

        $impls = array_values(array_filter($result['drafts'], fn ($d) => $d['kind'] === 'implementation_or_contract_task'));
        $this->assertCount(1, $impls);
        $draft = $impls[0];

        $this->assertSame(AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DORMANT_CLI_ARM_PROXY, $draft['source_blocker_family']);
        $this->assertNotEmpty($draft['prevented_give_back_reason']);
        $this->assertNotNull($draft['replacement_spec']);
        $this->assertContains('app/Console/Commands/FooCmd.php', $draft['replacement_spec']['allowed_files']);
        $this->assertContains('tests/Unit/Console/FooCmdTest.php', $draft['replacement_spec']['allowed_files']);
        $this->assertNotEmpty($draft['replacement_spec']['acceptance_criteria']);
        $this->assertSame(AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DORMANT_CLI_ARM_PROXY, $draft['replacement_spec']['source_blocker_family']);
        $this->assertNotEmpty($draft['replacement_spec']['prevented_give_back_reason']);
    }

    // ── AC: test-only allowed_files (no impl file) downgrades to review_recommended ───

    public function test_test_only_allowed_files_downgrades_to_review_recommended(): void
    {
        $result = $this->drafter()->draft([
            ['task_packet_id' => 'arm2', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DORMANT_CLI_ARM_PROXY,
                'allowed_files' => ['tests/Unit/Console/FooCmdTest.php'],
                'acceptance_criteria' => ['Running /opt/homebrew/bin/php artisan test tests/Unit/Console/FooCmdTest.php exits 0.']],
        ]);

        $impls = array_values(array_filter($result['drafts'], fn ($d) => $d['kind'] === 'implementation_or_contract_task'));
        $this->assertCount(0, $impls, 'incomplete replacement_spec must never masquerade as implementation_or_contract_task');

        $reviews = array_values(array_filter($result['drafts'], fn ($d) => $d['kind'] === 'review_recommended'));
        $this->assertCount(1, $reviews);
        $this->assertSame(3, $reviews[0]['wave']);
        $this->assertNull($reviews[0]['replacement_spec']);
    }

    // ── AC: missing runnable proof command downgrades to review_recommended ───────

    public function test_missing_runnable_proof_command_downgrades_to_review_recommended(): void
    {
        $result = $this->drafter()->draft([
            ['task_packet_id' => 'arm3', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DORMANT_CLI_ARM_PROXY,
                'allowed_files' => ['app/Console/Commands/FooCmd.php', 'tests/Unit/Console/FooCmdTest.php'],
                'acceptance_criteria' => ['The command must behave correctly.']],
        ]);

        $impls = array_values(array_filter($result['drafts'], fn ($d) => $d['kind'] === 'implementation_or_contract_task'));
        $this->assertCount(0, $impls, 'missing runnable proof must never masquerade as implementation_or_contract_task');

        $reviews = array_values(array_filter($result['drafts'], fn ($d) => $d['kind'] === 'review_recommended'));
        $this->assertCount(1, $reviews);
        $this->assertNull($reviews[0]['replacement_spec']);
    }

    // ── AC: downgrade never explodes a deduped family into singleton padding drafts ──

    public function test_downgraded_family_group_stays_a_single_review_draft_not_singletons(): void
    {
        $result = $this->drafter()->draft([
            ['task_packet_id' => 'arm4', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DORMANT_CLI_ARM_PROXY,
                'allowed_files' => ['tests/Unit/Console/BarCmdTest.php']],
            ['task_packet_id' => 'arm5', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DORMANT_CLI_ARM_PROXY,
                'allowed_files' => ['tests/Unit/Console/BarCmdTest.php']],
        ]);

        $reviews = array_values(array_filter($result['drafts'], fn ($d) => $d['kind'] === 'review_recommended'));
        $this->assertCount(1, $reviews, 'deduped proxy target must stay one downgraded draft, never explode into singletons');
        $this->assertSame(['arm4', 'arm5'], $reviews[0]['source_packet_ids']);
    }

    // ── AC: operator-only blocked record never gets replacement metadata ─────

    public function test_operator_only_blocked_record_has_no_replacement_metadata(): void
    {
        $result = $this->drafter()->draft([
            ['task_packet_id' => 'ac1', 'family' => AtlasTaskBlockedPacketFamilyClassifier::FAMILY_CODEX_META_AUTOPOIETIC_CONSTITUTION,
                'recommended_action' => 'manual_review'],
        ]);

        $this->assertSame('review_recommended', $result['drafts'][0]['kind']);
        $this->assertArrayNotHasKey('replacement_spec', $result['drafts'][0]);
        $this->assertArrayNotHasKey('source_blocker_family', $result['drafts'][0]);
        $this->assertArrayNotHasKey('prevented_give_back_reason', $result['drafts'][0]);
    }
}
