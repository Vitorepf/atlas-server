<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

/**
 * IntentLadderClassifier — Atlas's deterministic, PROVIDER-FREE classifier of a Search keyword's
 * commercial intent for affiliate VSL traffic. It replaces single-axis token-spotting with the
 * COMPOSITIONAL model distilled from the keyword-decision report (docs/affiliate-mastery/
 * search-network-keyword-decision-report.md) and the 5-lens synthesis:
 *
 *   intent_score = tier_base(T0~10 .. T4~92) + 14·pain + 8·specificity   (journey IS the tier; pain &
 *   specificity are bounded modifiers, never requirements; negative polarity caps the score near zero)
 *
 * Three independent axes — collapsing them into one bucket loses money:
 *   • journey       — knowledge→action ladder (Schwartz unaware→most-aware), tiers T0..T4
 *   • pain/urgency  — willingness-to-pay signal ("rápido/de vez/definitivo/now/fast")
 *   • specificity   — long-tail narrows the intent distribution (clean signal for Smart Bidding)
 *
 * Two fixes over the legacy KeywordQualityIndex::intentClass token-spotter, both verified bugs:
 *   1. MULTILINGUAL (PT-BR + EN). The operator's market is BR; an EN-only lexicon is blind to
 *      "como tratar", "tratamento", "o que é", "funciona".
 *   2. NEGATIVE-POLARITY detection. "funciona / does it work / vale a pena" is a buyer trust-check
 *      (POSITIVE), while "cancelar / reembolso / scam / side effects / processar" is a defensive
 *      NON-buyer (NEGATIVE). The legacy code (KeywordIntentMapper) wrongly flagged "funciona" as
 *      scam_complaint; here they are correctly separated.
 *
 * Tiers (journey value): T0 informacional .05 → T1 sintoma .30 → T2 solução .60 →
 * T3 tratamento+urgência .85 → T4 mecanismo/branded/transacional 1.0.
 *
 * Honest limit (report §2): text-only classification has a ~74% ceiling vs human (Jansen 2008).
 * This is a strong PRIOR, not proven conversion — the SERP / real CVR is the oracle. Detection is
 * compositional (PAR modificador×estágio), never bare-substring: "how to" alone is NOT informacional
 * — "how to [get/buy mechanism]" is transactional; only "what is / how does X work" is T0.
 */
class IntentLadderClassifier
{
    use CampaignMathHelper;

    /** L0 KnowledgeCore: as leis que ESTE motor aplica (proveniência por decisão). */
    public const LAWS = ['schwartz-awareness', 'text-intent-ceiling'];

    // T0 — informational / Know-Simple (curioso): wants to KNOW, not resolve → exclude.
    private const INFO = [
        'o que é', 'o que e', 'o que sao', 'o que são', 'o que significa', 'o que causa', 'para que serve',
        'por que', 'porque ', 'definição', 'definicao', 'significado', 'wikipedia', 'quem é', 'quem e',
        'como funciona', 'como saber', 'como sei', 'what is', 'what are', 'meaning of', 'definition',
        'causes of', 'why does', 'how does', 'who is',
    ];

    // T1 — symptom / problem-aware: feels the problem, no solution sought yet.
    private const SYMPTOM = [
        'sintomas de', 'sintoma de', 'sinais de', 'estou com', 'to com', 'tô com', 'não consigo', 'nao consigo',
        'sinto ', 'por que tenho', 'symptoms of', 'signs of', 'why am i', 'i feel', "can't ", 'cant ', 'struggling with',
    ];

    // T2 — solution-aware: accepted the need, hunting a category of solution.
    private const SOLUTION = [
        'tratamento para', 'tratamento de', 'tratamento', 'remédio para', 'remedio para', 'solução para',
        'solucao para', 'como tratar', 'como resolver', 'como curar', 'como acabar com', 'como parar de',
        'como eliminar', 'como reduzir', 'o que tomar para', 'existe cura', 'cura para',
        'treatment for', 'treatment', 'remedy for', 'solution for', 'how to treat', 'how to fix',
        'how to get rid of', 'how to stop', 'how to cure', 'how to lose', 'cure for', 'get rid of', 'help for',
        'medication', 'medicação', 'medicacao', 'medicamento', 'supplement', 'suplemento', 'pills', 'pílula',
    ];

    // Urgency / channel — the pain axis. Also promotes a T2 hit to T3 (treatment + NOW).
    private const URGENCY = [
        'rápido', 'rapido', 'rapidamente', 'agora', 'hoje', 'urgente', 'de vez', 'definitivo', 'definitiva',
        'para sempre', 'pra sempre', 'em casa', 'caseiro', 'caseira', 'natural', 'naturalmente', 'sem receita',
        'sem remédio', 'sem remedio', 'sem cirurgia', 'não aguento', 'nao aguento', 'socorro', 'desesperad',
        'fast', 'now', 'today', 'urgent', 'asap', 'overnight', 'immediately', 'at home', 'naturally',
        'permanently', 'without surgery', 'without exercise', 'desperate', "can't take", 'quick', 'quickly',
    ];

