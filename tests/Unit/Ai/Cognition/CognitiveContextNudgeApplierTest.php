<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition;

use App\Services\Ai\Cognition\CognitiveContextNudgeApplier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Isolated unit test for CognitiveContextNudgeApplier.
 *
 * Pins the FIRST-MATCH / single-arm (elseif short-circuit) semantics that the
 * frozen AtlasCognitiveFunctionDecomposerServiceTest only exercises indirectly
 * via dominant-axis dominance. Asserts each arm fires alone and that the
 * relocated branch ladder is byte-for-byte behavior-equivalent to the original.
 */
class CognitiveContextNudgeApplierTest extends TestCase
{
    private CognitiveContextNudgeApplier $applier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->applier = new CognitiveContextNudgeApplier;
    }

    /**
     * @return array<string,int>
     */
    private function zeroHits(): array
    {
        return [
            'reasoning' => 0,
            'retrieval' => 0,
            'generation' => 0,
            'code' => 0,
            'vision' => 0,
            'audit' => 0,
        ];
    }

    public function test_empty_context_is_a_noop(): void
    {
        $this->assertSame($this->zeroHits(), $this->applier->applyNudges($this->zeroHits(), []));
    }

    public function test_empty_framework_string_is_a_noop(): void
    {
        $this->assertSame($this->zeroHits(), $this->applier->applyNudges($this->zeroHits(), ['framework' => '']));
    }

    public function test_cartography_prefix_bumps_audit_by_two_only(): void
    {
        $out = $this->applier->applyNudges($this->zeroHits(), ['framework' => 'cartography_audit']);
        $expected = $this->zeroHits();
        $expected['audit'] = 2;
        $this->assertSame($expected, $out);
    }

    public function test_kernel_vault_bumps_audit_by_two_only(): void
    {
        $out = $this->applier->applyNudges($this->zeroHits(), ['framework' => 'kernel_vault']);
        $expected = $this->zeroHits();
        $expected['audit'] = 2;
        $this->assertSame($expected, $out);
    }

    public function test_programming_prefix_bumps_code_by_two_and_audit_by_one(): void
    {
        $out = $this->applier->applyNudges($this->zeroHits(), ['framework' => 'programming_governance']);
        $expected = $this->zeroHits();
        $expected['code'] = 2;
        $expected['audit'] = 1;
        $this->assertSame($expected, $out);
    }

    public function test_mission_mode_bumps_reasoning_and_retrieval_by_one(): void
    {
        $out = $this->applier->applyNudges($this->zeroHits(), ['framework' => 'mission_mode']);
        $expected = $this->zeroHits();
        $expected['reasoning'] = 1;
        $expected['retrieval'] = 1;
        $this->assertSame($expected, $out);
    }

    public function test_visual_prefix_bumps_vision_by_two_only(): void
    {
        $out = $this->applier->applyNudges($this->zeroHits(), ['framework' => 'visual_layout']);
        $expected = $this->zeroHits();
        $expected['vision'] = 2;
        $this->assertSame($expected, $out);
    }

    public function test_framework_is_case_insensitive(): void
    {
        $out = $this->applier->applyNudges($this->zeroHits(), ['framework' => 'CARTOGRAPHY_AUDIT']);
        $expected = $this->zeroHits();
        $expected['audit'] = 2;
        $this->assertSame($expected, $out);
    }

    public function test_unknown_framework_is_a_noop(): void
    {
        $this->assertSame($this->zeroHits(), $this->applier->applyNudges($this->zeroHits(), ['framework' => 'totally_unknown']));
    }

    /**
     * Each role maps to exactly its one delta (first-match, single arm).
     *
     * @return array<string,array{0:string,1:string,2:int}>
     */
    public static function roleProvider(): array
    {
        return [
            'auditor' => ['auditor', 'audit', 1],
            'reviewer' => ['reviewer', 'audit', 1],
            'researcher' => ['researcher', 'retrieval', 1],
            'librarian' => ['librarian', 'retrieval', 1],
            'writer' => ['writer', 'generation', 1],
            'editor' => ['editor', 'generation', 1],
            'engineer' => ['engineer', 'code', 1],
            'developer' => ['developer', 'code', 1],
            'programmer' => ['programmer', 'code', 1],
        ];
    }

    #[DataProvider('roleProvider')]
    public function test_each_role_maps_to_exactly_one_delta(string $role, string $axis, int $delta): void
    {
        $out = $this->applier->applyNudges($this->zeroHits(), ['role' => $role]);
        $expected = $this->zeroHits();
        $expected[$axis] = $delta;
        $this->assertSame($expected, $out, "role={$role} should bump only {$axis} by {$delta}");
    }

    public function test_role_is_case_insensitive(): void
    {
        $out = $this->applier->applyNudges($this->zeroHits(), ['role' => 'Engineer']);
        $expected = $this->zeroHits();
        $expected['code'] = 1;
        $this->assertSame($expected, $out);
    }

    public function test_unknown_role_is_a_noop(): void
    {
        $this->assertSame($this->zeroHits(), $this->applier->applyNudges($this->zeroHits(), ['role' => 'janitor']));
    }

    public function test_framework_and_role_compose_additively(): void
    {
        // programming framework (code+=2, audit+=1) + engineer role (code+=1).
        $out = $this->applier->applyNudges($this->zeroHits(), [
            'framework' => 'programming_governance',
            'role' => 'engineer',
        ]);
        $expected = $this->zeroHits();
        $expected['code'] = 3;
        $expected['audit'] = 1;
        $this->assertSame($expected, $out);
    }

    public function test_existing_hits_are_preserved_and_added_to(): void
    {
        $hits = $this->zeroHits();
        $hits['code'] = 5;
        $hits['audit'] = 2;
        $out = $this->applier->applyNudges($hits, ['framework' => 'programming_governance']);
        $expected = $this->zeroHits();
        $expected['code'] = 7;
        $expected['audit'] = 3;
        $this->assertSame($expected, $out);
    }
}
