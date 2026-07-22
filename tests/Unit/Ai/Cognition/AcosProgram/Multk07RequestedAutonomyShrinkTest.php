<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\AtlasDecide\RequestedAutonomyDerivation;
use App\Services\Ai\Policy\PolicyCanon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MULTK-07 — pure `requested_autonomy` shrink function.
 *
 * Acceptance from frontier plan §2401-2405:
 *   - property test: for every evidence, `derived ≤ 'autonomous'` (ladder order)
 *   - route with high reversal ⇒ pedido rebaixado (fixture)
 *   - pedido rebaixado NUNCA vira allow onde antes era deny — só o inverso
 *   - função só-para-baixo a partir de `autonomous`
 */
final class Multk07RequestedAutonomyShrinkTest extends TestCase
{
    #[Test]
    public function formula_version_is_pinned(): void
    {
        $this->assertSame(
            'atlas.multk_07.requested_autonomy_shrink.v1',
            RequestedAutonomyDerivation::FORMULA_VERSION,
        );
    }

    #[Test]
    public function normal_privacy_with_clean_reversal_stays_at_autonomous(): void
    {
        $out = RequestedAutonomyDerivation::derive([
            'privacy_class' => 'normal',
            'reversal_rate' => 0.02,
            'n' => 50,
        ]);
        $this->assertSame(PolicyCanon::AUTONOMY_AUTONOMOUS, $out['requested_autonomy']);
        $this->assertSame([], $out['reasons']);
    }

    #[Test]
    public function sensitive_privacy_class_always_tightens_from_autonomous(): void
    {
        $out = RequestedAutonomyDerivation::derive([
            'privacy_class' => 'sensitive',
            'reversal_rate' => 0.0,
            'n' => 1000,
        ]);
        $this->assertSame(PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL, $out['requested_autonomy']);
        $this->assertContains('privacy_class_sensitive', $out['reasons']);
    }

    #[Test]
    public function high_reversal_rate_shrinks_to_draft(): void
    {
        $out = RequestedAutonomyDerivation::derive([
            'privacy_class' => 'normal',
            'reversal_rate' => 0.45, // ≥ 0.30 high
            'n' => 20,
        ]);
        $this->assertSame(PolicyCanon::AUTONOMY_DRAFT, $out['requested_autonomy']);
        $this->assertContains('reversal_rate_high', $out['reasons']);
    }

    #[Test]
    public function moderate_reversal_rate_shrinks_to_execute_with_approval(): void
    {
        $out = RequestedAutonomyDerivation::derive([
            'privacy_class' => 'normal',
            'reversal_rate' => 0.15, // ≥ 0.10 moderate
            'n' => 40,
        ]);
        $this->assertSame(PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL, $out['requested_autonomy']);
    }

    #[Test]
    public function insufficient_n_tightens_regardless_of_the_observed_rate(): void
    {
        // Rate=0 looks perfect — but n=2 is noise, not evidence.
        $out = RequestedAutonomyDerivation::derive([
            'privacy_class' => 'normal',
            'reversal_rate' => 0.0,
            'n' => 2,
        ]);
        $this->assertSame(PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL, $out['requested_autonomy']);
        $this->assertContains('reversal_sample_too_small_n_2', $out['reasons']);
    }

    #[Test]
    public function ceiling_is_respected_never_raised_above_the_caller_request(): void
    {
        // Caller already asked for a tighter ceiling → the machine may
        // tighten further but MUST NEVER loosen above it.
        $out = RequestedAutonomyDerivation::derive([
            'privacy_class' => 'normal',
            'reversal_rate' => 0.0,
            'n' => 100,
            'ceiling' => PolicyCanon::AUTONOMY_DRAFT,
        ]);
        $this->assertSame(PolicyCanon::AUTONOMY_DRAFT, $out['requested_autonomy']);
        // High reversal rate on a caller-tightened ceiling should not
        // silently raise: derivation is only-downward.
        $out2 = RequestedAutonomyDerivation::derive([
            'privacy_class' => 'normal',
            'reversal_rate' => 0.5,
            'n' => 50,
            'ceiling' => PolicyCanon::AUTONOMY_DRAFT,
        ]);
        $this->assertSame(PolicyCanon::AUTONOMY_DRAFT, $out2['requested_autonomy']);
    }

    /**
     * @return array<string,array{0:array<string,mixed>}>
     */
    public static function propertyFixtures(): array
    {
        return [
            'normal + clean' => [['privacy_class' => 'normal', 'reversal_rate' => 0.0, 'n' => 100]],
            'normal + moderate' => [['privacy_class' => 'normal', 'reversal_rate' => 0.2, 'n' => 30]],
            'normal + high' => [['privacy_class' => 'normal', 'reversal_rate' => 0.9, 'n' => 50]],
            'sensitive + clean' => [['privacy_class' => 'sensitive', 'reversal_rate' => 0.0, 'n' => 200]],
            'secret + high' => [['privacy_class' => 'secret', 'reversal_rate' => 0.7, 'n' => 40]],
            'normal + small n' => [['privacy_class' => 'normal', 'reversal_rate' => 0.0, 'n' => 1]],
            'no signals' => [['privacy_class' => 'normal']],
            'garbage rate' => [['privacy_class' => 'normal', 'reversal_rate' => 'wat', 'n' => 100]],
        ];
    }

    #[Test]
    #[DataProvider('propertyFixtures')]
    public function property_derived_is_always_leq_autonomous(array $evidence): void
    {
        $out = RequestedAutonomyDerivation::derive($evidence);
        $this->assertTrue(
            RequestedAutonomyDerivation::isMonotonicallyDownward(
                $out['requested_autonomy'],
                PolicyCanon::AUTONOMY_AUTONOMOUS,
            ),
            'derived requested_autonomy must be ≤ autonomous for every input',
        );
    }
}
