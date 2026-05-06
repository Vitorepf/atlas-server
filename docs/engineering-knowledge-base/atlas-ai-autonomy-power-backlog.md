---
id: atlas-ai-autonomy-power-backlog
type: engineering_knowledge
title: Atlas AI Autonomy And Power Backlog
status: active
category: roadmap-governance
priority: 79
summary: Backlog governado de longo prazo para autonomia proativa, tool synthesis, dynamic compute market, real-world feedback loops, cross-domain heuristics e contexto multimodal continuo, sem criar superpoderes soltos fora do Kernel.
tags:
  - atlas-ai
  - autonomy
  - power-backlog
  - curator
  - provider-performance
  - tool-runtime
capabilities:
  - autonomy_plane
  - dynamic_compute_market
  - tool_synthesis
  - zero_click_shadow_mode
  - real_world_feedback_loop
  - multimodal_context
  - cross_domain_learning
decisions:
  - Este backlog e governado, nao compromisso de implementacao imediata.
  - Toda autonomia nova deve passar por Operation Envelope, Policy/Profile, Decision Receipt, Evidence Ledger, Quality Gates e Inbox approval quando houver risco real.
  - Detectar, planejar e testar em sandbox pode ser proativo; mutar producao, dinheiro, seguranca, calendario, privacidade ou infraestrutura critica exige approval explicito.
  - AP-99 / Provider Performance e a base antes de Dynamic Compute Market e arbitragem de IA.
  - Tool Synthesis deve comecar como proposta revisavel, nao instalacao automatica.
  - Multimodal continuo exige privacy review, redaction, provider-safe gates e opt-in por fonte.
maintenance:
  - Manter este doc abaixo de 220 linhas para performance de retrieval.
  - Promover itens para APs, ADRs ou Domain Specs apenas quando houver owner, acceptance criteria e safety boundary.
  - Atualizar este doc quando um item entrar em implementacao ativa ou for rejeitado.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-evolution-roadmap.md
  - docs/engineering-knowledge-base/atlas-ai-evolution-phase-0-audit.md
  - docs/engineering-knowledge-base/atlas-ai-governed-backlog.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/domains/self-improvement.md
---

# Atlas AI Autonomy And Power Backlog

Este documento preserva a fila de poder do Atlas AI sem transformar ideias
grandes em implementacoes soltas.

A regra central:

```text
Detectar sozinho: permitido em shadow mode.
Planejar sozinho: permitido com evidence.
Testar em sandbox: permitido com gates.
Alterar producao, dinheiro, privacidade ou sistema critico: somente com Decision Receipt + approval.
```

## Autonomy Plane

Os itens deste backlog pertencem a um plano futuro:

```text
Atlas Autonomy Plane
-> Shadow Observers
-> Opportunity Detector
-> Proposal Generator
-> Sandbox Executor
-> Approval Inbox
-> Evidence Feedback
-> Learning Promotion
```

Esse plano nao substitui o Kernel. Ele roda em cima de:

```text
Operation Envelope
-> Intent/Routing
-> Policy/Profile
-> Atlas Decide
-> Decision Receipt
-> Runtime/Executor
-> Quality Gates
-> Evidence Ledger
-> Learning/Curator
```

## Backlog Ordenado

| Ordem | Item | Status | Por que importa | Gate antes de implementar |
|---|---|---|---|---|
| 1 | Dynamic Compute Market | candidate_high | Escolhe provider/modelo por qualidade, custo, latencia e outcome real | AP-99 populado com dados confiaveis |
| 2 | Tool Synthesis | candidate_high | Atlas detecta falta de tool e propoe ferramenta nova testada | Super Tool Runtime registry + sandbox + security gate |
| 3 | Zero-Click Shadow Mode | candidate_high | Atlas observa anomalias e cria propostas antes do usuario pedir | Observers read-only + Inbox approval + no mutation |
| 4 | Real-World Feedback Loop | candidate_medium_high | Atlas implanta, mede e melhora campanhas/codigo com dados reais | Domain gates, rollback, budget e approval |
| 5 | Contexto Multimodal Continuo | candidate_medium | Atlas entende tela/audio/reunioes com contexto rico | Opt-in, privacy, redaction, provider-safe storage |
| 6 | Cross-Domain Heuristic Transfer | candidate_long | Atlas reaplica heuristicas entre dominios diferentes | Memory quality, taxonomy e evidence forte por dominio |

