<?php

namespace App\Services\Ai\VentureFoundry;

use App\Models\AiVenture;
use App\Models\AiVentureBusinessRule;
use App\Models\AiVentureMetricObservation;
use App\Services\Ai\Strategy\StrategyCanonicalHash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Canonical growth ladder S0 -> S5 (100M USD ARR) for Venture Foundry
 * companies.
 *
 * Every stage declares entry gates evaluated only against persisted records
 * (linked artifacts, active business rules, latest metric observations).
 * The ladder never claims a stage from conversation: no observation, no gate.
 */
class VentureGrowthLadderService
{
    public const STAGE_IDEATION = 'S0';

    public const STAGE_VALIDATION = 'S1';

    public const STAGE_FIRST_REVENUE = 'S2';

    public const STAGE_TRACTION_1M = 'S3';

    public const STAGE_SCALE_10M = 'S4';

    public const STAGE_CATEGORY_100M = 'S5';

    public const METRIC_ARR_USD = 'arr_usd';

    public const METRIC_LTV_CAC_RATIO = 'ltv_cac_ratio';

    /** Monthly recurring revenue — the success metric for the company success engine. */
    public const METRIC_MRR = 'mrr';

    public const MIN_ACTIVE_RULES_FOR_VALIDATION = 3;

    public static function stageKey(string $stage): string
    {
        return match ($stage) {
            self::STAGE_IDEATION => 'ideation',
            self::STAGE_VALIDATION => 'validation',
            self::STAGE_FIRST_REVENUE => 'first_revenue',
            self::STAGE_TRACTION_1M => 'traction_1m',
            self::STAGE_SCALE_10M => 'scale_10m',
            self::STAGE_CATEGORY_100M => 'category_100m',
            default => throw VentureFoundryException::invalidValue('ladder', 'stage', "unknown stage [{$stage}]"),
        };
    }

