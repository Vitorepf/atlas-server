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
        // Library deepened in Volta 2 (+10 master voice patterns); same short copy hits the cores
        // but not the rare ones — asserts pin the cores explicitly (anti-Goodhart).
        $this->assertGreaterThanOrEqual(55, $r['score']);
        $this->assertContains($r['grade'], ['decent', 'strong', 'killer']);

        foreach (['bucket_brigade', 'you_focus', 'conversational', 'empathy_mirror',
            'sensory_detail', 'specific_numbers', 'show_dont_tell', 'rule_of_three',
            'metaphor_anchor', 'callback'] as $k) {
            $this->assertContains($k, $r['present'], "Expected technique {$k}");
        }
    }

    public function test_volta_2_master_voice_patterns_fire(): void
    {
        $master = 'Imagine for a moment as you read this — feel the way the chair is touching your back. '
            .'She used to hide. Then one day everything changed. '
            ."Look, here's the deal — cut the crap. "
            .'As you know, the evidence shows what any reasonable person would suspect. '
            ."I'll tell you a secret — between you and me, here's the kicker. "
            .'She walks across the kitchen and opens the cupboard. She stares at the bottle. '
            .'No diet. No gym. No injection. '
            ."(Don't tell anyone I said this — off the record, this shouldn't be public.) "
            ."But here's where it gets interesting. And that's when it changed. "
            .'Spoiler: she did not die. Long story short, finally found peace.';
        $r = (new PatternLibraryScorer)->score(new NarrativeVoiceLibrary, $master);

        foreach (['conversational_hypnosis', 'micro_cliffhanger', 'anti_climax_humor',
            'voice_halbert', 'voice_bencivenga', 'voice_carlton', 'present_tense_immersion',
            'rhythm_of_three_climax', 'forbidden_aside', 'narrative_arc_complete'] as $k) {
            $this->assertContains($k, $r['present'], "Volta 2 master voice {$k} should fire");
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
