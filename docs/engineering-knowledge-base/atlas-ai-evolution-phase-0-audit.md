---
id: atlas-ai-evolution-phase-0-audit
type: engineering_knowledge
title: Atlas AI Evolution Phase 0 Audit
status: active
category: architecture
priority: 88
summary: Auditoria rigorosa da Fase 0 do roadmap de evolucao do Atlas AI, definindo o que ja existe, o que falta implementar, o ganho esperado e o que nao deve virar subsistema paralelo.
tags:
  - atlas-ai
  - phase-0
  - provider-strategy
  - evidence-ledger
  - telemetry
capabilities:
  - provider_strategy_matrix
  - provider_usage_contract
  - phase_0_evidence_audit
  - phase_0_telemetry_audit
  - phase_0_self_improvement_audit
decisions:
  - Fase 0 e AP-99: CLI Provider Usage / Performance Contract.
  - Fase 0 deve estender Evidence Ledger, DecisionReceipt, ai_router_decisions, telemetry e AtlasCliProviderStrategyService; nao deve criar ledger, receipt, router ou policy paralelos.
  - O ganho real da Fase 0 e transformar escolha de provider de hipotese estatica em matriz empirica por dominio, flow, task_type, risco, latencia, gates, repair e outcome.
maintenance:
  - Atualizar antes de implementar AP-99 ou alterar provider routing, AiWorker provider events, telemetry aggregation ou AtlasCliProviderStrategyService.
  - Rodar atlas:ai:architecture-validate e testes de provider usage/performance depois de qualquer mudanca nesta area.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-evolution-roadmap.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - app/Services/Ai/AiWorker.php
  - app/Services/Ai/AiGatewayService.php
  - app/Services/Ai/AtlasDecideService.php
  - app/Services/Ai/Cli/AtlasCliProviderStrategyService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-evolution-phase-0-audit

graph_title: Atlas AI Evolution Phase 0 Audit

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Evolution Phase 0 Audit
canonical_name: Atlas AI Evolution Phase 0 Audit
technical_name: atlas-ai-evolution-phase-0-audit
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-ai-evolution-phase-0-audit.md

owner: architecture

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-evolution-phase-0-audit.md

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
  - docs/engineering-knowledge-base/atlas-ai-evolution-phase-0-audit.md

evidence_refs:
  - symbol: AiWorker
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

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
# Atlas AI Evolution Phase 0 Audit

Este documento verifica a Fase 0 do `atlas-ai-evolution-roadmap.md` contra a
arquitetura e o codigo atuais. Ele deve ser lido como ponte entre roadmap e
implementacao.

## Veredito

A Fase 0 vale a pena e deve ser implementada, mas com escopo estreito:

```text
AP-99: CLI Provider Usage / Performance Contract
```

Ela nao e uma nova arquitetura. Ela e a camada que fecha a medicao real entre:

```text
Atlas Decide
-> DecisionReceipt
-> Provider Driver / CLI
-> AiWorker
-> Evidence Ledger
-> Telemetry Rollups
-> Provider Strategy Matrix
-> Self-Improvement provider_performance_review
```

O Atlas ja tem a fundacao. O que falta e normalizar o payload e criar a
projecao que permite aprender empiricamente qual provider e melhor para cada
tipo de trabalho.

## Ganho Esperado

Hoje a escolha de provider ja respeita policy, health, budget, modo e override
manual. Depois da Fase 0, o Atlas passa a decidir com memoria operacional:

| Antes | Depois da Fase 0 |
| --- | --- |
| Preferencia por modo: dev, review, plan, research | Performance real por domain, flow e task_type |
| Health snapshot recente | Historico de latencia, falha, repair e gate |
| Router decision isolada | Router decision correlacionada com receipt, ledger e outcome |
| Self-Improvement ve falhas gerais | Self-Improvement enxerga provider drift e provider/task mismatch |
| "Codex parece melhor para repo" | "Codex passou 92% dos gates em programming.refactor com p95 X e repair_count Y" |
| "Claude parece melhor para arquitetura" | "Claude teve maior acceptance em review/architecture, mas maior latencia" |
| "Gemini parece melhor para contexto gigante" | "Gemini performou melhor quando context_size > N e risk=low/medium" |

Esse e um ganho real de produto porque tira o Atlas do achismo. O Atlas Decide
continua soberano, mas agora pode usar estatistica operacional governada.

