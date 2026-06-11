---
id: atlas-context-intelligence-engine
type: engineering_knowledge
title: Atlas Context Intelligence Engine
status: active
category: intelligence-runtime
priority: 96
summary: Especificacao canonica do Atlas Context Intelligence Engine (ACIE), a camada responsavel por selecionar, recuperar, compactar, verificar, reidratar, reparar e auditar contexto para Atlas AI, Atlas Dev, Atlas Forge e specialist flows. O objetivo e elevar a qualidade de contexto acima do uso cru de LLMs, sem declarar benchmark externo.
tags:
  - atlas-ai
  - acie
  - context-intelligence
  - rag
  - compaction
  - world-model
  - teos
  - atlas-dev
  - atlas-forge
capabilities:
  - smart_context_pack
  - mandatory_retrieval_gate
  - multi_pass_compaction
  - must_keep_ledger
  - semantic_context_diff
  - codebase_world_model
  - context_freshness_gate_consumption
  - context_replay_manifest
  - context_repair_loop
  - provider_ensemble
  - critic_adjudicator
  - agent_handoff_protocol
  - subagent_context_slicing
decisions:
  - ACIE e infraestrutura interna do Atlas, nao uma surface separada para usuario final.
  - ACIE nao substitui TEOS; ACIE usa TEOS como camada temporal/long-horizon.
  - ACIE nao substitui Atlas Dev ou Atlas Forge; ACIE melhora o contexto que eles recebem.
  - Compactacao nao e resumo livre; compactacao e contrato verificavel com perda medida.
  - Nenhum claim de superioridade contra Claude Code, Codex, GPT ou Gemini pode sair desta doc sem benchmark autorizado.
  - Retrieval, compaction, freshness e replay devem gerar evidence refs e hashes deterministas.
maintenance:
  - Atualizar quando novos gates de contexto forem implementados.
  - Sincronizar termos novos com atlas-canonical-glossary-and-naming.md antes de codar schemas.
  - Manter ACIE alinhado com TEOS, Hyperflow, Atlas Dev, Atlas Forge e Evidence Ledger.
related_paths:
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system.md
  - docs/engineering-knowledge-base/atlas-teos-increment-1-plan.md
  - docs/engineering-knowledge-base/atlas-long-horizon-intelligence-layer.md
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-architecture.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-contracts.md
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/context-pack.md
  - docs/engineering-knowledge-base/code-intelligence.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-context-intelligence-engine
graph_title: Atlas Context Intelligence Engine
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-autonomous-intelligence-operating-system
graph_status: active
graph_source: repo
human_name: Atlas Context Intelligence Engine
canonical_name: Atlas Context Intelligence Engine
technical_name: atlas-context-intelligence-engine
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-context-intelligence-engine.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-context-intelligence-engine.md
allowed_changes:
  - Manter status active enquanto service, command, tests e certification gate existirem.
  - Adicionar contratos filhos quando schemas forem implementados.
  - Atualizar subcomponentes quando TEOS, Hyperflow, Dev ou Forge mudarem.
forbidden_changes:
  - Tratar ACIE como provider, LLM ou produto separado.
  - Declarar superioridade externa sem benchmark autorizado.
  - Criar store paralelo para memoria, evidence, world model ou continuation pack sem ADR.
  - Remover must-keep coverage, evidence refs ou hashes dos contratos.
depends_on:
  - atlas-temporal-engineering-operating-system
  - atlas-hyperflow-operation
  - atlas-programming-superiority-contracts
  - atlas-dual-core-engineering-system
  - atlas-canonical-glossary-and-naming
flows_to:
  - atlas-ai-router-runtime
  - atlas-dev
  - atlas-forge
  - atlas-research
  - atlas-finance
  - atlas-marketing
  - atlas-strategy
unlocks:
  - context_superiority_runtime
  - verified_compaction_runtime
  - provider_independent_context_replay
  - cross_domain_context_quality_gate
governs:
  - context_pack_selection
  - retrieval_quality
  - compaction_quality
  - long_horizon_resume_quality
  - provider_context_adaptation
evidence:
  - docs/engineering-knowledge-base/atlas-context-intelligence-engine.md
  - app/Services/Ai/ContextIntelligence/AtlasContextOperationsRuntimeService.php
  - app/Services/Ai/ContextIntelligence/AtlasContextIntelligenceService.php
  - app/Services/Ai/ContextIntelligence/ContextIntelligencePayloadHash.php
  - app/Services/Ai/CompactionLossPolicy.php
  - app/Services/Ai/ContextIntelligence/AtlasContextIntelligenceCertificationService.php
  - app/Console/Commands/AtlasContextIntelligenceCertifyCommand.php
