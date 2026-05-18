---
id: vox-session-packet-v1
type: contract
title: VoxSessionPacket v1
status: active
category: contracts
priority: 95
summary: Pacote canonico emitido pelo Mac Edge daemon ao iniciar uma sessao Vox. Carrega identidade, origem, modo solicitado e consentimentos opt-in. Primeira mensagem do pipeline Vox.
tags:
  - atlas-vox
  - contract
  - schema
  - v1
maintenance:
  - Imutavel em campos obrigatorios v1.
  - Extensoes opcionais em v1.x apenas com ADR.
related_paths:
  - docs/contracts/vox/README.md
  - docs/contracts/vox/VoxTranscript.v1.md
  - docs/engineering-knowledge-base/atlas-vox-operational-thinking-interface.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: vox-session-packet-v1

graph_title: VoxSessionPacket v1

graph_world: atlas

graph_layer: contract

graph_kind: contract

graph_parent: vox-contracts-v1-index

graph_status: active

graph_source: repo

owner: surface-architecture

repo_paths:
  - docs/contracts/vox/VoxSessionPacket.v1.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
---
# VoxSessionPacket v1

Schema id: `atlas.vox.session_packet.v1`.

Primeira mensagem do pipeline Vox. Emitida pelo Mac Edge daemon quando o
hotkey global e acionado (Hold Option+Space ou alternativa configurada).
Consumida por `VoxController` que materializa a sessao no Kernel.

## Quem produz, quem consome

- **Produz**: Mac Edge daemon (Rust dentro do binario Tauri ou launchd agent
  conforme hibrido aprovado em ADR 0003).
- **Consome**: `atlas-server/app/Http/Controllers/AtlasAiVoxController.php`
  -> `VoxDesktopSurfaceAdapter` -> `OperationEnvelopeFactory`.

## Campos

| Campo | Tipo | Obrigatorio | Descricao |
| --- | --- | --- | --- |
| `session_id` | UUID v4 | sim | Identificador unico da sessao Vox. Reutilizado em todos os pacotes subsequentes desta sessao. |
| `started_at` | ISO 8601 string UTC | sim | Momento exato em que o hotkey foi acionado. Precisao milissegundos. |
| `source` | enum | sim | Origem do trigger. Valores: `desktop_overlay`, `mac_edge_hotkey`, `desktop_inbox_button`, `desktop_workbench_button`. |
| `mode_requested` | enum | sim | Modo solicitado. Valores: `dictation` (V0), `prompt_polish` (V1), `intent_compile` (V2), `governed_execute` (V3). Kernel pode reclassificar para BAIXO se modo nao implementado ainda, nunca para CIMA. |
| `user_id` | string | sim | Sempre `vitor` em V0-V3 (sistema single-user). Campo preservado para futuras versoes multi-user. |
| `device` | object | sim | `{ os: "darwin", host: "<hostname>", atlas_desktop_version: "<semver>", mac_edge_version: "<semver>" }`. |
| `consent` | object | sim | `{ audio_capture: bool, context_share: bool, debug_keep_audio: bool }`. `audio_capture` deve ser `true` (sem ele a sessao e invalida). `context_share` autoriza envio de context_refs ao Kernel. `debug_keep_audio` opt-in escreve PCM em `~/.atlas/vox/debug/` com TTL 24h. |
| `eclipse_state` | enum | sim | `active` ou `inactive`. Se `active`, sessao e rejeitada com 423 Locked. |
| `dictionary_version` | integer | sim | Versao do dicionario pessoal aplicado durante STT (correspondencia com `~/.atlas/vox/dictionary.json`). |
| `parent_session_id` | UUID v4 | nao | Se esta sessao continua uma anterior (Vitor reabre overlay com `Cmd+Shift+Space`), referencia o `session_id` original. |
| `client_telemetry` | object | nao | `{ hotkey_to_capture_start_ms, capture_duration_ms_predicted }` - sinais para SLO. |

## Exemplo

```json
{
  "schema": "atlas.vox.session_packet.v1",
  "session_id": "b7c4e1a2-3d8f-4e5a-9b1c-2f6e8d3a4b5c",
  "started_at": "2026-05-18T14:33:12.412Z",
  "source": "mac_edge_hotkey",
  "mode_requested": "intent_compile",
  "user_id": "vitor",
  "device": {
    "os": "darwin",
    "host": "vitor-mbp",
    "atlas_desktop_version": "0.1.0",
    "mac_edge_version": "0.1.0"
  },
  "consent": {
    "audio_capture": true,
    "context_share": false,
    "debug_keep_audio": false
  },
  "eclipse_state": "inactive",
  "dictionary_version": 7,
  "parent_session_id": null,
  "client_telemetry": {
    "hotkey_to_capture_start_ms": 23,
    "capture_duration_ms_predicted": 4500
  }
}
```

## Regras invariantes

1. `audio_capture: false` -> sessao rejeitada com 400 Bad Request.
2. `eclipse_state: "active"` -> sessao rejeitada com 423 Locked, evento
   `VOX_ECLIPSE_ACTIVATED` registrado.
3. `mode_requested` nao implementado ainda (ex.: `governed_execute` em Onda 2)
   -> Kernel reclassifica para o modo maior disponivel e devolve aviso.
4. `dictionary_version` mismatch com Kernel -> Kernel devolve dicionario atual
   para Mac Edge antes da proxima sessao (`VOX_PERSONAL_DICTIONARY_UPDATED`).
5. `debug_keep_audio: true` exige warning visual no overlay durante toda a
   sessao.

## Evento Ledger associado

`VOX_SESSION_STARTED` emitido pelo Kernel apos validacao deste pacote. Payload
do ledger inclui: `session_id`, `started_at`, `source`, `mode_requested`,
`consent` (redigido para flags booleanas, sem detalhes), `eclipse_state`.

## Versionamento

- v1 (2026-05-18): versao inicial canonica.
- Mudancas que removem/renomeiam/mudam tipo de campo obrigatorio -> v2.
- Mudancas que adicionam campo opcional -> v1.x compativel.
