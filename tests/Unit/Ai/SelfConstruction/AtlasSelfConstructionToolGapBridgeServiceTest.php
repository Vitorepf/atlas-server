<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionToolGapBridgeService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AtlasSelfConstructionToolGapBridgeServiceTest extends TestCase
{
    private function service(): AtlasSelfConstructionToolGapBridgeService
    {
        // evaluateProposalQuality() never touches the constructor-injected loss-observer or
        // builder, so a constructor-less instance keeps this a pure Unit test.
        return (new ReflectionClass(AtlasSelfConstructionToolGapBridgeService::class))->newInstanceWithoutConstructor();
    }

    public function test_generic_code_fix_loss_is_skipped_with_code_fix_intent_classification(): void
    {
        $r = $this->service()->evaluateProposalQuality([
            ['reason' => 'assertion_failed_wrong_value', 'occurrences' => 10, 'target_path' => 'app/Foo.php'],
        ]);

        self::assertSame([], $r['accepted_proposals']);
        self::assertCount(1, $r['skipped']);
        self::assertSame('code_fix_intent', $r['skipped'][0]['classification']);
    }

    public function test_recurrent_missing_fixture_gap_is_accepted_with_matched_signal(): void
    {
        $r = $this->service()->evaluateProposalQuality([
            ['reason' => 'missing fixture builder for scenario X', 'occurrences' => 5, 'target_path' => ''],
        ]);

        self::assertCount(1, $r['accepted_proposals']);
        self::assertTrue($r['accepted_proposals'][0]['proposal_quality']['accepted']);
        self::assertSame('fixture', $r['accepted_proposals'][0]['signal']);
    }

    public function test_recurrent_missing_linter_harness_and_command_gaps_are_accepted(): void
    {
        $r = $this->service()->evaluateProposalQuality([
            ['reason' => 'contract lint_contract failure', 'occurrences' => 4, 'target_path' => ''],
            ['reason' => 'harness_missing for integration suite', 'occurrences' => 3, 'target_path' => ''],
            ['reason' => 'command_not_found: atlas:tool:x', 'occurrences' => 6, 'target_path' => ''],
        ]);

        self::assertCount(3, $r['accepted_proposals']);
        foreach ($r['accepted_proposals'] as $proposal) {
            self::assertTrue($proposal['proposal_quality']['accepted']);
        }
    }

    public function test_duplicate_gap_signals_are_collapsed_before_max_proposals_applied(): void
    {
        $r = $this->service()->evaluateProposalQuality([
            ['reason' => 'missing fixture builder', 'occurrences' => 5, 'target_path' => ''],
            ['reason' => 'missing fixture builder', 'occurrences' => 5, 'target_path' => ''],
            ['reason' => 'missing fixture builder', 'occurrences' => 5, 'target_path' => ''],
        ], ['max_proposals' => 5]);

        self::assertCount(1, $r['accepted_proposals']);
        self::assertSame(2, $r['duplicates_collapsed']);
    }

    public function test_claim_policy_remains_never_silent_and_requires_human_approval(): void
    {
        $r = $this->service()->evaluateProposalQuality([
            ['reason' => 'missing fixture builder', 'occurrences' => 5, 'target_path' => ''],
        ]);

        self::assertTrue($r['claim_policy']['never_silent']);
        self::assertTrue($r['claim_policy']['requires_human_approval']);
        self::assertFalse($r['claim_policy']['auto_approved']);
        self::assertFalse($r['claim_policy']['auto_promoted']);
    }
}
