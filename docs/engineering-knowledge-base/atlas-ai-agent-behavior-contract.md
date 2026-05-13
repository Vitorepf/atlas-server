---
id: atlas-ai-agent-behavior-contract
type: engineering_knowledge
title: Atlas AI Agent Behavior Contract
status: active
category: architecture
priority: 95
summary: Contrato governado para transformar boas praticas tipo Karpathy em comportamento verificavel de agentes, providers e fluxos de programacao do Atlas.
tags:
  - atlas-ai
  - agents
  - programming
  - quality-gates
  - provider-governance
capabilities:
  - agent_behavior_governance
  - surgical_change_discipline
  - verifiable_goal_loop
  - assumption_management
decisions:
  - O valor do andrej-karpathy-skills e comportamento operacional, nao nova arquitetura-mae.
  - Atlas deve absorver esses principios como contrato verificavel, nao como prompt solto.
  - Toda execucao de programacao deve declarar escopo, suposicoes, criterios de sucesso, verificacao e limites do que nao vai mexer.
  - Quality Gates devem futuramente detectar overengineering, mudanca lateral e ausencia de verificacao como findings.
maintenance:
  - Manter abaixo de 240 linhas.
  - Atualizar antes de alterar provider prompts, Programming Domain, Review Mode, Quality Gates ou worker prompts.
  - Nao copiar CLAUDE.md externo literalmente; adaptar para contratos Atlas, evidence e gates.
related_paths:
  - app/Services/Ai/Kernel/Provider/AgentBehaviorContract.php
  - app/Services/Ai/Kernel/Behavior/AgentBehaviorQualityGate.php
  - app/Services/Ai/AiQualityActionService.php
  - app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php
  - app/Services/Ai/Kernel/Provider/AtlasProviderIdentityProjector.php
  - app/Services/Ai/ValueObjects/AiExecutionPlan.php
  - app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php
  - app/Services/Ai/Programming/ProgrammingExecutionRequest.php
  - app/Services/Engineering/EngineeringHarnessExecutionService.php
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-governed-backlog.md
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - docs/ap/AP-148-agent-behavior-identity-fragment.md
  - docs/ap/AP-149-agent-behavior-execution-plan.md
  - docs/ap/AP-150-agent-behavior-quality-gate.md
  - docs/ap/AP-151-agent-behavior-review-action-surface.md
  - docs/ap/AP-152-programming-plan-agent-behavior-contract.md
  - docs/ap/AP-153-programming-harness-agent-behavior-contract.md
  - docs/ap/AP-154-agent-behavior-evidence-ledger.md
  - docs/ap/AP-155-agent-behavior-replay-read-model.md
  - docs/ap/AP-156-agent-behavior-mcp-report.md
  - docs/ap/AP-157-agent-behavior-self-improvement-review.md
  - docs/ap/AP-158-agent-behavior-direct-surfaces.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-agent-behavior-contract

graph_title: Atlas AI Agent Behavior Contract

graph_world: atlas

graph_layer: system

graph_kind: contract

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: architecture

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md

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
  - docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - contract
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
# Atlas AI Agent Behavior Contract

Este documento registra o que vale absorver de
`forrestchang/andrej-karpathy-skills` e como implementar sem criar prompt solto,
duplicacao ou nova arquitetura paralela.

## Veredito

Vale implementar como camada de governanca comportamental para agentes e
providers. Nao vale tratar como produto, dominio novo ou substituto do Kernel.

O ganho e reduzir quatro falhas recorrentes de IA programando:

1. assumir sem verificar;
2. complicar antes da hora;
3. mexer em codigo lateral;
4. trabalhar sem criterio verificavel de sucesso.

## Principios Atlas

| Principio | Regra Atlas | Onde aplicar |
|---|---|---|
| Assumption Management | Declarar suposicoes, ambiguidades e tradeoffs antes de editar quando houver risco real. | Decide, Programming, Review |
| Simplicity Bias | Resolver com o menor desenho que fecha o objetivo atual; abstracao so com necessidade concreta. | Programming, Refactor |
| Surgical Diff Discipline | Toda linha alterada deve estar ligada ao pedido, AP, bug ou verificacao. | Programming, Review, Gates |
| Verifiable Goal Loop | Pedido vira objetivo, criterio de sucesso e verificacao executada ou justificativa. | Executor, Gates, Evidence |

## Contrato Para Tarefas De Programacao

Toda execucao `atlas dev`, `atlas forge`, `atlas fix`, `programming.repair`,
`programming.review` ou worker equivalente deve produzir, no minimo:

```text
scope: o que sera alterado
assumptions: o que foi inferido e nivel de confianca
non_goals: o que nao sera alterado
success_criteria: como saber que terminou
verification_plan: testes/comandos/checagens ou motivo para nao rodar
changed_surface: arquivos/modulos tocados
```

Para bugfix:

```text
reproduce -> fix -> verify -> summarize residual risk
```

Para refactor:

```text
baseline verify -> small refactor -> verify behavior unchanged
```

Para feature:

```text
contract/test expectation -> implementation -> verification -> evidence
```

## O Que Nao Fazer

