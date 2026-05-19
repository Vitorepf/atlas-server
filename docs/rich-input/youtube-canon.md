# YouTube canonical capability — Atlas AI

**Slice entregue 2026-05-19.** Capability multilíngue e auditável; mobile, desktop e backend falam o mesmo dicionário via `@atlas/rich-input-canon`.

## Premissa de uso real

90% dos vídeos YouTube que o operador manda pro Atlas estão em idiomas estrangeiros (en, es, fr, ja, zh, …). Resposta final esperada: PT-BR. Antes desta entrega o fluxo conflatava ingestão e tradução numa única string `status`, e o desktop nem mostrava status. Agora:

- Ingestão, transcrição e tradução são **três dimensões independentes**.
- Idioma da interface (PT-BR) ≠ idioma do vídeo.
- Default `target_language = 'pt-BR'` em qualquer surface.
- Nunca declarar tradução que não aconteceu. `translation_status = 'translated_ready'` é **inalcançável até alguém implementar pipeline real de tradução** — hoje, todo vídeo estrangeiro fica `'required'`.

## Os três status

### `ingestion_status` — `queued | processing | ready | failed`
Pegamos o registro do vídeo + tentamos as legendas? Independente de termos transcript ou não.

### `transcript_status` — `unavailable | pending | original_ready | failed`
Temos texto de transcript pra alimentar o modelo? `original_ready` quer dizer: temos transcript no idioma original do vídeo (legenda manual, automática ou Whisper). NÃO quer dizer traduzido.

### `translation_status` — `not_required | required | pending | translated_ready | failed`
Quando o idioma original ≠ `target_language` precisamos traduzir? Hoje sem pipeline o estado fica `required`. Honesto.

## Tabela de derivação (server-side, `YoutubeCanonicalProjection`)

| Status legado do `YouTubeKnowledgeIngestionService` | `ingestion_status` | `transcript_status` |
|---|---|---|
| `ready` | `ready` | `original_ready` |
| `queued` | `queued` | `pending` |
| `processing` | `processing` | `pending` |
| `caption_unavailable`, `transcript_empty`, `skipped_duration`, `disabled` | `ready` (tentamos) | `unavailable` |
| `metadata_unavailable`, `download_failed`, `runtime_missing`, `processing_stale` | `failed` | `failed` |
| `failed`, `caption_failed` | `failed` | `failed` |
| Tudo o mais | `failed` | `failed` |

`translation_status` deriva de `transcript_status` + `translation_required`:

| `transcript_status` | `translation_required` | `translation_status` |
|---|---|---|
| `failed` | qualquer | `failed` |
| `unavailable` | qualquer | `not_required` (nada pra traduzir) |
| `pending` | qualquer | `pending` (não sabemos ainda) |
| `original_ready` | `false` | `not_required` |
| `original_ready` | `true` | `required` (até existir pipeline) |

`translation_required` deriva pura de `source_language` e `target_language`:

- target ausente ou source ausente → `false` (sem claim)
- ambos começam com `pt` → `false` (variantes portuguesas colapsam)
- iguais → `false`
- caso contrário → `true`

Sinal explícito de `translation_status` (futuro pipeline) é honrado quando válido — única forma de chegar em `translated_ready`.

## Fluxo ponta a ponta

1. **Detecção (client)**: `@atlas/rich-input-canon` → `classifyUrl()` + `normalizeYouTubeUrl()` → payload com `url_attachments[].kind='youtube'`, `ref_id` = videoId 11 chars, URL normalizada `https://www.youtube.com/watch?v=ID`.
2. **POST `/ai/interactions`**: `StoreAiInteractionRequest` valida `ref_id` quando `kind='youtube'` (422 se faltar/inválido).
3. **Gateway (`AiGatewayService::optionsWithYouTubeKnowledge`)**: une URLs de `input_text` + `rich_input_payload.url_attachments[]` (kind='youtube'), dedupa por canonical URL, chama `YouTubeKnowledgeIngestionService::ingestFromUrls()`.
4. **Service**: `finalizeVideoResult` projeta cada vídeo via `YoutubeCanonicalProjection`. Persiste em `ai_youtube_ingestions` com 6 colunas canônicas + audit completo.
5. **Worker** (`AiWorker::refreshReadyYouTubePrompt`): mesma união de URLs (texto + payload), re-ingere quando processing → ready, re-grava payload e prompt.
6. **GET `/ai/turns/{id}`**: `AiJobResource::withFreshYoutubeIngestion` re-resolve `payload.youtube_ingestion.videos[]` a partir do `ai_youtube_ingestions` ao vivo. **Cliente sempre vê o estado atual, não snapshot do POST**. Essa é a convergência.
7. **PromptBuilder** (`AiPromptBuilder::youtubeKnowledgeSection`): quando `translation_required=true && translation_status != translated_ready`, injeta diretiva proibindo o modelo de "fingir que traduziu". O modelo lê o transcript original (en/ja/etc) e responde em PT-BR por inferência — isso fica explícito na resposta.

## Cadência real de sync (sem realtime peer-to-peer)

- Desktop: SSE com janela ~25s + polling `GET /ai/turns/{id}` a cada 1.5s (`TRACE_POLL_INTERVAL_MS` em `useAtlasAi.ts`).
- Mobile: re-fetch da turn no ciclo do hook (~5-18s dependendo do hook).
- **Não há broadcast/Reverb/Pusher**. Não prometer realtime peer mobile↔desktop. Convergência via backend.
- Quando `AiYoutubeIngestion.ingestion_status` muda no DB (worker termina background), o próximo poll/SSE refresh já reflete porque `withFreshYoutubeIngestion` re-resolve sempre.

