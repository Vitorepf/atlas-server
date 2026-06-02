<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\RouterRuntime\Reversibility;

use App\Services\Ai\RouterRuntime\Reversibility\ReversalCostTierClassifier;
use PHPUnit\Framework\TestCase;

final class ReversalCostTierClassifierTest extends TestCase
{
    private ReversalCostTierClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new ReversalCostTierClassifier();
    }

    public function testThreeChangedFilesNoMergeNoExternalIsCheapRankZero(): void
    {
        $result = $this->classifier->classify([
            'changed_files' => 3,
            'merged_to_main' => false,
            'external_call' => false,
        ]);

        $this->assertSame('atlas.router.reversal_cost_tier.v1', $result['schema_version']);
        $this->assertSame('cheap', $result['cost_tier']);
        $this->assertSame(0, $result['cost_rank']);
        $this->assertFalse($result['fail_closed']);
        $this->assertFalse($result['provider_invoked']);
    }

    public function testBandEdgeFiveIsCheapWhileSixIsModerate(): void
    {
        $five = $this->classifier->classify([
            'changed_files' => 5,
            'merged_to_main' => false,
            'external_call' => false,
        ]);

        $this->assertSame('cheap', $five['cost_tier']);
        $this->assertSame(0, $five['cost_rank']);

        $six = $this->classifier->classify([
            'changed_files' => 6,
            'merged_to_main' => false,
            'external_call' => false,
        ]);

        $this->assertSame('moderate', $six['cost_tier']);
        $this->assertSame(1, $six['cost_rank']);
    }

    public function testMergedToMainAndExternalCallIsUnrecoverableRankThree(): void
    {
        $result = $this->classifier->classify([
            'changed_files' => 2,
            'merged_to_main' => true,
            'external_call' => true,
        ]);

        $this->assertSame('unrecoverable', $result['cost_tier']);
        $this->assertSame(3, $result['cost_rank']);
        $this->assertFalse($result['fail_closed']);
    }

    public function testOrderingAscendingAndUnknownRankFailsClosedToThree(): void
    {
        $cheap = $this->classifier->rankOf('cheap');
        $moderate = $this->classifier->rankOf('moderate');
        $expensive = $this->classifier->rankOf('expensive');
        $unrecoverable = $this->classifier->rankOf('unrecoverable');

        $this->assertSame(0, $cheap);
        $this->assertSame(1, $moderate);
        $this->assertSame(2, $expensive);
        $this->assertSame(3, $unrecoverable);

        $this->assertTrue($cheap < $moderate);
        $this->assertTrue($moderate < $expensive);
        $this->assertTrue($expensive < $unrecoverable);

        $this->assertSame(3, $this->classifier->rankOf('unknown'));
    }

    public function testEmptyInputFailsClosedToUnrecoverableRankThree(): void
    {
        $result = $this->classifier->classify([]);

        $this->assertTrue($result['fail_closed']);
        $this->assertSame('unrecoverable', $result['cost_tier']);
        $this->assertSame(3, $result['cost_rank']);
    }

    public function testMergedToMainAloneIsExpensiveRankTwo(): void
    {
        $result = $this->classifier->classify([
            'changed_files' => 1,
            'merged_to_main' => true,
            'external_call' => false,
        ]);

        $this->assertSame('expensive', $result['cost_tier']);
        $this->assertSame(2, $result['cost_rank']);
    }

    public function testExternalCallAloneIsExpensiveRankTwo(): void
    {
        $result = $this->classifier->classify([
            'changed_files' => 2,
            'merged_to_main' => false,
            'external_call' => true,
        ]);

        $this->assertSame('expensive', $result['cost_tier']);
        $this->assertSame(2, $result['cost_rank']);
    }

    public function testTwentyChangedFilesIsExpensiveWhileNineteenIsModerate(): void
    {
        $nineteen = $this->classifier->classify([
            'changed_files' => 19,
            'merged_to_main' => false,
            'external_call' => false,
        ]);

        $this->assertSame('moderate', $nineteen['cost_tier']);
        $this->assertSame(1, $nineteen['cost_rank']);

        $twenty = $this->classifier->classify([
            'changed_files' => 20,
            'merged_to_main' => false,
            'external_call' => false,
        ]);

        $this->assertSame('expensive', $twenty['cost_tier']);
        $this->assertSame(2, $twenty['cost_rank']);
    }

    public function testChangedFilesAsListIsCountedByLength(): void
    {
        $result = $this->classifier->classify([
            'changed_files' => ['a.php', 'b.php', 'c.php', 'd.php', 'e.php', 'f.php', 'g.php'],
            'merged_to_main' => false,
            'external_call' => false,
        ]);

        $this->assertSame('moderate', $result['cost_tier']);
        $this->assertSame(1, $result['cost_rank']);
    }

    public function testNegativeChangedFilesCoercedToZeroFailsClosed(): void
    {
        $result = $this->classifier->classify([
            'changed_files' => -7,
            'merged_to_main' => false,
            'external_call' => false,
        ]);

        $this->assertTrue($result['fail_closed']);
        $this->assertSame('unrecoverable', $result['cost_tier']);
        $this->assertSame(3, $result['cost_rank']);
    }

    public function testReasonsAreComputedAndDeterministic(): void
    {
        $first = $this->classifier->classify([
            'changed_files' => 12,
            'merged_to_main' => false,
            'external_call' => false,
        ]);

        $second = $this->classifier->classify([
            'changed_files' => 12,
            'merged_to_main' => false,
            'external_call' => false,
        ]);

        $this->assertSame('mid_blast', $first['reasons'][0]['code']);
        $this->assertNotSame('', $first['reasons'][0]['detail']);
        $this->assertSame($first['classification_hash'], $second['classification_hash']);
        $this->assertNotSame($first['classification_hash'], $this->classifier->classify([
            'changed_files' => 25,
            'merged_to_main' => false,
            'external_call' => false,
        ])['classification_hash']);
    }
}
