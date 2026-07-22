<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopDecompositionService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopDecompositionShapeFingerprinter;
use Tests\TestCase;

/**
 * Proves the DECOMPOR service: flag-OFF null no-op; deterministic content-hash task_ids (byte-stable across
 * regeneration); plan_fingerprint equals the canonical fingerprinter on the same plan; ATOMIC fail-closed when
 * any task citation is ungrounded against the cleared draft (no partial plan).
 */
final class AtlasLoopDecompositionServiceTest extends TestCase
{
    private function service(): AtlasLoopDecompositionService
    {
        return new AtlasLoopDecompositionService;
    }

    /** @return array<string,mixed> */
    private function groundedDraft(): array
    {
        return [
            'objective' => 'wire the Foo cluster',
            'cited_symbols' => ['App\\Foo\\Bar', 'App\\Foo\\Baz'],
            'proposed_files' => ['app/Foo/Bar.php', 'app/Foo/Baz.php'],
        ];
    }

    public function test_flag_off_is_null_no_op(): void
    {
        config(['atlas.loop.decomposition_service_enabled' => false]);

        $this->assertNull($this->service()->decompose($this->groundedDraft()));
    }

    public function test_deterministic_plan_with_stable_task_ids(): void
    {
        config(['atlas.loop.decomposition_service_enabled' => true]);

        $plan1 = $this->service()->decompose($this->groundedDraft());
        $plan2 = $this->service()->decompose($this->groundedDraft());

        $this->assertTrue($plan1['decomposed']);
        $this->assertSame('atlas.loop.decomposition_plan.v1', $plan1['schema_version']);
        $this->assertCount(2, $plan1['ordered_tasks']);
        $this->assertSame(json_encode($plan1), json_encode($plan2), 'byte-stable across regeneration');

        $first = $plan1['ordered_tasks'][0];
        $this->assertArrayHasKey('task_id', $first);
        $this->assertSame(['app/Foo/Bar.php'], $first['allowed_files']);
        $this->assertSame([], $first['depends_on']);
        $this->assertSame(0, $first['wave']);
        $this->assertSame([$first['task_id']], $plan1['ordered_tasks'][1]['depends_on'], 'sequential dependency on the prior task');
    }

    public function test_plan_fingerprint_matches_canonical_fingerprinter(): void
    {
        config(['atlas.loop.decomposition_service_enabled' => true]);

        $plan = $this->service()->decompose($this->groundedDraft());

        $nodes = array_map(static fn (array $t): array => [
            'id' => $t['task_id'],
            'depends_on' => $t['depends_on'],
            'allowed_files' => $t['allowed_files'],
        ], $plan['ordered_tasks']);

        $expected = (new AtlasLoopDecompositionShapeFingerprinter)->fingerprint(['nodes' => $nodes])['hash'];
        $this->assertSame($expected, $plan['plan_fingerprint'], 'plan_fingerprint is the canonical DAG-shape hash');
    }

    public function test_ungrounded_task_citation_fails_closed_atomically(): void
    {
        config(['atlas.loop.decomposition_service_enabled' => true]);

        $draft = [
            'objective' => 'wire Foo + a ghost',
            'cited_symbols' => ['App\\Foo\\Bar'],          // Ghost is NOT cited
            'proposed_files' => ['app/Foo/Bar.php', 'app/Foo/Ghost.php'],
        ];

        $result = $this->service()->decompose($draft);

        $this->assertFalse($result['decomposed']);
        $this->assertSame('ungrounded_task_citation', $result['reason']);
        $this->assertContains('Ghost', $result['refuted']);
        $this->assertArrayNotHasKey('ordered_tasks', $result, 'atomic: no partial plan is emitted');
    }
}
