---
id: atlas-aemor-judgment-learning-guard
type: engineering_knowledge
title: Atlas AEMOR Judgment & Learning Guard
status: active
category: intelligence-runtime
priority: 97
summary: Camada de julgamento operacional acima da AEMOR Intelligence Layer. Ela decide o que um outcome realmente prova, bloqueia aprendizado falso, mede ROI de contexto, detecta repeticao de falhas e gera propostas de memoria, estrategia e policy com evidencia.
implementation_state: implemented_local_runtime
blocker: none_for_local_runtime; future hardening may improve attribution depth with more real outcome data.
tags:
  - atlas-ai
  - aemor
  - judgment
  - learning-guard
  - anti-false-learning
  - outcome-causality
  - atlas-dev
  - atlas-forge
capabilities:
  - outcome_causality_ranker
  - anti_false_learning_gate
  - repeated_failure_suppression
  - context_roi_scoring
  - execution_strategy_memory
  - patch_quality_fingerprint
  - memory_conflict_resolver
  - human_correction_compression
  - pre_execution_risk_prediction
  - outcome_to_policy_proposal
decisions:
  - Esta camada julga outcomes; ela nao substitui AEMOR, APCR, ACIE, Evidence Ledger ou Compounding.
  - Nenhum aprendizado vira memoria se houver explicacao alternativa forte.
  - Falha repetida deve subir severidade e bloquear novo ciclo sem mitigacao.
  - Correcao humana e signal de alta prioridade, mas ainda exige compressao, escopo e evidence.
  - Propostas de policy sao propostas; nao alteram router, provider topology ou gates automaticamente.
maintenance:
  - Atualizar quando AEMOR, APCR, ACIE, Atlas Dev, Forge, Evidence ou Compounding mudarem contratos.
  - Manter todos os julgamentos replayable por receipt/hash/evidence refs.
  - Evitar criar score opaco sem breakdown auditavel.
related_paths:
  - docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
  - docs/engineering-knowledge-base/atlas-persistent-context-runtime.md
  - docs/engineering-knowledge-base/atlas-context-intelligence-engine.md
  - docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-aemor-judgment-learning-guard
graph_title: Atlas AEMOR Judgment & Learning Guard
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-execution-memory-outcome-runtime
graph_status: active
graph_source: repo
human_name: Atlas AEMOR Judgment & Learning Guard
canonical_name: Atlas AEMOR Judgment & Learning Guard
technical_name: atlas-aemor-judgment-learning-guard
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-aemor-judgment-learning-guard.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-aemor-judgment-learning-guard.md
allowed_changes:
  - Implementar services, models, commands e tests de julgamento em AEMOR.
  - Adicionar consumidores em APCR, Dev, Forge e Control Plane.
  - Promover novos schemas apenas quando houver codigo e testes.
forbidden_changes:
  - Promover memoria duravel sem evidence refs, attribution e anti-false-learning gate.
  - Declarar causa unica quando houver causas concorrentes plausiveis.
  - Usar score sem breakdown auditavel.
  - Alterar policy/router/provider automaticamente.
  - Rodar benchmark externo ou declarar superioridade externa.
depends_on:
  - atlas-execution-memory-outcome-runtime
  - atlas-persistent-context-runtime
  - atlas-context-intelligence-engine
  - atlas-compounding-engineering-intelligence
flows_to:
  - atlas-execution-memory-outcome-runtime
  - atlas-persistent-context-runtime
  - atlas-dev
  - atlas-forge
  - atlas-ai-control-plane
unlocks:
  - anti_false_learning
  - context_roi_loop
  - repeated_failure_blocking
  - pre_execution_risk_prediction
  - outcome_based_policy_proposals
governs:
  - outcome_judgment
  - learning_candidate_validation
  - memory_conflict_resolution
  - human_override_learning
  - policy_proposal
evidence:
  - docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
  - docs/engineering-knowledge-base/atlas-persistent-context-runtime.md
required_tests:
  - "php artisan atlas:aemor:judgment-certify --json --strict"
  - "php artisan test tests/Feature/Ai/Aemor/Judgment"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Add richer causal attribution once more AEMOR outcomes exist.
  - Feed repeated failure blockers deeper into Dev/Forge execution gates.
  - Keep judgment certification green whenever learning gates change.
