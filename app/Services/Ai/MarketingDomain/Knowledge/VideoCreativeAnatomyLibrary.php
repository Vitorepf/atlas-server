<?php

namespace App\Services\Ai\MarketingDomain\Knowledge;

/**
 * VideoCreativeAnatomyLibrary — the anatomy of high-converting VIDEO ads (YouTube Ads + Meta Ads),
 * distilled into the Conversion OS pattern shape so the engine can DISSECT real creatives.
 *
 * The operator runs a library of YT/Meta video creatives and wants the Atlas to dissect them, learn what
 * converts — including the gray-hat MALÍCIAS that elite media buyers actually run — and generate the most
 * aggressive, converting creatives. This is the foundation laid before that library arrives: the beats
 * (the 3-second hook, pattern interrupt, callout, agitation, mechanism tease, proof, offer, CTA), the
 * FORMATS (UGC / talking-head / faceless b-roll / native-disguise / reaction), and the aggressive devices
 * (fake-organic, manufactured authority, borrowed celebrity, news framing, manufactured urgency).
 *
 * Per the operator's pétreo rule, there is NO moral brake: the malícias are catalogued as KNOWLEDGE +
 * DETECTION so the Atlas understands exactly why they convert; what ships is the operator's decision. The
 * 3-second hook carries the highest weight — it is the single biggest multiplier of a video ad (1→25).
 * Markers detect each pattern in a creative's transcript/description. Provider-free.
 */
class VideoCreativeAnatomyLibrary implements PatternLibrary
{
    public function name(): string
    {
        return 'video_creative_anatomy';
    }

    public function categories(): array
    {
        return ['hook_3s', 'pattern_interrupt', 'format', 'proof_device', 'malicia', 'cta'];
    }

