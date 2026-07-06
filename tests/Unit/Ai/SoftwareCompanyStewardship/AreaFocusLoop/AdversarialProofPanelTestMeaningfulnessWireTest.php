<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AdversarialProofPanelService;
use Tests\TestCase;

/**
 * WIRE-OBSERVE pin: refute() now records an advisory `test_meaningfulness`
 * grade computed from the diff-scoped test additions the panel already
 * receives. The grade NEVER refutes — merge_allowed stays governed only by the
 * four verifiers.
 */
final class AdversarialProofPanelTestMeaningfulnessWireTest extends TestCase
{
    /**
     * @param  array<string,string>  $addedLines
     * @return array<string,mixed>
     */
    private function cycleWith(array $addedLines): array
    {
        return [
            'cycle_id' => 'aes_wire',
            'changed_files' => array_keys($addedLines),
            'allowed_files' => array_keys($addedLines),
            'validation' => ['ran' => true, 'passed' => true, 'commands' => ['php artisan test']],
            'changed_file_contents' => $addedLines,
            'changed_added_lines' => $addedLines,
        ];
    }

    public function test_meaningful_test_addition_scores_high_and_merge_allowed_is_untouched(): void
    {
        $verdict = (new AdversarialProofPanelService)->refute($this->cycleWith([
            'app/Services/Ai/Example.php' => "final class Example\n{\n    public function score(): int { return 3; }\n}",
            'tests/Unit/Ai/ExampleTest.php' => "public function test_score_returns_three(): void\n"
                ."\$this->assertSame(3, (new Example)->score());",
        ]));

        $this->assertTrue($verdict['merge_allowed']);
        $meaning = $verdict['test_meaningfulness'];
        $this->assertIsArray($meaning);
        $this->assertSame('atlas.software_company_stewardship.test_meaningfulness.v1', $meaning['schema_version']);
        $this->assertFalse($meaning['theater']);
        $this->assertSame('high', $meaning['band']);
        $this->assertSame(1, $meaning['test_method_count']);
        $this->assertSame(1, $meaning['real_assertion_count']);
        $this->assertSame(1, $meaning['production_referencing_assertion_count']);
        $this->assertGreaterThanOrEqual(0.66, $meaning['meaningfulness_score']);
        $this->assertLessThanOrEqual(1.0, $meaning['meaningfulness_score']);
    }

    public function test_tautological_test_addition_is_graded_theater_without_changing_the_verdict(): void
    {
        $verdict = (new AdversarialProofPanelService)->refute($this->cycleWith([
            'app/Services/Ai/Example.php' => "final class Example\n{\n    public function score(): int { return 3; }\n}",
            'tests/Unit/Ai/ExampleTest.php' => "public function test_tautology(): void\n\$this->assertTrue(true);",
        ]));

        // Advisory only: theater tests still clear the four verifiers, so the
        // merge verdict is byte-identical to before the wire.
        $this->assertTrue($verdict['merge_allowed']);
        $meaning = $verdict['test_meaningfulness'];
        $this->assertIsArray($meaning);
        $this->assertTrue($meaning['theater']);
        $this->assertSame('low', $meaning['band']);
        $this->assertSame(0, $meaning['real_assertion_count']);
        $this->assertSame(1, $meaning['degenerate_assertion_count']);
        $this->assertLessThanOrEqual(0.39, $meaning['meaningfulness_score']);
        $this->assertContains('no_production_reference', $meaning['reasons']);
    }
}
