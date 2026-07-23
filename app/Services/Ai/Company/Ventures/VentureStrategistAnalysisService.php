<?php

namespace App\Services\Ai\Company\Ventures;

use App\Models\AiJob;
use App\Models\AiVenture;
use App\Models\AiVentureIdea;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AtlasDecide\AtlasStructuredOutputValidator;
use Illuminate\Support\Str;
use Throwable;

/**
 * The THINKING layer of the venture strategist — a qualitative strategic
 * opinion produced by a provider, grounded fail-closed in the venture's
 * persisted state.
 *
 * Grounding lock (the anti-hallucination core): the prompt presents the
 * venture as a NUMBERED fact sheet (F1..Fn) built only from persisted records
 * (stage, gates, gaps, metrics, trajectory, business rules, thesis). Every
 * strategic move returned by the provider must cite the fact ids it stands
 * on; a move citing no facts or unknown facts is DROPPED and reported. An
 * opinion that loses all moves degrades to `all_moves_ungrounded` — the
 * deterministic review survives unchanged either way.
 *
 * Same seam philosophy as VentureIdeaGenerationService: provider invocation
 * isolated behind protected {@see invokeProvider()} for zero-spend tests.
 */
class VentureStrategistAnalysisService
{
    public const STATUS_OK = 'ok';

    public const STATUS_PROVIDER_FAILED = 'provider_failed';

    public const STATUS_INVALID_OUTPUT = 'invalid_structured_output';

    public const STATUS_ALL_MOVES_UNGROUNDED = 'all_moves_ungrounded';

    private const MAX_LIST_ITEMS = 6;

    public function __construct(
        private readonly AiProviderManager $providers,
        private readonly AtlasStructuredOutputValidator $validator,
    ) {}

    /**
     * @param  array<string,mixed>  $evaluation  ladder evaluation packet
     * @param  array<string,mixed>  $trajectory  trajectory projection packet
     * @param  array<int,\App\Models\AiVentureBusinessRule>  $activeRules
     * @return array<string,mixed>
     */
    public function analyze(AiVenture $venture, array $evaluation, array $trajectory, array $activeRules): array
    {
        $facts = $this->facts($venture, $evaluation, $trajectory, $activeRules);
        $providerKey = config('atlas_venture_foundry.analysis_provider_key');
        $providerKey = is_string($providerKey) && $providerKey !== '' ? $providerKey : null;

        try {
            $raw = $this->invokeProvider($providerKey, $this->prompt($venture, $facts));
        } catch (Throwable $e) {
            return $this->degraded(self::STATUS_PROVIDER_FAILED, $facts, $e->getMessage());
        }

        $validated = $this->validator->validate($this->isolateJson($raw), $this->schema());
        if (($validated['valid'] ?? false) !== true || ! is_array($validated['value'] ?? null)) {
            return $this->degraded(self::STATUS_INVALID_OUTPUT, $facts, implode('; ', (array) ($validated['errors'] ?? [])));
        }

        $value = $validated['value'];
        $knownFactIds = array_keys($facts);

        $moves = [];
        $droppedMoves = [];
        foreach ((array) ($value['strategic_moves'] ?? []) as $move) {
            if (! is_array($move)) {
                continue;
            }
            $grounding = array_values(array_filter((array) ($move['grounded_in'] ?? []), 'is_string'));
            $validGrounding = array_values(array_intersect($grounding, $knownFactIds));

            if ($validGrounding === []) {
                $droppedMoves[] = [
                    'move' => Str::limit(trim((string) ($move['move'] ?? '')), 200),
                    'reason' => 'ungrounded',
                    'cited' => $grounding,
                ];

                continue;
            }

            $moves[] = [
                'move' => Str::limit(trim((string) ($move['move'] ?? '')), 400),
                'rationale' => Str::limit(trim((string) ($move['rationale'] ?? '')), 600),
                'horizon' => in_array($move['horizon'] ?? null, ['now', 'quarter', 'year'], true) ? $move['horizon'] : 'now',
                'grounded_in' => $validGrounding,
            ];
            if (count($moves) >= self::MAX_LIST_ITEMS) {
                break;
            }
        }

        if ($moves === []) {
            return $this->degraded(self::STATUS_ALL_MOVES_UNGROUNDED, $facts, 'provider returned no move grounded in known facts', $droppedMoves);
        }

        $confidence = $value['confidence'] ?? null;
        $confidence = is_numeric($confidence) ? max(0.0, min(1.0, (float) $confidence)) : null;

        return [
            'schema_version' => 'atlas.ai.venture.strategist_analysis.v1',
            'analysis_status' => self::STATUS_OK,
            'provider_key' => $providerKey ?? 'runtime_default',
            'headline' => Str::limit(trim((string) ($value['headline'] ?? '')), 300),
            'strengths' => $this->stringList($value['strengths'] ?? []),
            'risks' => $this->stringList($value['risks'] ?? []),
            'strategic_moves' => $moves,
            'focus_next_cycle' => $this->stringList($value['focus_next_cycle'] ?? []),
            'confidence' => $confidence,
            'facts' => $facts,
            'dropped_moves' => $droppedMoves,
            'raw_output_hash' => hash('sha256', $raw),
        ];
    }

