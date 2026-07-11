<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\NonFunctional\ArchitectureRegressionProbe;
use PhpParser\Error;
use PHPUnit\Framework\TestCase;

final class ArchitectureEdgeExtractionTest extends TestCase
{
    public function test_simple_import_extracts_edge_and_triggers_violation(): void
    {
        $sources = [
            'app/Services/Ai/EngineeringKernel/Sub/SomeClass.php' => <<<'PHP'
                <?php
                namespace App\Services\Ai\EngineeringKernel\Sub;
                use Illuminate\Database\Eloquent\Model;
                PHP,
        ];

        $edges = ArchitectureRegressionProbe::edgesFromSources($sources);

        $this->assertCount(1, $edges);
        $this->assertSame('App\Services\Ai\EngineeringKernel\Sub', $edges[0]['from']);
        $this->assertSame('Illuminate\Database\Eloquent\Model', $edges[0]['to']);

        // Feeding into violations() yields exactly ONE violation (Eloquent is forbidden)
        $violations = ArchitectureRegressionProbe::violations($edges);
        $this->assertCount(1, $violations);
        $this->assertStringContainsString('Illuminate\Database', $violations[0]);
    }

    public function test_grouped_import_extracts_both_edges(): void
    {
        $sources = [
            'app/Services/Ai/EngineeringKernel/Sub/AnotherClass.php' => <<<'PHP'
                <?php
                namespace App\Services\Ai\EngineeringKernel\Sub;
                use App\Foo\{Bar, Baz};
                PHP,
        ];

        $edges = ArchitectureRegressionProbe::edgesFromSources($sources);

        $this->assertCount(2, $edges);
        $this->assertSame('App\Services\Ai\EngineeringKernel\Sub', $edges[0]['from']);
        $this->assertSame('App\Foo\Bar', $edges[0]['to']);
        $this->assertSame('App\Services\Ai\EngineeringKernel\Sub', $edges[1]['from']);
        $this->assertSame('App\Foo\Baz', $edges[1]['to']);

        // Neither triggers a forbidden-layer rule (App\Foo is not forbidden)
        $violations = ArchitectureRegressionProbe::violations($edges);
        $this->assertCount(0, $violations);
    }

    public function test_aliased_leading_backslash_import_normalizes_to(): void
    {
        $sources = [
            'app/Services/Ai/EngineeringKernel/Sub/AnotherClass.php' => <<<'PHP'
                <?php
                namespace App\Services\Ai\EngineeringKernel\Sub;
                use \Illuminate\Database\Eloquent\Model as EloquentModel;
                PHP,
        ];

        $edges = ArchitectureRegressionProbe::edgesFromSources($sources);

        $this->assertCount(1, $edges);
        // "to" must be normalized: no leading backslash
        $this->assertSame('Illuminate\Database\Eloquent\Model', $edges[0]['to']);
        $this->assertSame('I', $edges[0]['to'][0], 'to must not have leading backslash');

        // Still trips the forbidden-layer rule
        $violations = ArchitectureRegressionProbe::violations($edges);
        $this->assertCount(1, $violations);
    }

    public function test_from_is_declared_namespace_not_use_target(): void
    {
        $sources = [
            'app/Services/Ai/EngineeringKernel/Sub/YetAnother.php' => <<<'PHP'
                <?php
                namespace App\Services\Ai\EngineeringKernel\Sub;
                use App\Services\Ai\EngineeringKernel\SovereignHonestyFloor;
                PHP,
        ];

        $edges = ArchitectureRegressionProbe::edgesFromSources($sources);

        $this->assertCount(1, $edges);
        // "from" MUST be the file's declared namespace, not the use target
        $this->assertSame('App\Services\Ai\EngineeringKernel\Sub', $edges[0]['from']);
        $this->assertSame('App\Services\Ai\EngineeringKernel\SovereignHonestyFloor', $edges[0]['to']);

        // A naive parser that confuses "from" with a use target would have
        // from=SovereignHonestyFloor — but it must NOT.
        $this->assertNotSame($edges[0]['from'], $edges[0]['to']);

        // No violation: SovereignHonestyFloor is inside EngineeringKernel
        $violations = ArchitectureRegressionProbe::violations($edges);
        $this->assertCount(0, $violations);
    }

    public function test_empty_source_without_namespace_produces_no_edges(): void
    {
        $sources = [
            'app/Foo.php' => <<<'PHP'
                <?php
                use Illuminate\Database\Eloquent\Model;
                PHP,
        ];

        $edges = ArchitectureRegressionProbe::edgesFromSources($sources);

        $this->assertCount(0, $edges);
    }

    public function test_ast_extracts_fqcn_static_extends_implements_and_attributes(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App\Services\Ai\EngineeringKernel\Sub;
            #[\App\Models\AuditAttribute]
            final class Dangerous extends \App\Models\BaseModel implements \Illuminate\Http\Responsable
            {
                public function run(): void
                {
                    \App\Models\User::query();
                    new \Illuminate\Database\Connection();
                }
            }
            PHP;

        $targets = array_column(ArchitectureRegressionProbe::edgesFromSources(['Dangerous.php' => $source]), 'to');

        $this->assertContains('App\Models\AuditAttribute', $targets);
        $this->assertContains('App\Models\BaseModel', $targets);
        $this->assertContains('Illuminate\Http\Responsable', $targets);
        $this->assertContains('App\Models\User', $targets);
        $this->assertContains('Illuminate\Database\Connection', $targets);
        $this->assertCount(5, ArchitectureRegressionProbe::violations(
            ArchitectureRegressionProbe::edgesFromSources(['Dangerous.php' => $source]),
        ));
    }

    public function test_ast_ignores_dependency_like_text_in_comments_and_strings(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App\Services\Ai\EngineeringKernel\Sub;
            // \App\Models\User::query();
            final class Safe
            {
                public string $text = '\\Illuminate\\Database\\Connection';
            }
            PHP;

        $this->assertSame([], ArchitectureRegressionProbe::edgesFromSources(['Safe.php' => $source]));
    }

    public function test_exact_kernel_root_namespace_is_governed_for_fqcn_extends_and_static_calls(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App\Services\Ai\EngineeringKernel;
            final class RootDanger extends \App\Models\BaseModel
            {
                public function run(): void
                {
                    \App\Models\User::query();
                    \Illuminate\Database\Connection::class;
                }
            }
            PHP;

        $edges = ArchitectureRegressionProbe::edgesFromSources(['RootDanger.php' => $source]);

        $this->assertCount(3, ArchitectureRegressionProbe::violations($edges));
    }

    public function test_unsupported_php_syntax_fails_closed(): void
    {
        $this->expectException(Error::class);

        ArchitectureRegressionProbe::edgesFromSources([
            'Broken.php' => '<?php namespace App\\Services\\Ai\\EngineeringKernel; class {',
        ]);
    }
}
