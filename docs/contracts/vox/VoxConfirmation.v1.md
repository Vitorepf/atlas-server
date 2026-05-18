---
id: vox-confirmation-v1
type: contract
title: VoxConfirmation v1 (Request + Response)
status: active
category: contracts
priority: 95
summary: Par de mensagens trocadas entre Kernel e Atlas Desktop overlay quando uma acao Vox exige confirmacao humana antes de executar. Obrigatorio para R2-R4. Request carrega preview rico; response carrega decisao + confirmation_token HMAC.
tags:
  - atlas-vox
  - contract
  - schema
  - confirmation
  - v1
maintenance:
  - Imutavel em campos obrigatorios v1.
related_paths:
  - docs/contracts/vox/README.md
  - docs/contracts/vox/VoxIntentPacket.v1.md
  - docs/contracts/vox/VoxActionOutcome.v1.md
  - docs/engineering-knowledge-base/atlas-vox-operational-thinking-interface.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: vox-confirmation-v1

graph_title: VoxConfirmation v1

graph_world: atlas

graph_layer: contract

graph_kind: contract

graph_parent: vox-contracts-v1-index

graph_status: active

graph_source: repo

owner: surface-architecture

repo_paths:
  - docs/contracts/vox/VoxConfirmation.v1.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
---
# VoxConfirmation v1

Schema ids:
- `atlas.vox.confirmation_request.v1`
- `atlas.vox.confirmation_response.v1`

Par de mensagens trocadas entre Kernel e Atlas Desktop overlay quando uma
acao Vox exige confirmacao humana. Obrigatorio para R2-R4. R0-R1 podem usar
preview opcional sem bloquear execucao.

## Fluxo

```
[Kernel pos-Decide+Receipt]
     │ POST overlay (via SSE/WebSocket local)
     ▼
[Atlas Desktop - VoxOverlay]
     │ usuario clica Executar/Editar/Salvar/Cancelar
     ▼
[Kernel POST /vox/execute]
     │ valida confirmation_token
     ▼
[ExecutionGate -> Executor]
```

## VoxConfirmationRequest.v1

Emitido pelo Kernel apos `DecisionReceiptIssuer.issue()`. Entregue ao overlay.

### Campos

| Campo | Tipo | Obrigatorio | Descricao |
| --- | --- | --- | --- |
| `request_id` | UUID v4 | sim | Identificador unico desta requisicao. |
| `session_id` | UUID v4 | sim | Referencia ao `VoxSessionPacket.session_id`. |
| `intent_id` | UUID v4 | sim | Referencia ao `VoxIntentPacket.intent_id`. |
| `receipt_id` | UUID v4 | sim | Decision Receipt emitido. |
| `preview` | object | sim | Conteudo humano-legivel para o overlay. Detalhado abaixo. |
| `actions_available` | array de enum | sim | Subset de `[ execute, edit_intent, save_as_note, cancel ]`. Sempre inclui `cancel`. |
| `ttl_seconds` | integer | sim | Validade da requisicao. Default 120s. Apos expiracao, request invalido. |
| `risk_class` | enum | sim | `R0`-`R4` decidida pelo Kernel (pode diferir do hint do intent packet). |
| `requires_literal_confirmation` | boolean | sim | Se `true`, Vitor deve digitar texto literal especifico (R4). |
| `literal_confirmation_text` | string \| null | sim | Texto exato a digitar se `requires_literal_confirmation: true`. Ex.: `execute push force`. `null` caso contrario. |
| `expires_at` | ISO 8601 string UTC | sim | Momento exato de expiracao. |

### Sub-objeto `preview`

| Campo | Tipo | Obrigatorio | Descricao |
| --- | --- | --- | --- |
| `what_i_heard` | string | sim | Transcript limpo. |
| `what_i_understood` | string | sim | Frase humana descrevendo `goal` + constraints principais. |
| `what_i_will_do` | string | sim | Frase descrevendo executor + acao concreta. |
| `risk_label` | string | sim | Etiqueta visivel da risk class. Ex.: "R2 - Edicao local reversivel". |
| `evidence_promise` | string | sim | Frase resumindo o que sera gravado no ledger. |
| `command_proposal` | string \| null | nao | Se `executor=terminal_propose`, texto exato do comando proposto. `null` caso contrario. |
| `affected_paths` | array de strings | nao | Caminhos de arquivo que serao tocados em `executor=edit`. |
| `diff_preview` | string \| null | nao | Preview do diff em `executor=edit`. Pode ser truncado. |

### Exemplo

