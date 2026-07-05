<?php

namespace Tests\Feature\Ai\OperatorIntelligence;

use App\Models\OperatorLearningCandidate;
use App\Models\OperatorLearningSignal;
use App\Models\OperatorProfileItem;
use App\Services\Ai\OperatorIntelligence\OperatorProfileRegistry;
use Tests\Concerns\CreatesOperatorIntelligenceTables;
use Tests\TestCase;

final class OperatorProfileRegistryValidityKindTest extends TestCase
{
    use CreatesOperatorIntelligenceTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOperatorIntelligenceTables();
    }

    protected function tearDown(): void
    {
        $this->dropOperatorIntelligenceTables();
        parent::tearDown();
    }

    private function buildSignal(array $overrides = []): OperatorLearningSignal
    {
        return OperatorLearningSignal::query()->create(array_merge([
            'operator_id' => 'op-1',
            'taxonomy_item_id' => 'PREFERENCE',
            'signal_kind' => 'comprehension',
            'source_type' => 'conversation',
            'claim' => 'user prefers concise responses',
            'normalized_claim' => 'user prefers concise responses',
            'evidence_quote' => 'keep it brief',
            'inference_type' => 'observation',
            'confidence' => 0.8,
            'scope_type' => 'global',
            'privacy_class' => 'normal',
            'evidence_refs' => ['ref-1'],
            'metadata' => ['validity_hint' => 'durable'],
        ], $overrides));
    }

    private function buildCandidate(OperatorLearningSignal $signal, array $overrides = []): OperatorLearningCandidate
    {
        return OperatorLearningCandidate::query()->create(array_merge([
            'operator_id' => 'op-1',
            'signal_id' => $signal->id,
            'taxonomy_item_id' => 'PREFERENCE',
            'claim' => 'user prefers concise responses',
            'confidence' => 0.8,
            'classifier' => 'direct_extraction',
        ], $overrides));
    }

    public function test_momentary_hint_yields_temporary_with_bounded_valid_until(): void
    {
        $signal = $this->buildSignal(['metadata' => ['validity_hint' => 'momentary']]);
        $candidate = $this->buildCandidate($signal);

        $item = app(OperatorProfileRegistry::class)->promoteCandidate($candidate);

        $this->assertSame('temporary', $item->validity_kind);
        $this->assertNotNull($item->valid_until);
        $this->assertTrue($item->valid_until->greaterThan(now()));
    }

    public function test_durable_hint_yields_permanent_with_null_valid_until(): void
    {
        $signal = $this->buildSignal(['metadata' => ['validity_hint' => 'durable']]);
        $candidate = $this->buildCandidate($signal);

        $item = app(OperatorProfileRegistry::class)->promoteCandidate($candidate);

        $this->assertSame('permanent', $item->validity_kind);
        $this->assertNull($item->valid_until);
    }

    public function test_scoped_hint_yields_session_validity(): void
    {
        $signal = $this->buildSignal(['metadata' => ['validity_hint' => 'scoped']]);
        $candidate = $this->buildCandidate($signal);

        $item = app(OperatorProfileRegistry::class)->promoteCandidate($candidate);

        $this->assertSame('session', $item->validity_kind);
        $this->assertNotNull($item->valid_until);
        $this->assertTrue($item->valid_until->greaterThan(now()));
    }
}
