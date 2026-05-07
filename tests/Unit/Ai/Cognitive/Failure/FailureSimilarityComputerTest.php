<?php

namespace Tests\Unit\Ai\Cognitive\Failure;

use App\Services\Ai\Cognitive\Failure\FailureSimilarityComputer;
use Tests\TestCase;

class FailureSimilarityComputerTest extends TestCase
{
    public function test_signature_key_is_deterministic_and_similarity_scores_repetition(): void
    {
        $computer = app(FailureSimilarityComputer::class);
        $signature = [
            'domain' => 'programming',
            'category' => 'technical',
            'sub_cause' => 'runtime_failed',
            'canonical_features' => [
                'event_type' => 'OPERATION_FAILED',
                'classification' => ['failure_domain' => 'runtime.failed'],
            ],
        ];

        $this->assertSame($computer->signatureKey($signature), $computer->signatureKey($signature));
        $this->assertSame(1.0, $computer->similarity($signature, [[
            ...$signature,
            'signature_key' => $computer->signatureKey($signature),
        ]]));
        $this->assertSame(0.55, $computer->similarity($signature, [[
            'domain' => 'programming',
            'category' => 'technical',
            'sub_cause' => 'provider_timeout',
            'signature_key' => 'different',
        ]]));
    }
}