evidence_refs:
  - symbol: AtlasContextOperationsRuntimeService
  - command: atlas:context-intelligence:certify
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:context-intelligence:certify --json --strict"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Evoluir ACIE-I3+ com source coverage mais agressivo para tarefas de alto risco.
  - Adicionar UX operacional leve para inspecionar context packs, semantic diff e blockers.
  - Manter certificacao ACIE sincronizada com Hyperflow, Atlas Dev direto e Forge intake.
---
# Atlas Context Intelligence Engine

## Resumo

Atlas Context Intelligence Engine (ACIE) e a camada do Atlas responsavel por
fazer contexto virar uma vantagem operacional. Ela decide o que buscar, o que
preservar, o que compactar, o que reidratar, o que verificar e o que bloquear
antes de qualquer resposta ou execucao importante.

O objetivo nao e competir com LLMs no "cerebro bruto". O objetivo e fazer o
Atlas operar contexto melhor que um uso cru de GPT, Claude, Codex ou Gemini:

```text
intent -> retrieve -> rank -> pack -> compact -> verify -> execute -> repair -> certify -> learn
```

ACIE deve servir todos os dominios, mas a primeira implementacao deve focar em
programacao porque Atlas Dev e Atlas Forge ja possuem TEOS, receipts, RAG,
world model e certification gates.

## Papel no Atlas

ACIE fica entre o roteamento/intencao e os runtimes especialistas.

```text
Atlas AI
  -> Router / Hyperflow
    -> Atlas Context Intelligence Engine
      -> Atlas Dev / Atlas Forge / Research / Finance / Marketing / Strategy
        -> Execution / Response / Certification
```

Para o usuario, ACIE nao aparece como modo separado. O usuario continua usando
Atlas AI, Atlas Dev ou Atlas Forge. Internamente, ACIE aumenta a qualidade do
contexto entregue ao flow correto.

ACIE responde cinco perguntas antes de uma acao relevante:

1. Qual contexto e necessario?
2. Qual contexto e confiavel?
3. O que nao pode ser perdido?
4. O que mudou desde a ultima vez?
5. A resposta/execucao usou contexto suficiente?

## Onde Se Encaixa

ACIE compoe sistemas existentes:

- Hyperflow: fornece intent, domain, flow e runtime target.
- TEOS: fornece temporal truth, continuation pack, freshness, replay e safe resume.
- Atlas Dev: consome contexto para patch, debug, review, test e repair.
- Atlas Forge: consome contexto para Obra, SDD, milestones, work packets e certification.
- Open Brain / Memory: fornece memoria e conhecimento persistente.
- Code Intelligence / World Model: fornece grafo de codigo, testes, docs e riscos.
- Evidence Ledger: registra hashes, receipts, source refs e decision trail.
- Compounding: aprende com perdas, falhas, recuperacoes e feedback de uso.

ACIE nao deve criar store paralelo quando ja existir store canonico. A regra e
reuse-first.

## Contratos

Contratos alvo:

- `atlas.context_intelligence.request.v1`
- `atlas.context_intelligence.context_pack.v1`
- `atlas.context_intelligence.retrieval_report.v1`
- `atlas.context_intelligence.must_keep_ledger.v1`
- `atlas.context_intelligence.compaction_receipt.v1`
- `atlas.context_intelligence.semantic_diff.v1`
- `atlas.context_intelligence.freshness_report.v1`
- `atlas.context_intelligence.replay_bundle.v1`
- `atlas.context_intelligence.repair_plan.v1`
- `atlas.context_intelligence.provider_context_plan.v1`
- `atlas.context_intelligence.agent_handoff_packet.v1`
- `atlas.context_intelligence.subagent_work_contract.v1`
- `atlas.context_intelligence.critic_report.v1`
- `atlas.context_intelligence.certification.v1`

Campos obrigatorios em todos os contratos relevantes:

- `schema_version`
- `scope_type`
- `scope_id`
- `flow_id`
- `input_hash`
- `output_hash`
- `evidence_refs`
- `created_at`
- `policy`
- `claim_policy`

Invariantes:

- `benchmark_not_run=true` ate autorizacao explicita de benchmark.
- `provider_calls_made=false` em gates read-only.
- `must_keep_coverage` precisa ser calculavel quando houver compactacao.
- `context_pack_hash` precisa ser deterministico.
- `source_refs` nao podem ser inventados.
- `discarded_context` precisa ser explicitado quando houver perda relevante.

## Fluxo

Fluxo canonico:

1. Receber intent, domain, flow, risk e scope.
2. Construir retrieval plan.
3. Buscar fontes internas e externas permitidas.
4. Ranqueiar fontes por relevancia, autoridade, recencia e risco.
5. Montar Smart Context Pack.
6. Registrar Must-Keep Ledger.
7. Compactar quando necessario.
8. Rodar Semantic Context Diff.
9. Rodar Freshness Gate.
10. Gerar Provider Context Plan.
11. Executar flow ou resposta.
12. Rodar Critic/Adjudicator quando risco exigir.
13. Se falhar, abrir Context Repair Loop.
14. Certificar contexto usado.
15. Enviar feedback para Compounding.

Para tarefas triviais, ACIE pode operar em modo leve. Para tarefas de alto
risco, long-horizon ou multi-arquivo, ACIE deve operar em modo completo.

## Regras para IA

- Nunca trate ACIE como um LLM. ACIE e orquestracao de contexto.
- Nunca substitua source refs por resumo sem origem.
- Nunca compacte decisao, blocker, constraint ou risco critico sem registrar no Must-Keep Ledger.
- Nunca declare contexto suficiente se houver required source ausente.
- Nunca ignore freshness gate em retomada longa.
- Nunca rode benchmark ou rivals dentro de ACIE sem autorizacao explicita.
- Nunca promova uma conclusao externa sem evidence refs.

## Provider Context Staging Policy

O pacote inicial entregue a Atlas Dev, Forge, Codex, Claude Code ou outro
provider deve ser minimo, provider-safe e expansivel. O primeiro pacote deve
conter objetivo, escopo, owner refs, arquivos provaveis, riscos e handles de
expansao; nao deve despejar testes completos, docs completas ou dumps integrais
de grafo quando um ponteiro auditavel resolve.

Contrato operacional:

1. `initial_context_contract=minimal_provider_safe`.
2. `verification_handles` satisfazem plano de verificacao sem exigir conteudo
   completo de testes no primeiro pacote.
3. `expansion_handles` apontam para docs, testes, code graph, migrations,
   rotas e evidencia sob demanda.
4. `initial_context_chars` acima do budget ou `initial_context_kinds` contendo
   testes/docs/grafo completos deve bloquear ou exigir review antes de chamar
   provider.
5. O provider pode navegar para o contexto completo no proximo passo, mas o
   Atlas nao paga o custo cognitivo antes de haver necessidade real.
- Nunca reimplemente TEOS; use os contratos TEOS quando o problema for temporal/long-horizon.
- Nunca reimplemente Atlas Dev/Forge; ACIE prepara contexto para eles.
- Quando houver incerteza, retorne `blocked`, `read_only`, `ask_human` ou `needs_retrieval`.

## Escopo de Implementacao

### 1. Context Selection

Seleciona o contexto certo: Smart Context Pack, relevance ranking, source
authority score, recency score, task-specific budget, priority tiers, noise
filtering, duplicate collapse e dependency-aware selection.

### 2. Retrieval

Busca antes de responder ou executar: Mandatory Retrieval Gate, hybrid search
(keyword + semantic + graph), code/doc/test/git-history/issue/PR/user-memory
retrieval, web retrieval quando permitido e retrieval feedback loop.

### 3. World Model

Modela o ambiente: codebase graph, relacoes arquivo/modulo/teste/doc,
ownership map, risk map, dependency graph, runtime command graph,
migration/database graph, API route graph, UI surface graph e temporal world
model.

### 4. Compaction

Compactacao deve ser verificavel: multi-pass compaction, role-specific
summaries, decision/blocker/risk/evidence-preserving summaries, task-state
summaries, semantic compression, loss accounting e compaction confidence score.

### 5. Must-Keep Ledger

Registra o que nao pode sumir: decisoes, blockers, constraints, assumptions,
riscos, acceptance criteria, preferencias do usuario, invariantes de
arquitetura, open questions, pending approvals e evidence refs.

### 6. Verification

Prova que o contexto sustenta a resposta: semantic diff original vs compactado,
must-keep coverage, source coverage, contradiction detection, stale context,
missing source, unsupported claim, hallucination risk e replay verification.

### 7. Freshness

