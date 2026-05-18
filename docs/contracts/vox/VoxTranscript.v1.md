---
id: vox-transcript-v1
type: contract
title: VoxTranscript v1
status: active
category: contracts
priority: 95
summary: Transcript estruturado emitido apos STT local sobre o audio capturado em uma sessao Vox. NUNCA inclui PCM cru. Insumo principal do VoxCompiler.
tags:
  - atlas-vox
  - contract
  - schema
  - transcript
  - stt
  - v1
maintenance:
  - Imutavel em campos obrigatorios v1.
related_paths:
  - docs/contracts/vox/README.md
  - docs/contracts/vox/VoxSessionPacket.v1.md
  - docs/contracts/vox/VoxIntentPacket.v1.md
  - docs/engineering-knowledge-base/atlas-vox-operational-thinking-interface.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: vox-transcript-v1

graph_title: VoxTranscript v1

graph_world: atlas

graph_layer: contract

graph_kind: contract

graph_parent: vox-contracts-v1-index

graph_status: active

graph_source: repo

owner: surface-architecture

repo_paths:
  - docs/contracts/vox/VoxTranscript.v1.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
---
# VoxTranscript v1

Schema id: `atlas.vox.transcript.v1`.

Produzido pelo Mac Edge daemon apos STT local sobre o audio capturado. Carrega
texto estruturado + metadados + correcoes aplicadas. **Nunca carrega PCM bruto
nem caminho para arquivo de audio em disco** (exceto em modo
`debug_keep_audio` opt-in).

## Quem produz, quem consome

- **Produz**: Mac Edge daemon (`whisper.cpp@large-v3` + dicionario pessoal).
- **Consome**: `VoxCompiler` no Kernel para gerar `VoxIntentPacket`.

## Campos

| Campo | Tipo | Obrigatorio | Descricao |
| --- | --- | --- | --- |
| `session_id` | UUID v4 | sim | Referencia o `VoxSessionPacket.session_id`. |
| `transcript_id` | UUID v4 | sim | Identificador unico do transcript. |
| `audio_handle` | UUID v4 | sim | Identificador opaco do buffer de audio que ficou em memoria no Mac Edge. TTL 60s. Sem valor fora da sessao. |
| `language` | string BCP-47 | sim | Sempre `pt-BR` em V0-V3 (single-user, sistema fixado). |
| `engine` | string | sim | Identificador do engine STT usado. Ex.: `whisper.cpp@large-v3`, `whisper.cpp@large-v3-turbo`, `mlx-whisper@large-v3`. |
| `engine_invocation_id` | UUID v4 | sim | Identificador da invocacao especifica (para correlacao com benchmark da Onda 7.5). |
| `text` | string | sim | Texto final pos-correcoes. Insumo primario do `VoxCompiler`. |
| `text_raw` | string | sim | Texto bruto direto do STT, ANTES de aplicar dicionario pessoal. Mantido para audit + benchmark. |
| `confidence` | float [0.0, 1.0] | sim | Confianca media reportada pelo engine. |
| `words` | array de objetos | sim | `[{ w, t_start, t_end, conf }]` - palavras com timestamps e confianca individual. Usado para edicao inline no overlay. |
| `personal_dictionary_applied` | array de strings | sim | Palavras-chave do dicionario pessoal aplicadas como prompt biasing nesta invocacao. |
| `post_corrections` | array de objetos | sim | `[{ from, to, rule }]` - correcoes aplicadas pos-STT. `rule` pode ser `dictionary`, `fuzzy_match`, `manual_inline_edit`. |
| `latency_ms` | object | sim | `{ capture_to_stt_start, stt_processing, correction_pass, total }` - SLO tracking. |
| `raw_pcm_persisted` | boolean | sim | Sempre `false` em V0-V3 a menos que `consent.debug_keep_audio` estivesse `true` na sessao. Metrica hard-gate `raw_audio_persisted_count` monitora violacoes. |
| `eclipse_check` | enum | sim | `passed` ou `aborted_mid_capture`. Se `aborted_mid_capture`, sessao foi interrompida por eclipse durante captura; transcript pode ser parcial e Kernel descarta. |
| `noise_signals` | object | nao | `{ silence_ratio, snr_estimate_db, vad_segments }` - sinais para debug. |

## Exemplo

```json
{
  "schema": "atlas.vox.transcript.v1",
  "session_id": "b7c4e1a2-3d8f-4e5a-9b1c-2f6e8d3a4b5c",
  "transcript_id": "f3e1d2c4-5b6a-7890-abcd-ef1234567890",
  "audio_handle": "a1b2c3d4-e5f6-7890-1234-567890abcdef",
  "language": "pt-BR",
  "engine": "whisper.cpp@large-v3",
  "engine_invocation_id": "11111111-2222-3333-4444-555555555555",
  "text": "manda o Codex olhar esse modulo do voice sem mexer",
  "text_raw": "manda o codes olhar esse modulo do voys sem mexer",
  "confidence": 0.91,
  "words": [
    { "w": "manda", "t_start": 0.12, "t_end": 0.38, "conf": 0.97 },
    { "w": "o", "t_start": 0.39, "t_end": 0.45, "conf": 0.99 },
    { "w": "Codex", "t_start": 0.46, "t_end": 0.83, "conf": 0.89 }
  ],
  "personal_dictionary_applied": ["Codex", "Atlas", "Tauri", "LiveKit", "Vox"],
  "post_corrections": [
    { "from": "codes", "to": "Codex", "rule": "dictionary" },
    { "from": "voys", "to": "voice", "rule": "fuzzy_match" }
  ],
  "latency_ms": {
    "capture_to_stt_start": 45,
    "stt_processing": 612,
    "correction_pass": 8,
    "total": 665
  },
  "raw_pcm_persisted": false,
  "eclipse_check": "passed"
}
```

## Regras invariantes

1. `raw_pcm_persisted: true` exige `consent.debug_keep_audio: true` no
   VoxSessionPacket correspondente; qualquer divergencia e violacao
   stop-the-line.
2. `audio_handle` so e valido durante TTL 60s. Apos isso, qualquer referencia
   externa retorna `audio_handle_expired`.
3. `text_raw` NUNCA e omitido. Permite audit de evolucao do dicionario pessoal
   sem reprocessar audio.
4. `personal_dictionary_applied` documenta APENAS o que foi efetivamente usado
   como prompt biasing, nao toda a lista.
5. `eclipse_check: aborted_mid_capture` -> Kernel descarta o transcript e
   emite `VOX_ECLIPSE_ACTIVATED` retroativamente.

## Evento Ledger associado

`VOX_TRANSCRIPT_READY` emitido pelo Kernel apos receber este pacote. Payload
do ledger inclui: `session_id`, `transcript_id`, `engine`, `latency_ms.total`,
`confidence`, `personal_dictionary_applied`, `raw_pcm_persisted`. **O campo
`text` NAO vai para o ledger por default**; e referenciado via `transcript_id`
e gravado em armazenamento de transcricoes com policy de retencao propria
(definida em Onda 2).

## Versionamento

- v1 (2026-05-18): versao inicial canonica.