## Inventario Da Fase 0

| Item | Status real | Decisao |
| --- | --- | --- |
| Evidence Ledger minimo | Implementado mais forte que o roadmap: `AtlasEvidenceLedger`, `atlas_ledger_events`, `AtlasLedgerEvent`, `LedgerEventType` e replay/read models | Nao reimplementar |
| ProviderCalled / ProviderReturned | Existem no `AiWorker`, mas o payload ainda e insuficiente para o contrato completo de usage/performance | Normalizar payload |
| ProviderFallback | Existe como enum no Ledger, mas precisa ser verificado/normalizado no caminho real de fallback | Cobrir no AP-99 |
| `ai_router_decisions` | Existe e e gravado no gateway com selected/fallback/signals/reason/override | Correlacionar com ledger por trace/envelope/receipt |
| `DecisionReceipt` | Existe, tipado, com `dryRun`, `signedBy` e runtime guard | Reusar, nao criar Dynamic Execution Contract paralelo |
| `AtlasCliProviderStrategyService` | Existe, usa health snapshots, budget, allow_auto e preferred order | Evoluir para consumir agregado empirico depois do contrato |
| Telemetry rollups | Existem, com `AiTraceMetricAggregator` e docs de aggregator_version | Criar projection provider performance compatível |
| Self-Improvement | Existe com flow `self_improvement.provider_performance_review`, mas ainda olha falhas/gates/SLO de forma geral | Alimentar com projection dedicada |
| Policy-as-Code minimo | Ja existe em settings, profiles, budgets, gates e runtime guards | Consolidar como policy executavel, nao duplicar |

## Contrato Correto Da Fase 0

O payload normalizado deve aparecer nos eventos existentes:

```text
PROVIDER_CALLED
PROVIDER_RETURNED
PROVIDER_FALLBACK
```

Nao deve criar tabela nova de ledger. O evento de ledger e a fonte auditavel;
qualquer relatorio ou matriz e projecao recomputavel.

### Campos Obrigatorios

```text
schema_version
provider_cli
model_name_if_available
domain
flow
task_type
risk
trace_id
envelope_id
receipt_id
router_decision_id
started_at
finished_at
latency_seconds
attempts
attempt_number
input_context_size_estimate
output_size_estimate
exit_status
quality_gate_result
repair_count
failure_reason
user_acceptance
ledger_event_ref
```

Alguns campos so ficam completos no `ProviderReturned`. `ProviderCalled` deve
registrar pelo menos identidade, escopo, contexto estimado, attempt, receipt e
router decision quando disponivel.

## Como Deve Funcionar

Fluxo operacional correto:

```text
1. Surface envia input.
2. Kernel cria OperationEnvelope.
3. Atlas Intent/Routing resolve domain, flow, task_type e risco.
4. Atlas Decide escolhe provider/model e emite DecisionReceipt.
5. Gateway registra ai_router_decisions.
6. Worker valida DecisionReceiptRuntimeGuard antes do provider.
7. Worker emite PROVIDER_CALLED com payload normalizado.
8. Provider CLI executa.
9. Worker emite PROVIDER_RETURNED ou PROVIDER_FALLBACK.
10. Quality/Repair/Gates emitem eventos proprios.
11. Projection correlaciona receipt + router + ledger + telemetry.
12. Provider Strategy Matrix passa a enxergar performance empirica.
13. Self-Improvement provider_performance_review gera proposta quando ha drift.
```

## Forma Diferente De Usar O Atlas

A Fase 0 muda o uso ideal do Atlas em tarefas medias/dificeis:

| Uso | Como fica melhor |
| --- | --- |
| `atlas dev "tarefa"` | Atlas escolhe provider por modo e, depois de dados suficientes, por historico real de programming flow semelhante |
| `atlas forge "tarefa complexa"` | Atlas pode justificar provider/harness com base em gates, repair_count e sucesso historico por flow pesado |
| `atlas chat --mode=review` | Atlas pode preferir provider com melhor acceptance/gate para review, nao apenas default global |
| `atlas decide --explain` futuro | Deve mostrar "por que escolhi este provider" com signals, receipt e metricas agregadas |
| Override manual | Continua permitido, mas marcado como excecao; nao deve poluir a matriz como decisao automatica pura |
| Self-Improvement noturno | Passa a detectar "provider X esta piorando neste flow" e propor ajuste revisavel |