    // T4 — transactional / most-aware: HARD buy-intent only. Device-form words (protocol/protocolo/drops)
    // were moved out — they are mechanism markers (coinedMechanism), so "o que é o protocolo" stays T0.
    private const TRANSACTIONAL = [
        'comprar', 'onde comprar', 'preço', 'preco', 'valor', 'quanto custa', 'pedido', 'site oficial',
        'buy', 'order', 'where to buy', 'price', 'cost', 'official', ' try ',
    ];

    // Buyer trust-check (POSITIVE most-aware) — NOT a complaint. Fixes funciona=scam. "[produto] review(s)"
    // is the safest high-intent window (report §6) — proof-seeking, bottom-funnel, NOT a defensive complaint.
    private const BUYER_CHECK = [
        'funciona', 'funciona mesmo', 'vale a pena', 'é bom', 'e bom', 'resultados', 'depoimento', 'depoimentos',
        'review', 'reviews', 'antes e depois', 'does it work', 'worth it', 'legit', 'real results',
        'before after', 'before and after',
    ];

    // Negative polarity (defensive non-buyer) — cap intent near-zero even with an owned root. Only the
    // UNAMBIGUOUS defensive markers live here; pure safety-checks ("é seguro / is it safe / é perigoso")
    // were REMOVED — near a mechanism they are buyer trust-checks, not defection (panel false-split fix).
    private const NEG_POLARITY = [
        'cancelar', 'como cancelar', 'cancela assinatura', 'reembolso', 'estorno', 'chargeback', 'devolução',
        'devolucao', 'processar', 'processo contra', 'ação judicial', 'acao judicial', 'reclame aqui',
        'reclamação', 'reclamacao', 'efeitos colaterais', 'efeito colateral', 'side effects', 'side effect',
        'scam', 'golpe', 'fraude', 'complaints', 'complaint', 'lawsuit', 'sue ', 'ripoff', 'rip off',
    ];

    private const QUALIFIERS = [
        'women', 'men', 'over 40', 'over 50', 'over 60', 'menopause', 'menopausa', 'belly', 'barriga',
        'at home', 'em casa', 'without exercise', 'sem exercício', 'sem exercicio', 'no injection', 'sem injeção',
        'sem injecao', 'diabético', 'diabetico', 'diabetic',
    ];

    private const STOPWORDS = [
        'the', 'a', 'an', 'to', 'for', 'of', 'and', 'or', 'with', 'in', 'on', 'at', 'is', 'it',
        'o', 'a', 'os', 'as', 'de', 'da', 'do', 'das', 'dos', 'no', 'na', 'para', 'pra', 'com', 'em', 'que', 'um', 'uma',
    ];