    /**
     * Numbered fact sheet from persisted state only.
     *
     * @param  array<string,mixed>  $evaluation
     * @param  array<string,mixed>  $trajectory
     * @param  array<int,\App\Models\AiVentureBusinessRule>  $activeRules
     * @return array<string,string>
     */
    private function facts(AiVenture $venture, array $evaluation, array $trajectory, array $activeRules): array
    {
        $facts = [];
        $i = 0;
        $add = function (string $fact) use (&$facts, &$i): void {
            $i++;
            $facts['F'.$i] = $fact;
        };

        $add(sprintf('Tese: %s', $venture->thesis));

        $idea = $venture->idea_id !== null ? AiVentureIdea::query()->whereKey($venture->idea_id)->first() : null;
        if ($idea !== null) {
            $add(sprintf('Problema: %s | ICP: %s | Dor: %s', $idea->problem, $idea->icp, $idea->pain));
        }

        $add(sprintf(
            'Estágio atual %s; estágio recomendado pelos gates %s; alvo %s USD ARR; ARR observado %s USD.',
            $evaluation['current_stage'],
            $evaluation['recommended_stage'],
            number_format((float) $evaluation['target_arr_usd'], 0, '.', ','),
            number_format((float) $evaluation['observed_arr_usd'], 0, '.', ','),
        ));

        foreach ((array) $evaluation['gaps'] as $gap) {
            $add(sprintf('Gap aberto [%s/%s]: %s', $gap['stage'] ?? '?', $gap['gate'] ?? '?', $gap['detail'] ?? ''));
        }

        foreach ((array) ($evaluation['metrics'] ?? []) as $key => $observation) {
            $add(sprintf('Métrica observada %s = %s (em %s).', $key, $observation['value'], $observation['observed_at']));
        }

        if (($trajectory['status'] ?? '') === VentureTrajectoryService::STATUS_PROJECTED) {
            foreach ((array) $trajectory['scenarios'] as $scenario) {
                $add(sprintf(
                    'Trajetória %s: crescendo %d%% ao ano, %s anos até o alvo.',
                    $scenario['scenario'],
                    (int) round($scenario['annual_growth_rate'] * 100),
                    $scenario['years_to_target'],
                ));
            }
        } else {
            $add('Trajetória: '.($trajectory['status'] ?? 'desconhecida').' — '.($trajectory['detail'] ?? ''));
        }

        $add(sprintf('Regras de negócio ativas: %d.', count($activeRules)));
        foreach (array_slice($activeRules, 0, self::MAX_LIST_ITEMS) as $rule) {
            $add(sprintf('Regra [%s/%s]: %s', $rule->category, $rule->rule_id, $rule->statement));
        }

        return $facts;
    }