1. nao adicionar framework, strategy pattern, provider abstraction ou config nova
   se a tarefa atual nao exigir;
2. nao reformatar arquivos por gosto;
3. nao alterar comments, nomes ou fluxo lateral para "melhorar";
4. nao apagar codigo morto preexistente sem pedido;
5. nao declarar sucesso sem teste, gate ou justificativa clara;
6. nao usar esses principios para travar tarefas triviais obvias.

## Implementacao Futura

Este contrato deve virar AP pequeno, nao frente grande.

| Fase | Entrega | Status |
|---|---|---|
| ABC-0 | Doc canonica e backlog governado | active |
| ABC-1 | Provider/Identity Fragment curto com os 4 principios Atlas | implemented |
| ABC-2 | Execution Plan injeta `agent_behavior_contract` em prompts de tarefa | implemented-initial |
| ABC-3 | Quality Gate cria findings para diff lateral e falta de verificacao | implemented-initial |
| ABC-4 | Review actions carregam findings comportamentais para UI/Inbox/CLI | implemented-initial |
| ABC-5 | Fluxos Programming carregam o contrato no plano canonico | implemented |
| ABC-6 | Forge/Harness recebe contrato comportamental via ProgrammingExecutionRequest | implemented |
| ABC-7 | Findings comportamentais viram evento `GATE_EVALUATED` no Evidence Ledger | implemented |
| ABC-8 | Replay read model resume recorrencia de findings comportamentais | implemented |
| ABC-9 | Open Brain MCP expoe `atlas_agent_behavior_report` read-only | implemented |
| ABC-10 | Self-Improvement consome replay comportamental e abre proposal revisavel | implemented |
| ABC-11 | CLI/API expoem Agent Behavior Report direto para operador/app | implemented |
| ABC-12 | Flow dedicado `self_improvement.agent_behavior_review` roda Curator focado em comportamento de agentes | implemented |
| ABC-13 | CLI do Curator aceita filtros `--agent-slug`, `--finding-code`, `--contract-id` e `--agent-status` | implemented |
| ABC-14 | `agent_behavior_review` entra no default recorrente do Self-Improvement | implemented |
| ABC-15 | Propostas de comportamento de agente entram no Inbox com governanca explicita | implemented |

## Implementado Agora

`AgentBehaviorContract` centraliza os quatro principios em texto curto,
hashavel e versionado (`atlas-ai.agent-behavior.v1`). `AtlasProviderIdentityProjector`
anexa esse fragmento dentro do `IdentityFragment` de todo provider driver.

Isso significa que Claude, Codex, Gemini e Council recebem o mesmo contrato
comportamental no ponto de maior autoridade: a identidade do provider preparada
pelo Kernel. A surface nao copia prompt local; ela herda o contrato pelo driver.

Garantias atuais:

1. o texto inclui `Assumption Management`, `Simplicity Bias`,
   `Surgical Diff Discipline` e `Verifiable Goal Loop`;
2. o hash do contrato viaja em `identity_fragment.metadata`;
3. testes de provider provam que todo driver injeta o contrato no payload;
4. AP-148 documenta e o scanner arquitetural protege a implementacao.

AP-149 estende o mesmo contrato ao `AiExecutionPlan`: o plano estruturado passa
a carregar `agent_behavior_contract`, e `toPromptSection()` renderiza uma secao
curta com contract id, hash e principios. Isso cobre prompts que passam pelo
pipeline geral do Atlas e reduz a chance de cada surface inventar instrucoes
comportamentais locais.

AP-150 cria `AgentBehaviorQualityGate`, que transforma duas violacoes em findings
estruturados `agent.*`: `agent.verification_missing` e `agent.unsurgical_diff`.
`AiQualityEvaluator` ja consome o gate para preservar o flag legado
`verification_missing` e gravar os findings em metadata de evidencia. O contrato
permanece proposal/review-oriented: ele detecta e documenta o problema; bloquear
execucao em gates formais fica para a proxima fase.

AP-151 leva esses findings para `AiQualityActionService`: acoes humanas como
`request_verification` e `operator_review` passam a carregar
`agent_behavior_findings` no payload. Assim UI, CLI e Inbox podem mostrar o
checklist comportamental sem reparsear texto livre nem duplicar regra local.

AP-152 injeta `agent_behavior_contract` diretamente no
`AtlasProgrammingOrchestrator::sessionPlan()`. Isso faz `atlas dev`, `atlas
forge`, `atlas fix`, dispatch e harness herdarem o mesmo contrato comportamental
como dado estruturado do plano Programming, sem depender apenas do prompt geral.

AP-153 fecha a ponte para programacao pesada: `ProgrammingExecutionRequest`
publica `agentBehaviorContract()` e o `EngineeringHarnessExecutionService`
projeta o contrato nas opcoes efetivas, no contrato da task, no metadata do
orchestrator e no metadata de repair. Assim Forge/Harness tambem recebem o
mesmo contrato sem regra paralela.

