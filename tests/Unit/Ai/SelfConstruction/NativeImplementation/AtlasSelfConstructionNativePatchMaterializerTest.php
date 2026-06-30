<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeImplementation;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionNativePatchMaterializer;
use Tests\TestCase;

final class AtlasSelfConstructionNativePatchMaterializerTest extends TestCase
{
    public function test_new_file_creation_emits_a_unified_diff_with_only_additions(): void
    {
        $verdict = (new AtlasSelfConstructionNativePatchMaterializer)->materialize([
            'allowed_files' => ['app/Foo.php'],
            'patches' => [['path' => 'app/Foo.php', 'mode' => 'create', 'next' => "line1\nline2"]],
        ]);

        $this->assertTrue($verdict['accepted']);
        $diff = $verdict['diffs'][0]['unified_diff'];
        $this->assertStringContainsString('--- a/app/Foo.php', $diff);
        $this->assertStringContainsString('+++ b/app/Foo.php', $diff);
        $this->assertStringContainsString('+line1', $diff);
        $this->assertStringContainsString('+line2', $diff);
        $this->assertStringNotContainsString('-line', $diff);
    }

    public function test_modify_file_virtual_input_emits_diff_with_removals_and_additions(): void
    {
        $verdict = (new AtlasSelfConstructionNativePatchMaterializer)->materialize([
            'allowed_files' => ['app/Bar.php'],
            'patches' => [[
                'path' => 'app/Bar.php',
                'mode' => 'modify',
                'previous' => "old_line_1\nold_line_2",
                'next' => "new_line_1\nnew_line_2",
            ]],
        ]);

        $diff = $verdict['diffs'][0]['unified_diff'];
        $this->assertStringContainsString('-old_line_1', $diff);
        $this->assertStringContainsString('-old_line_2', $diff);
        $this->assertStringContainsString('+new_line_1', $diff);
        $this->assertStringContainsString('+new_line_2', $diff);
    }

    public function test_forbidden_output_path_is_rejected(): void
    {
        $verdict = (new AtlasSelfConstructionNativePatchMaterializer)->materialize([
            'allowed_files' => ['app/Foo.php'],
            'patches' => [['path' => 'config/atlas.php', 'mode' => 'create', 'next' => 'x']],
        ]);

        $this->assertFalse($verdict['accepted']);
        $this->assertContains('forbidden_output_path:config/atlas.php', $verdict['blockers']);
    }

    public function test_empty_allowed_files_is_rejected(): void
    {
        $verdict = (new AtlasSelfConstructionNativePatchMaterializer)->materialize([
            'allowed_files' => [],
            'patches' => [['path' => 'app/Foo.php', 'mode' => 'create', 'next' => 'x']],
        ]);

        $this->assertFalse($verdict['accepted']);
        $this->assertContains('allowed_files_empty', $verdict['blockers']);
    }

    public function test_unknown_mode_is_rejected(): void
    {
        $verdict = (new AtlasSelfConstructionNativePatchMaterializer)->materialize([
            'allowed_files' => ['app/Foo.php'],
            'patches' => [['path' => 'app/Foo.php', 'mode' => 'mystical', 'next' => 'x']],
        ]);

        $this->assertContains('unknown_patch_mode:mystical', $verdict['blockers']);
    }

    public function test_traversal_path_is_rejected(): void
    {
        $svc = new AtlasSelfConstructionNativePatchMaterializer;

        $v1 = $svc->materialize([
            'allowed_files' => ['../etc/passwd'],
            'patches' => [['path' => '../etc/passwd', 'mode' => 'create', 'next' => 'x']],
        ]);
        $this->assertFalse($v1['accepted']);
        $this->assertContains('traversal_path:../etc/passwd', $v1['blockers']);

        $v2 = $svc->materialize([
            'allowed_files' => ['/etc/passwd'],
            'patches' => [['path' => '/etc/passwd', 'mode' => 'create', 'next' => 'x']],
        ]);
        $this->assertFalse($v2['accepted']);
        $this->assertContains('traversal_path:/etc/passwd', $v2['blockers']);
    }

    public function test_duplicate_output_path_is_rejected(): void
    {
        $verdict = (new AtlasSelfConstructionNativePatchMaterializer)->materialize([
            'allowed_files' => ['app/Foo.php'],
            'patches' => [
                ['path' => 'app/Foo.php', 'mode' => 'create', 'next' => 'first'],
                ['path' => 'app/Foo.php', 'mode' => 'modify', 'previous' => 'first', 'next' => 'second'],
            ],
        ]);

        $this->assertFalse($verdict['accepted']);
        $this->assertContains('duplicate_output_path:app/Foo.php', $verdict['blockers']);
        $this->assertCount(1, $verdict['files']);
    }

    public function test_diff_output_is_byte_stable_across_two_invocations(): void
    {
        $svc = new AtlasSelfConstructionNativePatchMaterializer;
        $plan = [
            'allowed_files' => ['app/Foo.php'],
            'patches' => [['path' => 'app/Foo.php', 'mode' => 'create', 'next' => "line1\nline2\nline3"]],
        ];

        $this->assertSame(
            $svc->materialize($plan)['diffs'][0]['unified_diff'],
            $svc->materialize($plan)['diffs'][0]['unified_diff'],
        );
    }

    public function test_materializer_does_not_touch_filesystem(): void
    {
        // The materializer is virtual; we verify no file with the same path was created
        // by checking is_file() before and after a virtual write to a unique sandbox path.
        $sandboxPath = 'tests/_sandbox_'.bin2hex(random_bytes(4)).'.php';
        $existedBefore = is_file(base_path($sandboxPath));

        (new AtlasSelfConstructionNativePatchMaterializer)->materialize([
            'allowed_files' => [$sandboxPath],
            'patches' => [['path' => $sandboxPath, 'mode' => 'create', 'next' => 'x']],
        ]);

        $existsAfter = is_file(base_path($sandboxPath));
        $this->assertSame($existedBefore, $existsAfter, 'materializer MUST NOT create any file on disk');
    }
}
