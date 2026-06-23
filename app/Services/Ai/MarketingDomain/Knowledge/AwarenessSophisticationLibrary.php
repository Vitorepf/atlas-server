<?php

namespace App\Services\Ai\MarketingDomain\Knowledge;

/**
 * AwarenessSophisticationLibrary — the routing meta-layer (Eugene Schwartz). It catalogs the 5 levels
 * of prospect awareness (unaware → most-aware) and the 5 stages of market sophistication (be-first →
 * identity), each with the markers that reveal which level a piece of copy is written FOR. This is the
 * layer that decides WHICH construction to use — the right angle/lead at the wrong awareness level
 * converts at zero, so getting this right multiplies every other library. Pairs with AwarenessRouter,
 * which turns a (level, stage) into a concrete prescription.
 */
class AwarenessSophisticationLibrary implements PatternLibrary
{
    public function name(): string
    {
        return 'awareness_sophistication';
    }

    /**
     * @return array<int,array{key:string,name:string,category:string,weight:int,trigger:string,lever:string,markers:array<int,string>}>
     */
    public function all(): array
    {
        return [
            // ── 5 levels of awareness (what the prospect already knows) ──────────────────────────
            ['key' => 'unaware', 'name' => 'Inconsciente', 'category' => 'awareness', 'weight' => 3,
                'trigger' => 'Não sabe que tem o problema — só sente um sintoma vago. Vender produto aqui espanta.',
                'lever' => 'Lead de história/identificação ou curiosidade pura; nunca nomear produto cedo.',
                'markers' => ['imagine', 'imagine', 'a woman just like', 'uma mulher como', 'have you ever noticed', 'você já reparou', 'story', 'história', 'it started', 'começou quando']],
            ['key' => 'problem_aware', 'name' => 'Consciente do problema', 'category' => 'awareness', 'weight' => 4,
                'trigger' => 'Sente a dor, não conhece a solução. Quer ser entendido antes de ouvir oferta.',
                'lever' => 'Agite o problema com cena visceral e revele a causa/mecanismo.',
                'markers' => ['struggling with', 'lutando', 'tired of', 'cansad', "can't seem to", 'não consegue', 'sick of', 'farto', 'why nothing', 'por que nada', 'the scale', 'a balança']],
            ['key' => 'solution_aware', 'name' => 'Consciente da solução', 'category' => 'awareness', 'weight' => 4,
                'trigger' => 'Sabe que há soluções e compara categorias — busca a MELHOR. Cético de claims.',
                'lever' => 'Lidere com o mecanismo único e diferencie das outras soluções.',
                'markers' => ['unlike', 'ao contrário de', 'better than', 'melhor que', 'the difference', 'a diferença', 'alternative to', 'alternativa', 'vs ', 'compared to', 'instead of ozempic']],
            ['key' => 'product_aware', 'name' => 'Consciente do produto', 'category' => 'awareness', 'weight' => 4,
                'trigger' => 'Conhece seu produto, falta decidir. Precisa de prova, diferenciação e razão pra agir.',
                'lever' => 'Oferta, prova, garantia, bônus, "por que escolher este".',
                'markers' => ['why choose', 'por que escolher', 'reviews', 'avaliações', 'guarantee', 'garantia', 'official', 'oficial', 'real customers', 'clientes reais', 'verified']],
            ['key' => 'most_aware', 'name' => 'Totalmente consciente', 'category' => 'awareness', 'weight' => 3,
                'trigger' => 'Pronto pra comprar — só precisa do empurrão e do melhor negócio.',
                'lever' => 'Oferta direta, escassez, CTA forte, deal.',
                'markers' => ['order now', 'compre agora', 'get yours', 'garanta o seu', 'last chance', 'última chance', 'claim', 'discount', 'desconto', 'today only', 'só hoje']],

            // ── 5 stages of market sophistication (how tired the market is of the claim) ──────────
            ['key' => 'stage1_first', 'name' => 'Estágio 1 — ser o primeiro', 'category' => 'sophistication', 'weight' => 2,
                'trigger' => 'Mercado virgem: o claim simples e direto basta.',
                'lever' => 'Afirme o benefício direto, sem mecanismo.',
                'markers' => ['lose weight', 'perca peso', 'make money', 'ganhe dinheiro', 'feel better', 'simple', 'simples']],
            ['key' => 'stage2_bigger', 'name' => 'Estágio 2 — claim maior', 'category' => 'sophistication', 'weight' => 2,
                'trigger' => 'Mercado já ouviu o claim: amplie (mais rápido, mais, maior).',
                'lever' => 'Aumente a promessa com números/superlativos.',
                'markers' => ['twice as', 'duas vezes', '2x', 'fastest', 'mais rápido', 'the most', 'o maior', 'in just', 'em apenas', 'even more']],
            ['key' => 'stage3_mechanism', 'name' => 'Estágio 3 — mecanismo único', 'category' => 'sophistication', 'weight' => 4,
                'trigger' => 'Mercado não acredita no claim cru: o COMO (mecanismo nomeado) vira o herói.',
                'lever' => 'Nomeie e explique o mecanismo único que torna o resultado possível.',
                'markers' => ['protocol', 'protocolo', 'mechanism', 'mecanismo', 'how it works', 'como funciona', 'the method', 'o método', 'discovery', 'because of', 'por causa de']],
            ['key' => 'stage4_bigger_mechanism', 'name' => 'Estágio 4 — mecanismo ampliado', 'category' => 'sophistication', 'weight' => 3,
                'trigger' => 'Mercado já ouviu o mecanismo: torne-o novo, melhor, mais fácil ou mais rápido.',
                'lever' => 'Posicione o mecanismo como nova geração / o único que / sem o esforço.',
                'markers' => ['new and improved', 'nova geração', 'the only', 'o único', 'without the', 'sem o', 'next generation', 'easier', 'mais fácil', 'breakthrough', 'finally a']],
            ['key' => 'stage5_identity', 'name' => 'Estágio 5 — identidade', 'category' => 'sophistication', 'weight' => 3,
                'trigger' => 'Mercado esgotado de mecanismo: vende-se identidade, pertencimento e experiência.',
                'lever' => 'Fale com quem ela é/quer ser; tribo, história, estilo de vida.',
                'markers' => ['for women who', 'para mulheres que', 'join', 'junte-se', 'people like us', 'gente como', 'you deserve', 'você merece', 'become', 'torne-se', 'movement', 'movimento']],

            // ── Aprofundamento Volta 2: micro-transições entre níveis (a engenharia fina) ───
            ['key' => 'unaware_to_problem_bridge', 'name' => 'Ponte unaware → problem-aware', 'category' => 'transition', 'weight' => 4,
                'trigger' => 'Tráfego frio precisa ser ENSINADO que tem um problema antes de qualquer venda — sem isso, "buy now" não converte.',
                'lever' => 'Cena/história + revelação: "você notou que…?" → planta o sintoma → nomeia o problema.',
                'markers' => ['did you notice', 'você notou', 'have you been feeling', 'tem sentido', 'something most people don\'t realize', 'algo que a maioria não percebe', 'what they don\'t tell you', 'o que não te contam', 'the symptom', 'o sintoma']],
            ['key' => 'problem_to_solution_bridge', 'name' => 'Ponte problem → solution-aware', 'category' => 'transition', 'weight' => 4,
                'trigger' => 'Já entende a dor — agora precisa SABER QUE EXISTE uma solução nova/diferente. "Há uma forma" abre o salto.',
                'lever' => '"E se eu te dissesse que existe uma forma de…" → categoria nova + esperança.',
                'markers' => ['what if i told you', 'e se eu te dissesse', 'there is a way', 'existe uma forma', 'imagine if you could', 'imagine se você pudesse', 'a new approach', 'uma nova abordagem', "what if there was", 'e se houvesse']],
            ['key' => 'solution_to_product_bridge', 'name' => 'Ponte solution → product-aware', 'category' => 'transition', 'weight' => 4,
                'trigger' => 'Conhece soluções e compara — agora precisa saber POR QUE ESTE produto específico é a melhor encarnação.',
                'lever' => '"Existem várias formas de fazer X. Esta é a única que…" → diferenciação concreta.',
                'markers' => ['there are many', 'existem várias', 'this is the only', 'esta é a única', 'unlike other', 'ao contrário de outros', 'what makes this different', 'o que torna isto diferente', 'while everyone else', 'enquanto todos os outros']],
            ['key' => 'product_to_most_bridge', 'name' => 'Ponte product → most-aware', 'category' => 'transition', 'weight' => 3,
                'trigger' => 'Conhece o produto, decide pelo deal — empurrão final é oferta/escassez/garantia, não argumento.',
                'lever' => '"Ok, você já sabe. Aqui está o melhor preço pelos próximos X" → CTA direto.',
                'markers' => ['you already know', 'você já sabe', "let's get to it", 'vamos ao que interessa', 'here is the deal', 'eis o acordo', "no need to explain", 'não precisa explicar', 'just click', 'é só clicar']],

            // ── Padrões raros de sofisticação ────────────────────────────────────────────────
            ['key' => 'meta_sophistication', 'name' => 'Meta-sofisticação (vencer pelo cansaço)', 'category' => 'sophistication', 'weight' => 4,
                'trigger' => 'Mercado tão saturado que todo claim/mecanismo já foi feito — vencer reconhecendo a fadiga: "eu sei, mais um?".',
                'lever' => '"Eu sei o que você está pensando: mais uma solução milagrosa? Justo. Vou te mostrar por que esta é diferente — e se eu não convencer em 2 min, feche."',
                'markers' => ['i know what you', 'eu sei o que você', 'another ', 'mais um ', 'tired of all', 'cansad de todos', 'yet another', 'mais uma', 'fair enough', 'justo', "you've heard it all", 'você já ouviu de tudo']],
            ['key' => 'mismatch_self_correction', 'name' => 'Auto-correção de mismatch', 'category' => 'transition', 'weight' => 3,
                'trigger' => 'Quando a página detecta que o leitor pode estar no nível errado, redireciona ela: "se você está procurando X, isto é Y".',
                'lever' => '"Se você ainda está procurando o problema, leia o artigo X. Se já sabe o problema e quer a solução, continue."',
                'markers' => ['if you are still', 'se você ainda está', 'if you already know', 'se você já sabe', 'wrong page if', 'página errada se', 'this is the right page if', 'esta é a página certa se', 'go here instead', 'vá para lá']],
            ['key' => 'awareness_layering', 'name' => 'Camadas (mesma página atende vários níveis)', 'category' => 'transition', 'weight' => 3,
                'trigger' => 'Página premium serve várias awareness simultaneamente: TL;DR pra most-aware, história pra unaware, prova pra solution.',
                'lever' => 'Box "Já conhece? Pule para [oferta]" no topo + história completa abaixo + tabela técnica no fim.',
                'markers' => ['tldr', 'tl;dr', 'skip to', 'pule para', 'in a hurry', 'com pressa', 'for the curious', 'para os curiosos', 'jump to', 'pule para', 'short version', 'versão curta']],
            ['key' => 'lateral_awareness_shift', 'name' => 'Mudança lateral de awareness (pivô)', 'category' => 'transition', 'weight' => 3,
                'trigger' => 'Pegar tráfego de uma awareness pra OUTRO problema relacionado — "você veio aqui por X, mas X é só sintoma de Y".',
                'lever' => '"Você procurou por dieta. Mas dieta não é o problema — é hormônio. E isto resolve hormônio."',
                'markers' => ['you came here for', 'você veio aqui por', 'but actually', 'mas na verdade', "what you're really", 'o que você realmente', "isn't the real", 'não é o real', "the bigger picture", 'o quadro maior']],

            // ── Padrões raros de sofisticação de mercado ───────────────────────────────────
            ['key' => 'sophistication_collapse', 'name' => 'Colapso de sofisticação (voltar ao básico)', 'category' => 'sophistication', 'weight' => 3,
                'trigger' => 'Quando o mercado está exausto de mecanismos elaborados, voltar à promessa simples e direta surpreende — "Hey Schwartz, e se a gente desfizer isto?".',
                'lever' => '"Esquece protocolos. Esquece hormônios. É isso: você toma, você emagrece. Olha os resultados."',
                'markers' => ['forget all the', 'esqueça todos os', 'no fancy ', 'sem nada chique ', 'no science talk', 'sem papo científico', 'just take it', 'só tome', "let's keep it simple", 'vamos simplificar', 'back to basics', 'volta ao básico']],
            ['key' => 'category_creation', 'name' => 'Criação de nova categoria', 'category' => 'sophistication', 'weight' => 4,
                'trigger' => 'Quando todas as 5 sofisticações foram exauridas, a saída é CRIAR uma categoria nova — "isto não é dieta, é X".',
                'lever' => '"Isto não é um suplemento. É um protocolo. É a primeira [categoria nova]."',
                'markers' => ['this is not a', 'isto não é um', "we're not a", 'não somos um', 'introducing the first', 'apresentando o primeiro', 'a new category', 'uma nova categoria', 'we invented', 'nós inventamos', 'category-creating']],
        ];
    }

    /**
     * @return array<int,string>
     */
    public function categories(): array
    {
        return ['awareness', 'sophistication', 'transition'];
    }
}
