<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Grinder;

use App\Services\Ai\AutonomousEvolution\Grinder\AtlasLoopGrinderComprehensionCitationDeriver;
use Tests\TestCase;

class AtlasLoopGrinderComprehensionCitationDeriverTest extends TestCase
{
    public function test_semantic_allowed_files_unions(): void
    {
        $payload = ['allowed_files' => ['app/Foo.php', 'app/Bar.php']];
        $explorer = ['allowed_files' => ['app/Baz.php', 'app/Foo.php']];

        $result = AtlasLoopGrinderComprehensionCitationDeriver::semanticAllowedFiles($payload, $explorer);

        self::assertSame(['app/Foo.php', 'app/Bar.php', 'app/Baz.php'], $result);
    }

    public function test_semantic_allowed_files_handles_missing(): void
    {
        self::assertSame([], AtlasLoopGrinderComprehensionCitationDeriver::semanticAllowedFiles([], []));
    }

    public function test_comprehension_citations_strips_php_extension(): void
    {
        $payload = ['allowed_files' => ['app/Foo.php', 'app/Bar.php']];

        $result = AtlasLoopGrinderComprehensionCitationDeriver::comprehensionCitations($payload, []);

        self::assertSame(['Foo', 'Bar'], $result);
    }

    public function test_comprehension_citations_handles_non_php_files(): void
    {
        $payload = ['allowed_files' => ['docs/readme.md', 'config/app.yaml']];

        $result = AtlasLoopGrinderComprehensionCitationDeriver::comprehensionCitations($payload, []);

        self::assertSame(['readme.md', 'app.yaml'], $result);
    }

    public function test_comprehension_citations_empty_yields_empty(): void
    {
        self::assertSame([], AtlasLoopGrinderComprehensionCitationDeriver::comprehensionCitations([], []));
    }

    public function test_comprehension_citations_dedupes(): void
    {
        $payload = ['allowed_files' => ['app/Foo.php']];
        $explorer = ['allowed_files' => ['app/Foo.php']];

        $result = AtlasLoopGrinderComprehensionCitationDeriver::comprehensionCitations($payload, $explorer);

        self::assertSame(['Foo'], $result);
    }

    public function test_comprehension_citations_unions_payload_and_explorer(): void
    {
        $payload = ['allowed_files' => ['app/Foo.php']];
        $explorer = ['allowed_files' => ['app/Bar.php']];

        $result = AtlasLoopGrinderComprehensionCitationDeriver::comprehensionCitations($payload, $explorer);

        self::assertSame(['Foo', 'Bar'], $result);
    }
}