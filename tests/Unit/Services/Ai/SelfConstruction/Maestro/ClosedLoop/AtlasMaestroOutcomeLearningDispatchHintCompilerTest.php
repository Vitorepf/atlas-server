<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Maestro\ClosedLoop;

use App\Services\Ai\SelfConstruction\Maestro\ClosedLoop\AtlasMaestroOutcomeLearningDispatchHintCompiler;
use Tests\TestCase;

final class AtlasMaestroOutcomeLearningDispatchHintCompilerTest extends TestCase
{
    private function compiler(): AtlasMaestroOutcomeLearningDispatchHintCompiler
    {
        return new AtlasMaestroOutcomeLearningDispatchHintCompiler;
    }

    // ── AC: success patterns emit expansion hints ──

    public function test_high_success_rate_emits_expansion_hint(): void
    {
        $result = $this->compiler()->compile([
            'total_outcomes' => 10,
            'success_count' => 8,
            'give_back_count' => 1,
            'duplicate_count' => 0,
        ]);

        $this->assertSame('expansion_first', $result['dispatch_hint']);
    }

    // ── AC: repeated give_back emits repair hints ──

    public function test_high_give_back_rate_emits_repair_hint(): void
    {
        $result = $this->compiler()->compile([
            'total_outcomes' => 10,
            'success_count' => 3,
            'give_back_count' => 4,
            'duplicate_count' => 0,
        ]);

        $this->assertSame('repair_first', $result['dispatch_hint']);
    }

    // ── AC: duplicate work emits consolidation hints ──

    public function test_high_duplicate_rate_emits_consolidation_hint(): void
    {
        $result = $this->compiler()->compile([
            'total_outcomes' => 10,
            'success_count' => 5,
            'give_back_count' => 2,
            'duplicate_count' => 3,
        ]);

        $this->assertSame('consolidation_first', $result['dispatch_hint']);
    }

    // ── balanced default ──

    public function test_no_dominant_pattern_emits_balanced_hint(): void
    {
        $result = $this->compiler()->compile([
            'total_outcomes' => 10,
            'success_count' => 5,
            'give_back_count' => 2,
            'duplicate_count' => 1,
        ]);

        $this->assertSame('balanced', $result['dispatch_hint']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->compiler()->compile([
            'total_outcomes' => 10,
            'success_count' => 5,
            'give_back_count' => 2,
            'duplicate_count' => 1,
        ]);

        $this->assertSame(AtlasMaestroOutcomeLearningDispatchHintCompiler::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('dispatch_hint', $result);
        $this->assertArrayHasKey('reasons', $result);
        $this->assertArrayHasKey('success_rate', $result);
        $this->assertArrayHasKey('give_back_rate', $result);
        $this->assertArrayHasKey('duplicate_rate', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'total_outcomes' => 10,
            'success_count' => 8,
            'give_back_count' => 1,
            'duplicate_count' => 0,
        ];

        $a = $this->compiler()->compile($input);
        $b = $this->compiler()->compile($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
