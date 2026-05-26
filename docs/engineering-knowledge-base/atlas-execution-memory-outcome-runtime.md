---
id: atlas-execution-memory-outcome-runtime
type: engineering_knowledge
title: Atlas Execution Memory & Outcome Runtime
status: active
category: intelligence-runtime
priority: 98
summary: AEMOR e a camada de memoria operacional verificavel do Atlas. Enquanto APCR garante contexto antes da execucao, AEMOR registra episodios, outcomes, falhas, reparos, decisoes, handoffs e aprendizados depois/durante a execucao, promovendo memoria somente com evidencia.
implementation_state: implemented_local_runtime
blocker: none_for_local_runtime; later hardening may extend depth without changing the canonical contract.
tags:
  - atlas-ai
  - aemor
  - execution-memory
  - outcome-runtime
  - atlas-dev
  - atlas-forge
  - evidence
  - compounding
capabilities:
  - execution_episode_ledger
  - outcome_evaluation
  - failure_pattern_memory
  - patch_outcome_intelligence
  - decision_reuse
  - learning_signal_distillation
  - memory_promotion_gate
  - replay_manifest
  - forge_obra_memory
  - temporal_improvement_certification
  - causal_outcome_graph
  - outcome_attribution
  - memory_use_feedback
  - negative_knowledge
  - provider_skill_reliability
  - counterfactual_replay
  - memory_budget_governance
  - human_override_learning
  - operational_doctrine_extraction
  - aemor_quality_score
decisions:
  - AEMOR e infraestrutura interna, nao produto ou tela separada.
  - APCR prepara contexto; AEMOR interpreta execucao e cria aprendizado governado.
  - Execution trace, evidence, learning signal, memory, context e decision sao entidades diferentes.
  - Memoria operacional nunca nasce diretamente de resposta de IA, log bruto ou chat.
  - AEMOR nao chama provider, nao roda benchmark e nao altera router/policy automaticamente.
  - Termos `blocked`, `candidate`, `watch`, `trusted`, `stale`, `deprecated`, `archived` e `tombstoned` sao estados canonicos de outcome/memoria; nao indicam doc futura ou planejamento pendente.
maintenance:
  - Atualizar quando APCR, Atlas Dev, Atlas Forge, Evidence Runtime ou Compounding mudarem contratos.
  - Nao criar runtime paralelo aos ledgers existentes sem ADR e evidencia de lacuna.
  - Manter todos os schemas com receipts, hashes, evidence refs e claim policy.
related_paths:
  - docs/engineering-knowledge-base/atlas-persistent-context-runtime.md
  - docs/engineering-knowledge-base/atlas-context-intelligence-engine.md
  - docs/engineering-knowledge-base/atlas-conversation-operations-layer.md
  - docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-contracts.md
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-execution-memory-outcome-runtime
graph_title: Atlas Execution Memory & Outcome Runtime
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-persistent-context-runtime
graph_status: active
graph_source: repo
human_name: Atlas Execution Memory & Outcome Runtime
canonical_name: Atlas Execution Memory & Outcome Runtime
technical_name: atlas-execution-memory-outcome-runtime
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
allowed_changes:
  - Endurecer AEMOR em incrementos sem quebrar contratos existentes.
  - Adicionar novos consumers de outcome em APCR, Dev, Forge e specialist flows.
  - Promover novos schemas apenas quando houver codigo e testes.
forbidden_changes:
  - Promover memoria duravel sem evidence refs e promotion receipt.
  - Transformar log bruto, chat ou output de provider em memoria confiavel.
  - Alterar router, policy, provider topology ou gates automaticamente.
  - Declarar superioridade externa, benchmark ou melhoria temporal sem certificacao.
  - Duplicar Evidence Ledger, Memory Registry, Control Plane ou Compounding sem ADR.
depends_on:
  - atlas-persistent-context-runtime
  - atlas-context-intelligence-engine
  - atlas-conversation-operations-layer
  - atlas-compounding-engineering-intelligence
  - atlas-dual-core-engineering-system
flows_to:
  - atlas-dev
  - atlas-forge
  - atlas-ai-router-runtime
  - atlas-persistent-context-runtime
  - atlas-compounding-engineering-intelligence
unlocks:
  - execution_memory
  - no_repeated_failures
  - outcome_based_learning
  - provider_independent_replay
  - forge_obra_operational_memory
governs:
  - execution_episode
  - outcome_evaluation
  - learning_candidate
  - operational_memory_promotion
  - replay_manifest
evidence:
  - docs/engineering-knowledge-base/atlas-persistent-context-runtime.md
  - docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-contracts.md
