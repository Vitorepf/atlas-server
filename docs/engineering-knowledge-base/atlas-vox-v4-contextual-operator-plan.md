---
id: atlas-vox-v4-contextual-operator-plan
type: engineering_plan
title: Atlas Vox V4 Contextual Operator - Plan (paper-only, gated by V3)
status: planned
category: planning
priority: 92
implementation_state: paper_only_gated_by_v3_not_current_runtime
summary: Plano decision-complete para V4 Contextual Operator do Atlas Vox. Define quais context_refs sao permitidos / opt-in / proibidos, como overlay mostra contexto usado, como remover contexto antes de executar, evidence ledger, privacidade e fronteiras com mobile / Voice Realtime. Documento de papel; NENHUM runtime V4 implementado. Bloqueado por GATE V3 (Lei 0.9 + ADR 0003).
tags:
  - atlas-vox
  - v4
  - contextual-operator
  - plan
  - gated
  - privacy
capabilities:
  - vox_v4_paper_plan
  - vox_v4_context_ref_taxonomy
  - vox_v4_privacy_invariants
  - vox_v4_gate_requirements
decisions:
  - V4 Contextual Operator e paper-only enquanto GATE V3 nao fechar; nenhum codigo runtime V4 e introduzido por esta onda.
  - context_refs default-on (workspace/active_surface/composer_selection/last_vox_transcript/last_kernel_response/atlas_dev_recent_run/terminal_recent_output) sao permitidos automaticamente porque sao Atlas-internal.
  - context_refs opt-in (active_app_name/active_window_title/focused_text_selection/filesystem_file_open_in_editor) exigem toggle por kind, TTL <= 24h e indicador permanente no overlay.
  - context_refs proibidos (screen recording, full screen OCR, browser history, messages/email, full disk scan, keychain, clipboard continuous watch) so podem mudar via nova ADR.
  - Conteudo cru NUNCA entra no payload do ledger; apenas sha256 + metadados curtos.
  - V4 nao toca atlas-app/ nem app/Services/Ai/Voice/ (ADR 0003 segue valida).
maintenance:
  - Atualizar quando GATE V3 abrir; ate la, doc e congelado em forma.
  - Implementacao deste plano exige nova ADR (V4 unlock) e revisao de Vitor.
related_paths:
  - docs/contracts/vox/README.md
  - docs/contracts/vox/VoxIntentPacket.v1.md
  - docs/contracts/vox/VoxConfirmation.v1.md
  - docs/contracts/vox/VoxActionOutcome.v1.md
  - docs/contracts/vox/VoxContextPacket.v1.md
  - docs/contracts/vox/VoxContextRef.v1.md
  - docs/engineering-knowledge-base/atlas-vox-operational-thinking-interface.md
  - docs/engineering-knowledge-base/adr/0003-vox-vs-voice-realtime-surface-boundary.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-vox-v4-contextual-operator-plan

graph_title: Atlas Vox V4 Contextual Operator - Plan

graph_world: atlas

graph_layer: system

graph_kind: index

graph_parent: atlas-vox-operational-thinking-interface

graph_status: planned

graph_source: repo

owner: surface-architecture

patamar_after: []
patamar_next: []
versions:
  - atlas-vox-v0
  - atlas-vox-v3
  - atlas-vox-v4
  - atlas-vox-v6

repo_paths:
  - docs/engineering-knowledge-base/atlas-vox-v4-contextual-operator-plan.md

allowed_changes:
  - Refinar definicao de context_refs permitidos / opt-in / proibidos.
  - Atualizar gate requirements quando GATE V3 fechar.
  - Adicionar referencia a ADR V4 quando criada.

forbidden_changes:
  - Implementar runtime V4 antes de GATE V3 verde.
  - Adicionar endpoint, controller ou service Vox V4 sem nova ADR.
  - Promover graph_status para building/active sem aprovacao explicita de Vitor.
  - Relaxar a lista de context_refs proibidos sem nova ADR.
  - Implementar context_refs opt-in sem UI de preview + remocao.

depends_on:
  - atlas-vox-operational-thinking-interface
  - adr-0003-vox-vs-voice-realtime-surface-boundary
  - vox-contracts-v1-index

