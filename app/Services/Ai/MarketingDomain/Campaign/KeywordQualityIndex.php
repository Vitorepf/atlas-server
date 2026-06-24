<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

use App\Models\AiMarketingVslAsset;

/**
 * KeywordQualityIndex — Atlas's deterministic 0-100 quality/qualification index for a Google Search
 * keyword, distilled from multi-agent research of Google Ads Quality Score (expected CTR / ad
 * relevance / landing-page experience), 2026 match-type + Smart-Bidding reality, and the memory-recall
 * arbitrage pattern. It ELIMINATES waste keywords BEFORE scoring (the operator's law: don't pay to
 * find out a keyword is bad), then scores survivors on 6 weighted components and bands them to an
 * action (scale / launch / test-small / kill). Pure PHP, zero live API — predicts QS from controllable
 * levers (message-match recall against the dissected VSL/bridge, owned-root provenance, intent class).
 *
 * Components (weights from research, normalized to 100): owned_root_provenance .24, intent_class .20,
 * predicted_qs_proxy .22, profit_headroom .14, specificity_match .10, signal_concentration .05.
 */
class KeywordQualityIndex
{
    /** family → provenance base (post-VSL-exposure ownership = the highest-QS/CVR class). */
    private const FAMILY_BASE = [
        'mechanism_trick' => 100, 'slogan' => 92, 'power_phrase' => 85,
        'objection_verification' => 82, 'celebrity' => 78, 'category_alternative' => 55, 'generic' => 15,
    ];

    /** Hard KILL lexicons (waste — eliminated before scoring; emitted as negatives). */
    private const KILL_INFORMATIONAL = ['what is', 'how to', 'why ', 'symptoms', 'causes', 'meaning', 'definition', 'guide', 'tutorial', 'wikipedia'];

    private const KILL_FREE = ['free', 'cheap', 'cheapest', 'discount', 'coupon', 'promo code', 'sample', 'trial', 'torrent', 'download', 'cracked'];

    private const KILL_JOBS = ['jobs', ' job', 'careers', 'salary', 'hiring', 'internship', 'course', 'training', 'degree'];

    private const KILL_RETAIL = ['amazon', 'walmart', 'ebay', 'gnc', 'cvs', 'walgreens', 'costco', 'near me', 'in stores', 'pharmacy'];

    private const KILL_MEDIA = ['meme', 'joke', 'picture', 'photo', 'image', 'lyrics', 'net worth', 'shirt', 'hat', 'news', 'divorce'];

    /** Skeptic/research tokens — allowed ONLY with an owned root (the objection_verification tier). */
    private const SKEPTIC = ['reviews', 'review', 'scam', 'complaints', 'ripoff', 'lawsuit', 'side effects', 'does it work', 'legit', 'real or fake'];

    private const GENERIC_STOP = ['weight loss', 'diet', 'lose weight', 'fat loss', 'belly fat', 'how to lose weight', 'slim'];

    private const DRUGS = ['retatrutide', 'ozempic', 'wegovy', 'mounjaro', 'zepbound', 'semaglutide', 'tirzepatide', 'glp-1', 'glp 1'];

    private const STOPWORDS = ['the', 'a', 'an', 'to', 'for', 'of', 'and', 'or', 'with', 'without', 'in', 'on', 'at', 'is', 'it', 'your', 'you', 'my'];

    private IntentLadderClassifier $intent;

    private KeywordInvestmentGate $gate;

    private KeywordMindState $mindState;

    private KeywordAccountRiskSignal $accountRisk;

    private KeywordOutcomeCalibrator $calibrator;

    public function __construct(?IntentLadderClassifier $intent = null, ?KeywordInvestmentGate $gate = null, ?KeywordMindState $mindState = null, ?KeywordAccountRiskSignal $accountRisk = null, ?KeywordOutcomeCalibrator $calibrator = null)
    {
        $this->intent = $intent ?? new IntentLadderClassifier;
        $this->gate = $gate ?? new KeywordInvestmentGate;
        $this->mindState = $mindState ?? new KeywordMindState;
        $this->accountRisk = $accountRisk ?? new KeywordAccountRiskSignal;
        $this->calibrator = $calibrator ?? new KeywordOutcomeCalibrator;
    }