    /**
     * Canonical ladder definition: objective, entry gates, key metrics and
     * strategy playbook per stage.
     *
     * @return array<int,array<string,mixed>>
     */
    public function ladder(): array
    {
        return [
            [
                'stage' => self::STAGE_IDEATION,
                'key' => 'ideation',
                'name' => 'Ideação',
                'objective' => 'Transformar uma ideia promovida em oportunidade qualificada com problema, ICP e dor explícitos.',
                'entry_gates' => [],
                'key_metrics' => [],
                'playbook' => [
                    'focus' => ['Qualificar problema, ICP e dor', 'Registrar oportunidade no radar', 'Declarar as primeiras regras de negócio'],
                    'key_questions' => ['Quem sofre o problema e quanto paga hoje para resolvê-lo?', 'Por que agora?'],
                    'risks' => ['Apaixonar-se pela solução antes de validar o problema'],
                ],
            ],
            [
                'stage' => self::STAGE_VALIDATION,
                'key' => 'validation',
                'name' => 'Validação',
                'objective' => 'Provar a tese com oportunidade ligada e canon mínimo de regras de negócio.',
                'entry_gates' => [
                    ['gate' => 'opportunity_linked', 'kind' => 'structural', 'detail' => 'Venture ligada a uma oportunidade do radar (problema/ICP/dor/mercado/risco).'],
                    ['gate' => 'business_rules_min_active', 'kind' => 'structural', 'detail' => 'Pelo menos '.self::MIN_ACTIVE_RULES_FOR_VALIDATION.' regras de negócio ativas.'],
                ],
                'key_metrics' => ['experiment_count', 'discovery_interviews'],
                'playbook' => [
                    'focus' => ['Blueprint de produto/GTM/unit economics', 'Experimentos com hipótese e métrica de sucesso', 'Definir north star'],
                    'key_questions' => ['Qual experimento mais barato derruba a tese?', 'Qual o custo de aquisição plausível?'],
                    'risks' => ['Validar com opinião em vez de evidência'],
                ],
            ],
            [
                'stage' => self::STAGE_FIRST_REVENUE,
                'key' => 'first_revenue',
                'name' => 'Primeira Receita',
                'objective' => 'Operar de verdade: blueprint validado, north star definida e receita real observada.',
                'entry_gates' => [
                    ['gate' => 'blueprint_linked', 'kind' => 'structural', 'detail' => 'Venture ligada a um venture blueprint (produto, GTM, unit economics, hiring, ops).'],
                    ['gate' => 'north_star_defined', 'kind' => 'structural', 'detail' => 'North star metric declarada na venture.'],
                    ['gate' => 'arr_positive', 'kind' => 'metric', 'metric' => self::METRIC_ARR_USD, 'operator' => '>', 'threshold' => 0.0, 'detail' => 'ARR observado maior que zero.'],
                ],
                'key_metrics' => [self::METRIC_ARR_USD, 'active_customers', 'gross_margin'],
                'playbook' => [
                    'focus' => ['Fechar os primeiros clientes pagantes', 'Encurtar ciclo de venda', 'Documentar o playbook de entrega'],
                    'key_questions' => ['Os clientes renovariam hoje?', 'O que quebra se 10x o volume?'],
                    'risks' => ['Escalar aquisição antes de reter'],
                ],
            ],
            [
                'stage' => self::STAGE_TRACTION_1M,
                'key' => 'traction_1m',
                'name' => 'Tração — 1M USD ARR',
                'objective' => 'Repetibilidade comercial comprovada com 1M USD de receita anual recorrente.',
                'entry_gates' => [
                    ['gate' => 'arr_1m', 'kind' => 'metric', 'metric' => self::METRIC_ARR_USD, 'operator' => '>=', 'threshold' => 1_000_000.0, 'detail' => 'ARR observado >= 1M USD.'],
                ],
                'key_metrics' => [self::METRIC_ARR_USD, self::METRIC_LTV_CAC_RATIO, 'churn_monthly'],
                'playbook' => [
                    'focus' => ['Motor de aquisição repetível', 'Unit economics saudáveis', 'Primeiras contratações-chave'],
                    'key_questions' => ['Qual canal escala sem degradar CAC?', 'Onde está o gargalo operacional?'],
                    'risks' => ['Crescer queimando margem sem entender o porquê'],
                ],
            ],
            [
                'stage' => self::STAGE_SCALE_10M,
                'key' => 'scale_10m',
                'name' => 'Escala — 10M USD ARR',
                'objective' => 'Escalar com economia saudável: 10M USD ARR e LTV/CAC >= 3.',
                'entry_gates' => [
                    ['gate' => 'arr_10m', 'kind' => 'metric', 'metric' => self::METRIC_ARR_USD, 'operator' => '>=', 'threshold' => 10_000_000.0, 'detail' => 'ARR observado >= 10M USD.'],
                    ['gate' => 'ltv_cac_healthy', 'kind' => 'metric', 'metric' => self::METRIC_LTV_CAC_RATIO, 'operator' => '>=', 'threshold' => 3.0, 'detail' => 'LTV/CAC observado >= 3.'],
                ],
                'key_metrics' => [self::METRIC_ARR_USD, self::METRIC_LTV_CAC_RATIO, 'net_revenue_retention'],
                'playbook' => [
                    'focus' => ['Expansão de mercado/segmento', 'Estrutura de gestão e rituais', 'Segunda linha de produto ou expansão de receita'],
                    'key_questions' => ['O que limita a próxima duplicação?', 'A retenção líquida sustenta o crescimento?'],
                    'risks' => ['Complexidade organizacional destruindo velocidade'],
                ],
            ],
            [
                'stage' => self::STAGE_CATEGORY_100M,
                'key' => 'category_100m',
                'name' => 'Categoria — 100M USD ARR',
                'objective' => 'Liderança de categoria com 100M USD de receita anual recorrente.',
                'entry_gates' => [
                    ['gate' => 'arr_100m', 'kind' => 'metric', 'metric' => self::METRIC_ARR_USD, 'operator' => '>=', 'threshold' => 100_000_000.0, 'detail' => 'ARR observado >= 100M USD.'],
                ],
                'key_metrics' => [self::METRIC_ARR_USD, 'net_revenue_retention', 'market_share'],
                'playbook' => [
                    'focus' => ['Defender a categoria', 'Eficiência de capital', 'Opções estratégicas (M&A, novas linhas, geografia)'],
                    'key_questions' => ['O que protege a posição nos próximos 5 anos?'],
                    'risks' => ['Perder o foco que construiu a posição'],
                ],
            ],
        ];
    }

