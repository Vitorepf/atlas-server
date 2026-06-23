<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\PatternLibraryScorer;
use App\Services\Ai\MarketingDomain\Knowledge\AngleBigIdeaLibrary;
use App\Services\Ai\MarketingDomain\Knowledge\PersuasionPatternLibrary;
use PHPUnit\Framework\TestCase;

/**
 * Locks the highest-leverage library + the unified scorer: the angle archetypes are detected, the ONE
 * generic PatternLibraryScorer measures any library (Angle and Persuasion alike), and angles generalize
 * across unrelated niches (health / finance / relationship) — proving the construction sells regardless
 * of content. Deterministic.
 */
class AngleBigIdeaLibraryTest extends TestCase
{
    public function test_detects_angle_archetypes(): void
    {
        $copy = 'The real reason nothing worked is a hidden cause Big Pharma does not want you to know. '
            .'This leaked protocol was nearly banned. Everything you know about dieting is wrong — there is a new way. '
            .'I was at rock bottom until one day that is when everything changed.';
        $r = (new PatternLibraryScorer)->score(new AngleBigIdeaLibrary, $copy);

        $this->assertSame('angle_big_idea', $r['library']);
        // Library deepened in Volta 2 (+10 rare master angles); same short copy fires fewer % —
        // anti-Goodhart: the asserts below pin the angles that MATTER, not a fixed percentage.
        $this->assertGreaterThanOrEqual(25, $r['score']);
        $this->assertContains('hidden_cause', $r['present']);
        $this->assertContains('forbidden_discovery', $r['present']);
        $this->assertContains('transformation_story', $r['present']);
    }

    public function test_one_scorer_measures_any_library(): void
    {
        $scorer = new PatternLibraryScorer;
        $persuasion = $scorer->score(new PersuasionPatternLibrary, 'mechanism big pharma guarantee scarcity authority');
        $this->assertSame('persuasion', $persuasion['library']);
        $this->assertGreaterThan(0, $persuasion['score']);
        $this->assertArrayHasKey('mechanism', $persuasion['by_category']);
    }

    public function test_volta_2_rare_master_angles_fire(): void
    {
        $swipe = "I worked at Big Pharma for 14 years before I quit. Who really runs the FDA? Follow the money. "
            ."Do the math: \$1,200/month times 12 months times 10 years comes to over \$140,000 — without a cure. "
            ."According to CDC data, 67% of people who tried it failed. Within 5 years, the odds are you'll be in the same place. "
            ."This isn't a sales pitch — just information. My mother died of complications. Never again. I made a promise. "
            ."One year from now, you will look back at this moment. "
            ."I learned it from my grandmother — an ancient remedy passed down. "
            ."Yes, diet matters. But only 20%. The other 80% is this.";
        $r = (new PatternLibraryScorer)->score(new AngleBigIdeaLibrary, $swipe);

        foreach (['enemy_double_reveal', 'insider_defector', 'forbidden_math', 'statistical_revelation',
            'predictive_prophecy', 'trojan_horse', 'personal_grudge', 'future_history',
            'stolen_knowledge', 'contrarian_with_tilt'] as $k) {
            $this->assertContains($k, $r['present'], "Volta 2 rare angle {$k} should fire");
        }
    }

    public function test_angles_generalize_across_niches(): void
    {
        $scorer = new PatternLibraryScorer;
        $lib = new AngleBigIdeaLibrary;

        $finance = 'Everything you know about retirement is wrong. The wealthy use a secret loophole the banks hide. '
            .'A new way is coming before everyone — act before it is too late.';
        $relationship = 'The real reason he pulled away is not what you think. A forbidden truth the industry hides. '
            .'I was at rock bottom until one day everything changed.';

        $rf = $scorer->score($lib, $finance);
        $rr = $scorer->score($lib, $relationship);

        $this->assertContains('contrarian_truth', $rf['present']);   // finance
        $this->assertContains('shortcut_secret', $rf['present']);
        $this->assertContains('forbidden_discovery', $rr['present']); // relationship
        $this->assertGreaterThanOrEqual(3, count($rf['present']));
        $this->assertGreaterThanOrEqual(3, count($rr['present']));
    }
}
