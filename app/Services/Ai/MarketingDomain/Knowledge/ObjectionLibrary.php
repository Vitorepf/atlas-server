<?php

namespace App\Services\Ai\MarketingDomain\Knowledge;

/**
 * ObjectionLibrary — every sale faces the same universal objections; naming and crushing each one
 * before the prospect voices it drops resistance to zero. These are the 12 canonical objections in
 * direct response (price, trust, "works for me?", time, "tried everything", spouse, safety, support,
 * "is it too good?", "do it later", competition, identity) — each paired with the neutralization
 * pattern elite copy uses. Content-independent: every market has the same objections, just dressed
 * differently.
 */
class ObjectionLibrary implements PatternLibrary
{
    public function name(): string
    {
        return 'objection';
    }

    /**
     * @return array<int,array{key:string,name:string,category:string,weight:int,trigger:string,lever:string,markers:array<int,string>}>
     */
    public function all(): array
    {
        return [
            ['key' => 'price_too_high', 'name' => 'É caro demais', 'category' => 'price', 'weight' => 5,
                'trigger' => 'O preço parece grande sem contexto de valor. Sem ancoragem, vira "não vale".',
                'lever' => 'Reframe em custo/dia, comparado ao status quo caro (injeção $1k/mês), ou empilhe valor antes de mostrar o preço.',
                'markers' => ['less than', 'menos que', 'cost of', 'custo de', 'per day', 'por dia', 'cheaper than', 'mais barato que', 'fraction of', 'fração do', 'compared to spending']],
            ['key' => 'cant_afford', 'name' => 'Não tenho como pagar', 'category' => 'price', 'weight' => 4,
                'trigger' => 'Mesmo barato, "não cabe no orçamento" — falta sentir que o custo de NÃO comprar é maior.',
                'lever' => 'Mostre o custo de continuar como está (médico, roupas novas, autoestima); ofereça parcelamento.',
                'markers' => ['payment plan', 'parcelamento', 'monthly installments', 'parcelas', 'split into', 'dividido em', 'cost of doing nothing', 'custo de não fazer', "can't afford NOT to"]],
            ['key' => 'wont_work_for_me', 'name' => 'Não vai funcionar pra MIM', 'category' => 'trust', 'weight' => 5,
                'trigger' => '"É diferente pra mim" — idade, condição, histórico. Sem identificação, prova social não vale.',
                'lever' => 'Mostre depoimentos do exato sub-perfil (idade, condição); "se funcionou pra X, funciona pra você".',
                'markers' => ['women over 40', 'mulheres com mais de', 'even if you', 'mesmo se você', 'no matter your', 'não importa sua', 'works for any', 'funciona para qualquer', 'people just like you']],
            ['key' => 'tried_everything', 'name' => 'Já tentei de tudo', 'category' => 'trust', 'weight' => 4,
                'trigger' => 'O cínico de mercado saturado — diet, injeção, app, coach. Sem mecanismo único, vira "mais do mesmo".',
                'lever' => 'Reconheça (foi por isso que não funcionou) + plante o mecanismo único que ataca o que faltava.',
                'markers' => ["if you've tried", 'se você já tentou', 'and nothing worked', 'e nada funcionou', "that's why", 'foi por isso que', 'never had access to', "you weren't given"]],
            ['key' => 'is_it_scam', 'name' => 'Isso é golpe?', 'category' => 'trust', 'weight' => 4,
                'trigger' => 'Tom agressivo + promessas grandes acionam alarme. Sem confronto direto, ela fecha a aba.',
                'lever' => 'Traga a objeção à tona, responda com honestidade + prova + garantia.',
                'markers' => ['is this a scam', 'isso é golpe', 'is it real', 'isso é real', 'you might be thinking', 'você deve estar pensando', "we don't blame you", 'sou cética', 'too good to be true']],
            ['key' => 'no_time', 'name' => 'Não tenho tempo', 'category' => 'effort', 'weight' => 3,
                'trigger' => '"Não dá pra encaixar na vida que já é caótica."',
                'lever' => 'Mostre que cabe nos 3 segundos/manhã, sem mudar rotina; comparar com o tempo que ela já gasta sofrendo.',
                'markers' => ['takes seconds', 'leva segundos', 'no time required', 'sem tempo', 'fits into', 'cabe em', "while you're", 'enquanto você', 'no extra work', 'sem trabalho extra']],
            ['key' => 'is_it_safe', 'name' => 'É seguro?', 'category' => 'safety', 'weight' => 4,
                'trigger' => 'Saúde/finanças tem objeção de risco real — sem credenciais, o cérebro nega.',
                'lever' => 'Lab tested, FDA-registered facility, doctor-formulated, sem efeitos colaterais documentados.',
                'markers' => ['lab-tested', 'testado em lab', 'fda-registered', 'registrado na', 'doctor-formulated', 'formulado por médico', 'no side effects', 'sem efeitos colaterais', 'safe for', 'seguro para', 'gmp-certified']],
            ['key' => 'spouse_partner', 'name' => '"Meu marido/esposa vai concordar?"', 'category' => 'identity', 'weight' => 2,
                'trigger' => 'Aprovação social do parceiro trava a compra — especialmente em saúde/finanças.',
                'lever' => 'Depoimentos com "meu marido viu a diferença", "minha esposa também começou", "ele me apoiou".',
                'markers' => ['my husband', 'meu marido', 'my wife', 'minha esposa', 'my partner', 'meu parceiro', 'family supported', 'família apoiou', 'showed him', 'mostrei pra ele']],
            ['key' => 'need_support', 'name' => 'E se eu precisar de ajuda?', 'category' => 'support', 'weight' => 2,
                'trigger' => 'Medo de comprar e ficar sozinho — sem suporte humano percebido.',
                'lever' => 'Acesso a especialista, grupo privado, suporte 24/7, sessão semanal.',
                'markers' => ['private group', 'grupo privado', 'support team', 'equipe de suporte', '24/7', 'weekly session', 'sessão semanal', 'direct access', 'acesso direto', 'community of']],
            ['key' => 'do_it_later', 'name' => 'Vou pensar (faço depois)', 'category' => 'urgency', 'weight' => 5,
                'trigger' => 'Adiar é a maior morte da venda. Sem custo de adiar, ela fecha e não volta.',
                'lever' => 'Custo de cada dia perdido (resultado adiado, preço sobe, oferta sai do ar, ela continua igual).',
                'markers' => ['every day you wait', 'cada dia que passa', "tomorrow won't be different", 'amanhã não será diferente', 'this page may not be here', 'esta página pode não estar', 'price goes up', 'preço sobe', "won't last"]],
            ['key' => 'competition', 'name' => 'E por que não a concorrência?', 'category' => 'trust', 'weight' => 3,
                'trigger' => 'Comparações com alternativas (Ozempic, app, programa) — sem diferenciação, vira commodity.',
                'lever' => 'Tabela/contraste explícito: o que o outro NÃO faz, o que esta solução faz só ela.',
                'markers' => ['unlike ', 'ao contrário ', 'while others', 'enquanto outros', 'the difference', 'a diferença', 'only this', 'apenas este', 'vs the', 'comparison table', 'better than the', 'melhor que o']],
            ['key' => 'identity_block', 'name' => 'Não sou esse tipo de pessoa', 'category' => 'identity', 'weight' => 3,
                'trigger' => 'A oferta exige uma identidade que ela rejeita ("não sou de comprar isso na internet", "não sou influencer").',
                'lever' => 'Mostre o avatar dela na compra ("mulheres comuns como você", "executivas céticas"), normalize a decisão.',
                'markers' => ['ordinary women', 'mulheres comuns', 'people like you', 'pessoas como você', "you don't need to be", 'você não precisa ser', 'not a guru', 'não é guru', 'regular people', 'gente normal']],
        ];
    }

    /**
     * @return array<int,string>
     */
    public function categories(): array
    {
        return ['price', 'trust', 'effort', 'safety', 'support', 'urgency', 'identity'];
    }
}
