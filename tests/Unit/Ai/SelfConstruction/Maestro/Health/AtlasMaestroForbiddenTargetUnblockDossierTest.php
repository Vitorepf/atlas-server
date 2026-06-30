<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Health;

use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroForbiddenTargetUnblockDossier;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroForbiddenTargetUnblockDossierTest extends TestCase
{
    private function dossier(): AtlasMaestroForbiddenTargetUnblockDossier
    {
        return new AtlasMaestroForbiddenTargetUnblockDossier;
    }

    // ── AC1: output shape ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->dossier()->build([]);
        $this->assertSame(AtlasMaestroForbiddenTargetUnblockDossier::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('severity', $r);
        $this->assertArrayHasKey('unblock_action', $r);
        $this->assertArrayHasKey('respec_recommendation', $r);
        $this->assertArrayHasKey('requires_operator_review', $r);
        $this->assertArrayHasKey('diagnostics', $r);
    }

    // ── Severity rules ────────────────────────────────────────────────────────

    public function test_self_target_failure_is_high_severity(): void
    {
        $r = $this->dossier()->build([
            'failure_reason'  => 'forbidden_self_target',
            'forbidden_paths' => ['app/Services/Ai/TaskFabric/Foo.php'],
        ]);

        $this->assertSame('high', $r['severity']);
        $this->assertTrue($r['requires_operator_review']);
        $this->assertTrue($r['diagnostics']['is_self_target']);
    }

    public function test_core_system_path_is_high_severity(): void
    {
        $r = $this->dossier()->build([
            'forbidden_paths' => ['app/Services/Ai/Brain/AtlasBrainCore.php'],
        ]);

        $this->assertSame('high', $r['severity']);
        $this->assertTrue($r['diagnostics']['touches_core_system']);
    }

    public function test_multiple_forbidden_paths_is_medium_severity(): void
    {
        $r = $this->dossier()->build([
            'forbidden_paths' => ['app/Foo.php', 'app/Bar.php'],
        ]);

        $this->assertSame('medium', $r['severity']);
        $this->assertFalse($r['requires_operator_review']);
    }

    public function test_single_non_core_forbidden_path_is_low_severity(): void
    {
        $r = $this->dossier()->build([
            'forbidden_paths' => ['app/Services/Utility/HelperFoo.php'],
        ]);

        $this->assertSame('low', $r['severity']);
    }

    // ── unblock_action rules ──────────────────────────────────────────────────

    public function test_many_forbidden_paths_decomposes_into_subtasks(): void
    {
        $r = $this->dossier()->build([
            'forbidden_paths' => ['a.php', 'b.php', 'c.php', 'd.php'],
        ]);

        $this->assertSame('decompose_into_subtasks', $r['unblock_action']);
    }

    public function test_with_allowed_paths_action_is_respec_to_allowed_target(): void
    {
        $r = $this->dossier()->build([
            'forbidden_paths' => ['app/Brain/Core.php'],
            'allowed_paths'   => ['app/Services/Proxy/BrainProxy.php'],
        ]);

        $this->assertSame('respec_to_allowed_target', $r['unblock_action']);
    }

    public function test_high_severity_with_no_allowed_paths_delegates_to_operator(): void
    {
        $r = $this->dossier()->build([
            'failure_reason'  => 'forbidden_self_target',
            'forbidden_paths' => ['app/Brain/Core.php'],
            // no allowed_paths
        ]);

        $this->assertSame('delegate_to_operator', $r['unblock_action']);
    }

    // ── AC2: respec_recommendation is always non-forbidden ───────────────────

    public function test_respec_recommendation_excludes_forbidden_paths(): void
    {
        $forbidden = ['app/Brain/Core.php'];
        $r         = $this->dossier()->build([
            'forbidden_paths' => $forbidden,
            'allowed_paths'   => ['app/Services/Proxy/BrainProxy.php', 'app/Brain/Core.php'],
        ]);

        $suggested = $r['respec_recommendation']['suggested_paths'];
        foreach ($suggested as $path) {
            $this->assertNotContains($path, $forbidden, "Suggested path '$path' is forbidden");
        }
    }

    public function test_respec_recommendation_always_present_even_without_allowed_paths(): void
    {
        $r = $this->dossier()->build([
            'forbidden_paths'    => ['app/Brain/Core.php'],
            'original_objective' => 'Add health check capability',
        ]);

        $this->assertArrayHasKey('strategy', $r['respec_recommendation']);
        $this->assertArrayHasKey('description', $r['respec_recommendation']);
        $this->assertNotEmpty($r['respec_recommendation']['strategy']);
        $this->assertNotEmpty($r['respec_recommendation']['description']);
    }

    public function test_respec_recommendation_with_no_paths_uses_proxy_strategy(): void
    {
        $r = $this->dossier()->build(['forbidden_paths' => ['app/Foo.php']]);

        $this->assertSame('introduce_allowed_proxy_layer', $r['respec_recommendation']['strategy']);
    }

    public function test_respec_recommendation_with_allowed_paths_uses_interface_strategy(): void
    {
        $r = $this->dossier()->build([
            'forbidden_paths' => ['app/Foo.php'],
            'allowed_paths'   => ['app/Services/Proxy/FooProxy.php'],
        ]);

        $this->assertSame('target_allowed_interface_layer', $r['respec_recommendation']['strategy']);
        $this->assertContains('app/Services/Proxy/FooProxy.php', $r['respec_recommendation']['suggested_paths']);
    }

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'task_id'            => 'task-42',
            'failure_reason'     => 'forbidden_self_target',
            'forbidden_paths'    => ['app/Brain/Core.php'],
            'original_objective' => 'Strengthen health monitor',
            'allowed_paths'      => ['app/Services/Health/HealthProxy.php'],
        ];
        $a = $this->dossier()->build($facts);
        $b = $this->dossier()->build($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
