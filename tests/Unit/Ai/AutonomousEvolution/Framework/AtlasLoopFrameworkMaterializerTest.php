<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Framework;

use App\Services\Ai\AutonomousEvolution\Framework\AtlasLoopFrameworkMaterializer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class AtlasLoopFrameworkMaterializerTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/atlas-fw-materializer-'.bin2hex(random_bytes(4));
        mkdir($this->repo.'/app', 0o755, true);
        mkdir($this->repo.'/vendor/composer', 0o755, true);
        mkdir($this->repo.'/vendor/acme', 0o755, true);
        file_put_contents($this->repo.'/.gitignore', "/vendor/\n.env\n.env.testing\n/storage/\n/bootstrap/cache/\n");
        file_put_contents($this->repo.'/composer.json', json_encode([
            'autoload' => ['psr-4' => ['App\\' => 'app/']],
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n");
        file_put_contents($this->repo.'/.env', "APP_KEY=base64:".base64_encode(str_repeat('a', 32))."\nDB_CONNECTION=pgsql\nDB_PORT=5433\n");
        file_put_contents($this->repo.'/vendor/autoload.php', "<?php\n");
        file_put_contents($this->repo.'/vendor/acme/package.php', "<?php\n");
        file_put_contents($this->repo.'/app/Subject.php', "<?php\nnamespace App;\nfinal class Subject { public function value(): string { return 'bad'; } }\n");
        foreach ([
            ['git', 'init', '-q'],
            ['git', 'add', '-A'],
            ['git', '-c', 'user.email=atlas-loop@local', '-c', 'user.name=Atlas Loop', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'base'],
        ] as $argv) {
            (new Process($argv, $this->repo, null, null, 60.0))->run();
        }
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->repo], null, null, null, 60.0))->run();
        parent::tearDown();
    }

    public function test_materializes_framework_worktree_with_local_autoload_and_hermetic_testing_env(): void
    {
        [$task, $cleanup] = (new AtlasLoopFrameworkMaterializer)->materializeBase($this->repo, 'prove framework materialization', [
            'target_relative_path' => 'app/Subject.php',
            'acceptance' => [
                'commands' => ['php -r "require \'vendor/autoload.php\'; exit((new App\\\\Subject)->value() === \'fixed\' ? 0 : 1);"'],
                'allowed_globs' => ['app/Subject.php'],
                'frozen_globs' => ['tests/**'],
                'metric_kind' => 'gate',
            ],
        ]);

        $workspace = (string) $task['base_workspace'];

        try {
            $this->assertSame('framework', $task['materializer']);
            $this->assertSame('worktree', $task['scenario_clone_mode']);
            $this->assertSame(['app/Subject.php'], $task['allowed_files']);
            $this->assertTrue(is_dir($workspace));
            $this->assertTrue(is_file($workspace.'/vendor/autoload.php'));
            $this->assertTrue(is_link($workspace.'/vendor/acme'));

            $env = (string) file_get_contents($workspace.'/.env.testing');
            $this->assertStringContainsString('APP_ENV=testing', $env);
            $this->assertStringContainsString('DB_CONNECTION=sqlite', $env);
            $this->assertStringContainsString('DB_DATABASE=:memory:', $env);

            $probe = new Process([
                PHP_BINARY,
                '-r',
                'require "vendor/autoload.php"; $r = new ReflectionClass("App\\\\Subject"); echo $r->getFileName();',
            ], $workspace, null, null, 60.0);
            $probe->run();

            $this->assertTrue($probe->isSuccessful(), $probe->getErrorOutput());
            $this->assertSame(realpath($workspace.'/app/Subject.php'), realpath(trim((string) $probe->getOutput())));
        } finally {
            $cleanup();
        }

        $this->assertFalse(is_dir($workspace));
    }
}