    /**
     * Record a metric observation for a venture.
     *
     * @param  array<string,mixed>  $args
     */
    public function recordMetric(AiVenture $venture, array $args): AiVentureMetricObservation
    {
        $metricKey = trim((string) ($args['metric_key'] ?? ''));
        if ($metricKey === '') {
            throw VentureFoundryException::missingField('metric_observation', 'metric_key');
        }
        if (! array_key_exists('value', $args) || ! is_numeric($args['value'])) {
            throw VentureFoundryException::missingField('metric_observation', 'value');
        }

        $uuid = (string) Str::uuid();
        $observedAt = isset($args['observed_at'])
            ? Carbon::parse((string) $args['observed_at'])
            : Carbon::now();

        return AiVentureMetricObservation::query()->create([
            'uuid' => $uuid,
            'venture_id' => $venture->id,
            'metric_key' => $metricKey,
            'value' => (float) $args['value'],
            'unit' => $args['unit'] ?? null,
            'currency' => $args['currency'] ?? null,
            'source' => (string) ($args['source'] ?? 'operator'),
            'note' => $args['note'] ?? null,
            'observed_at' => $observedAt,
            'observation_hash' => StrategyCanonicalHash::sha256([
                'uuid' => $uuid,
                'venture_id' => $venture->id,
                'metric_key' => $metricKey,
                'value' => (float) $args['value'],
                'observed_at' => $observedAt->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Latest observation per metric key for a venture.
     *
     * @return array<string,array{value: float, observed_at: string}>
     */
    public function latestMetrics(AiVenture $venture): array
    {
        $observations = AiVentureMetricObservation::query()
            ->where('venture_id', $venture->id)
            ->orderBy('observed_at')
            ->orderBy('created_at')
            ->get();

        $latest = [];
        foreach ($observations as $observation) {
            $latest[$observation->metric_key] = [
                'value' => (float) $observation->value,
                'observed_at' => $observation->observed_at?->toIso8601String() ?? '',
            ];
        }

        return $latest;
    }

    /**
     * Evaluate a venture against the ladder. Returns per-stage gate results,
     * the highest stage whose gates all pass (recommended), gaps blocking the
     * next stage and the playbook for the recommended stage.
     *
     * @return array<string,mixed>
     */
    public function evaluate(AiVenture $venture): array
    {
        $metrics = $this->latestMetrics($venture);
        $activeRules = AiVentureBusinessRule::query()
            ->where('venture_id', $venture->id)
            ->where('status', VentureBusinessRuleService::STATUS_ACTIVE)
            ->count();

        $stageResults = [];
        $recommended = self::STAGE_IDEATION;
        $recommendedPlaybook = null;
        $chainUnbroken = true;

        foreach ($this->ladder() as $definition) {
            $gateResults = [];
            $allPass = true;

            foreach ($definition['entry_gates'] as $gate) {
                $result = $this->evaluateGate($venture, $gate, $metrics, $activeRules);
                $gateResults[] = $result;
                $allPass = $allPass && $result['passed'];
            }

            $stagePass = $chainUnbroken && $allPass;
            $stageResults[] = [
                'stage' => $definition['stage'],
                'key' => $definition['key'],
                'name' => $definition['name'],
                'objective' => $definition['objective'],
                'gates' => $gateResults,
                'passed' => $stagePass,
            ];

            if ($stagePass) {
                $recommended = $definition['stage'];
                $recommendedPlaybook = $definition['playbook'];
            } else {
                $chainUnbroken = false;
            }
        }

        $gaps = [];
        $nextActions = [];
        foreach ($stageResults as $stageResult) {
            if ($stageResult['passed']) {
                continue;
            }
            foreach ($stageResult['gates'] as $gateResult) {
                if (! $gateResult['passed']) {
                    $gaps[] = [
                        'stage' => $stageResult['stage'],
                        'gate' => $gateResult['gate'],
                        'detail' => $gateResult['detail'],
                    ];
                    $nextActions[] = sprintf('[%s] %s', $stageResult['stage'], $gateResult['detail']);
                }
            }
            break;
        }

        return [
            'venture_id' => $venture->venture_id,
            'current_stage' => $venture->stage,
            'recommended_stage' => $recommended,
            'target_arr_usd' => (float) $venture->target_arr_usd,
            'observed_arr_usd' => $metrics[self::METRIC_ARR_USD]['value'] ?? 0.0,
            'active_business_rules' => $activeRules,
            'metrics' => $metrics,
            'stages' => $stageResults,
            'gaps' => $gaps,
            'next_actions' => $nextActions,
            'playbook' => $recommendedPlaybook,
        ];
    }

    /**
     * @param  array<string,mixed>  $gate
     * @param  array<string,array{value: float, observed_at: string}>  $metrics
     * @return array<string,mixed>
     */
    private function evaluateGate(AiVenture $venture, array $gate, array $metrics, int $activeRules): array
    {
        $passed = false;
        $observed = null;

        if ($gate['kind'] === 'structural') {
            $passed = match ($gate['gate']) {
                'opportunity_linked' => $venture->opportunity_id !== null,
                'business_rules_min_active' => $activeRules >= self::MIN_ACTIVE_RULES_FOR_VALIDATION,
                'blueprint_linked' => $venture->venture_blueprint_id !== null,
                'north_star_defined' => is_array($venture->north_star) && $venture->north_star !== [],
                default => false,
            };
            $observed = $gate['gate'] === 'business_rules_min_active' ? $activeRules : null;
        }

        if ($gate['kind'] === 'metric') {
            $metricKey = (string) $gate['metric'];
            $observed = $metrics[$metricKey]['value'] ?? null;
            if ($observed !== null) {
                $threshold = (float) $gate['threshold'];
                $passed = match ($gate['operator']) {
                    '>' => $observed > $threshold,
                    '>=' => $observed >= $threshold,
                    default => false,
                };
            }
        }

        return [
            'gate' => $gate['gate'],
            'kind' => $gate['kind'],
            'detail' => $gate['detail'],
            'observed' => $observed,
            'passed' => $passed,
        ];
    }
}
