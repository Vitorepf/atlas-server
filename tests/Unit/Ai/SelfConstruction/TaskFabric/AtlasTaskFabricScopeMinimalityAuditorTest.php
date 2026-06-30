<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricScopeMinimalityAuditor;
use Tests\TestCase;

final class AtlasTaskFabricScopeMinimalityAuditorTest extends TestCase
{
    private function svc(): AtlasTaskFabricScopeMinimalityAuditor
    {
        return new AtlasTaskFabricScopeMinimalityAuditor;
    }

    private function cleanSpec(array $overrides = []): array
    {
        return $overrides + [
            'allowed_files' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
            'forbidden_files' => [],
        ];
    }

    // ── scope_ok: accepted cases ──────────────────────────────────────────────

    public function test_minimal_impl_and_test_scope_is_accepted(): void
    {
        $r = $this->svc()->audit($this->cleanSpec());

        $this->assertTrue($r['scope_ok']);
        $this->assertSame([], $r['missing_required_files']);
        $this->assertSame([], $r['overbroad_files']);
        $this->assertSame([], $r['hidden_self_target_flags']);
        $this->assertSame(AtlasTaskFabricScopeMinimalityAuditor::SCHEMA, $r['schema_version']);
    }

    // ── missing required files ────────────────────────────────────────────────

    public function test_test_only_scope_flags_missing_implementation(): void
    {
        $r = $this->svc()->audit($this->cleanSpec([
            'allowed_files' => ['tests/Unit/FooTest.php'],
        ]));

        $this->assertFalse($r['scope_ok']);
        $this->assertContains('implementation_file', $r['missing_required_files']);
        $this->assertNotContains('test_file', $r['missing_required_files']);
    }

    public function test_impl_only_scope_flags_missing_test(): void
    {
        $r = $this->svc()->audit($this->cleanSpec([
            'allowed_files' => ['app/Services/Foo.php'],
        ]));

        $this->assertFalse($r['scope_ok']);
        $this->assertContains('test_file', $r['missing_required_files']);
        $this->assertNotContains('implementation_file', $r['missing_required_files']);
    }

    public function test_empty_allowed_files_flags_both_missing(): void
    {
        $r = $this->svc()->audit($this->cleanSpec(['allowed_files' => []]));

        $this->assertFalse($r['scope_ok']);
        $this->assertContains('implementation_file', $r['missing_required_files']);
        $this->assertContains('test_file', $r['missing_required_files']);
        $this->assertSame(0, $r['allowed_files_count']);
    }

    // ── overbroad files ───────────────────────────────────────────────────────

    public function test_bare_directory_is_overbroad(): void
    {
        $r = $this->svc()->audit($this->cleanSpec([
            'allowed_files' => ['app/Services/', 'tests/Unit/FooTest.php'],
        ]));

        $this->assertFalse($r['scope_ok']);
        $this->assertContains('app/Services/', $r['overbroad_files']);
    }

    public function test_wildcard_path_is_overbroad(): void
    {
        $r = $this->svc()->audit($this->cleanSpec([
            'allowed_files' => ['app/Services/*.php', 'tests/Unit/FooTest.php'],
        ]));

        $this->assertFalse($r['scope_ok']);
        $this->assertContains('app/Services/*.php', $r['overbroad_files']);
    }

    public function test_extensionless_file_path_is_overbroad(): void
    {
        $r = $this->svc()->audit($this->cleanSpec([
            'allowed_files' => ['app/Services/SomeBinary', 'tests/Unit/FooTest.php'],
        ]));

        $this->assertFalse($r['scope_ok']);
        $this->assertContains('app/Services/SomeBinary', $r['overbroad_files']);
    }

    // ── hidden self-targets ───────────────────────────────────────────────────

    public function test_hidden_self_target_flagged_when_file_in_both_allowed_and_forbidden(): void
    {
        $r = $this->svc()->audit([
            'allowed_files' => ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
            'forbidden_files' => ['app/Services/Foo.php'],
        ]);

        $this->assertFalse($r['scope_ok']);
        $this->assertContains('app/Services/Foo.php', $r['hidden_self_target_flags']);
    }

    public function test_no_self_target_when_forbidden_is_empty(): void
    {
        $r = $this->svc()->audit($this->cleanSpec());

        $this->assertSame([], $r['hidden_self_target_flags']);
    }

    // ── combined failures ─────────────────────────────────────────────────────

    public function test_multiple_issues_all_reported(): void
    {
        $r = $this->svc()->audit([
            'allowed_files' => ['tests/Unit/FooTest.php', 'app/Services/'],
            'forbidden_files' => ['tests/Unit/FooTest.php'],
        ]);

        $this->assertFalse($r['scope_ok']);
        $this->assertContains('implementation_file', $r['missing_required_files']);
        $this->assertContains('app/Services/', $r['overbroad_files']);
        $this->assertContains('tests/Unit/FooTest.php', $r['hidden_self_target_flags']);
    }

    public function test_allowed_files_count_reflects_non_empty_entries(): void
    {
        $r = $this->svc()->audit($this->cleanSpec([
            'allowed_files' => ['app/A.php', 'app/B.php', 'tests/ATest.php'],
        ]));

        $this->assertSame(3, $r['allowed_files_count']);
    }
}