O ponto principal: o usuario nao precisa aprender qual provider usar em cada
caso. O Atlas aprende, mede e explica. Quando o usuario passa um provider
especifico, isso vira override auditado, nao novo padrao.

## O Que Nao Implementar Agora

Nao implementar na Fase 0:

- Graph RAG.
- Text-to-SQL.
- API direta de providers.
- Modelos locais.
- Hybrid Router local/API/CLI.
- Background fanout autonomo.
- Specialist Agent Council novo.
- Life Timeline.
- Ingestao ampla de dados pessoais.
- Dynamic Execution Contract como contrato paralelo ao DecisionReceipt.
- Novo Evidence Ledger.
- Nova Policy engine paralela.

Essas capacidades podem ser valiosas, mas antes delas o Atlas precisa medir bem
o que ja executa hoje via CLI.

## Lacunas Concretas

| Lacuna | Risco se ignorar | Implementacao correta |
| --- | --- | --- |
| Payload de `PROVIDER_CALLED` pequeno demais | Nao da para saber domain/flow/task/risk/contexto da chamada | Criar normalizador `ProviderUsagePayload` ou equivalente |
| `PROVIDER_RETURNED` nao carrega contrato completo | Latencia e erro existem, mas sem outcome rico fica dificil ranquear provider | Expandir payload com schema_version e correlacoes |
| Falta projection dedicada por provider/domain/task | Strategy Matrix continua health-based, nao evidence-based | Criar service de projection sobre ledger + router + telemetry |
| Self-Improvement provider review sem input dedicado | Curator so ve falhas gerais | Adicionar read model de provider performance ao flow |
| Manual override pode contaminar estatistica | Atlas aprende preferencia do usuario como se fosse escolha automatica | Marcar `selection_mode=manual_override` e separar nos agregados |
| Context size ainda e estimativa dispersa | Matrix nao entende quando provider ganhou por contexto grande | Centralizar estimativa no payload |

## Critérios De Aceite

Fase 0 esta pronta quando:

1. `PROVIDER_CALLED`, `PROVIDER_RETURNED` e fallback carregam `schema_version`
   e payload normalizado.
2. O payload inclui `provider_cli`, `model`, `domain`, `flow`, `task_type`,
   `risk`, `trace_id`, `envelope_id`, `receipt_id`, attempt, latencia e status.
3. Existe teste unitario do contrato de payload.
4. Existe teste de correlacao com `ai_router_decisions`.
5. Existe projection/agregado minimo por `provider_cli + domain + task_type`.
6. `AtlasCliProviderStrategyService` ainda pode funcionar sem projection, mas
   passa a expor ou preparar consumo da matriz empirica.
7. `self_improvement.provider_performance_review` consegue consumir o agregado
   ou gerar finding quando faltam dados suficientes.
8. `atlas:ai:architecture-validate` ganha AP-99 ou teste equivalente.
9. Nenhuma tabela nova de ledger, receipt paralelo ou policy paralela foi criada.

## Ordem De Implementacao Recomendada

```text
1. Criar contrato/normalizador de Provider Usage Payload.
2. Usar o normalizador em AiWorker para ProviderCalled/Returned/Fallback.
3. Criar testes do payload normalizado.
4. Criar projection minima de provider performance.
5. Criar teste de projection com ledger + ai_router_decisions.
6. Expor projection em comando/diagnostic ou service consumivel.
7. Conectar Self-Improvement provider_performance_review.
8. Adicionar AP-99 no architecture validator.
9. Atualizar docs e rodar sync/index.
```

## Conclusao

A Fase 0 e pequena, mas estrategica. Ela e o primeiro ponto em que o Atlas deixa
de apenas "orquestrar melhor" e passa a aprender, com evidencia, qual rota
funciona melhor. Isso e essencial para superar uso manual de Claude Code,
Codex CLI ou Gemini CLI, porque o usuario deixa de escolher ferramenta por
intuicao e passa a delegar essa escolha ao Atlas Decide com memoria operacional.

## Resumo

Auditoria rigorosa da Fase 0 do roadmap de evolucao do Atlas AI, definindo o que ja existe, o que falta implementar, o ganho esperado e o que nao deve virar subsistema paralelo.

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
