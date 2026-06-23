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

            // ── Aprofundamento Volta 2: objeções raras + neutralizadores avançados ────────────
            ['key' => 'preemptive_disqualification', 'name' => 'Desqualificação preemptiva', 'category' => 'trust', 'weight' => 5,
                'trigger' => '"Não é pra todo mundo" elimina os que iam reclamar + faz quem fica querer MAIS — Cialdini scarcity de identidade.',
                'lever' => '"Se você é X (perfil errado), feche esta aba" — antes de qualquer oferta.',
                'markers' => ['not for everyone', 'não é pra todo mundo', "if you're looking for", 'se você procura', 'do not buy if', 'não compre se', 'this is wrong for', 'isto está errado para', 'close this page if', 'feche esta página se']],
            ['key' => 'agitate_past_failures', 'name' => 'Agita falhas passadas (sem culpar)', 'category' => 'trust', 'weight' => 4,
                'trigger' => 'Lembrar tudo que ela já tentou — SEM culpá-la — abre o sim pra mais uma tentativa diferente.',
                'lever' => '"Você fez tudo direito. Não foi você. Foi o método errado."',
                'markers' => ['you did everything', 'você fez tudo', 'it was never you', 'nunca foi você', "wasn't your fault", 'não foi sua culpa', 'you were told', 'te disseram', 'they failed you', 'eles falharam com você']],
            ['key' => 'authority_proof_layered', 'name' => 'Prova em camadas (estudo+médico+caso)', 'category' => 'trust', 'weight' => 4,
                'trigger' => 'Empilhar 3 tipos de prova (estudo + autoridade + caso real) cobre todos os céticos — quem rejeita um aceita outro.',
                'lever' => 'Cite estudo + nomeie médico + conte caso real, em sequência rápida.',
                'markers' => ['according to a study', 'segundo um estudo', 'as dr. ', 'como o dr. ', 'in the case of', 'no caso de', 'one woman from', 'uma mulher de', 'documented in ', 'documentado em']],
            ['key' => 'sunk_cost_reframe', 'name' => 'Reframe do custo afundado', 'category' => 'price', 'weight' => 4,
                'trigger' => 'Lembra quanto ela JÁ GASTOU em soluções que falharam — faz o preço atual parecer ridículo em comparação.',
                'lever' => '"Você já gastou $X em dietas/injeções/programas. Isto custa Y% disso e funciona".',
                'markers' => ['already spent', 'já gastou', "you've invested", 'já investiu', 'compared to what', 'comparado ao que', 'over the years', 'ao longo dos anos', 'after all those', 'depois de todos esses', 'fraction of what']],
            ['key' => 'normalize_skepticism', 'name' => 'Normaliza o ceticismo', 'category' => 'trust', 'weight' => 4,
                'trigger' => '"Eu também era cética" cria identificação imediata e baixa a guarda — o leitor sente "ela me entende".',
                'lever' => '"Eu também não acreditei. Olha o que mudou minha cabeça…"',
                'markers' => ["i didn't believe", 'eu não acreditei', "i was skeptical", 'eu era cética', 'me too', 'eu também', "i'd be skeptical", 'eu seria cética', 'good — be skeptical', 'ótimo, seja cética']],
            ['key' => 'show_the_math', 'name' => 'Mostra a matemática do custo', 'category' => 'price', 'weight' => 3,
                'trigger' => 'Quebrar o preço em custo/dia/uso desarma "é caro" — "$1/dia" é mais aceito que "$30/mês".',
                'lever' => '"$97 ÷ 30 dias = R$3,23/dia. Menos que um café".',
                'markers' => ['less than a coffee', 'menos que um café', 'cost per day', 'custo por dia', 'comes out to', 'dá uns', '/\$\d{1,2}(\.\d{1,2})?\s*per (day|month|week)/iu', 'cheaper than', 'mais barato que']],
            ['key' => 'address_obvious_objection', 'name' => 'Aborda a objeção óbvia', 'category' => 'trust', 'weight' => 5,
                'trigger' => 'Nomear a objeção que o leitor está pensando NESTE momento ("você deve estar pensando…") mostra leitura mental.',
                'lever' => '"Você deve estar pensando: \'mas e se X?\' Boa pergunta. Aqui está a resposta…"',
                'markers' => ["you're probably thinking", 'você deve estar pensando', "you may be wondering", 'você pode estar se perguntando', 'good question', 'boa pergunta', "let's address that", 'vamos tratar disso', 'i know what you\'re', 'eu sei o que você']],
            ['key' => 'guarantee_with_consequence', 'name' => 'Garantia com consequência real', 'category' => 'safety', 'weight' => 4,
                'trigger' => 'Garantia comum (devolução) é commodity — adicionar consequência real ("e te pago $X de mimo") trava o cético.',
                'lever' => '"60 dias, devolvemos + R$200 pelo seu tempo + você fica com tudo".',
                'markers' => ['plus we pay', 'pagamos a mais', 'on top of refund', 'além do reembolso', 'keep everything', 'fique com tudo', "we'll pay you", 'te pagamos', 'no risk at all', 'sem risco nenhum']],
            ['key' => 'objection_via_3rd_party', 'name' => 'Objeção pela voz de 3ª pessoa', 'category' => 'trust', 'weight' => 3,
                'trigger' => 'Cita a objeção como "outra cliente me perguntou" — sai do confronto direto, fica mais aceitável.',
                'lever' => '"Uma leitora me escreveu perguntando: \'mas e se eu tomar com remédio?\' Boa pergunta dela. A resposta é…"',
                'markers' => ['a reader asked', 'uma leitora perguntou', 'a customer wrote', 'uma cliente escreveu', "someone asked me", 'alguém me perguntou', 'i was asked', 'me perguntaram', "let me share what", 'deixa eu compartilhar']],
            ['key' => 'objection_about_objection', 'name' => 'Meta-objeção: "tudo parece bom demais"', 'category' => 'trust', 'weight' => 3,
                'trigger' => 'A objeção mais perigosa é a meta: "tudo isso junto parece bom demais pra ser verdade".',
                'lever' => 'Reconheça explicitamente: "eu sei como soa. Eu também acharia bom demais. Por isso a garantia é assim."',
                'markers' => ['too good to be true', 'bom demais pra ser verdade', "i know how this sounds", 'eu sei como isto soa', 'sounds unreal', 'parece irreal', "that's exactly why", 'é exatamente por isso']],
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
