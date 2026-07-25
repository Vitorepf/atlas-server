<?php

declare(strict_types=1);

namespace Tests\Unit\Engineering;

use App\Services\Engineering\DocumentationReality\DocumentationRealityClassifySupport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DocumentationRealityClassifySupportTest extends TestCase
{
    #[Test]
    public function execution_for_classifies_executes_partial_declared(): void
    {
        $this->assertSame('declared', DocumentationRealityClassifySupport::executionFor(null, ['a'], ['b']));
        $this->assertSame('executes', DocumentationRealityClassifySupport::executionFor('a', ['a'], ['b']));
        $this->assertSame('partial', DocumentationRealityClassifySupport::executionFor('b', ['a'], ['b']));
        $this->assertSame('declared', DocumentationRealityClassifySupport::executionFor('c', ['a'], ['b']));
    }

    #[Test]
    public function declared_evidence_refs_parses_array_and_string_forms(): void
    {
        $refs = DocumentationRealityClassifySupport::declaredEvidenceRefs([
            ['kind' => 'doc', 'ref' => 'x'],
            'code:FooBar',
            'bad',
            ['kind' => '', 'ref' => 'skip'],
        ]);
        $this->assertSame([
            ['kind' => 'doc', 'ref' => 'x'],
            ['kind' => 'code', 'ref' => 'FooBar'],
        ], $refs);
    }
}
