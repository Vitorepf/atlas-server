---
id: atlas-self-improvement-governance-ladder
type: engineering_knowledge
title: Atlas Self-Improvement Governance Ladder
status: active
category: self-construction
priority: 102
summary: Lei canonica dos 7 niveis de autoaprimoramento do Atlas, com Proposal Power Gate, before/after delta, invariant lock, regression sentinel, maturity score, trust ledger e strategy portfolio.
tags:
  - atlas
  - self-improvement
  - self-construction
  - self-programming
  - governance
  - before-after
capabilities:
  - self_improvement_governance_ladder
  - proposal_power_gate
  - before_after_delta_scorecard
  - invariant_lock
  - regression_sentinel
  - capability_maturity_score
  - human_trust_ledger
decisions:
  - O Atlas nao pode declarar autoaprimoramento por volume de codigo, numero de features ou testes verdes isolados.
  - Toda melhoria estrutural precisa de proposta forte, snapshot antes, implementacao governada, snapshot depois, delta positivo e gates sem hard fail.
  - O modelo canonico de autoaprimoramento tem 7 niveis: observacao, diagnostico, proposta, implementacao governada, verificacao/Rivals, promocao controlada e autoestrategia.
  - Proposal Power Gate, Invariant Lock, Regression Sentinel e Before/After Delta Scorecard sao obrigatorios antes de promover melhoria estrutural.
  - Autopromocao so e aceitavel para baixo risco com rollback, evidencia, maturidade suficiente e politica explicita.
maintenance:
  - Atualize antes de implementar auto-obras, auto-propostas, autopromocao, self-programming, strategy portfolio ou avaliacao before/after.
  - Mantenha este doc como autoridade dos niveis e gates; docs filhos podem detalhar schemas e runtime.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
  - docs/engineering-knowledge-base/atlas-rivals-one-shot-enterprise-evaluation-v1.md
  - docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md
  - docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md
  - docs/engineering-knowledge-base/self-construction/capability-maturity-ladder.md
  - docs/engineering-knowledge-base/self-construction/autonomous-implementation-loop.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-self-improvement-governance-ladder
graph_title: Atlas Self-Improvement Governance Ladder
graph_world: atlas
graph_layer: system
graph_kind: policy
graph_parent: atlas-ai-self-construction-os
graph_status: active
graph_source: repo
human_name: Atlas Self-Improvement Governance Ladder
canonical_name: Atlas Self-Improvement Governance Ladder
technical_name: atlas-self-improvement-governance-ladder
cartography_type: policy
canonical_source: docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md
allowed_changes:
  - Atualizar niveis, gates, schemas e criterios de promocao quando houver implementacao, audit ou evidencia nova.
forbidden_changes:
  - Permitir autoalteracao critica sem review/evidence.
  - Declarar melhoria sem before/after delta.
  - Tratar score sintetico, teste isolado ou proposta sem business rule como prova de avanco real.
  - Remover invariant lock, rollback, review humano ou Rivals de mudancas criticas.
depends_on:
  - atlas-ai-self-construction-os
  - atlas-ai-research-self-improvement-runtime
  - atlas-forge-continuum-os
flows_to:
  - atlas-code
  - atlas-forge-continuum-os
  - atlas-self-improvement-activation-cockpit-v1
  - atlas-self-improvement-closed-loop-level7-v1
  - self-construction
  - rivals-learning
unlocks:
  - governed_auto_improvement
  - measurable_self_programming
  - controlled_auto_promotion
governs:
  - self-improvement
  - self-programming
  - auto-obras
  - before-after-evaluation
evidence:
  - docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md
evidence_refs:
  - symbol: AtlasSelfImprovementProposalPacketService
  - command: atlas:self-improvement:proposal-gate
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - self-improvement
  - governance
  - before-after
ai_entrypoints:
  - Leia este doc antes de propor autoaprimoramento, auto-obra, autopromocao, self-programming ou mudanca que declare o Atlas melhor que antes.
ai_usage_notes:
  - Use a escada, gates e hard fails deste doc como contrato. Se faltar Proposal Packet, Before Snapshot ou Delta Scorecard, a melhoria nao pode ser promovida.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Sistema ficar maior sem ficar melhor.
  - Proposta fraca virar Obra automatica.
  - Teste verde mascarar regressao de negocio, governanca, UX ou risco.
  - Autonomia aumentar mais rapido que evidencia, rollback e confianca humana.
