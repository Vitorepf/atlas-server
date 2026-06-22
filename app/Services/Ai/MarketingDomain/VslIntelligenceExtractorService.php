<?php

namespace App\Services\Ai\MarketingDomain;

use App\Models\AiJob;
use App\Models\AiMarketingVslAsset;
use App\Services\Ai\AiProviderManager;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Pillar 1 — VSL intelligence extraction. Reads a transcribed VSL and runs a
 * focused multi-pass LLM analysis (governed provider, never a pinned model) to
 * fill the offer anatomy, the Google-Search funnel kit, and the campaign assets.
 *
 * Quality layers:
 *  - entity normalization pass (fixes ASR errors in names/brands/drugs)
 *  - multi-run consensus on the critical market/mechanism pass
 *  - a comprehension critic that scores each field and re-runs the weak passes
 *
 * Uses an UNSAVED AiJob so it does not depend on the ai_jobs runtime table.
 */
class VslIntelligenceExtractorService
{
    public ?string $lastModel = null;

    /** @var array<int,string> */
    private const PASSES = ['entities', 'market', 'offer', 'funnel', 'keywords', 'creative'];

    public function __construct(
        private readonly AiProviderManager $providers,
    ) {}

    public function extract(AiMarketingVslAsset $asset, int $criticalConsensus = 2): AiMarketingVslAsset
    {
        $transcript = trim((string) $asset->transcript);
        if ($transcript === '') {
            return $this->markFailed($asset, 'No transcript to analyze.');
        }

        $asset->forceFill([
            'status' => 'analyzing',
            'structure_status' => 'pending',
            'top_terms' => $this->computeTopTerms($transcript),
        ])->save();

        $errors = [];
        $ok = [];

        // Entity normalization first → downstream passes use canonical names.
        $this->safe('entities', $errors, $ok, function () use ($asset, $transcript): void {
            $this->runAndApply('entities', $asset, $transcript);
        });
        $asset->refresh();

        // Critical pass (market/mechanism) → multi-run consensus.
        $this->safe('market', $errors, $ok, function () use ($asset, $transcript, $criticalConsensus): void {
            $this->runCritical('market', $asset, $transcript, max(1, $criticalConsensus));
        });
        $asset->refresh();

        // Remaining passes — each is independent; one failure must not lose the rest.
        foreach (['offer', 'funnel', 'keywords', 'creative'] as $key) {
            $this->safe($key, $errors, $ok, function () use ($key, $asset, $transcript): void {
                $this->runAndApply($key, $asset, $transcript);
            });
            $asset->refresh();
        }

        if ($ok === []) {
            return $this->markFailed($asset, 'All extraction passes failed: '.Str::limit(json_encode($errors) ?: '', 400, ''));
        }

        // Comprehension critic → score + auto-improve weak passes (best-effort).
        try {
            $this->runCritic($asset, $transcript);
        } catch (Throwable $e) {
            $errors['critic'] = Str::limit($e->getMessage(), 200, '');
        }
        $asset->refresh();

        $diagnostics = is_array($asset->diagnostics) ? $asset->diagnostics : [];
        $asset->forceFill([
            'status' => 'structured',
            'structure_status' => $errors === [] ? 'ready' : 'partial',
            'structured_at' => now(),
            'extraction_model' => $this->lastModel ?? 'hermes_cli',
            'reason' => $errors === [] ? null : 'passes com erro: '.implode(', ', array_keys($errors)),
            'diagnostics' => array_merge($diagnostics, ['pass_errors' => $errors ?: null, 'passes_ok' => $ok]),
        ])->save();

        return $asset->refresh();
    }

    /**
     * Run a pass, recording success/failure without aborting the pipeline.
     *
     * @param  array<string,string>  $errors
     * @param  array<int,string>  $ok
     */
    private function safe(string $key, array &$errors, array &$ok, callable $fn): void
    {
        try {
            $fn();
            $ok[] = $key;
        } catch (Throwable $e) {
            $errors[$key] = Str::limit($e->getMessage(), 200, '');
        }
    }

