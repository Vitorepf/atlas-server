---
id: vox-context-packet-v1
type: contract
title: VoxContextPacket v1 (paper-only, V4 plan)
status: planned
category: contracts
priority: 90
summary: Schema canonico do bundle de context_refs resolvidos pelo Mac Edge / Kernel no Wave V4. Agrega N `VoxContextRef.v1`, indica quais sao opt-in, ttl da sessao de consentimento, e o resumo humano-legivel exibido no overlay. Paper-only - V4 esta bloqueado por GATE V3 (Lei 0.9 + ADR 0003).
tags:
  - atlas-vox
  - contract
  - v4
  - context
  - packet
  - planned
maintenance:
  - V4 runtime nao existe; alteracoes no schema exigem nova ADR.
  - O packet e um agregador: extensoes do agregador NAO substituem extensoes de `VoxContextRef.v1`.
related_paths:
  - docs/contracts/vox/README.md
  - docs/contracts/vox/VoxIntentPacket.v1.md
  - docs/contracts/vox/VoxContextRef.v1.md
  - docs/engineering-knowledge-base/atlas-vox-v4-contextual-operator-plan.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: vox-context-packet-v1

graph_title: VoxContextPacket v1

graph_world: atlas

graph_layer: contract

graph_kind: contract

graph_parent: vox-contracts-v1-index

graph_status: planned

graph_source: repo

owner: surface-architecture

repo_paths:
  - docs/contracts/vox/VoxContextPacket.v1.md

allowed_changes:
  - Adicionar campo opcional com default (v1.x).
  - Refinar shape do `summary_text` humano.

forbidden_changes:
  - Implementar runtime que materialize este contrato antes de GATE V3 verde + ADR V4.
  - Adicionar campo cujo conteudo cru viaja no ledger (vazamento de conteudo).
  - Permitir packet sem nenhum `VoxContextRef.v1` valido (zero-context vai direto pelo VoxIntentPacket.v1).

depends_on:
  - vox-contracts-v1-index
  - vox-context-ref-v1
  - vox-intent-packet-v1
  - atlas-vox-v4-contextual-operator-plan

flows_to: []

unlocks: []

governs:
  - vox-v4-context-packet-shape

evidence:
  - docs/contracts/vox/VoxContextPacket.v1.md
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
  - Leia "Regras invariantes" antes de propor agregacao de novo `kind` no packet.

ai_usage_notes:
  - Este contrato e paper-only.
  - O packet e produzido APENAS no Kernel; Desktop manda hints + selecoes locais, nunca o packet pronto.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Emitir packet com `refs=[]` (use o caminho zero-context em VoxIntentPacket.v1 com `context_refs=[{kind:none,...}]`).
  - Permitir que `summary_text` inclua conteudo cru.
  - Persistir o packet inteiro (com `meta`) no ledger.
---
# VoxContextPacket v1 (paper-only)

Schema id: `atlas.vox.context_packet.v1`.

Bundle agregado de `VoxContextRef.v1` produzido pelo Kernel em V4
(Contextual Operator). Substitui o array cru `context_refs` que vive dentro
de `VoxIntentPacket.v1` em V0-V3 - **sem** quebrar o intent v1: o intent
continua aceitando o array cru; V4 adiciona um campo opcional novo
`context_packet_ref` (proposta `v1.x`) que aponta para este packet.

