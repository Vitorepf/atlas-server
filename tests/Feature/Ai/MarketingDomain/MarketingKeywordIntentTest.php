<?php

namespace Tests\Feature\Ai\MarketingDomain;

use App\Models\AiMarketingWinningPattern;
use App\Services\Ai\MarketingDomain\Campaign\KeywordIntentMapper;
use App\Services\Ai\MarketingDomain\Campaign\NegativeListMiner;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesMarketingDomainTables;
use Tests\TestCase;

class MarketingKeywordIntentTest extends TestCase
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

    private function pattern(): AiMarketingWinningPattern
    {
        return AiMarketingWinningPattern::query()->create([
            'id' => (string) Str::uuid(),
            'niche' => 'weight_loss',
            'converting_keywords' => [
                ['term' => 'jello diet', 'conversions' => 1083],
                ['term' => 'pink gelatin recipe', 'conversions' => 29],
            ],
            'keyword_performance' => [
                'by_cvr' => [
                    ['term' => 'pink gelatin recipe', 'cvr' => 0.78, 'sales' => 29, 'clicks' => 37],
                    ['term' => 'jello diet', 'cvr' => 0.43, 'sales' => 1083, 'clicks' => 2510],
                    ['term' => 'free weight loss tips', 'cvr' => 0.005, 'sales' => 1, 'clicks' => 200],
                ],
            ],
        ]);
    }

    public function test_mapper_ranks_proven_sellers_first(): void
    {
        $pattern = $this->pattern();
        $clusters = [
            ['name' => 'cold_problem', 'awareness' => 'problem_aware', 'terms' => ['how to lose belly fat']],
            ['name' => 'proven', 'awareness' => 'solution_aware', 'terms' => ['jello diet', 'pink gelatin recipe']],
        ];

        $mapped = (new KeywordIntentMapper)->map($clusters, $pattern);

        // the cluster carrying proven sellers ranks first
        $this->assertSame('proven', $mapped[0]['name']);
        $this->assertGreaterThan(0, $mapped[0]['proven_hits']);
        $this->assertArrayHasKey('bridge_lead', $mapped[0]);
        $this->assertArrayHasKey('match_type_hint', $mapped[0]);
    }

    public function test_miner_flags_low_cvr_but_protects_niche_terms(): void
    {
        $m = (new NegativeListMiner)->mine($this->pattern());

        // the low-CVR spender becomes a candidate
        $terms = array_column($m['low_cvr_candidates'], 'term');
        $this->assertContains('free weight loss tips', $terms);

        // 'free' is a real negative; 'weight' is protected because it... is NOT in a converter here,
        // but the category list always carries 'free'
        $this->assertContains('free', $m['combined']);
        // a winning word never gets negated
        $this->assertNotContains('gelatin', $m['n_gram_negatives']);
    }

    public function test_miner_without_pattern_returns_category_only(): void
    {
        $m = (new NegativeListMiner)->mine(null);
        $this->assertSame('category_only', $m['source']);
        $this->assertContains('free', $m['combined']);
        $this->assertSame([], $m['low_cvr_candidates']);
    }
}
