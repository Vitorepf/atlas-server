<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionNativePatchPlanner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Proves AtlasSelfConstructionNativePatchPlanner: pure-service task yields a plan with template_id
 * 'pure_service' and a determined plan_id; CLI wrapper task picks 'cli_wrapper' with Command import;
 * scope_file outside allowed_files throws; task_shape with no matching template throws;
 * provider_reasoning_required=true throws.
 */
final class AtlasSelfConstructionNativePatchPlannerTest extends TestCase
{
    private function purePacket(): array
    {
        return [
            'allowed_files' => ['app/Demo/Foo.php', 'tests/Unit/Demo/FooTest.php'],
            'scope_files' => ['app/Demo/Foo.php'],
            'task_shape' => ['kind' => 'service', 'side_effects' => 'none'],
            'context' => ['namespace' => 'App\\Demo', 'class_name' => 'Foo', 'method_name' => 'bar'],
            'test_files' => ['tests/Unit/Demo/FooTest.php'],
        ];
    }

    public function test_pure_service_packet_yields_plan_with_pure_service_template(): void
    {
        $r = (new AtlasSelfConstructionNativePatchPlanner)->plan($this->purePacket());
        $this->assertContains('pure_service', $r['template_ids']);
        $this->assertSame(64, strlen($r['plan_id']));
    }

    public function test_cli_wrapper_packet_picks_cli_wrapper_template_with_command_import(): void
    {
        $r = (new AtlasSelfConstructionNativePatchPlanner)->plan([
            'allowed_files' => ['app/Console/Commands/DemoCli.php'],
            'scope_files' => ['app/Console/Commands/DemoCli.php'],
            'task_shape' => ['kind' => 'cli_wrapper'],
            'context' => ['namespace' => 'App\\Console\\Commands', 'class_name' => 'DemoCli', 'signature' => 'demo', 'description' => 'd'],
        ]);
        $this->assertContains('cli_wrapper', $r['template_ids']);
        $this->assertContains('Illuminate\\Console\\Command', $r['required_imports']);
    }

    public function test_scope_file_outside_allowed_files_throws(): void
    {
        $p = $this->purePacket();
        $p['scope_files'] = ['/etc/passwd'];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/scope_file_outside_allowed/');
        (new AtlasSelfConstructionNativePatchPlanner)->plan($p);
    }

    public function test_no_matching_template_throws(): void
    {
        $p = $this->purePacket();
        $p['task_shape'] = ['kind' => 'unknown_witchcraft'];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no_matching_template_for_task_shape/');
        (new AtlasSelfConstructionNativePatchPlanner)->plan($p);
    }

    public function test_provider_reasoning_required_throws(): void
    {
        $p = $this->purePacket();
        $p['provider_reasoning_required'] = true;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/provider_reasoning_required/');
        (new AtlasSelfConstructionNativePatchPlanner)->plan($p);
    }

    public function test_forbidden_path_in_scope_files_throws(): void
    {
        $p = $this->purePacket();
        $p['forbidden_files'] = ['app/Demo/Foo.php'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/forbidden_path/');
        (new AtlasSelfConstructionNativePatchPlanner)->plan($p);
    }

    public function test_speculative_abstraction_task_shape_throws(): void
    {
        $p = $this->purePacket();
        $p['task_shape']['speculative_abstraction'] = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/speculative_abstraction_in_task_shape/');
        (new AtlasSelfConstructionNativePatchPlanner)->plan($p);
    }

    public function test_minimal_plan_selected_from_multiple_candidate_template_ids(): void
    {
        // pure_service has 0 imports; cli_wrapper has 1 (Command).
        // Planner must pick pure_service (minimal).
        $p = $this->purePacket();
        $p['candidate_template_ids'] = ['cli_wrapper', 'pure_service'];

        $r = (new AtlasSelfConstructionNativePatchPlanner)->plan($p);

        $this->assertSame('pure_service', $r['template_ids'][0]);
    }

    public function test_plan_id_is_byte_identical_for_same_input(): void
    {
        $pp = new AtlasSelfConstructionNativePatchPlanner;
        $a = $pp->plan($this->purePacket());
        $b = $pp->plan($this->purePacket());
        $this->assertSame($a['plan_id'], $b['plan_id']);
    }

    public function test_empty_allowed_files_throws(): void
    {
        $p = $this->purePacket();
        $p['allowed_files'] = [];
        $p['scope_files'] = [];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/allowed_files empty/');
        (new AtlasSelfConstructionNativePatchPlanner)->plan($p);
    }

    // ── missing_test_plan risk note ───────────────────────────────────────────

    public function test_absent_test_files_adds_missing_test_plan_risk_note(): void
    {
        $p = $this->purePacket();
        unset($p['test_files']);

        $r = (new AtlasSelfConstructionNativePatchPlanner)->plan($p);

        $this->assertContains('missing_test_plan', $r['risk_notes']);
    }

    public function test_empty_test_files_adds_missing_test_plan_risk_note(): void
    {
        $p = $this->purePacket();
        $p['test_files'] = [];

        $r = (new AtlasSelfConstructionNativePatchPlanner)->plan($p);

        $this->assertContains('missing_test_plan', $r['risk_notes']);
    }

    public function test_test_files_outside_allowed_scope_adds_missing_test_plan_risk_note(): void
    {
        $p = $this->purePacket();
        $p['test_files'] = ['/outside/the/scope/SomeTest.php']; // not in allowed_files

        $r = (new AtlasSelfConstructionNativePatchPlanner)->plan($p);

        $this->assertContains('missing_test_plan', $r['risk_notes']);
    }

    public function test_test_files_within_allowed_scope_omits_missing_test_plan_risk_note(): void
    {
        // purePacket has test_files=['tests/Unit/Demo/FooTest.php'] which IS in allowed_files
        $r = (new AtlasSelfConstructionNativePatchPlanner)->plan($this->purePacket());

        $this->assertNotContains('missing_test_plan', $r['risk_notes']);
    }

    // ── minimal_template_reason ───────────────────────────────────────────────

    public function test_multiple_candidates_exposes_minimal_template_reason(): void
    {
        $p = $this->purePacket();
        $p['candidate_template_ids'] = ['cli_wrapper', 'pure_service'];

        $r = (new AtlasSelfConstructionNativePatchPlanner)->plan($p);

        $this->assertArrayHasKey('minimal_template_reason', $r);
        $this->assertNotNull($r['minimal_template_reason']);
        $this->assertStringContainsString('pure_service', $r['minimal_template_reason']);
    }

    public function test_single_candidate_minimal_template_reason_is_null(): void
    {
        $r = (new AtlasSelfConstructionNativePatchPlanner)->plan($this->purePacket());

        $this->assertArrayHasKey('minimal_template_reason', $r);
        $this->assertNull($r['minimal_template_reason']);
    }
}
