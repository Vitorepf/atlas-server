<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\ProviderReleaseCandidateFingerprint;
use Tests\TestCase;

class ProviderReleaseCandidateFingerprintTest extends TestCase
{
    public function test_canonical_url_normalizes_scheme_case_trailing_slash_and_tracking_query(): void
    {
        $fingerprint = app(ProviderReleaseCandidateFingerprint::class);

        $this->assertSame(
            'https://www.anthropic.com/news/finance-agents',
            $fingerprint->canonicalUrl('HTTP://WWW.ANTHROPIC.COM/news/finance-agents/?utm_source=x'),
        );
    }

    public function test_canonical_url_preserves_allowlisted_query_keys_only(): void
    {
        $fingerprint = app(ProviderReleaseCandidateFingerprint::class);

        $this->assertSame(
            'https://platform.openai.com/docs/changelog?page=2',
            $fingerprint->canonicalUrl('https://platform.openai.com/docs/changelog?utm=x&page=2', ['page' => null]),
        );
    }

    public function test_content_hash_is_stable_for_equivalent_urls(): void
    {
        $fingerprint = app(ProviderReleaseCandidateFingerprint::class);

        $left = $fingerprint->contentHash('Anthropic Finance Agents', 'https://www.anthropic.com/news/finance-agents/');
        $right = $fingerprint->contentHash('anthropic finance agents', 'http://www.anthropic.com/news/finance-agents?utm_campaign=test');

        $this->assertSame($left, $right);
    }

    public function test_unique_candidates_dedupes_by_canonical_url_and_content_hash(): void
    {
        $fingerprint = app(ProviderReleaseCandidateFingerprint::class);
        $contentHash = $fingerprint->contentHash('Release', 'https://example.com/release');

        $unique = $fingerprint->uniqueCandidates([
            ['source_url' => 'https://example.com/release', 'content_hash' => $contentHash, 'title' => 'Release'],
            ['source_url' => 'http://example.com/release/?utm_source=x', 'content_hash' => $contentHash, 'title' => 'Release duplicate'],
            ['source_url' => 'https://example.com/release-2', 'content_hash' => $contentHash, 'title' => 'Other release'],
        ]);

        $this->assertCount(2, $unique);
        $this->assertArrayHasKey('dedupe_key', $unique[0]);
        $this->assertArrayHasKey('dedupe_key', $unique[1]);
        $this->assertNotSame($unique[0]['dedupe_key'], $unique[1]['dedupe_key']);
    }
}
