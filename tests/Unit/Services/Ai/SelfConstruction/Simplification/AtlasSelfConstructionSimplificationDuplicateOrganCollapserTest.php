<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationDuplicateOrganCollapser;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionSimplificationDuplicateOrganCollapserTest extends TestCase
{
    private AtlasSelfConstructionSimplificationDuplicateOrganCollapser $collapser;

    protected function setUp(): void
    {
        $this->collapser = new AtlasSelfConstructionSimplificationDuplicateOrganCollapser;
    }

    public function test_same_input_same_output_organs_are_collapse_candidates(): void
    {
        $result = $this->collapser->identify([
            ['id' => 'a', 'input_shape' => 'array', 'output_shape' => 'string', 'capability' => 'normalize'],
            ['id' => 'b', 'input_shape' => 'array', 'output_shape' => 'string', 'capability' => 'normalize'],
        ]);

        $this->assertSame(1, $result['candidate_count']);
        $this->assertContains('a', $result['collapse_candidates'][0]['organs']);
        $this->assertContains('b', $result['collapse_candidates'][0]['organs']);
    }

    public function test_divergent_organs_are_kept_separate(): void
    {
        $result = $this->collapser->identify([
            ['id' => 'a', 'input_shape' => 'array', 'output_shape' => 'string', 'capability' => 'normalize'],
            ['id' => 'b', 'input_shape' => 'string', 'output_shape' => 'int', 'capability' => 'parse'],
        ]);

        $this->assertSame(0, $result['candidate_count']);
        $this->assertSame(2, $result['kept_count']);
    }

    public function test_mixed_organs_split_correctly(): void
    {
        $result = $this->collapser->identify([
            ['id' => 'a', 'input_shape' => 'array', 'output_shape' => 'string', 'capability' => 'normalize'],
            ['id' => 'b', 'input_shape' => 'array', 'output_shape' => 'string', 'capability' => 'normalize'],
            ['id' => 'c', 'input_shape' => 'string', 'output_shape' => 'int', 'capability' => 'parse'],
        ]);

        $this->assertSame(1, $result['candidate_count']);
        $this->assertSame(1, $result['kept_count']);
        $this->assertSame(3, $result['total_organs']);
    }

    public function test_empty_input_returns_empty(): void
    {
        $result = $this->collapser->identify([]);
        $this->assertSame(0, $result['candidate_count']);
        $this->assertSame(0, $result['kept_count']);
    }

    public function test_schema_present(): void
    {
        $result = $this->collapser->identify([]);
        $this->assertSame(AtlasSelfConstructionSimplificationDuplicateOrganCollapser::SCHEMA, $result['schema']);
    }
}
