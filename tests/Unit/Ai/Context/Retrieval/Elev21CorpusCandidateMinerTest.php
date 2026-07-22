<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context\Retrieval;

use App\Services\Ai\Context\Retrieval\GatedCorpusCandidateMiner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Elev21CorpusCandidateMinerTest extends TestCase
{
    #[Test]
    public function canonical_docs_become_gated_candidates_with_origin_refs(): void
    {
        $out = GatedCorpusCandidateMiner::mine([
            ['source' => 'doc', 'ref' => 'docs/a.md#decision', 'text' => 'Decision: keep memory gated.', 'privacy_class' => 'normal'],
        ]);

        $this->assertSame('ok', $out['status']);
        $this->assertSame('docs/a.md#decision', $out['candidates'][0]['origin_ref']);
        $this->assertTrue($out['candidates'][0]['admission']['via_asi_02']);
        $this->assertTrue($out['candidates'][0]['admission']['immune_gates_apply']);
    }

    #[Test]
    public function protected_classes_are_not_emitted_to_provider_bound_candidates(): void
    {
        $out = GatedCorpusCandidateMiner::mine([
            ['source' => 'doc', 'ref' => 'secret', 'text' => 'secret text', 'privacy_class' => 'secret'],
        ]);

        $this->assertSame([], $out['candidates']);
        $this->assertSame(['protected_class_omitted'], $out['omitted']);
    }

    #[Test]
    public function direct_memory_writes_are_forbidden_by_contract(): void
    {
        $out = GatedCorpusCandidateMiner::mine([]);

        $this->assertFalse($out['source']['writes_memory_directly']);
        $this->assertTrue($out['source']['candidate_only']);
    }
}
