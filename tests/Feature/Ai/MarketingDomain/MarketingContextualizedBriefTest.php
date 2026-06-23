<?php

namespace Tests\Feature\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Models\AiMarketingWinningPattern;
use App\Services\Ai\MarketingDomain\Decision\BriefGroundingHelper;
use App\Services\Ai\MarketingDomain\Decision\ContextualizedBriefService;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesMarketingDomainTables;
use Tests\TestCase;

class MarketingContextualizedBriefTest extends TestCase
{
    use CreatesMarketingDomainTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMarketingDomainTables();
    }

    protected function tearDown(): void
    {
        $this->dropMarketingDomainTables();
        parent::tearDown();
    }

    public function test_grounding_helper_injects_nivor_angles(): void
    {
        $pattern = AiMarketingWinningPattern::query()->create([
            'id' => (string) Str::uuid(),
            'niche' => 'weight_loss',
            'real_cvr' => 0.0209,
            'converting_keywords' => [['term' => 'jello diet']],
            'winner_commonalities' => ['dominant_device' => 'mobile', 'keyword_angle' => 'recipe+trick'],
        ]);

        $g = (new BriefGroundingHelper)->groundBriefFromPattern($pattern, 'copy');
        $this->assertTrue($g['grounded']);
        $this->assertSame('mobile', $g['device_priority']);
        $this->assertSame('recipe+trick', $g['keyword_angle']);
    }

    public function test_contextual_binds_action_to_playbook_and_vsl_context(): void
    {
        $vsl = new AiMarketingVslAsset([
            'awareness_level' => 'problem_aware',
            'big_idea' => 'the pink trick',
            'mechanism_name' => 'Pink Protocol',
            'power_phrases' => ['the pink trick', 'metabolism reset'],
            'ad_assets' => ['headlines' => ['Lose Weight Fast', 'The Pink Trick']],
        ]);
        $diagnosis = ['primary' => ['action' => 'edit_hook', 'lever' => 'vsl-architect', 'vsl_block' => 'hook', 'numeric_rule' => 'hook 5s']];

        $svc = app(ContextualizedBriefService::class);
        $c = $svc->contextual($diagnosis, $vsl, null);

        $this->assertSame('edit_hook', $c['action']);
        $this->assertSame('vsl-architect', $c['playbook']['skill']);
        $this->assertContains('the pink trick', $c['vsl_context']['hook_snippets']);
        $this->assertContains('Lose Weight Fast', $c['vsl_context']['ad_headlines']);
    }

    public function test_hold_action_has_no_playbook(): void
    {
        $svc = app(ContextualizedBriefService::class);
        $c = $svc->contextual(['primary' => ['action' => 'hold']], new AiMarketingVslAsset, null);
        $this->assertNull($c['playbook']);
    }
}
