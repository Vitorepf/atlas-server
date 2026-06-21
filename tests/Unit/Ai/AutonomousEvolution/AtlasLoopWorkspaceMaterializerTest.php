<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopWorkspaceMaterializer;
use Tests\TestCase;

final class AtlasLoopWorkspaceMaterializerTest extends TestCase
{
    /** @var list<callable> */
    private array $cleanups = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanups as $cleanup) {
            $cleanup();
        }

        parent::tearDown();
    }

    public function test_materialize_writes_default_composer_when_support_files_do_not_include_it(): void
    {
        [$task, $cleanup] = (new AtlasLoopWorkspaceMaterializer)->materialize('make Foo good', [
            'target_relative_path' => 'src/Foo.php',
            'target_content' => "<?php\nfinal class Foo {}\n",
            'acceptance' => ['commands' => ['php tests/FooTest.php']],
        ]);
        $this->cleanups[] = $cleanup;

        $this->assertSame("{}\n", file_get_contents($task['base_workspace'].'/composer.json'));
    }

    public function test_materialize_preserves_supported_composer_override(): void
    {
        [$task, $cleanup] = (new AtlasLoopWorkspaceMaterializer)->materialize('make Foo good', [
            'target_relative_path' => 'src/Foo.php',
            'target_content' => "<?php\nfinal class Foo {}\n",
            'acceptance' => ['commands' => ['php tests/FooTest.php']],
            'support_files' => [[
                'path' => 'composer.json',
                'content' => "{\n    \"name\": \"atlas/test\"\n}\n",
            ]],
        ]);
        $this->cleanups[] = $cleanup;

        $this->assertSame("{\n    \"name\": \"atlas/test\"\n}\n", file_get_contents($task['base_workspace'].'/composer.json'));
    }

    public function test_support_has_distinguishes_matching_composer_file_from_non_matching_support_entries(): void
    {
        $method = new \ReflectionMethod(AtlasLoopWorkspaceMaterializer::class, 'supportHas');
        $subject = new AtlasLoopWorkspaceMaterializer();

        $this->assertTrue($method->invoke($subject, [['path' => './composer.json']], 'composer.json'));
        $this->assertFalse($method->invoke($subject, [['path' => 'support/other.json']], 'composer.json'));
    }
}
