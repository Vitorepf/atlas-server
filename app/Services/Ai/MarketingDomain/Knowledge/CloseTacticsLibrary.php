<?php

namespace App\Services\Ai\MarketingDomain\Knowledge;

/**
 * CloseTacticsLibrary — the CLOSE-phase moves, the gap the OS had (ObjectionLibrary catalogs OBJECTIONS;
 * nothing catalogued the closing MOVES that turn a hesitant reader into a buyer).
 *
 * Distilled from the aggressive closers (Belfort straight-line, Kennedy take-away, Cialdini commitment,
 * Hormozi/Voss): assumptive close, take-away / qualification inversion, feel-felt-found, future-pace close,
 * risk-reversal close, cost-of-inaction (loss at the decision), price-per-day reframe, the certainty-axis
 * re-close loop, the alternative close. Same PatternLibrary shape → measured by the single scorer and
 * indexed by SalesMomentPatternIndex at sales_moment=close. Per the operator's pétreo rule there is NO
 * moral brake: the dark closers (take-away pressure, manufactured "not for everyone") are catalogued as
 * capability; what ships is the operator's call. Provider-free.
 */
class CloseTacticsLibrary implements PatternLibrary
{
    public function name(): string
    {
        return 'close_tactics';
    }

    public function categories(): array
    {
        return ['assumptive', 'takeaway', 'reframe', 'certainty', 'loss', 'risk_reversal'];
    }

