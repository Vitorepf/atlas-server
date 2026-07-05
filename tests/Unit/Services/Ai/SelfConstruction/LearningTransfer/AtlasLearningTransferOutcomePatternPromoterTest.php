<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\LearningTransfer;

use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasLearningTransferOutcomePatternPromoter;
use PHPUnit\Framework\TestCase;

final class AtlasLearningTransferOutcomePatternPromoterTest extends TestCase
{
    private AtlasLearningTransferOutcomePatternPromoter $promoter;

    protected function setUp(): void
    {
        $this->promoter = new AtlasLearningTransferOutcomePatternPromoter;
    }

    public function test_repeated_success_promotes_pattern(): void
    {
        $result = $this->promoter->evaluate([
            'pattern_id' => 'p1',
            'success_count' => 3,
            'constraint' => 'always_use_structured_steps',
        ]);

        $this->assertTrue($result['promoted']);
        $this->assertFalse($result['advisory']);
    }

    public function test_one_off_success_stays_advisory(): void
    {
        $result = $this->promoter->evaluate([
            'pattern_id' => 'p1',
            'success_count' => 1,
        ]);

        $this->assertFalse($result['promoted']);
        $this->assertTrue($result['advisory']);
    }

    public function test_conflicting_give_back_evidence_blocks_promotion(): void
    {
        $result = $this->promoter->evaluate([
            'pattern_id' => 'p1',
            'success_count' => 5,
            'give_back_count' => 1,
        ]);

        $this->assertFalse($result['promoted']);
        $this->assertTrue($result['blocked']);
    }

    public function test_zero_success_no_promotion(): void
    {
        $result = $this->promoter->evaluate([
            'pattern_id' => 'p1',
            'success_count' => 0,
        ]);

        $this->assertFalse($result['promoted']);
        $this->assertFalse($result['advisory']);
    }

    public function test_schema_present(): void
    {
        $result = $this->promoter->evaluate([]);
        $this->assertSame(AtlasLearningTransferOutcomePatternPromoter::SCHEMA, $result['schema']);
    }
}
