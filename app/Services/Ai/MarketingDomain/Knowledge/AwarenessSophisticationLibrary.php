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
        ];
    }

    /**
     * @return array<int,string>
     */
    public function categories(): array
    {
        return ['awareness', 'sophistication'];
    }
}