flows_to:
  - vox-context-packet-v1
  - vox-context-ref-v1

unlocks:
  - vox-context-packet-v1
  - vox-context-ref-v1

governs:
  - vox-v4-implementation-scope

evidence:
  - docs/engineering-knowledge-base/atlas-vox-v4-contextual-operator-plan.md
  - docs/contracts/vox/VoxContextPacket.v1.md
  - docs/contracts/vox/VoxContextRef.v1.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

next_actions:
  - Manter graph_status=planned ate GATE V3 fechar verde.
  - Quando V3 fechar, abrir ADR 0004 "Atlas Vox V4 Contextual Operator scope" antes de qualquer implementacao.
  - Gerar schemas JSON formais (atlas.vox.context_ref.v1.schema.json + atlas.vox.context_packet.v1.schema.json) em app/Services/Ai/Vox/Schema/ apenas apos ADR 0004 ativa.

visual_tags:
  - system
  - plan
  - vox
  - v4
  - gated

ai_entrypoints:
  - Leia este plano antes de propor qualquer alteracao em runtime Vox V3 que parece V4 (context, screenshot, terminal scrape, browser).
  - Leia "Onde Se Encaixa" antes de cogitar mover graph_status para building.

ai_usage_notes:
  - V4 e paper-only enquanto graph_status=planned. Codigo runtime V4 = stop-the-line.
  - As listas de context_refs nesta doc sao normativas: alterar lista exige nova ADR.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Implementar V4 escondido (rota /ai/vox/v4*, service em Vox/V4/, flag v4_unlock_allowed=true no ledger).
  - Adicionar context_ref proibido (screen recording, full screen OCR, browser history) sem ADR.
  - Permitir context_ref opt-in sem preview no overlay e sem botao de remocao por item.
  - Tocar atlas-app/ ou app/Services/Ai/Voice/ neste plano.
---
# Atlas Vox V4 Contextual Operator - Plan

> Documento de **papel**. Nenhum runtime V4 e implementado por esta entrega.
> V4 esta congelado por **Lei 0.9** ([atlas-vox-operational-thinking-interface](atlas-vox-operational-thinking-interface.md))
> e **ADR 0003** ([0003-vox-vs-voice-realtime-surface-boundary](adr/0003-vox-vs-voice-realtime-surface-boundary.md))
> ate GATE V3 verde + aprovacao explicita de Vitor.

## Resumo

V4 Contextual Operator transforma Vox de "compilador de intencao" em "operador
que entende `isso`, `aqui`, `esse erro`, `esse projeto`, `aquela decisao`".
Concretamente, V4 adiciona ao `VoxIntentPacket.v1` um conjunto **explicito,
auditavel e governavel** de `context_refs` resolvidos pelo Mac Edge / Kernel,
exibidos no overlay antes da execucao, e gravados no Evidence Ledger.

V4 **nao** adiciona novo modo de execucao, **nao** adiciona novo provider,
**nao** adiciona novo dominio. So adiciona contexto a intent ja existente.

## Papel no Atlas

