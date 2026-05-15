<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\Concerns;

/**
 * Shared helpers for rivals-flavoured feature tests.
 *
 * Extracted from AtlasRivalsHarnessOperatorTest so that both the v1 harness
 * and the v2 operator-battery tests share a single, audited tear-down path.
 *
 * The using TestCase MUST declare `protected array $createdPaths = []` and
 * call $this->purgeAll() from tearDown.
 */
trait RivalsTestHelpers
{
    /** @var list<string> */
    protected array $createdPaths = [];

    protected function makeTmpRoot(string $tag): string
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'forge-rivals-v2-'.$tag.'-'.bin2hex(random_bytes(6));
        @mkdir($root, 0o755, true);
        $this->createdPaths[] = $root;

        return $root;
    }

    protected function newRunId(string $suffix): string
    {
        return 'forge_rivals_v2_test_'.bin2hex(random_bytes(6)).'-'.$suffix;
    }

    protected function purge(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->purge($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    protected function purgeAll(): void
    {
        foreach ($this->createdPaths as $path) {
            $this->purge($path);
        }
        $this->createdPaths = [];
    }
}
