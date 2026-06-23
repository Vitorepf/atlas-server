<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Decision\VslPersuasionAuditService;
use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;
use PHPUnit\Framework\TestCase;

class VslPersuasionAuditServiceTest extends TestCase
{
    private VslPersuasionAuditService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new VslPersuasionAuditService;
    }

    public function test_empty_vsl_scores_zero(): void
    {
        $asset = new AiMarketingVslAsset(['transcript' => '']);
        $audit = $this->svc->audit($asset);

        $this->assertFalse($audit['has_transcript']);
        $this->assertSame(0, $audit['score']);
    }

    public function test_complete_vsl_scores_high_with_few_fixes(): void
    {
        $transcript = strtolower(
            'What if I told you a shocking secret? You are tired of the struggle and the pain. '.
            "It's not your fault — the real reason is hidden. The only breakthrough method, our mechanism, ".
            'is backed by a clinical study from university research, proven results, a doctor and scientist endorse it. '.
            'Order the package today, a free bonus gift included. 60-day money-back guarantee, risk-free. '.
            'Limited supplies, hurry, this deadline expires. Click the button below to order now. '.
            'P.S. you might be thinking it is too good — thousands of customers and people just like you joined us together.'
        );

        $asset = new AiMarketingVslAsset([
            'transcript' => $transcript,
            'awareness_level' => 'problem_aware',
            'problem_mechanism' => 'hidden cause',
            'solution_mechanism' => 'unique protocol',
            'mechanism_name' => 'Method X',
            'offer' => ['price' => 49],
            'claims' => ['clinically proven'],
            'cta' => ['text' => 'order now'],
            'objection_rebuttals' => ['too good' => 'no'],
            'big_idea' => 'the hidden cause',
            'power_phrases' => ['shocking secret'],
            'persuasion' => ['authority' => true, 'social_proof' => true],
        ]);

        $audit = $this->svc->audit($asset);

        $this->assertTrue($audit['has_transcript']);
        $this->assertGreaterThanOrEqual(85, $audit['score']);
        $this->assertTrue($audit['awareness']['aligned']);
        // strong script → all 10 blocks present
        $this->assertSame('10/10', $audit['score_breakdown']['anatomy_blocks_present']);
    }

    public function test_weak_vsl_flags_missing_blocks_with_actions(): void
    {
        // No scarcity, no guarantee, no proof, no offer markers/fields.
        $asset = new AiMarketingVslAsset([
            'transcript' => strtolower('Imagine a better life. You are tired of the struggle. Click the button below.'),
            'awareness_level' => '',
            'problem_mechanism' => 'the cause',
        ]);

        $audit = $this->svc->audit($asset);

        $this->assertLessThan(70, $audit['score']);

        $targets = array_column($audit['fixes'], 'target');
        $this->assertContains('vsl_block:scarcity', $targets);
        $this->assertContains('vsl_block:guarantee', $targets);

        // the scarcity/guarantee fixes resolve to strengthen_close
        $byTarget = [];
        foreach ($audit['fixes'] as $f) {
            $byTarget[$f['target']] = $f['action'];
        }
        $this->assertSame(MarketingPlaybook::ACTION_STRENGTHEN_CLOSE, $byTarget['vsl_block:scarcity']);
        $this->assertSame(MarketingPlaybook::ACTION_STRENGTHEN_CLOSE, $byTarget['vsl_block:guarantee']);

        // awareness not declared → a fix nudging the lead
        $this->assertContains('awareness', $targets);
    }

    public function test_audit_is_deterministic(): void
    {
        $asset = new AiMarketingVslAsset(['transcript' => 'a study proven by a doctor, money-back guarantee, limited time, click now']);
        $this->assertSame($this->svc->audit($asset), $this->svc->audit($asset));
    }
}
