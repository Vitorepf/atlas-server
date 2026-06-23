<?php

namespace App\Services\Ai\MarketingDomain\Knowledge;

/**
 * CognitiveBiasLibrary — the behavioral-economics layer (Kahneman, Tversky, Ariely) BEYOND Cialdini's
 * influence weapons. These are the decision shortcuts the brain can't switch off: anchoring, loss
 * aversion, the decoy effect, sunk cost, endowment, framing, Zeigarnik, peak-end, contrast, cognitive
 * ease, choice simplicity, charm pricing, default bias. Each carries the human mechanism, how elite
 * copy/offer construction deploys it, and detection markers. Content-independent — biases fire the
 * same in any niche.
 */
class CognitiveBiasLibrary implements PatternLibrary
{
    public function name(): string
    {
        return 'cognitive_bias';
    }

    /**
     * @return array<int,array{key:string,name:string,category:string,weight:int,trigger:string,lever:string,markers:array<int,string>}>
     */
    public function all(): array
    {
        return [
            ['key' => 'anchoring', 'name' => 'Ancoragem', 'category' => 'value', 'weight' => 4,
                'trigger' => 'O primeiro número visto vira a régua de tudo que vem depois — um preço alto faz o real parecer pequeno.',
                'lever' => 'Ancore um valor alto (preço riscado, "normalmente $X", custo da alternativa) antes do preço real.',
                'markers' => ['normally', 'normalmente', 'regular price', 'preço normal', 'worth', 'vale', 'was $', 'de r$', 'valued at', '$1,000', 'a month', 'por mês', 'compared to the']],
            ['key' => 'loss_aversion', 'name' => 'Aversão à perda', 'category' => 'loss', 'weight' => 5,
                'trigger' => 'Perder dói ~2× mais que ganhar o equivalente — o medo de perder move mais que o desejo.',
                'lever' => 'Enquadre como o que ela PERDE ao não agir (tempo, resultado, a janela).',
                'markers' => ["don't miss", 'não perca', 'miss out', 'ficar de fora', 'before you lose', 'antes de perder', 'slipping away', 'escapando', 'every day you wait', 'cada dia que passa', "what you're losing"]],
            ['key' => 'decoy_effect', 'name' => 'Efeito chamariz', 'category' => 'choice', 'weight' => 4,
                'trigger' => 'Uma terceira opção "isca" faz a opção-alvo parecer obviamente a melhor.',
                'lever' => 'Ofereça 3 pacotes onde o do meio/maior é o alvo e o pequeno é a isca cara-por-unidade.',
                'markers' => ['most popular', 'mais popular', 'best value', 'melhor custo', 'per bottle', 'por frasco', 'recommended kit', 'kit recomendado', '6-bottle', '3-bottle', 'only $ per']],
            ['key' => 'sunk_cost', 'name' => 'Custo afundado', 'category' => 'loss', 'weight' => 3,
                'trigger' => 'O que já foi investido (tempo/dinheiro/esperança) puxa pra continuar pra não "perder tudo".',
                'lever' => 'Lembre tudo que ela já tentou/gastou — e como parar agora desperdiça isso.',
                'markers' => ["you've already", 'você já', 'after everything', 'depois de tudo', 'all the diets', 'todas as dietas', 'so much time', 'tanto tempo', 'invested', 'investiu', 'years of trying']],
            ['key' => 'endowment', 'name' => 'Efeito de posse', 'category' => 'value', 'weight' => 3,
                'trigger' => 'Sentir algo como já seu aumenta o valor percebido e a dor de não ficar com ele.',
                'lever' => '"Seu kit está reservado", "imagine já tendo" — dê posse antes da compra.',
                'markers' => ['your kit', 'seu kit', 'reserved for you', 'reservado pra você', 'imagine having', 'imagine ter', 'claim yours', 'garanta o seu', 'is waiting for you', 'está esperando']],
            ['key' => 'framing', 'name' => 'Enquadramento', 'category' => 'framing', 'weight' => 4,
                'trigger' => 'A mesma informação enquadrada diferente muda a decisão (90% magro ≠ 10% gordura).',
                'lever' => 'Enquadre no positivo desejado e a inação no negativo concreto.',
                'markers' => ['9 out of 10', '9 em cada 10', 'free of', 'livre de', 'only X%', 'apenas', 'instead of', 'em vez de', 'without the', 'sem o', 'more than half']],
            ['key' => 'zeigarnik', 'name' => 'Efeito Zeigarnik', 'category' => 'attention', 'weight' => 3,
                'trigger' => 'Tarefas/laços incompletos grudam na mente até serem fechados — puxam pra continuar.',
                'lever' => 'Abra um laço/passo incompleto ("falta 1 passo", barra de progresso, "a seguir").',
                'markers' => ['one more step', 'falta um passo', 'almost there', 'quase lá', 'step 1 of', 'passo 1 de', 'unlock', 'desbloque', 'complete your', 'to be continued', 'finish']],
            ['key' => 'peak_end', 'name' => 'Regra do pico-fim', 'category' => 'attention', 'weight' => 3,
                'trigger' => 'A memória de uma experiência é dominada pelo pico emocional e pelo final.',
                'lever' => 'Crie um pico emocional forte e termine com a imagem/CTA mais potente.',
                'markers' => ['and the best part', 'e o melhor', 'the moment', 'o momento em que', 'imagine the day', 'imagine o dia', 'one last thing', 'por último', 'finally', 'finalmente']],
            ['key' => 'contrast', 'name' => 'Efeito de contraste', 'category' => 'framing', 'weight' => 3,
                'trigger' => 'A percepção é relativa — o contraste (com/sem, antes/depois, vs alternativa) define o valor.',
                'lever' => 'Justaponha o caminho velho doloroso ao novo fácil; com vs sem.',
                'markers' => ['before and after', 'antes e depois', 'with vs without', 'com e sem', 'the difference between', 'a diferença entre', 'while others', 'enquanto outros', 'unlike the']],
            ['key' => 'cognitive_ease', 'name' => 'Fluência cognitiva', 'category' => 'attention', 'weight' => 3,
                'trigger' => 'O que é fácil de processar parece mais verdadeiro, seguro e confiável.',
                'lever' => 'Simplicidade radical: frases curtas, "em 3 segundos", "passo a passo", nada complicado.',
                'markers' => ['simple', 'simples', 'easy', 'fácil', 'just ', 'basta', 'in 3 seconds', 'em 3 segundos', 'no complicated', 'sem complicação', 'step-by-step', 'passo a passo']],
            ['key' => 'choice_simplicity', 'name' => 'Simplicidade de escolha', 'category' => 'choice', 'weight' => 3,
                'trigger' => 'Excesso de opções paralisa; reduzir a uma decisão clara aumenta a ação.',
                'lever' => 'Reduza tudo a uma única próxima decisão; tire o resto da frente.',
                'markers' => ['one decision', 'uma decisão', 'just one', 'só uma', 'the only choice', 'a única escolha', 'no need to choose', 'one simple', 'all you have to do', 'tudo que você precisa']],
            ['key' => 'charm_pricing', 'name' => 'Preço psicológico', 'category' => 'value', 'weight' => 2,
                'trigger' => 'Preços terminados em 7/9 são percebidos como muito menores que o redondo logo acima.',
                'lever' => 'Use terminações 7/9 e o "de/por" pra cravar a percepção de barganha.',
                'markers' => ['/\$\d*[79](\.\d{2})?\b/u', '/r\$\s?\d*[79]\b/u', ' 97', ' 47', ' 67', 'ending in', 'just $', 'apenas r$']],
            ['key' => 'default_bias', 'name' => 'Viés do padrão', 'category' => 'choice', 'weight' => 3,
                'trigger' => 'As pessoas tendem a ficar com a opção pré-selecionada/recomendada — o caminho de menor esforço.',
                'lever' => 'Destaque e pré-selecione a opção-alvo como "a mais escolhida/recomendada".',
                'markers' => ['pre-selected', 'pré-selecionado', 'we recommend', 'recomendamos', 'most choose', 'a maioria escolhe', 'best seller', 'mais vendido', 'default', 'highlighted', 'em destaque']],
        ];
    }

    /**
     * @return array<int,string>
     */
    public function categories(): array
    {
        return ['framing', 'loss', 'value', 'attention', 'choice'];
    }
}
