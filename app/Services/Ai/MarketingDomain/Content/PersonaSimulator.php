<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * PersonaSimulator — em vez de medir markers (o que a página TEM), simula a reação de personas
 * céticas à página (o que cada CABEÇA REAL faria). Para cada persona, devolve: prob de clicar play
 * (0-1), prob de fechar a aba, a primeira objeção que ela pensa, e o que ela precisaria ler em
 * seguida pra continuar. É o brief original ("eu mesmo julgo como painel brutal") cristalizado — vai
 * além do que markers detectam pra um modelo computacional da reação humana. Provider-free, heurístico.
 *
 * NICHE-AWARE: o painel de saúde (mulher 40+, ex-Ozempic, mãe sem tempo, marido cético, early
 * adopter) vive aqui em métodos hand-tuned. Mas o cérebro de um leitor de FINANÇAS ou RELACIONAMENTO
 * é outro — o medo é PERDER dinheiro / ficar só, não "mais uma dieta". Quando um niche não-saúde é
 * passado, o painel correto vem do PersonaLibrary (data-driven). Sem isso, a prova cross-nicho do
 * loop era inválida: as personas de saúde davam audience≈0 em QUALQUER página de finanças, boa ou má.
 */
class PersonaSimulator
{
    public function __construct(
        private readonly PersonaLibrary $library = new PersonaLibrary,
        private readonly CopySubstanceProbe $substance = new CopySubstanceProbe,
    ) {}

    /**
     * @return array<string,array{will_watch:float,will_close:float,first_objection:string,what_she_needs_next:string,reason:string}>
     */
    public function simulate(string $copy, string $html = '', string $niche = ''): array
    {
        $family = $this->library->resolveFamily($niche);
        if ($family !== 'health') {
            // Non-health niche → the niche's real skeptic panel (finance/relationship/generic).
            return $this->library->simulate($family, $copy);
        }

        // Health / unlabeled (the OT169 proving ground) → the hand-tuned panel, byte-identical.
        $text = mb_strtolower($copy);
        $personas = [];

        $personas['woman_40_diet_fatigue'] = $this->woman40($text);
        $personas['ex_ozempic_buyer'] = $this->exOzempic($text);
        $personas['busy_mom_no_time'] = $this->busyMom($text);
        $personas['skeptic_husband'] = $this->skepticHusband($text);
        $personas['early_adopter'] = $this->earlyAdopter($text);

        return $personas;
    }

    /**
     * Aggregated "audience" score in [0,1]. Niche-aware: simulates the right panel for the niche, then
     * aggregates with two fixes the brutal cross-niche panel forced (it proved the old formula made
     * keyword-salad out-score real elite copy, and collapsed every non-stuffed page to exactly 0):
     *   - FLOOR-PENETRATING MAP: mean(watch−close) ∈ [−1,1] mapped to [0,1] so the bottom of the scale
     *     keeps ranking power — real copy the markers can't "see" lands at a neutral baseline, not 0.
     *   - SUBSTANCE GATE: multiplied by CopySubstanceProbe so a vacuous page (salad / bare-label
     *     stuffing) cannot out-score real craft just by firing the right vocabulary.
     */
    public function audienceScore(string $copy, string $niche = ''): float
    {
        $sim = $this->simulate($copy, '', $niche);
        $n = max(1, count($sim));
        $raw = 0.0;
        foreach ($sim as $p) {
            $raw += $p['will_watch'] - $p['will_close'];
        }
        $raw /= $n;                       // mean reaction ∈ [-1, 1]

        $mapped = ($raw + 1.0) / 2.0;     // floor-penetrating → [0, 1]
        $mapped *= $this->substance->substance($copy);

        return max(0.0, min(1.0, $mapped));
    }

    /**
     * @return array{will_watch:float,will_close:float,first_objection:string,what_she_needs_next:string,reason:string}
     */
    private function woman40(string $text): array
    {
        $callout = $this->any($text, ['woman over 40', 'women over 40', 'after 40', 'menopause', 'mulher 40', 'depois dos 40']);
        $reframe = $this->any($text, ['not your fault', 'never your willpower', 'a culpa não', 'biology', 'biologia', 'three hormones', 'três hormônios']);
        $tried = $this->any($text, ['tried everything', 'tentei tudo', 'nothing worked', 'nada funcionou']);
        $proof = $this->any($text, ['as seen on', 'visto na', 'reviews', 'study', 'estudo', 'dr.', 'harvard']);
        $hollow = $this->any($text, ['amazing', 'incredible', 'revolutionary', 'transform your life']);

        $watch = 0.2 + ($callout ? 0.25 : 0) + ($reframe ? 0.2 : 0) + ($tried ? 0.1 : 0) + ($proof ? 0.15 : 0) - ($hollow ? 0.15 : 0);
        $close = 0.4 - ($callout ? 0.15 : 0) - ($reframe ? 0.1 : 0) + ($hollow ? 0.2 : 0);
        $obj = ! $proof ? 'cadê a prova de que não é mais um suplemento qualquer?'
            : (! $reframe ? 'isto é só mais uma dieta diferente, vou falhar de novo' : 'tudo bem, mas e o efeito colateral?');
        $next = ! $proof ? 'precisa de um nome de médico ou veículo de mídia'
            : (! $reframe ? 'precisa de "a culpa não é sua" + mecanismo nomeado' : 'precisa de depoimento de mulher 40+ específica');

        return [
            'will_watch' => round(max(0.0, min(1.0, $watch)), 2),
            'will_close' => round(max(0.0, min(1.0, $close)), 2),
            'first_objection' => $obj,
            'what_she_needs_next' => $next,
            'reason' => 'Mulher 40+ desgastada: pesa muito callout específico + reframe ("não é sua culpa") + prova específica; hype solto a faz fechar.',
        ];
    }

