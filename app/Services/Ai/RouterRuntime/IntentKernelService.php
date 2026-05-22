<?php

namespace App\Services\Ai\RouterRuntime;

use App\Models\AiAtlasIntentClassification;
use Illuminate\Support\Str;

class IntentKernelService
{
    /**
     * Deterministic keyword-based intent classifier. Heuristic for Meta 6 v1
     * — the long-term roadmap is to back this with the Router Runtime
     * Enterprise Upgrade kernel + provider/LLM signal, but v1 must be
     * deterministic so tests and audits remain reproducible.
     *
     * @var array<string,array<int,string>>
     */
    private const INTENT_KEYWORDS = [
        RouterRuntimeCanon::INTENT_PROGRAMMING => [
            'implemente', 'implementar', 'implement',
            'codigo', 'código', 'code', 'codifique',
            'fix', 'fixe', 'corrija', 'corrigir', 'correcao', 'correção',
            'refator', 'refactor',
            'bug', 'testes',
            'obra', 'forge',
            'adicione metodo', 'crie funcao', 'crie função', 'crie classe',
            'escreva teste', 'crie teste',
        ],
        RouterRuntimeCanon::INTENT_DEBUG => [
            'debug', 'depurar', 'rastreie',
            'investigue bug', 'why does it fail', 'porque falha',
            'stack trace', 'erro', 'exception',
        ],
        RouterRuntimeCanon::INTENT_REVIEW => [
            'review', 'revise', 'revisar',
            'code review', 'analise esse pr', 'analise esse commit',
        ],
        RouterRuntimeCanon::INTENT_RESEARCH => [
            'pesquise', 'pesquisar', 'pesquisa',
            'análise profunda', 'analise profunda',
            'fontes', 'source quality',
            'state of the art', 'estado da arte',
            'levantamento', 'mapping',
        ],
        RouterRuntimeCanon::INTENT_EXPLAIN => [
            'explique', 'explain', 'o que é', 'o que e ',
            'como funciona', 'how does',
        ],
        RouterRuntimeCanon::INTENT_PLAN => [
            'plano', 'plan', 'estruture',
            'roadmap', 'cronograma', 'fases',
            'objetivos da meta', 'definicao de done',
        ],
        RouterRuntimeCanon::INTENT_FINANCE => [
            'carteira', 'investimento', 'portfolio',
            'valuation', 'fluxo de caixa',
            'day trade', 'daytrade', 'broker',
            'asset allocation', 'risco de mercado',
        ],
        RouterRuntimeCanon::INTENT_MARKETING => [
            'campanha', 'campaign',
            'copy', 'copywriting',
            'anúncios', 'anuncios', 'paid media',
            'funil', 'funnel',
            'icp', 'persona',
            'gtm', 'growth',
        ],
        RouterRuntimeCanon::INTENT_STRATEGY => [
            'estrategia', 'estratégia', 'strategy',
            'tam', 'sam', 'som',
            'venture', 'oportunidade',
            'tese de negocio', 'tese de negócio',
        ],
        RouterRuntimeCanon::INTENT_CYBER => [
            'pentest', 'penetration test',
            'bug bounty',
            'vulnerabilidade', 'vulnerability', 'cve',
            'exploit',
            'osint',
        ],
        RouterRuntimeCanon::INTENT_PERSONAL_DEVELOPMENT => [
            'rotina', 'hábito', 'habito', 'habit',
            'estudo', 'estudar', 'aprendizado',
            'professor', 'coach',
            'pratica deliberada', 'prática deliberada',
        ],
        RouterRuntimeCanon::INTENT_AUTOMATION => [
            'automatize', 'automatizar', 'automation',
            'browser automation',
            'integre site', 'integre api',
            'webhook',
            'crawl', 'scrape', 'scraping',
        ],
        RouterRuntimeCanon::INTENT_CONVERSATION => [
            'oi', 'olá', 'ola', 'hello', 'bom dia', 'boa tarde', 'boa noite',
            'obrigado', 'thanks', 'valeu',
        ],
    ];

