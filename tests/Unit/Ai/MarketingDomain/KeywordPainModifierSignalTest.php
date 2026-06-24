<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\KeywordPainModifierSignal;
use PHPUnit\Framework\TestCase;

/**
 * Locks the #7 rule: a pain/clinical/explicit-goal qualifier lifts CVR even on an information suffix.
 * bariatric gelatin recipe 14.37% = 7× pink gelatin recipe ~2%. The qualifier reveals a committed,
 * specific relationship to the problem → far higher intent. Deterministic.
 */
class KeywordPainModifierSignalTest extends TestCase
{
    private KeywordPainModifierSignal $s;

    protected function setUp(): void
    {
        $this->s = new KeywordPainModifierSignal;
    }

    public function test_clinical_and_goal_qualifiers_lift(): void
    {
        $this->assertGreaterThan(1.0, $this->s->assess('bariatric gelatin recipe')['multiplier']);
        $this->assertGreaterThan(1.0, $this->s->assess('gelatin for weight loss')['multiplier']);
        $this->assertContains('bariatric', $this->s->assess('bariatric gelatin trick')['matched']);
    }

    public function test_generic_term_is_not_lifted(): void
    {
        $this->assertSame(1.0, $this->s->assess('pink gelatin recipe')['multiplier']);
        $this->assertFalse($this->s->assess('gelatin trick')['has_pain_modifier']);
    }

    public function test_no_false_positive_on_substring(): void
    {
        // "severance" contém "sever" mas não "severe" como palavra; "clinically" só com 'clinically proven'
        $this->assertFalse($this->s->assess('severance package')['has_pain_modifier']);
    }
}
