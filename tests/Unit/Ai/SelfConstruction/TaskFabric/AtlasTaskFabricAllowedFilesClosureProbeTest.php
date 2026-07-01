<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricAllowedFilesClosureProbe;
use Tests\TestCase;

final class AtlasTaskFabricAllowedFilesClosureProbeTest extends TestCase
{
    private function svc(): AtlasTaskFabricAllowedFilesClosureProbe
    {
        return new AtlasTaskFabricAllowedFilesClosureProbe;
    }

    // ── output structure ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->svc()->probe([
            'objective' => 'Implement AtlasFoo so it validates input.',
            'allowed_files' => ['app/Services/Ai/Foo/AtlasFoo.php', 'tests/Unit/Ai/Foo/AtlasFooTest.php'],
        ]);

        foreach (['closed', 'findings', 'missing_files', 'recommended_action'] as $key) {
            $this->assertArrayHasKey($key, $r, "missing key: {$key}");
        }
        $this->assertSame(AtlasTaskFabricAllowedFilesClosureProbe::SCHEMA, $r['schema']);
    }

    public function test_clean_implementation_plus_test_pair_is_closed(): void
    {
        $r = $this->svc()->probe([
            'objective' => 'Implement AtlasFoo so it validates input.',
            'allowed_files' => ['app/Services/Ai/Foo/AtlasFoo.php', 'tests/Unit/Ai/Foo/AtlasFooTest.php'],
        ]);

        $this->assertTrue($r['closed']);
        $this->assertSame([], $r['findings']);
        $this->assertSame(AtlasTaskFabricAllowedFilesClosureProbe::ACTION_PROCEED, $r['recommended_action']);
    }

    // ── AC1: test-only scope rejected ───────────────────────────────────────────

    public function test_test_only_allowed_files_is_rejected_with_missing_implementation_scope(): void
    {
        $r = $this->svc()->probe([
            'objective' => 'Add a characterization test for AtlasFoo.',
            'allowed_files' => ['tests/Unit/Ai/Foo/AtlasFooTest.php'],
        ]);

        $this->assertFalse($r['closed']);
        $this->assertContains('missing_implementation_scope', $r['findings']);
    }

    // ── AC2: implementation-only scope rejected ─────────────────────────────────

    public function test_implementation_only_allowed_files_is_rejected_with_missing_test_scope(): void
    {
        $r = $this->svc()->probe([
            'objective' => 'Implement AtlasFoo so it validates input.',
            'allowed_files' => ['app/Services/Ai/Foo/AtlasFoo.php'],
        ]);

        $this->assertFalse($r['closed']);
        $this->assertContains('missing_test_scope', $r['findings']);
    }

    // ── AC3: objective names a caller/collaborator outside allowed_files ───────

    public function test_objective_naming_a_file_outside_allowed_files_reports_missing_behavior_path(): void
    {
        $r = $this->svc()->probe([
            'objective' => 'Wire AtlasFoo into app/Http/Controllers/FooController.php so requests reach it.',
            'allowed_files' => ['app/Services/Ai/Foo/AtlasFoo.php', 'tests/Unit/Ai/Foo/AtlasFooTest.php'],
        ]);

        $this->assertFalse($r['closed']);
        $this->assertContains('missing_behavior_path', $r['findings']);
        $this->assertContains('app/Http/Controllers/FooController.php', $r['missing_files']);
    }

    public function test_known_collaborators_hint_outside_allowed_files_reports_missing_behavior_path(): void
    {
        $r = $this->svc()->probe([
            'objective' => 'Implement AtlasFoo so it validates input.',
            'allowed_files' => ['app/Services/Ai/Foo/AtlasFoo.php', 'tests/Unit/Ai/Foo/AtlasFooTest.php'],
            'known_collaborators' => ['app/Services/Ai/Bar/AtlasBarCaller.php'],
        ]);

        $this->assertContains('missing_behavior_path', $r['findings']);
        $this->assertContains('app/Services/Ai/Bar/AtlasBarCaller.php', $r['missing_files']);
    }

    public function test_objective_with_no_external_file_reference_has_no_missing_behavior_path(): void
    {
        $r = $this->svc()->probe([
            'objective' => 'Implement AtlasFoo so it validates input.',
            'allowed_files' => ['app/Services/Ai/Foo/AtlasFoo.php', 'tests/Unit/Ai/Foo/AtlasFooTest.php'],
        ]);

        $this->assertNotContains('missing_behavior_path', $r['findings']);
        $this->assertSame([], $r['missing_files']);
    }

    // ── AC4: forbidden real fix path → give_back_or_respec ─────────────────────

    public function test_forbidden_real_fix_path_recommends_give_back_or_respec(): void
    {
        $r = $this->svc()->probe([
            'objective' => 'Wire AtlasFoo into app/Services/Ai/AutonomousEvolution/Constitution/Bylaw.php.',
            'allowed_files' => ['app/Services/Ai/Foo/AtlasFoo.php', 'tests/Unit/Ai/Foo/AtlasFooTest.php'],
            'forbidden_files' => ['app/Services/Ai/AutonomousEvolution/Constitution/Bylaw.php'],
        ]);

        $this->assertContains('forbidden_real_fix_path', $r['findings']);
        $this->assertSame(AtlasTaskFabricAllowedFilesClosureProbe::ACTION_GIVE_BACK_OR_RESPEC, $r['recommended_action']);
    }

    public function test_forbidden_allowed_file_recommends_give_back_or_respec(): void
    {
        $r = $this->svc()->probe([
            'objective' => 'Implement AtlasFoo so it validates input.',
            'allowed_files' => ['app/Services/Ai/AutonomousEvolution/Constitution/Bylaw.php', 'tests/Unit/Ai/Foo/AtlasFooTest.php'],
            'forbidden_files' => ['app/Services/Ai/AutonomousEvolution/Constitution/Bylaw.php'],
        ]);

        $this->assertSame(AtlasTaskFabricAllowedFilesClosureProbe::ACTION_GIVE_BACK_OR_RESPEC, $r['recommended_action']);
    }

    public function test_non_forbidden_findings_recommend_rescope_not_give_back(): void
    {
        $r = $this->svc()->probe([
            'objective' => 'Add a characterization test for AtlasFoo.',
            'allowed_files' => ['tests/Unit/Ai/Foo/AtlasFooTest.php'],
        ]);

        $this->assertSame(AtlasTaskFabricAllowedFilesClosureProbe::ACTION_RESCOPE_ALLOWED_FILES, $r['recommended_action']);
    }

    // ── findings are sorted and deduped ──────────────────────────────────────────

    public function test_findings_are_sorted(): void
    {
        $r = $this->svc()->probe([
            'objective' => 'Wire AtlasFoo into app/Http/Controllers/FooController.php.',
            'allowed_files' => ['tests/Unit/Ai/Foo/AtlasFooTest.php'],
        ]);

        $copy = $r['findings'];
        sort($copy, SORT_STRING);
        $this->assertSame($copy, $r['findings']);
    }

    public function test_missing_files_are_sorted_and_unique(): void
    {
        $r = $this->svc()->probe([
            'objective' => 'Touch app/B.php and app/A.php and app/B.php again.',
            'allowed_files' => [],
        ]);

        $this->assertSame(['app/A.php', 'app/B.php'], $r['missing_files']);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_probe_is_deterministic(): void
    {
        $task = [
            'objective' => 'Wire AtlasFoo into app/Http/Controllers/FooController.php.',
            'allowed_files' => ['app/Services/Ai/Foo/AtlasFoo.php', 'tests/Unit/Ai/Foo/AtlasFooTest.php'],
        ];

        $a = $this->svc()->probe($task);
        $b = $this->svc()->probe($task);

        $this->assertSame(json_encode($a, JSON_UNESCAPED_SLASHES), json_encode($b, JSON_UNESCAPED_SLASHES));
    }

    // ── AC2: missing_test_scope only emitted when acceptance requires a test ────

    public function test_implementation_only_scope_is_accepted_when_acceptance_does_not_require_a_test(): void
    {
        $r = $this->svc()->probe([
            'objective' => 'Update AtlasFoo config default.',
            'allowed_files' => ['app/Services/Ai/Foo/AtlasFoo.php'],
            'acceptance_requires_test' => false,
        ]);

        $this->assertNotContains('missing_test_scope', $r['findings']);
    }

    public function test_implementation_only_scope_still_flags_missing_test_scope_when_explicitly_required(): void
    {
        $r = $this->svc()->probe([
            'objective' => 'Implement AtlasFoo so it validates input.',
            'allowed_files' => ['app/Services/Ai/Foo/AtlasFoo.php'],
            'acceptance_requires_test' => true,
        ]);

        $this->assertContains('missing_test_scope', $r['findings']);
    }

    // ── AC3: recommended_closure is the smallest impl/test pair, no broad dirs ──

    public function test_test_only_scope_recommends_minimal_impl_test_pair(): void
    {
        $r = $this->svc()->probe([
            'objective' => 'Add a characterization test for AtlasFoo.',
            'allowed_files' => ['tests/Unit/Ai/Foo/AtlasFooTest.php'],
        ]);

        $this->assertSame(
            ['app/Ai/Foo/AtlasFoo.php', 'tests/Unit/Ai/Foo/AtlasFooTest.php'],
            $r['recommended_closure'],
        );
    }

    public function test_implementation_only_scope_recommends_minimal_impl_test_pair(): void
    {
        $r = $this->svc()->probe([
            'objective' => 'Implement AtlasFoo so it validates input.',
            'allowed_files' => ['app/Services/Ai/Foo/AtlasFoo.php'],
        ]);

        $this->assertSame(
            ['app/Services/Ai/Foo/AtlasFoo.php', 'tests/Unit/Services/Ai/Foo/AtlasFooTest.php'],
            $r['recommended_closure'],
        );
    }

    public function test_recommended_closure_never_contains_a_broad_directory(): void
    {
        $r = $this->svc()->probe([
            'objective' => 'Add a characterization test for AtlasFoo.',
            'allowed_files' => ['tests/Unit/Ai/Foo/AtlasFooTest.php'],
        ]);

        $this->assertCount(2, $r['recommended_closure']);
        foreach ($r['recommended_closure'] as $path) {
            $this->assertStringEndsWith('.php', $path);
        }
    }

    public function test_closed_scope_has_empty_recommended_closure(): void
    {
        $r = $this->svc()->probe([
            'objective' => 'Implement AtlasFoo so it validates input.',
            'allowed_files' => ['app/Services/Ai/Foo/AtlasFoo.php', 'tests/Unit/Ai/Foo/AtlasFooTest.php'],
        ]);

        $this->assertSame([], $r['recommended_closure']);
    }
}