---
# Atlas AEMOR Judgment & Learning Guard

## Resumo

AEMOR registra execucao, outcome, falhas, reparos e aprendizados candidatos. AEMOR Judgment & Learning Guard decide o que esses dados realmente significam.

O objetivo e impedir tres classes de erro:

- aprender coisa falsa porque um patch passou por acaso;
- repetir falhas que o Atlas ja viu;
- poluir APCR/ACIE/Compounding com memoria irrelevante, stale ou perigosa.

Regra central:

```text
Outcome nao e aprendizado. Outcome precisa ser julgado antes de virar memoria, estrategia ou policy proposal.
```

## Papel no Atlas

Esta camada fica acima da AEMOR Intelligence Layer:

```text
APCR -> Hyperflow/Dev/Forge -> AEMOR Episode/Outcome -> AEMOR Intelligence Layer -> Judgment & Learning Guard -> Memory/Policy/Control Plane proposals
```

Ela transforma execucao em julgamento auditavel:

- o que causou o resultado;
- o que foi provado;
- o que nao foi provado;
- o que deve entrar no proximo contexto;
- o que deve ser bloqueado;
- o que deve virar proposta de regra operacional.

## Onde Se Encaixa

Entradas:

- execution episode;
- context pack APCR;
- retrieval pack ACIE;
- patch outcome;
- command/test ledger;
- human correction;
- evidence refs;
- provider/skill metadata;
- prior failures.

Saidas:

- causality ranking;
- false-learning decision;
- context ROI report;
- negative knowledge entry;
- execution strategy candidate;
- pre-execution risk warning;
- memory conflict resolution;
- policy proposal;
- quality score.

## Contratos

Schemas canonicos:

- `atlas.aemor.judgment_report.v1`
- `atlas.aemor.outcome_causality_rank.v1`
- `atlas.aemor.false_learning_gate.v1`
- `atlas.aemor.repeated_failure_suppression.v1`
- `atlas.aemor.context_roi_score.v1`
- `atlas.aemor.execution_strategy_memory.v1`
- `atlas.aemor.patch_quality_fingerprint.v1`
- `atlas.aemor.memory_conflict_resolution.v1`
- `atlas.aemor.human_correction_compression.v1`
- `atlas.aemor.pre_execution_risk_prediction.v1`
- `atlas.aemor.policy_proposal.v1`

Campos comuns:

- `episode_id`
- `outcome_id`
- `scope_type`
- `scope_id`
- `evidence_refs`
- `alternative_explanations`
- `confidence`
- `risk_level`
- `valid_from`
- `valid_until`
- `supersedes`
- `claim_policy`
- `receipt_hash`

## Fluxo

Fluxo canonico:

```text
1. Ler outcome e evidence.
2. Construir causal candidates.
3. Ranquear causas provaveis.
4. Avaliar explicacoes alternativas.
5. Rodar anti-false-learning gate.
6. Medir ROI do contexto usado.
7. Detectar falha repetida e conflito de memoria.
8. Comprimir correcao humana quando existir.
9. Gerar estrategia, negative knowledge ou policy proposal.
10. Publicar judgment report no Control Plane.
```

Se o gate anti-false-learning falhar, o resultado correto e `blocked_for_learning`: o episodio continua registrado, mas nao vira memoria confiavel.

## Regras para IA

Regras obrigatorias:

- Nao inferir causalidade unica sem evidence.
- Nao aprender de sucesso sem checar cobertura de teste.
- Nao aprender de falha sem checar contexto faltante.
- Nao punir provider quando o input estava incompleto.
- Nao promover correcao humana como regra global sem escopo.
- Nao apagar memoria conflitante; marcar como superseded/conflicted/tombstoned com receipt.
- Nao criar policy automaticamente; criar proposta auditavel.

Regra de severidade:

```text
Falha repetida em mesmo scope sobe severidade. Terceira repeticao exige blocker ou mitigacao antes de nova execucao.
```

## Escopo de Implementacao

Componentes:

- **Outcome Causality Ranker**: ranqueia causas provaveis do outcome.
- **Anti-False-Learning Gate**: bloqueia aprendizado quando ha explicacao alternativa forte.
- **Repeated Failure Suppression**: detecta falhas repetidas e cria blocker/mitigacao.
- **Context ROI Scoring**: mede quais fontes ajudaram, confundiram, faltaram ou ficaram stale.
- **Execution Strategy Memory**: registra estrategias que funcionaram por repo/dominio/tarefa.
- **Patch Quality Fingerprint**: mede risco, tamanho, cobertura, rollback e repair cost do patch.
- **Memory Conflict Resolver**: resolve memoria contraditoria por freshness, authority e evidence.
- **Human Correction Compression**: transforma correcao humana em regra escopada e testavel.
- **Pre-Execution Risk Prediction**: avisa antes da execucao que a tarefa parece falhas antigas.
- **Outcome-to-Policy Proposal**: gera proposta de regra/gate/policy para review.

Comandos ativos:

```bash
php artisan atlas:aemor:judgment --episode=... --json
php artisan atlas:aemor:risk-predict --goal="..." --json
php artisan atlas:aemor:memory-conflicts --json
php artisan atlas:aemor:judgment-certify --json --strict
```

## Dependencias

Dependencias:

- AEMOR para episodio/outcome/eventos.
- APCR para medir contexto usado e aplicar risk prediction.
- ACIE para medir ROI de retrieval/context pack.
- Evidence Ledger para prova append-only.
- Atlas Dev para patch/test/repair data.
- Atlas Forge para Obra/milestone/work packet data.
- Compounding para receber learning candidates aprovados.
- Control Plane para expor blockers, conflitos e proposals.

## Evidencias

Evidencia minima para declarar pronto:

- causality rank com pelo menos uma causa e explicacoes alternativas;
- anti-false-learning bloqueando aprendizado falso em teste;
- repeated failure criando blocker no mesmo scope;
- context ROI marcando fonte util, stale, ausente e irrelevante;
- memory conflict resolver supersedendo memoria antiga;
- human correction virando regra escopada, nao global;
- pre-execution risk prediction alterando APCR/context pack;
- policy proposal gerada sem autoaplicar policy;
- certification command `passed`.

Definition of Done:

```text
Todo aprendizado aprovado deve responder:
1. O que foi provado?
2. O que nao foi provado?
3. Qual causa principal?
4. Quais causas alternativas foram descartadas?
5. Qual evidencia sustenta?
6. Onde isso vale?
7. Quando expira?
8. Como evitar repeticao?
```

## Riscos

Riscos:

- Score opaco virar autoridade falsa.
- Attribution punir provider por erro de contexto.
- Negative knowledge bloquear solucao valida no futuro.
- Human override virar regra ampla demais.
- Repeated failure suppression bloquear progresso sem plano de reparo.
- Policy proposal virar mudanca automatica sem review.

Mitigacoes:

- breakdown obrigatorio em todo score;
- `alternative_explanations` obrigatorio;
- validade temporal;
- escopo estrito;
- human review para policy;
- replay/counterfactual antes de memoria trusted.

## Exemplos

Exemplo 1:

```text
Patch passou, mas nenhum teste cobria o caminho alterado.
Gate: blocked_for_learning.
Learning permitido: "test coverage insufficient", nao "patch strategy succeeded".
```

Exemplo 2:

```text
Tres sessoes quebraram YouTube ingestion porque rich_input.url_attachments foi ignorado.
Repeated failure: critical.
Acao: APCR passa a incluir negative knowledge + teste obrigatorio ate o blocker ser resolvido.
```

Exemplo 3:

```text
Usuario corrige: "TEOS e de programacao, nao Atlas AI global".
Human correction compression: regra escopada para docs/flows de programming.
Outcome-to-policy: proposal para naming/domain boundary gate.
```

## Proximas Acoes

Estado local esperado:

- Judgment Guard emite causality, attribution, anti-false-learning, repeated failure, context ROI, patch fingerprint, memory conflicts, human correction, negative knowledge, provider reliability, counterfactual replay, memory budget, operational doctrine, risk prediction, policy proposals e quality score.
- APCR consome risk prediction antes do handoff.
- Certificacao: `php artisan atlas:aemor:judgment-certify --json --strict`.

Proximas evolucoes nao bloqueantes:

1. Calibrar pesos com dados reais de execucao.
2. Expandir reliability memory por familia de tarefa.
3. Criar dashboards quando houver volume suficiente para comparar tendencias.