    /**
     * @return array<int,array{key:string,name:string,category:string,weight:int,trigger:string,lever:string,markers:array<int,string>,sales_moment:string,aggression:string}>
     */
    public function all(): array
    {
        return [
            ['key' => 'assumptive_close', 'name' => 'Fechamento assumido', 'category' => 'assumptive', 'weight' => 4, 'sales_moment' => 'close', 'aggression' => 'aggressive',
                'trigger' => 'Falar como se a compra já fosse fato move o cérebro pra logística, não pra decisão de comprar.',
                'lever' => '"Quando você começar…", "na sua primeira semana…", "assim que chegar…" — assume a venda.',
                'markers' => ['when you get started', 'when you start', 'your first week', 'once you have', 'after you order', 'quando você começar', 'na sua primeira semana', 'assim que você']],
            ['key' => 'takeaway_qualification', 'name' => 'Take-away / inversão de qualificação', 'category' => 'takeaway', 'weight' => 5, 'sales_moment' => 'close', 'aggression' => 'dark',
                'trigger' => 'Tirar a oferta inverte a postura — o prospect passa a se VENDER pra você (reatância + escassez de status).',
                'lever' => '"Isso não é pra todo mundo", "deixa eu ver se você se qualifica", "talvez não seja pra você".',
                'markers' => ['this is not for everyone', 'not for everyone', 'this might not be for you', 'only if you', 'see if you qualify', 'isso não é pra todo mundo', 'talvez não seja pra você', 'só se você']],
            ['key' => 'feel_felt_found', 'name' => 'Feel-Felt-Found', 'category' => 'reframe', 'weight' => 4, 'sales_moment' => 'close', 'aggression' => 'standard',
                'trigger' => 'Validar a hesitação + prova social de quem superou desarma sem confrontar.',
                'lever' => '"Eu sei como você se sente; outras sentiram o mesmo; e foi isso que descobriram…"',
                'markers' => ['i know how you feel', 'others felt the same', 'what they found', 'sei como você se sente', 'sentiram o mesmo', 'foi isso que descobriram']],
            ['key' => 'future_pace_close', 'name' => 'Future-pacing no fechamento', 'category' => 'reframe', 'weight' => 4, 'sales_moment' => 'close', 'aggression' => 'standard',
                'trigger' => 'Projetar o leitor no resultado futuro torna a compra o caminho óbvio pra aquela cena.',
                'lever' => '"Imagine 30 dias a partir de hoje, quando…" — vívido, sensorial, posse antecipada.',
                'markers' => ['imagine 30 days from now', 'picture yourself', '30 days from now', 'imagine daqui a', 'se veja daqui']],
            ['key' => 'risk_reversal_close', 'name' => 'Inversão de risco no close', 'category' => 'risk_reversal', 'weight' => 5, 'sales_moment' => 'close', 'aggression' => 'aggressive',
                'trigger' => 'Transferir 100% do risco pro vendedor remove a última objeção lógica de comprar.',
                'lever' => '"Você não arrisca nada — se não X em N dias, devolvo cada centavo / fica com os bônus".',
                'markers' => ['you risk nothing', 'risk-free', 'money-back', 'every penny back', 'keep the bonuses', 'não arrisca nada', 'sem risco', 'devolvo cada centavo', 'fica com os bônus']],
            ['key' => 'cost_of_inaction', 'name' => 'Custo da inação (perda no clímax)', 'category' => 'loss', 'weight' => 5, 'sales_moment' => 'close', 'aggression' => 'aggressive',
                'trigger' => 'Loss aversion é máxima no instante da escolha — o NÃO-agir vira a perda concreta.',
                'lever' => '"O verdadeiro custo é não fazer nada — mais um ano de X, e a conta só cresce."',
                'markers' => ['the real cost is doing nothing', 'cost of doing nothing', 'another year of', 'every day you wait', 'o verdadeiro custo é não', 'mais um ano de', 'cada dia que você espera']],
            ['key' => 'price_per_day_reframe', 'name' => 'Reframe de preço por dia', 'category' => 'reframe', 'weight' => 4, 'sales_moment' => 'close', 'aggression' => 'standard',
                'trigger' => 'Dividir o preço num custo trivial diário neutraliza a dor do número cheio.',
                'lever' => '"Menos que um café por dia", "centavos por dia pra resolver X".',
                'markers' => ['less than a coffee', 'a coffee a day', 'pennies a day', 'cents a day', 'per day', 'menos que um café', 'centavos por dia', 'por dia']],
            ['key' => 'certainty_axis_reclose', 'name' => 'Loop de certeza (Belfort 3 eixos)', 'category' => 'certainty', 'weight' => 5, 'sales_moment' => 'close', 'aggression' => 'dark',
                'trigger' => 'A hesitação cai em 1 dos 3 eixos: certeza no produto, em você (vendedor) ou na empresa. Re-elevar o eixo certo re-fecha.',
                'lever' => 'Diagnostica qual certeza caiu → reframe com prova NOVA naquele eixo → re-pede ("faz sentido? então o próximo passo é…").',
                'markers' => ['does that make sense', 'the only question is', 'the next step is', 'let me ask you', 'faz sentido', 'a única pergunta é', 'o próximo passo é', 'deixa eu te perguntar']],
            ['key' => 'alternative_close', 'name' => 'Fechamento por alternativa', 'category' => 'reframe', 'weight' => 4, 'sales_moment' => 'close', 'aggression' => 'standard',
                'trigger' => 'Reduzir a duas opções (mudar vs continuar na dor) força a escolha e remove o "talvez depois".',
                'lever' => '"Você tem duas escolhas: continuar como está, ou…" — o status quo vira a opção ruim.',
                'markers' => ['you have two choices', 'two options', 'either you', 'keep doing what', 'você tem duas escolhas', 'continuar como está', 'ou você']],
            ['key' => 'objection_preempt_close', 'name' => 'Pré-empção de objeção', 'category' => 'reframe', 'weight' => 4, 'sales_moment' => 'close', 'aggression' => 'standard',
                'trigger' => 'Verbalizar a objeção antes do leitor a desarma — ele não consegue usar o que você já respondeu.',
                'lever' => '"Você deve estar pensando X — e é justo; eis por que não é problema…"',
                'markers' => ['you might be thinking', 'you may be wondering', 'i know what you', 'fair question', 'você deve estar pensando', 'você pode estar se perguntando', 'pergunta justa']],
            ['key' => 'direct_command_close', 'name' => 'Comando direto', 'category' => 'assumptive', 'weight' => 3, 'sales_moment' => 'close', 'aggression' => 'aggressive',
                'trigger' => 'Um comando claro e único remove a paralisia de decisão (o cérebro obedece a instrução simples).',
                'lever' => '"Clique no botão agora", "garanta o seu" — UM comando, sem ramificação.',
                'markers' => ['click the button now', 'claim yours', 'get yours now', 'order now', 'do this now', 'clique no botão', 'garanta o seu', 'peça agora']],
            ['key' => 'social_momentum_close', 'name' => 'Momentum social no close', 'category' => 'reframe', 'weight' => 4, 'sales_moment' => 'close', 'aggression' => 'aggressive',
                'trigger' => 'Mostrar gente agindo AGORA cria FOMO de pertencer ao movimento, não só ao resultado.',
                'lever' => '"Centenas começaram esta semana", "junte-se a X que já…" — a manada já se moveu.',
                'markers' => ['joined this week', 'hundreds started', 'join the', 'others are already', 'começaram esta semana', 'junte-se a', 'já estão']],
        ];
    }
}
