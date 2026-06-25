<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\KeywordRegimeClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Locks the #6 scale lever: harvest (coined/possession/celebrity, ~3 clicks/sale) MUST be isolated from
 * seed (coined+information, recall) and probe (cold symptom) so B's 50-133 clicks/sale signal never poisons
 * A's Smart Bidding. Deterministic routing into the 3 isolated buckets.
 */
class KeywordRegimeClassifierTest extends TestCase
{
    private KeywordRegimeClassifier $c;

    protected function setUp(): void
    {
        $this->c = new KeywordRegimeClassifier;
    }

    private function row(string $kw, string $suffix, string $family = ''): array
    {
        return ['keyword' => $kw, 'score' => 80, 'suffix_regime' => $suffix, 'family' => $family];
    }

    public function test_coined_possession_is_harvest(): void
    {
        $this->assertSame('harvest', $this->c->classify($this->row('jello diet', 'possession', 'power_phrase'))['regime']);
        $this->assertSame('harvest', $this->c->classify($this->row('orivelle pen', 'possession'))['regime']);
        $this->assertSame('harvest', $this->c->classify($this->row('sanjay gupta brain supplement', 'possession', 'celebrity'))['regime']);
    }

    public function test_coined_information_suffix_is_seed(): void
    {
        $this->assertSame('seed', $this->c->classify($this->row('jello diet recipe', 'information', 'power_phrase'))['regime']);
        $this->assertSame('seed', $this->c->classify($this->row('gelatin trick', 'information', 'mechanism_trick'))['regime']);
    }

    public function test_cold_symptom_is_probe(): void
    {
        $this->assertSame('probe', $this->c->classify($this->row('ed treatment', 'neutral'))['regime']);
        $this->assertSame('probe', $this->c->classify($this->row('memory loss', 'neutral'))['regime']);
    }

    public function test_own_coined_name_bare_is_harvest_not_seed(): void
    {
        // BUG corrigido (achado na validação cross-nicho): o nome coined PRÓPRIO nu (recall puro, ~30% CVR)
        // caía em seed por ter suffix=neutral. Provenance: só promove o root do PRÓPRIO ativo.
        $roots = ['gelatin trick', 'pink gelatin'];
        $this->assertSame('harvest', $this->c->classify($this->row('gelatin trick', 'neutral', 'discovered_real'), $roots)['regime'], 'mecanismo próprio nu → colheita');
        $this->assertSame('harvest', $this->c->classify($this->row('gelatin trick pen', 'neutral', 'discovered_real'), $roots)['regime'], 'root como cabeça → colheita');
        $this->assertSame('seed', $this->c->classify($this->row('orivelle', 'neutral', 'discovered_real'), $roots)['regime'], 'marca ESTRANGEIRA não é promovida');
        // sem owned-roots (legado) o coined-neutro segue conservador → seed
        $this->assertSame('seed', $this->c->classify($this->row('gelatin trick', 'neutral', 'discovered_real'))['regime']);
    }

    public function test_partition_isolates_the_three_regimes(): void
    {
        $p = $this->c->partition([
            $this->row('jello diet', 'possession', 'power_phrase'),
            $this->row('jello diet recipe', 'information', 'power_phrase'),
            $this->row('ed treatment', 'neutral'),
        ]);
        $this->assertCount(1, $p['harvest']);
        $this->assertCount(1, $p['seed']);
        $this->assertCount(1, $p['probe']);
        $this->assertSame('A_refinder_harvest', $p['harvest'][0]['isolation']);
    }
}
