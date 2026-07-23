<?php

namespace App\Services\Ai\Company\Ventures;

use App\Models\AiJob;
use App\Models\AiVentureIdea;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AtlasDecide\AtlasStructuredOutputValidator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Governed generative ideation — the ONLY provider/LLM path in the Venture
 * Foundry. Runs exclusively as a batch action (`atlas:venture ideate-generate`),
 * never inline on a hot path.
 *
 * Same seam philosophy as ProviderObraDecomposer: the provider invocation is
 * isolated behind the protected {@see invokeProvider()} so tests exercise the
 * full parse/validate/gate path with zero spend.
 *
 * Anti-hallucination gates (fail-closed, cite-or-omit):
 *  - structured output must pass AtlasStructuredOutputValidator against a
 *    strict schema (enum urgency, required fields) or the batch yields zero;
 *  - market_size_usd only survives when accompanied by a non-empty
 *    market_size_assumption (no naked numbers);
 *  - 0-5 inputs are structurally clamped;
 *  - candidates are capped at the configured max and deduped against existing
 *    idea slugs;
 *  - every accepted idea lands as status=proposed / source=generated with the
 *    deterministic score — generation NEVER promotes.
 */
class VentureIdeaGenerationService
{
    public function __construct(
        private readonly AiProviderManager $providers,
        private readonly AtlasStructuredOutputValidator $validator,
        private readonly VentureIdeationService $ideation,
    ) {}

    /**
     * Generate idea candidates from an operator brief.
     *
     * @return array<string,mixed>
     */
    public function generate(string $brief, ?int $count = null): array
    {
        $brief = trim($brief);
        if ($brief === '') {
            throw VentureFoundryException::missingField('idea_generation', 'brief');
        }

        $max = max(1, min($count ?? (int) config('atlas_venture_foundry.ideation_max_ideas', 5), 10));
        $providerKey = config('atlas_venture_foundry.ideation_provider_key');
        $providerKey = is_string($providerKey) && $providerKey !== '' ? $providerKey : null;

        $raw = '';
        try {
            $raw = $this->invokeProvider($providerKey, $this->prompt($brief, $max));
        } catch (Throwable $e) {
            return $this->report($brief, $providerKey, 'provider_failed', [], [
                ['reason' => 'provider_error', 'detail' => $e->getMessage()],
            ]);
        }

        $validated = $this->validator->validate($this->isolateJson($raw), $this->schema());
        if (($validated['valid'] ?? false) !== true || ! is_array($validated['value'] ?? null)) {
            return $this->report($brief, $providerKey, 'invalid_structured_output', [], [
                ['reason' => 'schema_violation', 'detail' => implode('; ', (array) ($validated['errors'] ?? []))],
            ]);
        }

        [$created, $dropped] = $this->registerCandidates(
            (array) ($validated['value']['ideas'] ?? []),
            $max,
            $brief,
            $providerKey,
            $raw,
        );

        return $this->report($brief, $providerKey, 'ok', $created, $dropped);
    }

