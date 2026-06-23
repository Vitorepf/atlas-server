<?php

namespace App\Services\Ai\MarketingDomain\Knowledge;

/**
 * VisualPersuasionLibrary — the conversion that happens WITHOUT text. Visual hierarchy, F/Z-pattern,
 * eye-direction cues, color contrast on the CTA, friction reduction (form fields), trust signal
 * placement, thumb-zone on mobile, white-space pacing, image-text contract. These markers detect the
 * presence of the visual decision-architecture in the page HTML/structure (selectors, classes,
 * common patterns) — not just the visible copy. The visual layer alone can swing conversion 2-3×.
 */
class VisualPersuasionLibrary implements PatternLibrary
{
    public function name(): string
    {
        return 'visual_persuasion';
    }

    /**
     * @return array<int,array{key:string,name:string,category:string,weight:int,trigger:string,lever:string,markers:array<int,string>}>
     */
    public function all(): array
    {
        return [
            ['key' => 'cta_contrast', 'name' => 'CTA com cor de alto contraste', 'category' => 'hierarchy', 'weight' => 5,
                'trigger' => 'CTA na cor mais diferente da página é a coisa que mais sobe taxa de clique.',
                'lever' => 'Cor de CTA única na página (laranja/vermelho em paleta neutra), nada compete.',
                'markers' => ['class="cta', 'background:#e2510a', 'background:#e8400a', 'background:#ff', 'btn-primary', 'btn-cta', 'background:var(--cta', 'orange', 'cta-hero', 'cta-sticky']],
            ['key' => 'cta_repeated', 'name' => 'CTA repetido em pontos quentes', 'category' => 'hierarchy', 'weight' => 4,
                'trigger' => 'Vários CTAs evitam que o leitor procure quando decidir — está sempre à mão.',
                'lever' => 'CTA acima da dobra + após cada bloco importante + sticky no rodapé.',
                'markers' => ['cta-hero', 'cta-sticky', 'cta-bottom', 'stick', 'fixed', 'position:fixed', 'href="#vsl"', 'href="#order', 'data-goal="watch']],
            ['key' => 'sticky_cta', 'name' => 'CTA sticky/persistente', 'category' => 'hierarchy', 'weight' => 4,
                'trigger' => 'CTA sempre visível ao rolar elimina o atrito "onde clico?" no momento de decisão.',
                'lever' => 'Botão fixed bottom no mobile, sempre visível.',
                'markers' => ['position:fixed', 'position: fixed', 'sticky', 'cta-sticky', '.stick', 'bottom:12px', 'bottom:0', 'z-index']],
            ['key' => 'eye_direction_cue', 'name' => 'Direcionador de olhar', 'category' => 'attention', 'weight' => 3,
                'trigger' => 'Seta/curva/olhar de pessoa para o CTA aumenta clique mensuravelmente.',
                'lever' => 'Seta curva amarela apontando pro botão, rosto olhando pro CTA, dedo apontando.',
                'markers' => ['arrow', 'seta', '→', '⮕', '↓', 'path d="M', 'stroke-linecap="round"', 'fill="#ffd23f"', 'pointing']],
            ['key' => 'visual_hierarchy', 'name' => 'Hierarquia clara (H1/H2/dek)', 'category' => 'hierarchy', 'weight' => 4,
                'trigger' => 'O cérebro escaneia em F/Z; uma hierarquia visual clara o guia pelo argumento.',
                'lever' => 'H1 enorme, dek/subtítulo, H2 numerados, sem barulho.',
                'markers' => ['<h1', '<h2', 'class="kicker', 'class="dek', 'class="kick', 'font-size:30px', 'font-size:33px', 'font-size:40px', 'class="lead"']],
            ['key' => 'trust_bar', 'name' => 'Barra de confiança visível', 'category' => 'trust', 'weight' => 4,
                'trigger' => 'Selos/logos perto do CTA reduzem ansiedade no momento da decisão.',
                'lever' => 'Trust bar (garantia, "as seen on", número de clientes, selo de pagamento) sob o hero.',
                'markers' => ['class="trust', 'class="media', 'as seen on', 'as seen in', 'guarantee badge', 'verified buyer', 'reviews', 'badge', 'seal', 'icon-verified']],
            ['key' => 'social_proof_volume', 'name' => 'Prova social em volume visual', 'category' => 'trust', 'weight' => 4,
                'trigger' => 'Grade de comentários/depoimentos em volume vence depoimento único em escassez.',
                'lever' => 'Grade visual de reviews, contador grande (9.400+ reviews, 4.8★), foto de cliente.',
                'markers' => ['class="rev', 'class="tgrid', 'class="tcard', 'class="rc', '★★★★★', 'reviews', '+ reviews', 'class="hd"', 'verified buyer', 'figcaption']],
            ['key' => 'before_after_grid', 'name' => 'Grade de antes/depois', 'category' => 'trust', 'weight' => 4,
                'trigger' => 'Comparação visual lado-a-lado é a prova mais potente em saúde/estética.',
                'lever' => 'Cards de antes/depois com badge de resultado entre as fotos.',
                'markers' => ['class="ba', 'class="bagrid', 'class="bacard', 'class="ph', 'before', 'after', '.bares', 'transform']],
            ['key' => 'video_first', 'name' => 'Vídeo acima da dobra', 'category' => 'hierarchy', 'weight' => 5,
                'trigger' => 'Player de vídeo grande acima da dobra é o melhor "trigger" para o tempo na página.',
                'lever' => 'Player de aspecto 16:9 logo após a headline, antes de qualquer texto longo.',
                'markers' => ['class="vsl', 'aspect-ratio:16/9', '<iframe', 'class="vsl', 'id="vsl"', 'video', 'wistia', 'youtube', '<svg viewBox="0 0 680 383']],
            ['key' => 'thumbnail_compelling', 'name' => 'Thumbnail conversiva', 'category' => 'attention', 'weight' => 4,
                'trigger' => 'Thumbnail é o que faz o play acontecer — chamada explícita + play button + bordas vermelhas.',
                'lever' => 'Texto grande no centro, badge LEAKED/SPECIAL REPORT, botão play branco-vermelho.',
                'markers' => ['LEAKED', 'SPECIAL REPORT', 'taken down', 'before this story', 'play button', 'fill="#fff"', 'circle cx', 'path d="M', 'PLAY']],
            ['key' => 'urgency_visual', 'name' => 'Urgência visual (contador/barra)', 'category' => 'urgency', 'weight' => 4,
                'trigger' => 'Contador regressivo visível é o maior intensificador de urgência sem mexer no texto.',
                'lever' => 'Barra vermelha sticky no topo com countdown ao vivo.',
                'markers' => ['class="cdbar', 'countdown', 'id="cd"', '11:59', 'mm:ss', 'setInterval', 'background:var(--red', 'background:#b3261e', 'taken offline in']],
            ['key' => 'notification_stream', 'name' => 'Notificações flutuantes', 'category' => 'urgency', 'weight' => 3,
                'trigger' => 'Popups "Karen just ordered" criam prova social ao vivo + FOMO sutil.',
                'lever' => 'Toast no canto rotacionando nome/cidade/ação.',
                'markers' => ['class="toast', 'class="nbox', 'just requested', 'just ordered', 'just watched', 'started the protocol', 'id="toast"', 'id="tt"']],
            ['key' => 'mobile_thumb_zone', 'name' => 'Mobile thumb-zone', 'category' => 'mobile', 'weight' => 3,
                'trigger' => 'CTA na zona do polegar (terço inferior) tem clique 3-4× maior no mobile.',
                'lever' => 'Botão sticky bottom no mobile + viewport tag + font-size grande.',
                'markers' => ['viewport-fit=cover', 'viewport"', 'width=device-width', 'sticky', 'cta-sticky', '@media(max-width', 'min-width:560px']],
            ['key' => 'friction_reduction', 'name' => 'Redução de fricção', 'category' => 'flow', 'weight' => 4,
                'trigger' => 'Cada campo/clique a mais corta conversão. Menos perguntas, mais vendas.',
                'lever' => 'Nenhum formulário desnecessário, 1-click pro VSL/checkout.',
                'markers' => ['noindex', 'one-click', '1-click', 'no signup', 'sem cadastro', 'instant access', 'acesso imediato', 'href="#vsl"', 'autofocus']],
            ['key' => 'white_space_pacing', 'name' => 'Espaço em branco pra ritmo', 'category' => 'flow', 'weight' => 2,
                'trigger' => 'Texto compacto sufoca; espaço dá pausa que faz cada bloco respirar e ser lido.',
                'lever' => 'Margem generosa entre seções, line-height >1.5, max-width legível (680px).',
                'markers' => ['max-width:680px', 'max-width: 680px', 'line-height:1.6', 'line-height: 1.6', 'line-height:1.65', 'margin:26px', 'padding: 18px', 'gap: 12']],
        ];
    }

    /**
     * @return array<int,string>
     */
    public function categories(): array
    {
        return ['hierarchy', 'attention', 'trust', 'urgency', 'mobile', 'flow'];
    }
}