    /**
     * @return array{will_watch:float,will_close:float,first_objection:string,what_she_needs_next:string,reason:string}
     */
    private function exOzempic(string $text): array
    {
        $vsInjection = $this->any($text, ['without injection', 'sem injeção', 'no needle', 'sem agulha', 'unlike ozempic', 'ao contrário do ozempic', 'instead of ozempic']);
        $proof = $this->any($text, ['as seen on', 'study', 'dr.', 'reviews', 'verified']);
        $price = $this->any($text, ['$1,000 a month', '$1000 a month', '1 mil por mês', '$200 billion', 'big pharma']);
        $hollow = $this->any($text, ['amazing', 'just take', 'so easy']);

        $watch = 0.15 + ($vsInjection ? 0.3 : 0) + ($price ? 0.2 : 0) + ($proof ? 0.15 : 0) - ($hollow ? 0.1 : 0);
        $close = 0.5 - ($vsInjection ? 0.2 : 0) - ($price ? 0.1 : 0) + ($hollow ? 0.15 : 0);
        $obj = ! $vsInjection ? 'mais um suplemento que não vai chegar perto do efeito da injeção'
            : (! $price ? 'por que isto custa tão barato se funciona?' : 'tudo bem, mas onde está o estudo comparando lado a lado?');
        $next = ! $vsInjection ? 'precisa de "all 3 hormones, no needle" antes da dobra'
            : (! $price ? 'precisa de ancoragem com o custo da injeção' : 'precisa de estudo head-to-head ou caso real');

        return [
            'will_watch' => round(max(0.0, min(1.0, $watch)), 2),
            'will_close' => round(max(0.0, min(1.0, $close)), 2),
            'first_objection' => $obj,
            'what_she_needs_next' => $next,
            'reason' => 'Ex-compradora de Ozempic: comparação direta com a injeção + ancoragem de preço da alternativa cara é tudo; sem isso, fecha em 3 segundos.',
        ];
    }

    /**
     * @return array{will_watch:float,will_close:float,first_objection:string,what_she_needs_next:string,reason:string}
     */
    private function busyMom(string $text): array
    {
        $time = $this->any($text, ['3 seconds', '3 segundos', 'seconds in the morning', 'segundos de manhã', 'no diet', 'sem dieta', 'no gym', 'sem academia']);
        $easy = $this->any($text, ['easy', 'simple', 'fácil', 'simples', 'just take', 'basta tomar']);
        $tooLong = mb_strlen($text) > 4000;
        $hollow = $this->any($text, ['amazing', 'revolutionary']);

        $watch = 0.15 + ($time ? 0.3 : 0) + ($easy ? 0.15 : 0) - ($tooLong ? 0.1 : 0) - ($hollow ? 0.1 : 0);
        $close = 0.45 - ($time ? 0.2 : 0) + ($tooLong ? 0.15 : 0) + ($hollow ? 0.1 : 0);
        $obj = $tooLong ? 'longo demais, não tenho tempo de ler tudo'
            : (! $time ? 'isto vai exigir tempo que eu não tenho' : 'tudo bem mas como funciona na rotina real?');
        $next = $tooLong ? 'precisa de TL;DR ou summary box no topo'
            : (! $time ? 'precisa de "3 segundos de manhã, não muda a rotina"' : 'precisa de "1 mãe ocupada, 30 dias, resultado X"');

        return [
            'will_watch' => round(max(0.0, min(1.0, $watch)), 2),
            'will_close' => round(max(0.0, min(1.0, $close)), 2),
            'first_objection' => $obj,
            'what_she_needs_next' => $next,
            'reason' => 'Mãe ocupada: tempo + rotina simples + cena de mãe específica; copy longa sem TL;DR a perde imediato.',
        ];
    }

