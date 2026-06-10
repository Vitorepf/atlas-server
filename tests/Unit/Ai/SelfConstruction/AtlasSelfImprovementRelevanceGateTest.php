<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfImprovementRelevanceGate;
use PHPUnit\Framework\TestCase;

/**
 * S3.F1 — the OUT-OF-PROCESS relevance gate, as a pure decision function. This is
 * the load-bearing safety that stops the 412-line-garbage failure: it compares the
 * FACTS of the signal (the named file) against the FACTS of what was delivered (the
 * touched files), never the generation prompt — so the provider cannot game it.
 */
final class AtlasSelfImprovementRelevanceGateTest extends TestCase
{
    private function gate(): AtlasSelfImprovementRelevanceGate
    {
        return new AtlasSelfImprovementRelevanceGate;
    }

    public function test_exact_file_touch_is_on_target(): void
    {
        $v = $this->gate()->evaluate(
            ['area' => 'code', 'file' => 'app/Services/Widget.php', 'line' => 7, 'signal' => 'TODO: guard'],
            ['delivered' => true, 'branch' => 'atlas/materialize/x', 'delivery' => ['files' => ['app/Services/Widget.php']]],
        );

        $this->assertTrue($v['relevant']);
        $this->assertSame('on_target', $v['reason']);
        $this->assertSame('app/Services/Widget.php', $v['matched_file']);
    }

    public function test_sibling_under_same_dir_is_on_target(): void
    {
        // A defensible adjacent change in the same directory the operator reviews in context.
        $v = $this->gate()->evaluate(
            ['file' => 'app/Services/Widget.php', 'line' => 7],
            ['delivered' => true, 'delivery' => ['files' => ['app/Services/WidgetGuard.php']]],
        );

        $this->assertTrue($v['relevant']);
        $this->assertSame('app/Services/WidgetGuard.php', $v['matched_file']);
    }

    public function test_unrelated_file_is_off_target_rejected(): void
    {
        // The 412-line-garbage case: signal about Widget.php, generation produced an
        // unrelated Hermes Kanban driver. DEFAULT-REFUSE.
        $v = $this->gate()->evaluate(
            ['file' => 'app/Services/Widget.php', 'line' => 7, 'signal' => 'TODO: running/scheduled tasks'],
            ['delivered' => true, 'branch' => 'atlas/materialize/x', 'delivery' => ['files' => ['app/Services/Hermes/HermesKanbanDriver.php']]],
        );

        $this->assertFalse($v['relevant']);
        $this->assertSame('off_target_generation', $v['reason']);
        $this->assertNull($v['matched_file']);
        $this->assertSame('app/Services/Widget.php', $v['target_file']);
    }

    public function test_empty_delivery_is_never_relevant(): void
    {
        $v = $this->gate()->evaluate(
            ['file' => 'app/Services/Widget.php', 'line' => 7],
            ['delivered' => true, 'delivery' => ['files' => []]],
        );
        $this->assertFalse($v['relevant'], 'the gate never fabricates a pass for an empty delivery');
        $this->assertSame('no_delivery_to_check', $v['reason']);
    }

    public function test_blocked_delivery_is_never_relevant(): void
    {
        $v = $this->gate()->evaluate(
            ['file' => 'app/Services/Widget.php', 'line' => 7],
            ['delivered' => false, 'delivery' => ['files' => ['app/Services/Widget.php']]],
        );
        $this->assertFalse($v['relevant']);
        $this->assertSame('no_delivery_to_check', $v['reason']);
    }

    public function test_operator_gap_no_file_is_admitted_as_unverifiable(): void
    {
        $v = $this->gate()->evaluate(
            ['area' => 'operator', 'file' => null, 'line' => null, 'signal' => 'Harden the secret scanner'],
            ['delivered' => true, 'delivery' => ['files' => ['app/Services/Security/Scanner.php']]],
        );

        $this->assertTrue($v['relevant'], 'a fileless operator gap cannot be target-checked; admitted');
        $this->assertSame('no_target_unverifiable_admitted', $v['reason']);
        $this->assertNull($v['target_file']);
    }

    public function test_path_normalisation_handles_leading_slash_and_dot(): void
    {
        $v = $this->gate()->evaluate(
            ['file' => './app/Services/Widget.php', 'line' => 7],
            ['delivered' => true, 'delivery' => ['files' => ['/app/Services/Widget.php']]],
        );
        $this->assertTrue($v['relevant'], 'normalised paths must compare equal');
        $this->assertSame('app/Services/Widget.php', $v['matched_file']);
    }

    public function test_accepts_orchestrator_shape_with_path_keyed_files(): void
    {
        // The orchestrator's raw shape can carry {path:...} entries — gate must read both.
        $v = $this->gate()->evaluate(
            ['file' => 'app/Services/Widget.php', 'line' => 7],
            ['delivered' => true, 'files' => [['path' => 'app/Services/Widget.php']]],
        );
        $this->assertTrue($v['relevant']);
    }
}
