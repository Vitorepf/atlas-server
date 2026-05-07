<?php

namespace Tests\Unit\Ai\Kernel\Gates;

use App\Services\Ai\Kernel\Gates\FailureSignatureClassifiedGate;
use App\Services\Ai\Kernel\Gates\FailureSignatureProviderSafetyGate;
use Tests\TestCase;

class FailureSignatureClassifiedGateTest extends TestCase
{
    public function test_failure_signature_gate_passes_canonical_category(): void
    {
        $result = app(FailureSignatureClassifiedGate::class)->evaluate([
            'domain' => 'programming',
            'message' => 'repair exhausted after maximum attempts',
        ]);

        $this->assertSame('passed', $result['status']);
        $this->assertSame('process', data_get($result, 'signature.category'));
    }

    public function test_provider_safety_gate_blocks_unredacted_private_summary(): void
    {
        $result = app(FailureSignatureProviderSafetyGate::class)->evaluate([
            'context_summary' => 'operator email vitor@example.com appeared in provider context',
            'canonical_features' => ['privacy_class' => 3],
        ]);

        $this->assertSame('blocked', $result['status']);
    }
}
