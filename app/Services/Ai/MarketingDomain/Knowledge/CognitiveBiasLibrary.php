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

            // ── Aprofundamento Volta 2: vieses raros + variações avançadas ─────────────────────
            ['key' => 'availability_heuristic', 'name' => 'Heurística da disponibilidade', 'category' => 'attention', 'weight' => 4,
                'trigger' => 'O cérebro julga probabilidade pela facilidade de lembrar um exemplo — uma história vívida vale mais que dados.',
                'lever' => 'Conte 1 cena vívida + recente em vez de citar estatística — "Maria, semana passada, em Tampa…".',
                'markers' => ['just last week', 'semana passada', 'yesterday', 'ontem', 'recently', 'recentemente', 'this morning', 'esta manhã', 'a story', 'uma história', 'this just happened']],
            ['key' => 'recency_effect', 'name' => 'Efeito recência', 'category' => 'attention', 'weight' => 3,
                'trigger' => 'O último argumento/cena fica mais vivo na memória — o final domina a decisão.',
                'lever' => 'Termine com o ponto mais forte (não com FAQ ou disclaimer); CTA final = imagem do estado pós-compra.',
                'markers' => ['one last thing', 'mais uma coisa', 'before you go', 'antes de você ir', 'finally', 'finalmente', 'in closing', 'pra fechar', 'leaving you with', 'deixando você com']],
            ['key' => 'fluency_bias', 'name' => 'Viés de fluência (rima/aliteração)', 'category' => 'attention', 'weight' => 3,
                'trigger' => 'Frases rimadas/aliterativas são percebidas como mais verdadeiras (efeito "rhyme as reason").',
                'lever' => 'Slogan curto com rima/aliteração: "Triple Hormone Drops Protocol", "Stop the Yo-Yo".',
                'markers' => ['/\b(\w{2,})-\1\b/iu', 'yo-yo', 'win-win', 'tit-for-tat', 'no-brainer', '/\b([a-z])\w+\s+\1\w+\s+\1\w+/iu', '/\b(\w{3,}),?\s+\1,?\s+\1/iu', 'rhyme', 'rima', 'easy peasy', 'mais é melhor']],
            ['key' => 'status_quo_bias', 'name' => 'Viés do status quo (quebrar)', 'category' => 'choice', 'weight' => 3,
                'trigger' => 'Mudar exige esforço; o leitor prefere ficar parado. Pintar o status quo como insuportável quebra a inércia.',
                'lever' => 'Mostre que "ficar como está" é uma DECISÃO ativa de continuar sofrendo.',
                'markers' => ['doing nothing', 'não fazer nada', 'staying the same', 'continuar igual', 'the cost of inaction', 'custo da inação', 'is also a choice', 'também é uma escolha', 'tomorrow you wake up the same', 'amanhã você acorda igual']],
            ['key' => 'ostrich_effect', 'name' => 'Efeito avestruz (quebrar)', 'category' => 'attention', 'weight' => 3,
                'trigger' => 'Pessoas evitam informação dolorosa. Forçar confronto direto ("isto vai doer ler") quebra a evitação.',
                'lever' => '"Eu sei que dói ler isto. Mas se você fechar agora, é mais 6 meses no mesmo lugar."',
                'markers' => ['this will hurt', 'isto vai doer', "i know it's uncomfortable", 'eu sei que é desconfortável', 'face it now', 'encare agora', 'avoiding it', 'evitar isso', 'look away if you want', 'olhe para o lado se quiser']],
            ['key' => 'bandwagon_explicit', 'name' => 'Efeito manada explícito', 'category' => 'attention', 'weight' => 3,
                'trigger' => 'Pessoas seguem o que muitos estão fazendo AGORA — "todo mundo está comprando" funciona.',
                'lever' => '"X pessoas começaram hoje", "Y compraram este mês", contador ao vivo.',
                'markers' => ['/\b\d{1,3}(,\d{3})*\s+(people|women|men|pessoas) (joined|started|bought|começaram|compraram)/iu', 'people are joining', 'pessoas estão entrando', 'going viral', 'viralizando', 'everyone is talking', 'todo mundo está falando']],
            ['key' => 'ikea_effect', 'name' => 'Efeito IKEA (esforço = valor)', 'category' => 'value', 'weight' => 2,
                'trigger' => 'O que envolve esforço próprio (montar, configurar, personalizar) é valorizado 2× mais.',
                'lever' => 'Quiz que personaliza a oferta; "monte seu kit"; passos que ela cumpre.',
                'markers' => ['take the quiz', 'faça o quiz', 'build your', 'monte seu', 'customize', 'personalize', 'your personal plan', 'seu plano pessoal', 'tailored to you', 'feito para você', 'configure']],
            ['key' => 'reactance_avoidance', 'name' => 'Evitar reactância', 'category' => 'framing', 'weight' => 4,
                'trigger' => 'Quando se sentem pressionadas, pessoas resistem por instinto — "compre agora!" pode ter efeito reverso.',
                'lever' => '"Você decide", "sem pressão", "vê e julga por si" — devolver controle aumenta sim.',
                'markers' => ['you decide', 'você decide', 'no pressure', 'sem pressão', "it's your call", 'a decisão é sua', 'take your time', 'no seu tempo', "we don't push", 'não pressionamos', 'see for yourself', 'veja por si']],
            ['key' => 'mental_accounting', 'name' => 'Contabilidade mental', 'category' => 'value', 'weight' => 3,
                'trigger' => 'Dinheiro em "categorias mentais" diferentes parece diferente — $97 em "saúde" sente diferente que em "supérfluo".',
                'lever' => 'Reframe a compra na categoria que ela já gasta: "menos que seu cabelo do mês".',
                'markers' => ['less than your', 'menos que seu', 'what you spend on', 'o que você gasta com', 'one dinner out', 'um jantar fora', 'one tank of gas', 'um tanque de gasolina', 'less than netflix', 'menos que a netflix']],
            ['key' => 'goal_gradient', 'name' => 'Gradiente do objetivo', 'category' => 'attention', 'weight' => 3,
                'trigger' => 'Quanto mais perto da meta, mais a pessoa acelera (efeito Dropbox: progresso visual aumenta conclusão).',
                'lever' => '"Você já fez 80% do trabalho — só falta o último passo": barra de progresso, "passo 3 de 4".',
                'markers' => ['almost there', 'quase lá', '/step \d+ of \d+/iu', '/passo \d+ de \d+/iu', 'last step', 'último passo', "you're 80%", 'você já está em 80%', 'final mile', 'reta final', 'finish line', 'linha de chegada']],
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