    /**
     * @param  array<int,mixed>  $candidates
     * @return array{0: array<int,AiVentureIdea>, 1: array<int,array<string,string>>}
     */
    private function registerCandidates(array $candidates, int $max, string $brief, ?string $providerKey, string $raw): array
    {
        $created = [];
        $dropped = [];

        foreach ($candidates as $candidate) {
            if (count($created) >= $max) {
                $dropped[] = ['reason' => 'max_ideas_cap', 'detail' => (string) ($candidate['title'] ?? '')];

                continue;
            }
            if (! is_array($candidate)) {
                $dropped[] = ['reason' => 'not_an_object', 'detail' => ''];

                continue;
            }

            $title = trim((string) ($candidate['title'] ?? ''));
            $ideaId = 'gen-'.Str::slug($title);
            if ($title === '' || Str::slug($title) === '') {
                $dropped[] = ['reason' => 'empty_title', 'detail' => ''];

                continue;
            }
            if (AiVentureIdea::query()->where('idea_id', $ideaId)->exists()) {
                $dropped[] = ['reason' => 'duplicate_existing', 'detail' => $ideaId];

                continue;
            }

            [$created, $dropped] = $this->registerCandidate(
                $candidate,
                $ideaId,
                $title,
                $brief,
                $providerKey,
                $raw,
                $created,
                $dropped,
            );
        }

        return [$created, $dropped];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<int,AiVentureIdea>  $created
     * @param  array<int,array<string,string>>  $dropped
     * @return array{0: array<int,AiVentureIdea>, 1: array<int,array<string,string>>}
     */
    private function registerCandidate(array $candidate, string $ideaId, string $title, string $brief, ?string $providerKey, string $raw, array $created, array $dropped): array
    {
        [$marketSize, $assumption] = $this->candidateMarketSizeAndAssumption($candidate);

        try {
            $created[] = $this->ideation->register([
                'idea_id' => $ideaId,
                'title' => $title,
                'problem' => (string) ($candidate['problem'] ?? ''),
                'icp' => (string) ($candidate['icp'] ?? ''),
                'pain' => (string) ($candidate['pain'] ?? ''),
                'urgency' => (string) ($candidate['urgency'] ?? 'medium'),
                'source' => VentureIdeationService::SOURCE_GENERATED,
                'market_size_usd' => $marketSize,
                'pain_severity' => $this->clamp($candidate['pain_severity'] ?? 3),
                'founder_fit' => $this->clamp($candidate['founder_fit'] ?? 3),
                'sovereignty_fit' => $this->clamp($candidate['sovereignty_fit'] ?? 3),
                'generation_meta' => [
                    'schema_version' => 'atlas.ai.venture.idea_generation.v1',
                    'provider_key' => $providerKey ?? 'runtime_default',
                    'brief' => Str::limit($brief, 400),
                    'rationale' => Str::limit(trim((string) ($candidate['rationale'] ?? '')), 600),
                    'market_size_assumption' => $assumption !== '' ? Str::limit($assumption, 400) : null,
                    'raw_output_hash' => hash('sha256', $raw),
                ],
            ]);
        } catch (VentureFoundryException $e) {
            $dropped[] = ['reason' => 'gate_rejected', 'detail' => $e->getMessage()];
        }

        return [$created, $dropped];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array{0: float|null, 1: string}
     */
    private function candidateMarketSizeAndAssumption(array $candidate): array
    {
        // Cite-or-omit: a market size without a stated assumption is dropped.
        $marketSize = null;
        $assumption = trim((string) ($candidate['market_size_assumption'] ?? ''));
        if (isset($candidate['market_size_usd']) && is_numeric($candidate['market_size_usd']) && (float) $candidate['market_size_usd'] > 0 && $assumption !== '') {
            $marketSize = (float) $candidate['market_size_usd'];
        }

        return [$marketSize, $assumption];
    }

    /**
     * Provider seam — override in tests for zero-spend runs.
     */
    protected function invokeProvider(?string $providerKey, string $prompt): string
    {
        $job = new AiJob;
        $job->forceFill([
            'kind' => 'venture_ideation',
            'provider' => $providerKey,
            'model' => (string) (config('atlas_venture_foundry.ideation_model') ?? ''),
            'timeout_seconds' => (int) config('atlas_venture_foundry.ideation_timeout_seconds', 180),
            'metadata' => ['purpose' => 'venture_idea_generation', 'proposal_only' => true, 'permission_mode' => 'read'],
        ]);

        $result = $this->providers->get($providerKey)->run($job, $prompt);
        if (! (bool) ($result->ok ?? false)) {
            throw new \RuntimeException('provider_returned_not_ok:'.(string) ($result->errorCode ?? ''));
        }

        return (string) ($result->output !== '' ? $result->output : $result->stdout);
    }

    private function prompt(string $brief, int $max): string
    {
        $existing = AiVentureIdea::query()
            ->orderByDesc('created_at')
            ->limit(20)
            ->pluck('title')
            ->all();

        $existingBlock = $existing === []
            ? '(nenhuma)'
            : '- '.implode("\n- ", array_map(fn ($t) => (string) $t, $existing));

        return <<<PROMPT
Você é o estrategista de criação de empresas de um operador solo (local-first, soberania sobre ferramentas, forte em engenharia de software e automação com IA).

Brief do operador:
{$brief}

Gere até {$max} ideias de negócio DISTINTAS e acionáveis. Para cada ideia responda os campos exigidos. Regras duras:
- problem, icp e pain devem ser específicos (quem sofre, qual dor, contexto), nunca genéricos.
- market_size_usd: só informe se você conseguir declarar a premissa em market_size_assumption (ex.: "X empresas alvo * ticket Y/ano"). Sem premissa, use null nos dois.
- urgency: um de [low, medium, high, critical].
- pain_severity, founder_fit, sovereignty_fit: inteiros 0-5 (founder_fit = aderência ao perfil do operador acima; sovereignty_fit = quanto o negócio preserva independência local-first).
- rationale: 1-2 frases do porquê agora.
- NÃO repita ideias já existentes:
{$existingBlock}

Responda APENAS com JSON estrito, sem markdown, no formato:
{"ideas":[{"title":"...","problem":"...","icp":"...","pain":"...","urgency":"medium","market_size_usd":1000000000,"market_size_assumption":"...","pain_severity":4,"founder_fit":4,"sovereignty_fit":4,"rationale":"..."}]}
PROMPT;
    }

    /**
     * @return array<string,mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['ideas'],
            'properties' => [
                'ideas' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['title', 'problem', 'icp', 'pain', 'urgency'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'problem' => ['type' => 'string'],
                            'icp' => ['type' => 'string'],
                            'pain' => ['type' => 'string'],
                            'urgency' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']],
                            'market_size_usd' => ['type' => ['number', 'integer', 'null']],
                            'market_size_assumption' => ['type' => ['string', 'null']],
                            'pain_severity' => ['type' => ['number', 'integer', 'null']],
                            'founder_fit' => ['type' => ['number', 'integer', 'null']],
                            'sovereignty_fit' => ['type' => ['number', 'integer', 'null']],
                            'rationale' => ['type' => ['string', 'null']],
                        ],
                    ],
                ],
            ],
        ];
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

    private function clamp(mixed $value): int
    {
        return max(0, min(5, (int) (is_numeric($value) ? $value : 3)));
    }

    /**
     * @param  array<int,AiVentureIdea>  $created
     * @param  array<int,array<string,string>>  $dropped
     * @return array<string,mixed>
     */
    private function report(string $brief, ?string $providerKey, string $status, array $created, array $dropped): array
    {
        return [
            'schema_version' => 'atlas.ai.venture.idea_generation_run.v1',
            'generation_status' => $status,
            'provider_key' => $providerKey ?? 'runtime_default',
            'brief' => Str::limit($brief, 400),
            'created' => count($created),
            'ideas' => array_map(fn (AiVentureIdea $idea) => $idea->only(['idea_id', 'title', 'score', 'status', 'source']), $created),
            'dropped' => $dropped,
        ];
    }
}