required_tests:
  - "php artisan atlas:aemor:certify --json --strict"
  - "php artisan test tests/Feature/Ai/Aemor"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Expandir AEMOR com provider/skill reliability mais profundo quando dados reais de execucao acumularem.
  - Add UI/control-plane panels only after backend adoption stabilizes.
  - Keep certification green whenever APCR, Hyperflow, Dev or Forge wiring changes.
---
# Atlas Execution Memory & Outcome Runtime

## Resumo

AEMOR e o par operacional do APCR.

APCR responde: **com que contexto o Atlas deve comecar?**

AEMOR responde: **o que aconteceu, o que aprendemos, o que deve ser reutilizado e o que nao pode se repetir?**

A regra canonica:

```text
Execution trace != Evidence != Learning signal != Memory != Context != Decision
```

Nada vira memoria confiavel diretamente. Execucao gera evidencia, evidencia gera sinal, sinal gera candidato, candidato passa gates, e so entao pode virar memoria operacional consumida pelo APCR.

## Papel no Atlas

AEMOR transforma uso continuo do Atlas em vantagem acumulada. Claude Code/Codex puro pode trabalhar bem numa sessao; AEMOR faz o Atlas melhorar entre sessoes, semanas e Obras.

Posicao no pipeline:

```text
Surface / CLI / API
-> APCR cria persistent_context
-> Hyperflow / Router / Domain flow
-> AEMOR abre Execution Episode
-> Atlas Dev / Atlas Forge / Specialist Flow executa
-> AEMOR observa eventos, comandos, patches, gates, repairs e handoffs
-> Evidence Runtime prova artefatos e claims
-> AEMOR fecha Outcome
-> AEMOR destila Learning Signals
-> Promotion Gates criam memoria operacional governada
-> APCR consome memoria aprovada no proximo ciclo
```

AEMOR nao substitui Dev, Forge, Evidence, Compounding ou APCR. Ele conecta esses sistemas em torno de outcomes verificaveis.

## Onde Se Encaixa

AEMOR fica depois do APCR e antes/durante/depois da execucao.

Fronteiras:

- APCR nao aprende; prepara contexto.
- AEMOR nao executa provider; observa, avalia e aprende.
- Evidence Runtime prova; AEMOR interpreta outcome.
- Compounding recebe sinais aprovados; AEMOR nao muda comportamento critico sozinho.
- Self-Improvement aprova mudancas estruturais; AEMOR propoe.

Escopos:

- Atlas AI: episodios de flow/domain.
- Atlas Dev: patch, debug, test, repair e escalation.
- Atlas Forge: Obras, milestones, work packets e handoffs longos.
- Specialist flows: research, finance, marketing, strategy, cyber e outros dominios.
- APCR: consumo futuro de memoria operacional aprovada.

## Contratos

Schemas canonicos:

- `atlas.aemor.execution_episode.v1`
- `atlas.aemor.execution_event.v1`
- `atlas.aemor.outcome.v1`
- `atlas.aemor.context_utility.v1`
- `atlas.aemor.patch_outcome.v1`
- `atlas.aemor.command_run.v1`
- `atlas.aemor.failure_pattern.v1`
- `atlas.aemor.decision_reuse.v1`
- `atlas.aemor.learning_signal.v1`
- `atlas.aemor.memory_candidate.v1`
- `atlas.aemor.memory_promotion_receipt.v1`
- `atlas.aemor.memory_rejection_receipt.v1`
- `atlas.aemor.memory_supersession_receipt.v1`
- `atlas.aemor.handoff_receipt.v1`
- `atlas.aemor.replay_manifest.v1`
- `atlas.aemor.causal_outcome_graph.v1`
- `atlas.aemor.outcome_attribution.v1`
- `atlas.aemor.memory_use_feedback.v1`
- `atlas.aemor.negative_knowledge.v1`
- `atlas.aemor.provider_skill_reliability.v1`
- `atlas.aemor.counterfactual_replay.v1`
- `atlas.aemor.memory_budget.v1`
- `atlas.aemor.human_override_learning.v1`
- `atlas.aemor.operational_doctrine.v1`
- `atlas.aemor.quality_score.v1`
- `atlas.aemor.certification.v1`

Campos obrigatorios comuns:

- `schema_version`
- `id`
- `episode_id`
- `scope_type`
- `scope_id`
- `source_refs`
- `evidence_refs`
- `receipt_hash`
- `confidence`
- `freshness`
- `privacy_class`
- `risk_level`
- `status`
- `blockers`
- `claim_policy`
- `created_at`