    private function markFailed(AiMarketingVslAsset $asset, string $reason): AiMarketingVslAsset
    {
        AiMarketingVslAsset::query()->whereKey($asset->getKey())->update([
            'status' => $asset->transcript ? 'transcribed' : 'failed',
            'structure_status' => 'failed',
            'reason' => $reason,
        ]);

        return $asset->fresh() ?? $asset;
    }

    // ---- orchestration -----------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function runAndApply(string $key, AiMarketingVslAsset $asset, string $transcript): array
    {
        $result = $this->callModel($this->systemFor($key), $this->groundedUser($asset, $transcript), $this->schemaFor($key));
        $this->applyPass($key, $asset, $result);

        return $result;
    }

    private function runCritical(string $key, AiMarketingVslAsset $asset, string $transcript, int $n): void
    {
        if ($n <= 1) {
            $this->runAndApply($key, $asset, $transcript);

            return;
        }

        $runs = [];
        for ($i = 0; $i < $n; $i++) {
            $runs[] = $this->callModel($this->systemFor($key), $this->groundedUser($asset, $transcript), $this->schemaFor($key));
        }

        $reconciled = $this->callModel(
            $this->systemReconcile($this->schemaFor($key)),
            'EXTRAÇÕES INDEPENDENTES ('.count($runs).'x):'."\n".json_encode($runs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n=== TRANSCRIÇÃO ===\n".$transcript,
            $this->schemaFor($key)
        );
        $this->applyPass($key, $asset, $reconciled);
    }

    private function runCritic(AiMarketingVslAsset $asset, string $transcript): void
    {
        $critique = $this->callModel(
            $this->systemCritic(),
            "ESTRUTURA EXTRAÍDA:\n".$this->snapshotJson($asset)."\n\n=== TRANSCRIÇÃO ===\n".$transcript,
            'atlas.vsl.quality.v1'
        );
        $asset->forceFill(['structure_quality' => $critique])->save();

        $weak = array_values(array_filter(
            is_array($critique['weak_passes'] ?? null) ? $critique['weak_passes'] : [],
            static fn ($k): bool => is_string($k) && in_array($k, self::PASSES, true)
        ));
        $weak = array_slice(array_values(array_unique($weak)), 0, 3);
        if ($weak === []) {
            return;
        }

        foreach ($weak as $key) {
            $this->runAndApply($key, $asset, $transcript);
            $asset->refresh();
        }

        $rescore = $this->callModel(
            $this->systemCritic(),
            'ESTRUTURA EXTRAÍDA (revisada após re-rodar '.implode(', ', $weak).'):'."\n".$this->snapshotJson($asset)."\n\n=== TRANSCRIÇÃO ===\n".$transcript,
            'atlas.vsl.quality.v1'
        );
        $rescore['reran_passes'] = $weak;
        $asset->forceFill(['structure_quality' => $rescore])->save();
    }

    private function applyPass(string $key, AiMarketingVslAsset $asset, array $r): void
    {
        match ($key) {
            'entities' => $asset->forceFill([
                'entities' => is_array($r['entities'] ?? null) ? $r['entities'] : $asset->entities,
            ]),
            'market' => $asset->forceFill([
                'niche' => $this->str($r['niche'] ?? null, 290) ?? $asset->niche,
                'sub_niche' => $this->str($r['sub_niche'] ?? null, 390),
                'problem_mechanism' => $this->str($r['problem_mechanism'] ?? null),
                'solution_mechanism' => $this->str($r['solution_mechanism'] ?? null),
                'big_idea' => $this->str($r['big_idea'] ?? null),
                'core_promise' => $this->str($r['core_promise'] ?? null),
                'awareness_level' => $this->str($r['awareness_level'] ?? null, 150),
                'sophistication_level' => $this->str($r['sophistication_level'] ?? null, 70),
                'avatar' => is_array($r['avatar'] ?? null) ? $r['avatar'] : $asset->avatar,
            ]),
            'offer' => $asset->forceFill([
                'essential_summary' => $this->str($r['essential_summary'] ?? null),
                'offer' => is_array($r['offer'] ?? null) ? $r['offer'] : $asset->offer,
                'persuasion' => is_array($r['persuasion'] ?? null) ? $r['persuasion'] : $asset->persuasion,
                'pitch_starts_at_seconds' => isset($r['pitch_starts_at_seconds']) ? (int) $r['pitch_starts_at_seconds'] : $asset->pitch_starts_at_seconds,
                'claims' => is_array($r['claims'] ?? null) ? $r['claims'] : $asset->claims,
            ]),
            'funnel' => $asset->forceFill([
                'funnel_kit' => is_array($r['funnel_kit'] ?? null) ? $r['funnel_kit'] : $asset->funnel_kit,
                'levers' => is_array($r['levers'] ?? null) ? $r['levers'] : $asset->levers,
            ]),
            'keywords' => $asset->forceFill([
                'mechanism_name' => $this->str($r['mechanism_name'] ?? null, 290) ?? $asset->mechanism_name,
                'target_geo' => $this->str($r['target_geo'] ?? null, 110) ?? $asset->target_geo,
                'keywords' => is_array($r['keywords'] ?? null) ? $r['keywords'] : $asset->keywords,
            ]),
            'creative' => $asset->forceFill([
                'value_equation' => is_array($r['value_equation'] ?? null) ? $r['value_equation'] : $asset->value_equation,
                'advertorial_brief' => is_array($r['advertorial_brief'] ?? null) ? $r['advertorial_brief'] : $asset->advertorial_brief,
                'ad_assets' => is_array($r['ad_assets'] ?? null) ? $r['ad_assets'] : $asset->ad_assets,
                'cta' => is_array($r['cta'] ?? null) ? $r['cta'] : $asset->cta,
                'objection_rebuttals' => is_array($r['objection_rebuttals'] ?? null) ? $r['objection_rebuttals'] : $asset->objection_rebuttals,
                'power_phrases' => is_array($r['power_phrases'] ?? null) ? $r['power_phrases'] : $asset->power_phrases,
                'beat_timestamps' => is_array($r['beat_timestamps'] ?? null) ? $r['beat_timestamps'] : $asset->beat_timestamps,
            ]),
            default => null,
        };

        $asset->save();
    }

    private function systemFor(string $key): string
    {
        return match ($key) {
            'entities' => $this->systemEntities(),
            'market' => $this->systemMarket(),
            'offer' => $this->systemOffer(),
            'funnel' => $this->systemFunnel(),
            'keywords' => $this->systemKeywords(),
            'creative' => $this->systemCreative(),
            default => throw new RuntimeException("Unknown pass [{$key}]"),
        };
    }

    private function schemaFor(string $key): string
    {
        return match ($key) {
            'entities' => 'atlas.vsl.entities.v1',
            'market' => 'atlas.vsl.market.v1',
            'offer' => 'atlas.vsl.offer.v1',
            'funnel' => 'atlas.vsl.funnel.v1',
            'keywords' => 'atlas.vsl.keywords.v1',
            'creative' => 'atlas.vsl.creative.v1',
            default => throw new RuntimeException("Unknown pass [{$key}]"),
        };
    }

    // ---- provider plumbing -------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function callModel(string $system, string $user, string $schemaVersion, int $timeoutSeconds = 600): array
    {
        $lastError = 'unknown error';
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $payload = $attempt === 1
                ? $user
                : $user."\n\nIMPORTANTE: a resposta anterior não pôde ser lida. Responda SOMENTE com UM bloco json VÁLIDO e COMPLETO (sem truncar, sem texto fora do bloco json).";

            $job = new AiJob([
                'trace_id' => (string) Str::ulid(),
                'kind' => 'vsl_intelligence_extraction',
                'status' => 'pending',
                'input_text' => $payload,
                'prompt' => $system,
                'payload' => ['model_identity_source' => 'provider_default_identity'],
                'timeout_seconds' => $timeoutSeconds,
            ]);
            $job->id = (string) Str::uuid();

            $result = $this->providers->get()->run($job, $system."\n\n".$payload);
            if (! $result->ok) {
                $lastError = $result->errorMessage ?: ($result->stderr ?: 'provider error');

                continue;
            }

            $parsed = $this->parseJson($result->output, $schemaVersion);
            if ($parsed !== null) {
                return $parsed;
            }
            $lastError = "could not parse JSON ({$schemaVersion})";
        }

        throw new RuntimeException('LLM call failed: '.$lastError);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function parseJson(string $output, string $schemaVersion): ?array
    {
        $blocks = [];
        if (preg_match_all('/```(?:json)?\s*(\{.*?\})\s*```/isu', $output, $m)) {
            foreach ($m[1] as $b) {
                $blocks[] = trim($b);
            }
        }
        $trimmed = trim($output);
        if (str_starts_with($trimmed, '{') && str_ends_with($trimmed, '}')) {
            $blocks[] = $trimmed;
        }
        $first = strpos($output, '{');
        $last = strrpos($output, '}');
        if ($first !== false && $last !== false && $last > $first) {
            $blocks[] = substr($output, $first, $last - $first + 1);
        }

        $fallback = null;
        foreach (array_values(array_unique($blocks)) as $block) {
            try {
                $decoded = json_decode($block, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
            if (! is_array($decoded)) {
                continue;
            }
            if (($decoded['schema_version'] ?? null) === $schemaVersion) {
                return $decoded;
            }
            $fallback ??= $decoded;
        }

        return $fallback;
    }

    private function str(mixed $value, int $limit = 6000): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }

    private function groundedUser(AiMarketingVslAsset $asset, string $transcript): string
    {
        $prior = json_encode([
            'entities' => $asset->entities,
            'niche' => $asset->niche,
            'sub_niche' => $asset->sub_niche,
            'mechanism_name' => $asset->mechanism_name,
            'problem_mechanism' => $asset->problem_mechanism,
            'solution_mechanism' => $asset->solution_mechanism,
            'big_idea' => $asset->big_idea,
            'core_promise' => $asset->core_promise,
            'awareness_level' => $asset->awareness_level,
            'avatar' => $asset->avatar,
            'offer' => $asset->offer,
            'persuasion' => $asset->persuasion,
            'top_terms' => $asset->top_terms,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        return "ESTRUTURA EXTRAÍDA (entities = nomes canônicos; top_terms = termos mais repetidos da VSL — use-os):\n{$prior}\n\n=== TRANSCRIÇÃO COMPLETA DA VSL ===\n{$transcript}";
    }

    private function snapshotJson(AiMarketingVslAsset $asset): string
    {
        return json_encode([
            'niche' => $asset->niche,
            'sub_niche' => $asset->sub_niche,
            'mechanism_name' => $asset->mechanism_name,
            'problem_mechanism' => $asset->problem_mechanism,
            'solution_mechanism' => $asset->solution_mechanism,
            'big_idea' => $asset->big_idea,
            'core_promise' => $asset->core_promise,
            'awareness_level' => $asset->awareness_level,
            'sophistication_level' => $asset->sophistication_level,
            'pitch_starts_at_seconds' => $asset->pitch_starts_at_seconds,
            'essential_summary' => $asset->essential_summary,
            'avatar' => $asset->avatar,
            'offer' => $asset->offer,
            'persuasion' => $asset->persuasion,
            'claims' => $asset->claims,
            'funnel_kit' => $asset->funnel_kit,
            'keywords' => $asset->keywords,
            'value_equation' => $asset->value_equation,
            'advertorial_brief' => $asset->advertorial_brief,
            'ad_assets' => $asset->ad_assets,
            'cta' => $asset->cta,
            'objection_rebuttals' => $asset->objection_rebuttals,
            'power_phrases' => $asset->power_phrases,
            'beat_timestamps' => $asset->beat_timestamps,
            'entities' => $asset->entities,
            'target_geo' => $asset->target_geo,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * Deterministic word/phrase frequency over the transcript (stopword-filtered).
     * The exact "most-used keywords" — not an LLM guess.
     *
     * @return array<string,mixed>
     */
    private function computeTopTerms(string $transcript, int $words = 20, int $phrases = 15): array
    {
        $text = mb_strtolower($transcript);
        $text = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);
        $tokens = array_values(array_filter(
            preg_split('/\s+/u', $text) ?: [],
            static fn (string $t): bool => $t !== ''
        ));

        $stop = $this->stopwords();
        $meaningful = static fn (string $t): bool => mb_strlen($t) >= 3 && ! isset($stop[$t]) && ! ctype_digit($t);

        $uni = [];
        foreach ($tokens as $t) {
            if ($meaningful($t)) {
                $uni[$t] = ($uni[$t] ?? 0) + 1;
            }
        }
        arsort($uni);
        $topWords = [];
        foreach (array_slice($uni, 0, $words, true) as $term => $count) {
            $topWords[] = ['term' => $term, 'count' => $count];
        }

        $bi = [];
        $n = count($tokens);
        for ($i = 0; $i < $n - 1; $i++) {
            // skip repeated adjacent words (transcription stutter: "mix mix")
            if ($tokens[$i] !== $tokens[$i + 1]
                && $meaningful($tokens[$i])
                && $meaningful($tokens[$i + 1])) {
                $key = $tokens[$i].' '.$tokens[$i + 1];
                $bi[$key] = ($bi[$key] ?? 0) + 1;
            }
        }
        arsort($bi);
        $topPhrases = [];
        foreach (array_slice($bi, 0, $phrases, true) as $term => $count) {
            if ($count < 2) {
                continue;
            }
            $topPhrases[] = ['term' => $term, 'count' => $count];
        }

        return ['top_words' => $topWords, 'top_phrases' => $topPhrases];
    }

    /**
     * @return array<string,bool>
     */
    private function stopwords(): array
    {
        $list = [
            // EN
            'the', 'and', 'for', 'you', 'your', 'that', 'this', 'with', 'are', 'was', 'were', 'have', 'has', 'had',
            'not', 'but', 'they', 'them', 'their', 'there', 'here', 'also', 'very', 'much', 'only', 'even', 'because',
            'from', 'out', 'now', 'can', 'will', 'just', 'about', 'what', 'all', 'more', 'when', 'who', 'how', 'one',
            'two', 'like', 'then', 'get', 'got', 'our', 'its', 'his', 'her', 'him', 'she', 'too', 'any', 'some', 'than',
            'into', 'over', 'off', 'been', 'being', 'does', 'did', 'doing', 'would', 'could', 'should', 'while', 'where',
            'which', 'these', 'those', 'don', 'didn', 'doesn', 'isn', 'aren', 'wasn',
            // PT
            'que', 'nao', 'uma', 'com', 'para', 'por', 'dos', 'das', 'isso', 'esse', 'essa', 'este', 'esta', 'mais',
            'muito', 'como', 'quando', 'onde', 'porque', 'sobre', 'ate', 'mas', 'tambem', 'entao', 'voce', 'eles',
            'elas', 'ele', 'ela', 'seu', 'sua', 'meu', 'minha', 'nas', 'aos', 'foi', 'sao', 'ser', 'ter', 'tem',
            'estao', 'pelo', 'pela', 'num', 'numa', 'aqui', 'ali', 'sim', 'vai', 'vou', 'fazer', 'coisa', 'agora',
            'todo', 'toda', 'todos', 'todas', 'uns', 'umas', 'sem', 'seus', 'suas', 'meus', 'minhas', 'nossa', 'nosso',
            'deste', 'desta', 'disso', 'nos',
        ];

        return array_fill_keys($list, true);
    }

    // ---- prompts -----------------------------------------------------------

    private function systemEntities(): string
    {
        return <<<'PROMPT'
Você analisa a transcrição de uma VSL gerada por ASR (pode conter erros em nomes próprios, marcas e fármacos).
Extraia e NORMALIZE as entidades-chave, corrigindo erros prováveis de transcrição.

Responda APENAS com UM bloco de código json:
```json
{
  "schema_version": "atlas.vsl.entities.v1",
  "entities": [
    {"type": "pessoa|marca|produto|ingrediente|empresa|lugar|claim_numerico", "raw": "como apareceu no transcript", "canonical": "forma correta/canônica", "note": "contexto"}
  ]
}
```
Ex.: {"type":"pessoa","raw":"atiyah","canonical":"Dr. Peter Attia","note":"autoridade citada"}.
Inclua pessoas, marcas, produtos, ingredientes/fármacos (ex.: Ozempic, Mounjaro, retatrutide), empresas e números de claim relevantes. Não escreva nada fora do json.
PROMPT;
    }

    private function systemMarket(): string
    {
        return <<<'PROMPT'
Você é um estrategista sênior de copy de resposta direta e marketing de afiliados, especialista em dissecar VSLs (Video Sales Letters).
Analise a transcrição da VSL e extraia a ANATOMIA DE MERCADO da oferta.

Escreva a análise em PORTUGUÊS (o operador é brasileiro), mantendo nomes próprios/termos da oferta como estão.

Responda APENAS com UM bloco de código json, exatamente neste formato:
```json
{
  "schema_version": "atlas.vsl.market.v1",
  "niche": "o mercado real (ex.: emagrecimento mulheres 40+), não o disfarce",
  "sub_niche": "o ângulo específico",
  "problem_mechanism": "o mecanismo ÚNICO do PROBLEMA — a causa-raiz que a VSL culpa ('não é sua culpa, o verdadeiro motivo é X')",
  "solution_mechanism": "o mecanismo ÚNICO da SOLUÇÃO — como o produto resolve de um jeito diferente de tudo",
  "big_idea": "a grande ideia central",
  "core_promise": "a grande promessa concreta",
  "awareness_level": "unaware|problem_aware|solution_aware|product_aware|most_aware",
  "sophistication_level": "1|2|3|4|5",
  "avatar": {
    "quem": "quem é o prospecto",
    "demografia": "idade, gênero, situação",
    "dores": ["..."],
    "desejos": ["..."],
    "objecoes": ["..."],
    "solucoes_que_falharam": ["..."],
    "emocoes": ["..."]
  }
}
```
REGRAS DE FORMATO: "niche" e "sub_niche" devem ser CURTOS (máx ~10 palavras cada — um rótulo, não um parágrafo). "awareness_level" = APENAS um token do enum (ex.: solution_aware). "sophistication_level" = APENAS o dígito de 1 a 5. Não escreva nada fora do bloco json.
PROMPT;
    }

    private function systemOffer(): string
    {
        return <<<'PROMPT'
Você é um estrategista sênior de resposta direta. Analise a transcrição da VSL e extraia a OFERTA e a ESTRUTURA DE PERSUASÃO.
Escreva a análise em PORTUGUÊS, mantendo nomes/termos da oferta como estão.

Responda APENAS com UM bloco de código json:
```json
{
  "schema_version": "atlas.vsl.offer.v1",
  "essential_summary": "resumo só do essencial da VSL (8-15 frases): do gancho ao fechamento, com promessa, mecanismos, prova e oferta",
  "offer": {
    "product_name": "...",
    "type": "suplemento|infoproduto|protocolo|software|outro",
    "price": "preço principal se mencionado",
    "currency": "USD|BRL|...",
    "stack": ["itens/valor empilhados na oferta"],
    "bonuses": ["..."],
    "guarantee": "garantia oferecida",
    "upsells": ["..."]
  },
  "persuasion": {
    "hook": "o gancho/lead — como a VSL abre nos primeiros segundos",
    "lead_type": "história|notícia|promessa|pergunta|proclamação|outro",
    "story": "resumo da história/personagem",
    "emotional_drivers": ["medo, esperança, vergonha, etc."],
    "proof": [{"type": "clínica|depoimento|autoridade|demonstração|estatística", "detail": "..."}],
    "close": "como fecha/chama pra ação",
    "scarcity": "escassez usada",
    "urgency": "urgência usada"
  },
  "pitch_starts_at_seconds": 0,
  "claims": [{"claim": "afirmação forte feita", "type": "saúde|renda|garantia|resultado"}]
}
```
Estime pitch_starts_at_seconds (segundo aproximado em que o preço/oferta aparece). Não escreva nada fora do json.
PROMPT;
    }

    private function systemFunnel(): string
    {
        return <<<'PROMPT'
Você é um estrategista de tráfego pago para afiliados, especialista em GOOGLE SEARCH. Recebe a estrutura já extraída da oferta + a transcrição.
Seu objetivo: montar o KIT inicial de campanha para levar o máximo de gente "com cartão na mão" até o pitch, com message-match perfeito anúncio → pré-sell → VSL.

A ANÁLISE escreva em PORTUGUÊS. As keywords e textos de anúncio escreva NO MESMO IDIOMA falado na VSL (idioma da oferta/tráfego).

Responda APENAS com UM bloco de código json:
```json
{
  "schema_version": "atlas.vsl.funnel.v1",
  "funnel_kit": {
    "recommended_presell_angle": "o ângulo do pré-sell/advertorial que melhor aquece o frio até a VSL",
    "awareness_entry_point": "em que nível de consciência o anúncio captura o sujeito",
    "message_match_notes": "como manter coerência anúncio→pré-sell→VSL",
    "ad_angles": ["3-6 ângulos de anúncio distintos"],
    "keyword_themes": {
      "problem_aware": ["..."],
      "solution_aware": ["..."],
      "product_aware": ["..."],
      "brand": ["..."]
    },
    "ad_hooks": ["5-8 hooks curtos para headline de anúncio"]
  },
  "levers": {
    "hook": "o gancho atual (alavanca editável)",
    "headline": "a headline-mãe atual (alavanca editável)",
    "close": "o fechamento atual (alavanca editável)",
    "angle": "o ângulo dominante (alavanca editável)",
    "note": "cada campo é uma alavanca que o motor de decisão poderá ajustar"
  }
}
```
Não escreva nada fora do json.
PROMPT;
    }

    private function systemKeywords(): string
    {
        return <<<'PROMPT'
Você é um media buyer de Google Search para afiliados. Recebe a estrutura da oferta + a transcrição.
Monte o conjunto de PALAVRAS-CHAVE pronto pra campanha, no MESMO IDIOMA da VSL. Use os termos mais repetidos da VSL (top_terms) para gerar keywords de ALTA INTENÇÃO que casam com quem assiste a VSL (público de alta conversão).

Responda APENAS com UM bloco de código json:
```json
{
  "schema_version": "atlas.vsl.keywords.v1",
  "mechanism_name": "nome curto/branded do mecanismo único (ex.: Triple-Hormone Protocol)",
  "target_geo": "mercado-alvo provável: país + idioma (ex.: US / inglês)",
  "keywords": {
    "clusters": [
      {"name": "nome do ad group", "awareness": "problem_aware|solution_aware|product_aware|brand", "intent": "intenção de busca", "match_type": "broad|phrase|exact", "terms": ["..."]}
    ],
    "negatives": ["termos a EXCLUIR (free, recipe, diy, jobs, cheap, etc.)"],
    "high_intent_from_vsl": ["keywords derivadas dos termos mais repetidos da VSL"],
    "notes": "agrupamento + 'confirmar volume no Keyword Planner'"
  }
}
```
Limites pra NÃO truncar o json: no máximo 6 clusters, até 12 terms por cluster, até 15 negatives, até 12 high_intent_from_vsl. SEMPRE preencha negatives. Não escreva nada fora do json.
PROMPT;
    }

    private function systemCreative(): string
    {
        return <<<'PROMPT'
Você é copywriter de resposta direta + media buyer. Recebe a estrutura + a transcrição.
Monte o KIT criativo pronto pra campanha. ANÁLISE em português; textos de anúncio/advertorial no IDIOMA da VSL.
No advertorial, TEÇA os termos/frases mais repetidos da VSL (top_terms) na copy, pra congruência e message-match (mesma linguagem do anúncio → página → VSL).

Responda APENAS com UM bloco de código json:
```json
{
  "schema_version": "atlas.vsl.creative.v1",
  "value_equation": {"dream_outcome": "...", "perceived_likelihood": "...", "time_delay": "...", "effort_sacrifice": "...", "notes": "onde fortalecer"},
  "advertorial_brief": {"format": "listicle|story|review", "title_options": ["3-5 títulos"], "items": ["itens/seções do advertorial"], "congruency_bridge": "como a promessa do advertorial casa com a da VSL", "vsl_terms_to_weave": ["termos repetidos da VSL a usar na página"], "proof_to_include": ["..."], "cta": "..."},
  "ad_assets": {"headlines": ["ate 15, cada uma com no maximo 30 caracteres"], "descriptions": ["ate 4, cada uma com no maximo 90 caracteres"], "sitelinks": ["..."], "callouts": ["..."], "structured_snippets": ["..."]},
  "cta": {"exact_wording": "a chamada exata da VSL", "next_step": "...", "offer_url": "se mencionada, senao null"},
  "objection_rebuttals": [{"objection": "...", "rebuttal": "como a VSL responde"}],
  "power_phrases": ["frases/palavras exatas de alta conversao ditas na VSL"],
  "beat_timestamps": {"hook_at": 0, "mechanism_at": 0, "proof_at": 0, "scarcity_at": 0, "close_at": 0}
}
```
headlines com no maximo 30 caracteres; descriptions com no maximo 90 caracteres. timestamps em segundos. Nao escreva nada fora do json.
PROMPT;
    }

    private function systemReconcile(string $schemaVersion): string
    {
        return 'Você recebe N extrações independentes (mesmo schema) da MESMA VSL. '
            .'Reconcilie-as na versão mais PRECISA, COMPLETA e CONSISTENTE — resolva divergências pela evidência do transcript, mantenha o que é consenso e descarte o que parece alucinação de uma run só. '
            ."Responda APENAS com UM bloco de código json com schema_version \"{$schemaVersion}\", mantendo EXATAMENTE a mesma estrutura de campos das extrações. Não escreva nada fora do json.";
    }

    private function systemCritic(): string
    {
        return <<<'PROMPT'
Você é um auditor de qualidade de extração. Recebe a ESTRUTURA extraída de uma VSL + a transcrição completa.
Pontue cada grupo de campos de 0 a 10 por COMPLETUDE e CONFIANÇA (fidelidade ao transcript), aponte campos fracos/faltando, e diga quais PASSADAS precisam re-rodar.
Passadas válidas (use exatamente estas chaves em weak_passes): entities, market, offer, funnel, keywords, creative.

Responda APENAS com UM bloco de código json:
```json
{
  "schema_version": "atlas.vsl.quality.v1",
  "overall_score": 0.0,
  "field_scores": [
    {"field": "nome_do_campo", "score": 0.0, "completeness": 0.0, "confidence": 0.0, "issue": "o que está fraco/faltando ou '' se ok"}
  ],
  "weak_passes": ["apenas as chaves das passadas que precisam re-rodar; vazio se tudo bom"],
  "notes": "resumo da qualidade"
}
```
Seja rigoroso e honesto: só marque uma passada em weak_passes se ela estiver realmente fraca, incompleta ou inconsistente com o transcript. Não escreva nada fora do json.
PROMPT;
    }
}
