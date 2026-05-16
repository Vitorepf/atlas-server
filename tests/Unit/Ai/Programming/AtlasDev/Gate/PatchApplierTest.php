<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Gate;

use App\Services\Ai\Programming\AtlasDev\Gate\PatchApplier;
use App\Services\Ai\Programming\AtlasDev\Gate\PatchApplyResult;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use Tests\TestCase;

final class PatchApplierTest extends TestCase
{
    public function test_applies_provider_diff_without_a_b_prefixes(): void
    {
        $workspace = $this->makeWorkspace();
        $file = $workspace.'/app/Services/Foo/FooService.php';
        file_put_contents($file, "<?php\n\nreturn 41;\n");

        $diff = "--- app/Services/Foo/FooService.php\n"
            ."+++ app/Services/Foo/FooService.php\n"
            ."@@ -1,3 +1,3 @@\n"
            ." <?php\n"
            ." \n"
            ."-return 41;\n"
            ."+return 42;\n";

        $result = (new PatchApplier)->apply(
            DiffParseResult::patch($diff, ['app/Services/Foo/FooService.php']),
            $workspace,
        );

        $this->assertSame(PatchApplyResult::STATUS_APPLIED, $result->status, $result->stderr);
        $this->assertStringContainsString('return 42;', (string) file_get_contents($file));
    }

    public function test_applies_standard_git_diff_with_a_b_prefixes(): void
    {
        $workspace = $this->makeWorkspace();
        $file = $workspace.'/app/Services/Foo/FooService.php';
        file_put_contents($file, "<?php\n\nreturn 41;\n");

        $diff = "--- a/app/Services/Foo/FooService.php\n"
            ."+++ b/app/Services/Foo/FooService.php\n"
            ."@@ -1,3 +1,3 @@\n"
            ." <?php\n"
            ." \n"
            ."-return 41;\n"
            ."+return 42;\n";

        $result = (new PatchApplier)->apply(
            DiffParseResult::patch($diff, ['app/Services/Foo/FooService.php']),
            $workspace,
        );

        $this->assertSame(PatchApplyResult::STATUS_APPLIED, $result->status, $result->stderr);
        $this->assertStringContainsString('return 42;', (string) file_get_contents($file));
    }

    private function makeWorkspace(): string
    {
        $workspace = sys_get_temp_dir().'/atlas-dev-patch-applier-'.bin2hex(random_bytes(6));
        mkdir($workspace.'/app/Services/Foo', 0777, true);

        return $workspace;
    }
}
