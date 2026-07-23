<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOutputContractNormalizer;
use Tests\TestCase;

final class AtlasExternalBrainOutputContractNormalizerTest extends TestCase
{
    private function normalizer(): AtlasExternalBrainOutputContractNormalizer
    {
        return new AtlasExternalBrainOutputContractNormalizer;
    }

    public function test_valid_output_is_normalized_into_stable_envelope(): void
    {
        $result = $this->normalizer()->normalizeEnvelope([
            'schema' => 'atlas.brain.some_module.v1',
            'status' => 'ok',
            'decision' => 'proceed',
            'evidence_refs' => ['tests/Unit/FooTest.php'],
        ]);

        $this->assertSame('atlas.brain.some_module.v1', $result['schema']);
        $this->assertSame('ok', $result['status']);
        $this->assertSame('proceed', $result['decision']);
        $this->assertSame(['tests/Unit/FooTest.php'], $result['evidence_refs']);
        $this->assertSame([], $result['warnings']);
        $this->assertTrue($result['provider_safe']);
    }

    public function test_missing_schema_status_and_decision_are_repaired_and_flagged(): void
    {
        $result = $this->normalizer()->normalizeEnvelope([]);

        $this->assertSame('atlas.external_brain.unknown_output.v1', $result['schema']);
        $this->assertSame('unknown', $result['status']);
        $this->assertSame('undecided', $result['decision']);
        $this->assertContains('missing_schema_defaulted', $result['warnings']);
        $this->assertContains('missing_or_invalid_status_defaulted', $result['warnings']);
        $this->assertContains('missing_decision_defaulted', $result['warnings']);
        $this->assertContains('no_evidence_refs', $result['warnings']);
        $this->assertTrue($result['provider_safe']);
    }

    public function test_invalid_status_value_is_defaulted_rather_than_passed_through(): void
    {
        $result = $this->normalizer()->normalizeEnvelope([
            'schema' => 'atlas.x.v1',
            'status' => 'totally_bogus_status',
            'decision' => 'proceed',
            'evidence_refs' => ['ref-1'],
        ]);

        $this->assertSame('unknown', $result['status']);
        $this->assertContains('missing_or_invalid_status_defaulted', $result['warnings']);
    }

    public function test_raw_prompt_and_provider_text_fields_are_redacted_while_safe_refs_are_preserved(): void
    {
        $result = $this->normalizer()->normalizeEnvelope([
            'schema' => 'atlas.brain.module.v1',
            'status' => 'ok',
            'decision' => 'proceed',
            'evidence_refs' => ['tests/Unit/FooTest.php'],
            'raw_prompt' => 'here is the actual sensitive provider prompt text',
            'raw_response' => 'here is the raw provider response text',
        ]);

        $this->assertSame(['tests/Unit/FooTest.php'], $result['evidence_refs']);
        $this->assertTrue($result['provider_safe']);

        $redactionWarnings = array_filter($result['warnings'], static fn (string $w): bool => str_starts_with($w, 'raw_text_redacted:'));
        $this->assertCount(2, $redactionWarnings);
        foreach ($redactionWarnings as $warning) {
            $this->assertStringNotContainsString('sensitive provider prompt text', $warning);
            $this->assertStringContainsString('sha256:', $warning);
        }
    }
}