Controla verdade temporal: `valid_from`, `valid_until`, `stale_after`,
`superseded_by`, `source_hash`, `observed_at`, freshness gate,
expired-memory gate, superseded-decision gate e temporal authority ranking.

### 8. Replay

Permite reconstruir estado: replay manifest, reconstruction bundle, context
provenance, deterministic hashes, provider-independent reader, resume
certificate, cross-session continuity e agent handoff bundle.

### 9. Repair

Corrige contexto quebrado: recovery planner, context repair loop, retrieval
repair, compaction repair, missing-evidence repair, failed-test repair,
stale-source repair, contradiction repair e fallback to human review.

### 10. Provider Intelligence

Adapta contexto ao provider certo: provider ensemble, routing, memory
compatibility, provider-specific prompt adapter, cheap model prefilter,
strong model final judge, cross-provider critique, local privacy mode e
cost/quality routing.

### 11. Critic / Adjudicator

Audita qualidade antes de liberar: context critic, compaction critic,
retrieval critic, patch critic, answer critic, evidence critic, contradiction
critic, confidence calibration e final adjudication receipt.

### 12. Execution Coupling

Amarra contexto com execucao real: context-aware patch loop, test-impact
analysis, command recommendation, rollback planning, safe execution mode,
blast-radius estimate, evidence capture e completion gate.

### 13. Handoff / Subagents

Distribui trabalho sem perder contexto: handoff packet, subagent work
contract, context slice por agente, role-specific prompt, disjoint write set,
evidence return contract, blocker escalation, merge contract, supervisor
critic, anti-duplication guard, TTL de contexto e final integration receipt.

Tecnicas avancadas:

- **Map-reduce de contexto:** dividir leitura por modulo/doc/teste e
  consolidar num context pack unico.
- **Specialist swarm controlado:** researcher, planner, patcher, tester,
  reviewer e critic com contratos diferentes.
- **Disjoint ownership:** cada subagente recebe arquivos ou responsabilidades
  exclusivas para evitar conflito.
- **Evidence-first handoff:** subagente retorna paths, linhas, comandos,
  resultados e incertezas; nao retorna opiniao solta.
- **Context budget slicing:** cada agente recebe apenas o necessario para sua
  tarefa, mais must-keep ledger global.
- **Supervisor adjudication:** ACIE compara entregas, detecta contradicoes e
  monta decisao final.
- **Failure capsule:** falha vira pacote pequeno com causa, tentativa, logs,
  teste afetado e proxima acao.
- **Return-to-core rule:** subagente nao decide arquitetura global sozinho;
  decisoes criticas voltam para Dev/Forge/Router.

### 14. Learning

Aprende com uso real: retrieval success feedback, failed context feedback,
compaction loss feedback, provider performance feedback, user correction
memory, benchmark candidate generation sem executar benchmark, reusable
context templates e auto-tune ranking weights.

### 15. Domain Adaptation

Cada dominio tem politica propria: programming, finance, research, marketing,
strategy, personal development, high-risk legal/medical e domain-specific
must-keep rules.

### 16. Operator UX

Mostra confianca sem poluir a resposta principal: context inspector, "why this
context?", source coverage, missing/stale context warnings, replay view,
compaction diff, evidence timeline e trust score.

## Dependencias

Dependencias tecnicas:

- `AiCompactionService`
- `ProgrammingResumeService`
- `DevContinuationPackBuilder`
- `ForgeContinuationPackBuilder`
- `LongHorizonContextFreshnessGate`
- `LongHorizonRecoveryPlannerService`
- `LongHorizonContinuityCertificationService`
- `AtlasTeosFinalCertificationService`
- Code Intelligence / World Model services
- Evidence Ledger / receipts
- Hyperflow Router Runtime
- Atlas Dev Fast Path
- Atlas Forge Obra Runtime
- Compounding feedback services
- Agent/subagent orchestration surfaces

Dependencias documentais:

- `atlas-temporal-engineering-operating-system.md`
- `atlas-hyperflow-operation.md`
- `atlas-programming-superiority-contracts.md`
- `atlas-dual-core-engineering-system.md`
- `atlas-canonical-glossary-and-naming.md`

## Evidencias

ACIE so pode ser declarado ativo quando existir:

