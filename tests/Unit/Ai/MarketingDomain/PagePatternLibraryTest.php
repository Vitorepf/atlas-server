<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Knowledge\PagePatternLibrary;
use PHPUnit\Framework\TestCase;

/**
 * Locks the audience-shaping skill: a weight-loss page for women is built differently from a prostate
 * page for men (gender inference + distinct playbooks), cold traffic gets more folds than hot, and the
 * brutal-mechanism arsenal is present. Deterministic.
 */
class PagePatternLibraryTest extends TestCase
{
    public function test_infers_female_for_a_weight_loss_womens_avatar(): void
    {
        $lib = new PagePatternLibrary;
        $p = $lib->audienceProfile(['gender' => 'female', 'description' => 'women over 40, menopause, PCOS'], 'weight_loss');

        $this->assertSame('female', $p['gender']);
        $this->assertStringContainsString('mulher', $p['playbook']['tone']);
    }

    public function test_infers_male_for_a_prostate_niche(): void
    {
        $lib = new PagePatternLibrary;
        $p = $lib->audienceProfile(['description' => 'men with prostate issues'], 'prostate');

        $this->assertSame('male', $p['gender']);
        $this->assertStringContainsString('direto', $p['playbook']['tone']);
    }

    public function test_male_and_female_playbooks_diverge(): void
    {
        $lib = new PagePatternLibrary;
        $female = $lib->bridgeBlueprint('female', 'solution_aware', '5');
        $male = $lib->bridgeBlueprint('male', 'solution_aware', '5');

        $this->assertNotSame($female['tone'], $male['tone']);
        $this->assertNotSame($female['mandatory_triggers'], $male['mandatory_triggers']);
    }

    public function test_cold_traffic_gets_more_folds_than_hot(): void
    {
        $lib = new PagePatternLibrary;
        $cold = $lib->bridgeBlueprint('female', 'solution_aware', '5', 'cold');
        $hot = $lib->bridgeBlueprint('female', 'most_aware', '5', 'hot');

        $this->assertGreaterThan($hot['fold_count'], $cold['fold_count']);
        $this->assertGreaterThanOrEqual($cold['fold_count'], count($cold['fold_structure']));
    }

    public function test_brutal_mechanism_arsenal_is_present(): void
    {
        $mechs = (new PagePatternLibrary)->brutalMechanisms();
        $names = array_column($mechs, 'name');

        $this->assertGreaterThanOrEqual(10, count($mechs));
        $this->assertContains('Open loop / curiosity gap', $names);
        $this->assertContains('Common enemy', $names);
        $this->assertContains('Unique mechanism', $names);
    }
}