    /**
     * Skeptic husband: the approval gate many 40+ women run things by ("vou perguntar pro meu marido").
     * Sells safety + ROI + zero-risk + no monthly cost, not transformation. Decides in seconds.
     *
     * @return array{will_watch:float,will_close:float,first_objection:string,what_she_needs_next:string,reason:string}
     */
    private function skepticHusband(string $text): array
    {
        $guarantee = $this->any($text, ['money-back', 'reembolso', '60-day', '60 dias', '90-day', 'no questions', 'sem perguntas']);
        $oneTime = $this->any($text, ['one-time', 'única vez', 'lifetime', 'no subscription', 'sem assinatura', 'no monthly', 'sem mensalidade']);
        $proof = $this->any($text, ['study', 'estudo', 'doctor', 'médic', 'fda', 'as seen on', 'visto na']);
        $price = $this->any($text, ['$1,000 a month', '$1000 a month', '1 mil por mês', '$200 billion']);
        $vague = $this->any($text, ['change your life', 'transform', 'incredible', 'amazing']) && ! $proof;

        $watch = 0.1 + ($guarantee ? 0.25 : 0) + ($oneTime ? 0.15 : 0) + ($proof ? 0.2 : 0) + ($price ? 0.15 : 0) - ($vague ? 0.2 : 0);
        $close = 0.6 - ($guarantee ? 0.15 : 0) - ($proof ? 0.15 : 0) + ($vague ? 0.2 : 0);
        $obj = ! $guarantee ? 'cadê a garantia? sem isso é cilada'
            : (! $proof ? 'cadê o estudo de verdade?' : (! $oneTime ? 'isto vira uma cobrança mensal eterna?' : 'tudo bem, mas qual o risco real disto?'));
        $next = ! $guarantee ? 'precisa de "60-day money-back guarantee" visível'
            : (! $proof ? 'precisa de "Dr. X" ou estudo nomeado' : (! $oneTime ? 'precisa de "one-time purchase, no subscription"' : 'precisa de "made in USA, FDA-registered" ou similar'));

        return [
            'will_watch' => round(max(0.0, min(1.0, $watch)), 2),
            'will_close' => round(max(0.0, min(1.0, $close)), 2),
            'first_objection' => $obj,
            'what_she_needs_next' => $next,
            'reason' => 'Marido cético/gate de aprovação: ROI + garantia + zero risco recorrente; hype solto = veto imediato.',
        ];
    }

    /**
     * Early adopter: opposite end of the skeptic spectrum. Already convinced of the category, hates
     * basic pitches, wants to feel ahead-of-the-curve. Validates that copy doesn't ALSO alienate the
     * enthusiast. If she rolls her eyes, the operator is over-cheesing.
     *
     * @return array{will_watch:float,will_close:float,first_objection:string,what_she_needs_next:string,reason:string}
     */
    private function earlyAdopter(string $text): array
    {
        $deep = $this->any($text, ['mechanism', 'mecanismo', 'glp-1', 'gip', 'glucagon', 'protocol', 'protocolo', 'hormones', 'hormôn']);
        $contrarian = $this->any($text, ['unlike', 'instead of', 'everything you know', 'tudo que você sabe', 'is wrong', 'está errado', 'opposite']);
        $premium = $this->any($text, ['ahead of', 'à frente', 'inner circle', 'membros', 'founder', 'fundador', 'early', 'antes de todos']);
        $cheesy = $this->any($text, ['miracle', 'milagre', 'magic', 'mágica', 'just take', 'change your life forever', 'transforme sua vida']);

        $watch = 0.2 + ($deep ? 0.25 : 0) + ($contrarian ? 0.2 : 0) + ($premium ? 0.15 : 0) - ($cheesy ? 0.25 : 0);
        $close = 0.45 - ($deep ? 0.15 : 0) - ($contrarian ? 0.1 : 0) + ($cheesy ? 0.25 : 0);
        $obj = $cheesy ? 'piegas demais, parece pirâmide'
            : (! $deep ? 'cadê a profundidade técnica? superficial demais' : (! $contrarian ? 'igual a 100 outras propagandas' : 'tudo bem, mas onde acesso o whitepaper?'));
        $next = $cheesy ? 'cortar miracle/transform/just take — voz mais sóbria'
            : (! $deep ? 'precisa de detalhe técnico (GLP-1/GIP/glucagon explicado)' : (! $contrarian ? 'precisa de "unlike X" diferenciador específico' : 'precisa de link pra estudo/whitepaper'));

        return [
            'will_watch' => round(max(0.0, min(1.0, $watch)), 2),
            'will_close' => round(max(0.0, min(1.0, $close)), 2),
            'first_objection' => $obj,
            'what_she_needs_next' => $next,
            'reason' => 'Early adopter: já entende o nicho, valida que a copy não é piegas; sua reação cobre o flanco oposto ao cético.',
        ];
    }

    /**
     * @param  array<int,string>  $needles
     */
    private function any(string $text, array $needles): bool
    {
        foreach ($needles as $n) {
            if (str_contains($text, mb_strtolower($n))) {
                return true;
            }
        }

        return false;
    }
}
