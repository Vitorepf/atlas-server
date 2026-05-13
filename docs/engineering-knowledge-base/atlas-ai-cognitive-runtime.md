---
id: atlas-ai-cognitive-runtime
type: engineering_knowledge
title: Atlas AI Cognitive Runtime
status: active
category: architecture
priority: 100
summary: Lei canonica para memoria governada, busca de contexto, sessoes longas de alta performance, compactacao automatica e auditoria cognitiva do Atlas.
tags:
  - atlas-ai
  - cognitive-runtime
  - memory
  - retrieval
  - long-sessions
  - compaction
capabilities:
  - cognitive_runtime
  - governed_memory
  - retrieval_quality
  - long_session_performance
  - automatic_compaction
  - cognitive_quality_audit
decisions:
  - Memoria, busca de contexto, sessao longa, compactacao e auditoria cognitiva sao sistema nervoso central do Atlas.
  - A meta operacional minima desta frente e sustentar 72 horas de trabalho com alta qualidade decisoria mensuravel.
  - Compactacao automatica e continuidade so contam como maduras quando preservam invariantes, evidence refs, decisoes, riscos e arquivos quentes sem depender de chat bruto.
  - Busca de contexto deve ser medida por precisao, recall critico, contaminacao, contexto obsoleto e ganho real em tarefas de programacao.
  - Nenhuma memoria, contexto, embedding, resumo ou handoff pode bypassar Policy, Receipt, Ledger, privacy ou Cognitive Immune Gate.
maintenance:
  - Leia este documento antes de alterar memoria, retrieval, Open Brain, atlas continue, compactacao, context pack, long sessions ou auditoria de qualidade.
  - Atualize este documento quando a meta de 72h, metricas cognitivas, DoD de compactacao ou qualidade de busca mudarem.
  - Manter abaixo de 260 linhas; detalhes de schema ficam nos docs filhos.
related_paths:
  - docs/ap/AP-688-cognitive-runtime-72h-contract.md
  - docs/engineering-knowledge-base/cognitive-runtime/schemas-and-packets.md
  - docs/engineering-knowledge-base/cognitive-runtime/enterprise-excellence-checklist.md
  - docs/engineering-knowledge-base/cognitive-runtime/retrieval-benchmark.md
  - docs/engineering-knowledge-base/cognitive-runtime/state-of-art-research-map.md
  - docs/engineering-knowledge-base/cognitive-runtime/runbook.md
  - docs/engineering-knowledge-base/cognitive-runtime/failure-modes.md
  - docs/engineering-knowledge-base/atlas-ai-memory-context-core-open-brain.md
  - docs/engineering-knowledge-base/atlas-ai-continuity-session-state.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
  - docs/engineering-knowledge-base/memory/retrieval-and-context.md
  - docs/engineering-knowledge-base/memory-core-maturity-dod.md
  - docs/engineering-knowledge-base/context-pack.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-cognitive-runtime

graph_title: Atlas AI Cognitive Runtime

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: architecture

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md

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
  - architecture

evidence:
  - docs/engineering-knowledge-base/atlas-ai-cognitive-runtime.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

visual_tags:
  - system
  - module
  - architecture

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
# Atlas AI Cognitive Runtime

Este documento e a lei mae da frente que faz o Atlas deixar de ser uma IA com
features e virar uma IA operacionalmente superior: memoria governada, contexto
preciso, sessoes longas, compactacao automatica e auditoria de qualidade.

## Positioning

```text
Atlas como sistema governado: ~75-85%
Atlas como runtime autonomo confiavel: ~35-50%
Atlas como produto ultra enterprise completo: ~45-60%
```

Esta frente e uma das maiores alavancas para mover os dois ultimos numeros. Ela
melhora autoprogramacao, pesquisa, continuidade, seguranca, qualidade de codigo
e velocidade porque reduz perda de contexto e evita aprendizado ruim.

## Authority

| Assunto | Autoridade detalhada |
|---|---|
| AP oficial e aceite da frente | `docs/ap/AP-688-cognitive-runtime-72h-contract.md` |
| Schemas e packets cognitivos | `cognitive-runtime/schemas-and-packets.md` |
| Checklist enterprise e nivel de superacao | `cognitive-runtime/enterprise-excellence-checklist.md` |
| Benchmark de retrieval/contexto | `cognitive-runtime/retrieval-benchmark.md` |
| Pesquisa estado-da-arte e postura Atlas | `cognitive-runtime/state-of-art-research-map.md` |
| Runbook operacional | `cognitive-runtime/runbook.md` |
| Failure modes cognitivos | `cognitive-runtime/failure-modes.md` |
| Separacao raw/evidence/learning/memory/context/decision | `memory/cognitive-immune-learning-kernel.md` |
| Contratos de memoria, privacy, projection e stores | `memory/contracts.md` |
| Busca, ranking, budget e context refs | `memory/retrieval-and-context.md` |
| Open Brain automatico em surfaces | `open-brain-context-injection.md` |
| Continuidade, compactacao e handoff | `atlas-ai-continuity-session-state.md` |
| Maturidade e DoD do Memory Core | `memory-core-maturity-dod.md` |
| Telemetria, performance e evidence | `atlas-ai-telemetry-evidence-performance.md` |

Este documento vence quando a pergunta for prioridade, meta, metricas ou DoD
integrado da frente cognitiva. Os documentos filhos vencem nos detalhes de
schema, API, stores e comandos.