    /**
     * @param  array<string,string>  $facts
     */
    private function prompt(AiVenture $venture, array $facts): string
    {
        $factLines = '';
        foreach ($facts as $id => $fact) {
            $factLines .= "{$id}: {$fact}\n";
        }

        return <<<PROMPT
Você é o estrategista empresarial da empresa [{$venture->name}] de um operador solo (local-first, forte em engenharia de software e automação com IA). Sua função: parecer estratégico honesto e específico, baseado SOMENTE nos fatos numerados abaixo.

FATOS (única fonte permitida):
{$factLines}
Regras duras:
- Cada strategic_move DEVE citar em grounded_in os ids dos fatos (ex.: ["F3","F5"]) que o sustentam. Movimento sem fato citado será descartado.
- Não invente números, mercados ou clientes que não estejam nos fatos.
- Seja específico para ESTA empresa; nada de conselho genérico de startup.
- horizon: "now" (este ciclo), "quarter" (3 meses) ou "year".
- confidence: 0.0-1.0, sua confiança no parecer dado o quão completos os fatos estão.

Responda APENAS com JSON estrito, sem markdown:
{"headline":"...","strengths":["..."],"risks":["..."],"strategic_moves":[{"move":"...","rationale":"...","horizon":"now","grounded_in":["F1"]}],"focus_next_cycle":["..."],"confidence":0.7}
PROMPT;
    }

    /**
     * @return array<string,mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['headline', 'strategic_moves'],
            'properties' => [
                'headline' => ['type' => 'string'],
                'strengths' => ['type' => ['array', 'null'], 'items' => ['type' => 'string']],
                'risks' => ['type' => ['array', 'null'], 'items' => ['type' => 'string']],
                'strategic_moves' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['move', 'grounded_in'],
                        'properties' => [
                            'move' => ['type' => 'string'],
                            'rationale' => ['type' => ['string', 'null']],
                            'horizon' => ['type' => ['string', 'null']],
                            'grounded_in' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
                'focus_next_cycle' => ['type' => ['array', 'null'], 'items' => ['type' => 'string']],
                'confidence' => ['type' => ['number', 'integer', 'null']],
            ],
        ];
    }

    /**
     * Provider seam — override in tests for zero-spend runs.
     */
    protected function invokeProvider(?string $providerKey, string $prompt): string
    {
        $job = new AiJob;
        $job->forceFill([
            'kind' => 'venture_strategist_analysis',
            'provider' => $providerKey,
            'model' => (string) (config('atlas_venture_foundry.analysis_model') ?? ''),
            'timeout_seconds' => (int) config('atlas_venture_foundry.analysis_timeout_seconds', 180),
            'metadata' => ['purpose' => 'venture_strategist_analysis', 'proposal_only' => true, 'permission_mode' => 'read'],
        ]);

        $result = $this->providers->get($providerKey)->run($job, $prompt);
        if (! (bool) ($result->ok ?? false)) {
            throw new \RuntimeException('provider_returned_not_ok:'.(string) ($result->errorCode ?? ''));
        }

        return (string) ($result->output !== '' ? $result->output : $result->stdout);
    }

    private function isolateJson(string $raw): string
    {
        $text = trim($raw);
        if (preg_match('/```(?:json)?\s*(.+?)```/su', $text, $m) === 1) {
            $text = trim($m[1]);
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return substr($text, $start, $end - $start + 1);
        }

        return $text;
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        $out = [];
        foreach ((array) $value as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $out[] = Str::limit($item, 400);
            }
            if (count($out) >= self::MAX_LIST_ITEMS) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<string,string>  $facts
     * @param  array<int,array<string,mixed>>  $droppedMoves
     * @return array<string,mixed>
     */
    private function degraded(string $status, array $facts, string $detail, array $droppedMoves = []): array
    {
        return [
            'schema_version' => 'atlas.ai.venture.strategist_analysis.v1',
            'analysis_status' => $status,
            'detail' => Str::limit($detail, 500),
            'facts' => $facts,
            'strategic_moves' => [],
            'dropped_moves' => $droppedMoves,
        ];
    }
}
