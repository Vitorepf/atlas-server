---
id: vox-context-ref-v1
type: contract
title: VoxContextRef v1 (paper-only, V4 plan)
status: planned
category: contracts
priority: 90
summary: Schema canonico de uma unica referencia contextual usada por V4 Contextual Operator. Define os kinds permitidos / opt-in / proibidos, o que vai no payload do ledger versus o que fica local, e as regras de redacao no Mac Edge. Paper-only - V4 esta bloqueado por GATE V3 (Lei 0.9 + ADR 0003).
tags:
  - atlas-vox
  - contract
  - v4
  - context
  - schema
  - planned
maintenance:
  - V4 runtime nao existe; alteracoes no schema exigem nova ADR.
  - Reaproveita o slot existente `context_refs` do VoxIntentPacket.v1 sem mudar o tipo `kind` obrigatorio (extensoes via enum).
related_paths:
  - docs/contracts/vox/README.md
  - docs/contracts/vox/VoxIntentPacket.v1.md
  - docs/contracts/vox/VoxContextPacket.v1.md
  - docs/engineering-knowledge-base/atlas-vox-v4-contextual-operator-plan.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: vox-context-ref-v1

graph_title: VoxContextRef v1

graph_world: atlas

graph_layer: contract

graph_kind: contract

graph_parent: vox-contracts-v1-index

graph_status: planned

graph_source: repo

owner: surface-architecture

repo_paths:
  - docs/contracts/vox/VoxContextRef.v1.md

allowed_changes:
  - Refinar lista de `kind` permitidos / opt-in / proibidos via ADR.
  - Adicionar campo opcional com default (v1.x) sem quebrar consumidor existente.

forbidden_changes:
  - Implementar runtime que materialize este contrato antes de GATE V3 verde + ADR V4.
  - Adicionar `kind` proibido (screen_recording, full_screen_ocr, browser_history, messages_*, full_disk_scan, keychain_*, clipboard_continuous_watch).
  - Permitir que `payload.raw_content` vire campo do ledger.

depends_on:
  - vox-contracts-v1-index
  - vox-intent-packet-v1
  - atlas-vox-v4-contextual-operator-plan

flows_to:
  - vox-context-packet-v1

unlocks: []

governs:
  - vox-v4-context-ref-shape

evidence:
  - docs/contracts/vox/VoxContextRef.v1.md
  - docs/engineering-knowledge-base/atlas-vox-v4-contextual-operator-plan.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - contract
  - vox
  - v4
  - gated

ai_entrypoints:
  - Leia "Kinds permitidos / opt-in / proibidos" antes de propor extensao.
  - Leia "Regras invariantes" antes de implementar V4.

ai_usage_notes:
  - Este contrato e paper-only. Nao existe service, schema JSON formal ou migration correspondente.
  - Quando V4 for desbloqueado, o schema JSON formal sera gerado em `app/Services/Ai/Vox/Schema/` em onda dedicada.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Aceitar `kind` fora da lista enumerada (deve devolver 422).
  - Persistir `raw_content` no ledger (vazamento de conteudo cru).
  - Resolver `terminal_recent_output` para terminal externo (so terminal embutido do Atlas).
---
# VoxContextRef v1 (paper-only)

Schema id: `atlas.vox.context_ref.v1`.

Define **uma** referencia contextual anexada a um `VoxIntentPacket` no Wave V4
(Contextual Operator). Hoje o `context_refs` do `VoxIntentPacket.v1` ja existe
como array de objetos `{ kind, ref, resolved }`, mas seu enum `kind` esta
restrito a `file | selection | active_window | terminal_recent | none`. V4
**estende** esse enum **sem quebrar** o contrato v1 (nova versao `v1.x`
prevista quando V4 for desbloqueada).

