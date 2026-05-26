<?php

namespace App\Services\Ai\Tokens;

/**
 * Atlas Cognition Operating System — ATER (Token Economy Runtime) Phase 1.
 *
 * Schemas canon: atlas.token_economy.{budget|consumption|reuse_credit|variant}.v1
 * Doc canon: atlas-cognition-operating-system.md (AUCRI bloco #17).
 *
 * RESPONSABILIDADE:
 *  - Aplicar token budget por flow / risk / surface.
 *  - Estimar custo de variantes (contexto compactado vs expandido).
 *  - Creditar reuse (mesma tarefa, mesmo provider, mesma hash de contexto -> usa cache).
 *  - Emitir receipts canonicos antes de provider call.
 *
 * Phase 1 (esta versao):
 *  - Budgets canonicos por flow (atlas_dev, atlas_research, atlas_forge, default).
 *  - Cost estimator deterministico (token count -> custo unitario via tabela).
 *  - Reuse credit computacao (cache hit).
 *  - Validacao de variants contra budget.
 *  - In-memory; sem persistencia DB ainda.
 *
 * Phase 2 (proximo AP):
 *  - Persistencia em atlas_token_economy_receipts.
 *  - Wire com ACPFR (Pareto frontier) para decisao final.
 *  - Wire com ARCLG (Cost Latency Governor) para gate antes de provider.
 *  - Real-time provider cost (custo do token varia por provider).
 *  - Hooks SLO para alerting quando budget excede.
 *
 * Cognitive immune compliance:
 *  - must_keep refs NUNCA sao removidas para caber em budget.
 *  - Se variants nao cabem em budget mesmo apos compactacao, retorna `blocked` + reason.
 */
class AtlasTokenEconomyBudgetPolicyService
{
    public const FLOW_DEFAULT = 'default';

    public const FLOW_ATLAS_DEV = 'atlas_dev';

    public const FLOW_ATLAS_RESEARCH = 'atlas_research';

    public const FLOW_ATLAS_FORGE = 'atlas_forge';

    public const FLOW_ATLAS_REVIEW = 'atlas_review';

    public const FLOW_ATLAS_DEBUG = 'atlas_debug';

    public const FLOW_ATLAS_EXPLAIN = 'atlas_explain';

    public const FLOW_ATLAS_CONVERSATION = 'atlas_conversation';

    public const RISK_LOW = 'low';

    public const RISK_MEDIUM = 'medium';

    public const RISK_HIGH = 'high';

    public const RISK_IRREVERSIBLE = 'irreversible';

    public const ALLOWED_RISK_LEVELS = [
        self::RISK_LOW,
        self::RISK_MEDIUM,
        self::RISK_HIGH,
        self::RISK_IRREVERSIBLE,
    ];