    /**
     * Score every keyword the QualifiedKeywordPatternEngine produced, eliminating waste first.
     *
     * @param  array<string,mixed>  $engineResult  output of QualifiedKeywordPatternEngine::build()
     * @param  array<string,mixed>  $econ  optional: payout, margin, refund, cvr (enables profit headroom)
     * @return array{scored:array<int,array<string,mixed>>,killed:array<int,array<string,mixed>>,bands:array<string,int>,avg_score:int}
     */
    public function scoreEngineResult(array $engineResult, AiMarketingVslAsset $asset, array $econ = []): array
    {
        $lexicon = $this->assetLexicon($asset);
        $product = (string) ($engineResult['artifacts']['product_name'] ?? '');
        $offerCtx = $this->offerContext($asset, $product);
        $scored = [];
        $killed = [];

        foreach ((array) ($engineResult['tiers'] ?? []) as $tier) {
            $family = (string) ($tier['family'] ?? 'generic');
            $roots = array_map('strval', (array) ($tier['roots'] ?? []));
            $match = (string) ($tier['match_type'] ?? 'phrase');
            $centroid = $this->centroid((array) ($tier['keywords'] ?? []));

            foreach ((array) ($tier['keywords'] ?? []) as $kw) {
                $kw = (string) $kw;
                $kill = $this->eliminate($kw, $family, $roots, $product);
                if ($kill !== null) {
                    $killed[] = ['keyword' => $kw, 'reason' => $kill];

                    continue;
                }
                $scored[] = $this->score($kw, $family, $roots, $match, $centroid, $lexicon, $econ, $offerCtx);
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        $bands = ['scale' => 0, 'launch' => 0, 'test' => 0];
        $sum = 0;
        foreach ($scored as $s) {
            $bands[$s['band'] === 'kill' ? 'test' : $s['band']] = ($bands[$s['band']] ?? 0) + 1;
            $sum += $s['score'];
        }

        return [
            'scored' => $scored,
            'killed' => $killed,
            'bands' => $bands,
            'avg_score' => $scored === [] ? 0 : (int) round($sum / count($scored)),
        ];
    }

    /**
     * Score a single keyword 0-100 on the 6 weighted components.
     *
     * @param  array<int,string>  $roots
     * @param  array<int,string>  $centroid
     * @param  array<int,string>  $lexicon
     * @param  array<string,mixed>  $econ
     * @return array<string,mixed>
     */
    public function score(string $kw, string $family, array $roots, string $match, array $centroid, array $lexicon, array $econ = [], array $offerCtx = []): array
    {
        $kl = mb_strtolower(trim($kw));
        $toks = $this->tokens($kl);

        // 1) owned_root_provenance (.24)
        $base = self::FAMILY_BASE[$family] ?? self::FAMILY_BASE['generic'];
        $provenance = $base / 100;

        // 2) intent — compositional ladder, OFFER-AWARE (the offer's mechanism/brand lexicon feeds T4
        //    detection). Owned-root keywords (post-VSL-exposure, most-aware) keep the 100 override; the
        //    full rationale (tier/journey/pain/polarity/confidence/action) rides along on the output. (.20)
        $intentResult = $this->intent->classify($kl, $offerCtx);
        $intent = $provenance >= 0.78 ? 100 : $intentResult['intent_score'];
        $intentResult['intent_score'] = $intent;

        // 3) predicted_quality_score_proxy (.22)
        $lp = $this->recall($toks, $lexicon);
        $adRel = $this->recall($toks, $this->tokens(implode(' ', $roots)));
        $spec = $this->specificity($kl, $toks);
        $ctrPrior = 0.5 * $provenance + 0.3 * ($intent / 100) + 0.2 * $spec;
        $qsProxy = 0.39 * $ctrPrior + 0.39 * $lp + 0.22 * $adRel;

        // 4) profit_per_click_headroom (.14)
        [$headroom, $unprofitable, $forecastCpc] = $this->headroom($qsProxy, $intent, $econ);

        // 5) specificity_and_match_discipline (.10)
        $matchDisc = ['exact/phrase' => 0.9, 'phrase' => 0.8, 'exact' => 1.0, 'broad' => 0.2][$match] ?? 0.7;
        $specMatch = 0.6 * $spec + 0.4 * $matchDisc;

        // 6) signal_concentration_fit (.05)
        $fit = $centroid === [] ? 0.6 : $this->jaccard($toks, $centroid);

        $points = $provenance * 24 + ($intent / 100) * 20 + $qsProxy * 22
            + $headroom * 14 + $specMatch * 10 + $fit * 5;
        $score = (int) round(($points / 95) * 100);
        if ($unprofitable) {
            $score = min($score, 39);
        }

        // LEARNING LOOP: apply the Bayesian-shrunk proven lift from real outcomes (KeywordLearningLoop)
        // — keywords/families that actually sold rise; proven money-losers fall. Bounded, data-driven.
        $lift = $this->provenLift($kl, $family, $econ['proven_lift'] ?? []);
        if ($lift !== 1.0) {
            $score = (int) round(max(0, min(100, $score * $lift)));
        }

        // L10 FLYWHEEL: peso CVR-lift do RESULTADO REAL (Blackink, read-only) — dá a PRECISÃO que o texto
        // não dá: o loser plausível-mas-não-vende desce. Neutro (1.0) sem dado → determinístico intacto.
        $outcomeWeight = $this->calibrator->weightFor($kl, (array) ($econ['outcome_calibration'] ?? []));
        if ($outcomeWeight !== 1.0) {
            $score = (int) round(max(0, min(100, $score * $outcomeWeight)));
        }

        // INVESTIMENTO vs GASTO: the decision math (breakeven / rule-of-three / EPC). Forecast PRIOR until
        // the operator runs a campaign (the gate labels basis=forecast_prior vs proven, never fakes proof).
        $investment = ($forecastCpc > 0 && isset($econ['payout']))
            ? $this->gate->decide(array_merge($econ, ['cpc' => $forecastCpc]))
            : null;

        return [
            'keyword' => $kw,
            'family' => $family,
            'score' => $score,
            'band' => $this->band($score),
            'match_type' => $match,
            'intent' => $intentResult, // WHY this keyword qualifies: tier/journey/pain/polarity/confidence/action
            'investment' => $investment, // WHY it is investimento vs gasto: breakeven / rule-of-three / EPC
            'mind_state' => $this->mindState->project($intentResult), // keyword→MENTE: awareness/driver/page-angle
            'account_risk' => $this->accountRisk->assess($kw, $offerCtx), // SINAL de morte-de-conta (não freio)
            'outcome_weight' => round($outcomeWeight, 3), // L10: peso da venda real aplicado (1.0 = sem dado)
            'components' => [
                'owned_root_provenance' => round($provenance, 2),
                'intent_class' => $intent,
                'predicted_qs_proxy' => round($qsProxy, 2),
                'lp_experience' => round($lp, 2),
                'profit_headroom' => round($headroom, 2),
                'specificity_match' => round($specMatch, 2),
                'signal_fit' => round($fit, 2),
            ],
        ];
    }

    /**
     * Pre-scoring elimination. Returns a kill reason, or null to keep.
     *
     * @param  array<int,string>  $roots
     */
    private function eliminate(string $kw, string $family, array $roots, string $product): ?string
    {
        $k = ' '.mb_strtolower(trim($kw)).' ';
        $hasRoot = $family !== 'generic' && $family !== 'category_alternative'; // engine families carry an owned root

        if ($product !== '' && str_contains($k, mb_strtolower($product))) {
            return 'product_name (affiliate cannot bid the merchant brand)';
        }
        foreach (self::GENERIC_STOP as $g) {
            if (trim($k) === $g) {
                return 'generic_head (no owned root)';
            }
        }
        foreach (self::DRUGS as $d) {
            if (trim($k) === $d) {
                return 'bare_drug (trademark/policy)';
            }
        }
        foreach (self::KILL_INFORMATIONAL as $t) {
            if (str_contains($k, $t) && ! $hasRoot) {
                return 'informational_intent';
            }
        }
        foreach (self::KILL_FREE as $t) {
            if (str_contains($k, ' '.$t.' ') || str_contains($k, ' '.$t)) {
                return 'free/price_sensitive';
            }
        }
        foreach (self::KILL_JOBS as $t) {
            if (str_contains($k, $t)) {
                return 'jobs';
            }
        }
        foreach (self::KILL_RETAIL as $t) {
            if (str_contains($k, $t)) {
                return 'retail_hijack';
            }
        }
        foreach (self::KILL_MEDIA as $t) {
            if (str_contains($k, $t)) {
                return 'media/entertainment';
            }
        }
        foreach (self::SKEPTIC as $t) {
            if (str_contains($k, $t) && ! $hasRoot) {
                return 'skeptic_without_owned_root';
            }
        }
        // Defensive intent (cancel / refund / complaint / lawsuit) is a NON-buyer even WITH an owned
        // root — kill it before it rides the provenance override (intent=100) to a high score.
        if ($this->intent->classify($kw)['polarity'] === 'negative') {
            return 'defensive_polarity';
        }

        return null;
    }

    /**
     * Per-offer lexicons for the IntentLadderClassifier: the coined mechanism/trick (owned roots that
     * prove VSL exposure) feed T4 detection; the product name feeds branded detection. Makes intent
     * grading OFFER-AWARE instead of relying only on the generic coined-mechanism heuristic.
     *
     * @return array{mechanism_lexicon:array<int,string>,brand_lexicon:array<int,string>}
     */
    private function offerContext(AiMarketingVslAsset $asset, string $product): array
    {
        $mech = array_values(array_filter(
            array_map(fn ($s) => mb_strtolower(trim((string) $s)), [$asset->mechanism_name, $asset->trick]),
            fn ($s) => $s !== '',
        ));
        $brand = $product === '' ? [] : [mb_strtolower(trim($product))];
        $devices = (array) ($asset->persuasion_devices ?? []);
        $celebs = array_values(array_filter(
            array_map(fn ($s) => mb_strtolower(trim((string) $s)), (array) ($devices['authority'] ?? [])),
            fn ($s) => $s !== '',
        ));

        return ['mechanism_lexicon' => $mech, 'brand_lexicon' => $brand, 'celebrity_lexicon' => $celebs];
    }

    private function specificity(string $kl, array $toks): float
    {
        $n = count($toks);
        $qual = preg_match('/\b(women|over 40|over 50|menopause|belly|without exercise|at home|no injection)\b/', $kl) ? 1 : 0;
        $num = preg_match('/\b\d+\b|days?|weeks?|24 hours/', $kl) ? 1 : 0;

        return $this->clamp01(min($n - 1, 4) / 4 * 0.6 + $qual * 0.25 + $num * 0.15);
    }

    /**
     * @param  array<string,mixed>  $econ
     * @return array{0:float,1:bool,2:float}  [headroom, unprofitable, forecast_cpc]
     */
    private function headroom(float $qsProxy, int $intent, array $econ): array
    {
        if ($econ === [] || ! isset($econ['payout'])) {
            return [0.5, false, 0.0]; // neutral when economics not supplied (no fabrication)
        }
        $payout = (float) $econ['payout'];
        $margin = (float) ($econ['margin'] ?? 0.30);
        $refund = (float) ($econ['refund'] ?? 0.10);
        $cvr = (float) ($econ['cvr'] ?? 0.012);
        $net = $payout * (1 - $refund);
        $maxCpa = $net * (1 - $margin);
        $targetCpc = $maxCpa * $cvr;
        $breakevenCpc = $net * $cvr;

        $baseCpc = $intent >= 100 ? 0.6 : ($intent >= 85 ? 1.2 : ($intent >= 65 ? 1.5 : 2.2));
        $mult = $qsProxy >= 0.85 ? 0.5 : ($qsProxy >= 0.70 ? 0.71 : ($qsProxy >= 0.55 ? 0.83 : ($qsProxy >= 0.40 ? 1.0 : 1.67)));
        $forecastCpc = $baseCpc * $mult;

        if ($forecastCpc > $breakevenCpc) {
            return [0.0, true, $forecastCpc];
        }
        $headroom = $this->clamp01(($targetCpc - $forecastCpc) / max($targetCpc, 0.01));

        return [$headroom, false, $forecastCpc];
    }

    /**
     * Look up the learned lift for this keyword: prefer the most specific matching root, else family.
     *
     * @param  array<string,float>  $liftMap  from KeywordLearningLoop::calibrate()['proven_lift']
     */
    private function provenLift(string $kw, string $family, array $liftMap): float
    {
        if ($liftMap === []) {
            return 1.0;
        }
        $best = null;
        $bestLen = -1;
        foreach ($liftMap as $key => $mult) {
            if (str_starts_with($key, 'root:')) {
                $root = mb_substr($key, 5);
                if ($root !== '' && str_contains($kw, $root) && mb_strlen($root) > $bestLen) {
                    $best = (float) $mult;
                    $bestLen = mb_strlen($root);
                }
            }
        }
        if ($best !== null) {
            return $best;
        }

        return (float) ($liftMap['family:'.$family] ?? 1.0);
    }

    private function band(int $score): string
    {
        return match (true) {
            $score >= 80 => 'scale',
            $score >= 60 => 'launch',
            $score >= 40 => 'test',
            default => 'kill',
        };
    }

    /** @return array<int,string> */
    private function assetLexicon(AiMarketingVslAsset $asset): array
    {
        $blob = implode(' ', array_filter([
            (string) $asset->core_promise, (string) $asset->mechanism_name, (string) $asset->angle,
            (string) $asset->hook, (string) $asset->trick, implode(' ', (array) $asset->power_phrases),
        ]));

        return $this->tokens(mb_strtolower($blob));
    }

    /** @param array<int,string> $keywords @return array<int,string> */
    private function centroid(array $keywords): array
    {
        $all = [];
        foreach ($keywords as $k) {
            foreach ($this->tokens(mb_strtolower((string) $k)) as $t) {
                $all[$t] = ($all[$t] ?? 0) + 1;
            }
        }
        arsort($all);

        return array_slice(array_keys($all), 0, 12);
    }

    /** @return array<int,string> */
    private function tokens(string $s): array
    {
        $raw = preg_split('/[^a-z0-9]+/', mb_strtolower($s)) ?: [];

        return array_values(array_filter($raw, fn ($t) => $t !== '' && mb_strlen($t) > 1 && ! in_array($t, self::STOPWORDS, true)));
    }

    /** recall = |a ∩ b| / |b|  (how much of the reference b the keyword a covers) */
    private function recall(array $a, array $b): float
    {
        if ($b === []) {
            return 0.0;
        }
        $set = array_flip($a);
        $hit = 0;
        foreach (array_unique($b) as $t) {
            if (isset($set[$t])) {
                $hit++;
            }
        }

        return $this->clamp01($hit / count(array_unique($b)));
    }

    private function jaccard(array $a, array $b): float
    {
        $a = array_unique($a);
        $b = array_unique($b);
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $inter = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union === 0 ? 0.0 : $inter / $union;
    }

    private function clamp01(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }
}