AP-154 torna o contrato auditavel no ledger: quando `AiQualityEvaluator` encontra
findings `agent.*`, `AtlasEvidenceLedger::recordAgentBehaviorGateEvaluation()`
emite `GATE_EVALUATED` com `gate_id = atlas.agent_behavior`, contrato, hash,
score, flags, trace e evidencia tecnica. Curator, replay e read models passam a
ter dado canonico, nao apenas metadata local de uma evaluation.

AP-155 adiciona `AtlasLedgerReplayService::agentBehaviorReportForWindow()` para
resumir esses eventos por finding, provider, agente e score. Findings recorrentes
geram `review_signal` com `open_reviewable_agent_behavior_quality_proposal`,
permitindo Curator priorizar melhoria comportamental com evidencia.

AP-156 expoe esse read model no Open Brain MCP via
`atlas_agent_behavior_report`. Assim qualquer IA autorizada consegue consultar
recorrencia de falhas comportamentais por janela e filtro sem ler tabela crua.

AP-157 conecta esse read model ao Curator: `agentBehaviorReplayFindings()` em
`AtlasSelfImprovementRuntime` consome `agentBehaviorReportForWindow()` nos fluxos
de auditoria e performance, cria finding
`atlas.self_improvement.agent_behavior_replay.v1` e preserva o
`open_reviewable_agent_behavior_quality_proposal` como proposta revisavel, sem
autoalterar comportamento critico.

AP-158 expoe a mesma leitura por CLI e API: `atlas:ai:agent-behavior-report`
e `GET /ai/agent-behavior/report`. O catalogo de operacoes da arquitetura mae
inclui `php artisan atlas:ai:agent-behavior-report --hours=24 --json`, permitindo App,
operador e novas IAs consultarem recorrencia comportamental sem depender apenas
do MCP.

AP-159 promove a leitura comportamental para um flow dedicado de Curator:
`self_improvement.agent_behavior_review`, descoberto pelo catalogo como
`php artisan atlas:ai:self-improve --flow=agent_behavior_review --hours=168 --json`. Esse
flow e proposal-only, preserva filtros de provider/model/finding code e nao
altera prompts, providers, policies ou gates sem review humano.

AP-160 fecha a ergonomia operacional do flow dedicado: `atlas:ai:self-improve`
aceita `--agent-slug`, `--finding-code`, `--contract-id` e `--agent-status`.
Esses filtros viram `agent_slug`, `finding_code`, `contract_id` e `status` no
plano e no runtime, permitindo auditoria focada sem criar surface paralela.

AP-161 coloca `agent_behavior_review` no default recorrente do
Self-Improvement. O scheduler canonico passa a reportar 5 comandos recorrentes,
com `daily=4` e `weekly=1`, garantindo que comportamento de agentes seja
auditado automaticamente pelo Curator e protegido pelo scanner
`ap161_agent_behavior_recurring_schedule`.

AP-162 fecha o caminho revisavel: quando `agent_behavior_review` roda com
`emit=true`, a proposta `atlas.self_improvement.agent_behavior_replay.v1`
preserva `available_actions`, policy `auto_apply_behavior_change=false`,
payload dedicado `atlas.self_improvement.agent_behavior_replay.proposal_payload.v1`
e link entre `LEARNING_PROPOSED` e Inbox. O Curator pode propor revisao do
contrato comportamental, mas nao autoaltera prompt, provider, policy ou gate.

## Findings Esperados No Futuro

Quality Gates devem conseguir emitir findings como:

1. `agent.assumption_unstated`: decisao relevante tomada sem declarar suposicao;
2. `agent.overengineered_change`: abstracao criada sem requisito ou uso concreto;
3. `agent.unsurgical_diff`: arquivos/linhas fora do escopo alterados;
4. `agent.verification_missing`: sem teste, comando, gate ou justificativa;
5. `agent.non_goal_violation`: agente mexeu em item declarado como nao-objetivo.

Status atual: `agent.verification_missing` e `agent.unsurgical_diff` ja existem
como findings iniciais no AP-150; os demais continuam backlog.

Esses findings devem continuar indo para Evidence Ledger e Review Mode, nao
apenas para texto.

## Definition Of Done

Uma IA futura deve considerar este item implementado somente quando:

1. o contrato for injetado nos prompts/identity fragments de Programming;
2. `atlas dev`, `atlas forge`, `atlas fix` e `programming.review` carregarem o
   mesmo contrato sem duplicacao local;
3. pelo menos um teste provar que o contrato aparece no payload/contexto;
4. pelo menos um gate/review finding detectar falta de verificacao ou diff
   lateral;
5. uma action de review carregar os findings comportamentais para surface humana;
6. um evento `GATE_EVALUATED` registrar os findings comportamentais no Evidence
   Ledger;
7. um read model resumir recorrencia de findings comportamentais;
8. o Open Brain MCP expuser consulta read-only do comportamento dos agentes;
9. docs e Code Intelligence forem sincronizados.

Enquanto isso nao existir, este documento permanece como backlog ativo e deve ser
notado por Self-Improvement/Curator quando revisar lacunas de qualidade de
programacao.

## Resumo

Contrato governado para transformar boas praticas tipo Karpathy em comportamento verificavel de agentes, providers e fluxos de programacao do Atlas.

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
