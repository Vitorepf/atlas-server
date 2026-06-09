<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\RuntimeBoundary;

use App\Services\Ai\RuntimeBoundary\SemanticRagRuntimeClient;
use RuntimeException;
use Tests\TestCase;

/**
 * Proves the PHP kernel really invokes the Python semantic/RAG runtime and gets
 * REAL embeddings back through the boundary — not a PHP fake. Gated on the
 * runtime being set up (scripts/setup-semantic-rag-runtime.sh); when absent the
 * e2e test skips honestly and the anti-fallback test asserts an explicit failure.
 */
final class SemanticRagRuntimeClientTest extends TestCase
{
    public function test_php_invokes_real_python_embeddings_end_to_end(): void
    {
        $client = new SemanticRagRuntimeClient;
        if (! $client->available()) {
            $this->markTestSkipped('semantic_rag runtime not set up — honest skip (not a fake).');
        }

        $result = $client->retrieve(
            [
                ['id' => 'feline', 'text' => 'A cat is a small domesticated carnivorous mammal that purrs.'],
                ['id' => 'finance', 'text' => 'Quarterly revenue exceeded the analyst earnings forecast.'],
            ],
            'a kitten sleeping on the sofa',
            k: 2,
        );

        // Real embeddings rank the feline doc first despite non-overlapping vocabulary.
        $this->assertSame('feline', $result['matches'][0]['id'], (string) json_encode($result['matches']));
        $this->assertTrue($result['boundary']['real_embeddings']);
        $this->assertFalse($result['boundary']['fabricated_vectors']);
        $this->assertTrue($result['boundary']['embeddings_engine_in_python']);
        $this->assertContains($result['boundary']['provider'], ['fastembed_local', 'openai_api']);
    }

    public function test_runtime_absence_fails_explicitly_never_a_silent_fake(): void
    {
        $client = new SemanticRagRuntimeClient;
        if ($client->available()) {
            // Runtime present: the e2e test covers it; the anti-fake guard is exercised there.
            $this->assertTrue(true);

            return;
        }
        $this->expectException(RuntimeException::class);
        $client->embed(['x']);
    }
}
