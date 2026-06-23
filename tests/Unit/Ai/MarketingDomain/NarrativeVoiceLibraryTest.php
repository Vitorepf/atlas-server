<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\PatternLibraryScorer;
use App\Services\Ai\MarketingDomain\Knowledge\NarrativeVoiceLibrary;
use PHPUnit\Framework\TestCase;

/**
 * Locks the voice/rhythm layer: an elite voice (slippery-slide bucket brigades, you-focus, sensory
 * scene, specific timestamps, rule-of-three, callback, metaphor anchor) scores killer; an
 * institutional/flat copy scores near zero. This is what makes copy unputdownable.
 */
class NarrativeVoiceLibraryTest extends TestCase
{
    private string $elite = "Look, here is why nothing worked.\n\n"
        ."You tried everything. Diets. Walks. Even injections.\n"
        ."And the scale just sat there.\n\n"
        ."I know what you are thinking. Honestly, I have been there too.\n"
        ."It was 3:47 am when she stood in her kitchen and saw the reflection.\n"
        ."She cried. Then her husband said something that changed everything.\n\n"
        ."Here is the thing: it is like a backdoor in your metabolism. "
        ."A switch nobody told you about. A code that unlocks the system.\n\n"
        ."11,847 women. No diet, no gym, no needle.\n"
        ."Before, she was hiding. Now, she is in every photo.\n"
        ."Remember that mirror? It does not scare her anymore.";

    public function test_elite_voice_scores_killer_with_core_techniques(): void
    {
        $r = (new PatternLibraryScorer)->score(new NarrativeVoiceLibrary, $this->elite);

        $this->assertSame('narrative_voice', $r['library']);
        $this->assertGreaterThanOrEqual(85, $r['score']);
        $this->assertSame('killer', $r['grade']);

        foreach (['bucket_brigade', 'you_focus', 'conversational', 'empathy_mirror',
            'sensory_detail', 'specific_numbers', 'show_dont_tell', 'rule_of_three',
            'metaphor_anchor', 'callback'] as $k) {
            $this->assertContains($k, $r['present'], "Expected technique {$k}");
        }
    }

    public function test_institutional_copy_scores_near_zero(): void
    {
        $flat = 'Our product is a supplement for weight loss. It contains natural ingredients. '
            .'It is sold on our website. Many customers have purchased it. It has good reviews. '
            .'Please consider buying our supplement today.';
        $r = (new PatternLibraryScorer)->score(new NarrativeVoiceLibrary, $flat);

        $this->assertLessThan(20, $r['score']);
    }
}
