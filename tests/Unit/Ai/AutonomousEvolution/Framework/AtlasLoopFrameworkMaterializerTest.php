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

    /**
     * REGRESSION (HIGH false-reject): the class-name extractor used a comment-blind regex that took
     * the first `class|interface|trait|enum <word>` anywhere in the source — so a docblock line like
     * "This class is the explicit …" captured "is", producing a bad FQN whose ReflectionClass probe
     * threw and was mislabeled autoload_resolves_outside_worktree, FAILING ~3.4% of refactor targets.
     * The tokenizer-based extractor must return the REAL declared class regardless of docblock prose.
     */
    public function test_class_extractor_ignores_docblock_prose_and_returns_the_real_declaration(): void
    {
        $extract = static function (string $body): ?string {
            $dir = sys_get_temp_dir().'/atlas-fw-class-'.bin2hex(random_bytes(4));
            mkdir($dir, 0o755, true);
            $file = $dir.'/Probe.php';
            file_put_contents($file, $body);
            try {
                $m = new \ReflectionMethod(AtlasLoopFrameworkMaterializer::class, 'classFromFile');

                return $m->invoke(new AtlasLoopFrameworkMaterializer, $file);
            } finally {
                @unlink($file);
                @rmdir($dir);
            }
        };

        // The exact shape that broke live (L7L10QueueConsumer): a docblock that says "This class is …"
        // BEFORE the real declaration. The old regex returned "App\\X\\is"; the fix returns the class.
        $this->assertSame('App\\X\\Widget', $extract(
            "<?php\n\nnamespace App\\X;\n\n/**\n * This class is the explicit, governed entrypoint.\n * Another interface to nowhere.\n */\nfinal class Widget\n{\n    public function go(): int { return 1; }\n}\n",
        ));

        // Interfaces/traits/enums and a leading `Foo::class` use must not derail it.
        $this->assertSame('App\\Y\\Shape', $extract("<?php\nnamespace App\\Y;\nuse App\\Z;\n// this trait is fake\ninterface Shape {}\n"));
        $this->assertSame('App\\E\\Status', $extract("<?php\nnamespace App\\E;\n\$x = Other::class;\nenum Status: string { case A = 'a'; }\n"));

        // No declaration (or no namespace) => null (the defensive skip-verification path).
        $this->assertNull($extract("<?php\nnamespace App\\None;\n// just a class mention in a comment\n\$y = 1;\n"));

        // A `: never` (or other reserved-type) RETURN TYPE must not derail the real class name — this
        // is the shape that was live-failing (AtlasEvolutionScenarioExplorer with a `: never` method).
        $this->assertSame('App\\R\\Explorer', $extract(
            "<?php\nnamespace App\\R;\nfinal class Explorer { public function spin(): never { for(;;){} } }\n",
        ));

        // A bogus `class never` (reserved word as the declared name — only reachable from a transient
        // malformed worktree) is an EXTRACTION ARTIFACT, not a real class => null (skip verification),
        // so the refactor is never failed on a bogus autoload-wiring probe; the cert's behavior test
        // remains the real gate.
        $this->assertNull($extract("<?php\nnamespace App\\Bad;\nclass never { }\n"));
        $this->assertNull($extract("<?php\nnamespace App\\Bad;\nenum void {}\n"));
    }
}