    /**
     * @param  array<string,mixed>  $ctx  optional: mechanism_lexicon[], brand_lexicon[] (per-offer, from the dissected VSL)
     * @return array{tier:string,journey:float,pain:float,specificity:float,polarity:string,intent_score:int,wtp_multiplier:float,action:string,confidence:float,lang:string,markers:array<int,string>}
     */
    public function classify(string $keyword, array $ctx = []): array
    {
        $kl = ' '.preg_replace('/\s+/', ' ', mb_strtolower(trim($keyword))).' ';
        $markers = [];

        $mech = array_map(fn ($s) => mb_strtolower((string) $s), (array) ($ctx['mechanism_lexicon'] ?? []));
        $brand = array_map(fn ($s) => mb_strtolower((string) $s), (array) ($ctx['brand_lexicon'] ?? []));

        // --- journey / tier signals (computed first: polarity depends on hasInfo) ---
        $isMech = $this->lexHit($kl, $mech) || $this->coinedMechanism($kl);
        $isBrand = $this->lexHit($kl, $brand);
        $hasTransact = (bool) $this->hits($kl, self::TRANSACTIONAL);
        $urgencyHits = $this->hits($kl, self::URGENCY);
        $qualifierHits = $this->hits($kl, self::QUALIFIERS);
        $hasSymptom = (bool) $this->hits($kl, self::SYMPTOM);
        $hasInfo = (bool) $this->hits($kl, self::INFO);
        // "como X" / "how to X" is action-seeking (solution) UNLESS it is an info pattern
        // ("como funciona" / "how does X work"). This is the cross-vertical generalizer: it lets
        // finance ("como sair das dívidas") and relationship ("como reconquistar meu ex") climb the
        // ladder without a health-specific lexicon — the STRUCTURE generalizes, the verbs don't.
        // A strong QUALIFIER ("sem exercício / para mulheres / 40+") names a solution constraint, so it
        // is solution-aware even without an explicit "como" (panel fix: "emagrecer sem exercício" = T2).
        $howToAction = ! $hasInfo && (str_contains($kl, 'como ') || str_contains($kl, 'how to '));
        $hasSolution = $howToAction || ($qualifierHits !== [] && ! $hasInfo) || (bool) $this->hits($kl, self::SOLUTION);

        // --- polarity: defensive non-buyer vs buyer trust-check ---
        // buyer-check is gated on !hasInfo so "como FUNCIONA X" (informational) is not mistaken for
        // "X FUNCIONA" (a most-aware buyer trust-check).
        $polarity = 'neutral';
        foreach ($this->hits($kl, self::NEG_POLARITY) as $m) {
            $polarity = 'negative';
            $markers[] = 'neg:'.$m;
        }
        $buyerCheck = false;
        if (! $hasInfo) {
            foreach ($this->hits($kl, self::BUYER_CHECK) as $m) {
                $buyerCheck = true;
                $markers[] = 'buyer_check:'.$m;
            }
        }
        if ($buyerCheck && $polarity === 'neutral') {
            $polarity = 'positive';
        }

        // informational dominates a coined-mechanism substring: "o que é o protocolo" is T0, not T4 —
        // but an explicit transactional/branded signal ("comprar / preço / [brand]") still wins.
        if ($hasInfo && ! $hasTransact && ! $isBrand) {
            $tier = 'T0';
            $journey = 0.05;
            $markers[] = 'tier:informational';
        } elseif ($isMech || $isBrand || $hasTransact) {
            $tier = 'T4';
            $journey = 1.0;
            $markers[] = $isBrand ? 'tier:branded' : ($isMech ? 'tier:mechanism' : 'tier:transactional');
        } elseif ($hasSolution && $urgencyHits !== []) {
            $tier = 'T3';
            $journey = 0.85;
            $markers[] = 'tier:treatment+urgency';
        } elseif ($hasSolution) {
            $tier = 'T2';
            $journey = 0.60;
            $markers[] = 'tier:solution';
        } elseif ($buyerCheck) {
            // "[produto] funciona / review" with no other tier marker — most-aware trust-check.
            $tier = 'T4';
            $journey = 0.88;
            $markers[] = 'tier:buyer_check';
        } elseif ($hasSymptom) {
            $tier = 'T1';
            $journey = 0.30;
            $markers[] = 'tier:symptom';
        } else {
            // unknown — treat as low problem-aware, let downstream (provenance / SERP) decide.
            $tier = 'T1';
            $journey = 0.28;
            $markers[] = 'tier:unknown';
        }

        // --- pain / urgency axis (a deadline — "em 7 dias", "24h" — is implicit urgency / WTP) ---
        $deadline = preg_match('/\b\d+\s*(dias?|semanas?|horas?|days?|weeks?|hours?)\b|24\s?h|48\s?h/u', $kl) ? 1 : 0;
        $u = count($urgencyHits) + $deadline;
        $pain = $u === 0 ? 0.0 : $this->clamp01(0.45 + 0.25 * ($u - 1));
        foreach ($urgencyHits as $m) {
            $markers[] = 'pain:'.$m;
        }
        if ($deadline) {
            $markers[] = 'pain:deadline';
        }

        // --- specificity axis ---
        $specificity = $this->specificity($kl, $mech);

        // --- compose: tier base (report §2 table T0~10..T4~92) nudged UP by pain (WTP) + specificity ---
        // journey is the dominant axis (the operator's thesis); pain/specificity are modifiers, never
        // requirements — so a clean solution keyword ("tratamento para X") lands in buy/test even with
        // zero urgency, while desperation ("...rápido de vez") pushes it toward the ceiling.
        $tierBase = ['T0' => 10, 'T1' => 33, 'T2' => 58, 'T3' => 76, 'T4' => 92][$tier] ?? 30;
        $intent = $tierBase + (int) round(14 * $pain) + (int) round(8 * $specificity);
        if ($polarity === 'negative') {
            $intent = min($intent, 12); // defensive searcher — near-exclude even with an owned root
        }
        $intent = max(0, min(100, $intent));

        $wtp = round(1.0 + 0.6 * $pain, 2);

        return [
            'tier' => $tier,
            'journey' => round($journey, 2),
            'pain' => round($pain, 2),
            'specificity' => round($specificity, 2),
            'polarity' => $polarity,
            'intent_score' => $intent,
            'wtp_multiplier' => $wtp,
            'action' => $this->action($tier, $intent, $polarity),
            'confidence' => $this->confidence($tier, $polarity, $hasInfo),
            'lang' => $this->detectLang($kl),
            'markers' => $markers,
        ];
    }

