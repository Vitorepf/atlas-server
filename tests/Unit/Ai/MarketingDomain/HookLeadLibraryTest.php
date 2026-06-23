<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\PatternLibraryScorer;
use App\Services\Ai\MarketingDomain\Knowledge\HookLeadLibrary;
use PHPUnit\Framework\TestCase;

/**
 * Locks the opening machine: a strong opening fires multiple hook formulas (callout, warning, stat
 * regex, contrarian, story) plus the elite lead archetypes (problem-solution, secret, story); a flat
 * "welcome to our website" scores near zero; the same construction generalizes from health to finance.
 */
class HookLeadLibraryTest extends TestCase
{
    private string $strong = 'If you are a woman over 40 who tried Ozempic — WARNING: stop before you waste another month. '
        .'Do you wonder why nothing works? 9 out of 10 women in this group failed. '
        .'Everything you know about weight loss is wrong. I was at rock bottom — that is when she discovered the secret '
        .'the wealthy never told us. Lose 41 lbs in 12 weeks. The real reason is finally revealed. '
        .'Proven by a study at Harvard.';

    public function test_strong_opening_fires_hooks_and_leads(): void
    {
        $r = (new PatternLibraryScorer)->score(new HookLeadLibrary, $this->strong);

        $this->assertSame('hook_lead', $r['library']);
        // Library deepened in Volta 2; short opening hits the core patterns but not the rare ones.
        $this->assertGreaterThanOrEqual(40, $r['score']);

        foreach (['hook_callout_specific', 'hook_warning', 'hook_question', 'hook_shocking_stat',
            'hook_contrarian', 'hook_story_open', 'lead_secret', 'lead_story', 'lead_problem_solution'] as $k) {
            $this->assertContains($k, $r['present'], "Expected pattern {$k}");
        }
    }

    public function test_volta_2_master_opening_patterns_fire(): void
    {
        $master = 'Dear friend, '
            .'Tuesday, 11:47pm. Karen, 47, in Phoenix opened the freezer and stared. '
            ."I'm about to admit something I never told anyone. "
            .'The endocrinologist who still eats pizza every day taught me something. '
            ."This is not for everyone — walk away if you're not over 40. "
            .'Are you tired of diets? Have you tried injections? Do you feel betrayed by your own body? '
            ."Nobody talks about it, but let's be honest: after 40, most women quit in silence. "
            .'The #1 ingredient sabotaging your metabolism is hiding in your breakfast. '
            .'Sincerely, your friend';
        $r = (new PatternLibraryScorer)->score(new HookLeadLibrary, $master);

        foreach (['hook_one_sentence_movie', 'hook_unfinished_confession', 'hook_strange_juxtaposition',
            'hook_named_avatar', 'lead_invitation_only', 'lead_letter_format', 'lead_diary_entry',
            'hook_uncomfortable_truth', 'lead_question_chain', 'hook_curiosity_gap_specific'] as $k) {
            $this->assertContains($k, $r['present'], "Volta 2 master pattern {$k} should fire");
        }
    }

    public function test_flat_welcome_page_scores_near_zero(): void
    {
        $r = (new PatternLibraryScorer)->score(new HookLeadLibrary, 'Welcome to our website. We sell supplements. Please buy.');
        $this->assertLessThan(15, $r['score']);
    }

    public function test_generalizes_to_finance(): void
    {
        $finance = 'If you are over 45 who tried every financial guru — WARNING: before you invest another dollar, read this. '
            .'Do you wonder why your money never grows? 70% of investors fail. Everything they teach is wrong. '
            .'I was at rock bottom — that is when I discovered the secret the wealthy use. Earn 10k/month in 90 days. '
            .'The real reason is published in Forbes.';
        $r = (new PatternLibraryScorer)->score(new HookLeadLibrary, $finance);

        $this->assertGreaterThanOrEqual(30, $r['score']);
        $this->assertContains('hook_callout_specific', $r['present']);
        $this->assertContains('hook_warning', $r['present']);
        $this->assertContains('lead_secret', $r['present']);
    }
}