    /**
     * Classify a raw input prompt. Returns a persisted classification record.
     *
     * @param  array<string,mixed>  $context
     */
    public function classify(string $rawInput, array $context = []): AiAtlasIntentClassification
    {
        $normalized = $this->normalize($rawInput);
        $scores = $this->scoreKeywords($normalized);
        $ranked = $this->rank($scores);
        [$intentType, $confidence, $ambiguity, $matchedKeywords] = $this->resolveIntent($ranked, $normalized);
        $surfaceContract = is_array($context['surface_contract'] ?? null)
            ? $context['surface_contract']
            : [];

        $override = $this->intentOverride($context);
        if ($override !== null) {
            $intentType = $override['intent_type'];
            $confidence = $override['confidence'];
            $ambiguity = $override['ambiguity_score'];
            $matchedKeywords = $override['matched_keywords'];
        }

        return AiAtlasIntentClassification::query()->create([
            'uuid' => (string) Str::uuid(),
            'mission_id' => $context['mission_id'] ?? null,
            'raw_input' => $rawInput,
            'normalized_intent' => $normalized,
            'intent_type' => $intentType,
            'ambiguity_score' => $ambiguity,
            'confidence' => $confidence,
            'signals' => [
                'matched_keywords' => $matchedKeywords,
                'scores' => $scores,
                'top' => array_slice($ranked, 0, 3),
                'length' => mb_strlen($normalized),
                'source' => $context['source'] ?? 'cli',
                'surface_contract' => $surfaceContract,
                'intent_override' => $override,
            ],
            'status' => 'classified',
        ]);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{intent_type:string,confidence:float,ambiguity_score:float,matched_keywords:array<int,string>,reason:string}|null
     */
    private function intentOverride(array $context): ?array
    {
        $candidate = $context['intent_override'] ?? null;
        if (! is_string($candidate) || ! in_array($candidate, RouterRuntimeCanon::INTENT_TYPES, true)) {
            return null;
        }

        return [
            'intent_type' => $candidate,
            'confidence' => $this->floatBetween($context['intent_override_confidence'] ?? null, 0.82),
            'ambiguity_score' => $this->floatBetween($context['intent_override_ambiguity_score'] ?? null, 0.25),
            'matched_keywords' => array_values(array_filter(
                (array) ($context['intent_override_matched_keywords'] ?? ['surface_contract']),
                static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
            )),
            'reason' => is_string($context['intent_override_reason'] ?? null)
                ? (string) $context['intent_override_reason']
                : 'surface_contract_intent_override',
        ];
    }

    private function floatBetween(mixed $value, float $fallback): float
    {
        if (! is_numeric($value)) {
            return $fallback;
        }

        return max(0.0, min(1.0, round((float) $value, 4)));
    }

    private function normalize(string $input): string
    {
        $lower = mb_strtolower(trim($input));

        return preg_replace('/\s+/u', ' ', $lower) ?? $lower;
    }

    /**
     * @return array<string,int>
     */
    private function scoreKeywords(string $normalized): array
    {
        $scores = [];
        foreach (self::INTENT_KEYWORDS as $intent => $keywords) {
            $hits = 0;
            foreach ($keywords as $keyword) {
                $needle = mb_strtolower($keyword);
                if ($needle === '' || str_contains($normalized, $needle)) {
                    if ($needle !== '' && str_contains($normalized, $needle)) {
                        $hits++;
                    }
                }
            }
            $scores[$intent] = $hits;
        }

        return $scores;
    }

    /**
     * @param  array<string,int>  $scores
     * @return array<int,array{intent:string,score:int}>
     */
    private function rank(array $scores): array
    {
        $ranked = [];
        foreach ($scores as $intent => $score) {
            $ranked[] = ['intent' => $intent, 'score' => $score];
        }
        usort($ranked, static fn ($a, $b) => $b['score'] <=> $a['score']);

        return $ranked;
    }

    /**
     * @param  array<int,array{intent:string,score:int}>  $ranked
     * @return array{0:string,1:float,2:float,3:array<int,string>}
     */
    private function resolveIntent(array $ranked, string $normalized): array
    {
        $top = $ranked[0] ?? null;
        $second = $ranked[1] ?? null;

        if ($top === null || $top['score'] === 0) {
            return [
                RouterRuntimeCanon::INTENT_UNKNOWN,
                0.10,
                0.90,
                [],
            ];
        }

        $topScore = $top['score'];
        $secondScore = $second['score'] ?? 0;
        $gap = $topScore - $secondScore;
        $confidence = min(0.99, 0.55 + 0.10 * $topScore + 0.05 * $gap);

        if ($topScore === 1 && $secondScore === 1) {
            $ambiguity = 0.85;
        } elseif ($topScore >= 2 && $secondScore >= 2) {
            $ambiguity = 0.55;
        } elseif ($secondScore === 0) {
            $ambiguity = 0.10;
        } else {
            $ambiguity = min(0.95, 0.75 - 0.15 * $gap);
        }

        $matched = [];
        foreach (self::INTENT_KEYWORDS[$top['intent']] ?? [] as $keyword) {
            if (str_contains($normalized, mb_strtolower($keyword))) {
                $matched[] = $keyword;
            }
        }

        return [
            $top['intent'],
            round($confidence, 4),
            round($ambiguity, 4),
            $matched,
        ];
    }
}
