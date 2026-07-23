<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\MemoryGovernance\AtlasMemorySourcePrivacyPolicy;
use Tests\TestCase;

class AtlasMemorySourcePrivacyPolicyTest extends TestCase
{
    public function test_trace_with_secret_defaults_to_blocked_secret_projection(): void
    {
        $projection = app(AtlasMemorySourcePrivacyPolicy::class)->project('trace', [
            'trace_key' => 'trace_123',
            'intent' => 'debug',
            'operator_input' => 'Use Bearer abcdefghijklmno to reproduce the issue.',
            'response_text' => 'The provider returned a diagnostic.',
        ]);

        $this->assertSame('ai_trace', $projection['source_type']);
        $this->assertSame('trace', $projection['source_category']);
        $this->assertSame('secret', $projection['privacy_class']);
        $this->assertFalse($projection['external_ai_allowed']);
        $this->assertFalse($projection['provider_safe']);
        $this->assertSame('redacted', $projection['redaction_status']);
        $this->assertStringContainsString('Bearer [redacted]', (string) data_get($projection, 'fields.body'));
        $this->assertStringNotContainsString('abcdefghijklmno', (string) data_get($projection, 'fields.body'));
    }

    public function test_semantic_note_can_be_explicitly_provider_safe_after_redaction(): void
    {
        $projection = app(AtlasMemorySourcePrivacyPolicy::class)->project('semantic_note', [
            'title' => 'Provider-safe note',
            'summary' => 'Use this note in normal contexts.',
            'body_excerpt' => 'Observed token=abcdef1234567890 should be removed.',
            'frontmatter' => [
                'privacy_class' => 'normal',
            ],
        ]);

        $this->assertSame('semantic_note', $projection['source_type']);
        $this->assertSame('normal', $projection['privacy_class']);
        $this->assertTrue($projection['external_ai_allowed']);
        $this->assertTrue($projection['provider_safe']);
        $this->assertSame('redacted', $projection['redaction_status']);
        $this->assertStringContainsString('token=[redacted]', (string) data_get($projection, 'fields.body'));
    }

    public function test_engineering_artifacts_default_to_private_provider_blocked(): void
    {
        $projection = app(AtlasMemorySourcePrivacyPolicy::class)->project('patch_artifact', [
            'path' => 'attempt-1.patch',
            'diff_excerpt' => '+ changed application code',
            'risk_flags' => ['migration_changed'],
        ]);

        $this->assertSame('engineering_artifact', $projection['source_type']);
        $this->assertSame('artifact', $projection['source_category']);
        $this->assertSame('private', $projection['privacy_class']);
        $this->assertFalse($projection['external_ai_allowed']);
        $this->assertFalse($projection['provider_safe']);
        $this->assertSame('clean', $projection['redaction_status']);
    }
}
