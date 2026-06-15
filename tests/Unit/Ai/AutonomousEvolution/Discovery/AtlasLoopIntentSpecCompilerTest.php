<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopIntentSpecCompiler;
use PHPUnit\Framework\TestCase;

/**
 * Next-lever 4 — intent→spec compiler. A NL goal becomes a structured, falsifiable contract (summary +
 * acceptance criteria) the other levers consume. Iterate-to-ready: a vague spec REPLANs with gaps fed back.
 */
final class AtlasLoopIntentSpecCompilerTest extends TestCase
{
    private function goodSpec(): array
    {
        return [
            'summary' => 'Rate-limit the export endpoint to protect the worker pool under load.',
            'acceptance_criteria' => [
                ['id' => 'rejects_4th_in_window', 'description' => 'POST /export rejects a 4th request within 60s with HTTP 429.', 'required' => true],
                ['id' => 'allows_after_window', 'description' => 'A request after the 60s window succeeds with 200.', 'required' => true],
                ['id' => 'emits_retry_after', 'description' => 'The 429 response includes a Retry-After header.', 'required' => false],
            ],
            'suggested_files' => ['app/Http/Controllers/ExportController.php'],
            'decomposition_hint' => 'extract a SlidingWindowLimiter, then wire it in the controller',
        ];
    }

    public function test_compiles_a_well_formed_spec_first_try(): void
    {
        $out = (new AtlasLoopIntentSpecCompiler)->compile('rate limit export', fn () => $this->goodSpec(), 3);
        $this->assertTrue($out['ready'], json_encode($out['gaps']));
        $this->assertSame(1, $out['attempts']);
        $this->assertCount(3, $out['spec']['acceptance_criteria']);
        $this->assertSame('app/Http/Controllers/ExportController.php', $out['spec']['suggested_files'][0]);
    }

    public function test_iterates_when_first_spec_is_vague_then_succeeds(): void
    {
        $calls = 0;
        $sawGaps = [];
        $out = (new AtlasLoopIntentSpecCompiler)->compile('rate limit export', function (string $goal, array $priorGaps) use (&$calls, &$sawGaps) {
            $calls++;
            if ($calls === 1) {
                return ['summary' => 'do it', 'acceptance_criteria' => [['id' => 'x', 'description' => 'works']]]; // vague + nothing required-real
            }
            $sawGaps = $priorGaps;

            return $this->goodSpec();
        }, 3);

        $this->assertTrue($out['ready']);
        $this->assertSame(2, $out['attempts']);
        $this->assertNotEmpty($sawGaps, 'gaps from the vague spec are fed back to the generator');
    }

    public function test_refuses_a_spec_with_no_criteria(): void
    {
        $out = (new AtlasLoopIntentSpecCompiler)->compile('x', fn () => ['summary' => 'something real here', 'acceptance_criteria' => []], 2);
        $this->assertFalse($out['ready']);
        $this->assertContains('no_acceptance_criteria', $out['gaps']);
    }

    public function test_refuses_when_everything_is_optional(): void
    {
        $out = (new AtlasLoopIntentSpecCompiler)->compile('x', fn () => [
            'summary' => 'a real summary of the goal',
            'acceptance_criteria' => [
                ['id' => 'a', 'description' => 'a sufficiently long but optional criterion', 'required' => false],
            ],
        ], 1);
        $this->assertFalse($out['ready'], 'a spec where everything is optional pins nothing');
        $this->assertContains('no_required_criterion', $out['gaps']);
    }
}
