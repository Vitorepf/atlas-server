<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\OperatorIntelligence;

use App\Models\OperatorProfileItem;
use App\Services\Ai\OperatorIntelligence\OperatorLearningGate;
use App\Services\Ai\OperatorIntelligence\OperatorSignalCaptureService;
use Tests\Concerns\CreatesOperatorIntelligenceTables;
use Tests\TestCase;

/**
 * The end-to-end safety proof for activating Phase-2 auto-apply. With auto_apply ON and
 * shadow OFF, ONLY a trusted-provenance, safe, explicit, high-confidence, non-high-stakes,
 * normal-privacy item reaches automatic application. Everything else — the passive regex
 * detector (no provenance), an inference, a registry-high-stakes id, a registry-sensitive
 * id — is captured for the Sunday review but can NEVER auto-apply. This is what makes
 * leaving the flag ON safe.
 */
final class OperatorAutoApplyGovernanceTest extends TestCase
{
    use CreatesOperatorIntelligenceTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createOperatorIntelligenceTables();
        config([
            'atlas_operator_intelligence.auto_apply_enabled' => true,
            'atlas_operator_intelligence.shadow_mode' => false,
            'atlas_operator_intelligence.min_auto_apply_confidence' => 0.85,
        ]);
    }

    /**
     * @param  array<string,mixed>  $over
     * @return array<string,mixed>
     */
    private function capture(array $over): array
    {
        $comprehension = ['metadata' => ['auto_apply_provenance' => OperatorLearningGate::AUTO_APPLY_PROVENANCE]];

        return app(OperatorSignalCaptureService::class)->capture(array_merge([
            'operator_id' => 'vitor',
            'source_type' => 'chat_comprehension',
            'privacy_class' => 'normal',
            'risk_level' => 'low',
        ], $comprehension, $over));
    }

    public function test_trusted_safe_explicit_item_auto_applies(): void
    {
        $res = $this->capture([
            'taxonomy_item_id' => 'COL-156',
            'claim' => 'O operador prefere respostas curtas e objetivas.',
            'confidence' => 0.93,
            'inference_type' => 'explicit',
            'scope_type' => 'project',
        ]);

        $this->assertTrue(data_get($res, 'automation.applied'), 'a trusted safe explicit item must auto-apply');
        $this->assertSame(1, OperatorProfileItem::query()->count());
    }

    public function test_passive_detector_signal_without_provenance_never_auto_applies(): void
    {
        // Same safe values, but NO provenance marker (the regex detector path).
        $res = app(OperatorSignalCaptureService::class)->capture([
            'operator_id' => 'vitor',
            'source_type' => 'chat_explicit_operator_signal',
            'taxonomy_item_id' => 'COL-156',
            'claim' => 'O operador prefere respostas curtas.',
            'privacy_class' => 'normal',
            'risk_level' => 'low',
            'confidence' => 0.95,
            'inference_type' => 'explicit',
            'scope_type' => 'project',
        ]);

        $this->assertFalse(data_get($res, 'automation.applied'), 'an unprovenanced producer must never auto-apply');
        $this->assertSame(0, OperatorProfileItem::query()->count());
    }

    public function test_inference_never_auto_applies(): void
    {
        $res = $this->capture([
            'taxonomy_item_id' => 'OP-075',
            'claim' => 'Talvez o operador prefira X.',
            'confidence' => 0.95, // even claiming high confidence...
            'inference_type' => 'implicit', // ...the structural clamp forces ≤0.6 → review
            'confidence_tier' => 'single_inference',
            'scope_type' => 'project',
        ]);

        $this->assertFalse(data_get($res, 'automation.applied'));
        $this->assertSame(0, OperatorProfileItem::query()->count());
    }

    public function test_registry_high_stakes_item_never_auto_applies(): void
    {
        $res = $this->capture([
            'taxonomy_item_id' => 'OP-145', // "what Atlas can never touch" — high-stakes
            'claim' => 'O operador declara um limite forte.',
            'confidence' => 0.99,
            'inference_type' => 'explicit',
            'scope_type' => 'project',
        ]);

        $this->assertFalse(data_get($res, 'automation.applied'), 'a high-stakes id must never auto-apply');
        $this->assertSame(0, OperatorProfileItem::query()->count());
    }

    public function test_registry_sensitive_item_never_auto_applies(): void
    {
        $res = $this->capture([
            'taxonomy_item_id' => 'OP-141', // registry sensitive_default
            'claim' => 'Uma restricao pessoal do operador.',
            'privacy_class' => 'normal', // even mislabeled normal...
            'confidence' => 0.95,
            'inference_type' => 'explicit',
            'scope_type' => 'project',
        ]);

        $this->assertFalse(data_get($res, 'automation.applied'), 'a registry-sensitive id must never auto-apply');
        $this->assertSame(0, OperatorProfileItem::query()->count());
    }
}
