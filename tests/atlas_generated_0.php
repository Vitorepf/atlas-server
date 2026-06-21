<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Provider {
    final class DiffParseResult
    {
        public const MODE_PATCH = 'patch';

        /** @param list<string> $changedFiles */
        public function __construct(
            public string $mode,
            public array $changedFiles = [],
            public ?string $reason = null,
            public ?string $question = null,
        ) {}
    }

    final class DiffParser
    {
        /** @var list<string> */
        public static array $changedFiles = [];

        public function parse(string $stdout): DiffParseResult
        {
            if (! str_contains($stdout, 'diff --git')) {
                return new DiffParseResult('no_diff', [], 'no patch found');
            }

            return new DiffParseResult(DiffParseResult::MODE_PATCH, self::$changedFiles);
        }
    }
}

namespace App\Services\Ai\Programming\AtlasDev\Gate {
    use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;

    final class PatchApplyResult
    {
        public const STATUS_APPLIED = 'applied';

        public function __construct(
            public string $status,
            public ?string $reason = null,
        ) {}
    }

    final class PatchApplier
    {
        /** @var array<string, string> */
        public static array $writes = [];
        public static int $applyCalls = 0;

        public function apply(DiffParseResult $parsed, string $workspace, int $timeoutSeconds): PatchApplyResult
        {
            self::$applyCalls++;

            foreach (self::$writes as $rel => $contents) {
                $target = rtrim($workspace, '/').'/'.$rel;
                if (! is_dir(dirname($target))) {
                    mkdir(dirname($target), 0o755, true);
                }
                file_put_contents($target, $contents);
            }

            return new PatchApplyResult(PatchApplyResult::STATUS_APPLIED);
        }
    }
}

namespace {
    require __DIR__.'/../app/Services/Ai/AutonomousEvolution/AtlasLoopProviderEditApplier.php';

    use App\Services\Ai\AutonomousEvolution\AtlasLoopProviderEditApplier;
    use App\Services\Ai\Programming\AtlasDev\Gate\PatchApplier;
    use App\Services\Ai\Programming\AtlasDev\Provider\DiffParser;

    function atlas_generated_assert_same(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException($message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true));
        }
    }

    function atlas_generated_assert_true(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }

    function atlas_generated_assert_false(bool $condition, string $message): void
    {
        if ($condition) {
            throw new RuntimeException($message);
        }
    }

    function atlas_generated_workspace(): string
    {
        $root = sys_get_temp_dir().'/atlas-provider-edit-applier-generated-'.bin2hex(random_bytes(6));
        if (! mkdir($root, 0o755, true) && ! is_dir($root)) {
            throw new RuntimeException('failed to create fixture workspace');
        }

        return $root;
    }

    function atlas_generated_rm_rf(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($it as $item) {
            if ($item instanceof SplFileInfo && $item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }

    function atlas_generated_full_file_block(string $rel, string $body): string
    {
        return "*** ATLAS_FILE: {$rel} ***\n{$body}\n*** ATLAS_END ***";
    }

    function atlas_generated_fake_diff(): string
    {
        return "diff --git a/placeholder.txt b/placeholder.txt\n--- a/placeholder.txt\n+++ b/placeholder.txt\n@@ -1 +1 @@\n-old\n+new\n";
    }

    function atlas_generated_reset_diff_stub(array $changedFiles, array $writes): void
    {
        DiffParser::$changedFiles = $changedFiles;
        PatchApplier::$writes = $writes;
        PatchApplier::$applyCalls = 0;
    }

    $failures = [];
    $run = static function (string $name, callable $test) use (&$failures): void {
        try {
            $test();
        } catch (Throwable $e) {
            $failures[] = $name.': '.$e->getMessage();
        }
    };

    $workspace = atlas_generated_workspace();
    try {
        $run('preserves existing full-file allowed-files behavior', static function () use ($workspace): void {
            file_put_contents($workspace.'/outside.txt', 'original outside');
            $reply = atlas_generated_full_file_block('inside.txt', 'inside body')
                ."\n".atlas_generated_full_file_block('outside.txt', 'forbidden body');

            $result = (new AtlasLoopProviderEditApplier)->applyFromText($reply, $workspace, 60, ['inside.txt']);

            atlas_generated_assert_true($result['applied'], 'allowed full-file block must still apply');
            atlas_generated_assert_same(['inside.txt'], $result['changed_files'], 'only the in-scope full-file block should be reported');
            atlas_generated_assert_same('inside body', file_get_contents($workspace.'/inside.txt'), 'allowed full-file body should be written');
            atlas_generated_assert_same('original outside', file_get_contents($workspace.'/outside.txt'), 'out-of-scope full-file block should stay rejected');
        });

        $run('preserves existing in-scope unified diff behavior', static function () use ($workspace): void {
            atlas_generated_reset_diff_stub(['inside.txt'], ['inside.txt' => 'allowed diff body']);

            $result = (new AtlasLoopProviderEditApplier)->applyFromText(atlas_generated_fake_diff(), $workspace, 60, ['inside.txt']);

            atlas_generated_assert_true($result['applied'], 'in-scope unified diffs must still apply');
            atlas_generated_assert_same(['inside.txt'], $result['changed_files'], 'in-scope diff changed_files should be preserved');
            atlas_generated_assert_same(1, PatchApplier::$applyCalls, 'in-scope diff should still invoke PatchApplier');
            atlas_generated_assert_same('allowed diff body', file_get_contents($workspace.'/inside.txt'), 'in-scope diff write should land');
        });

        $run('rejects out-of-scope unified diff before applying it', static function () use ($workspace): void {
            file_put_contents($workspace.'/outside.txt', 'original outside');
            atlas_generated_reset_diff_stub(['outside.txt'], ['outside.txt' => 'forbidden diff body']);

            $result = (new AtlasLoopProviderEditApplier)->applyFromText(atlas_generated_fake_diff(), $workspace, 60, ['inside.txt']);

            atlas_generated_assert_false($result['applied'], 'out-of-scope unified diff must not report applied');
            atlas_generated_assert_same([], $result['changed_files'], 'out-of-scope unified diff must not report changed files');
            atlas_generated_assert_same(0, PatchApplier::$applyCalls, 'out-of-scope unified diff must be rejected before PatchApplier can mutate the workspace');
            atlas_generated_assert_same('original outside', file_get_contents($workspace.'/outside.txt'), 'out-of-scope unified diff must not mutate the forbidden file');
        });
    } finally {
        atlas_generated_rm_rf($workspace);
    }

    if ($failures !== []) {
        fwrite(STDERR, implode("\n---\n", $failures)."\n");
        exit(1);
    }

    echo "ok\n";
    exit(0);
}