V4 e o degrau "outro patamar inicial" da Escada Vox V0-V10 (ver
[operational-thinking-interface](atlas-vox-operational-thinking-interface.md#escada-vox-v0-v10)).
Ele permanece dentro da fronteira Mac-first definida pela ADR 0003 e sob
Leis 0/0.5/0.75/0.9.

| Capacidade V4 | Diferenca contra V3 |
|---|---|
| Workspace consciente | `context_refs[*].kind=workspace` resolvido pelo Kernel (path sha256, git branch, atlas project, ultima atividade). |
| Surface ativa | `kind=active_surface` (cartografia/code/atlas_ai/atencao/control_plane) lido do Desktop. |
| Selecao no composer | `kind=composer_selection` (texto selecionado dentro do Atlas Desktop) anexado a intent. |
| Continuidade Vox | `kind=last_vox_transcript` / `last_kernel_response` reusa transcript anterior como contexto. |
| Atlas Dev recente | `kind=atlas_dev_recent_run` referencia run id + status. |
| Terminal Atlas | `kind=terminal_recent_output` SO se for o terminal embutido do Atlas (NUNCA terminal externo). |
| Preview obrigatorio | Overlay mostra cada `context_ref` resolvido antes de Compilar intencao. |
| Remocao por item | Vitor pode remover qualquer `context_ref` antes de executar; remocao e ledgered. |
| Evidence completo | `VOX_CONTEXT_ATTACHED` carrega kinds + sha256 (nunca conteudo cru). |

V4 **nao** adiciona: novos provedores, novo executor, novo modo de prompt,
nova politica de risco, nova ADR de fronteira (a 0003 segue valida).

## Onde Se Encaixa

V4 so comeca a implementar quando **todos** os criterios abaixo estao verdes
ou explicitamente waived por Vitor:

| Gate | Como verificar | Owner |
|---|---|---|
| V3 Certification Pack ready | `GET /ai/vox/gate-v3/certification-pack` | `VoxV3CertificationPackService` |
| Vitor explicit approval | `POST /ai/vox/gate-v3/review` com `decision=approved_for_v4_planning` | humano (Vitor) |
| Readiness pass | `GET /ai/vox/readiness` retorna `status=ready` | `VoxReadinessService` |
| Hardening audit | `GET /ai/vox/audit/v3-hardening` retorna `pass` OU `warn` (sem `fail`) | `VoxV3HardeningAuditService` |
| Privacy opt-in UI | Toggle por kind no Settings do Vox (Atlas Desktop) | atlas-desktop |
| Context preview UI | Bloco "Contexto (n)" + botao remover por item no overlay | atlas-desktop |
| ADR V4 ativa | ADR 0004 `vox-v4-contextual-operator-scope.md` aprovada | surface-architecture |

Mesmo com todos verdes, V4 nao auto-start: `v4_unlock_allowed` permanece
`false` no ledger ate uma onda dedicada explicitamente flipar (e essa onda
exige aprovacao manual + ADR 0004).

## Contratos

Dois contratos novos (paper-only) cobrem o shape de V4:

- [VoxContextRef.v1](../contracts/vox/VoxContextRef.v1.md) - schema de **um**
  ref contextual. Lista enumerada de `kind` permitidos / opt-in / proibidos.
- [VoxContextPacket.v1](../contracts/vox/VoxContextPacket.v1.md) - agregador
  de N `VoxContextRef.v1` produzido pelo Kernel, consumido por overlay +
  evidence service.

`VoxIntentPacket.v1` ja carrega o campo `context_refs` em V0-V3 com enum
restrito (`file | selection | active_window | terminal_recent | none`). V4
**estende** o enum sem quebrar o contrato v1 (proposta `v1.x` quando ADR 0004
abrir).

### Context refs permitidos (default-on)

| `kind` | Conteudo no ledger |
|---|---|
| `workspace` | sha256 do path; project_id; git_sha |
| `active_surface` | surface_name (enum curto, ja publico) |
| `composer_selection` | sha256 do texto; comprimento |
| `last_vox_transcript` | transcript_id (ref opaco) |
| `last_kernel_response` | intent_id (ref opaco) |
| `atlas_dev_recent_run` | run_id; status |
| `terminal_recent_output` | session_id; exit_code; sha256 (so terminal embutido) |

### Context refs opt-in forte

Toggle por kind no Settings + preview no overlay + TTL <= 24h por sessao.

| `kind` | `meta` no ledger |
|---|---|
| `active_app_name` | bundle id |
| `active_window_title` | sha256 do titulo |
| `focused_text_selection` | sha256 + comprimento (Mac Edge redacta secret/cartao/cpf/token/password antes) |
| `filesystem_file_open_in_editor` | sha256 do path |

### Context refs proibidos

Adicao = nova ADR. Remocao = nova ADR.

- `screen_recording_continuous`
- `full_screen_ocr`
- `silent_other_apps_state`
- `browser_history`
- `messages_email_imessage_slack`
- `full_disk_scan`
- `keychain_passwords_credentials`
- `clipboard_continuous_watch`

## Fluxo

### Overlay mostra contexto antes de Compilar

```
┌─────────────────────────────────────────────────────────┐
│ Ouvi:                                                   │
│ "manda o codex investigar isso aqui sem editar"         │
│                                                         │
│ Contexto (3)                            [Editar]        │
│ ─────────────────────────────────────────────────────── │
│   • workspace             /atlas-server (main)   [×]    │
│   • active_surface        code                   [×]    │
│   • composer_selection    "..." 184 chars        [×]    │
│                                                         │
│ Contexto extendido: desligado                           │
│ Modo: [ Intent Compile ▼ ]                              │
│ Risco proposto: R1 (read-only, sem edicao)              │
│                                                         │
│ [Compilar intencao]   [Editar]   [Cancelar]             │
└─────────────────────────────────────────────────────────┘
```

Invariantes UI:

1. Lista visivel sempre que `context_refs.length > 0`.
2. Botao `[×]` por item remove o ref antes de Compilar; emite
   `VOX_CONTEXT_REMOVED`.
3. Botao `[Editar]` abre tela com toggle por kind e link para esta doc.
4. Chip `Contexto extendido` (vermelho discreto) quando algum kind opt-in
   esta ativo na sessao.
5. Frase humana gerada pelo Kernel: `"considerando workspace + selecao
   do composer; nao usa contexto extendido"`.

### Remocao de contexto antes de executar

Tres caminhos, todos auditados:

1. **Por item**: `[×]` ao lado do `context_ref` no overlay - remove apenas
   aquele. Emite `VOX_CONTEXT_REMOVED`.
2. **Todos**: botao `Limpar contexto` no painel `[Editar]` - remove todos.
   Emite `VOX_CONTEXT_CLEARED`.
3. **Sessao inteira**: **Esc Esc** (eclipse) - aborta a sessao, descarta
   contexto, emite `VOX_ACTION_BLOCKED` com
   `reason_code=eclipse_user_initiated`.

## Regras para IA

- Esta doc e **bloqueador doutrinario**: codigo runtime V4 nao prossegue sem
  respeitar lista de context_refs permitidos/opt-in/proibidos aqui.
- Alterar a lista exige nova ADR; sugerir alteracao sem ADR = stop-the-line.
- Schemas formais `.schema.json` so sao gerados em onda posterior, apos ADR
  0004 abrir.
- Nunca duplicar conteudo cru em payloads de exemplo - sempre `sha256` +
  metadado curto.
- Audit V3 (`/ai/vox/audit/v3-hardening`) deve ganhar check
  `v4_context_forbidden_kinds_zero` **apenas** quando ADR 0004 abrir.
- Continua valido: nao tocar `atlas-app/` nem `app/Services/Ai/Voice/`.

## Escopo de Implementacao

### O que esta onda (V3.9 paper-only) entrega

- Este plano (`atlas-vox-v4-contextual-operator-plan.md`).
- Contrato canonico [VoxContextPacket.v1](../contracts/vox/VoxContextPacket.v1.md).
- Contrato canonico [VoxContextRef.v1](../contracts/vox/VoxContextRef.v1.md).

### O que esta onda NAO entrega (proibido)

- Endpoint, controller, service, model, migration, route, comando artisan.
- Codigo Rust em `crates/atlas-tauri` ou `crates/atlas-platform`.
- Componente React em `atlas-desktop`.
- Esquema JSON `.schema.json` formal (sera gerado em onda V4.0).
- Migracao de tabela `atlas_vox_context_*`.
- Mudanca em qualquer audit / metrics / gate / certificacao existente.
- Flip de `v4_unlock_allowed` no ledger.

`graph_status` permanece `planned` ate ADR 0004 abrir.

### O que continua proibido ate GATE V3 fechar

- Screen recording continuo, OCR de tela inteira sem gesto deliberado.
- Leitura silenciosa de outros apps, browser history, messages / email.
- Scan amplo de filesystem (`~/Documents/**`, `~/Downloads/**`, etc).
- Captura de senhas / keychain / autofill / clipboard cru por default.
- Persistencia de audio cru (segue **Lei 0.75**).
- Qualquer toque em `atlas-app/` (mobile) ou `app/Services/Ai/Voice/`.
- Provider direto (sem passar pelo Kernel).

## Dependencias

- [atlas-vox-operational-thinking-interface](atlas-vox-operational-thinking-interface.md)
  - Leis 0/0.5/0.75/0.9 + escada V0-V10.
- [ADR 0003](adr/0003-vox-vs-voice-realtime-surface-boundary.md) -
  fronteira Vox vs Voice Realtime (segue valida em V4).
- [vox-contracts-v1-index](../contracts/vox/README.md) - 5 contratos v1.
- ADR 0004 (futura) - "Atlas Vox V4 Contextual Operator scope" -
  pre-requisito para qualquer runtime V4.

V4 vive **inteiramente** em `atlas-server/app/Services/Ai/Vox/` e
`atlas-desktop/`. Nao toca `app/Services/Ai/Voice/` nem `atlas-app/` -
auditor V3 mantem static scan `voice_realtime_untouched` passing.

## Evidencias

Tres novos eventos canonicos (a serem confirmados em ADR 0004):

| Evento | Quando | Payload (resumido) |
|---|---|---|
| `VOX_CONTEXT_ATTACHED` | apos resolucao no Kernel | `{ intent_id, packet_id, kinds, default_on_count, opt_in_count, extended_active, forbidden_attempted_count, redacted_any, removed_kinds, resolver_version }` |
| `VOX_CONTEXT_REMOVED` | operador remove ref antes de Compilar | `{ intent_id, removed_kinds, remaining_kinds }` |
| `VOX_CONTEXT_OPTIN_TOGGLED` | toggle opt-in muda no Settings | `{ kind, enabled, ttl_seconds, source }` |

Regras:

1. **Nunca** persistir conteudo cru no ledger; apenas `ref_sha256` + metadados.
2. `compiled_prompt` continua **fora** do ledger por default
   (regra ja vigente em VoxIntentPacket.v1).
3. `VOX_CONTEXT_ATTACHED` e emitido **antes** de `VOX_INTENT_COMPILED`. Se
   nao houve contexto resolvido, o evento e omitido.
4. `VOX_CONTEXT_OPTIN_TOGGLED` e emitido sempre, mesmo para `enabled=false`.

## Riscos

Camadas defensivas (defense in depth):

1. **Schema-level**: `VoxContextRef.v1` enumera `kind`. Kernel rejeita 422
   qualquer kind fora da lista.
2. **Edge-level**: Mac Edge redacta patterns sensiveis (cartao, CPF, secret,
   key=, password=, token=) antes de o conteudo sair do processo.
3. **UI-level**: opt-in obrigatorio + preview + remocao por item + chip
   permanente.
4. **TTL-level**: opt-in expira em <= 24h.
5. **Eclipse-level**: Esc Esc zera consentimento e descarta contexto.
6. **Audit-level**: hardening audit ganha check
   `v4_context_forbidden_kinds_zero` (apenas quando ADR 0004 abrir).
7. **Ledger-level**: conteudo cru = stop-the-line.

Risco residual: operador habilitar opt-in e nao olhar o overlay. Mitigado
pelo chip permanente + TTL curto + Esc Esc.

## Exemplos

Payload resumido de `VOX_CONTEXT_ATTACHED` em estado saudavel:

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

Tentativa de `kind` proibido (Kernel recusa 422 + emite
`VOX_ACTION_BLOCKED`):

```json
{
  "reason_code": "v4_forbidden_context_ref",
  "kind": "browser_history",
  "message": "kind 'browser_history' is in the V4 forbidden list (V3 hardening)."
}
```

Exemplos completos vivem nos contratos: ver
[VoxContextRef.v1](../contracts/vox/VoxContextRef.v1.md#exemplos) e
[VoxContextPacket.v1](../contracts/vox/VoxContextPacket.v1.md#exemplos).

## Proximas Acoes

1. Manter `graph_status: planned` ate GATE V3 fechar verde.
2. Quando V3 fechar: abrir ADR 0004 "Atlas Vox V4 Contextual Operator scope"
   antes de qualquer implementacao runtime.
3. Gerar `.schema.json` formal em `app/Services/Ai/Vox/Schema/` apenas apos
   ADR 0004 ativa.
4. Implementar `VoxV3HardeningAuditService` check
   `v4_context_forbidden_kinds_zero` na primeira onda V4 (nao agora).
5. Implementar UI de toggle por kind + preview + remocao no
   `atlas-desktop` em onda V4.1 (nao agora).

## Versionamento

- v0.1 (2026-05-18) - paper-only. Bloqueado por GATE V3.
