<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasRetrievalPrivacyTrustLayerService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class RetrievalPrivacyTrustLayerTest extends TestCase
{
    public function test_safe_context_passes_provider_gate_and_emits_receipts(): void
    {
        $payload = app(AtlasRetrievalPrivacyTrustLayerService::class)->evaluate([
            'query' => 'debug context retrieval ranking with tests',
            'domain' => 'developer',
            'task_type' => 'debug',
            'risk_level' => 'low',
            'provider_target' => 'external',
            'source_refs' => [[
                'source_type' => 'canonical_doc',
                'source_ref' => 'docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md',
                'classification' => 'public',
                'authority_level' => 'canonical_doc',
            ]],
        ]);

        $this->assertSame(AtlasRetrievalPrivacyTrustLayerService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('passed', data_get($payload, 'provider_gate.status'));
        $this->assertTrue(data_get($payload, 'provider_gate.provider_allowed'));
        $this->assertSame('clean', data_get($payload, 'redaction_receipt.redaction_status'));
        $this->assertTrue(data_get($payload, 'retention_policy.delete_cascade_required'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'trust_receipt.receipt_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['privacy_trust_hash']);
    }

    public function test_secret_context_is_blocked_for_external_provider_and_never_leaks_raw_text(): void
    {
        $secret = 'api_key=sk-SEGREDOARPTL1234567890';
        $payload = app(AtlasRetrievalPrivacyTrustLayerService::class)->evaluate([
            'query' => 'use this '.$secret.' for retrieval',
            'provider_target' => 'external',
            'risk_level' => 'high',
        ]);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('secret', data_get($payload, 'provider_gate.classification'));
        $this->assertFalse(data_get($payload, 'provider_gate.provider_allowed'));
        $this->assertContains('secret_context_requires_local_only', data_get($payload, 'provider_gate.blocked_reasons'));
        $this->assertSame('redacted', data_get($payload, 'redaction_receipt.redaction_status'));
        $this->assertFalse(data_get($payload, 'redaction_receipt.raw_context_exposed'));
        $this->assertStringNotContainsString($secret, $encoded);
        $this->assertStringNotContainsString('sk-SEGREDOARPTL', $encoded);
    }

    public function test_pii_context_requires_review_or_local_execution_for_external_provider(): void
    {
        $payload = app(AtlasRetrievalPrivacyTrustLayerService::class)->evaluate([
            'raw_context' => 'Cliente: maria@example.com CPF 123.456.789-10',
            'provider_target' => 'external',
            'risk_level' => 'medium',
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('confidential', data_get($payload, 'provider_gate.classification'));
        $this->assertFalse(data_get($payload, 'provider_gate.provider_allowed'));
        $this->assertContains('confidential_context_requires_review_or_local_execution', data_get($payload, 'provider_gate.blocked_reasons'));
        $this->assertSame(2, data_get($payload, 'redaction_receipt.redaction_count'));
    }

    public function test_local_secret_context_stays_local_and_disallows_embedding_without_delete_policy(): void
    {
        $payload = app(AtlasRetrievalPrivacyTrustLayerService::class)->evaluate([
            'raw_context' => 'password=super-secreto-123456',
            'provider_target' => 'local',
            'risk_level' => 'high',
        ]);

        $this->assertSame('redacted', $payload['status']);
        $this->assertSame('passed', data_get($payload, 'provider_gate.status'));
        $this->assertTrue(data_get($payload, 'provider_gate.requires_local_only'));
        $this->assertSame(0, data_get($payload, 'retention_policy.retention_days'));
        $this->assertTrue(data_get($payload, 'retention_policy.embedding_delete_required'));
        $this->assertNotContains('provider_context_after_gate', data_get($payload, 'provider_gate.allowed_actions'));
    }

    public function test_hash_is_deterministic(): void
    {
        $service = app(AtlasRetrievalPrivacyTrustLayerService::class);

        $first = $service->evaluate(['query' => 'safe deterministic context']);
        $second = $service->evaluate(['query' => 'safe deterministic context']);

        $this->assertSame($first['privacy_trust_hash'], $second['privacy_trust_hash']);
        $this->assertSame(data_get($first, 'trust_receipt.receipt_hash'), data_get($second, 'trust_receipt.receipt_hash'));
    }

    public function test_command_emits_json(): void
    {
        $exit = Artisan::call('atlas:context:privacy-trust', [
            '--query' => 'safe context',
            '--provider-target' => 'external',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasRetrievalPrivacyTrustLayerService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
    }
}
