<?php

namespace Tests\Unit\Ai;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService;
use Tests\TestCase;

class AtlasMemoryPrivacyServiceTest extends TestCase
{
    public function test_provider_allowed_rejects_secret_even_when_legacy_column_allows_external_ai(): void
    {
        $entry = new AtlasMemoryEntry([
            'privacy_class' => 'secret',
            'external_ai_allowed' => true,
            'metadata' => [
                'privacy' => [
                    'class' => 'secret',
                    'external_ai_allowed' => true,
                ],
            ],
        ]);

        $this->assertFalse(app(AtlasMemoryPrivacyService::class)->providerAllowed($entry));
    }

    public function test_provider_decision_explains_why_memory_is_not_provider_safe(): void
    {
        $entry = new AtlasMemoryEntry([
            'privacy_class' => 'secret',
            'external_ai_allowed' => true,
            'metadata' => [
                'privacy' => [
                    'class' => 'secret',
                    'external_ai_allowed' => true,
                ],
            ],
        ]);

        $decision = app(AtlasMemoryPrivacyService::class)->providerDecision($entry);

        $this->assertFalse($decision['allowed']);
        $this->assertSame('secret', $decision['privacy_class']);
        $this->assertFalse($decision['external_ai_allowed']);
        $this->assertSame('external_ai_blocked_by_privacy_class', $decision['reason']);
    }

    public function test_provider_decision_explains_metadata_level_block(): void
    {
        $entry = new AtlasMemoryEntry([
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'metadata' => [
                'privacy' => [
                    'class' => 'normal',
                    'external_ai_allowed' => false,
                ],
            ],
        ]);

        $decision = app(AtlasMemoryPrivacyService::class)->providerDecision($entry);

        $this->assertFalse($decision['allowed']);
        $this->assertSame('external_ai_blocked_by_metadata', $decision['reason']);
    }

    public function test_provider_allowed_rejects_private_by_configured_block_list(): void
    {
        config()->set('atlas.privacy.block_external_ai_for_sensitivity', ['private', 'sensitive']);

        $entry = new AtlasMemoryEntry([
            'privacy_class' => 'private',
            'external_ai_allowed' => true,
            'metadata' => [
                'privacy' => [
                    'class' => 'private',
                    'external_ai_allowed' => true,
                ],
            ],
        ]);

        $this->assertFalse(app(AtlasMemoryPrivacyService::class)->providerAllowed($entry));
    }

    public function test_provider_allowed_accepts_normal_memory_only_when_metadata_does_not_block(): void
    {
        $allowed = new AtlasMemoryEntry([
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'metadata' => [
                'privacy' => [
                    'class' => 'normal',
                    'external_ai_allowed' => true,
                ],
            ],
        ]);
        $blocked = new AtlasMemoryEntry([
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'metadata' => [
                'privacy' => [
                    'class' => 'normal',
                    'external_ai_allowed' => false,
                ],
            ],
        ]);

        $privacy = app(AtlasMemoryPrivacyService::class);

        $this->assertTrue($privacy->providerAllowed($allowed));
        $this->assertFalse($privacy->providerAllowed($blocked));
    }

    public function test_provider_fields_redact_raw_fallbacks(): void
    {
        $entry = new AtlasMemoryEntry([
            'title' => 'Bearer abcdefghijklmno',
            'summary' => 'token=abcdef1234567890',
            'body' => 'Authorization: Bearer abcdefghijklmno',
        ]);

        $privacy = app(AtlasMemoryPrivacyService::class);

        $this->assertStringContainsString('[redacted]', (string) $privacy->providerTitle($entry));
        $this->assertStringContainsString('[redacted]', (string) $privacy->providerSummary($entry));
        $this->assertStringContainsString('[redacted]', $privacy->providerBody($entry));
        $this->assertStringNotContainsString('abcdefghijklmno', $privacy->providerBody($entry));
    }
}
