<?php

namespace App\Services\Ai\MarketingDomain\Knowledge;

/**
 * PagePatternLibrary — the executable "winning page patterns" skill. Encodes how an aggressive bridge
 * / VSL page should be SHAPED for the audience: by gender (a weight-loss page for women reads nothing
 * like a prostate page for men), by awareness (Schwartz), by sophistication, and by traffic temperature
 * — plus the arsenal of brutal direct-response sales mechanisms. Deterministic; this is structured
 * domain knowledge the composer injects so the model builds to a proven blueprint, not from scratch.
 */
class PagePatternLibrary
{
    /**
     * Infer the audience profile (gender + the gender playbook) from the dissected avatar/niche.
     *
     * @param  array<string,mixed>|null  $avatar
     * @return array<string,mixed>
     */
    public function audienceProfile(?array $avatar, ?string $niche): array
    {
        $gender = $this->inferGender($avatar, $niche);

        return [
            'gender' => $gender,
            'playbook' => $this->genderPlaybook($gender),
        ];
    }

    /**
     * The structural blueprint for a bridge advertorial: folds, length, lead, tone, mandatory triggers.
     *
     * @return array<string,mixed>
     */
    public function bridgeBlueprint(string $gender, ?string $awareness, ?string $sophistication, string $temperature = 'cold'): array
    {
        $aw = $this->awarenessShape($awareness);
        $soph = $this->sophisticationShape($sophistication);
        // Colder traffic (YouTube/Demand Gen) needs a longer warm-up; hotter search traffic converts shorter.
        $words = $temperature === 'cold' ? '900–1.500 palavras' : '500–900 palavras';
        $folds = $temperature === 'cold' ? 7 : 5;

        return [
            'format' => 'listicle/story advertorial',
            'fold_count' => $folds,
            'word_target' => $words,
            'fold_structure' => $this->foldStructure($gender, $folds),
            'lead_type' => $aw['lead'],
            'lead_move' => $aw['move'],
            'sophistication_move' => $soph,
            'tone' => $this->genderPlaybook($gender)['tone'],
            'mandatory_triggers' => $this->genderPlaybook($gender)['triggers'],
            'note' => 'Cada dobra abre um open-loop que só a VSL fecha — a bridge nunca entrega o mecanismo completo.',
        ];
    }

    /**
     * The brutal sales mechanisms arsenal — the aggressive direct-response levers, with when-to-use.
     *
     * @return array<int,array<string,string>>
     */
    public function brutalMechanisms(): array
    {
        return [
            ['name' => 'Pattern interrupt', 'use' => 'Abertura inesperada (manchete/cena) que quebra o scroll automático e força a leitura.'],
            ['name' => 'Avatar call-out', 'use' => 'Nomear o prospect com especificidade ("se você é mulher acima de 40 e já tentou X, Y, Z") — espelho identitário.'],
            ['name' => 'Open loop / curiosity gap', 'use' => 'Abrir uma pergunta a cada dobra e prometer a resposta "na apresentação" — puxa pro vídeo.'],
            ['name' => 'Visceral agitation', 'use' => 'Tornar a dor concreta, sensorial e presente (o espelho, a roupa, o olhar dos outros) antes de oferecer alívio.'],
            ['name' => 'Common enemy', 'use' => 'Externalizar a culpa (big pharma, indústria, sistema) — tira a vergonha do prospect e cria "nós contra eles".'],
            ['name' => 'Unique mechanism', 'use' => 'Nomear o "como" proprietário (o mecanismo) — destrava nichos saturados; o prospect não viu ISSO antes.'],
            ['name' => 'Reason why', 'use' => 'Dar a razão lógica que sustenta a promessa absurda (por que funciona, por que é barato, por que agora).'],
            ['name' => 'Future pacing', 'use' => 'Fazer o prospect VIVER o resultado no futuro (a foto daqui a 60 dias) antes de comprar.'],
            ['name' => 'Specific proof', 'use' => 'Prova social com números exatos, nomes e detalhes verificáveis-soando (não "muita gente" — "Amy, 47, −34 lb em 8 semanas").'],
            ['name' => 'Authority borrow', 'use' => 'Emprestar credibilidade de figura/instituição (como GANCHO de notícia, atribuído — não como afirmação médica na bridge).'],
            ['name' => 'Scarcity & urgency', 'use' => 'Estoque/lote/janela reais e específicos — medo de perda movido por tempo/quantidade.'],
            ['name' => 'Cliffhanger pre-CTA', 'use' => 'Logo antes do CTA, abrir a maior lacuna ("a parte que chocou o estúdio vem agora") — empurra o play.'],
            ['name' => 'Damaging admission', 'use' => 'Admitir algo "contra" a oferta para comprar credibilidade ("não é mágica, leva 2 min por noite").'],
            ['name' => 'Us-vs-them identity', 'use' => 'Criar pertencimento ("as mulheres que descobriram isso") — o prospect quer estar do lado certo.'],
        ];
    }

    // ---- internals -------------------------------------------------------------------------

