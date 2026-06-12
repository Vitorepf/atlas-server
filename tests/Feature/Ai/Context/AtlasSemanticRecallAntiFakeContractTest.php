<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\RuntimeBoundary\SemanticRetrievalRuntime;
use Tests\TestCase;

/**
 * O-4 anti-fake contract: the AUCRI semantic channel must be REAL local embeddings, not a
 * lexical/hash stand-in. Frozen so it can never silently regress to fake vectors.
 *
 * When the local python runtime (fastembed/ONNX under its venv) is unavailable — e.g. a
 * CI box without the venv — the system honestly degrades to the manifest placeholder, so
 * this test SKIPS rather than fails (it asserts a property of the REAL engine, not its
 * mere presence). When available, it pins two things the fake could never satisfy:
 *   1. the boundary receipt declares real_embeddings=true / fabricated_vectors=false;
 *   2. semantic ranking holds with ZERO shared tokens — "kitten meowing" ranks a feline
 *      doc far above a canine doc and a finance doc (lexical overlap would tie them ~0).
 */
final class AtlasSemanticRecallAntiFakeContractTest extends TestCase
{
    public function test_semantic_channel_is_real_local_embeddings_not_a_lexical_stand_in(): void
    {
        $runtime = app(SemanticRetrievalRuntime::class);
        if (! $runtime->available()) {
            $this->markTestSkipped('local semantic_rag runtime unavailable — honest degrade to manifest placeholder');
        }

        $docs = [
            ['id' => 'feline', 'text' => 'The cat is a small domesticated feline animal that purrs.'],
            ['id' => 'canine', 'text' => 'The dog is a loyal canine animal that barks.'],
            ['id' => 'finance', 'text' => 'Quarterly revenue and EBITDA margins drive the stock valuation.'],
        ];

        $result = $runtime->retrieve($docs, 'a tiny kitten meowing', 3);

        // 1. The boundary receipt proves real embeddings (the anti-fake guard's own claim).
        $boundary = is_array($result['boundary'] ?? null) ? $result['boundary'] : [];
        $this->assertTrue((bool) ($boundary['real_embeddings'] ?? false), 'channel must use real embeddings');
        $this->assertFalse((bool) ($boundary['fabricated_vectors'] ?? true), 'no fabricated vectors allowed');
        $this->assertTrue((bool) ($boundary['embeddings_engine_in_python'] ?? false), 'embeddings must run in the python runtime');

        // 2. Semantic ranking with zero lexical overlap: kitten -> feline >> canine >> finance.
        $scores = [];
        foreach ($result['matches'] ?? [] as $m) {
            $scores[(string) ($m['id'] ?? '')] = (float) ($m['score'] ?? 0.0);
        }
        $this->assertArrayHasKey('feline', $scores);
        $this->assertArrayHasKey('canine', $scores);
        $this->assertArrayHasKey('finance', $scores);
        $this->assertGreaterThan($scores['canine'], $scores['feline'], 'feline must outrank canine for "kitten"');
        $this->assertGreaterThan($scores['finance'], $scores['canine'], 'an animal doc must outrank a finance doc');
        // A lexical/hash stand-in would put these near-equal (no shared tokens); real
        // embeddings open a wide gap. Pin a conservative floor on that gap.
        $this->assertGreaterThan(0.3, $scores['feline'] - $scores['finance'], 'real semantics open a wide gap; lexical stand-in would not');
    }
}
