<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\DescriptorMatrixForge;
use PHPUnit\Framework\TestCase;

/**
 * Locks the #4 rule: the descriptor modifier raises CVR (orivelle 28 → fungus pen 33 → anti fungal pen 37).
 * Generates [coined]×[category]×[form]×[buy-intent], the champion pattern "coined category form" included,
 * never the bare name alone. Deterministic + dedup.
 */
class DescriptorMatrixForgeTest extends TestCase
{
    private DescriptorMatrixForge $f;

    protected function setUp(): void
    {
        $this->f = new DescriptorMatrixForge;
    }

    public function test_generates_the_champion_descriptor_pattern(): void
    {
        $m = $this->f->forge('orivelle', ['fungus', 'anti fungal'], ['pen']);
        $this->assertContains('orivelle anti fungal pen', $m, 'o padrão campeão de 37% CVR');
        $this->assertContains('orivelle fungus pen', $m);
        $this->assertContains('orivelle anti fungal pen review', $m, 'descritor × buy-intent');
    }

    public function test_never_emits_the_bare_name(): void
    {
        $m = $this->f->forge('orivelle', ['fungus'], ['pen']);
        $this->assertNotContains('orivelle', $m, 'o nome nu já vem do enumerator; aqui só descritor');
    }

    public function test_deterministic_and_empty_safe(): void
    {
        $this->assertSame($this->f->forge('orivelle', ['fungus'], ['pen']), $this->f->forge('orivelle', ['fungus'], ['pen']));
        $this->assertSame([], $this->f->forge('', ['fungus'], ['pen']));
    }
}
