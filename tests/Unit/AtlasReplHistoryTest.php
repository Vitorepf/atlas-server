<?php

namespace Tests\Unit;

use App\Services\Ai\Cli\AtlasReplHistory;
use Tests\TestCase;

class AtlasReplHistoryTest extends TestCase
{
    public function test_path_for_workspace_is_deterministic_and_isolated(): void
    {
        $history = new AtlasReplHistory;
        $a = $history->pathFor('/Users/vitorepf/Develop/atlas');
        $b = $history->pathFor('/Users/vitorepf/Develop/atlas');
        $c = $history->pathFor('/Users/vitorepf/Develop/other');

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
        $this->assertStringEndsWith('.history', $a);
        $this->assertStringContainsString('.atlas/history/', $a);
    }

    public function test_load_creates_directory_when_missing(): void
    {
        $tmpHome = sys_get_temp_dir().'/atlas-history-test-'.bin2hex(random_bytes(4));
        $original = getenv('HOME');
        putenv('HOME='.$tmpHome);
        $_SERVER['HOME'] = $tmpHome;

        try {
            $history = new AtlasReplHistory;
            $history->load('/some/workspace');
            $this->assertDirectoryExists($tmpHome.'/.atlas/history');
        } finally {
            putenv($original !== false ? 'HOME='.$original : 'HOME');
            if ($original !== false) {
                $_SERVER['HOME'] = $original;
            } else {
                unset($_SERVER['HOME']);
            }
            $this->cleanup($tmpHome);
        }
    }

    private function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($dir);
    }
}