    /**
     * Budgets canonicos: [input_tokens_max, output_tokens_max, cost_units_max].
     * Cost units arbitrario (1.0 = base; provider real ajusta).
     *
     * @var array<string,array<string,array{input_tokens_max:int,output_tokens_max:int,cost_units_max:float}>>
     */
    private const BUDGETS = [
        self::FLOW_DEFAULT => [
            self::RISK_LOW => ['input_tokens_max' => 8_000, 'output_tokens_max' => 2_000, 'cost_units_max' => 1.0],
            self::RISK_MEDIUM => ['input_tokens_max' => 16_000, 'output_tokens_max' => 4_000, 'cost_units_max' => 2.0],
            self::RISK_HIGH => ['input_tokens_max' => 32_000, 'output_tokens_max' => 8_000, 'cost_units_max' => 5.0],
            self::RISK_IRREVERSIBLE => ['input_tokens_max' => 64_000, 'output_tokens_max' => 16_000, 'cost_units_max' => 10.0],
        ],
        self::FLOW_ATLAS_DEV => [
            self::RISK_LOW => ['input_tokens_max' => 16_000, 'output_tokens_max' => 4_000, 'cost_units_max' => 1.5],
            self::RISK_MEDIUM => ['input_tokens_max' => 32_000, 'output_tokens_max' => 8_000, 'cost_units_max' => 3.0],
            self::RISK_HIGH => ['input_tokens_max' => 64_000, 'output_tokens_max' => 16_000, 'cost_units_max' => 8.0],
            self::RISK_IRREVERSIBLE => ['input_tokens_max' => 128_000, 'output_tokens_max' => 32_000, 'cost_units_max' => 15.0],
        ],
        self::FLOW_ATLAS_RESEARCH => [
            self::RISK_LOW => ['input_tokens_max' => 20_000, 'output_tokens_max' => 5_000, 'cost_units_max' => 2.0],
            self::RISK_MEDIUM => ['input_tokens_max' => 40_000, 'output_tokens_max' => 10_000, 'cost_units_max' => 4.0],
            self::RISK_HIGH => ['input_tokens_max' => 80_000, 'output_tokens_max' => 20_000, 'cost_units_max' => 10.0],
            self::RISK_IRREVERSIBLE => ['input_tokens_max' => 160_000, 'output_tokens_max' => 40_000, 'cost_units_max' => 20.0],
        ],
        self::FLOW_ATLAS_FORGE => [
            self::RISK_LOW => ['input_tokens_max' => 32_000, 'output_tokens_max' => 8_000, 'cost_units_max' => 3.0],
            self::RISK_MEDIUM => ['input_tokens_max' => 64_000, 'output_tokens_max' => 16_000, 'cost_units_max' => 6.0],
            self::RISK_HIGH => ['input_tokens_max' => 128_000, 'output_tokens_max' => 32_000, 'cost_units_max' => 15.0],
            self::RISK_IRREVERSIBLE => ['input_tokens_max' => 256_000, 'output_tokens_max' => 64_000, 'cost_units_max' => 30.0],
        ],
    ];

    /**
     * Retorna budget canonico para flow + risk.
     *
     * @return array{schema_version:string,flow_id:string,risk_level:string,input_tokens_max:int,output_tokens_max:int,cost_units_max:float}
     */
    public function budget(string $flowId = self::FLOW_DEFAULT, string $riskLevel = self::RISK_LOW): array
    {
        $flowId = $this->resolveFlow($flowId);
        $riskLevel = $this->resolveRisk($riskLevel);

        $limits = self::BUDGETS[$flowId][$riskLevel];

        return [
            'schema_version' => 'atlas.token_economy.budget.v1',
            'flow_id' => $flowId,
            'risk_level' => $riskLevel,
            'input_tokens_max' => $limits['input_tokens_max'],
            'output_tokens_max' => $limits['output_tokens_max'],
            'cost_units_max' => $limits['cost_units_max'],
        ];
    }