> Doc paper-only. V4 esta bloqueado por
> [GATE V3](../engineering-knowledge-base/atlas-vox-operational-thinking-interface.md#leis-0-fronteiras-atlas-vox-v0-v3-2026-05-18)
> e por [ADR 0003](../engineering-knowledge-base/adr/0003-vox-vs-voice-realtime-surface-boundary.md).
> Nenhum codigo runtime V4 existe.

## Quem produz, quem consome (quando V4 abrir)

- **Produz**: Kernel `app/Services/Ai/Vox/Context/VoxContextResolver.php`
  (a ser criado na onda V4.0).
- **Consome**:
  - `VoxCompiler` para compor `VoxIntentPacket.compiled_prompt`.
  - `VoxOverlay` (Atlas Desktop) para renderizar o bloco "Contexto (n)" do
    [plano V4](../engineering-knowledge-base/atlas-vox-v4-contextual-operator-plan.md#6-como-o-overlay-mostra-contexto-usado).
  - `VoxEvidenceService` para emitir `VOX_CONTEXT_ATTACHED`.

## Campos

| Campo | Tipo | Obrigatorio | Descricao |
| --- | --- | --- | --- |
| `schema` | string | sim | Literal `atlas.vox.context_packet.v1`. |
| `packet_id` | UUID v4 | sim | Identificador unico deste packet. |
| `session_id` | UUID v4 | sim | Referencia a `VoxSessionPacket.session_id`. |
| `intent_id` | UUID v4 | sim | Referencia ao `VoxIntentPacket.intent_id` que sera produzido com este contexto. |
| `refs` | array de `VoxContextRef.v1` | sim | Lista de refs resolvidos. **Pelo menos 1**; packet vazio nao existe (zero-context vai pelo caminho V0-V3 normal). |
| `default_on_count` | integer | sim | Contagem de refs com `opt_in=false`. |
| `opt_in_count` | integer | sim | Contagem de refs com `opt_in=true`. |
| `forbidden_attempted_count` | integer | sim | Contagem de tentativas (recusadas) de `kind` proibido durante a resolucao. Deve ser `0` em estado saudavel. |
| `summary_text` | string | sim | Frase humana exibida no overlay (e.g. `"considerando workspace + selecao do composer; nao usa contexto extendido"`). Maximo 300 chars. **Nao** carrega conteudo cru. |
| `extended_active` | boolean | sim | `true` quando `opt_in_count > 0`. Drive do chip `Contexto extendido ativo` no overlay. |
| `redacted_any` | boolean | sim | `true` quando qualquer `ref` tem `redacted=true`. |
| `removed_kinds` | array de strings | sim | Lista de `kind`s removidos pelo operador antes de Compilar (botao `[×]`). Lista, mesmo vazia. |
| `resolved_at` | ISO8601 | sim | Timestamp UTC da montagem do packet. |
| `resolver_version` | string | sim | Versao do `VoxContextResolver`. Ex.: `0.1.0`. |

## Regras invariantes

1. `refs.length >= 1`. Packet vazio nao existe: `VoxIntentPacket` v0/v1 ja
   suporta zero-context via `context_refs=[{kind:none, ref:null, resolved:true}]`.
2. `default_on_count + opt_in_count == refs.length`. Toda `ref` esta em uma das
   duas camadas.
3. `forbidden_attempted_count > 0` exige `VOX_ACTION_BLOCKED` emitido para
   cada tentativa, **antes** do packet ser materializado. Em packet final
   saudavel o valor e `0`; valor maior que zero indica que o resolver
   bloqueou kind(s) e seguiu sem eles - util para auditoria.
4. `extended_active == (opt_in_count > 0)`. Drift entre os dois e bug.
5. `summary_text` **nao** copia conteudo cru. Apenas enumera kinds em
   linguagem natural curta.
6. Eclipse (Esc Esc) durante resolucao descarta o packet sem materializar.
7. `removed_kinds` reflete remocoes feitas no overlay; o packet final ja
   esta livre desses kinds.
8. `meta` interno de cada `ref` segue regras de `VoxContextRef.v1`:
   conteudo cru **nunca** entra no payload do ledger.

## Exemplo - V4 com workspace + active_surface + composer_selection

```json
{
  "schema": "atlas.vox.context_packet.v1",
  "packet_id": "ctxp_8a1b2c3d-4e5f-6789-0123-456789abcdef",
  "session_id": "b7c4e1a2-3d8f-4e5a-9b1c-2f6e8d3a4b5c",
  "intent_id": "9a8b7c6d-5e4f-3210-fedc-ba0987654321",
  "refs": [
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
    },
    {
      "schema": "atlas.vox.context_ref.v1",
      "kind": "active_surface",
      "ref": "surface_code",
      "ref_sha256": "2f3d8d9b6e2c5d8c2c5b7d8e1f6a5b4c3d2e1f0a9b8c7d6e5f4a3b2c1d0e9f8a",
      "opt_in": false,
      "opt_in_session_id": null,
      "resolved": true,
      "redacted": false,
      "redacted_patterns": [],
      "meta": { "surface_name": "code" },
      "resolved_at": "2026-08-01T14:23:11Z",
      "ttl_seconds": null
    },
    {
      "schema": "atlas.vox.context_ref.v1",
      "kind": "composer_selection",
      "ref": "selsha_3a2b1c4d",
      "ref_sha256": "3a2b1c4d3a2b1c4d3a2b1c4d3a2b1c4d3a2b1c4d3a2b1c4d3a2b1c4d3a2b1c4d",
      "opt_in": false,
      "opt_in_session_id": null,
      "resolved": true,
      "redacted": false,
      "redacted_patterns": [],
      "meta": { "surface": "code", "length": 184, "sha256": "3a2b1c4d3a2b1c4d3a2b1c4d3a2b1c4d3a2b1c4d3a2b1c4d3a2b1c4d3a2b1c4d" },
      "resolved_at": "2026-08-01T14:23:11Z",
      "ttl_seconds": null
    }
  ],
  "default_on_count": 3,
  "opt_in_count": 0,
  "forbidden_attempted_count": 0,
  "summary_text": "considerando workspace + active_surface + selecao do composer; nao usa contexto extendido",
  "extended_active": false,
  "redacted_any": false,
  "removed_kinds": [],
  "resolved_at": "2026-08-01T14:23:11Z",
  "resolver_version": "0.1.0"
}
```

## Exemplo - operador removeu um item antes de Compilar

```json
{
  "schema": "atlas.vox.context_packet.v1",
  "packet_id": "ctxp_aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee",
  "session_id": "b7c4e1a2-3d8f-4e5a-9b1c-2f6e8d3a4b5c",
  "intent_id": "9a8b7c6d-5e4f-3210-fedc-ba0987654321",
  "refs": [
    {
      "schema": "atlas.vox.context_ref.v1",
      "kind": "workspace",
      "ref": "ws_atlas-server_main",
      "ref_sha256": "...",
      "opt_in": false,
      "opt_in_session_id": null,
      "resolved": true,
      "redacted": false,
      "redacted_patterns": [],
      "meta": { "atlas_project_id": "atlas-server", "git_branch": "main", "git_sha": "abc1234", "path_sha256": "..." },
      "resolved_at": "2026-08-01T14:23:11Z",
      "ttl_seconds": null
    }
  ],
  "default_on_count": 1,
  "opt_in_count": 0,
  "forbidden_attempted_count": 0,
  "summary_text": "considerando workspace; composer_selection removido pelo operador",
  "extended_active": false,
  "redacted_any": false,
  "removed_kinds": ["composer_selection"],
  "resolved_at": "2026-08-01T14:23:11Z",
  "resolver_version": "0.1.0"
}
```

## Evento Ledger associado

`VOX_CONTEXT_ATTACHED` (novo evento, a ser confirmado em ADR V4). Payload
**resumido** (sem `meta` cru):

```json
{
  "session_id": "uuid",
  "intent_id": "uuid",
  "packet_id": "uuid",
  "kinds": ["workspace", "active_surface", "composer_selection"],
  "default_on_count": 3,
  "opt_in_count": 0,
  "extended_active": false,
  "forbidden_attempted_count": 0,
  "redacted_any": false,
  "removed_kinds": [],
  "resolver_version": "0.1.0"
}
```

Eventos correlatos previstos:

- `VOX_CONTEXT_REMOVED` - quando operador remove ref antes de Compilar.
- `VOX_CONTEXT_OPTIN_TOGGLED` - quando toggle de opt-in muda no Settings.

## Versionamento

- v0.1 (2026-05-18) - paper-only. Bloqueado por GATE V3.
- Sera promovido a `v1` formal quando ADR V4 abrir e schema JSON
  (`atlas.vox.context_packet.v1.schema.json`) for gerado em
  `app/Services/Ai/Vox/Schema/`.

## Referencias

- [VoxContextRef.v1](VoxContextRef.v1.md)
- [VoxIntentPacket.v1](VoxIntentPacket.v1.md)
- [Atlas Vox V4 Plan](../engineering-knowledge-base/atlas-vox-v4-contextual-operator-plan.md)
- [ADR 0003 - Vox vs Voice Realtime Boundary](../engineering-knowledge-base/adr/0003-vox-vs-voice-realtime-surface-boundary.md)
