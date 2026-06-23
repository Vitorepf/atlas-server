<?php

namespace App\Services\Ai\MarketingDomain\Decision;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;

/**
 * OfferDoctorScorer — the offer-doctor skill made executable. Scores a REAL extracted VSL offer
 * on Hormozi's Value Equation (4 terms, 0-10 each), finds the weakest term and whether the lever
 * is numerator (raise) or denominator (shrink), computes Life-Force-8 coverage, and returns an
 * overall offer_readiness 0-100. Deterministic marker scoring (same asset → same score), no DB.
 *
 * The MarketingSymptomActionTree's checkout_rate branch already NAMES "offer-doctor (Value Equation)"
 * as its lever — this is the executable scorer behind it. Term keys mirror MarketingPlaybook::valueEquation().
 */
class OfferDoctorScorer
{
    private const DREAM_MARKERS = ['transform', 'finally', 'dream', 'life-changing', 'freedom', 'body', 'results', 'reverse', 'restore', 'transformação', 'liberdade'];

    private const PROOF_MARKERS = ['study', 'studies', 'clinical', 'proven', 'research', 'doctor', 'scientist', 'testimonial', 'guarantee', 'fda', 'university', 'results', 'estudo', 'comprov', 'médico'];

    private const IMMEDIACY_MARKERS = ['fast', 'quick', 'rapid', 'days', 'immediately', 'overnight', 'minutes', 'instant', 'weeks', 'today', 'right now', 'rápido', 'dias', 'imediat'];

    private const EASY_MARKERS = ['just', 'simple', 'easy', 'without', 'effortless', 'no diet', 'no exercise', 'done for you', 'automatic', 'sem esforço', 'simples', 'fácil'];

    private const HARD_MARKERS = ['strict', 'workout', 'exercise', 'every day', 'discipline', 'gym', 'restrict', 'starve', 'dieta rígida', 'academia'];

    /** Life-Force-8 detection markers per desire key. */
    private const LF8_MARKERS = [
        'survival' => ['live longer', 'health', 'disease', 'die', 'survive', 'longevity', 'saúde', 'viver'],
        'food_drink' => ['eat', 'food', 'delicious', 'craving', 'meal', 'comer', 'comida'],
        'freedom_from_fear' => ['pain', 'fear', 'safe', 'danger', 'relief', 'worry', 'dor', 'medo', 'alívio'],
        'sexual' => ['attractive', 'confidence', 'desire', 'sexy', 'libido', 'atraente', 'confiança'],
        'comfort' => ['comfortable', 'easy', 'convenient', 'effortless', 'confortável', 'conveniente'],
        'superiority' => ['better', 'win', 'best', 'superior', 'outperform', 'melhor', 'vencer'],
        'protect_loved' => ['family', 'children', 'protect', 'loved ones', 'kids', 'família', 'filhos', 'proteger'],
        'social_approval' => ['admire', 'approval', 'respect', 'belong', 'judged', 'aprovação', 'respeito'],
    ];

    public function __construct(private readonly MarketingPlaybook $playbook = new MarketingPlaybook) {}

    /**
     * @return array<string,mixed>
     */
    public function score(AiMarketingVslAsset $asset): array
    {
        $transcript = strtolower((string) $asset->transcript);
        $ve = is_array($asset->value_equation) ? $asset->value_equation : [];
        $offer = is_array($asset->offer) ? $asset->offer : [];
        $claims = is_array($asset->claims) ? $asset->claims : [];
        $hasTranscript = trim($transcript) !== '';

        $terms = [
            'dream_outcome' => $this->scoreDream($asset, $ve, $transcript),
            'perceived_likelihood' => $this->scoreLikelihood($claims, $transcript),
            'time_delay' => $this->scoreImmediacy($ve, $transcript),
            'effort_sacrifice' => $this->scoreEffortlessness($ve, $transcript),
        ];

        // Weakest term → the lever to pull (numerator raise vs denominator shrink).
        asort($terms);
        $weakest = (string) array_key_first($terms);
        $veKnowledge = $this->playbook->valueEquation();
        $side = (string) ($veKnowledge['terms'][$weakest]['side'] ?? 'numerador');
        $lever = $side === 'denominador'
            ? ($veKnowledge['terms'][$weakest]['shrink'] ?? 'reduzir tempo/esforço')
            : ($veKnowledge['terms'][$weakest]['raise'] ?? 'aumentar resultado/probabilidade');

        $lf8 = $this->scoreLifeForce8($transcript);
        $lf8Triggered = array_keys(array_filter($lf8));

        $readiness = (int) round((array_sum($terms) / 40) * 100);

        return [
            'vsl_id' => $asset->id,
            'has_transcript' => $hasTranscript,
            'offer_readiness' => $hasTranscript ? $readiness : 0,
            'value_equation_terms' => $terms,
            'weakest_term' => $weakest,
            'weakest_side' => $side,
            'recommended_lever' => $lever,
            'value_equation_insight' => $veKnowledge['insight'],
            'life_force_8' => $lf8,
            'life_force_8_triggered' => $lf8Triggered,
            'life_force_8_missing' => array_values(array_diff(array_keys($lf8), $lf8Triggered)),
            'grand_slam_fix' => $this->grandSlamFix($weakest),
            'note' => $hasTranscript
                ? 'Score determinístico do offer (Value Equation + LF8) sobre a VSL real — alavanca no termo mais fraco.'
                : 'VSL sem transcript — rode a extração antes de pontuar o offer.',
        ];
    }

