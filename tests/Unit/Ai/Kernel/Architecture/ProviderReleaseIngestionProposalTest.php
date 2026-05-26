<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasProviderReleaseSourceRegistry;
use App\Services\Ai\Kernel\Architecture\ProviderReleaseIngestionProposal;
use Tests\TestCase;

final class ProviderReleaseIngestionProposalTest extends TestCase
{
    private ProviderReleaseIngestionProposal $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProviderReleaseIngestionProposal(
            $this->app->make(AtlasProviderReleaseSourceRegistry::class),
        );
    }

    public function test_emits_canonical_schema_for_trusted_source(): void
    {
        $result = $this->service->ingest([
            'source_id' => 'anthropic_news',
            'raw_signal' => ['title' => 'Claude release', 'url' => 'https://example.com'],
        ]);

        $this->assertSame('atlas.provider_release.ingestion_proposal.v1', $result['schema_version']);
        $this->assertSame('proposed_for_review', $result['status']);
        $this->assertTrue($result['source_trusted']);
        $this->assertSame('CuratorProposalQueue', $result['forwards_to']);
        $this->assertFalse($result['policy_mutation_allowed']);
    }

    public function test_rejects_untrusted_source(): void
    {
        $result = $this->service->ingest([
            'source_id' => 'random_blog_'.uniqid(),
            'raw_signal' => ['title' => 'hot take'],
        ]);

        $this->assertSame('rejected_untrusted_source', $result['status']);
        $this->assertFalse($result['source_trusted']);
        $this->assertNull($result['forwards_to']);
    }

    public function test_rejects_missing_source_id(): void
    {
        $result = $this->service->ingest([
            'raw_signal' => ['title' => 'no source'],
        ]);

        $this->assertSame('rejected_invalid_payload', $result['status']);
    }

    public function test_rejects_empty_raw_signal(): void
    {
        $result = $this->service->ingest([
            'source_id' => 'anthropic_news',
            'raw_signal' => [],
        ]);

        $this->assertSame('rejected_invalid_payload', $result['status']);
    }

    public function test_fingerprint_defaults_to_payload_hash(): void
    {
        $payload = ['title' => 'X', 'url' => 'https://a.example'];
        $r1 = $this->service->ingest(['source_id' => 'anthropic_news', 'raw_signal' => $payload]);
        $r2 = $this->service->ingest(['source_id' => 'anthropic_news', 'raw_signal' => $payload]);
        $this->assertSame($r1['fingerprint'], $r2['fingerprint']);
        $this->assertSame(64, strlen($r1['fingerprint']));
    }

    public function test_anti_wrapper_invariants_always_present(): void
    {
        $r = $this->service->ingest(['source_id' => 'anthropic_news', 'raw_signal' => ['x' => 1]]);
        $this->assertContains('never_mutates_decide', $r['anti_wrapper_invariants']);
        $this->assertContains('never_auto_applies', $r['anti_wrapper_invariants']);
        $this->assertFalse($r['policy_mutation_allowed']);
    }

    public function test_envelope_shape_is_stable(): void
    {
        $r = $this->service->ingest(['source_id' => 'anthropic_news', 'raw_signal' => ['x' => 1]]);
        $this->assertSame([
            'schema_version', 'status', 'proposed_at', 'source_id', 'source_trusted',
            'fingerprint', 'anti_wrapper_invariants', 'forwards_to', 'detail', 'policy_mutation_allowed',
        ], array_keys($r));
    }
}
