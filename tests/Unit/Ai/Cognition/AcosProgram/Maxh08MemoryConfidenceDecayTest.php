<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\Memory\MemoryConfidenceDecayCalculator;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MAXH-08 — pure decayed-confidence calculator.
 *
 * Frontier plan §1541-1546 acceptance:
 *   - non-verified entry beyond half-life ⇒ decayed_confidence < base;
 *     needs_reverification true; base intocada (NEVER overwritten)
 *   - recall-hit resets/refreshes — usage counts as weak re-verification
 *   - decay is pure/deterministic
 *   - canonical types are ISENTOS
 *   - NEVER deletes (calculator returns a value, does not mutate storage)
 */
final class Maxh08MemoryConfidenceDecayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Pin config so the tests are hermetic against dev-only tweaks.
        config()->set('atlas.semantic_memory.confidence_decay.half_life_days', [
            'decision' => 30.0,
            'operational' => 14.0,
        ]);
        config()->set('atlas.semantic_memory.confidence_decay.canonical_exempt_types', ['canonical', 'operator_authored']);
        config()->set('atlas.semantic_memory.confidence_decay.floor_fraction', 0.15);
    }

    #[Test]
    public function formula_version_is_pinned(): void
    {
        $this->assertSame('atlas.memory.confidence_decay.v1', MemoryConfidenceDecayCalculator::FORMULA_VERSION);
    }

    #[Test]
    public function canonical_type_is_exempt_from_decay(): void
    {
        $out = MemoryConfidenceDecayCalculator::compute(
            baseConfidence: 90.0,
            verifiedAt: CarbonImmutable::now()->subYears(2),
            recallHits: 0,
            memoryType: 'canonical',
            referenceNow: CarbonImmutable::now(),
        );

        $this->assertTrue($out['canonical_exempt']);
        $this->assertSame(90.0, $out['decayed_confidence']);
        $this->assertSame(90.0, $out['base_confidence']);
        $this->assertFalse($out['needs_reverification']);
        $this->assertSame(1.0, $out['decay_factor']);
    }

    #[Test]
    public function non_verified_entry_beyond_half_life_decays_below_base(): void
    {
        $now = CarbonImmutable::create(2026, 7, 1);
        $out = MemoryConfidenceDecayCalculator::compute(
            baseConfidence: 100.0,
            verifiedAt: $now->subDays(60), // 2 half-lives for 'decision' (30d)
            recallHits: 0,
            memoryType: 'decision',
            referenceNow: $now,
        );

        // 2^-2 = 0.25
        $this->assertEqualsWithDelta(25.0, $out['decayed_confidence'], 1e-4);
        $this->assertLessThan($out['base_confidence'], $out['decayed_confidence']);
        $this->assertTrue($out['needs_reverification']);
        $this->assertFalse($out['floor_applied']);
        // Base MUST be preserved intact.
        $this->assertSame(100.0, $out['base_confidence']);
    }

    #[Test]
    public function recall_hits_slow_the_decay_as_weak_reverification(): void
    {
        $now = CarbonImmutable::create(2026, 7, 1);
        $verifiedAt = $now->subDays(30);

        $withoutHits = MemoryConfidenceDecayCalculator::compute(100.0, $verifiedAt, 0, 'decision', $now);
        $withHits = MemoryConfidenceDecayCalculator::compute(100.0, $verifiedAt, 3, 'decision', $now);

        $this->assertGreaterThan(
            $withoutHits['decayed_confidence'],
            $withHits['decayed_confidence'],
            'recall_hits must slow decay (higher decayed_confidence with hits)',
        );
    }

    #[Test]
    public function floor_prevents_decay_from_becoming_silence(): void
    {
        // MAXH-05 piso composto: even at extreme age the row remains
        // recoverable — never a zero, never a hide.
        $now = CarbonImmutable::create(2026, 7, 1);
        $out = MemoryConfidenceDecayCalculator::compute(
            baseConfidence: 100.0,
            verifiedAt: $now->subYears(5),
            recallHits: 0,
            memoryType: 'decision',
            referenceNow: $now,
        );

        $this->assertTrue($out['floor_applied']);
        $this->assertGreaterThan(0.0, $out['decayed_confidence']);
        $this->assertSame(0.15 * 100.0, $out['decayed_confidence']);
        $this->assertTrue($out['needs_reverification']);
    }

    #[Test]
    public function missing_verified_at_flags_needs_reverification_without_fabricating_a_decay(): void
    {
        $out = MemoryConfidenceDecayCalculator::compute(
            baseConfidence: 80.0,
            verifiedAt: null,
            recallHits: 0,
            memoryType: 'decision',
            referenceNow: CarbonImmutable::now(),
        );

        $this->assertTrue($out['needs_reverification']);
        // No date ⇒ we do NOT invent decay: base is returned as-is (§1546).
        $this->assertSame(80.0, $out['decayed_confidence']);
        $this->assertSame(1.0, $out['decay_factor']);
        $this->assertFalse($out['floor_applied']);
    }

    #[Test]
    public function decay_is_deterministic_pure_function_of_inputs(): void
    {
        $now = CarbonImmutable::create(2026, 7, 1);
        $verifiedAt = $now->subDays(45);

        $a = MemoryConfidenceDecayCalculator::compute(100.0, $verifiedAt, 2, 'decision', $now);
        $b = MemoryConfidenceDecayCalculator::compute(100.0, $verifiedAt, 2, 'decision', $now);
        $this->assertSame($a, $b, 'same inputs must yield byte-identical outputs');
    }

    #[Test]
    public function unknown_memory_type_falls_back_to_default_half_life(): void
    {
        $now = CarbonImmutable::create(2026, 7, 1);
        $out = MemoryConfidenceDecayCalculator::compute(
            100.0,
            $now->subDays(90),
            0,
            'brand_new_type_not_in_map',
            $now,
        );
        // default half-life 90d ⇒ exactly one half-life ⇒ factor = 0.5
        $this->assertEqualsWithDelta(50.0, $out['decayed_confidence'], 1e-4);
        $this->assertSame(MemoryConfidenceDecayCalculator::DEFAULT_HALF_LIFE_DAYS, $out['half_life_days']);
    }
}
