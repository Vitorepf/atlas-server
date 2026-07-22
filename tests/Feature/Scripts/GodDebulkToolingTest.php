<?php

declare(strict_types=1);

namespace Tests\Feature\Scripts;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class GodDebulkToolingTest extends TestCase
{
    public function test_audit_reports_the_stable_baseline_markers(): void
    {
        $process = $this->runCommand(['/opt/homebrew/bin/php', 'scripts/god-debulk-audit.php']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('GOD_DEBULK_AUDIT_OK', $process->getOutput());
        $this->assertStringContainsString('godfiles_gt_5k=', $process->getOutput());
        $this->assertStringContainsString('godfiles_gt_2k=', $process->getOutput());
    }

    public function test_guard_reports_its_stable_success_marker_without_mutating_history(): void
    {
        $process = $this->runCommand(['bash', 'scripts/god-debulk-guard.sh']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('GOD_DEBULK_GUARD_OK', $process->getOutput());
    }

    public function test_guard_rejects_an_uppercase_residual_pass_subject_without_mutating_history(): void
    {
        $fixture = $this->gitSubjectFixture('RESIDUAL PASS 3');

        try {
            $process = $this->runCommand(['bash', 'scripts/god-debulk-guard.sh'], $fixture['environment']);

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('GOD_DEBULK_GUARD_FAIL residual_pass_commit', $process->getErrorOutput());
        } finally {
            $fixture['cleanup']();
        }
    }

    public function test_guard_accepts_a_word_that_only_contains_residual(): void
    {
        $fixture = $this->gitSubjectFixture('nonresidual pass 3');

        try {
            $process = $this->runCommand(['bash', 'scripts/god-debulk-guard.sh'], $fixture['environment']);

            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString('GOD_DEBULK_GUARD_OK', $process->getOutput());
        } finally {
            $fixture['cleanup']();
        }
    }

    public function test_codemap_verifier_reports_its_stable_success_marker(): void
    {
        $process = $this->runCommand(['/opt/homebrew/bin/php', 'scripts/god-debulk-codemap-verify.php']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('GOD_DEBULK_CODEMAP_OK', $process->getOutput());
    }

    public function test_codemap_verifier_rejects_a_navigation_target_mentioned_only_in_prose(): void
    {
        $fixture = $this->codemapRepository(<<<'MARKDOWN'
# Fixture

<!-- GOD-DEBULK-CODEMAP: INCOMPLETE -->

This prose mentions `App\Services\Ai\Router\AtlasAiIntentKernelService::classify`,
but it is not a navigation table row.
MARKDOWN);

        try {
            $process = $this->runCodemapVerifier($fixture['root']);

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('GOD_DEBULK_CODEMAP_FAIL missing_navigation_row', $process->getErrorOutput());
        } finally {
            $fixture['cleanup']();
        }
    }

    public function test_codemap_verifier_rejects_a_missing_canonical_map_even_when_an_environment_path_is_valid(): void
    {
        $target = 'App\\Services\\Ai\\Fixture\\CanonicalMap::check';
        $fixture = $this->codemapRepository(null, [
            'App\\Services\\Ai\\Fixture\\CanonicalMap' => <<<'PHP'
<?php
namespace App\Services\Ai\Fixture;
class CanonicalMap { public function check(): void {} }
PHP,
        ]);
        $alternate = $fixture['root'].'/alternate-codemap.md';
        file_put_contents($alternate, $this->navigationMap($target));

        try {
            $process = $this->runCodemapVerifier($fixture['root'], [
                'GOD_DEBULK_CODEMAP_PATH' => $alternate,
            ]);

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('GOD_DEBULK_CODEMAP_FAIL missing_codemap', $process->getErrorOutput());
        } finally {
            $fixture['cleanup']();
        }
    }

    public function test_codemap_verifier_rejects_a_navigation_table_inside_a_fenced_code_block(): void
    {
        $target = 'App\\Services\\Ai\\Fixture\\FencedMap::check';
        $fixture = $this->codemapRepository(<<<MARKDOWN
# Fixture

<!-- GOD-DEBULK-CODEMAP: INCOMPLETE -->

```markdown
{$this->navigationTable($target)}
```
MARKDOWN, [
            'App\\Services\\Ai\\Fixture\\FencedMap' => <<<'PHP'
<?php
namespace App\Services\Ai\Fixture;
class FencedMap { public function check(): void {} }
PHP,
        ]);

        try {
            $process = $this->runCodemapVerifier($fixture['root']);

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('GOD_DEBULK_CODEMAP_FAIL missing_navigation_row', $process->getErrorOutput());
        } finally {
            $fixture['cleanup']();
        }
    }

    public function test_codemap_verifier_rejects_a_navigation_table_inside_a_fence_started_by_a_list_item(): void
    {
        $target = 'App\\Services\\Ai\\Fixture\\ListFenceMap::check';
        $fixture = $this->codemapRepository("<!-- GOD-DEBULK-CODEMAP: INCOMPLETE -->\n\n- ```markdown\n  ".$this->navigationTable($target)."\n  ```\n", [
            'App\\Services\\Ai\\Fixture\\ListFenceMap' => $this->fixtureSource('App\\Services\\Ai\\Fixture\\ListFenceMap'),
        ]);

        try {
            $process = $this->runCodemapVerifier($fixture['root']);

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('GOD_DEBULK_CODEMAP_FAIL missing_navigation_row', $process->getErrorOutput());
        } finally {
            $fixture['cleanup']();
        }
    }

    public function test_codemap_verifier_rejects_a_navigation_table_in_a_mixed_indentation_list_fence(): void
    {
        $target = 'App\\Services\\Ai\\Fixture\\MixedIndentMap::check';
        $table = str_replace("\n|", "\n  |", $this->navigationTable($target));
        $table = str_replace("\n  | ---", "\n   | ---", $table);
        $fixture = $this->codemapRepository("<!-- GOD-DEBULK-CODEMAP: INCOMPLETE -->\n\n- ```markdown\n  {$table}\n  ```\n", [
            'App\\Services\\Ai\\Fixture\\MixedIndentMap' => $this->fixtureSource('App\\Services\\Ai\\Fixture\\MixedIndentMap'),
        ]);

        try {
            $process = $this->runCodemapVerifier($fixture['root']);

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('GOD_DEBULK_CODEMAP_FAIL missing_navigation_row', $process->getErrorOutput());
        } finally {
            $fixture['cleanup']();
        }
    }

    public function test_codemap_verifier_rejects_html_literals_inside_a_fenced_code_block(): void
    {
        $target = 'App\\Services\\Ai\\Fixture\\HtmlFenceMap::check';
        $fixture = $this->codemapRepository("<!-- GOD-DEBULK-CODEMAP: INCOMPLETE -->\n\n```html\n<section>\n{$this->navigationTable($target)}\n</section>\n```\n", [
            'App\\Services\\Ai\\Fixture\\HtmlFenceMap' => $this->fixtureSource('App\\Services\\Ai\\Fixture\\HtmlFenceMap'),
        ]);

        try {
            $process = $this->runCodemapVerifier($fixture['root']);

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('GOD_DEBULK_CODEMAP_FAIL missing_navigation_row', $process->getErrorOutput());
        } finally {
            $fixture['cleanup']();
        }
    }

    public function test_codemap_verifier_accepts_a_visible_table_after_an_invalid_backtick_fence_info_string(): void
    {
        $target = 'App\\Services\\Ai\\Fixture\\InvalidFenceInfoMap::check';
        $fixture = $this->codemapRepository("<!-- GOD-DEBULK-CODEMAP: INCOMPLETE -->\n\n```invalid`info\n{$this->navigationTable($target)}\n", [
            'App\\Services\\Ai\\Fixture\\InvalidFenceInfoMap' => $this->fixtureSource('App\\Services\\Ai\\Fixture\\InvalidFenceInfoMap'),
        ]);

        try {
            $process = $this->runCodemapVerifier($fixture['root']);

            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString('GOD_DEBULK_CODEMAP_OK targets=1', $process->getOutput());
        } finally {
            $fixture['cleanup']();
        }
    }

    public function test_codemap_verifier_rejects_a_navigation_table_inside_an_html_comment(): void
    {
        $target = 'App\\Services\\Ai\\Fixture\\CommentedMap::check';
        $fixture = $this->codemapRepository(<<<MARKDOWN
# Fixture

<!-- GOD-DEBULK-CODEMAP: INCOMPLETE -->

<!--
{$this->navigationTable($target)}
-->
MARKDOWN, [
            'App\\Services\\Ai\\Fixture\\CommentedMap' => <<<'PHP'
<?php
namespace App\Services\Ai\Fixture;
class CommentedMap { public function check(): void {} }
PHP,
        ]);

        try {
            $process = $this->runCodemapVerifier($fixture['root']);

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('GOD_DEBULK_CODEMAP_FAIL missing_navigation_row', $process->getErrorOutput());
        } finally {
            $fixture['cleanup']();
        }
    }

    public function test_codemap_verifier_rejects_comment_and_string_method_lookalikes_in_another_class(): void
    {
        $target = 'App\\Services\\Ai\\Fixture\\Target::verified';
        $fixture = $this->codemapRepository($this->navigationMap($target), [
            'App\\Services\\Ai\\Fixture\\Target' => <<<'PHP'
<?php
namespace App\Services\Ai\Fixture;

class Decoy
{
    // function verified() {}
    private string $text = 'function verified() {}';
    public function verified(): void {}
}

class Target
{
    public function real(): void {}
}
PHP,
        ]);

        try {
            $process = $this->runCodemapVerifier($fixture['root']);

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString("GOD_DEBULK_CODEMAP_FAIL missing_method={$target}", $process->getErrorOutput());
        } finally {
            $fixture['cleanup']();
        }
    }

    public function test_codemap_verifier_accepts_a_commented_by_reference_method_after_interpolation(): void
    {
        $target = 'App\\Services\\Ai\\Fixture\\InterpolatedMap::verified';
        $fixture = $this->codemapRepository($this->navigationMap($target), [
            'App\\Services\\Ai\\Fixture\\InterpolatedMap' => <<<'PHP'
<?php
namespace App\Services\Ai\Fixture;

class InterpolatedMap
{
    public function seed(): string
    {
        return "value {$this->name()}";
    }

    public function /* declaration comment */ &verified(): array
    {
        $result = [];

        return $result;
    }

    private function name(): string
    {
        return 'ok';
    }
}
PHP,
        ]);

        try {
            $process = $this->runCodemapVerifier($fixture['root']);

            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString('GOD_DEBULK_CODEMAP_OK targets=1', $process->getOutput());
        } finally {
            $fixture['cleanup']();
        }
    }

    public function test_codemap_verifier_rejects_a_navigation_table_in_a_four_space_indented_code_block(): void
    {
        $target = 'App\\Services\\Ai\\Fixture\\IndentedSpacesMap::check';
        $fixture = $this->codemapRepository($this->indentedNavigationMap($target, '    '), [
            'App\\Services\\Ai\\Fixture\\IndentedSpacesMap' => $this->fixtureSource('App\\Services\\Ai\\Fixture\\IndentedSpacesMap'),
        ]);

        try {
            $process = $this->runCodemapVerifier($fixture['root']);

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('GOD_DEBULK_CODEMAP_FAIL missing_navigation_row', $process->getErrorOutput());
        } finally {
            $fixture['cleanup']();
        }
    }

    public function test_codemap_verifier_rejects_a_navigation_table_in_a_tab_indented_code_block(): void
    {
        $target = 'App\\Services\\Ai\\Fixture\\IndentedTabMap::check';
        $fixture = $this->codemapRepository($this->indentedNavigationMap($target, "\t"), [
            'App\\Services\\Ai\\Fixture\\IndentedTabMap' => $this->fixtureSource('App\\Services\\Ai\\Fixture\\IndentedTabMap'),
        ]);

        try {
            $process = $this->runCodemapVerifier($fixture['root']);

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('GOD_DEBULK_CODEMAP_FAIL missing_navigation_row', $process->getErrorOutput());
        } finally {
            $fixture['cleanup']();
        }
    }

    public function test_codemap_verifier_keeps_a_table_hidden_after_a_different_fence_character(): void
    {
        $target = 'App\\Services\\Ai\\Fixture\\DifferentFenceMap::check';
        $fixture = $this->codemapRepository($this->fencedNavigationMap($target, '````', '~~~~'), [
            'App\\Services\\Ai\\Fixture\\DifferentFenceMap' => $this->fixtureSource('App\\Services\\Ai\\Fixture\\DifferentFenceMap'),
        ]);

        try {
            $process = $this->runCodemapVerifier($fixture['root']);

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('GOD_DEBULK_CODEMAP_FAIL missing_navigation_row', $process->getErrorOutput());
        } finally {
            $fixture['cleanup']();
        }
    }

    public function test_codemap_verifier_keeps_a_table_hidden_after_a_shorter_fence(): void
    {
        $target = 'App\\Services\\Ai\\Fixture\\ShortFenceMap::check';
        $fixture = $this->codemapRepository($this->fencedNavigationMap($target, '````', '```'), [
            'App\\Services\\Ai\\Fixture\\ShortFenceMap' => $this->fixtureSource('App\\Services\\Ai\\Fixture\\ShortFenceMap'),
        ]);

        try {
            $process = $this->runCodemapVerifier($fixture['root']);

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('GOD_DEBULK_CODEMAP_FAIL missing_navigation_row', $process->getErrorOutput());
        } finally {
            $fixture['cleanup']();
        }
    }

    public function test_codemap_verifier_keeps_a_table_hidden_after_a_fence_with_trailing_text(): void
    {
        $target = 'App\\Services\\Ai\\Fixture\\TrailingFenceMap::check';
        $fixture = $this->codemapRepository($this->fencedNavigationMap($target, '```', '```not-a-close'), [
            'App\\Services\\Ai\\Fixture\\TrailingFenceMap' => $this->fixtureSource('App\\Services\\Ai\\Fixture\\TrailingFenceMap'),
        ]);

        try {
            $process = $this->runCodemapVerifier($fixture['root']);

            $this->assertSame(1, $process->getExitCode());
            $this->assertStringContainsString('GOD_DEBULK_CODEMAP_FAIL missing_navigation_row', $process->getErrorOutput());
        } finally {
            $fixture['cleanup']();
        }
    }

    public function test_codemap_verifier_ignores_a_fence_inside_a_comment_and_accepts_a_later_visible_table(): void
    {
        $target = 'App\\Services\\Ai\\Fixture\\CommentFenceMap::check';
        $fixture = $this->codemapRepository("<!-- GOD-DEBULK-CODEMAP: INCOMPLETE -->\n\n<!--\n```\n-->\n\n".$this->navigationTable($target)."\n", [
            'App\\Services\\Ai\\Fixture\\CommentFenceMap' => $this->fixtureSource('App\\Services\\Ai\\Fixture\\CommentFenceMap'),
        ]);

        try {
            $process = $this->runCodemapVerifier($fixture['root']);

            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertStringContainsString('GOD_DEBULK_CODEMAP_OK targets=1', $process->getOutput());
        } finally {
            $fixture['cleanup']();
        }
    }

    /**
     * @param  list<string>  $command
     */
    private function runCommand(array $command, array $environment = [], ?string $workingDirectory = null): Process
    {
        $process = new Process($command, $workingDirectory ?? base_path(), array_replace([
            'GOD_DEBULK_ENFORCE' => '0',
        ], $environment));
        $process->setTimeout(30);
        $process->run();

        return $process;
    }

    /**
     * @return array{environment:array<string,string>,cleanup:callable():void}
     */
    private function gitSubjectFixture(string $subject): array
    {
        $directory = sys_get_temp_dir().'/god-debulk-git-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $git = $directory.'/git';
        file_put_contents($git, <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "${GOD_DEBULK_TEST_SUBJECT:?}"
BASH);
        chmod($git, 0700);

        return [
            'environment' => [
                'GOD_DEBULK_TEST_SUBJECT' => $subject,
                'PATH' => $directory.':'.(getenv('PATH') ?: '/usr/bin:/bin'),
            ],
            'cleanup' => static function () use ($git, $directory): void {
                @unlink($git);
                @rmdir($directory);
            },
        ];
    }

    /**
     * @param  array<string,string>  $environment
     */
    private function runCodemapVerifier(string $root, array $environment = []): Process
    {
        return $this->runCommand(['/opt/homebrew/bin/php', $root.'/scripts/god-debulk-codemap-verify.php'], $environment, $root);
    }

    /**
     * @param  array<string,string>  $sources
     * @return array{root:string,cleanup:callable():void}
     */
    private function codemapRepository(?string $codemap, array $sources = []): array
    {
        $root = sys_get_temp_dir().'/god-debulk-codemap-'.bin2hex(random_bytes(8));
        mkdir($root.'/scripts', 0700, true);
        copy(base_path('scripts/god-debulk-codemap-verify.php'), $root.'/scripts/god-debulk-codemap-verify.php');
        mkdir($root.'/vendor', 0700, true);
        file_put_contents($root.'/vendor/autoload.php', "<?php\nrequire ".var_export(base_path('vendor/autoload.php'), true).";\n");

        if ($codemap !== null) {
            $path = $root.'/app/Services/Ai/CODEMAP.md';
            mkdir(dirname($path), 0700, true);
            file_put_contents($path, $codemap);
        }

        foreach ($sources as $class => $source) {
            $path = $root.'/app/'.str_replace('\\', '/', substr($class, strlen('App\\'))).'.php';
            mkdir(dirname($path), 0700, true);
            file_put_contents($path, $source);
        }

        return [
            'root' => $root,
            'cleanup' => function () use ($root): void {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($iterator as $path) {
                    $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
                }
                rmdir($root);
            },
        ];
    }

    private function navigationMap(string $target): string
    {
        return "<!-- GOD-DEBULK-CODEMAP: INCOMPLETE -->\n\n".$this->navigationTable($target)."\n";
    }

    private function navigationTable(string $target): string
    {
        return "| Change concern | Concrete navigation target |\n| --- | --- |\n| Fixture | `{$target}` |";
    }

    private function indentedNavigationMap(string $target, string $indent): string
    {
        return "<!-- GOD-DEBULK-CODEMAP: INCOMPLETE -->\n\n".$indent.str_replace("\n", "\n{$indent}", $this->navigationTable($target))."\n";
    }

    private function fencedNavigationMap(string $target, string $open, string $falseClose): string
    {
        return "<!-- GOD-DEBULK-CODEMAP: INCOMPLETE -->\n\n{$open}markdown\n{$falseClose}\n".$this->navigationTable($target)."\n{$open}\n";
    }

    private function fixtureSource(string $class): string
    {
        $separator = strrpos($class, '\\');
        $this->assertNotFalse($separator);
        $namespace = substr($class, 0, $separator);
        $name = substr($class, $separator + 1);

        return "<?php\nnamespace {$namespace};\nclass {$name} { public function check(): void {} }\n";
    }
}
