<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\AcosMax\AtlasModelCapabilitySpecService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AtlasModelCapabilitySpecServiceTest extends TestCase
{
    #[Test]
    public function frozen_spec_covers_every_function_consumed_by_the_program(): void
    {
        $service = new AtlasModelCapabilitySpecService();
        $functions = $service->functions();

        foreach (['dense_embed', 'late_chunk', 'rerank', 'sparse'] as $required) {
            $this->assertContains($required, $functions, "spec missing required function {$required}");
        }
    }

    #[Test]
    public function conforming_dense_embed_model_passes(): void
    {
        $service = new AtlasModelCapabilitySpecService();

        $verdict = $service->verify('dense_embed', [
            'model_id' => 'sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2',
            'dim' => 384,
            'pooling' => 'mean',
            'ctx_tokens' => 512,
            'multilingual_pt' => true,
            'deterministic' => true,
            'license' => 'apache-2.0',
        ]);

        $this->assertSame('ok', $verdict['status']);
        $this->assertSame([], $verdict['violations']);
    }

    #[Test]
    public function model_with_wrong_dim_is_refused_with_named_reason(): void
    {
        $service = new AtlasModelCapabilitySpecService();

        $verdict = $service->verify('dense_embed', [
            'model_id' => 'fake/wrong-dim-model',
            'dim' => 512,
            'pooling' => 'mean',
            'ctx_tokens' => 512,
            'multilingual_pt' => true,
            'deterministic' => true,
            'license' => 'apache-2.0',
        ]);

        $this->assertSame('violates_spec', $verdict['status']);
        $reasons = array_column($verdict['violations'], 'reason');
        $this->assertContains('dim_not_allowed', $reasons);
    }

    #[Test]
    public function model_with_forbidden_license_is_refused(): void
    {
        $service = new AtlasModelCapabilitySpecService();

        $verdict = $service->verify('rerank', [
            'model_id' => 'proprietary/rerank',
            'ctx_tokens' => 512,
            'pair_scoring' => true,
            'latency_per_pair_ms_p95' => 12,
            'deterministic' => true,
            'license' => 'proprietary',
        ]);

        $this->assertSame('violates_spec', $verdict['status']);
        $reasons = array_column($verdict['violations'], 'reason');
        $this->assertContains('license_not_allowed', $reasons);
    }

    #[Test]
    public function rerank_model_exceeding_latency_ceiling_is_refused(): void
    {
        $service = new AtlasModelCapabilitySpecService();

        $verdict = $service->verify('rerank', [
            'model_id' => 'too-slow/rerank',
            'ctx_tokens' => 512,
            'pair_scoring' => true,
            'latency_per_pair_ms_p95' => 250,
            'deterministic' => true,
            'license' => 'mit',
        ]);

        $this->assertSame('violates_spec', $verdict['status']);
        $reasons = array_column($verdict['violations'], 'reason');
        $this->assertContains('latency_above_spec_ceiling', $reasons);
    }

    #[Test]
    public function late_chunk_without_token_embeddings_is_refused(): void
    {
        $service = new AtlasModelCapabilitySpecService();

        $verdict = $service->verify('late_chunk', [
            'model_id' => 'plain/embed-only',
            'dim' => 768,
            'pooling' => 'none',
            'ctx_tokens' => 1024,
            'multilingual_pt' => true,
            'deterministic' => true,
            'license' => 'apache-2.0',
            'token_embeddings_exposed' => false,
        ]);

        $this->assertSame('violates_spec', $verdict['status']);
        $reasons = array_column($verdict['violations'], 'reason');
        $this->assertContains('token_embeddings_not_exposed', $reasons);
    }

    #[Test]
    public function sparse_model_conforming_passes(): void
    {
        $service = new AtlasModelCapabilitySpecService();

        $verdict = $service->verify('sparse', [
            'model_id' => 'naver/splade-cocondenser-ensembledistil',
            'ctx_tokens' => 512,
            'term_weights_exposed' => true,
            'deterministic' => true,
            'license' => 'apache-2.0',
        ]);

        $this->assertSame('ok', $verdict['status']);
    }

    #[Test]
    public function unknown_function_throws(): void
    {
        $service = new AtlasModelCapabilitySpecService();

        $this->expectExceptionMessage('unknown_model_function:hallucinated_fn');
        $service->verify('hallucinated_fn', [
            'model_id' => 'anything',
        ]);
    }
}
