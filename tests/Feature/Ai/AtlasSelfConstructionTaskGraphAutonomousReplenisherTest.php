<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasSelfConstructionTaskGraphAutonomousReplenisher;
use Tests\TestCase;

/**
 * Feature-level gate for AtlasSelfConstructionTaskGraphAutonomousReplenisher.
 *
 * Verifies dry-run default, apply-mode callback invocation with max_applied bounds,
 * and per-task callback error isolation without aborting the cycle.
 */
final class AtlasSelfConstructionTaskGraphAutonomousReplenisherTest extends TestCase
{
    private function validDraft(string $id): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => 'fill '.$id,
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/'.$id.'/Service.php',
                'tests/Unit/Ai/SelfConstruction/'.$id.'/ServiceTest.php',
            ],
            'scope_in' => [
                'app/Services/Ai/SelfConstruction/'.$id.'/Service.php',
                'tests/Unit/Ai/SelfConstruction/'.$id.'/ServiceTest.php',
            ],
            'acceptance_criteria' => ['noop'],
            'required_evidence' => ['tests_or_gates_result'],
            'expected_delta' => 'Adds '.$id.' capability.',
            'anti_proxy' => 'Green tests prove real behavior.',
            'depends_on' => [],
            'wave' => 'w',
            'tags' => ['self_construction', $id],
            'priority' => 5,
            'final_runtime_owner' => 'atlas_native',
            'steady_state_runtime_owner' => 'atlas_server',
            'requires_operator' => false,
            'requires_human' => false,
            'requires_external_provider' => false,
        ];
    }

    // ── AC1: Default dry-run, applies zero ───────────────────────────────────

    public function test_without_apply_and_callback_returns_dry_run_zero_applied(): void
    {
        $called = 0;
        $verdict = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->run(
            ['status' => 'incomplete'],
            [$this->validDraft('a-1'), $this->validDraft('b-1')],
            [],
            ['enqueue_callback' => function () use (&$called) { $called++; }],
        );

        $this->assertTrue($verdict['dry_run']);
        $this->assertSame(0, $verdict['applied_count']);
        $this->assertSame(0, $called);
    }

    public function test_apply_without_callback_stays_dry_run(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->run(
            ['status' => 'incomplete'],
            [$this->validDraft('a-1')],
            [],
            ['apply' => true],
        );

        $this->assertTrue($verdict['dry_run']);
        $this->assertSame(0, $verdict['applied_count']);
    }

    // ── AC2: Apply mode invokes callback, max_applied bounds ────────────────

    public function test_apply_invokes_callback_for_enqueue_inputs(): void
    {
        $received = [];
        $verdict = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->run(
            ['status' => 'incomplete'],
            [$this->validDraft('a-1'), $this->validDraft('b-1')],
            [],
            [
                'apply' => true,
                'enqueue_callback' => function (array $input) use (&$received): array {
                    $received[] = $input['task_packet']['task_packet_id'];
                    return ['enqueued' => true];
                },
            ],
        );

        $this->assertFalse($verdict['dry_run']);
        $this->assertSame(2, $verdict['applied_count']);
        $this->assertSame(['a-1', 'b-1'], $received);
    }

    public function test_max_applied_caps_and_withholds_remaining(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->run(
            ['status' => 'incomplete'],
            [$this->validDraft('a-1'), $this->validDraft('b-1'), $this->validDraft('c-1')],
            [],
            [
                'apply' => true,
                'max_applied' => 2,
                'enqueue_callback' => static fn(): array => ['enqueued' => true],
            ],
        );

        $this->assertSame(2, $verdict['applied_count']);
        $this->assertSame(1, $verdict['withheld_count']);
        $reasons = array_column($verdict['plan']['withheld'], 'reason');
        $this->assertContains('max_applied_reached', $reasons);
    }

    // ── AC3: Callback errors captured per task, don't abort cycle ───────────

    public function test_callback_error_captured_per_task_does_not_abort(): void
    {
        $verdict = (new AtlasSelfConstructionTaskGraphAutonomousReplenisher)->run(
            ['status' => 'incomplete'],
            [$this->validDraft('a-1'), $this->validDraft('b-1')],
            [],
            [
                'apply' => true,
                'enqueue_callback' => static function (array $input): array {
                    if ($input['task_packet']['task_packet_id'] === 'a-1') {
                        throw new \RuntimeException('enqueue failed for a-1');
                    }
                    return ['enqueued' => true];
                },
            ],
        );

        $results = array_column($verdict['enqueue_results'], null, 'task_packet_id');
        $this->assertFalse($results['a-1']['applied']);
        $this->assertNotNull($results['a-1']['error']);
        $this->assertTrue($results['b-1']['applied']);
        $this->assertSame(1, $verdict['applied_count']);
        $this->assertNotEmpty($verdict['replenisher_hash'], 'hash must still be emitted after partial failure');
    }
}
