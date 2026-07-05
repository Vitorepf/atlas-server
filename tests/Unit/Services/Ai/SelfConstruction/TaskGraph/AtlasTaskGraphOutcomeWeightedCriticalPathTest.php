<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskGraph;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasTaskGraphOutcomeWeightedCriticalPath;
use PHPUnit\Framework\TestCase;

final class AtlasTaskGraphOutcomeWeightedCriticalPathTest extends TestCase
{
    private AtlasTaskGraphOutcomeWeightedCriticalPath $ranker;

    protected function setUp(): void
    {
        $this->ranker = new AtlasTaskGraphOutcomeWeightedCriticalPath;
    }

    public function test_outcome_backed_blockers_outrank_long_shallow_chains(): void
    {
        $result = $this->ranker->rank([
            ['chain_id' => 'short-valuable', 'outcome_learning_value' => 0.9, 'autonomy_unblock_value' => 0.8, 'path_length' => 2, 'has_outcome_evidence' => true],
            ['chain_id' => 'long-shallow', 'outcome_learning_value' => 0.1, 'autonomy_unblock_value' => 0.1, 'path_length' => 10, 'has_outcome_evidence' => false],
        ]);

        $this->assertSame('short-valuable', $result['top_chain']['chain_id']);
        $this->assertGreaterThan($result['ranked_chains'][1]['weight'], $result['top_chain']['weight']);
    }

    public function test_duplicate_chains_suppressed(): void
    {
        $result = $this->ranker->rank([
            ['chain_id' => 'dup', 'outcome_learning_value' => 0.5, 'autonomy_unblock_value' => 0.5, 'path_length' => 3, 'has_outcome_evidence' => true],
            ['chain_id' => 'dup', 'outcome_learning_value' => 0.5, 'autonomy_unblock_value' => 0.5, 'path_length' => 3, 'has_outcome_evidence' => true],
        ]);

        $this->assertSame(1, $result['chain_count']);
    }

    public function test_no_evidence_discounted(): void
    {
        $result = $this->ranker->rank([
            ['chain_id' => 'no-evidence', 'outcome_learning_value' => 0.8, 'autonomy_unblock_value' => 0.8, 'path_length' => 2, 'has_outcome_evidence' => false],
            ['chain_id' => 'with-evidence', 'outcome_learning_value' => 0.8, 'autonomy_unblock_value' => 0.8, 'path_length' => 2, 'has_outcome_evidence' => true],
        ]);

        $this->assertSame('with-evidence', $result['top_chain']['chain_id']);
    }

    public function test_empty_chains_returns_empty(): void
    {
        $result = $this->ranker->rank([]);
        $this->assertSame(0, $result['chain_count']);
        $this->assertNull($result['top_chain']);
    }

    public function test_schema_present(): void
    {
        $result = $this->ranker->rank([]);
        $this->assertSame(AtlasTaskGraphOutcomeWeightedCriticalPath::SCHEMA, $result['schema']);
    }
}
