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
 * fill the offer anatomy: niche, problem/solution mechanism, avatar, offer,
 * persuasion, plus the Google-Search funnel kit and compliance flags.
 *
 * Uses an UNSAVED AiJob so it does not depend on the ai_jobs runtime table.
 */
class VslIntelligenceExtractorService
{
    public ?string $lastModel = null;

    public function __construct(
        private readonly AiProviderManager $providers,
    ) {}

    public function extract(AiMarketingVslAsset $asset): AiMarketingVslAsset
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

        try {
            // Pass A — market & mechanism
            $a = $this->callModel($this->systemMarket(), $this->userTranscript($transcript), 'atlas.vsl.market.v1');
            $asset->forceFill([
                'niche' => $this->str($a['niche'] ?? null, 290) ?? $asset->niche,
                'sub_niche' => $this->str($a['sub_niche'] ?? null, 390),
                'problem_mechanism' => $this->str($a['problem_mechanism'] ?? null),
                'solution_mechanism' => $this->str($a['solution_mechanism'] ?? null),
                'big_idea' => $this->str($a['big_idea'] ?? null),
                'core_promise' => $this->str($a['core_promise'] ?? null),
                'awareness_level' => $this->str($a['awareness_level'] ?? null, 150),
                'sophistication_level' => $this->str($a['sophistication_level'] ?? null, 70),
                'avatar' => is_array($a['avatar'] ?? null) ? $a['avatar'] : null,
            ])->save();

            // Pass B — offer & persuasion
            $b = $this->callModel($this->systemOffer(), $this->userTranscript($transcript), 'atlas.vsl.offer.v1');
            $asset->forceFill([
                'essential_summary' => $this->str($b['essential_summary'] ?? null),
                'offer' => is_array($b['offer'] ?? null) ? $b['offer'] : null,
                'persuasion' => is_array($b['persuasion'] ?? null) ? $b['persuasion'] : null,
                'pitch_starts_at_seconds' => isset($b['pitch_starts_at_seconds']) ? (int) $b['pitch_starts_at_seconds'] : null,
                'claims' => is_array($b['claims'] ?? null) ? $b['claims'] : null,
            ])->save();

            // Pass C — funnel kit (grounded on A+B)
            $c = $this->callModel($this->systemFunnel(), $this->groundedUser($asset, $transcript), 'atlas.vsl.funnel.v1');
            $asset->forceFill([
                'funnel_kit' => is_array($c['funnel_kit'] ?? null) ? $c['funnel_kit'] : null,
                'levers' => is_array($c['levers'] ?? null) ? $c['levers'] : null,
            ])->save();

            // Pass D — keywords, targeting & named mechanism (grounded + VSL frequency)
            $d = $this->callModel($this->systemKeywords(), $this->groundedUser($asset, $transcript), 'atlas.vsl.keywords.v1');
            $asset->forceFill([
                'mechanism_name' => $this->str($d['mechanism_name'] ?? null, 290),
                'target_geo' => $this->str($d['target_geo'] ?? null, 110),
                'keywords' => is_array($d['keywords'] ?? null) ? $d['keywords'] : null,
            ])->save();

            // Pass E — campaign creative kit (advertorial brief, RSA assets, CTA, rebuttals, beats)
            $e = $this->callModel($this->systemCreative(), $this->groundedUser($asset, $transcript), 'atlas.vsl.creative.v1');
            $asset->forceFill([
                'value_equation' => is_array($e['value_equation'] ?? null) ? $e['value_equation'] : null,
                'advertorial_brief' => is_array($e['advertorial_brief'] ?? null) ? $e['advertorial_brief'] : null,
                'ad_assets' => is_array($e['ad_assets'] ?? null) ? $e['ad_assets'] : null,
                'cta' => is_array($e['cta'] ?? null) ? $e['cta'] : null,
                'objection_rebuttals' => is_array($e['objection_rebuttals'] ?? null) ? $e['objection_rebuttals'] : null,
                'power_phrases' => is_array($e['power_phrases'] ?? null) ? $e['power_phrases'] : null,
                'beat_timestamps' => is_array($e['beat_timestamps'] ?? null) ? $e['beat_timestamps'] : null,
            ])->save();

            $asset->forceFill([
                'status' => 'structured',
                'structure_status' => 'ready',
                'structured_at' => now(),
                'extraction_model' => $this->lastModel ?? 'hermes_cli',
                'reason' => null,
            ])->save();

            return $asset->refresh();
        } catch (Throwable $e) {
            return $this->markFailed($asset, Str::limit($e->getMessage(), 480, ''));
        }
    }

    private function markFailed(AiMarketingVslAsset $asset, string $reason): AiMarketingVslAsset
    {
        // Direct keyed update — never re-save the model, whose dirty attributes
        // from a failed pass would re-trigger the very write error we caught.
        AiMarketingVslAsset::query()->whereKey($asset->getKey())->update([
            'status' => $asset->transcript ? 'transcribed' : 'failed',
            'structure_status' => 'failed',
            'reason' => $reason,
        ]);

        return $asset->fresh() ?? $asset;
    }

    /**
     * @return array<string,mixed>
     */
    private function callModel(string $system, string $user, string $schemaVersion, int $timeoutSeconds = 600): array
    {
        $job = new AiJob([
            'trace_id' => (string) Str::ulid(),
            'kind' => 'vsl_intelligence_extraction',
            'status' => 'pending',
            'input_text' => $user,
            'prompt' => $system,
            'payload' => ['model_identity_source' => 'provider_default_identity'],
            'timeout_seconds' => $timeoutSeconds,
        ]);
        $job->id = (string) Str::uuid();

        $result = $this->providers->get()->run($job, $system."\n\n".$user);
        if (! $result->ok) {
            throw new RuntimeException('LLM call failed: '.($result->errorMessage ?: ($result->stderr ?: 'unknown error')));
        }

        $parsed = $this->parseJson($result->output, $schemaVersion);
        if ($parsed === null) {
            throw new RuntimeException("Could not parse JSON ({$schemaVersion}) from model output.");
        }

        return $parsed;
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

    private function userTranscript(string $transcript): string
    {
        return "=== TRANSCRIÇÃO COMPLETA DA VSL ===\n".$transcript;
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

    private function groundedUser(AiMarketingVslAsset $asset, string $transcript): string
    {
        $prior = json_encode([
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

        return "ESTRUTURA EXTRAÍDA (top_terms = termos mais repetidos da VSL, use-os):\n{$prior}\n\n=== TRANSCRIÇÃO COMPLETA DA VSL ===\n{$transcript}";
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
            'which', 'these', 'those', 'don', 'didn', 'doesn', 'isn', 'aren', 'wasn', 'them', 'were',
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
Seja exaustivo nos terms (long-tail incluso). SEMPRE preencha negatives. Não escreva nada fora do json.
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
}
