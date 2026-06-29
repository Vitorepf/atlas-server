<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use Tests\TestCase;

/**
 * Anti-proxy dimension of the packet judge (Checkpoint A author≠judge milestone).
 *
 * The structural inspector waved through blind orphan-wiring packets — structurally perfect, but proxy
 * (wire dead code into the live flow because it is unused). That hole flooded the queue on 2026-06-25.
 * These tests pin the deterministic detector: it flags the blind-wire pattern and spares genuine wiring,
 * deletion, and wire-or-delete decision packets. It is ADVISORY (surfaced, not yet blocking).
 */
final class AtlasTaskPacketAntiProxyInspectionTest extends TestCase
{
    private function inspect(string $objective): array
    {
        return (new AtlasTaskPacketQualityInspector)->inspect([
            'objective' => $objective,
            'allowed_files' => ['app/Services/Ai/AutonomousEvolution/Foo.php'],
            'acceptance_criteria' => ['php artisan test asserts Foo behaves'],
            'evidence_requirements' => ['tests_or_gates_result'],
        ]);
    }

    public function test_flags_the_replenisher_blind_orphan_wiring_template(): void
    {
        $result = $this->inspect(
            'Wire the built-but-unused capability Foo (App\\Services\\Ai\\AutonomousEvolution\\Foo) into the '
            .'live flow. It exists at app/Services/Ai/AutonomousEvolution/Foo.php with ZERO production callers '
            .'(a confirmed orphan). Integrate it the same way its sibling is wired.'
        );

        $this->assertContains('blind_orphan_wiring_proxy', $result['deficiencies']);
        $this->assertTrue($result['facts']['blind_orphan_wiring_proxy']);
        // ADVISORY for now — must NOT block admission yet.
        $this->assertNotContains('blind_orphan_wiring_proxy', $result['blocking_deficiencies']);
    }

    public function test_spares_a_need_driven_wiring_task(): void
    {
        $result = $this->inspect(
            'Wire App\\Services\\Ai\\SelfConstruction\\Maestro\\PriorityFactSnapshotter into the live flow so the '
            .'Maestro dynamic-priority loop consumes real queue-depth facts, invoked from AppServiceProvider.'
        );

        $this->assertNotContains('blind_orphan_wiring_proxy', $result['deficiencies']);
    }

    public function test_spares_a_delete_dead_code_task(): void
    {
        $result = $this->inspect(
            'Eliminate dead legacy instrumentation: DELETE the built-but-unused class '
            .'App\\Services\\Ai\\AutonomousEvolution\\Foo (0 production references) and its test.'
        );

        $this->assertNotContains('blind_orphan_wiring_proxy', $result['deficiencies']);
    }

    public function test_spares_a_wire_or_delete_decision_task(): void
    {
        $result = $this->inspect(
            'Decide whether the built-but-unused capability Foo is dead weight to DELETE or a real capability '
            .'to wire into the live flow, and execute the chosen disposition with proof.'
        );

        $this->assertNotContains('blind_orphan_wiring_proxy', $result['deficiencies']);
    }

    public function test_blocks_dormant_cli_arm_wrapper_farm_template(): void
    {
        $result = $this->inspect(
            'Arm the dormant service `App\\Services\\Ai\\SelfConstruction\\FooService` at the operator surface: '
            .'add a new read-only `php artisan atlas:loop:arm-foo-service` command that resolves it and prints '
            .'build() as JSON (schema_version + result keys), proven by ArmFooServiceCommandTest asserting exit 0 '
            .'and the JSON schema.'
        );

        $this->assertContains('dormant_cli_arm_proxy', $result['deficiencies']);
        $this->assertContains('dormant_cli_arm_proxy', $result['blocking_deficiencies']);
        $this->assertTrue($result['facts']['dormant_cli_arm_proxy']);
    }

    public function test_spares_dormant_cli_task_that_changes_a_live_decision(): void
    {
        $result = $this->inspect(
            'Arm the dormant service `App\\Services\\Ai\\SelfConstruction\\FooService` inside the live queue '
            .'gate so it reads live task records and changes the decision fail-closed when poison is detected; '
            .'prove with php artisan test --filter=FooServiceLiveDecisionTest.'
        );

        $this->assertNotContains('dormant_cli_arm_proxy', $result['deficiencies']);
    }
}
