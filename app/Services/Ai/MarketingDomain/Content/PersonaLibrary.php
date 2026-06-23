<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * PersonaLibrary — the niche-keyed catalog of skeptical reader minds + a single generic evaluator.
 *
 * The PersonaSimulator was born health-only (woman 40+, ex-Ozempic, busy mom): every marker it tests
 * is a weight-loss marker, so on a finance or relationship page NOTHING fires and audience_score
 * collapses to ~0 for ANY copy, good or bad. That quietly broke the loop's whole cross-niche proof —
 * the persona dimension was blind everywhere except health.
 *
 * This library fixes that. Each persona is a DATA spec (identity + base reaction + signals that pull
 * her toward watching + penalties that make her close + an objection ladder), evaluated by one generic
 * deterministic engine. New niches are added as data, not code. Provider-free; the psychology is mine,
 * crystallized — not a runtime model's.
 *
 * Health stays in PersonaSimulator's own hand-tuned methods (byte-identical, proven by its tests).
 * This library owns finance, relationship and a universal "generic" panel for any unknown niche.
 */
class PersonaLibrary
{
    /**
     * Map a raw niche label to a persona family. Empty/unknown-health labels stay 'health' so the
     * legacy default (the OT169 proving ground) is preserved; anything else routes to its real panel
     * or, failing that, the generic universal-skeptic panel (so SOME valid read always happens).
     */
    public function resolveFamily(string $niche): string
    {
        $n = mb_strtolower(trim($niche));
        if ($n === '') {
            return 'health';
        }
        $families = [
            'health' => ['health', 'weight', 'weight_loss', 'weightloss', 'diet', 'fitness', 'saude', 'saúde', 'emagrec', 'metabol', 'ozempic', 'glp'],
            'finance' => ['finance', 'financ', 'money', 'wealth', 'invest', 'trading', 'trade', 'crypto', 'forex', 'stocks', 'income', 'renda', 'dinheiro', 'riqueza', 'cripto'],
            'relationship' => ['relationship', 'relacion', 'dating', 'romance', 'love', 'marriage', 'ex back', 'ex-back', 'attraction', 'namoro', 'casamento', 'amor', 'paquera', 'conquista'],
        ];
        foreach ($families as $family => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($n, $needle)) {
                    return $family;
                }
            }
        }

        return 'generic';
    }

    /** Families this library can simulate directly (health is handled by PersonaSimulator itself). */
    public function families(): array
    {
        return ['finance', 'relationship', 'generic'];
    }

    /**
     * Simulate a whole niche panel against the copy.
     *
     * @return array<string,array{will_watch:float,will_close:float,first_objection:string,what_she_needs_next:string,reason:string,slot:string,fix_heading:?string}>
     */
    public function simulate(string $family, string $copy): array
    {
        $text = mb_strtolower($copy);
        $out = [];
        foreach ($this->panel($family) as $spec) {
            $out[$spec['key']] = $this->evaluate($spec, $text);
        }

        return $out;
    }

    /**
     * The persona specs for a family. Falls back to the generic universal-skeptic panel.
     *
     * @return array<int,array<string,mixed>>
     */
    public function panel(string $family): array
    {
        return match ($family) {
            'finance' => $this->financePanel(),
            'relationship' => $this->relationshipPanel(),
            default => $this->genericPanel(),
        };
    }

    /**
     * The generic deterministic engine: base reaction + signals (pull toward watching) - penalties
     * (push toward closing), then walk the objection ladder to surface the first unmet need.
     *
     * @param  array<string,mixed>  $spec
     * @return array{will_watch:float,will_close:float,first_objection:string,what_she_needs_next:string,reason:string,slot:string,fix_heading:?string}
     */
    public function evaluate(array $spec, string $text): array
    {
        $watch = (float) ($spec['base_watch'] ?? 0.2);
        $close = (float) ($spec['base_close'] ?? 0.45);
        $fired = [];

        foreach (($spec['signals'] ?? []) as $sig) {
            // A signal fires only on an UN-negated occurrence — "not realistic" must not count as
            // "realistic".
            if ($this->scan($text, (array) ($sig['markers'] ?? []))['pos'] > 0) {
                $watch += (float) ($sig['watch'] ?? 0);
                $close += (float) ($sig['close'] ?? 0);
                $fired[(string) $sig['id']] = true;
            }
        }
        foreach (($spec['penalties'] ?? []) as $pen) {
            $negatedOnly = false;
            if (isset($pen['len_gt'])) {
                $hit = mb_strlen($text) > (int) $pen['len_gt'];
            } else {
                $scan = $this->scan($text, (array) ($pen['markers'] ?? []));
                $hit = $scan['pos'] > 0;
                // The bad frame appears ONLY negated ("not a get-rich scheme", "no mind games") —
                // that's take-away selling / ethical reassurance, the #1 premium lever. Don't punish
                // it; reward it. This is the negation bug the brutal panel caught.
                $negatedOnly = $scan['pos'] === 0 && $scan['neg'] > 0;
            }
            // A penalty can be suppressed when a redeeming signal already fired (e.g. "vague" only
            // bites when there's also no real proof).
            if ($hit && isset($pen['unless']) && ($fired[(string) $pen['unless']] ?? false)) {
                $hit = false;
            }
            if ($hit) {
                $watch += (float) ($pen['watch'] ?? 0);
                $close += (float) ($pen['close'] ?? 0);
                $fired['!'.(string) $pen['id']] = true;
            } elseif ($negatedOnly) {
                $watch += 0.08;
                $close -= 0.10;
                $fired['~'.(string) $pen['id']] = true;
            }
        }

        [$objection, $next] = $this->walkLadder((array) ($spec['ladder'] ?? []), $fired);

        return [
            'will_watch' => round(max(0.0, min(1.0, $watch)), 2),
            'will_close' => round(max(0.0, min(1.0, $close)), 2),
            'first_objection' => $objection,
            'what_she_needs_next' => $next,
            'reason' => (string) ($spec['reason'] ?? ''),
            'slot' => (string) ($spec['slot'] ?? 'ps'),
            'fix_heading' => $spec['fix_heading'] ?? null,
        ];
    }

    /**
     * Walk an ordered ladder. A step keyed by 'penalty' fires when that penalty hit (priority objection,
     * e.g. cheese present); a step keyed by 'needs' fires when that signal is MISSING; a step with
     * neither is the "all needs met" closer. Ladder order = priority.
     *
     * @param  array<int,array<string,string>>  $ladder
     * @param  array<string,bool>  $fired
     * @return array{0:string,1:string}
     */
    private function walkLadder(array $ladder, array $fired): array
    {
        foreach ($ladder as $step) {
            if (isset($step['penalty'])) {
                if ($fired['!'.$step['penalty']] ?? false) {
                    return [(string) $step['objection'], (string) $step['needs_next']];
                }

                continue;
            }
            if (isset($step['needs'])) {
                if (! ($fired[$step['needs']] ?? false)) {
                    return [(string) $step['objection'], (string) $step['needs_next']];
                }

                continue;
            }

            return [(string) $step['objection'], (string) $step['needs_next']];
        }

        return ['', ''];
    }

    /**
     * Scan markers with NEGATION polarity. Returns how many occurrences are un-negated ('pos') vs
     * negated ('neg', i.e. preceded by not/no/never/sem/não within the same clause). str_contains is
     * polarity-blind — it cannot tell "this is manipulation" from "this is NOT manipulation"; this can.
     *
     * @param  array<int,string>  $markers
     * @return array{pos:int,neg:int}
     */
    private function scan(string $text, array $markers): array
    {
        $pos = 0;
        $neg = 0;
        foreach ($markers as $m) {
            if ($m === '') {
                continue;
            }
            if ($m[0] === '/') {
                if (@preg_match_all($m, $text, $mm, PREG_OFFSET_CAPTURE)) {
                    foreach ($mm[0] as $hit) {
                        $this->isNegated($text, (int) $hit[1]) ? $neg++ : $pos++;
                    }
                }

                continue;
            }
            $needle = mb_strtolower($m);
            $len = strlen($needle);
            $off = 0;
            while (($p = strpos($text, $needle, $off)) !== false) {
                $this->isNegated($text, $p) ? $neg++ : $pos++;
                $off = $p + max(1, $len);
            }
        }

        return ['pos' => $pos, 'neg' => $neg];
    }

    /** True when a negator sits before $bytePos within the same clause (no .!?;: in between). */
    private function isNegated(string $text, int $bytePos): bool
    {
        $start = max(0, $bytePos - 32);
        $window = substr($text, $start, $bytePos - $start);

        return (bool) preg_match(
            '/\b(?:not|no|never|without|isn\'?t|aren\'?t|won\'?t|wont|don\'?t|dont|doesn\'?t|sem|nao|não|nunca|jamais|nem)\b[^.!?;:]*$/u',
            $window
        );
    }

    // ── FINANCE ─────────────────────────────────────────────────────────────────────────────────
    // Offers: trading systems, investing courses, income/wealth programs. The reader's core fear is
    // LOSS, not "another diet". A page that screams "get rich" makes the serious money buyer flee.

    /**
     * @return array<int,array<string,mixed>>
     */
    private function financePanel(): array
    {
        return [
            [
                'key' => 'burned_retiree',
                'reason' => 'Aposentado/a com pé-de-meia: aterrorizado de perder o que levou a vida pra juntar. Vende segurança + track record real + "não é enriquecimento rápido"; ganância explícita o faz fechar na hora.',
                'slot' => 'ps',
                'base_watch' => 0.12, 'base_close' => 0.55,
                'signals' => [
                    ['id' => 'safety', 'markers' => ['protect your capital', 'without risking', 'downside protection', 'preserve your', 'capital protegido', 'sem arriscar', 'proteger seu', 'low risk', 'baixo risco'], 'watch' => 0.25, 'close' => -0.2],
                    ['id' => 'proof', 'markers' => ['track record', 'verified', 'audited', 'brokerage statement', 'as seen on', 'forbes', 'bloomberg', 'wall street journal', 'comprovado', 'auditado', 'extrato'], 'watch' => 0.2, 'close' => -0.15],
                    ['id' => 'realistic', 'markers' => ['realistic', 'not get rich', 'no overnight', 'consistent', 'steady', 'realista', 'consistente', 'aos poucos'], 'watch' => 0.15, 'close' => -0.1],
                    ['id' => 'guarantee', 'markers' => ['money-back', 'refund', '30-day', '60-day', 'garantia', 'reembolso'], 'watch' => 0.12, 'close' => -0.1],
                ],
                'penalties' => [
                    ['id' => 'greed', 'markers' => ['get rich', 'millionaire overnight', 'guaranteed returns', 'guaranteed profit', 'double your money', '100x', 'lambo', 'to the moon', 'fique rico', 'dobre seu dinheiro', 'lucro garantido'], 'watch' => -0.25, 'close' => 0.25],
                ],
                'ladder' => [
                    ['penalty' => 'greed', 'objection' => 'isso cheira a esquema de enriquecimento rápido — vou perder tudo', 'needs_next' => 'cortar a ganância; ancorar em "preservação de capital, não enriquecimento rápido"'],
                    ['needs' => 'safety', 'objection' => 'vão me fazer perder o que levei a vida inteira pra juntar', 'needs_next' => 'precisa de "proteja seu capital / baixo risco" antes da dobra'],
                    ['needs' => 'proof', 'objection' => 'cadê a prova real disso, não só promessa?', 'needs_next' => 'precisa de track record verificado ou extrato auditado'],
                    ['needs' => 'realistic', 'objection' => 'parece bom demais; retorno assim não é realista', 'needs_next' => 'precisa de "retornos consistentes e realistas, não overnight"'],
                    ['objection' => 'tudo bem, mas qual o risco real do meu capital?', 'needs_next' => 'precisa de garantia + cenário de pior-caso explicado'],
                ],
            ],
            [
                'key' => 'crypto_skeptic_lost_money',
                'reason' => 'Já perdeu dinheiro em cripto/golpe de sinais: desconfia de tudo. Vende diferenciação do golpe + estratégia/edge explicada + transparência; "lucro garantido" = veto imediato.',
                'slot' => 'body_sections', 'fix_heading' => 'Why this is not another scam',
                'base_watch' => 0.13, 'base_close' => 0.55,
                'signals' => [
                    ['id' => 'differentiation', 'markers' => ['unlike', 'not another', 'no signals group', 'not a signals', 'ao contrário', 'não é mais um', 'sem grupo de sinais'], 'watch' => 0.22, 'close' => -0.2],
                    ['id' => 'mechanism', 'markers' => ['the exact strategy', 'the system', 'rule-based', 'backtested', 'algorithm', 'the edge', 'estratégia exata', 'backtest', 'sistema de regras'], 'watch' => 0.22, 'close' => -0.15],
                    ['id' => 'proof', 'markers' => ['verified', 'audited', 'track record', 'real results', 'comprovado', 'auditado', 'resultados reais'], 'watch' => 0.18, 'close' => -0.15],
                    ['id' => 'transparency', 'markers' => ['show you exactly', 'full transparency', 'no hidden', 'sem pegadinha', 'transparente', 'mostro exatamente'], 'watch' => 0.12, 'close' => -0.1],
                ],
                'penalties' => [
                    ['id' => 'scam', 'markers' => ['guaranteed profit', 'signals group', 'copy my trades', 'insider', 'pump', '100% win', 'lucro garantido', 'sinais vip', 'copie minhas'], 'watch' => -0.25, 'close' => 0.25],
                ],
                'ladder' => [
                    ['penalty' => 'scam', 'objection' => 'isso é a cara de mais um golpe de sinais', 'needs_next' => 'cortar "lucro garantido/sinais"; mostrar a estratégia de regras'],
                    ['needs' => 'differentiation', 'objection' => 'é só mais um grupo de sinais que vai me quebrar de novo', 'needs_next' => 'precisa de "ao contrário dos grupos de sinais, isto é…"'],
                    ['needs' => 'mechanism', 'objection' => 'cadê a estratégia de verdade, não só promessa?', 'needs_next' => 'precisa do sistema de regras / backtest explicado'],
                    ['needs' => 'proof', 'objection' => 'qualquer um diz que ganha; cadê o extrato?', 'needs_next' => 'precisa de track record verificado'],
                    ['objection' => 'ok, mas e quando o mercado virar contra?', 'needs_next' => 'precisa de gestão de risco / drawdown explicado'],
                ],
            ],
            [
                'key' => 'ambitious_broke_hustler',
                'reason' => 'Quer sair do buraco, pouco capital, iniciante. Vende baixa barreira de entrada + passo-a-passo + números específicos; exigência de muito capital a faz desistir.',
                'slot' => 'kicker',
                'base_watch' => 0.18, 'base_close' => 0.45,
                'signals' => [
                    ['id' => 'low_barrier', 'markers' => ['start with $', 'no experience', 'beginner', 'small account', 'as little as', 'a partir de', 'sem experiência', 'iniciante', 'pouco capital', 'do zero'], 'watch' => 0.25, 'close' => -0.2],
                    ['id' => 'path', 'markers' => ['step-by-step', 'step by step', 'blueprint', 'exact plan', 'passo a passo', 'plano exato', 'roteiro'], 'watch' => 0.18, 'close' => -0.12],
                    ['id' => 'specific', 'markers' => ['/\$\s?\d/', '/\d+\s?%/', 'per month', 'a week', 'por mês', 'por semana'], 'watch' => 0.15, 'close' => -0.1],
                    ['id' => 'time', 'markers' => ['spare time', 'part-time', 'minutes a day', 'tempo livre', 'minutos por dia', 'meio período'], 'watch' => 0.1, 'close' => -0.08],
                ],
                'penalties' => [
                    ['id' => 'gatekeeping', 'markers' => ['you need capital', 'minimum $10,000', 'minimum $5,000', 'advanced only', 'precisa de capital', 'apenas avançado'], 'watch' => -0.2, 'close' => 0.2],
                ],
                'ladder' => [
                    ['penalty' => 'gatekeeping', 'objection' => 'preciso de muito dinheiro que eu não tenho pra começar', 'needs_next' => 'mostrar "comece com pouco / do zero"'],
                    ['needs' => 'low_barrier', 'objection' => 'isso deve exigir capital ou experiência que eu não tenho', 'needs_next' => 'precisa de "comece com $X, sem experiência" no topo'],
                    ['needs' => 'path', 'objection' => 'não sei nem por onde começar', 'needs_next' => 'precisa de "plano passo-a-passo exato"'],
                    ['needs' => 'specific', 'objection' => 'quanto dá pra fazer de verdade com isso?', 'needs_next' => 'precisa de número específico ($/mês realista)'],
                    ['objection' => 'ok, mas isso funciona mesmo pra quem tá começando?', 'needs_next' => 'precisa de caso real de iniciante'],
                ],
            ],
            [
                'key' => 'analytical_diy_investor',
                'reason' => 'Já investe, sofisticado, odeia papo de guru. Valida que a copy tem profundidade (números/metodologia) e não é piegas — cobre o flanco oposto ao desesperado.',
                'slot' => 'body_sections', 'fix_heading' => 'The methodology, in detail',
                'base_watch' => 0.2, 'base_close' => 0.45,
                'signals' => [
                    ['id' => 'depth', 'markers' => ['backtested', 'risk-adjusted', 'asset allocation', 'expected value', 'drawdown', 'sharpe', 'data-driven', 'probabilities', 'metodologia', 'esperança matemática', 'alocação'], 'watch' => 0.25, 'close' => -0.18],
                    ['id' => 'contrarian', 'markers' => ['unlike', 'contrary to', "wall street won't", "what they don't tell", 'ao contrário', 'o que não te contam'], 'watch' => 0.18, 'close' => -0.1],
                    ['id' => 'transparency', 'markers' => ['show the numbers', 'full methodology', 'transparent', 'mostro os números', 'metodologia completa'], 'watch' => 0.12, 'close' => -0.1],
                ],
                'penalties' => [
                    ['id' => 'guru', 'markers' => ['secret millionaire', 'guru', 'get rich', 'life-changing', 'financial freedom forever', 'milagre', 'fórmula secreta'], 'watch' => -0.25, 'close' => 0.25, 'unless' => 'depth'],
                ],
                'ladder' => [
                    ['penalty' => 'guru', 'objection' => 'papo de guru, sem substância', 'needs_next' => 'cortar o hype; mostrar metodologia e números'],
                    ['needs' => 'depth', 'objection' => 'superficial demais, cadê os números/metodologia?', 'needs_next' => 'precisa de detalhe técnico (backtest, drawdown, alocação)'],
                    ['needs' => 'contrarian', 'objection' => 'é o mesmo conselho genérico de sempre', 'needs_next' => 'precisa de um diferencial real "ao contrário de X"'],
                    ['objection' => 'ok, mas qual o edge real e o pior drawdown?', 'needs_next' => 'precisa de transparência total dos números'],
                ],
            ],
            [
                'key' => 'skeptical_spouse_finance',
                'reason' => 'Cônjuge que guarda o orçamento da casa: ROI + garantia + zero armadilha recorrente. Hype vago = veto.',
                'slot' => 'body_sections', 'fix_heading' => 'Why this is zero-risk',
                'base_watch' => 0.1, 'base_close' => 0.6,
                'signals' => [
                    ['id' => 'guarantee', 'markers' => ['money-back', 'refund', '60-day', '30-day', 'no questions', 'garantia', 'reembolso', 'sem perguntas'], 'watch' => 0.25, 'close' => -0.2],
                    ['id' => 'proof', 'markers' => ['study', 'track record', 'as seen on', 'verified', 'estudo', 'comprovado', 'auditado'], 'watch' => 0.2, 'close' => -0.15],
                    ['id' => 'one_time', 'markers' => ['one-time', 'no subscription', 'lifetime', 'sem mensalidade', 'única vez', 'pagamento único'], 'watch' => 0.15, 'close' => -0.12],
                    ['id' => 'roi', 'markers' => ['pays for itself', 'return on', 'se paga', 'retorno sobre'], 'watch' => 0.12, 'close' => -0.1],
                ],
                'penalties' => [
                    ['id' => 'vague', 'markers' => ['change your life', 'financial freedom', 'transform', 'mude sua vida', 'liberdade financeira'], 'watch' => -0.2, 'close' => 0.2, 'unless' => 'proof'],
                ],
                'ladder' => [
                    ['needs' => 'guarantee', 'objection' => 'e se a gente perder esse dinheiro? cadê a garantia?', 'needs_next' => 'precisa de garantia de reembolso visível'],
                    ['needs' => 'proof', 'objection' => 'isso é confiável mesmo ou é cilada?', 'needs_next' => 'precisa de estudo/track record nomeado'],
                    ['needs' => 'one_time', 'objection' => 'isso vira uma mensalidade eterna?', 'needs_next' => 'precisa de "pagamento único, sem assinatura"'],
                    ['objection' => 'ok, mas qual o risco real pro nosso orçamento?', 'needs_next' => 'precisa de ROI explicado + pior-caso'],
                ],
            ],
        ];
    }

    // ── RELATIONSHIP ────────────────────────────────────────────────────────────────────────────
    // Offers: get-ex-back, attraction, dating confidence, save-marriage. Core wounds: loneliness,
    // betrayal, fear of being manipulated. "Mind tricks" copy repels the very people who'd buy.

    /**
     * @return array<int,array<string,mixed>>
     */
    private function relationshipPanel(): array
    {
        return [
            [
                'key' => 'divorced_woman_afraid_alone',
                'reason' => 'Divorciada com medo de que já é tarde / de ficar só. Vende esperança ("nunca é tarde") + reframe ("a culpa não é sua") + relatabilidade; manipulação/piegas a faz fechar.',
                'slot' => 'ps',
                'base_watch' => 0.15, 'base_close' => 0.5,
                'signals' => [
                    ['id' => 'hope', 'markers' => ['never too late', "it's not too late", 'any age', 'at any age', 'nunca é tarde', 'qualquer idade', 'não é tarde'], 'watch' => 0.25, 'close' => -0.2],
                    ['id' => 'reframe', 'markers' => ['not your fault', 'you were never the problem', 'não é sua culpa', 'o problema nunca foi você'], 'watch' => 0.2, 'close' => -0.15],
                    ['id' => 'relatability', 'markers' => ['i was where you are', 'divorced', 'starting over', 'recomeçar', 'também passei', 'divorciada'], 'watch' => 0.15, 'close' => -0.12],
                    ['id' => 'proof', 'markers' => ['testimonial', 'real women', 'case study', 'depoimento', 'mulheres reais', 'caso real'], 'watch' => 0.1, 'close' => -0.08],
                ],
                'penalties' => [
                    ['id' => 'manipulation', 'markers' => ['mind tricks', 'manipulate him', 'make him obsessed', 'control him', 'truques', 'manipular', 'deixá-lo obcecado'], 'watch' => -0.22, 'close' => 0.22],
                    ['id' => 'cheese', 'markers' => ['magic', 'miracle', 'soulmate guaranteed', 'milagre', 'mágica', 'alma gêmea garantida'], 'watch' => -0.15, 'close' => 0.15],
                ],
                'ladder' => [
                    ['penalty' => 'manipulation', 'objection' => 'isso é manipulação barata, não vou fazer joguinho', 'needs_next' => 'cortar "truques/manipular"; usar psicologia honesta'],
                    ['needs' => 'hope', 'objection' => 'já é tarde demais pra mim', 'needs_next' => 'precisa de "nunca é tarde, em qualquer idade"'],
                    ['needs' => 'reframe', 'objection' => 'o problema sou eu, sempre vai dar errado', 'needs_next' => 'precisa de "a culpa nunca foi sua"'],
                    ['needs' => 'relatability', 'objection' => 'essa pessoa não faz ideia do que eu passo', 'needs_next' => 'precisa de "eu também passei por isso / divorciada"'],
                    ['objection' => 'tudo bem, mas isso funciona pra quem já é mais velha?', 'needs_next' => 'precisa de depoimento de mulher específica que recomeçou'],
                ],
            ],
            [
                'key' => 'betrayed_cynic',
                'reason' => 'Foi traído/a e desconfia de tudo. Vende autenticidade ("não é truque") + reconhecimento da dor + prova real; framing de manipulação = veto.',
                'slot' => 'body_sections', 'fix_heading' => 'Why this is real, not manipulation',
                'base_watch' => 0.12, 'base_close' => 0.55,
                'signals' => [
                    ['id' => 'authenticity', 'markers' => ['real psychology', 'not tricks', 'honest', 'genuine', 'no games', 'psicologia real', 'não é truque', 'de verdade', 'sem joguinho'], 'watch' => 0.24, 'close' => -0.2],
                    ['id' => 'acknowledgment', 'markers' => ['the betrayal', 'the hurt', 'what they did to you', 'a traição', 'a dor', 'o que fizeram com você'], 'watch' => 0.18, 'close' => -0.12],
                    ['id' => 'proof', 'markers' => ['testimonial', 'case study', 'research', 'study', 'depoimento', 'caso real', 'estudo'], 'watch' => 0.15, 'close' => -0.12],
                ],
                'penalties' => [
                    ['id' => 'manipulation', 'markers' => ['manipulate', 'mind games', 'tricks to control', 'make them obsessed', 'manipular', 'jogos mentais', 'controlar'], 'watch' => -0.25, 'close' => 0.25],
                    ['id' => 'hype', 'markers' => ['guaranteed', 'instantly', 'overnight', 'garantido', 'instantâneo', 'da noite pro dia'], 'watch' => -0.12, 'close' => 0.12],
                ],
                'ladder' => [
                    ['penalty' => 'manipulation', 'objection' => 'isso é só manipulação, não vou cair de novo', 'needs_next' => 'cortar jargão de manipulação; mostrar psicologia honesta'],
                    ['needs' => 'authenticity', 'objection' => 'parece mais um truque pra me enganar', 'needs_next' => 'precisa de "psicologia real, não truques"'],
                    ['needs' => 'acknowledgment', 'objection' => 'ninguém entende o que eu passei', 'needs_next' => 'precisa nomear a traição/dor que ela viveu'],
                    ['needs' => 'proof', 'objection' => 'cadê a prova de que funciona pra valer?', 'needs_next' => 'precisa de caso real / estudo'],
                    ['objection' => 'ok, mas e se eu confiar e me machucar de novo?', 'needs_next' => 'precisa de reversão de risco emocional / garantia'],
                ],
            ],
            [
                'key' => 'skeptical_man_thinks_manipulation',
                'reason' => 'Acha que é "pickup"/jogo mental. Vende psicologia ética + mecanismo real + resultados; cantada barata = fecha na hora.',
                'slot' => 'body_sections', 'fix_heading' => 'The real psychology, in detail',
                'base_watch' => 0.15, 'base_close' => 0.5,
                'signals' => [
                    ['id' => 'ethical', 'markers' => ['real psychology', 'science of attraction', 'not pickup', 'authentic', 'ethical', 'psicologia real', 'não é cantada', 'autêntico', 'ético'], 'watch' => 0.22, 'close' => -0.18],
                    ['id' => 'mechanism', 'markers' => ['the exact', 'the method', 'step-by-step', 'how it works', 'o método', 'passo a passo', 'como funciona'], 'watch' => 0.18, 'close' => -0.12],
                    ['id' => 'results', 'markers' => ['testimonial', 'case', 'real men', 'results', 'depoimento', 'caso', 'homens reais', 'resultados'], 'watch' => 0.12, 'close' => -0.1],
                ],
                'penalties' => [
                    ['id' => 'pickup', 'markers' => ['pickup', 'negging', 'one weird trick', 'make her chase', 'tricks', 'cantada', 'truque', 'fazer ela correr atrás'], 'watch' => -0.24, 'close' => 0.24],
                    ['id' => 'hype', 'markers' => ['guaranteed', 'any woman', 'instantly', 'garantido', 'qualquer mulher', 'instantâneo'], 'watch' => -0.12, 'close' => 0.12],
                ],
                'ladder' => [
                    ['penalty' => 'pickup', 'objection' => 'isso é papo de cantada barata / pickup', 'needs_next' => 'cortar "truque/cantada"; enquadrar como psicologia real'],
                    ['needs' => 'ethical', 'objection' => 'parece manipulação, não quero isso', 'needs_next' => 'precisa de "psicologia real, não pickup"'],
                    ['needs' => 'mechanism', 'objection' => 'qual é o método de verdade?', 'needs_next' => 'precisa do método passo-a-passo'],
                    ['needs' => 'results', 'objection' => 'igual a 100 outras propagandas', 'needs_next' => 'precisa de caso de homem real'],
                    ['objection' => 'ok, mas isso funciona pra um cara normal como eu?', 'needs_next' => 'precisa de caso relatável + reversão de risco'],
                ],
            ],
            [
                'key' => 'busy_single_parent_relationship',
                'reason' => 'Pai/mãe solo sem tempo: simples + cabe na vida real + prático. Copy longa/complexa sem TL;DR a perde.',
                'slot' => 'kicker',
                'base_watch' => 0.15, 'base_close' => 0.45,
                'signals' => [
                    ['id' => 'time', 'markers' => ['a few minutes', 'simple steps', 'real life', 'busy', 'poucos minutos', 'simples', 'rotina', 'corrida'], 'watch' => 0.25, 'close' => -0.18],
                    ['id' => 'practical', 'markers' => ['exact words', 'what to say', 'scripts', 'step-by-step', 'o que dizer', 'frases exatas', 'passo a passo'], 'watch' => 0.18, 'close' => -0.12],
                ],
                'penalties' => [
                    ['id' => 'too_long', 'len_gt' => 4000, 'watch' => -0.1, 'close' => 0.15],
                    ['id' => 'complex', 'markers' => ['hours of', 'complicated', 'complexo', 'horas de'], 'watch' => -0.1, 'close' => 0.1],
                ],
                'ladder' => [
                    ['penalty' => 'too_long', 'objection' => 'longo demais, não tenho tempo de ler tudo', 'needs_next' => 'precisa de TL;DR / resumo no topo'],
                    ['needs' => 'time', 'objection' => 'isso exige tempo que eu não tenho', 'needs_next' => 'precisa de "poucos minutos, cabe na rotina"'],
                    ['needs' => 'practical', 'objection' => 'ok na teoria, mas o que eu faço na prática?', 'needs_next' => 'precisa de "as palavras exatas / o que dizer"'],
                    ['objection' => 'tudo bem, mas dá pra encaixar na correria?', 'needs_next' => 'precisa de caso de pai/mãe solo ocupado'],
                ],
            ],
            [
                'key' => 'hopeful_romantic_early',
                'reason' => 'Curte autoconhecimento, quer profundidade (psicologia real), detesta piegas. Cobre o flanco oposto ao cético.',
                'slot' => 'body_sections', 'fix_heading' => 'The deeper psychology',
                'base_watch' => 0.2, 'base_close' => 0.45,
                'signals' => [
                    ['id' => 'depth', 'markers' => ['attachment', 'emotional', 'psychology', 'communication', 'vulnerability', 'apego', 'emocional', 'psicologia', 'comunicação', 'vulnerabilidade'], 'watch' => 0.25, 'close' => -0.18],
                    ['id' => 'authentic', 'markers' => ['genuine', 'real connection', 'authentic', 'de verdade', 'conexão real', 'autêntico'], 'watch' => 0.16, 'close' => -0.12],
                    ['id' => 'contrarian', 'markers' => ['unlike', 'what no one tells', 'ao contrário', 'o que ninguém conta'], 'watch' => 0.12, 'close' => -0.08],
                ],
                'penalties' => [
                    ['id' => 'cheese', 'markers' => ['magic', 'miracle', 'one weird trick', 'pickup', 'milagre', 'mágica', 'truque', 'cantada'], 'watch' => -0.25, 'close' => 0.25, 'unless' => 'depth'],
                ],
                'ladder' => [
                    ['penalty' => 'cheese', 'objection' => 'piegas/pickup, não é isso que eu quero', 'needs_next' => 'cortar a pieguice; aprofundar a psicologia'],
                    ['needs' => 'depth', 'objection' => 'superficial, cadê a psicologia de verdade?', 'needs_next' => 'precisa de profundidade (apego, emoção, comunicação)'],
                    ['needs' => 'authentic', 'objection' => 'parece roteiro falso', 'needs_next' => 'precisa de "conexão real, autêntica"'],
                    ['objection' => 'ok, mas isso constrói conexão real?', 'needs_next' => 'precisa de um diferencial "ao contrário de X"'],
                ],
            ],
        ];
    }

    // ── GENERIC ─────────────────────────────────────────────────────────────────────────────────
    // Universal skeptics keyed off niche-agnostic markers (guarantee, proof, mechanism, specificity,
    // brevity, hype). Ensures ANY unknown-niche page gets a meaningful audience read instead of 0.

    /**
     * @return array<int,array<string,mixed>>
     */
    private function genericPanel(): array
    {
        return [
            [
                'key' => 'bargain_skeptic',
                'reason' => 'Cético do valor: quer justificativa de preço + garantia antes de gastar. Preço nu = fecha.',
                'slot' => 'body_sections', 'fix_heading' => 'Why this is worth it',
                'base_watch' => 0.15, 'base_close' => 0.5,
                'signals' => [
                    ['id' => 'value', 'markers' => ['worth', 'value', 'for the price of', 'less than', 'pelo preço de', 'vale', 'menos que'], 'watch' => 0.2, 'close' => -0.15],
                    ['id' => 'guarantee', 'markers' => ['money-back', 'refund', 'guarantee', 'garantia', 'reembolso'], 'watch' => 0.2, 'close' => -0.18],
                    ['id' => 'specific', 'markers' => ['/\d/'], 'watch' => 0.1, 'close' => -0.08],
                ],
                'penalties' => [
                    ['id' => 'hype', 'markers' => ['amazing', 'incredible', 'revolutionary', 'incrível', 'revolucionário'], 'watch' => -0.15, 'close' => 0.15, 'unless' => 'guarantee'],
                ],
                'ladder' => [
                    ['needs' => 'guarantee', 'objection' => 'e se eu pagar e não funcionar? cadê a garantia?', 'needs_next' => 'precisa de garantia de reembolso visível'],
                    ['needs' => 'value', 'objection' => 'não sei se vale o que custa', 'needs_next' => 'precisa de justificativa de valor (vs alternativa cara)'],
                    ['needs' => 'specific', 'objection' => 'tudo vago, nada concreto', 'needs_next' => 'precisa de números específicos'],
                    ['objection' => 'ok, mas qual o risco real de comprar?', 'needs_next' => 'precisa de reversão de risco forte'],
                ],
            ],
            [
                'key' => 'burned_before_cynic',
                'reason' => 'Já tentou algo parecido e falhou. Vende diferenciação + "a culpa não é sua" + prova; hype = fecha.',
                'slot' => 'body_sections', 'fix_heading' => 'Why this is different',
                'base_watch' => 0.13, 'base_close' => 0.5,
                'signals' => [
                    ['id' => 'differentiation', 'markers' => ['unlike', 'not another', 'why this is different', 'ao contrário', 'não é mais um', 'por que isto é diferente'], 'watch' => 0.22, 'close' => -0.18],
                    ['id' => 'reframe', 'markers' => ['not your fault', 'never your fault', 'não é sua culpa', 'a culpa não'], 'watch' => 0.18, 'close' => -0.12],
                    ['id' => 'proof', 'markers' => ['study', 'as seen on', 'verified', 'testimonial', 'estudo', 'comprovado', 'depoimento'], 'watch' => 0.15, 'close' => -0.12],
                ],
                'penalties' => [
                    ['id' => 'hype', 'markers' => ['amazing', 'revolutionary', 'transform your life', 'incrível', 'transforme sua vida'], 'watch' => -0.18, 'close' => 0.18, 'unless' => 'proof'],
                ],
                'ladder' => [
                    ['needs' => 'differentiation', 'objection' => 'já tentei isso e não funcionou', 'needs_next' => 'precisa de "ao contrário do que você tentou…"'],
                    ['needs' => 'reframe', 'objection' => 'o problema deve ser eu mesmo', 'needs_next' => 'precisa de "a culpa nunca foi sua"'],
                    ['needs' => 'proof', 'objection' => 'cadê a prova de que dessa vez é diferente?', 'needs_next' => 'precisa de prova específica'],
                    ['objection' => 'ok, mas por que dessa vez seria diferente?', 'needs_next' => 'precisa de mecanismo nomeado diferenciado'],
                ],
            ],
            [
                'key' => 'time_poor_scanner',
                'reason' => 'Sem tempo: escaneia, quer clareza/brevidade. Parede de texto sem resumo a perde.',
                'slot' => 'kicker',
                'base_watch' => 0.18, 'base_close' => 0.45,
                'signals' => [
                    ['id' => 'brevity', 'markers' => ['in short', 'tl;dr', 'summary', 'in seconds', 'em resumo', 'resumo', 'em segundos'], 'watch' => 0.22, 'close' => -0.15],
                    ['id' => 'clarity', 'markers' => ['simple', 'easy', 'here is how', 'simples', 'fácil', 'veja como'], 'watch' => 0.15, 'close' => -0.1],
                ],
                'penalties' => [
                    ['id' => 'too_long', 'len_gt' => 4500, 'watch' => -0.12, 'close' => 0.18],
                ],
                'ladder' => [
                    ['penalty' => 'too_long', 'objection' => 'longo demais, não tenho tempo de ler tudo', 'needs_next' => 'precisa de TL;DR / resumo no topo'],
                    ['needs' => 'brevity', 'objection' => 'cadê o resumo? não vou ler isso tudo', 'needs_next' => 'precisa de "em resumo / em segundos" no topo'],
                    ['needs' => 'clarity', 'objection' => 'confuso, não entendi o que é', 'needs_next' => 'precisa de "veja como funciona" claro'],
                    ['objection' => 'ok, mas isso me toma quanto tempo?', 'needs_next' => 'precisa deixar o esforço explícito'],
                ],
            ],
            [
                'key' => 'proof_demander',
                'reason' => 'Exige evidência: autoridade + especificidade + prova. Afirmação vaga = não acredita.',
                'slot' => 'body_sections', 'fix_heading' => 'The proof',
                'base_watch' => 0.13, 'base_close' => 0.5,
                'signals' => [
                    ['id' => 'authority', 'markers' => ['dr.', 'study', 'research', 'university', 'as seen on', 'estudo', 'pesquisa', 'universidade', 'médico'], 'watch' => 0.22, 'close' => -0.18],
                    ['id' => 'specific', 'markers' => ['/\d{2,}/'], 'watch' => 0.15, 'close' => -0.1],
                    ['id' => 'evidence', 'markers' => ['testimonial', 'case study', 'verified', 'depoimento', 'caso real', 'comprovado'], 'watch' => 0.15, 'close' => -0.12],
                ],
                'penalties' => [
                    ['id' => 'vague', 'markers' => ['amazing', 'incredible', 'best ever', 'incrível', 'o melhor'], 'watch' => -0.18, 'close' => 0.18, 'unless' => 'authority'],
                ],
                'ladder' => [
                    ['needs' => 'authority', 'objection' => 'cadê a prova de que isso é real?', 'needs_next' => 'precisa de autoridade nomeada (Dr./estudo/mídia)'],
                    ['needs' => 'evidence', 'objection' => 'só promessa, nenhum caso concreto', 'needs_next' => 'precisa de depoimento/caso verificado'],
                    ['needs' => 'specific', 'objection' => 'tudo genérico, nenhum número', 'needs_next' => 'precisa de especificidade numérica'],
                    ['objection' => 'ok, mas esses resultados são típicos?', 'needs_next' => 'precisa de prova representativa, não cereja'],
                ],
            ],
            [
                'key' => 'status_seeker',
                'reason' => 'Quer identidade/exclusividade, à frente da curva. Rejeita pieguice de massa.',
                'slot' => 'body_sections', 'fix_heading' => 'For the few',
                'base_watch' => 0.2, 'base_close' => 0.45,
                'signals' => [
                    ['id' => 'exclusivity', 'markers' => ['inner circle', 'few', 'ahead of', 'before everyone', 'invite', 'círculo', 'poucos', 'à frente', 'antes de todos', 'convite'], 'watch' => 0.22, 'close' => -0.15],
                    ['id' => 'identity', 'markers' => ['people like you', 'for those who', 'the kind of person', 'pessoas como você', 'para quem', 'o tipo de pessoa'], 'watch' => 0.16, 'close' => -0.1],
                ],
                'penalties' => [
                    ['id' => 'cheese', 'markers' => ['everyone', 'miracle', 'magic', 'todo mundo', 'milagre', 'mágica'], 'watch' => -0.18, 'close' => 0.18],
                ],
                'ladder' => [
                    ['penalty' => 'cheese', 'objection' => 'isso é pra massa, piegas demais', 'needs_next' => 'cortar "todo mundo/milagre"; mirar o seleto'],
                    ['needs' => 'exclusivity', 'objection' => 'parece propaganda genérica pra qualquer um', 'needs_next' => 'precisa de exclusividade (círculo/à frente)'],
                    ['needs' => 'identity', 'objection' => 'isso não é pra mim', 'needs_next' => 'precisa de "para quem é como você"'],
                    ['objection' => 'ok, mas o que isso me coloca à frente de quem?', 'needs_next' => 'precisa de vantagem de status concreta'],
                ],
            ],
        ];
    }
}
