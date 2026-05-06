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
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-governed-backlog.md
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
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
| ABC-1 | Provider/Identity Fragment curto com os 4 principios Atlas | future |
| ABC-2 | Programming Domain injeta `agent_behavior_contract` em dev/forge/fix/review | future |
| ABC-3 | Quality Gate cria findings para diff lateral, overengineering e falta de verificacao | future |
| ABC-4 | Review Mode mostra checklist de comportamento no output/evidence | future |
| ABC-5 | Architectural test garante que fluxos Programming carregam o contrato | future |

## Findings Esperados No Futuro

Quality Gates devem conseguir emitir findings como:

1. `agent.assumption_unstated`: decisao relevante tomada sem declarar suposicao;
2. `agent.overengineered_change`: abstracao criada sem requisito ou uso concreto;
3. `agent.unsurgical_diff`: arquivos/linhas fora do escopo alterados;
4. `agent.verification_missing`: sem teste, comando, gate ou justificativa;
5. `agent.non_goal_violation`: agente mexeu em item declarado como nao-objetivo.

Esses findings devem ir para Evidence Ledger e Review Mode, nao apenas para texto.

## Definition Of Done

Uma IA futura deve considerar este item implementado somente quando:

1. o contrato for injetado nos prompts/identity fragments de Programming;
2. `atlas dev`, `atlas forge`, `atlas fix` e `programming.review` carregarem o
   mesmo contrato sem duplicacao local;
3. pelo menos um teste provar que o contrato aparece no payload/contexto;
4. pelo menos um gate/review finding detectar falta de verificacao ou diff
   lateral;
5. docs e Code Intelligence forem sincronizados.

Enquanto isso nao existir, este documento permanece como backlog ativo e deve ser
notado por Self-Improvement/Curator quando revisar lacunas de qualidade de
programacao.
