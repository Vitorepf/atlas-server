---
id: atlas-ai-continuity-session-state
type: engineering_knowledge
title: Atlas AI Continuity And Session State
status: active
category: memory-context
priority: 88
summary: Contrato canonico para continuidade entre sessoes, compactacao, handoff de provider e uso de Open Brain em atlas continue, app e surfaces.
tags:
  - atlas-ai
  - continuity
  - session-state
  - open-brain
capabilities:
  - continuity_context_projection
  - session_continuity
decisions:
  - Continuidade e extensao auditavel do mesmo trabalho, nao replay cego de chat.
  - Compactacao preserva intent, decisions, evidence refs, pending risks e context hash; nao preserva prompt bruto por default.
  - Handoff entre providers ou surfaces exige novo receipt/evidence que aponte para a sessao anterior.
maintenance:
  - Atualizar quando atlas continue, provider handoff, session compaction ou Open Brain injection mudarem contrato.
related_paths:
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - resolver-o-que-vale-a-pena/root-md/Atlas_AI_Sessoes_Compactacao_Continuidade.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-continuity-session-state

graph_title: Atlas AI Continuity And Session State

graph_world: atlas

graph_layer: module

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Continuity And Session State
canonical_name: Atlas AI Continuity And Session State
technical_name: atlas-ai-continuity-session-state
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-ai-continuity-session-state.md

owner: memory-context

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-continuity-session-state.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - memory-context

evidence:
  - docs/engineering-knowledge-base/atlas-ai-continuity-session-state.md
evidence_refs:
  - symbol: AtlasAiContinuitySessionStateService
  - command: atlas:aaeos:ai-continuity-session-state
  - test: AtlasAiContinuitySessionStateTest

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: low

visual_tags:
  - module
  - module
  - memory-context

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas AI Continuity And Session State

Este documento define o contrato de continuidade do Atlas AI. Ele complementa
`open-brain-context-injection.md`: Open Brain monta contexto; este doc define
quando uma nova execucao continua a mesma historia operacional.

## Autoridade

| Assunto | Autoridade |
|---|---|
| Injecao automatica de Open Brain | `open-brain-context-injection.md` |
| Contratos de receipt, envelope e ledger | `atlas-ai-kernel-architecture.md` |
| Memory Core e Context Pack | `atlas-ai-memory-context-core-open-brain.md` |
| Continuidade, compactacao e handoff | Este documento |

## Estados De Sessao

| Estado | Significado | Pode continuar? |
|---|---|---|
| `active` | Trabalho em andamento, contexto recente confiavel. | Sim |
| `paused` | Operador pausou, mas intent e evidence ainda sao validos. | Sim, com resumo |
| `handoff` | Provider/surface/executor mudou durante a operacao. | Sim, com novo receipt |
| `compacted` | Historico bruto foi substituido por resumo auditado. | Sim, se refs preservadas |
| `closed` | Resultado entregue e evidence final registrada. | Apenas como nova operacao relacionada |
| `archived` | Preservada por historia, nao ativa. | Nao sem revalidacao humana |

## Contrato Minimo De Session Snapshot

Um snapshot de continuidade deve conter:

| Campo | Regra |
|---|---|
| `session_id` | Identificador estavel da sessao operacional. |
| `parent_session_id` | Preenchido quando veio de handoff ou continuation. |
| `intent` | Objetivo atual, em linguagem curta. |
| `surface_id` | Surface que gerou ou retomou o trabalho. |
| `domain_id` / `flow_id` | Quando aplicavel, usar catalogo canonico de dominios. |
| `context_pack_hash` | Hash do contexto usado ou reutilizado. |
| `decision_receipt_id` | Receipt ativo ou ultimo receipt relevante. |
| `evidence_refs` | Refs de ledger, traces, files ou runs que sustentam a continuidade. |
| `pending_questions` | Perguntas ainda abertas. |
| `pending_risks` | Riscos/gates pendentes antes de agir. |
| `provider_safe_summary` | Resumo compacto, redigido e seguro para provider. |
| `privacy_flags` | Classes de privacidade que bloquearam ou limitaram contexto. |

## Compactacao

Compactacao nao e resumir tudo. E preservar apenas o que a proxima operacao
precisa para agir corretamente.

Deve preservar:

- intent atual;
- decisoes tomadas e alternativas rejeitadas;
- evidence refs;
- arquivos, comandos ou routes relevantes;
- constraints do operador;
- riscos e gates pendentes;
- provider/model/surface usados quando isso afeta reproducibilidade;
- context hash e motivo para refresh ou reuse.

Nao deve preservar por default:

- prompt bruto completo;
- notas pessoais nao redigidas;
- secrets, tokens, envs ou paths sensiveis sem necessidade;
- pensamento exploratorio sem decisao;
- output verbatim de provider sem valor operacional.

## `atlas continue`

`atlas continue` deve:

1. localizar a sessao ou run mais relevante;
2. verificar se `context_pack_hash` ainda e valido;
3. regenerar Open Brain quando docs/codigo/memoria mudaram;
4. mostrar resumo compacto do que sera retomado;
5. criar novo receipt quando provider, executor, policy ou safety mode mudarem;
6. registrar evidence de continuidade no ledger.

Flags esperadas seguem `open-brain-context-injection.md`:

- `--no-open-brain`;
- `--require-open-brain`;
- `--open-brain-refresh`;
- `--open-brain-budget=...`.

## Handoff De Provider Ou Surface

Provider swap ou troca de surface no meio da operacao deve ser explicito:

- o provider novo recebe apenas resumo provider-safe;
- o receipt registra provider anterior, provider novo e motivo;
- o ledger registra `provider_fallback`, `handoff` ou evento equivalente;
- se a mudanca elevar permissao, exigir gate humano ou policy adequada.

## Failure Modes

| Falha | Tratamento |
|---|---|
| Contexto antigo sem hash | Regenerar Open Brain ou falhar em modo required. |
| Sessao com privacy pendente | Continuar em modo read/plan-only ate redaction. |
| Provider mudou sem receipt | Registrar violation e emitir novo receipt antes de agir. |
| Evidence refs ausentes | Pedir contexto ou rodar coleta antes de executar. |
| Compactacao contradiz docs canonicos | Docs canonicos vencem; snapshot vira suspeito. |

## Source Material

- `resolver-o-que-vale-a-pena/root-md/Atlas_AI_Sessoes_Compactacao_Continuidade.md`
- `docs/engineering-knowledge-base/open-brain-context-injection.md`
- `docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md`

## Resumo

Contrato canonico para continuidade entre sessoes, compactacao, handoff de provider e uso de Open Brain em atlas continue, app e surfaces.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
