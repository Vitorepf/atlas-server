<?php

namespace App\Services\Ai\MarketingDomain\Knowledge;

/**
 * PersuasionPatternLibrary — the deterministic brain of direct-response psychology: the persuasion
 * patterns the world's top affiliates and copywriters actually use (Cialdini's influence weapons,
 * Schwartz awareness/sophistication, Hormozi's value equation, the aggressive VSL/advertorial
 * playbook). Each pattern carries the underlying human trigger, how elite copy deploys it, and the
 * text markers used to detect whether a page already pulls that lever. This is encoded craft
 * knowledge — used to MEASURE and STRENGTHEN persuasion, so Atlas understands *why* a page converts.
 */
class PersuasionPatternLibrary
{
    /**
     * @return array<int,array{key:string,name:string,category:string,weight:int,trigger:string,lever:string,markers:array<int,string>}>
     */
    public function all(): array
    {
        return [
            // ── Mechanism & reframe (the engine of "I've tried everything" markets) ──────────────
            ['key' => 'unique_mechanism', 'name' => 'Mecanismo único', 'category' => 'mechanism', 'weight' => 5,
                'trigger' => 'Uma causa-raiz nova e nomeada torna toda solução anterior "errada pelo motivo errado" — neutraliza o ceticismo do "já tentei tudo".',
                'lever' => 'Nomeie o mecanismo (ex: 3 hormônios / um protocolo) e posicione concorrentes como atacando só parte dele.',
                'markers' => ['mechanism', 'mecanismo', 'protocol', 'protocolo', 'hormone', 'hormôn', 'the only', 'a única', 'root cause', 'causa raiz', 'switch', 'ativa']],
            ['key' => 'reframe_not_your_fault', 'name' => 'Reframe: a culpa não é sua', 'category' => 'emotion', 'weight' => 5,
                'trigger' => 'Tira a vergonha/culpa do leitor e a transfere pra uma causa externa — gera alívio, gratidão e abertura.',
                'lever' => 'Diga explicitamente que não foi falta de esforço/força de vontade; foi biologia/sistema.',
                'markers' => ['not your fault', 'a culpa não', 'never your willpower', 'força de vontade', 'character flaw', 'it is biology', 'é biologia', 'wasn\'t you']],
            ['key' => 'common_enemy', 'name' => 'Inimigo comum (vilão)', 'category' => 'emotion', 'weight' => 4,
                'trigger' => 'Une o leitor contra um vilão (Big Pharma/indústria) — canaliza a raiva e cria identidade de grupo.',
                'lever' => 'Nomeie quem lucra com o problema e tem interesse em esconder a solução.',
                'markers' => ['big pharma', 'indústria', 'injection industry', 'billion', 'bilh', 'they don\'t want', 'não querem que', 'hide', 'esconde', 'bury', 'enterrar']],

            // ── Curiosity & attention (what earns the next sentence) ────────────────────────────
            ['key' => 'open_loop', 'name' => 'Loop aberto', 'category' => 'curiosity', 'weight' => 4,
                'trigger' => 'Uma pergunta/promessa não resolvida cria tensão cognitiva que só fecha continuando.',
                'lever' => 'Abra um loop ("o porquê está no vídeo / a seguir") e adie a resolução pro próximo passo.',
                'markers' => ['the reason why', 'o porquê', 'in the video', 'no vídeo', 'in a moment', 'a seguir', 'keep reading', 'continue', 'what happened next']],
            ['key' => 'forbidden_knowledge', 'name' => 'Conhecimento proibido', 'category' => 'curiosity', 'weight' => 4,
                'trigger' => '"O que não querem que você saiba" combina curiosidade + vilão + exclusividade.',
                'lever' => 'Enquadre a informação como vazada/censurada/escondida.',
                'markers' => ['leaked', 'vazad', 'banned', 'proibid', 'censored', 'censur', 'taken down', 'saiu do ar', 'they don\'t want you', 'before it\'s removed']],
            ['key' => 'specificity', 'name' => 'Especificidade crível', 'category' => 'curiosity', 'weight' => 3,
                'trigger' => 'Números exatos soam mais verdadeiros que arredondados e prendem atenção.',
                'lever' => 'Use números específicos e críveis (41 lbs, 9.400 reviews) — não redondos.',
                'markers' => ['/\b\d{1,3}\s*(lbs|pounds|kg|libras)\b/u', '/\b\d{1,3}(\.\d+)?\s*(weeks|months|semanas|meses)\b/u', '/\d{2,3},\d{3}/u']],
            ['key' => 'pattern_interrupt', 'name' => 'Quebra de padrão', 'category' => 'curiosity', 'weight' => 3,
                'trigger' => 'Algo inesperado para o scroll/zapping nos primeiros segundos.',
                'lever' => 'Abra com cena/afirmação contra-intuitiva ou chocante (sem ser claim médico falso).',
                'markers' => ['stop scrolling', 'pare', 'what if', 'e se', 'imagine', 'nobody told you', 'ninguém te contou', 'this may be the most']],

            // ── Cialdini influence weapons ──────────────────────────────────────────────────────
            ['key' => 'social_proof', 'name' => 'Prova social', 'category' => 'cialdini', 'weight' => 4,
                'trigger' => 'Pessoas seguem a ação de muitos parecidos com elas — reduz risco percebido.',
                'lever' => 'Depoimentos com nome/local + volume (150k+, 9.400 reviews) + "pessoas como você".',
                'markers' => ['reviews', 'verified buyer', 'people have', 'women have', 'pessoas', 'mulheres', 'testimonial', 'depoiment', '★', 'join', 'members']],
            ['key' => 'authority', 'name' => 'Autoridade', 'category' => 'cialdini', 'weight' => 4,
                'trigger' => 'Credenciais/figuras de autoridade emprestam credibilidade e atalham o ceticismo.',
                'lever' => 'Médico/instituição/mídia ("as seen on", Harvard, Dr.) ancorando a alegação.',
                'markers' => ['doctor', 'médic', 'dr.', 'harvard', 'stanford', 'as seen on', 'university', 'universidade', 'study', 'estudo', 'clinical', 'fda']],
            ['key' => 'scarcity', 'name' => 'Escassez/urgência', 'category' => 'cialdini', 'weight' => 4,
                'trigger' => 'Medo de perder (FOMO) é mais forte que o desejo de ganhar — força ação agora.',
                'lever' => 'Tempo limitado/estoque/contador/"sai do ar" — com motivo crível.',
                'markers' => ['only', 'apenas', 'limited', 'limitad', 'today', 'hoje', 'now', 'agora', 'before it', 'antes que', 'running out', 'last chance', 'countdown', 'left in stock', 'expires']],
            ['key' => 'commitment', 'name' => 'Compromisso & consistência', 'category' => 'cialdini', 'weight' => 2,
                'trigger' => 'Pequenos "sim" iniciais levam a sim maiores; o leitor age coerente com o que já admitiu.',
                'lever' => 'Faça micro-perguntas que ela responde "sim" mentalmente antes do pedido.',
                'markers' => ['if you', 'se você', 'have you ever', 'você já', 'does this sound', 'isso parece', 'you know the', 'you\'ve felt']],
            ['key' => 'liking', 'name' => 'Afinidade/identificação', 'category' => 'cialdini', 'weight' => 2,
                'trigger' => 'Compramos de quem é parecido e gostamos; a identificação derruba a guarda.',
                'lever' => 'Espelhe a dor/linguagem do avatar; fale "como você".',
                'markers' => ['like you', 'como você', 'i was', 'eu era', 'me too', 'i get it', 'eu entendo', 'we\'ve all', 'mulher como']],

            // ── Hormozi value equation (offer strength) ─────────────────────────────────────────
            ['key' => 'dream_outcome', 'name' => 'Resultado dos sonhos', 'category' => 'offer', 'weight' => 3,
                'trigger' => 'O desejo final vívido (se reconhecer no espelho, roupas antigas) move mais que features.',
                'lever' => 'Pinte o estado pós-transformação em cena concreta e emocional.',
                'markers' => ['mirror', 'espelho', 'fit into', 'roupas', 'clothes', 'confident', 'yourself again', 'você de novo', 'feel', 'sentir']],
            ['key' => 'proof_of_likelihood', 'name' => 'Prova de probabilidade', 'category' => 'offer', 'weight' => 4,
                'trigger' => 'Quanto mais crível que VAI funcionar pra ela, maior o valor percebido.',
                'lever' => 'Empilhe prova (depoimentos, estudo, mecanismo) de que o resultado é provável.',
                'markers' => ['results', 'resultado', 'proven', 'comprovad', 'study', 'estudo', 'average', 'média', 'guarantee', 'garantia', 'worked for']],
            ['key' => 'speed_low_effort', 'name' => 'Rápido & sem esforço', 'category' => 'offer', 'weight' => 3,
                'trigger' => 'Menor tempo + menor esforço = maior valor (denominador da equação de valor).',
                'lever' => '"Em segundos de manhã", "sem dieta/academia/agulha".',
                'markers' => ['seconds', 'segundos', 'no diet', 'sem dieta', 'no gym', 'sem academia', 'no needle', 'sem agulha', 'easy', 'fácil', 'morning', 'manhã', 'fast', 'rápido']],
            ['key' => 'risk_reversal', 'name' => 'Reversão de risco', 'category' => 'offer', 'weight' => 4,
                'trigger' => 'A garantia transfere o risco do comprador pro vendedor — remove a última objeção.',
                'lever' => 'Garantia forte, incondicional, com prazo ("60 dias, sem perguntas").',
                'markers' => ['guarantee', 'garantia', 'money-back', 'reembolso', 'refund', 'no questions', 'sem perguntas', 'risk-free', 'sem risco']],
            ['key' => 'value_stack', 'name' => 'Empilhamento de valor', 'category' => 'offer', 'weight' => 2,
                'trigger' => 'Ancorar valor alto e empilhar bônus faz o preço parecer pequeno em comparação.',
                'lever' => 'Liste bônus com valor ancorado; mostre o "de/por".',
                'markers' => ['bonus', 'bônus', 'free gift', 'brinde', 'value', 'valor', 'normally', 'normalmente', 'today only', '$', 'r$', 'stack']],

            // ── Structure (the slippery slide) ──────────────────────────────────────────────────
            ['key' => 'pas', 'name' => 'Problema-Agitação-Solução', 'category' => 'structure', 'weight' => 3,
                'trigger' => 'Agitar a dor antes da solução aumenta o alívio e a urgência percebidos.',
                'lever' => 'Problema → agita com cena visceral → revela a solução (mecanismo).',
                'markers' => ['the scale', 'a balança', 'every photo', 'cada foto', 'tired of', 'cansad', 'still', 'mesmo assim', 'and yet', 'no matter']],
            ['key' => 'objection_crush', 'name' => 'Quebra de objeção', 'category' => 'structure', 'weight' => 3,
                'trigger' => 'Nomear e destruir a objeção do cético ("é golpe?") aumenta a confiança.',
                'lever' => 'Traga a objeção à tona e responda com honestidade + prova + garantia.',
                'markers' => ['scam', 'golpe', 'too good to be true', 'bom demais', 'skeptic', 'cétic', 'is it safe', 'é seguro', 'but isn\'t', 'mas não é', 'faq']],
            ['key' => 'single_cta', 'name' => 'CTA único e repetido', 'category' => 'structure', 'weight' => 3,
                'trigger' => 'Uma só próxima ação, repetida, elimina a paralisia de decisão.',
                'lever' => 'Um único pedido (assistir/clicar), repetido em pontos quentes.',
                'markers' => ['watch', 'assista', 'click', 'clique', 'get', 'claim', 'garanta', 'see the', 'veja', 'try', 'experimente']],
            ['key' => 'future_pacing', 'name' => 'Projeção de futuro', 'category' => 'structure', 'weight' => 2,
                'trigger' => 'Fazer o leitor se ver no futuro pós-resultado aumenta o desejo e o compromisso.',
                'lever' => 'Descreva o dia/cena dela depois da transformação.',
                'markers' => ['imagine', 'imagine', 'picture yourself', 'you\'ll', 'você vai', 'in a few weeks', 'em poucas semanas', 'wake up', 'acordar']],
        ];
    }

    /**
     * @return array<int,string>
     */
    public function categories(): array
    {
        return ['mechanism', 'emotion', 'curiosity', 'cialdini', 'offer', 'structure'];
    }
}