```json
{
  "schema": "atlas.vox.confirmation_request.v1",
  "request_id": "11aabbcc-22dd-33ee-44ff-556677889900",
  "session_id": "b7c4e1a2-3d8f-4e5a-9b1c-2f6e8d3a4b5c",
  "intent_id": "9a8b7c6d-5e4f-3210-fedc-ba0987654321",
  "receipt_id": "rcpt_0192837465abcdef",
  "preview": {
    "what_i_heard": "manda o Codex olhar esse modulo do voice sem mexer",
    "what_i_understood": "Investigar modulo Voice/Vox com Codex em modo analise (sem editar)",
    "what_i_will_do": "Rodar `codex` em /Users/vitorepf/develop/Atlas com prompt compilado, capturar diagnostico",
    "risk_label": "R1 - Leitura / analise",
    "evidence_promise": "Transcript, intent packet, prompt compilado, output do Codex, decisao",
    "command_proposal": null,
    "affected_paths": [],
    "diff_preview": null
  },
  "actions_available": ["execute", "edit_intent", "save_as_note", "cancel"],
  "ttl_seconds": 120,
  "risk_class": "R1",
  "requires_literal_confirmation": false,
  "literal_confirmation_text": null,
  "expires_at": "2026-05-18T14:35:12.412Z"
}
```

## VoxConfirmationResponse.v1

Emitido pelo overlay quando Vitor decide. Validado pelo Kernel antes de
acionar `ExecutionGate`.

### Campos

| Campo | Tipo | Obrigatorio | Descricao |
| --- | --- | --- | --- |
| `request_id` | UUID v4 | sim | Referencia ao `VoxConfirmationRequest.request_id`. |
| `session_id` | UUID v4 | sim | Mesma sessao. |
| `receipt_id` | UUID v4 | sim | Mesmo receipt. |
| `decision` | enum | sim | `execute`, `edit_intent`, `save_as_note`, `cancel`. |
| `edits` | object \| null | sim | Se `decision=edit_intent`, carrega edicoes Vitor fez no overlay. `null` caso contrario. |
| `literal_confirmation_input` | string \| null | sim | Texto digitado por Vitor quando `requires_literal_confirmation=true`. Kernel valida igualdade exata. `null` caso contrario. |
| `confirmation_token` | string (HMAC) | sim | Token HMAC-SHA256 gerado pelo overlay assinando `(request_id, decision, intent_id, receipt_id, expires_at)` com chave de sessao. Previne replay e tampering. |
| `decided_at` | ISO 8601 string UTC | sim | Momento da decisao. |

### Sub-objeto `edits` (quando `decision=edit_intent`)

| Campo | Tipo | Obrigatorio | Descricao |
| --- | --- | --- | --- |
| `goal` | string | nao | Novo goal. Se omitido, mantem original. |
| `constraints` | array de strings | nao | Novas constraints (substitui inteiramente se presente). |
| `executor_hint` | enum | nao | Novo executor hint. |
| `risk_class_proposal` | enum | nao | Vitor pode SUGERIR risk class menor; Kernel decide. |

Apos `edit_intent`, Kernel re-roda Decide + Receipt -> emite novo
`VoxConfirmationRequest`.

### Exemplo

```json
{
  "schema": "atlas.vox.confirmation_response.v1",
  "request_id": "11aabbcc-22dd-33ee-44ff-556677889900",
  "session_id": "b7c4e1a2-3d8f-4e5a-9b1c-2f6e8d3a4b5c",
  "receipt_id": "rcpt_0192837465abcdef",
  "decision": "execute",
  "edits": null,
  "literal_confirmation_input": null,
  "confirmation_token": "hmac_sha256:9c8b...e3f7",
  "decided_at": "2026-05-18T14:33:48.901Z"
}
```

## Regras invariantes

1. `risk_class` em `R2`/`R3`/`R4` -> `actions_available` SEMPRE inclui
   `execute`, `edit_intent` e `cancel`. `save_as_note` opcional.
2. `risk_class=R4` -> `requires_literal_confirmation` SEMPRE `true` e
   `literal_confirmation_text` nao-nulo. Sem isso, request invalido.
3. Resposta apos `expires_at` -> Kernel rejeita com 410 Gone.
4. `confirmation_token` invalido ou ausente -> 401 Unauthorized.
5. `literal_confirmation_input != literal_confirmation_text` (case-sensitive)
   -> Kernel rejeita com 403 Forbidden e emite `VOX_ACTION_BLOCKED`.
6. `decision=execute` sem `confirmation_token` valido -> stop-the-line.
   Metrica `confirmation_bypass_count` incrementa - hard-gate.

## Eventos Ledger associados

- `VOX_CONFIRMATION_REQUESTED` emitido quando request e enviado ao overlay.
- `VOX_ACTION_DISPATCHED` emitido apos response valido e ExecutionGate verde.
- `VOX_ACTION_BLOCKED` emitido se confirmation falha, expira ou e cancelada.

## Versionamento

- v1 (2026-05-18): versao inicial canonica.