    /**
     * Tier-sensitive action. A problem-aware (T1) searcher with urgency deserves a small TEST, not an
     * outright EXCLUDE — the global 45/70 cut mis-bucketed it. Negative polarity always excludes.
     */
    private function action(string $tier, int $intent, string $polarity): string
    {
        if ($polarity === 'negative') {
            return 'exclude';
        }

        return match ($tier) {
            'T4', 'T3' => $intent >= 60 ? 'buy' : 'test',
            'T2' => $intent >= 70 ? 'buy' : 'test',
            // T1 problem-aware: test só com sinal mínimo. NÃO afrouxar pra <35 — o backtest provou
            // (precisão 0% nos losers) que afrouxar o classificador = gaming de recall; precisão de
            // verdade só vem do flywheel L10 (conversão real down-weighta o loser plausível-mas-não-vende).
            'T1' => $intent >= 35 ? 'test' : 'exclude',
            default => 'exclude', // T0 informacional / Know-Simple
        };
    }

    /**
     * Confidence 0-1 — honest materialization of the ~74% text-only ceiling (report §2). High when the
     * signal is unambiguous (clear informational / clear defensive / journey-saturated most-aware); low
     * when we fell through to the 'unknown' bucket and the real intent needs the SERP / live CVR.
     */
    private function confidence(string $tier, string $polarity, bool $hasInfo): float
    {
        if ($polarity === 'negative') {
            return 0.85;
        }
        if ($tier === 'T4') {
            return 0.85;
        }
        if ($tier === 'T0' || $hasInfo) {
            return 0.8;
        }
        if ($tier === 'T1') {
            return 0.5; // includes the 'unknown' fall-through — weakest prior, defer to SERP/ledger
        }

        return 0.7;
    }

    /** Just the 0-100 intent score (the legacy KeywordQualityIndex::intentClass replacement). */
    public function intentScore(string $keyword, array $ctx = []): int
    {
        return $this->classify($keyword, $ctx)['intent_score'];
    }

    /** @param array<int,string> $lexicon @return array<int,string> matched markers */
    private function hits(string $kl, array $lexicon): array
    {
        $out = [];
        foreach ($lexicon as $t) {
            if ($t !== '' && str_contains($kl, $t)) {
                $out[] = $t;
            }
        }

        return $out;
    }

    /** @param array<int,string> $lex */
    private function lexHit(string $kl, array $lex): bool
    {
        foreach ($lex as $t) {
            $t = trim((string) $t);
            if ($t !== '' && str_contains($kl, $t)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Heuristic for a coined/owned mechanism name with no per-offer lexicon supplied: a multi-word
     * phrase that pairs an exotic modifier with a device noun ("salt trick", "gelatin trick",
     * "hormone drops protocol", "japanese method"). Conservative — only fires on the device-noun tail.
     */
    private function coinedMechanism(string $kl): bool
    {
        // device-noun tail only; the weakest/most generic words ("receita / segredo / hack") were dropped
        // because they false-positive in non-health verticals (cooking "receita de bolo" is not a mechanism).
        return (bool) preg_match('/\b(trick|truque|protocol|protocolo|method|método|metodo|ritual|loophole|formula|fórmula)\b/u', $kl)
            && str_word_count(trim($kl)) >= 2;
    }

    /** @param array<int,string> $mech */
    private function specificity(string $kl, array $mech): float
    {
        $toks = $this->tokens($kl);
        $n = count($toks);
        $qual = $this->lexHit($kl, self::QUALIFIERS) ? 1 : 0;
        $num = preg_match('/\b\d+\b|days?|weeks?|dias?|semanas?|24 ?h|48 ?h/', $kl) ? 1 : 0;
        $m = $this->lexHit($kl, $mech) ? 1 : 0;

        return $this->clamp01(min($n - 1, 4) / 4 * 0.5 + $qual * 0.2 + $num * 0.15 + $m * 0.15);
    }

    private function detectLang(string $kl): string
    {
        $pt = preg_match('/[áàâãéêíóôõúç]/u', $kl)
            || preg_match('/\b(o que|como|tratamento|funciona|comprar|para|sem|você|voce|emagrecer|remédio|remedio|barriga)\b/u', $kl);
        $en = preg_match('/\b(what|how|treatment|buy|for|without|weight|loss|does|work|review)\b/', $kl);

        return $pt && $en ? 'mixed' : ($pt ? 'pt' : 'en');
    }

    /** @return array<int,string> */
    private function tokens(string $s): array
    {
        $raw = preg_split('/[^a-z0-9áàâãéêíóôõúç]+/u', mb_strtolower($s)) ?: [];

        return array_values(array_filter($raw, fn ($t) => $t !== '' && mb_strlen($t) > 1 && ! in_array($t, self::STOPWORDS, true)));
    }
}
