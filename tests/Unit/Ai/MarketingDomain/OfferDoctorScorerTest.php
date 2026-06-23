<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Decision\OfferDoctorScorer;
use PHPUnit\Framework\TestCase;

class OfferDoctorScorerTest extends TestCase
{
    private OfferDoctorScorer $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new OfferDoctorScorer;
    }

    public function test_empty_offer_scores_zero(): void
    {
        $audit = $this->svc->score(new AiMarketingVslAsset(['transcript' => '']));
        $this->assertFalse($audit['has_transcript']);
        $this->assertSame(0, $audit['offer_readiness']);
    }

    public function test_strong_offer_scores_high(): void
    {
        $asset = new AiMarketingVslAsset([
            'transcript' => strtolower(
                'Finally transform your body and restore your health — live longer, free of pain. '.
                'A clinical study and university research prove it, doctors and scientists agree, 60-day guarantee. '.
                'Get results fast, in just days, immediately — simple and easy, done for you, without strict diets or exercise.'
            ),
            'big_idea' => 'lose 30 pounds in 30 days',
            'core_promise' => 'reverse obesity',
            'value_equation' => ['dream_outcome' => 'slim body', 'time_delay' => 'days', 'effort_sacrifice' => 'none'],
            'claims' => ['clinically proven', 'doctor recommended', 'fda registered'],
        ]);

        $audit = $this->svc->score($asset);

        $this->assertGreaterThanOrEqual(80, $audit['offer_readiness']);
        $this->assertNotEmpty($audit['life_force_8_triggered']);
    }

    public function test_weakest_term_is_perceived_likelihood_when_proof_is_absent(): void
    {
        // Strong dream/immediacy/effort, but ZERO proof/claims → likelihood is the weak link.
        $asset = new AiMarketingVslAsset([
            'transcript' => strtolower('Finally transform your life fast, in just days, immediately — simple, easy, effortless, done for you.'),
            'big_idea' => 'transform in 7 days',
            'value_equation' => ['dream_outcome' => 'x', 'time_delay' => 'days', 'effort_sacrifice' => 'none'],
            'claims' => [],
        ]);

        $audit = $this->svc->score($asset);

        $this->assertSame('perceived_likelihood', $audit['weakest_term']);
        $this->assertSame('numerador', $audit['weakest_side']);
        $this->assertSame('guarantee + proof', $audit['grand_slam_fix']['lever']);
    }

    public function test_deterministic(): void
    {
        $asset = new AiMarketingVslAsset(['transcript' => 'a proven study, fast results, simple and easy']);
        $this->assertSame($this->svc->score($asset), $this->svc->score($asset));
    }
}
