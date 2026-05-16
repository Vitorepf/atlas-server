<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Gate;

use App\Services\Ai\Programming\AtlasDev\Gate\SymfonyProcessCommandRunner;
use PHPUnit\Framework\TestCase;

final class SymfonyProcessCommandRunnerTest extends TestCase
{
    public function test_runner_passes_shell_path_to_verification_process(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-dev-command-runner-'.bin2hex(random_bytes(4));
        $bin = $workspace.'/bin';
        mkdir($bin, 0o755, true);
        file_put_contents($bin.'/atlas-dev-fake-tool', "#!/bin/sh\nprintf tool-ok\n");
        chmod($bin.'/atlas-dev-fake-tool', 0o755);

        $previousPath = getenv('PATH');
        putenv('PATH='.$bin.PATH_SEPARATOR.($previousPath !== false ? $previousPath : ''));

        try {
            $result = (new SymfonyProcessCommandRunner)->run('atlas-dev-fake-tool', $workspace, 5);
        } finally {
            if ($previousPath === false) {
                putenv('PATH');
            } else {
                putenv('PATH='.$previousPath);
            }
        }

        $this->assertTrue($result->ok(), $result->combinedOutput());
        $this->assertSame('tool-ok', $result->stdout);
    }
}