observability_signals:
  - proposal_power_gate_status
  - before_after_delta_score
  - invariant_lock_violations
  - regression_sentinel_findings
  - capability_maturity_delta
next_actions:
  - Implementar packets e comandos para Proposal Power Gate, Before/After Delta Scorecard, Invariant Lock e Regression Sentinel.
---
# Atlas Self-Improvement Governance Ladder

## Resumo

Este doc e a lei canonica para o Atlas melhorar a si mesmo sem se enganar. O
objetivo nao e produzir mais codigo, mais docs ou mais automacao; o objetivo e
provar que o Atlas novo e melhor que o Atlas anterior, com evidencia, rollback,
gates e limites de autonomia.

## Papel no Atlas

Este documento governa auto-Obras, self-programming, autopropostas,
autopromocao e qualquer claim de que uma mudanca tornou o Atlas mais poderoso.
Ele fica acima de implementacoes locais: se uma melhoria nao passa por proposta
forte, before/after, invariant lock e regression sentinel, ela nao pode ser
promovida como avanco canonico.

## Onde Se Encaixa

Fica abaixo do `atlas-ai-self-construction-os.md` e ao lado do
`atlas-ai-research-self-improvement-runtime.md`. O Forge executa a mudanca; este
doc decide se a proposta e forte, se a comparacao prova avanco e se a promocao e
permitida.

## Contratos

Contratos canonicos deste eixo:

- `atlas.self_improvement.proposal_packet.v1`;
- `atlas.self_improvement.proposal_power_gate.v1`;
- `atlas.self_improvement.before_after_delta_scorecard.v1`;
- `atlas.self_improvement.regression_sentinel.v1`;
- `atlas.self_improvement.invariant_lock.v1`;
- `atlas.self_improvement.capability_maturity_score.v1`;
- `atlas.self_improvement.human_trust_ledger.v1`.

## Fluxo

O Atlas deve evoluir por ciclos mensuraveis:

```text
Signal
-> Diagnosis
-> Proposal Packet
-> Proposal Power Gate
-> Before Snapshot
-> Forge Implementation
-> Invariant Lock
-> Regression Sentinel
-> After Snapshot
-> Delta Scorecard
-> Promotion Decision
-> Learning Memory
-> Strategy Portfolio
```

Qualquer ciclo que pule proposta, before/after, invariantes ou regressao pode
ser experimento, mas nao pode ser promocao canonica.

## North Star

Maximizar poder real e autonomia correta, nao velocidade aparente. Toda melhoria
deve ser mensuravel, reversivel, auditavel e governada por docs canonicas.

## Sete Niveis Canonicos

| Nivel | Nome | Pergunta que responde | Pode mudar codigo? |
| --- | --- | --- | --- |
| 0 | Auto-Observacao | O que esta acontecendo? | Nao |
| 1 | Auto-Diagnostico | Por que isso importa? | Nao |
| 2 | Auto-Proposta | Qual melhoria merece virar Obra? | Nao |
| 3 | Auto-Implementacao Governada | Consigo construir com seguranca? | Sim, em worktree/sandbox |
| 4 | Auto-Verificacao / Rivals | Melhorou de verdade? | Nao promove |
| 5 | Auto-Promocao Controlada | Pode incorporar ao Atlas principal? | Sim, se politica permitir |
| 6 | Auto-Estrategia / Self-Evolution | Para onde o Atlas deve evoluir? | Cria portfolio, nao bypasse gates |

Os niveis sao cumulativos. Nivel 5 nao existe sem 0-4 completos. Nivel 6 nao e
permissao para alterar regras sagradas; ele prioriza o portfolio de evolucao.

## Proposal Packet

Toda auto-proposta estrutural deve emitir `atlas.self_improvement.proposal_packet.v1`.

Campos obrigatorios:

- `proposal_id`, `title`, `problem_statement`;
- `business_rule` e `target_capability`;
- `why_now` e `expected_power_gain`;
- `before_snapshot_plan`;
- `success_metrics`;
- `acceptance_gates`;
- `canonical_docs`;
- `allowed_paths` e `forbidden_paths`;
- `risk_classification`;
- `provider_topology_recommendation`;
- `test_strategy`;
- `rollback_strategy`;
- `rivals_evaluation_plan`;
- `human_review_required`;
- `autopromotion_allowed`.

Proposta que nao declara regra de negocio, escopo, metrica, rollback e evidencia
nao pode criar Obra automatica.

## Proposal Power Gate

`atlas.self_improvement.proposal_power_gate.v1` decide se a proposta e forte.

Hard fails:

- sem business rule;
- sem canonical docs;
- sem before snapshot;
- sem metricas de sucesso;
- sem forbidden changes;
- sem rollback strategy;
- sem test strategy;
- mistura hipotese com claim;
- tenta autopromover mudanca critica;
- ignora Rivals ou before/after quando a proposta declara melhoria.

Saidas permitidas: `approved`, `needs_revision`, `rejected`, `human_review_required`.

## Before / After Delta Scorecard

Toda melhoria precisa comparar Atlas antes e Atlas depois com o mesmo conjunto de
sinais. Schema: `atlas.self_improvement.before_after_delta_scorecard.v1`.

Metricas minimas:

- functional correctness;
- business rule alignment;
- canonical documentation adherence;
- test and risk coverage;
- enterprise architecture quality;
- governance integrity;
- operator experience;
- automation level;
- human intervention load;
- evidence and observability;
- runtime safety;
- provider cost/token impact;
- regressions and new blockers.

Promocao exige delta positivo ou justificativa humana explicita. Tempo e custo
sao secundarios; qualidade, solidez e completude vencem velocidade.

## Regression Sentinel

`atlas.self_improvement.regression_sentinel.v1` procura danos que testes comuns
nao capturam:

- API publica mudou sem doc;
- fail-closed virou fail-open;
- provider externo passou a chamar sem aprovacao;
- completion claim foi promovido sem review;
- fallback ficou silencioso;
- Rivals foi desbloqueado por score sintetico;
- UI ficou mais confusa ou exigiu mais passos sem ganho;
- complexidade aumentou sem maturity delta;
- docs e runtime divergiram.

Qualquer finding severo bloqueia promocao.

## Invariant Lock

`atlas.self_improvement.invariant_lock.v1` protege regras sagradas:

- Obra nunca e criada silenciosamente;
- `static_policy` nunca executa runtime;
- provider real nunca chama sem aprovacao e budget gate;
- fallback nunca e silencioso;
- completion nunca fecha sem evidence/review quando exigido;
- Rivals externo nunca e substituido por score sintetico;
- docs canonicas governam mudanca estrutural;
- rollback e checkpoint sao obrigatorios para mudanca critica.

Invariant violation e hard fail.

## Capability Maturity Score

`atlas.self_improvement.capability_maturity_score.v1` mede avancos reais:

```text
0 doc only
1 service exists
2 CLI exists
3 API exists
4 tests exist
5 UI exists
6 state projection exists
7 audit certification exists
8 replay/evidence exists
9 Rivals/before-after exists
10 production-ready
```

Toda proposta deve declarar maturidade antes, maturidade esperada depois e
maturidade realmente obtida. Sem maturity delta, a melhoria precisa de outra
prova forte de valor.

## Blast Radius Control

Toda Obra de autoaprimoramento deve declarar:

- `allowed_paths`;
- `forbidden_paths`;
- `max_files_changed`;
- `risk_level`;
- `requires_migration_review`;
- `requires_security_review`;
- `requires_provider_cost_approval`;
- `rollback_required`.

Se a implementacao passar do raio aprovado, deve bloquear ou pedir humano.

## Human Trust Ledger

`atlas.self_improvement.human_trust_ledger.v1` mede confianca real:

- propostas aprovadas, rejeitadas e retrabalhadas;
- autopromocoes aceitas ou revertidas;
- motivos de rejeicao humana;
- areas em que o Atlas exagerou autonomia;
- areas em que o Atlas foi conservador demais.

O objetivo e reduzir intervencao humana sem reduzir confianca.

## Learning Memory

Cada ciclo deve alimentar memoria de aprendizagem:

- padroes de proposta boa/ruim;
- falhas recorrentes;
- providers melhores por papel;
- testes que mais capturaram regressao;
- tipos de mudanca que exigem humano;
- rollback e repair effectiveness.

Learning Memory nao vira regra operacional sem source/evidence gate.

## Strategy Portfolio

Nivel 6 governa portfolio, nao execucao sem freio. Buckets canonicos:

- quick wins;
- core runtime;
- enterprise reliability;
- provider intelligence;
- UX/operator experience;
- Rivals/evaluation;
- self-construction;
- security/governance.

O Atlas deve balancear correcoes pequenas com avancos estruturais. Um sistema
que so corrige lint nao esta se autoaprimorando no sentido forte.

## Autopromotion Policy

Autopromocao pode ser considerada para:

- typo ou indice de doc;
- teste isolado sem alterar runtime;
- refactor pequeno com cobertura forte;
- melhoria UI isolada com screenshot/evidence;
- docs pequenas com docs-health e arquitetura verdes.

Autopromocao e proibida para:

- provider invocation, auth, billing ou tokens;
- migrations criticas;
- Policy/Profile/Decision Receipt/Ledger;
- completion gate;
- Rivals claim;
- fallback policy;
- security/privacy;
- deletar dados, docs canonicas ou memoria promovida.

## Regras para IA

- Nao declare melhoria sem before/after.
- Nao crie Obra automatica a partir de proposta fraca.
- Nao aumente autonomia sem rollback, evidence e trust ledger.
- Nao confunda teste verde com ganho de produto.
- Nao promova mudanca critica sem humano.
- Nao use velocidade como substituto de qualidade one-shot enterprise.
- Nao esconda regressao por estar fora do escopo do teste filtrado.

## Escopo de Implementacao

Permitido:

- criar proposal packets;
- rodar before/after local;
- pontuar maturidade;
- bloquear propostas fracas;
- gerar Obras governadas;
- executar Forge em worktree/sandbox;
- recomendar promocao, rollback ou review humano.

Proibido:

- autopromover mudanca critica;
- chamar provider pago sem approval;
- apagar invariantes para passar gate;
- declarar melhoria sem delta scorecard;
- substituir Rivals externo por score sintetico.

## Dependencias

Depende de:

- `atlas-ai-self-construction-os.md`;
- `atlas-ai-research-self-improvement-runtime.md`;
- `atlas-forge-continuum-os.md`;
- `atlas-rivals-one-shot-enterprise-evaluation-v1.md`;
- `self-construction/self-programming-safety-contract.md`.

## Evidencias

Evidencias aceitas:

- Proposal Packet;
- Proposal Power Gate report;
- Before Snapshot;
- After Snapshot;
- Delta Scorecard;
- Invariant Lock report;
- Regression Sentinel report;
- Capability Maturity Score;
- test/build/lint/docs-health/architecture-validate;
- Evidence Ledger;
- review humano;
- rollback/checkpoint.

## Exemplos

Exemplo aprovado: detectar que Provider Invocation esta em maturidade 4,
propor Obra com business rule, before snapshot, acceptance gates, allowed paths,
rollback e target maturity 8; implementar no Forge; provar delta positivo e
sem regression sentinel severo.

Exemplo bloqueado: "melhorar o Atlas Code" sem escopo, sem regra de negocio,
sem docs canonicas, sem before snapshot e tentando autopromover mudanca de
provider. Proposal Power Gate deve retornar `rejected` ou `needs_revision`.

## Riscos

- Autoaprimoramento virar crescimento sem qualidade.
- Propostas fracas consumirem Forge.
- Autopromocao quebrar regras sagradas.
- Comparacao before/after medir o que e facil, nao o que importa.
- Strategy Portfolio priorizar quick wins e ignorar gargalos profundos.

## Proximas Acoes

Implementacao v1 entregue: 8 services (`AtlasSelfImprovementProposalPacketService`, `…ProposalPowerGateService`, `…DeltaScorecardService`, `…InvariantLockService`, `…RegressionSentinelService`, `…CapabilityMaturityScoreService`, `…HumanTrustLedgerService`, `…StrategyPortfolioService`), 6 CLIs (`atlas:self-improvement:proposal-gate|before-after|invariant-lock|regression-sentinel|maturity-score|trust-ledger`), `AtlasCodeSelfImprovementGovernanceController` com 8 endpoints, state projection `self_improvement_governance`, audit block `atlas_self_improvement_governance_certification` (schema `atlas.self_improvement.governance_certification.v1`, 27 invariantes), painel desktop `AtlasSelfImprovementGovernancePanel`, e suite `AtlasSelfImprovementGovernanceTest`. Read-model + diagnostic + governance — nunca chama provider externo, nunca promove Forge, nunca libera `external_rivals_certification`.

Proximos passos governados:

1. Conectar Strategy Portfolio a uma colecao real de Proposal Packets persistida (hoje a ferramenta aceita lista in-memory).
2. Plugar Decision Receipt do Atlas Decide nos `provider_topology_recommendation` quando o dispatcher real (eixo separado) emitir.
3. Habilitar workflow human-review com signoff persistido apos `power_gate.outcome=human_review_required`.

Para fechar o ciclo proposta → Obra real ver `atlas-self-improvement-forge-activation-v1.md` (closed loop com baseline + approval receipt + Intake completo, sem auto Fast Path).
