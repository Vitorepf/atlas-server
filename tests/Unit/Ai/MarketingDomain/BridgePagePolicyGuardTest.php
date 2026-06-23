<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\BridgePagePolicyGuard;
use PHPUnit\Framework\TestCase;

/**
 * Locks the uptime=performance contract: things that get an account suspended are hard BLOCKS
 * (fail-closed), aggressive-but-hedged copy only WARNS, and a strong original-content advertorial
 * that routes one goal to the VSL is OK. Deterministic; no LLM.
 */
class BridgePagePolicyGuardTest extends TestCase
{
    private function goodBridge(): array
    {
        $para = 'Se você é uma mulher acima dos quarenta e já tentou de tudo para emagrecer, ' .
            'provavelmente o problema nunca foi força de vontade. Pesquisas recentes sugerem que ' .
            'três hormônios controlam a queima de gordura e que muitas relataram resultados quando ' .
            'eles voltam a funcionar. Os resultados variam de pessoa para pessoa. ';

        return [
            'headline' => 'O que mulheres acima de 40 estão descobrindo sobre o hormônio da queima de gordura',
            'subheadline' => 'Um mecanismo natural que muitas dizem ter mudado tudo.',
            'hero_cta' => ['label' => 'Assistir à apresentação gratuita', 'target' => '#vsl'],
            'trust_bar' => ['Mais de 150 mil pessoas', '4,8/5 em avaliações'],
            'lead_paragraph' => str_repeat($para, 3),
            'mechanism_tease' => str_repeat($para, 2),
            'body_sections' => [
                ['heading' => 'Razão 1', 'body' => str_repeat($para, 2), 'open_loop' => 'O detalhe está na apresentação.'],
                ['heading' => 'Razão 2', 'body' => str_repeat($para, 2), 'open_loop' => 'Ela explica no vídeo.'],
                ['heading' => 'Razão 3', 'body' => str_repeat($para, 2), 'open_loop' => 'Veja por quê na apresentação.'],
            ],
            'cta_blocks' => [
                ['label' => 'Assistir agora', 'target' => '#vsl'],
                ['label' => 'Ver o vídeo', 'target' => '#vsl'],
            ],
            'disclosure' => 'Conteúdo publicitário. Resultados variam.',
        ];
    }

    public function test_strong_original_content_routed_to_vsl_is_ok(): void
    {
        $verdict = (new BridgePagePolicyGuard)->evaluate($this->goodBridge());

        $this->assertSame('ok', $verdict['verdict']);
        $this->assertTrue($verdict['safe_to_publish']);
        $this->assertTrue($verdict['metrics']['cta_points_to_video']);
        $this->assertSame(1, $verdict['metrics']['cta_distinct_goals']);
    }

    public function test_thin_content_is_blocked(): void
    {
        $bridge = $this->goodBridge();
        $bridge['lead_paragraph'] = 'Clique abaixo.';
        $bridge['mechanism_tease'] = '';
        $bridge['body_sections'] = [['heading' => 'Veja', 'body' => 'Assista.', 'open_loop' => '']];

        $verdict = (new BridgePagePolicyGuard)->evaluate($bridge);

        $this->assertSame('block', $verdict['verdict']);
        $this->assertFalse($verdict['safe_to_publish']);
        $this->assertContains('original_content', array_column($verdict['blocks'], 'rule'));
    }

    public function test_raw_affiliate_link_is_blocked(): void
    {
        $bridge = $this->goodBridge();
        $bridge['cta_blocks'][] = ['label' => 'Comprar', 'target' => 'https://x.hop.clickbank.net/?aff=me'];

        $verdict = (new BridgePagePolicyGuard)->evaluate($bridge);

        $this->assertFalse($verdict['safe_to_publish']);
        $this->assertContains('no_raw_affiliate_link', array_column($verdict['blocks'], 'rule'));
    }

    public function test_competing_conversion_goals_block(): void
    {
        $bridge = $this->goodBridge();
        $bridge['cta_blocks'] = [
            ['label' => 'Assistir ao vídeo', 'target' => '#vsl'],
            ['label' => 'Comprar agora no checkout', 'target' => 'buy'],
        ];

        $verdict = (new BridgePagePolicyGuard)->evaluate($bridge);

        $this->assertFalse($verdict['safe_to_publish']);
        $this->assertContains('single_goal', array_column($verdict['blocks'], 'rule'));
    }

    public function test_absolute_claim_without_hedge_only_warns(): void
    {
        $bridge = $this->goodBridge();
        // remove hedges, inject an absolute claim — aggressive but a durability risk, not a ban.
        $bridge['headline'] = 'O segredo caseiro que derrete gordura';
        $bridge['subheadline'] = 'O corpo vira uma máquina de queimar gordura.';
        $clean = 'Este protocolo caseiro derrete gordura enquanto você dorme e transforma o corpo numa nova silhueta diariamente. ';
        $bridge['lead_paragraph'] = str_repeat($clean, 12).'cura definitiva para todos.';
        $bridge['mechanism_tease'] = str_repeat($clean, 8);
        $bridge['body_sections'] = [
            ['heading' => 'A', 'body' => str_repeat($clean, 6), 'open_loop' => 'no video'],
            ['heading' => 'B', 'body' => str_repeat($clean, 6), 'open_loop' => 'no video'],
            ['heading' => 'C', 'body' => str_repeat($clean, 6), 'open_loop' => 'no video'],
        ];
        $bridge['disclosure'] = '';

        $verdict = (new BridgePagePolicyGuard)->evaluate($bridge);

        $this->assertSame('warn', $verdict['verdict']);
        $this->assertTrue($verdict['safe_to_publish']); // warns inform, they do not refuse
        $this->assertContains('claim_substantiation', array_column($verdict['warnings'], 'rule'));
    }
}