## 1. Dynamic Compute Market

Atlas Decide vira broker de computacao.

- escolher Claude, Codex, Gemini, local model ou outros providers por tarefa;
- otimizar qualidade por custo, latencia, risco e taxa de retrabalho;
- usar dados empiricos, nao preferencia fixa.

- AP-99 Provider Usage / Performance Contract;
- ProviderPerformanceProjection;
- Decision Receipt com provider/model auditavel;
- budgets por Policy/Profile.

- read-only recommendation no `Atlas Decide`;
- sem trocar provider quando usuario passou modelo manual;
- report com motivo: `best_allowed`, `best_available`, `manual_override`.

## 2. Tool Synthesis

Atlas detecta que falta uma ferramenta e cria uma proposta para adiciona-la.

- Curator identifica capability gap;
- gera spec da tool, risco, inputs, outputs, sandbox e testes;
- cria proposta no Inbox com diff/script;
- so promove ao Super Tool Runtime depois de review.

- proposal-only;
- sem instalar automaticamente;
- gitleaks/semgrep/static checks obrigatorios;
- Evidence Ledger registra `TOOL_SYNTHESIS_PROPOSED`.

## 3. Zero-Click Shadow Mode

Atlas observa sinais operacionais sem esperar prompt.

- detectar anomalias em logs, schedules, ledger, health, custos e falhas;
- abrir Operation Envelope autonomo;
- gerar proposta com plano, patch ou runbook;
- pedir approval no Inbox.

- observers read-only;
- shadow mode apenas;
- nenhuma chamada externa mutavel;
- proposta revisavel com confidence, evidence refs e rollback plan.

## 4. Real-World Feedback Loop

Atlas fecha ciclo entre criacao, deploy, medicao e melhoria.

Dominios-alvo:

- Marketing: landing pages, copy, criativos, tracker, ROI;
- Programming: deploy canary, error rate, rollback, refactor baseado em prod;
- Finance: analysis-only e compliance-first, sem execucao de mercado automatica.

- experimento pequeno com budget fixo;
- dashboards/read models primeiro;
- desligar perdas ou aplicar mudancas somente com approval.

## 5. Contexto Multimodal Continuo

Atlas Input evolui de prompt voluntario para contexto sensorial opt-in.

Fontes candidatas:

- screenshots;
- audio de reuniao;
- clipboard;
- documentos recentes;
- tela de app local.

Regras:

- opt-in por fonte;
- redaction antes de provider;
- retention curta por default;
- AtlasVault pode receber resumo humano, nao raw dump sensivel.

## 6. Cross-Domain Heuristic Transfer

Atlas aprende um padrao em um dominio e sugere aplicacao em outro.

Exemplo:

- Programming detecta "ciclos abertos" em leaks/queues;
- Personal Development recebe analogia como plano non-clinical;
- Marketing usa heuristica de testing/rollback de Programming para campanhas.

- proposal-only;
- explicitar dominio origem, dominio destino e limites da analogia;
- nenhum conselho medico, financeiro ou psicologico como verdade clinica.

## Promotion Gate

Um item so sai deste backlog quando tiver:

- AP ou ADR dedicada;
- owner;
- safety boundary;
- expected Evidence Ledger events;
- tests/scanner;
- rollback;
- doc canonica atualizada.

## Ordem Recomendada Atual

1. AP-99 e Dynamic Compute Market.
2. Tool Synthesis proposal-only.
3. Zero-Click Shadow Observers read-only.
4. Real-World Feedback Loop por dominio.
5. Contexto multimodal continuo com privacy gates.
6. Cross-Domain Heuristic Transfer com evidence suficiente.