> Doc paper-only. V4 esta bloqueado por
> [GATE V3](../engineering-knowledge-base/atlas-vox-operational-thinking-interface.md#leis-0-fronteiras-atlas-vox-v0-v3-2026-05-18)
> e por [ADR 0003](../engineering-knowledge-base/adr/0003-vox-vs-voice-realtime-surface-boundary.md).
> Nenhum codigo runtime V4 existe.

## Quem produz, quem consome (quando V4 abrir)

- **Produz**: Mac Edge daemon (`atlas-desktop`) + Kernel
  (`atlas-server/app/Services/Ai/Vox/`).
- **Consome**: `VoxCompiler` ao montar `VoxIntentPacket.context_refs`,
  `VoxExecutionGate` ao avaliar policy, `VoxEvidenceService` ao emitir
  `VOX_CONTEXT_ATTACHED`.

## Campos

| Campo | Tipo | Obrigatorio | Descricao |
| --- | --- | --- | --- |
| `schema` | string | sim | Literal `atlas.vox.context_ref.v1`. |
| `kind` | enum | sim | Categoria do contexto. Lista enumerada nas tabelas abaixo. Fora da lista = 422. |
| `ref` | string | sim | Identificador opaco do contexto resolvido. Nao e o conteudo cru. Ex.: `workspace_id`, `transcript_id`, `selection_sha256`. |
| `ref_sha256` | string | sim | `sha256` do `ref` resolvido OU do conteudo cru (quando `ref` ja e o conteudo). Sempre 64 chars hex. |
| `opt_in` | boolean | sim | `true` quando este `kind` exigiu opt-in explicito. `false` para `kind` default-on. |
| `opt_in_session_id` | string \| null | sim | Quando `opt_in=true`, identificador da sessao de consentimento (referencia ao toggle no Settings). `null` para default-on. |
| `resolved` | boolean | sim | `true` se Kernel resolveu sem ambiguidade. `false` exige confirmacao no overlay. |
| `redacted` | boolean | sim | `true` quando Mac Edge aplicou redacao de pattern sensivel (cartao/cpf/secret/key=/password=/token=) antes de resolver. |
| `redacted_patterns` | array de strings | sim | Lista de labels dos patterns redacted (e.g. `["secret", "cartao"]`). Lista, mesmo que vazia. |
| `meta` | objeto | sim | Metadados curtos especificos do `kind`. Ver tabelas abaixo. Conteudo cru **nao** entra aqui. |
| `resolved_at` | ISO8601 | sim | Timestamp UTC da resolucao. |
| `ttl_seconds` | integer \| null | sim | TTL desta resolucao quando `opt_in=true`. `null` quando default-on (sem TTL especial). |

## Kinds permitidos (default-on, sem opt-in)

`opt_in=false`. Resolvido automaticamente pelo Mac Edge / Kernel.
`payload.meta` carrega apenas metadados curtos auditaveis.

| `kind` | `meta` permitido | `meta` no ledger |
| --- | --- | --- |
| `workspace` | `{ path_sha256, atlas_project_id, git_branch, git_sha }` | identico |
| `active_surface` | `{ surface_name }` (enum: `cartografia`/`code`/`atlas_ai`/`atencao`/`control_plane`) | identico |
| `composer_selection` | `{ surface, length, sha256 }` | identico |
| `last_vox_transcript` | `{ transcript_id, mode, age_seconds }` | identico |
| `last_kernel_response` | `{ intent_id, compiled_prompt_template, age_seconds }` | identico |
| `atlas_dev_recent_run` | `{ run_id, status, duration_ms }` | identico |
| `terminal_recent_output` | `{ session_id, last_command_sha256, exit_code, output_tail_sha256 }` | identico |

## Kinds opt-in (off-by-default, exigem consentimento por sessao)

`opt_in=true` obrigatorio. `opt_in_session_id` referencia a sessao de
consentimento. `ttl_seconds` <= 86400 (24h). Sem opt-in valido = 422.

| `kind` | `meta` permitido | `meta` no ledger |
| --- | --- | --- |
| `active_app_name` | `{ bundle_id, app_name }` | `{ bundle_id }` |
| `active_window_title` | `{ title_sha256, length }` | `{ title_sha256, length }` |
| `focused_text_selection` | `{ length, sha256, source_app_bundle_id }` | `{ length, sha256, source_app_bundle_id }` |
| `filesystem_file_open_in_editor` | `{ path_sha256, ext, editor_bundle_id }` | identico |

Conteudo cru **nunca** entra em `meta`. Apenas `sha256` + tamanhos + ids.

## Kinds proibidos (V4 nao introduz nem com opt-in)

Kernel deve recusar com 422 e emitir `VOX_ACTION_BLOCKED` com
`reason_code=v4_forbidden_context_ref`:

- `screen_recording_continuous`
- `full_screen_ocr`
- `silent_other_apps_state`
- `browser_history`
- `messages_email_imessage_slack`
- `full_disk_scan`
- `keychain_passwords_credentials`
- `clipboard_continuous_watch`

Lista normativa. Adicao = nova ADR. Remocao = nova ADR.

## Regras invariantes

1. `kind` fora dos conjuntos enumerados = 422. Mac Edge nao pode "tentar" um
   `kind` novo sem ADR.
2. `opt_in=true` exige `opt_in_session_id` nao-nulo **e** `ttl_seconds <= 86400`.
3. `meta` **nao** carrega conteudo cru. Conteudo de selecao / titulo / output
   vira `sha256` + tamanho. Vazamento = stop-the-line.
4. `redacted=true` exige `redacted_patterns` nao-vazio. Sem patterns, `redacted`
   so pode ser `false`.
5. `terminal_recent_output` **so** se aplica ao terminal embutido do Atlas
   Desktop. Terminal externo (iTerm, Warp, Terminal.app) = `kind` proibido.
6. `resolved=false` pausa a sessao em `compilation_ambiguous`; Vitor confirma
   ou remove o ref no overlay antes de compilar.
7. Eclipse (Esc Esc) invalida todos os `opt_in_session_id` ativos da sessao.

## Exemplo - kind default-on `workspace`

```json
{
  "schema": "atlas.vox.context_ref.v1",
  "kind": "workspace",
  "ref": "ws_atlas-server_main",
  "ref_sha256": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
  "opt_in": false,
  "opt_in_session_id": null,
  "resolved": true,
  "redacted": false,
  "redacted_patterns": [],
  "meta": {
    "path_sha256": "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08",
    "atlas_project_id": "atlas-server",
    "git_branch": "main",
    "git_sha": "abc1234"
  },
  "resolved_at": "2026-08-01T14:23:11Z",
  "ttl_seconds": null
}
```

## Exemplo - kind opt-in `focused_text_selection` com redacao

```json
{
  "schema": "atlas.vox.context_ref.v1",
  "kind": "focused_text_selection",
  "ref": "selsha_9b74c9897bac770ffc029102a200c5de",
  "ref_sha256": "9b74c9897bac770ffc029102a200c5de9b74c9897bac770ffc029102a200c5de",
  "opt_in": true,
  "opt_in_session_id": "optin_2026-08-01_v4-selection",
  "resolved": true,
  "redacted": true,
  "redacted_patterns": ["secret", "token"],
  "meta": {
    "length": 142,
    "sha256": "9b74c9897bac770ffc029102a200c5de9b74c9897bac770ffc029102a200c5de",
    "source_app_bundle_id": "com.apple.dt.Xcode"
  },
  "resolved_at": "2026-08-01T14:23:11Z",
  "ttl_seconds": 7200
}
```

## Exemplo - kind proibido (Kernel recusa)

Request:

```json
{
  "schema": "atlas.vox.context_ref.v1",
  "kind": "browser_history",
  "ref": "...",
  "opt_in": true,
  "opt_in_session_id": "..."
}
```

Resposta esperada (422 + ledger event):

```json
{
  "schema": "atlas.vox.error.v1",
  "reason_code": "v4_forbidden_context_ref",
  "message": "kind 'browser_history' is in the V4 forbidden list (V3 hardening).",
  "event_emitted": "VOX_ACTION_BLOCKED"
}
```

## Evento Ledger associado

`VOX_CONTEXT_ATTACHED` (novo evento previsto, a ser confirmado em ADR V4)
agrega os `VoxContextRef` da intent. Payload (resumido):

```json
{
  "intent_id": "uuid",
  "context_refs": [
    { "kind": "workspace",          "ref_sha256": "...", "opt_in": false, "redacted": false },
    { "kind": "composer_selection", "ref_sha256": "...", "opt_in": false, "redacted": false }
  ]
}
```

`meta` cru **nao** vai para o ledger. Apenas o campo `ref_sha256` (e
`opt_in` / `redacted` flags) entram.

## Versionamento

- v0.1 (2026-05-18) - paper-only. Bloqueado por GATE V3.
- Sera promovido a `v1` formal quando ADR V4 abrir e schema JSON
  (`atlas.vox.context_ref.v1.schema.json`) for gerado em
  `app/Services/Ai/Vox/Schema/`.

## Referencias

- [VoxContextPacket.v1](VoxContextPacket.v1.md)
- [VoxIntentPacket.v1](VoxIntentPacket.v1.md)
- [Atlas Vox V4 Plan](../engineering-knowledge-base/atlas-vox-v4-contextual-operator-plan.md)
- [ADR 0003 - Vox vs Voice Realtime Boundary](../engineering-knowledge-base/adr/0003-vox-vs-voice-realtime-surface-boundary.md)