## Contrato cross-stack

| Conceito | Canon TS (`@atlas/rich-input-canon`) | PHP (`atlas-server`) |
|---|---|---|
| Enum ingestão | `YOUTUBE_INGESTION_STATUSES` | `App\Enums\YoutubeIngestionStatus` |
| Enum transcrição | `YOUTUBE_TRANSCRIPT_STATUSES` | `App\Enums\YoutubeTranscriptStatus` |
| Enum tradução | `YOUTUBE_TRANSLATION_STATUSES` | `App\Enums\YoutubeTranslationStatus` |
| Default target lang | `YOUTUBE_DEFAULT_TARGET_LANGUAGE` | `YoutubeCanonicalProjection::DEFAULT_TARGET_LANGUAGE` |
| Projeção | `summarizeYouTubeVideo(rawVideo)` | `YoutubeCanonicalProjection::project(array)` |
| Detecção | `extractYouTubeVideoId`/`isYouTubeUrl` | regex em `YouTubeKnowledgeIngestionService::videoIdFromUrl` |
| Normalização URL | `normalizeYouTubeUrl` | `YouTubeKnowledgeIngestionService::canonicalUrl` |
| Inferência idioma | `inferYouTubeSourceLanguage` | `YoutubeCanonicalProjection::inferSourceLanguage` |
| Translation required | `deriveYouTubeTranslationRequirement` | `YoutubeCanonicalProjection::deriveTranslationRequirement` |
| Labels PT-BR/EN | `youtube*StatusLabel(status, locale?)` | UI usa labels do canon TS — backend não traduz |

## Schema canônico do video em `payload.youtube_ingestion.videos[]`

```json
{
  "url": "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
  "video_id": "dQw4w9WgXcQ",
  "status": "ready",
  "ingestion_status": "ready",
  "transcript_status": "original_ready",
  "translation_status": "required",
  "source_language": "en",
  "target_language": "pt-BR",
  "translation_required": true,
  "metadata": { "title": "...", "channel": "...", "duration_seconds": 120 },
  "caption": { "language": "en", "kind": "manual" },
  "chunks": [ ... ]
}
```

## Limitações reais (honesto)

- **Pipeline de tradução não foi implementada**. `translation_status='translated_ready'` é canônico mas inalcançável até trabalho futuro.
- A AI consegue ler transcript estrangeiro e responder PT-BR por inferência. Isso NÃO é tradução certificada e o prompt agora deixa isso explícito.
- Sync é via backend polling/SSE, não broadcast. Latência típica: até 1.5s desktop, até 18s mobile.
- Worker só re-ingere quando há vídeo `processing` no payload — vídeos que ficam `failed` permanecem assim até nova interação.

## Anti-fingimento (regras pra IA, injetadas pelo PromptBuilder)

Quando `translation_required=true && translation_status != translated_ready`:

> TRADUCAO HONESTA: o transcript esta em idioma estrangeiro e o Atlas NAO possui pipeline de traducao explicita ainda — voce esta lendo o transcript original e respondendo em pt-BR por inferencia. Deixe claro na resposta que a base e o transcript ORIGINAL no idioma de origem (cite o idioma quando souber), nao uma traducao certificada. Nao escreva "traduzi o video para voce" nem "aqui esta a traducao": isso seria mentira sobre o que o Atlas fez.

## Confirmação de escopo

- **Benchmark/rivals NÃO foram executados** nesta entrega. Equipe separada (`atlas:forge:rivals`).
- Forge/Obras intake não foi tocado — `forge_work_intake_ready` continua governado por outros sinais, não por YouTube isolado.
- Hyperflow V2, Presentation Contract e Cartografia não foram afetados.
- Schema `atlas.rich_input.payload.v1` continua estável (mudanças foram aditivas em `UrlAttachment` opcional client-side).

## Pontos de extensão futura

- Pipeline real de tradução → quando existir, set `translation_status='translated_ready'` no payload + persist em `AiYoutubeIngestion.translation_status`. Toda a UI já trata esse estado.
- Reverb/SSE broadcast para `AiYoutubeIngestion` updates → reduz polling. Opcional, não bloqueante.

## Onde está o código (mapa rápido)

- Canon: `packages/atlas-rich-input-canon/src/youtube.ts`
- PHP enums: `atlas-server/app/Enums/Youtube{Ingestion,Transcript,Translation}Status.php`
- Projection: `atlas-server/app/Services/Ai/YoutubeCanonicalProjection.php`
- Migration: `atlas-server/database/migrations/2026_05_19_120000_add_canonical_status_to_ai_youtube_ingestions.php`
- Gateway union: `atlas-server/app/Services/Ai/AiGatewayService.php::optionsWithYouTubeKnowledge`
- Worker union: `atlas-server/app/Services/Ai/AiWorker.php::refreshReadyYouTubePrompt`
- Service helpers: `extractUrlsFromRichInputPayload`, `ingestFromUrls` em `YouTubeKnowledgeIngestionService.php`
- Validation: `atlas-server/app/Http/Requests/StoreAiInteractionRequest.php::withValidator`
- Trace re-resolve: `atlas-server/app/Http/Resources/AiJobResource.php::withFreshYoutubeIngestion`
- Prompt: `atlas-server/app/Services/Ai/AiPromptBuilder.php::youtubeKnowledgeSection`
