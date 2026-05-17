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

    public function test_applies_provider_diff_with_slightly_wrong_hunk_line_number(): void
    {
        $workspace = $this->makeWorkspace('src');
        $file = $workspace.'/src/SmokeSubject.php';
        file_put_contents($file, <<<'PHP'
<?php
namespace Smoke;

final class SmokeSubject
{
    public function greeting(): string
    {
        return 'helo atlas';
    }
}
PHP);

        $diff = "--- src/SmokeSubject.php\n"
            ."+++ src/SmokeSubject.php\n"
            ."@@ -5,7 +5,7 @@\n"
            ." final class SmokeSubject\n"
            ." {\n"
            ."     public function greeting(): string\n"
            ."     {\n"
            ."-        return 'helo atlas';\n"
            ."+        return 'hello atlas';\n"
            ."     }\n"
            .' }';

        $result = (new PatchApplier)->apply(
            DiffParseResult::patch($diff, ['src/SmokeSubject.php']),
            $workspace,
        );

        $this->assertSame(PatchApplyResult::STATUS_APPLIED, $result->status, $result->stderr);
        $this->assertStringContainsString("return 'hello atlas';", (string) file_get_contents($file));
    }

    public function test_applies_provider_diff_with_wrong_hunk_line_count(): void
    {
        $workspace = $this->makeWorkspace('src');
        $file = $workspace.'/src/SmokeSubject.php';
        file_put_contents($file, <<<'PHP'
<?php
namespace Smoke;

final class SmokeSubject
{
    public function greeting(): string
    {
        return 'helo atlas';
    }
}
PHP);

        $diff = "--- src/SmokeSubject.php\n"
            ."+++ src/SmokeSubject.php\n"
            ."@@ -6,7 +6,7 @@\n"
            ."     public function greeting(): string\n"
            ."     {\n"
            ."-        return 'helo atlas';\n"
            ."+        return 'hello atlas';\n"
            ."     }\n"
            .' }';

        $result = (new PatchApplier)->apply(
            DiffParseResult::patch($diff, ['src/SmokeSubject.php']),
            $workspace,
        );

        $this->assertSame(PatchApplyResult::STATUS_APPLIED, $result->status, $result->stderr);
        $this->assertStringContainsString("return 'hello atlas';", (string) file_get_contents($file));
    }

    private function makeWorkspace(string $subdir = 'app/Services/Foo'): string
    {
        $workspace = sys_get_temp_dir().'/atlas-dev-patch-applier-'.bin2hex(random_bytes(6));
        mkdir($workspace.'/'.$subdir, 0777, true);

        return $workspace;
    }
}
