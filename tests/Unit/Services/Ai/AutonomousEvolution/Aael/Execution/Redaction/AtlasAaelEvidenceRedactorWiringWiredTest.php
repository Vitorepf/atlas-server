<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\AutonomousEvolution\Aael\Execution\Redaction;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\Redaction\AtlasAaelEvidenceRedactionPolicyRegistry;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\Redaction\AtlasAaelEvidenceRedactor;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\Redaction\RedactedEvidence;
use Tests\TestCase;

final class AtlasAaelEvidenceRedactorWiringWiredTest extends TestCase
{
    public function test_registry_redact_facade_invokes_redactor_and_scrubs_secret_payload(): void
    {
        $registry = new AtlasAaelEvidenceRedactionPolicyRegistry();
        $payload = 'authorization: Bearer abc1234567890abcdefg something /Users/op/secrets';

        $result = $registry->redact('stdout', $payload);

        $this->assertInstanceOf(RedactedEvidence::class, $result);
        $this->assertSame('stdout', $result->evidenceKind);
        $this->assertStringNotContainsString('abc1234567890abcdefg', $result->payload);
        $this->assertStringNotContainsString('/Users/op', $result->payload);
        $this->assertStringContainsString('[REDACTED:oauth_bearer]', $result->payload);
        $this->assertStringContainsString('[REDACTED:home]/', $result->payload);
    }

    public function test_registry_redact_is_deterministic_and_caches_redactor(): void
    {
        $registry = new AtlasAaelEvidenceRedactionPolicyRegistry();

        $a = $registry->redact('stdout', 'sk-ant-abcdefghijklmnop value');
        $b = $registry->redact('stdout', 'sk-ant-abcdefghijklmnop value');
        $this->assertSame($a->contentHash, $b->contentHash);
        $this->assertSame($a->payload, $b->payload);
        $this->assertStringContainsString('[REDACTED:anthropic_api_key]', $a->payload);
    }

    public function test_unknown_kind_via_registry_facade_uses_sealed_fail_closed_default(): void
    {
        $registry = new AtlasAaelEvidenceRedactionPolicyRegistry();
        $result = $registry->redact('totally_new_kind', 'top secret payload that should disappear');

        $this->assertSame(AtlasAaelEvidenceRedactionPolicyRegistry::UNKNOWN_KIND_SENTINEL, $result->payload);
    }

    public function test_direct_redactor_and_registry_facade_agree_on_output(): void
    {
        $registry = new AtlasAaelEvidenceRedactionPolicyRegistry();
        $direct = new AtlasAaelEvidenceRedactor($registry);

        $payload = ['env' => 'PATH=/Users/op/bin', 'note' => 'Bearer abcdefghijklmnopqrst'];
        $viaRegistry = $registry->redact('env_snapshot', $payload);
        $viaDirect = $direct->redact('env_snapshot', $payload);

        $this->assertSame($viaDirect->contentHash, $viaRegistry->contentHash);
        $this->assertSame($viaDirect->payload, $viaRegistry->payload);
    }
}
