<?php

namespace Tests\Unit\Ai\Cognitive\Pattern;

use App\Services\Ai\Cognitive\Pattern\ProcessPatternStructureValidator;
use Tests\TestCase;

class ProcessPatternStructureValidatorTest extends TestCase
{
    public function test_validator_passes_complete_pattern_and_blocks_invalid_category(): void
    {
        $validator = app(ProcessPatternStructureValidator::class);
        $pattern = [
            'name' => 'validate-then-scale',
            'category' => 'process',
            'intent' => 'Validate before scaling.',
            'problem_context' => 'A reversible decision may become expensive after scale.',
            'forces' => [['name' => 'speed']],
            'solution' => ['abstract' => 'Start small.'],
            'consequences' => ['pros' => ['cheap evidence']],
        ];

        $this->assertSame('passed', $validator->validate($pattern)['status']);
        $this->assertSame('pattern_structure_invalid_category', $validator->validate([...$pattern, 'category' => 'magic'])['reason']);
    }
}