    /**
     * Avalia variants candidatos contra o budget.
     *
     * @param  array<int,array{name?:string,input_tokens:int,output_tokens_estimate?:int,quality_score?:float,must_keep_coverage?:float,latency_ms?:int}>  $variants
     * @return array{schema_version:string,flow_id:string,risk_level:string,evaluated:array<int,array<string,mixed>>,selected:?array<string,mixed>,blocked_reason:?string}
     */
    public function evaluateVariants(array $variants, string $flowId = self::FLOW_DEFAULT, string $riskLevel = self::RISK_LOW): array
    {
        $budget = $this->budget($flowId, $riskLevel);

        $evaluated = [];
        $eligible = [];

        foreach ($variants as $idx => $v) {
            $inputTokens = (int) ($v['input_tokens'] ?? 0);
            $outputEst = (int) ($v['output_tokens_estimate'] ?? 0);
            $quality = (float) ($v['quality_score'] ?? 0.7);
            $coverage = (float) ($v['must_keep_coverage'] ?? 1.0);
            $latencyMs = (int) ($v['latency_ms'] ?? 0);
            $name = (string) ($v['name'] ?? "variant_$idx");

            $costUnits = $this->estimateCost($inputTokens, $outputEst);

            $reasons = [];
            $eligibleVariant = true;

            if ($coverage < 1.0) {
                $reasons[] = 'must_keep_coverage_below_1';
                $eligibleVariant = false;
            }
            if ($inputTokens > $budget['input_tokens_max']) {
                $reasons[] = 'input_tokens_over_budget';
                $eligibleVariant = false;
            }
            if ($outputEst > $budget['output_tokens_max']) {
                $reasons[] = 'output_tokens_over_budget';
                $eligibleVariant = false;
            }
            if ($costUnits > $budget['cost_units_max']) {
                $reasons[] = 'cost_units_over_budget';
                $eligibleVariant = false;
            }

            $envelope = [
                'schema_version' => 'atlas.token_economy.variant.v1',
                'name' => $name,
                'input_tokens' => $inputTokens,
                'output_tokens_estimate' => $outputEst,
                'cost_units' => $costUnits,
                'quality_score' => $quality,
                'must_keep_coverage' => $coverage,
                'latency_ms' => $latencyMs,
                'eligible' => $eligibleVariant,
                'rejection_reasons' => $reasons,
            ];
            $evaluated[] = $envelope;
            if ($eligibleVariant) {
                $eligible[] = $envelope;
            }
        }

        $selected = null;
        $blockedReason = null;

        if ($eligible === []) {
            $blockedReason = 'no_variant_fits_budget_with_must_keep_intact';
        } else {
            // Selecao default: maior quality_score, depois menor cost_units.
            usort($eligible, function ($a, $b): int {
                $q = $b['quality_score'] <=> $a['quality_score'];
                if ($q !== 0) {
                    return $q;
                }

                return $a['cost_units'] <=> $b['cost_units'];
            });
            $selected = $eligible[0];
        }

        return [
            'schema_version' => 'atlas.token_economy.consumption.v1',
            'flow_id' => $budget['flow_id'],
            'risk_level' => $budget['risk_level'],
            'evaluated' => $evaluated,
            'selected' => $selected,
            'blocked_reason' => $blockedReason,
        ];
    }

    /**
     * Calcula reuse credit: hash do contexto coincide com hash anterior?
     *
     * @return array{schema_version:string,context_hash:string,reused:bool,saved_tokens:int}
     */
    public function reuseCredit(string $contextHash, int $estimatedInputTokens, array $cachedHashes = []): array
    {
        $hit = in_array($contextHash, $cachedHashes, true);

        return [
            'schema_version' => 'atlas.token_economy.reuse_credit.v1',
            'context_hash' => $contextHash,
            'reused' => $hit,
            'saved_tokens' => $hit ? $estimatedInputTokens : 0,
        ];
    }

    /**
     * Estimativa de cost unit a partir de input + output token counts.
     * Phase 1: formula simples (input + output*4) / 100_000.
     * Phase 2: tabela por provider real (Claude/GPT/Gemini/Composer/MiniMax).
     *
     * Calibracao: cost_units sao normalizados para 1.0 ≈ 100k tokens
     * weighted, equivalente a ~$0.10 em valor real estimado.
     */
    public function estimateCost(int $inputTokens, int $outputTokensEstimate): float
    {
        if ($inputTokens < 0) {
            $inputTokens = 0;
        }
        if ($outputTokensEstimate < 0) {
            $outputTokensEstimate = 0;
        }

        $weighted = $inputTokens + ($outputTokensEstimate * 4);

        return round($weighted / 100_000.0, 4);
    }

    public static function isValidFlow(string $flowId): bool
    {
        return array_key_exists($flowId, self::BUDGETS) || $flowId === self::FLOW_DEFAULT;
    }

    public static function isValidRisk(string $riskLevel): bool
    {
        return in_array($riskLevel, self::ALLOWED_RISK_LEVELS, true);
    }

    private function resolveFlow(string $flowId): string
    {
        return array_key_exists($flowId, self::BUDGETS) ? $flowId : self::FLOW_DEFAULT;
    }

    private function resolveRisk(string $riskLevel): string
    {
        return in_array($riskLevel, self::ALLOWED_RISK_LEVELS, true) ? $riskLevel : self::RISK_LOW;
    }
}
