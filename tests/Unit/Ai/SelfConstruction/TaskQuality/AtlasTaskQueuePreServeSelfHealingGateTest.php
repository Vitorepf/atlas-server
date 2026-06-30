<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskQueuePreServeSelfHealingGate;
use PHPUnit\Framework\TestCase;

final class AtlasTaskQueuePreServeSelfHealingGateTest extends TestCase
{
    private function gate(): AtlasTaskQueuePreServeSelfHealingGate
    {
        return new AtlasTaskQueuePreServeSelfHealingGate;
    }

    private function healthyPacket(array $overrides = []): array
    {
        return array_merge([
            'id'                   => 'pkt1',
            'objective'            => 'Implement X',
            'allowed_files'        => ['app/Foo.php', 'tests/FooTest.php'],
            'acceptance_criteria'  => ['Tests pass'],
            'test_only_has_contract' => false,
            'dependencies'         => [],
        ], $overrides);
    }

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->gate()->classify([]);
        $this->assertSame(AtlasTaskQueuePreServeSelfHealingGate::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('classification',     $r);
        $this->assertArrayHasKey('reasons',            $r);
        $this->assertArrayHasKey('unmet_dependencies', $r);
        $this->assertArrayHasKey('repair_hints',       $r);
    }

    // ── AC2: serve ────────────────────────────────────────────────────────────

    public function test_healthy_packet_returns_serve(): void
    {
        $r = $this->gate()->classify(['packet' => $this->healthyPacket()]);
        $this->assertSame('serve', $r['classification']);
        $this->assertEmpty($r['reasons']);
        $this->assertEmpty($r['unmet_dependencies']);
    }

    // ── AC3: dependency_wait beats respec ─────────────────────────────────────

    public function test_unmet_dependency_returns_dependency_wait(): void
    {
        $r = $this->gate()->classify([
            'packet'                => $this->healthyPacket(['dependencies' => ['dep-1', 'dep-2']]),
            'resolved_dependencies' => ['dep-1'],
        ]);
        $this->assertSame('dependency_wait', $r['classification']);
        $this->assertContains('dep-2', $r['unmet_dependencies']);
        $this->assertNotContains('dep-1', $r['unmet_dependencies']);
    }

    public function test_dependency_wait_even_when_packet_has_other_issues(): void
    {
        // dep unmet + empty acceptance → dependency_wait wins (AC3).
        $r = $this->gate()->classify([
            'packet' => $this->healthyPacket([
                'dependencies'        => ['dep-x'],
                'acceptance_criteria' => [],
            ]),
            'resolved_dependencies' => [],
        ]);
        $this->assertSame('dependency_wait', $r['classification']);
    }

    public function test_all_deps_resolved_does_not_trigger_dependency_wait(): void
    {
        $r = $this->gate()->classify([
            'packet'                => $this->healthyPacket(['dependencies' => ['dep-1']]),
            'resolved_dependencies' => ['dep-1'],
        ]);
        $this->assertSame('serve', $r['classification']);
        $this->assertEmpty($r['unmet_dependencies']);
    }

    // ── AC2: respec — scope too many files ────────────────────────────────────

    public function test_too_many_allowed_files_returns_respec(): void
    {
        $r = $this->gate()->classify([
            'packet'      => $this->healthyPacket(['allowed_files' => ['a', 'b', 'c', 'd', 'e', 'f']]),
            'scope_rules' => ['max_files' => 5],
        ]);
        $this->assertSame('respec', $r['classification']);
        $this->assertContains('scope_too_many_files', $r['reasons']);
    }

    // ── AC2: respec — forbidden target ────────────────────────────────────────

    public function test_forbidden_target_in_allowed_files_returns_respec(): void
    {
        $r = $this->gate()->classify([
            'packet'      => $this->healthyPacket(['allowed_files' => ['app/Forbidden.php']]),
            'scope_rules' => ['forbidden_targets' => ['app/Forbidden.php']],
        ]);
        $this->assertSame('respec', $r['classification']);
        $this->assertContains('forbidden_target', $r['reasons']);
        $this->assertContains('app/Forbidden.php', $r['repair_hints']['forbidden_files']);
    }

    // ── AC2: respec — empty acceptance ────────────────────────────────────────

    public function test_empty_acceptance_criteria_returns_respec(): void
    {
        $r = $this->gate()->classify([
            'packet' => $this->healthyPacket(['acceptance_criteria' => []]),
        ]);
        $this->assertSame('respec', $r['classification']);
        $this->assertContains('empty_acceptance_criteria', $r['reasons']);
    }

    // ── AC2: respec — empty objective ─────────────────────────────────────────

    public function test_empty_objective_returns_respec(): void
    {
        $r = $this->gate()->classify([
            'packet' => $this->healthyPacket(['objective' => '']),
        ]);
        $this->assertSame('respec', $r['classification']);
        $this->assertContains('empty_objective', $r['reasons']);
    }

    // ── Multiple respec reasons accumulated ───────────────────────────────────

    public function test_multiple_respec_reasons_all_reported(): void
    {
        $r = $this->gate()->classify([
            'packet'      => $this->healthyPacket([
                'objective'           => '',
                'acceptance_criteria' => [],
            ]),
        ]);
        $this->assertSame('respec', $r['classification']);
        $this->assertContains('empty_objective',          $r['reasons']);
        $this->assertContains('empty_acceptance_criteria', $r['reasons']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'packet'                => $this->healthyPacket(['dependencies' => ['d1']]),
            'resolved_dependencies' => [],
            'scope_rules'           => ['max_files' => 3, 'forbidden_targets' => []],
        ];
        $a = $this->gate()->classify($facts);
        $b = $this->gate()->classify($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
