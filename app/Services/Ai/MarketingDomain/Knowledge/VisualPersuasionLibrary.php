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

            // ── Aprofundamento Volta 2: design persuasivo raro ─────────────────────────────────
            ['key' => 'highlight_yellow', 'name' => 'Grifo amarelo (highlight de venda direta)', 'category' => 'attention', 'weight' => 4,
                'trigger' => 'Grifo amarelo (Halbert) na frase de maior impacto faz o leitor que SKIM parar e ler.',
                'lever' => '<mark> ou background:yellow na 1-3 frases-chave; nada além disso destacado.',
                'markers' => ['<mark', 'background:yellow', 'background: yellow', 'background:#ff', 'class="highlight', 'class="hl', 'highlight: yellow']],
            ['key' => 'cta_directional_arrow', 'name' => 'Seta direcionando o CTA', 'category' => 'attention', 'weight' => 3,
                'trigger' => 'Seta amarela curva apontando pro botão é o eye-direction cue mais testado e provado.',
                'lever' => 'SVG path curvo de seta + cor de contraste, terminando 10px antes do CTA.',
                'markers' => ['arrow.png', 'class="arrow', 'transform:rotate', 'stroke="#ffd', 'stroke="yellow', 'path d="M150 250 C', 'fill="#ffd', 'transform="rotate']],
            ['key' => 'fixed_player', 'name' => 'Player de vídeo fixado ao rolar', 'category' => 'hierarchy', 'weight' => 4,
                'trigger' => 'Vídeo que vira mini-player fixado ao rolar mantém VTR alta — leitor não para o vídeo pra ler.',
                'lever' => 'JS que detecta scroll e fixa o player no canto + botão de minimizar.',
                'markers' => ['position:fixed', 'sticky-video', 'mini-player', 'wistia-sticky', 'video-fixed', 'pip-mode', 'picture-in-picture', 'position: sticky']],
            ['key' => 'progressive_disclosure', 'name' => 'Revelação progressiva', 'category' => 'flow', 'weight' => 3,
                'trigger' => 'Oferta/preço revelado só após X minutos/scroll mantém leitor lendo — anti-fadiga de venda.',
                'lever' => 'JS que mostra oferta apenas após o leitor passar do ponto-chave da VSL/copy.',
                'markers' => ['display:none', 'reveal-at', 'show-after', 'unlock-on-scroll', 'opacity:0;', 'visibility:hidden', 'data-reveal']],
            ['key' => 'image_text_contract', 'name' => 'Contrato imagem-texto', 'category' => 'trust', 'weight' => 3,
                'trigger' => 'Imagem deve PROVAR o texto. Quebra de contrato (foto genérica abaixo de claim específico) destrói confiança.',
                'lever' => 'Cada foto/visual tem caption que amarra ao claim; nada de stock genérico sem contexto.',
                'markers' => ['<figcaption', 'class="caption', 'alt="', 'data-claim', 'image-proof', 'photo evidence', 'screenshot-of', 'caption']],
            ['key' => 'comparison_table', 'name' => 'Tabela de comparação', 'category' => 'trust', 'weight' => 4,
                'trigger' => 'Tabela com check verde vs X vermelho contra concorrentes vence argumento textual em prontidão de compra.',
                'lever' => 'Tabela: linhas = features, colunas = você vs Ozempic vs nada. Check vs X. Sua coluna toda check.',
                'markers' => ['<table', 'comparison-table', 'class="compare', '✔', '✓', '✗', '❌', 'vs-table', 'vs ozempic', 'vs the competition', '✅']],
            ['key' => 'risk_reversal_seal', 'name' => 'Selo visual de garantia', 'category' => 'trust', 'weight' => 4,
                'trigger' => 'Selo circular tipo "60-day money-back" perto do CTA reduz ansiedade no clique.',
                'lever' => 'SVG/imagem circular vermelha+amarela, "100% GUARANTEE" — sempre perto do botão.',
                'markers' => ['guarantee-seal', 'selo-garantia', 'class="seal', '100% money', '60-day guarantee', 'badge-guarantee', 'class="badge', 'border-radius:50%']],
            ['key' => 'video_play_button_oversized', 'name' => 'Play button gigante (chamativo)', 'category' => 'attention', 'weight' => 3,
                'trigger' => 'Play button grande+pulsando+animado supera o "automático" do player — taxa de click no play 2-3×.',
                'lever' => 'Círculo branco/vermelho gigante centralizado + animação pulse + texto "WATCH NOW".',
                'markers' => ['play-button', 'class="play', 'animation:pulse', 'pulse-animation', '@keyframes pulse', 'cursor:pointer', 'class="player-overlay']],
            ['key' => 'sticky_offer_bar', 'name' => 'Barra de oferta sticky', 'category' => 'urgency', 'weight' => 3,
                'trigger' => 'Barra horizontal sticky com "$97 hoje + 60 dias garantia" sempre visível mantém oferta no topo da mente.',
                'lever' => 'Header sticky com preço + CTA + garantia em uma linha.',
                'markers' => ['offer-bar', 'class="offerbar', 'class="topbar-offer', 'position:sticky;top:0', 'sticky-offer', 'class="hellobar']],
            ['key' => 'mobile_first_typography', 'name' => 'Tipografia mobile-first', 'category' => 'mobile', 'weight' => 3,
                'trigger' => 'Font-size grande (17-19px base) + line-height generoso (1.6+) no mobile é o que faz copy longa ser lida.',
                'lever' => 'Base 17-19px, line-height ≥1.6, h1 30-38px no mobile.',
                'markers' => ['font-size:17px', 'font-size:18px', 'font-size:19px', 'font-size: 17px', 'font-size: 18px', 'font-size: 19px', 'line-height:1.6', 'line-height:1.65', 'line-height:1.7']],
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
