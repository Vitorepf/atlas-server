<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * PersonaSimulator — em vez de medir markers (o que a página TEM), simula a reação de 3 personas
 * céticas à página (o que cada CABEÇA REAL faria): mulher 40+ desgastada de dietas, ex-compradora
 * de Ozempic frustrada, mãe sem tempo. Para cada persona, devolve: prob de clicar play (0-1), prob
 * de fechar a aba, a primeira objeção que ela pensa, e o que ela precisaria ler em seguida pra
 * continuar. É o brief original ("eu mesmo julgo como painel brutal") cristalizado — vai além do
 * que markers detectam pra um modelo computacional da reação humana. Provider-free, heurístico.
 */
class PersonaSimulator
{
    /**
     * @return array<string,array{will_watch:float,will_close:float,first_objection:string,what_she_needs_next:string,reason:string}>
     */
    public function simulate(string $copy, string $html = ''): array
    {
        $text = mb_strtolower($copy);
        $personas = [];

        $personas['woman_40_diet_fatigue'] = $this->woman40($text);
        $personas['ex_ozempic_buyer'] = $this->exOzempic($text);
        $personas['busy_mom_no_time'] = $this->busyMom($text);

        return $personas;
    }

    /**
     * Aggregated "audience" score — average will_watch minus average will_close, clipped to [0,1].
     */
    public function audienceScore(string $copy): float
    {
        $sim = $this->simulate($copy);
        $watch = 0.0;
        $close = 0.0;
        foreach ($sim as $p) {
            $watch += $p['will_watch'];
            $close += $p['will_close'];
        }
        $n = max(1, count($sim));

        return max(0.0, min(1.0, ($watch - $close) / $n));
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
