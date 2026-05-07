<?php

namespace Tests\Unit\Ai\Cognitive\Failure;

use App\Services\Ai\Cognitive\Failure\FailureSignatureClassifier;
use Tests\TestCase;

class FailureSignatureClassifierTest extends TestCase
{
    public function test_classifier_maps_kernel_failure_domains_to_cognitive_signature(): void
    {
        $signature = app(FailureSignatureClassifier::class)->classify([
            'domain' => 'programming',
            'event_type' => 'OPERATION_FAILED',
            'envelope_id' => 'env_failure_1',
            'message' => 'Quality gate failed because test evidence missing.',
        ]);

        $this->assertSame('atlas.cognitive.failure_signature.v1', $signature['schema_version']);
        $this->assertSame('programming', $signature['domain']);
        $this->assertSame('process', $signature['category']);
        $this->assertSame('evidence_missing', $signature['sub_cause']);
        $this->assertStringStartsWith('fsig_', $signature['signature_key']);
        $this->assertSame('OPERATION_FAILED', $signature['canonical_features']['event_type']);
    }

    public function test_classifier_redacts_secrets_and_email_from_context_summary(): void
    {
        $signature = app(FailureSignatureClassifier::class)->classify([
            'domain' => 'finance',
            'message' => 'Provider refused key sk-supersecret123456789 and owner test@example.com.',
        ]);

        $this->assertStringNotContainsString('sk-supersecret', $signature['context_summary']);
        $this->assertStringNotContainsString('test@example.com', $signature['context_summary']);
        $this->assertStringContainsString('[REDACTED_SECRET]', $signature['context_summary']);
        $this->assertStringContainsString('[REDACTED_EMAIL]', $signature['context_summary']);
    }
}