    /**
     * @param  array<string,mixed>  $ve
     */
    private function scoreDream(AiMarketingVslAsset $asset, array $ve, string $transcript): int
    {
        $s = 0;
        if ($this->filled($ve['dream_outcome'] ?? null) || trim((string) $asset->core_promise) !== '' || trim((string) $asset->big_idea) !== '') {
            $s += 4;
        }
        $s += min(4, $this->countMarkers($transcript, self::DREAM_MARKERS));
        // Specificity: a concrete number in the promise reads as a bigger, more believable dream.
        if (preg_match('/\d/', (string) $asset->big_idea.' '.(string) $asset->core_promise.' '.implode(' ', (array) ($ve['dream_outcome'] ?? [])))) {
            $s += 2;
        }

        return min(10, $s);
    }

    /**
     * @param  array<int,mixed>  $claims
     */
    private function scoreLikelihood(array $claims, string $transcript): int
    {
        return min(10, min(4, count($claims)) + min(6, $this->countMarkers($transcript, self::PROOF_MARKERS)));
    }

    /**
     * @param  array<string,mixed>  $ve
     */
    private function scoreImmediacy(array $ve, string $transcript): int
    {
        $s = 3 + $this->countMarkers($transcript, self::IMMEDIACY_MARKERS);
        if ($this->filled($ve['time_delay'] ?? null)) {
            $s += 1;
        }

        return min(10, $s);
    }

    /**
     * @param  array<string,mixed>  $ve
     */
    private function scoreEffortlessness(array $ve, string $transcript): int
    {
        $s = 5 + $this->countMarkers($transcript, self::EASY_MARKERS) - $this->countMarkers($transcript, self::HARD_MARKERS);
        if ($this->filled($ve['effort_sacrifice'] ?? null)) {
            $s += 1;
        }

        return max(0, min(10, $s));
    }

    /**
     * @return array<string,bool>
     */
    private function scoreLifeForce8(string $transcript): array
    {
        $out = [];
        foreach (self::LF8_MARKERS as $key => $markers) {
            $out[$key] = $this->countMarkers($transcript, $markers) > 0;
        }

        return $out;
    }

    /**
     * @return array<string,string>
     */
    private function grandSlamFix(string $weakest): array
    {
        $gs = $this->playbook->grandSlam();

        return match ($weakest) {
            'perceived_likelihood' => ['lever' => 'guarantee + proof', 'how' => $gs['stack']['guarantee']],
            'dream_outcome' => ['lever' => 'bonuses + naming', 'how' => $gs['stack']['bonuses']],
            'time_delay' => ['lever' => 'quick-win + scarcity', 'how' => $gs['stack']['scarcity']],
            default => ['lever' => 'remove friction', 'how' => 'reduzir passos/pré-requisitos (done-for-you); empilhar bônus que tiram o esforço.'],
        };
    }

    private function filled(mixed $v): bool
    {
        return is_array($v) ? $v !== [] : trim((string) $v) !== '';
    }

    /**
     * @param  array<int,string>  $markers
     */
    private function countMarkers(string $haystack, array $markers): int
    {
        if ($haystack === '') {
            return 0;
        }
        $n = 0;
        foreach ($markers as $m) {
            if ($m !== '' && str_contains($haystack, $m)) {
                $n++;
            }
        }

        return $n;
    }
}