Contrato de episodio:

```text
execution_episode:
  objective
  trace_id
  mission_id
  work_order_id
  obra_id
  flow_id
  provider
  apcr_pack_id
  persistent_context_hash
  workspace_baseline_ref
  status
```

Contrato de outcome:

```text
outcome:
  status: success|partial|failed|blocked|reverted|inconclusive
  quality_scores
  failure_modes
  evidence_pack_id
  repair_cycles
  learning_required
  outcome_hash
```

## Fluxo

Fluxo padrao:

1. abrir `execution_episode`;
2. anexar APCR por `persistent_context_hash`;
3. capturar baseline de workspace, thread, Obra ou dominio;
4. observar routing, provider, tool calls, comandos e gates;
5. registrar patch transactions, handoffs e repairs;
6. anexar evidence refs e receipts;
7. avaliar outcome;
8. calcular context utility;
9. destilar learning signals;
10. criar memory candidates;
11. aplicar promotion gates;
12. gerar replay manifest;
13. atualizar read model/control plane;
14. disponibilizar memorias aprovadas para APCR.

Fluxo de memoria:

```text
raw execution log
-> evidence
-> learning candidate
-> watch
-> trusted memory
-> APCR retrieval
-> later execution
-> usage feedback / decay / forgetting
```

Estados de memoria:

- `candidate`
- `watch`
- `trusted`
- `stale`
- `conflicted`
- `deprecated`
- `archived`
- `tombstoned`

## Regras para IA

- Nunca promover memoria diretamente a partir de resposta de IA.
- Nunca tratar trace bruto como evidence.
- Nunca usar memoria sem escopo, confidence e freshness.
- Nunca declarar melhoria sem outcome temporal.
- Nunca aplicar heuristic update sem approval/policy gate.
- Nunca esconder falha como sucesso parcial.
- Sempre registrar `use_when` e `do_not_use_when`.
- Sempre diferenciar fonte, evidencia, inferencia e decisao.
- Sempre gerar blocker quando evidence estiver ausente.
- Sempre permitir supersession em vez de editar memoria silenciosamente.

Regra de decisao:

```text
Se a informacao nao mudaria uma decisao futura, ela nao deve virar memoria operacional.
```

## Escopo de Implementacao

Dentro do escopo:

- Execution episode ledger.
- Execution event collector.
- Outcome evaluator.
- Evidence joiner.
- Context utility report.
- Patch outcome intelligence.
- Failure pattern memory.
- Decision reuse memory.
- Learning signal distiller.
- Memory promotion gates.
- Replay manifest.
- AEMOR Control Plane.
- Certification/readiness commands.

AEMOR Intelligence Layer:

- **Causal Outcome Graph**: liga contexto usado, decisao, arquivo, comando, falha, reparo, teste, outcome e memoria. Sem grafo causal, AEMOR so sabe que algo aconteceu; com grafo, sabe por que aconteceu.
- **Outcome Attribution Engine**: classifica a causa provavel do resultado: contexto ruim, retrieval faltante, provider fraco, teste ausente, patch errado, ambiente, requisito ambiguo ou decisao stale.
- **Memory Use Feedback**: mede se cada memoria recuperada pelo APCR foi util, irrelevante, stale, perigosa ou ausente. Isso fecha o ciclo APCR -> execucao -> AEMOR -> APCR.
- **Negative Knowledge Ledger**: registra o que nao fazer de novo, com escopo, validade e evidence. Exemplo: "nao alterar migration X sem rodar teste Y".
- **Provider/Skill Reliability Memory**: mede confiabilidade por tarefa, dominio, arquivo, teste e modo. Nao e ranking global de provider; e memoria operacional escopada.
- **Counterfactual Replay**: reconstrói episodio perguntando quais fontes, decisoes ou testes teriam evitado a falha. Gera candidates, nao verdades automaticas.
- **Memory Budget Governor**: controla crescimento da memoria por projeto, dominio e risco; aplica decay, compressao, supersession e tombstone com receipt.
- **Human Override Learning**: transforma correcao humana em signal auditavel, sem virar regra automatica sem gate.
- **Operational Doctrine Extractor**: extrai regras de operacao do repo: sempre, nunca, primeiro, antes de concluir, se falhar, pedir review.
- **AEMOR Quality Score**: score interno por episodio: evidence coverage, replayability, attribution confidence, memory usefulness, repeated-failure reduction, stale-memory avoidance e unsupported-claim risk.

Fora do escopo:

- Chamar provider.
- Rodar rivals/benchmark.
- Autoalterar router, policy ou provider topology.
- Criar UI pesada na primeira fase.
- Substituir APCR, Compounding ou Evidence Runtime.

Comandos ativos:

```bash
php artisan atlas:aemor:readiness --json
php artisan atlas:aemor:episode-open --json
php artisan atlas:aemor:observe --json
php artisan atlas:aemor:close-outcome --json
php artisan atlas:aemor:distill --json
php artisan atlas:aemor:memory-audit --json
php artisan atlas:aemor:replay --json
php artisan atlas:aemor:control-plane --json
php artisan atlas:aemor:certify --json --strict
```

## Dependencias

Dependencias principais:

- APCR para `persistent_context_hash`.
- Evidence Ledger para prova append-only.
- Atlas Dev para patch/test/repair data.
- Atlas Forge para Obra/milestone/work packet data.
- Compounding para aprendizagem aprovada.
- Control Plane para read model operacional.
- Policy/Approval para promotion gates.
- TEOS/long-horizon para replay, freshness e continuity.

Reuso obrigatorio:

- Evidence Ledger existente.
- Memory Registry/AiMemoryDelta/AtlasMemoryEntry.
- Control Plane existente.
- Compounding services existentes.
- Programming/Forge receipts existentes.

## Evidencias

AEMOR so pode ser declarado pronto quando houver evidencia de:

- episodio aberto antes de execucao relevante;
- APCR hash anexado ao episodio;
- eventos append-only;
- outcome fechado com evidence refs;
- patch outcome em Atlas Dev;
- Obra/milestone outcome em Forge;
- learning candidates gerados;
- promotion gates bloqueando memoria sem evidencia;
- APCR consumindo memoria aprovada;
- replay manifest reconstruindo episodio;
- control-plane mostrando blockers;
- certify command `passed`.

Definition of Done:

```text
Para qualquer memoria, claim ou decisao AEMOR deve responder:
1. De onde veio?
2. Qual episodio gerou?
3. Qual evidence sustenta?
4. Quem/qual gate promoveu?
5. Qual escopo e validade?
6. Qual confidence/freshness?
7. Como supersede ou desfaz?
8. Qual claim ela pode ou nao pode sustentar?
```

Sem essas respostas, o estado correto e `blocked`.

## Riscos

Riscos principais:

- Virar telemetry dump.
- Aprender lixo por excesso de automacao.
- Poluir APCR com memoria stale.
- Criar subsistemas paralelos ao Evidence/Compounding.
- Punir provider por contexto incluído mas nao necessario.
- Transformar review humano em carimbo decorativo.
- Gerar claims falsas de superioridade.
- Aumentar custo operacional sem melhorar decisao futura.

Mitigacoes:

- Distiller obrigatorio.
- Promotion gates.
- Confidence vector.
- Freshness e decay.
- `use_when` e `do_not_use_when`.
- Replay manifest.
- Human approval para alto risco.
- Certificacao temporal antes de claims.

## Exemplos

Exemplo Dev:

```text
Falha: migration JSON default quebra SQLite nos testes.
Evidence: comando test falhou, patch corrigiu, teste passou.
Learning: quando migration tocar JSON/default, rodar teste de migration SQLite.
Estado: watch; promover para trusted se repetir ou virar gate.
```

Exemplo Forge:

```text
Milestone concluido sem evidence runtime universal.
Outcome: partial, blocker aberto.
Learning: work packet terminal precisa evidence pack cross-runtime.
Acao: criar repair packet e atualizar continuity pack.
```

Exemplo APCR:

```text
APCR inclui memoria AEMOR porque tarefa toca billing.
Memoria diz: billing requer teste X e review humano Y.
Provider recebe must-know ledger com regra escopada.
```

## Proximas Acoes

Estado local esperado:

- I1/I2/I3/I4/I5 existem como runtime local: episodio, evento, outcome, distillation, memory candidate, replay, judgment, negative knowledge, provider reliability, counterfactual, budget, doctrine, APCR, Hyperflow e Control Plane.
- Certificacao: `php artisan atlas:aemor:certify --json --strict`.
- Guard separado: `php artisan atlas:aemor:judgment-certify --json --strict`.
- Operacao: `atlas:aemor:episode-open`, `observe`, `close-outcome`, `distill`, `judgment`, `risk-predict`, `memory-audit`, `replay`, `control-plane`.

Proximas evolucoes nao bloqueantes:

1. Alimentar AEMOR com dados reais de mais obras Dev/Forge.
2. Criar UI de Control Plane quando houver volume operacional suficiente.
3. Promover policy proposals apenas por review humano/Compounding governance.
