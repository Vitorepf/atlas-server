<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionNativePatchMaterializer;
use Tests\TestCase;

final class AtlasSelfConstructionNativePatchMaterializerTest extends TestCase
{
    private function materialize(array $plan): array
    {
        return (new AtlasSelfConstructionNativePatchMaterializer)->materialize($plan);
    }

    public function test_new_file_creation_emits_a_unified_diff_with_only_additions(): void
    {
        $r = $this->materialize([
            'allowed_files' => ['src/new.php'],
            'patches' => [['path' => 'src/new.php', 'mode' => 'create', 'next' => "<?php\necho 'hello';\n"]],
        ]);

        $this->assertTrue($r['accepted']);
        $this->assertSame([], $r['blockers']);
        $this->assertCount(1, $r['files']);
        $this->assertSame('src/new.php', $r['files'][0]['path']);
        // Create diff shows 0 previous lines
        $this->assertStringContainsString('-1,0', $r['diffs'][0]['unified_diff']);
        $this->assertStringContainsString('+1,2', $r['diffs'][0]['unified_diff']);
    }

    public function test_modify_file_virtual_input_emits_diff_with_removals_and_additions(): void
    {
        $r = $this->materialize([
            'allowed_files' => ['src/old.php'],
            'patches' => [['path' => 'src/old.php', 'mode' => 'modify', 'previous' => "old content\n", 'next' => "new content\n"]],
        ]);

        $this->assertTrue($r['accepted']);
        $this->assertCount(1, $r['files']);
        $this->assertStringContainsString('-old content', $r['diffs'][0]['unified_diff']);
        $this->assertStringContainsString('+new content', $r['diffs'][0]['unified_diff']);
    }

    public function test_forbidden_output_path_is_rejected(): void
    {
        $r = $this->materialize([
            'allowed_files' => ['src/allowed.php'],
            'patches' => [['path' => 'src/forbidden.php', 'mode' => 'create', 'next' => 'x']],
        ]);

        $this->assertFalse($r['accepted']);
        $this->assertContains('forbidden_output_path:src/forbidden.php', $r['blockers']);
    }

    public function test_empty_allowed_files_is_rejected(): void
    {
        $r = $this->materialize(['patches' => [['path' => 'x.php', 'mode' => 'create', 'next' => 'x']]]);

        $this->assertFalse($r['accepted']);
        $this->assertContains('allowed_files_empty', $r['blockers']);
    }

    public function test_unknown_mode_is_rejected(): void
    {
        $r = $this->materialize([
            'allowed_files' => ['x.php'],
            'patches' => [['path' => 'x.php', 'mode' => 'delete', 'next' => '']],
        ]);

        $this->assertFalse($r['accepted']);
        $this->assertContains('unknown_patch_mode:delete', $r['blockers']);
    }

    public function test_traversal_path_is_rejected(): void
    {
        $r = $this->materialize([
            'allowed_files' => ['safe.php'],
            'patches' => [['path' => '../etc/passwd', 'mode' => 'create', 'next' => 'evil']],
        ]);

        $this->assertFalse($r['accepted']);
        $this->assertContains('traversal_path:../etc/passwd', $r['blockers']);
    }

    public function test_duplicate_output_path_is_rejected(): void
    {
        $r = $this->materialize([
            'allowed_files' => ['dup.php'],
            'patches' => [
                ['path' => 'dup.php', 'mode' => 'create', 'next' => 'a'],
                ['path' => 'dup.php', 'mode' => 'create', 'next' => 'b'],
            ],
        ]);

        $this->assertFalse($r['accepted']);
        $this->assertContains('duplicate_output_path:dup.php', $r['blockers']);
    }

    public function test_diff_output_is_byte_stable_across_two_invocations(): void
    {
        $plan = [
            'allowed_files' => ['stable.php'],
            'patches' => [['path' => 'stable.php', 'mode' => 'modify', 'previous' => "old\n", 'next' => "new\n"]],
        ];

        $a = $this->materialize($plan);
        $b = $this->materialize($plan);

        $this->assertSame($a['diffs'][0]['unified_diff'], $b['diffs'][0]['unified_diff']);
        $this->assertSame($a['files'][0]['contents'], $b['files'][0]['contents']);
    }

    public function test_materializer_does_not_touch_filesystem(): void
    {
        // This is a pure virtual materializer — it MUST NOT create any real files.
        $r = $this->materialize([
            'allowed_files' => ['virtual.php'],
            'patches' => [['path' => 'virtual.php', 'mode' => 'create', 'next' => "virtual\n"]],
        ]);

        $this->assertTrue($r['accepted']);
        $this->assertFileDoesNotExist(base_path('virtual.php'));
        $this->assertSame("virtual\n", $r['files'][0]['contents']);
    }
}