    /**
     * @param  array<string,mixed>|null  $avatar
     */
    private function inferGender(?array $avatar, ?string $niche): string
    {
        $blob = mb_strtolower(json_encode($avatar ?: [], JSON_UNESCAPED_UNICODE).' '.(string) $niche);
        // WORD-BOUNDARY counting — "female" must NOT count as "male", "women" must NOT count as "men".
        $female = ['woman', 'women', 'female', 'females', 'mulher', 'mulheres', 'mae', 'mãe', 'menopause', 'menopausa', 'pcos', 'pregnancy', 'pregnant', 'gravida', 'wife', 'beauty', 'skincare', 'feminine'];
        $male = ['man', 'men', 'male', 'males', 'homem', 'homens', 'prostate', 'prostata', 'erectile', 'testosterone', 'husband', 'masculine'];
        $maleNiches = ['prostate', 'ed', 'erectile', 'testosterone'];

        $count = static function (string $word) use ($blob): int {
            return preg_match_all('/\b'.preg_quote($word, '/').'\b/u', $blob);
        };
        $fScore = 0;
        $mScore = 0;
        foreach ($female as $w) {
            $fScore += $count($w);
        }
        foreach ($male as $w) {
            $mScore += $count($w);
        }
        if (in_array((string) $niche, $maleNiches, true)) {
            $mScore += 3;
        }

        if ($fScore === $mScore) {
            return 'neutral';
        }

        return $fScore > $mScore ? 'female' : 'male';
    }

    /**
     * @return array<string,mixed>
     */
    private function genderPlaybook(string $gender): array
    {
        return match ($gender) {
            'female' => [
                'tone' => 'empático e caloroso, MAS urgente — fala de mulher para mulher; valida a frustração antes de prometer',
                'angles' => ['identidade/auto-imagem', 'relacionamento e olhar dos outros', 'transformação e recomeço', 'vergonha → esperança', 'não-é-sua-culpa (hormonal)'],
                'triggers' => ['call-out identitário', 'prova social de pares (mulheres reais)', 'narrativa emocional/história', 'inimigo comum (indústria que culpa a mulher)', 'future pacing visual (a foto, a roupa)'],
                'avoid' => 'tom técnico-frio, números sem emoção, culpar o prospect',
            ],
            'male' => [
                'tone' => 'direto, confiante e factual — performance e controle; menos relacional, mais "resolve o problema"',
                'angles' => ['desempenho/capacidade', 'status e controle', 'medo de falha/declínio', 'mecanismo técnico/científico', 'recuperar o que perdeu'],
                'triggers' => ['dado/mecanismo técnico', 'prova científica/autoridade', 'medo de perda (status/saúde)', 'inimigo comum (sistema/médicos)', 'razão-porquê lógica'],
                'avoid' => 'excesso de emoção/floreio, história longa sem payoff, linguagem de auto-ajuda',
            ],
            default => [
                'tone' => 'direto e empático equilibrado — lidere pelo mecanismo e pela prova',
                'angles' => ['mecanismo único', 'transformação', 'inimigo comum'],
                'triggers' => ['call-out', 'mecanismo único', 'prova específica', 'open loops'],
                'avoid' => 'generalidade vaga',
            ],
        };
    }

    /**
     * @return array<int,string>
     */
    private function foldStructure(string $gender, int $folds): array
    {
        $base = [
            'Dobra 1 — HOOK + call-out identitário do avatar (pattern interrupt; abre o loop principal)',
            'Dobra 2 — AGITAÇÃO visceral da dor + "não é sua culpa" (inimigo comum)',
            'Dobra 3 — o MECANISMO único (tease, sem entregar) — por que isso funciona quando nada funcionou',
            'Dobra 4 — PROVA específica (depoimentos/números) + autoridade emprestada',
            'Dobra 5 — CLIFFHANGER + ponte pro vídeo (CTA: assistir a apresentação)',
        ];
        if ($folds >= 6) {
            array_splice($base, 3, 0, ['Dobra extra — por que as soluções comuns (dietas/pílulas/injeções) FALHAM']);
        }
        if ($folds >= 7) {
            array_splice($base, 5, 0, ['Dobra extra — future pacing (a vida daqui a 60 dias) + reforço de escassez']);
        }

        return $base;
    }

    /**
     * @return array<string,string>
     */
    private function awarenessShape(?string $awareness): array
    {
        $a = mb_strtolower(trim((string) $awareness));

        return match (true) {
            str_contains($a, 'unaware') => ['lead' => 'story', 'move' => 'abra com história/cena — o prospect não sabe que tem o problema'],
            str_contains($a, 'problem') => ['lead' => 'problem-solution', 'move' => 'agite a DOR antes de qualquer solução'],
            str_contains($a, 'solution') => ['lead' => 'big_secret/mechanism', 'move' => 'lidere pelo MECANISMO único (o "como" diferente)'],
            str_contains($a, 'product') => ['lead' => 'offer/proof', 'move' => 'lidere com prova e diferencial do produto'],
            str_contains($a, 'most') => ['lead' => 'offer', 'move' => 'oferta/desconto direto — já está pronto pra agir'],
            default => ['lead' => 'big_secret/mechanism', 'move' => 'lidere pelo mecanismo único (padrão para tráfego frio de saúde)'],
        };
    }

    private function sophisticationShape(?string $sophistication): string
    {
        $s = trim((string) $sophistication);

        return match (true) {
            str_contains($s, '5') || str_contains(mb_strtolower($s), 'identi') => 'Estágio 5: lidere pela IDENTIDADE/identificação do prospect + mecanismo nomeado (mercado saturado)',
            str_contains($s, '4') || str_contains(mb_strtolower($s), 'unique') => 'Estágio 4: mecanismo ÚNICO/nomeado (mecanismos comuns cansados)',
            str_contains($s, '3') || str_contains(mb_strtolower($s), 'mechan') => 'Estágio 3: lidere pelo MECANISMO (claims cansados)',
            str_contains($s, '2') => 'Estágio 2: claim ampliado/maior',
            str_contains($s, '1') => 'Estágio 1: claim direto (mercado virgem)',
            default => 'Estágio 4-5: mecanismo único nomeado + identidade (saúde/peso = saturado)',
        };
    }
}