- Doc canonica.
- Service runtime de context certification.
- Service read-only de certification.
- Command `atlas:context-intelligence:certify --json --strict`.
- Testes de runtime, hash, claim policy e command.
- Teste Hyperflow provando `payload.hyperflow_runtime.context_intelligence`.
- Control-plane runtime expondo `context_operations.context_intelligence`, verified compaction e blockers.
- Testes provando uso em `AtlasProgrammingOrchestrator` e `ForgeIntakeService`, sem bypass direto Dev/Forge.
- Gate Dev/Forge robust flow incluído na certificação.
- TEOS final certification incluído na certificação.
- Evidence refs e hashes deterministas.
- Gate que prova `benchmark_not_run=true`.

Evidence minima por resposta/execucao importante:

- `context_pack_hash`
- `retrieval_report_hash`
- `must_keep_ledger_hash`
- `compaction_receipt_hash`, quando houver compactacao
- `freshness_report_hash`, quando houver retomada
- `critic_report_hash`, quando houver risco alto
- `agent_handoff_packet_hash`, quando houver subagentes
- `subagent_work_contract_hash`, quando houver delegacao
- `certification_hash`

Use `ContextIntelligencePayloadHash` para hashes de envelope e
`CompactionLossPolicy` para risco de perda; nao recrie esses helpers em
consumers ACIE/ACOL/compaction.

## Riscos

Riscos principais:

- Overengineering antes de entregar valor.
- Criar store paralelo para memoria/contexto.
- Duplicar TEOS.
- Duplicar World Model.
- Compactar contexto e perder decisao critica.
- Confiar demais em resumo sem source refs.
- Custo alto por critic/adjudicator em toda tarefa.
- Latencia alta em tarefas triviais.
- Provider ensemble virar benchmark disfarçado.
- Subagentes duplicarem trabalho ou conflitar em arquivos.
- Handoff perder must-keep decisions.
- Supervisor aceitar conclusao de subagente sem evidence refs.
- UX mostrar auditoria demais na resposta principal.
- Ranking reforcar fonte errada por feedback ruim.

Mitigacoes:

- Implementar por incrementos.
- Modo leve para tarefas triviais.
- Modo completo para risco alto/long-horizon.
- Reuse-first sobre servicos existentes.
- Must-Keep Ledger obrigatorio.
- Semantic Diff antes de liberar compactacao critica.
- Disjoint ownership e return contract obrigatorios para subagentes.
- Supervisor adjudication antes de integrar trabalho paralelo.
- Claim policy explicita em todo payload.

## Exemplos

Exemplos: bug pequeno usa stack trace + arquivo + teste focado; retomada longa
usa `continuation_pack.v2` + receipts + freshness gate; Obra Forge usa SDD +
milestones + work packets + blockers; delegacao de refactor usa handoff packet
para explorer/patcher/tester e supervisor integra so entregas com evidence refs;
pesquisa estrategica usa fontes recentes + authority score + contradiction
check.

## Proximas Acoes

Estado implementado e certificado nesta versao:

1. **ACIE-I1: Context Certification Read Model** — implementado via
   `AtlasContextIntelligenceService`, command de certificacao e testes.
2. **ACIE-I2: Verified Compaction** — implementado via
   `AtlasVerifiedCompactionService`, Semantic Diff e `must_keep_coverage`.
3. **Default runtime wiring** — implementado em Hyperflow, Atlas Dev direto,
   Forge intake e Control Plane.
4. **Handoff/context operations** — implementado via
   `AtlasContextOperationsRuntimeService` consumindo ACOL.

Evolucoes futuras, nao bloqueantes para o fluxo padrao atual:

- Source coverage mais agressivo para tarefas de alto risco.
- Ranking de World Model com pesos temporais mais granulares.
- Provider ensemble/critic com budget e policy dedicados.
- UX operacional leve para inspecionar context pack e semantic diff.

Definition of Done para ACIE no fluxo padrao:

- Hyperflow injeta ACIE por padrao no envelope principal.
- Atlas Dev usa ACIE por padrao via Hyperflow e robust flow certification.
- Atlas Forge usa ACIE por padrao via Hyperflow e robust flow certification.
- Specialist flows recebem ACIE com politica de dominio via Hyperflow.
- Compactacao critica nunca passa sem must-keep coverage.
- Retomada longa nunca passa sem freshness gate.
- Resposta importante nunca passa sem source coverage.
- Toda decisao relevante tem evidence refs e hash.
- Todo subagente recebe contexto limitado, contrato de retorno e ownership claro.
- Toda delegacao paralela passa por supervisor adjudication antes de integrar.
- Benchmark externo continua bloqueado ate autorizacao explicita.
