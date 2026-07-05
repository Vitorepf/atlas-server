<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\NonFunctional\ArchitectureRegressionProbe;
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
}