## Non-Negotiable Invariants

1. Raw capture nunca e memoria, contexto, evidence, learning signal ou decision.
2. Memoria pertence ao Atlas, nao ao provider.
3. Retrieval recebe candidatos, nao autoridade.
4. Context pack precisa explicar por que cada ref entrou e por que refs criticas foram excluidas.
5. Compactacao nao resume conversa: preserva estado operacional verificavel.
6. Sessoes longas precisam ser mensuraveis por qualidade, nao por tempo aberto.
7. Handoff entre provider, surface ou executor exige receipt/evidence novo.
8. Contexto insuficiente e melhor que contexto contaminado.
9. Toda promocao de memoria ou comportamento critico passa por gate, policy e evidence.
10. Nenhuma surface monta memoria manualmente no prompt.

## Priority Stack

| Prioridade | Frente | Resultado esperado |
|---|---|---|
| P0 | Memoria governada | Lembrar apenas o que tem escopo, fonte, estado, privacy e utilidade. |
| P0 | Busca de contexto | Recuperar o contexto certo, pequeno, provider-safe e com reasons. |
| P0 | Sessao longa 72h | Manter alta qualidade decisoria por 3 dias sem drift perigoso. |
| P0 | Compactacao automatica | Continuar apos compaction sem perder invariantes, riscos ou hot files. |
| P0 | Auditoria cognitiva | Medir se memoria/contexto/compactacao ajudaram ou prejudicaram. |

## 72h Long Session Goal

A meta nao e manter um processo aberto por 72 horas. A meta e sustentar uma
operacao longa com alta performance decisoria.

Uma sessao de 72h so conta como `ready` quando o Atlas mede:

| Metrica | Bom sinal | Alerta |
|---|---|---|
| decision quality by hour | decisoes continuam aceitas e rastreaveis | queda sem diagnostico |
| repeated work rate | baixo; repeticao explicada por mudanca real | mesmo arquivo/risco redescoberto varias vezes |
| drift rate | objetivo e constraints preservados | agente troca de frente sem evidence |
| compaction recovery time | retomada rapida com snapshot valido | precisa reler chat bruto ou operador reconstruir tudo |
| missed invariant count | zero para policy, hot files, gates e ownership | qualquer violacao de regra ja declarada |
| context precision@k | refs principais sao realmente usadas | refs irrelevantes dominam budget |
| missed critical context | zero para docs/codigo/APs essenciais | falha por nao buscar doc existente |
| stale context use | zero ou justificado | doc antigo vence doc canonico |
| context contamination rate | zero | raw/untrusted/private entra em prompt |
| cost per useful hour | estavel e explicado | custo sobe por repeticao ou excesso de contexto |

## Compaction DoD

Uma compactacao automatica e aceita quando preserva:

- objetivo atual;
- fase atual e proximo passo;
- decisoes tomadas e alternativas rejeitadas;
- invariantes, gates e regras do operador;
- arquivos quentes e ownership;
- comandos rodados e validacoes;
- diffs relevantes e evidence refs;
- riscos pendentes;
- context pack hash e motivo de refresh/reuse;
- limites do que nao pode ser feito.

Ela deve bloquear continuidade quando:

- evidence refs somem;
- hot files ou ownership ficam ambiguos;
- policy/receipt/ledger deixam de estar conectados;
- resumo contradiz docs canonicos;
- privacy flags ou secrets nao foram redigidos;
- o proximo executor precisaria do chat bruto para agir com seguranca.

## Retrieval Quality DoD

Busca de contexto e madura quando:

- cada context ref tem source, reason, scope, priority e provider-safe summary;
- ranking privilegia docs canonicos, codigo real, evidence e decisoes aceitas;
- retrieval registra refs excluidas por stale, private, conflicted, out_of_scope ou low_relevance;
- vector/hybrid search nunca bypassa filtros deterministicos;
- tarefas de programacao recebem code refs e testes relevantes sem despejo bruto;
- benchmark mede precision@k, missed critical context e context contamination.

## Cognitive Audit Loop

Toda sessao longa ou compactacao relevante deve gerar um score read-only:

```text
Cognitive Runtime Net Value =
  ganho por contexto/memoria util
- dano por contexto errado
- repeticao evitavel
- drift de objetivo
- custo cognitivo/tokenico
- violacoes de policy/privacy
```

O score pode alimentar Self-Improvement proposal-only, mas nao pode promover
memoria, alterar policy ou auto-aplicar mudancas criticas sozinho.

## Implementation Roadmap

1. Implementar schema de long-session snapshot com metricas de 72h.
2. Adicionar testes de compactacao boa/ruim e bloqueio por evidence ausente.
3. Criar benchmark frio de retrieval para programacao: docs, code refs, APs e hot files.
4. Emitir audit packet read-only apos compactacao, handoff e sessoes longas.
5. Integrar score com telemetry/evidence sem criar memoria paralela.
6. Promover apenas depois de dogfood real e replay reproduzivel.

## Done Means

Esta frente so esta madura quando um trabalho real de engenharia consegue durar
72 horas com compactacoes, handoffs e retrieval automaticos mantendo qualidade
alta, baixo retrabalho, zero bypass de Kernel e evidence suficiente para replay.

## Resumo

Lei canonica para memoria governada, busca de contexto, sessoes longas de alta performance, compactacao automatica e auditoria cognitiva do Atlas.

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