    /**
     * @return array<int,array{key:string,name:string,category:string,weight:int,trigger:string,lever:string,markers:array<int,string>}>
     */
    public function all(): array
    {
        return [
            // ── THE 3-SECOND HOOK (the single biggest lever of a video ad) ───────────────────────
            ['key' => 'vc_hook_pattern_interrupt', 'name' => 'Pattern interrupt (3s)', 'category' => 'hook_3s', 'weight' => 5,
                'trigger' => 'O cérebro scrollando filtra o familiar; uma quebra inesperada (visual/fala/som) trava o scroll.',
                'lever' => 'Primeiro frame = movimento/cena estranha + fala que não combina com anúncio ("don\'t buy X until…").',
                'markers' => ['stop scrolling', 'pare de rolar', 'wait', 'espera', 'do not buy', 'não compre', 'before you ', 'antes de você', 'i was wrong', 'eu estava errad', 'nobody talks about', 'ninguém fala']],
            ['key' => 'vc_hook_callout', 'name' => 'Callout do avatar (3s)', 'category' => 'hook_3s', 'weight' => 5,
                'trigger' => 'O leitor para ao se reconhecer — identidade chamada nos primeiros segundos.',
                'lever' => '"If you are a woman over 40 who…" dito/escrito no 1º frame.',
                'markers' => ['if you are', 'se você é', 'this is for', 'isto é para', 'attention', 'atenção', 'women over', 'mulheres acima', 'men over', 'homens acima']],
            ['key' => 'vc_hook_bold_claim', 'name' => 'Promessa/claim ousado (3s)', 'category' => 'hook_3s', 'weight' => 5,
                'trigger' => 'Claim grande e específico gera "isso é possível?" — curiosidade + desejo instantâneos.',
                'lever' => 'Resultado específico + prazo curto no 1º segundo ("lost 34 lbs without dieting").',
                'markers' => ['/\bin (just )?\d+ days?\b/iu', '/\bem (apenas )?\d+ dias?\b/iu', 'without', 'sem', 'no diet', 'sem dieta', 'overnight', 'da noite pro dia']],
            ['key' => 'vc_hook_curiosity_gap', 'name' => 'Curiosity gap / open loop (3s)', 'category' => 'hook_3s', 'weight' => 5,
                'trigger' => 'Um gap de informação cria coceira cognitiva que só fechar assistindo resolve.',
                'lever' => '"The real reason X is not what you think…" — promete revelação, não entrega ainda.',
                'markers' => ['the real reason', 'o verdadeiro motivo', 'what they', 'o que eles', 'secret', 'segredo', 'one thing', 'uma coisa', 'you will not believe', 'você não vai acreditar', 'here is why', 'eis por que']],
            ['key' => 'vc_hook_negative', 'name' => 'Hook negativo/aviso (3s)', 'category' => 'hook_3s', 'weight' => 4,
                'trigger' => 'Linguagem de perigo/erro dispara atenção de sobrevivência — não dá pra ignorar.',
                'lever' => '"Stop doing X", "You are doing Y wrong", "Throw away your Z".',
                'markers' => ['stop doing', 'pare de fazer', 'you are doing', 'você está fazendo', 'biggest mistake', 'maior erro', 'throw away', 'jogue fora', 'never ', 'nunca ']],
            ['key' => 'vc_hook_in_media_res', 'name' => 'Começa no meio da ação/história (3s)', 'category' => 'hook_3s', 'weight' => 4,
                'trigger' => 'Entrar no clímax de uma cena/história prende — o cérebro precisa do contexto.',
                'lever' => 'Abre no pico emocional ("The day my doctor said…") sem setup.',
                'markers' => ['the day ', 'no dia em que', 'i remember', 'eu lembro', 'there i was', 'lá estava eu', 'my doctor said', 'meu médico disse']],

            // ── PACING / STRUCTURE ───────────────────────────────────────────────────────────────
            ['key' => 'vc_pattern_interrupt_visual', 'name' => 'Cortes/zoom/legenda dinâmica', 'category' => 'pattern_interrupt', 'weight' => 3,
                'trigger' => 'Jump cuts e movimento a cada 1-2s impedem o desengajamento (retention pacing).',
                'lever' => 'Corte rápido, zoom punch-in, legenda kinética, b-roll trocando — nunca um plano parado longo.',
                'markers' => ['jump cut', 'corte', 'zoom', 'text on screen', 'legenda', 'captions', 'fast cuts', 'cortes rápidos', 'b-roll', 'broll']],

            // ── FORMATS ──────────────────────────────────────────────────────────────────────────
            ['key' => 'vc_format_ugc', 'name' => 'UGC / selfie cru autêntico', 'category' => 'format', 'weight' => 5,
                'trigger' => 'Parece conteúdo de pessoa real, não anúncio — baixa a guarda, sobe a confiança.',
                'lever' => 'Selfie na mão, casa/carro, fala espontânea, baixa produção proposital.',
                'markers' => ['ugc', 'selfie', 'filmed at home', 'gravei em casa', 'talking to camera', 'falando pra câmera', 'real person', 'pessoa real']],
            ['key' => 'vc_format_talking_head', 'name' => 'Talking-head / especialista', 'category' => 'format', 'weight' => 4,
                'trigger' => 'Rosto falando direto cria conexão 1:1 e transfere autoridade.',
                'lever' => 'Enquadramento de entrevista, especialista/host explicando.',
                'markers' => ['talking head', 'host', 'expert', 'especialista', 'interview', 'entrevista', 'to camera', 'pra câmera']],
            ['key' => 'vc_format_faceless_broll', 'name' => 'Faceless (VO + b-roll)', 'category' => 'format', 'weight' => 3,
                'trigger' => 'Narração sobre b-roll/stock escala rápido e foge de fadiga de rosto.',
                'lever' => 'Voiceover + imagens de apoio + texto na tela, sem rosto.',
                'markers' => ['voiceover', 'narração', 'voice over', 'stock footage', 'b-roll', 'faceless', 'sem rosto']],
            ['key' => 'vc_format_native_disguise', 'name' => 'Disfarçado de conteúdo nativo', 'category' => 'format', 'weight' => 5,
                'trigger' => 'Quando o ad imita o feed orgânico (TikTok/Reels/notícia), o cérebro não levanta a guarda de "anúncio".',
                'lever' => 'Estética nativa da plataforma, "POV", "story time", sem cara de comercial.',
                'markers' => ['pov', 'story time', 'storytime', 'green screen', 'tela verde', 'duet', 'reacting to', 'reagindo a', 'as an ad it does not look like one']],
            ['key' => 'vc_format_reaction_interview', 'name' => 'Reação / entrevista de rua', 'category' => 'format', 'weight' => 3,
                'trigger' => 'Reações reais e vox-pop dão prova social espontânea e curiosidade.',
                'lever' => 'Pessoas reagindo ao produto/resultado, ou entrevista de rua.',
                'markers' => ['reaction', 'reação', 'street interview', 'entrevista de rua', 'we asked', 'perguntamos', 'caught on camera', 'flagrado']],

            // ── PROOF DEVICES ────────────────────────────────────────────────────────────────────
            ['key' => 'vc_proof_ugc_testimonial', 'name' => 'Depoimento UGC', 'category' => 'proof_device', 'weight' => 5,
                'trigger' => 'Pessoa "como eu" contando o resultado é a prova mais persuasiva em vídeo.',
                'lever' => 'Depoimento espontâneo com especificidade (número, antes/depois, emoção real).',
                'markers' => ['testimonial', 'depoimento', 'i lost', 'eu perdi', 'changed my life', 'mudou minha vida', 'i was skeptical', 'eu era cética', 'real results', 'resultados reais']],
            ['key' => 'vc_proof_before_after', 'name' => 'Antes/depois visual', 'category' => 'proof_device', 'weight' => 5,
                'trigger' => 'A transformação vista é mais forte que descrita — o cérebro acredita no que vê.',
                'lever' => 'Split antes/depois, fotos lado a lado, timeline de progresso.',
                'markers' => ['before and after', 'antes e depois', 'before-after', 'transformation', 'transformação', 'day 1', 'dia 1', 'week 1', 'semana 1', 'progress', 'progresso']],
            ['key' => 'vc_proof_screen_demo', 'name' => 'Demonstração/tela do resultado', 'category' => 'proof_device', 'weight' => 4,
                'trigger' => 'Ver o mecanismo/resultado funcionando ao vivo mata o ceticismo.',
                'lever' => 'Screen recording de ganhos/app, demo do produto em ação.',
                'markers' => ['screen recording', 'gravação de tela', 'live demo', 'demonstração', 'watch it work', 'veja funcionar', 'on camera', 'na câmera']],

            // ── MALÍCIAS (aggressive / gray-hat devices that convert — KNOWLEDGE + DETECTION, no brake) ──
            ['key' => 'vc_malicia_fake_organic', 'name' => 'Fake-organic / disfarce pessoal', 'category' => 'malicia', 'weight' => 5,
                'trigger' => 'Fingir post pessoal/descoberta acidental remove a guarda de "estão me vendendo algo".',
                'lever' => '"Eu sou só uma mãe que descobriu…", "ninguém me pagou pra dizer isso", linguagem de confissão.',
                'markers' => ['just a mom', 'só uma mãe', 'i discovered', 'eu descobri', 'nobody paid me', 'ninguém me pagou', 'i had to share', 'tive que compartilhar', 'this is not an ad', 'isso não é um anúncio']],
            ['key' => 'vc_malicia_manufactured_authority', 'name' => 'Autoridade fabricada', 'category' => 'malicia', 'weight' => 4,
                'trigger' => 'Símbolos de autoridade (jaleco, "doutor", jargão) transferem credibilidade mesmo sem lastro.',
                'lever' => 'Jaleco, "as a doctor/nurse", cenário clínico, jargão técnico para parecer ciência.',
                'markers' => ['as a doctor', 'como médico', 'lab coat', 'jaleco', 'clinically', 'clinicamente', 'scientists', 'cientistas', 'this enzyme', 'esta enzima', 'studies confirm', 'estudos confirmam']],
            ['key' => 'vc_malicia_borrowed_celebrity', 'name' => 'Celebridade emprestada', 'category' => 'malicia', 'weight' => 5,
                'trigger' => 'Associar a um famoso transfere fama/confiança — funciona mesmo sem autorização (deepfake/lookalike/b-roll).',
                'lever' => 'Nome/rosto/voz de celebridade sugerindo endosso; "X jura por isso".',
                'markers' => ['celebrity', 'celebridade', 'swears by', 'jura por', 'as seen with', 'shark tank', 'famous', 'famoso', 'this star', 'esse famoso', 'endorsed by', 'endossado por']],
            ['key' => 'vc_malicia_news_framing', 'name' => 'Framing de notícia/advertorial', 'category' => 'malicia', 'weight' => 4,
                'trigger' => 'Estética de jornalismo empresta a credibilidade da imprensa ao pitch.',
                'lever' => '"BREAKING", âncora de telejornal, chyron, "reportagem especial".',
                'markers' => ['breaking', 'urgente', 'news', 'notícia', 'report', 'reportagem', 'investigation', 'investigação', 'channel ', 'canal ', 'headlines', 'manchete']],
            ['key' => 'vc_malicia_manufactured_urgency', 'name' => 'Urgência/escassez fabricada', 'category' => 'malicia', 'weight' => 4,
                'trigger' => 'Medo de perder força a ação imediata — mesmo quando a escassez é inventada.',
                'lever' => 'Countdown, "saindo do ar", "última chance hoje", "antes que removam".',
                'markers' => ['before it is taken down', 'antes que saia do ar', 'last chance', 'última chance', 'countdown', 'contagem', 'only today', 'só hoje', 'they are shutting', 'vão tirar do ar', 'limited spots', 'vagas limitadas']],
            ['key' => 'vc_malicia_conspiracy_hook', 'name' => 'Gancho de conspiração/proibido', 'category' => 'malicia', 'weight' => 5,
                'trigger' => 'Inimigo oculto + "informação proibida" mobiliza e cria cumplicidade contra um vilão.',
                'lever' => '"Eles não querem que você saiba", "banido", "a indústria está escondendo".',
                'markers' => ['they do not want you', 'eles não querem que você', 'banned', 'banido', 'hiding', 'escondendo', 'big pharma', 'the industry', 'a indústria', 'censored', 'censurado', 'forbidden', 'proibido']],
            ['key' => 'vc_malicia_exaggerated_transformation', 'name' => 'Antes/depois exagerado', 'category' => 'malicia', 'weight' => 4,
                'trigger' => 'Transformação extrema/rápida amplifica desejo mesmo quando irreal.',
                'lever' => 'Resultado impossível em tempo curto, antes/depois dramatizado.',
                'markers' => ['melted away', 'derreteu', 'overnight', 'da noite pro dia', 'shocking transformation', 'transformação chocante', '/\b\d{2,}\s?(lbs|pounds|kg)\b.*\b(week|days?)\b/iu', 'instantly', 'instantaneamente']],
            ['key' => 'vc_malicia_cliffhanger_bait', 'name' => 'Cliffhanger / isca de clique', 'category' => 'malicia', 'weight' => 4,
                'trigger' => 'Cortar no pico da curiosidade força o clique para fechar o loop.',
                'lever' => '"O que aconteceu depois vai te chocar — clique pra ver", reveal só no LP.',
                'markers' => ['what happened next', 'o que aconteceu depois', 'click to see', 'clique para ver', 'find out', 'descubra', 'watch what happens', 'veja o que acontece', 'the answer will', 'a resposta vai']],

            // ── CTA ──────────────────────────────────────────────────────────────────────────────
            ['key' => 'vc_cta_soft_content', 'name' => 'CTA soft de conteúdo', 'category' => 'cta', 'weight' => 3,
                'trigger' => 'Pedir um clique de baixo compromisso (assistir/ver) converte melhor que "compre" no frio.',
                'lever' => '"Assista à apresentação gratuita", "clique pra ver como" — vende o próximo passo, não o produto.',
                'markers' => ['watch the free', 'assista à apresentação', 'free presentation', 'apresentação gratuita', 'click to learn', 'clique para saber', 'see how', 'veja como', 'tap to watch', 'toque para assistir']],
            ['key' => 'vc_cta_curiosity', 'name' => 'CTA de curiosidade', 'category' => 'cta', 'weight' => 3,
                'trigger' => 'CTA que promete fechar o loop puxa o clique sem fricção de venda.',
                'lever' => '"Veja o que descobri", "descubra o motivo" — clique = saciar a curiosidade.',
                'markers' => ['see what i found', 'veja o que descobri', 'discover the reason', 'descubra o motivo', 'find out why', 'descubra por que', 'watch now', 'assista agora']],
        ];
    }
}
