---
id: atlas-ai-implemented-vs-scaffold-matrix
type: engineering_knowledge
title: Atlas AI Implemented vs Scaffold Matrix
status: active
category: architecture-audit
priority: 90
summary: Snapshot read-only do que esta implementado, scaffold, futuro ou bloqueado na estrutura mae do Atlas AI, para orientar proximas sessoes sem duplicar fluxo.
tags:
  - atlas-ai
  - architecture
  - audit
  - readiness
  - anti-duplication
capabilities:
  - architecture_audit
  - documentation_governance
  - implementation_readiness
decisions:
  - Esta matriz e diagnostico, nao fonte de autoridade superior ao Kernel, Master Architecture, Domain specs ou APs.
  - Status aqui deve ser atualizado por validacao executavel e leitura de docs/codigo, nao por memoria de chat.
  - Itens scaffold ou future nao podem ser vendidos como produto pronto.
maintenance:
  - Atualizar depois de blocos grandes, architecture-validate verde ou mudancas em Voice, Constelacao, Provider Intelligence, Curator ou domains.
  - Manter abaixo de 260 linhas.
  - Nao transformar esta matriz em backlog paralelo; converter proximos passos em APs ou docs donos.
related_paths:
  - docs/engineering-knowledge-base/architecture-audit/README.md
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/canonical-index/layer-status.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
  - docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md
  - docs/engineering-knowledge-base/atlas-constelacao-surface.md
---

# Atlas AI Implemented vs Scaffold Matrix

Snapshot read-only atualizado em 2026-05-09 para ajudar a estrutura mae a seguir
sem perder o que ja existe, sem duplicar fluxo e sem confundir scaffold com
produto final.

## Scope

Esta matriz responde:

1. o que ja esta implementado ou validado;
2. o que esta parcialmente pronto;
3. o que e scaffold/future;
4. qual e o proximo bloco seguro para outro agente adiantar.

Ela nao substitui `architecture-validate`, `docs-health`, APs, domain specs ou
os docs donos. Use como mapa de handoff.

## Coverage Boundary

Esta matriz cobre blocos estruturais e surfaces relevantes para a estrutura mae:
Kernel, domains, Memory, Evidence, Curator, providers, surfaces, MCP, mobile,
engineering, tools, semantic layer e product substrate.

Ela nao tenta listar cada controller, model, migration, command ou teste. Quando
um agente precisar de inventario exaustivo por arquivo, deve gerar auditoria
focada a partir de `rg`, Code Intelligence e `architecture-readiness`.

## Status Vocabulary

| Status | Significado operacional |
|---|---|
| `implemented_ready` | Codigo/teste/comando/API existem e a validacao nao aponta blocker direto. |
| `implemented_partial` | Base existe, mas falta integracao, runtime real, maturidade ou reconciliacao. |
| `scaffold` | Contrato, doc, rota ou classe existem, mas ainda nao e produto final. |
| `future` | Intencao aprovada, sem execucao atual suficiente. |
| `blocked` | Proximo passo deve corrigir validacao ou conflito antes de expandir. |
| `unknown` | Nao afirmar pronto; exige auditoria focada antes de implementar. |

## Validation Snapshot

Comandos usados:

```bash
php artisan atlas:ai:architecture-validate --json
php artisan atlas:ai:architecture-readiness --json
atlas engineering knowledge docs-health --json
git status --short
```

Resultado do snapshot:

| Area | Resultado |
|---|---|
| Documentation health | `ok`: 0 missing required, 0 oversized, 0 frontmatter violations. |
| Architecture readiness | `ready`: architecture-validate verde, docs-health verde, provider projection passed. |
| Static scanner | 0 failed static scans no readiness atual. |
| Blocker atual | Nenhum blocker ativo no readiness atual. |
| Provider projection | `passed`. |
| Architecture operations | `published`, 58 commands. |

Observacao: esta matriz continua read-only; confirmar estado real com readiness
e testes focados antes de expandir qualquer bloco.

## Implemented Ready

| Bloco | Status | Evidencia | Proximo cuidado |
|---|---|---|---|
| Kernel contracts | implemented_ready | Surface Adapter, Provider Driver, failure domains, classifier, SLOs e static scans verdes no readiness atual. | Manter architecture-validate verde antes de expandir. |
| Surface adapters | implemented_ready | 9 surfaces mapeadas: CLI dev/chat/forge, API, app, worker, MCP readonly, Vault, voice. | Nao criar surface paralela para capacidade ja mapeada. |
| Provider drivers | implemented_ready | 4 providers: `claude_cli`, `codex_cli`, `gemini_cli`, `claude_codex`. | Provider nao decide fluxo, memoria ou policy. |
| Domain plane | implemented_ready | Validador reporta 15 domains ready e 93 flows. | Reconciliar docs que ainda digam scaffold apos higiene. |
| Evidence Ledger foundation | implemented_ready | Append-only model, commands/API, replay service e 3 projections ready. | Ledger projection drift ainda depende de ledger presente. |
| Decision Receipt v2 | implemented_ready | Receipt issuer, runtime guard, hash, replay/report tests e AP scans verdes. | Runtime nao pode executar sem receipt onde contrato exige. |
| Architecture Operations | implemented_ready | Catalogo publicado e readiness command/API/MCP declarados. | Usar antes de expandir arquitetura. |
| Documentation OS | implemented_ready | docs-health verde e limites de linha respeitados nos docs obrigatorios. | Manter indices sincronizados depois da higiene. |
| Self-Improvement base | implemented_ready | Runtime, schedule, health/report, proposal inbox e filtros por replay existem. | Curator recomenda/revisa; nao aplica mudanca sozinho. |
| AP-99/AP-146 provider cost/performance | implemented_ready | Provider performance, cost rates, inbox replay e Curator finding para rates nao aplicados. | Rates continuam humanos/revisaveis. |
| Dynamic Compute Market | implemented_partial | Advisor/report, proposal gate, evidence contract, Curator proposal e recomendacoes existem em modo shadow/proposal. | Nao virar roteador automatico sem benchmark, review humano, policy patch e novo receipt. |
| Cognitive development plane | implemented_partial | Dreyfus, worked examples, patterns, failure tracker, SRL, Productive Failure e Personal Worked Examples tem servicos/testes/comandos. | AP-168 ainda tem transfer test proposal-only; AP-169 ainda nao tem scheduler/review UI; validar maturidade por dominio antes de vender como tutor final. |
| Provider Projection | implemented_ready | Projection status passed para `claude` e `agents`; AGENTS/CLAUDE sao artefatos gerenciados. | Nao editar bloco gerenciado manualmente fora do projection flow. |
| AtlasVault boundary | implemented_ready | Vault docs/contracts/runbook definem sync gerenciado, frontmatter e promocao para Memory. | Vault nao e fonte operacional crua. |
| Telemetry / Observability | implemented_ready | SLOs, provider performance, telemetry commands e reports existem. | Observability reporta; nao decide policy sozinho. |
| Mobile gateway base | implemented_partial | Mobile surface e rotas voice/AI existem em camada API. | UX mobile final e contratos por feature ainda precisam maturidade. |
| Attachments / multimodal input | implemented_partial | Attachment index, chunked upload, file/image attachment services e tests existem. | Garantir que tudo entre por Atlas Input/context policy. |
| Search / retrieval surfaces | implemented_partial | Session search, retrieval inputs, context router, `atlas:ai:local-rag-readiness`, corpus benchmark, contrato `LOCAL_RAG_*` e AP-683 review gate proposal-only existem. | Graph RAG/rerank Python ainda e futuro governado; falta review humano/Curator antes de policy patch real. |
| Inbox / Proposal loop | implemented_ready | Inbox actions, proposal commands, mobile inbox, action replay report e Curator refs existem. | Inbox e review humano continuam gate; nao auto-aplicar proposals. |
| Open Brain MCP surfaces | implemented_ready | Open Brain MCP service, context command/API e architecture operations MCP reports existem. | MCP e read-only/projection; nao vira executor oculto. |
| Engineering Knowledge / Code Intelligence | implemented_ready | KB sync, docs-health, code-index, code-status, API e commands existem. | Reindexar depois de docs/codigo grandes. |
| AP Agent Workflow registry | implemented_ready | AP workflow command, registry, handoff/preflight/decision/receipt classes e tests existem. | Nao confundir workflow contract com execucao runtime real. |
| Policy / permission / runtime budget | implemented_partial | Policy service, permission engine, runtime budget input/commands e tests existem. | Autonomia forte ainda exige gates e receipts por dominio. |

## Implemented Partial

| Bloco | Status | O que existe | O que falta |
|---|---|---|---|
| Voice Realtime | implemented_partial/scaffold | Doc canonico, adapter `voice_realtime`, rotas API/mobile, health/readiness/rivals, callbacks fail-closed sem `VOICE_TURN_DECIDED`, certification tests, artifact sanitization gate, cliente mobile start/end/readiness e Voice Mode com fallback local. | LiveKit Agents runtime real, audio streaming de produto, UX mobile completa e uso em producao. |
| Constelacao | implemented_partial | Backend v1 de posicoes, rota API/mobile, consumo mobile, fallback deterministico, privacy/evidence, readiness semantico, promotion gate vector-only, contrato UI Lente 1, telemetria mobile open/load/fail/tap e Curator usage review existem. | Embeddings/Graph RAG benchmarkado, review humano para promocao e uso real por 30 dias antes de Lente 2. |
| Provider Release Intelligence | implemented_partial | Source registry, source watchlist CLI/API read-only, `continuous_ingestion_contract` fail-closed, release review command/API/MCP, `absorption_plan` proposal-only, Architecture Operations, docs de provider evolution e Self-Improvement default schedule proposal-only. | Crawler/fetch runtime real e promocao de sinais em Decide sempre via proposta, AP-99/Rivals e review humano. |
| Open Brain / Memory context | implemented_partial | Context injection, retrieval inputs, memory services, provider projections e readiness de RAG local. | Graph RAG/reranker e quality scoring avancado. |
| Programming harness | implemented_partial | CLI dev/fix/continue/forge, repair aliases, receipts, gates e tests. | Fechar unificacao com workers/runtime pesado e durable execution real. |
| Finance domain | implemented_partial | Domain contract, runtime, compliance gate, profile factory e tests. | Skill packs/agentes financeiros profundos e benchmarks contra agentes especializados. |
| Strategic Decision | implemented_partial | Domain contract, command/API e QL roadmap. | QL-5 curator/mutation e simulacoes robustas. |
| Tool Runtime | implemented_partial | Core, contracts, policy/evidence gates e catalogos de programming tools. | Promotion de recipes novas e runtime pesado padronizado. |
| Mac Agent service | implemented_partial | `MacAgentService` tem readiness, power helper, wake/background checks e API local relacionada. | Produto nativo/Swift futuro ainda nao substitui runtime governado. |
| Skills system | implemented_partial | Skill store/parser/discovery e dev quality gates existem. | Promotion para skill packs enterprise por dominio ainda precisa APs. |
| Engineering Blueprint / Harness | implemented_partial | Blueprint commands/API, engineering runs, gates, benchmark, visual/API/security scans e replay existem. | Durable execution enterprise e worker runtime pesado ainda precisam fechamento. |
| Scheduler / background jobs | implemented_partial | Scheduler inputs, tick command, Self-Improvement schedules, Provider Release recurring review proposal-only e recurring review surfaces existem. | Jobs autonomos precisam stop conditions, evidence e proposal gates por fluxo. |
| Semantic notes/search layer | implemented_partial | Semantic commands/controllers para notes, search, activation e curation proposals existem. | Nao promover como Graph RAG final ate encaixar em Memory/Context/Policy. |

## Product Substrate Snapshot

| Bloco | Status | Observacao |
|---|---|---|
| Capture / knowledge intake | implemented_partial | Capture services/controllers, privacy and semantic clarification feed Memory/Semantic layers; not a replacement for Atlas Input policy. |
| Projects / tasks / routines | implemented_partial | Project, task, blocker, plan proposal and routine scheduling services exist; AI autonomy must still pass proposals/gates. |
| Behavioral / digital signals | implemented_partial | Behavior logs, passive signals, digital activity, procrastination and checkins exist as substrate; they are not automatic decisions. |
| Health / cognitive app data | implemented_partial | Health snapshots and cognitive game runs exist; health domain remains non-clinical and review-only. |
| Audit / sync / vault health | implemented_ready | Audit events, sync logs and vault health snapshots support traceability; they do not override Evidence Ledger authority. |

## Domain Coverage Snapshot

| Grupo | Status | Observacao |
|---|---|---|
| Programming | implemented_partial | Dominio ready, surfaces/gates/repair fortes; specialist profiles e frontend harness avancado ainda future. |
| Finance | implemented_partial | Review/compliance prontos; agentes financeiros profundos e benchmarks externos ainda faltam. |
| Personal Development / Health | implemented_ready | Plan/review non-clinical, safety boundaries e tests existem. |
| Self-Improvement | implemented_ready | Curator, schedule, findings, proposal inbox e replay review existem. |
| Strategic Decision | implemented_partial | Review-only pronto; QL-5 mutation/simulation profunda futura. |
| Marketing / Research / Writing / Learning | implemented_ready | Domain docs/flows ready; evitar auto-publicacao, auto-spend ou memoria sem review. |
| QA / Security / Operations / Background / General | implemented_ready | Dominios ready com boundaries claros; nao executam mutacao operacional autonoma. |

## Scaffold Or Future

| Bloco | Status | Dono documental | Nao fazer agora |
|---|---|---|---|
| Cognitive future APs | scaffold | `docs/ap/AP-170-*` | AP-168/AP-169 ja tem runtime minimo parcial; nao confundir specs cognitivas ativas com APs futuros; implementar AP por AP. |
| Native Mac Agent | future | `atlas-native-mac-agent.md` | Nao transformar em primeira surface de voz antes do mobile. |
| Local Graph RAG / embeddings / reranker | future | `atlas-ai-local-performance-memory-strategy.md` | Nao criar cerebro Python paralelo ao Kernel. |
| Hot Context Pack Cache | future | `atlas-ai-local-performance-memory-strategy.md` | Nao cachear sem freshness, hashes e privacy gate. |
| Evidence distillation | future | `atlas-ai-local-performance-memory-strategy.md` | Nao resumir evidence sem fonte/citacao. |
| Local model triage / KV cache | future | `atlas-ai-local-performance-memory-strategy.md` | Nao decidir policy/provider fora do Laravel Kernel. |
| External Graph Harness / Graphify AP-684 | implemented_partial | `code-intelligence/external-graph-harness.md` + `docs/ap/AP-684-graphify-external-graph-harness.md` | P0/P2 tem contrato, validator, CLI/API e Architecture Operations read-only; nao rodar Graphify direto em memoria/docs privados nem promover `graph.json` para contexto. |
| Cyber Security extension | scaffold | `cyber-security-extension.md` e `cyber-security/README.md` | Nao promover recipes ofensivas sem policy/refusal/tool gates. |
| Personal worked examples generator AP-169 | implemented_partial | AP-169 docs + `atlas:worked-example extract/personal` | Nao criar gerador solto; continuar pelo fluxo existente de Worked Example/AP-164. |
| QL-5 Curator mutation classes | future | `atlas-ai-qualitative-levels-roadmap.md` | Curator nao deve mutar sistema sem Proposal Inbox/review. |

## Conflicts To Reconcile

| Sinal | Risco | Acao segura |
|---|---|---|
| Domain readiness e produto final sao coisas diferentes. | IA pode vender dominio ready como produto final completo. | `15 domains ready` significa contrato/orchestrator/flows/gates prontos; maturidade de produto vive nas linhas partial/scaffold desta matriz. |
| Cognitive Plane specs sao ativas, mas AP-170 segue scaffold e AP-168/AP-169 sao parciais. | IA pode criar capability solta fora do AP. | Ler `cognitive/implementation-briefing.md`; implementar AP por AP, com status granular. |
| Voice tem muitas rotas e testes, mas doc ainda `status: scaffold`. | Confundir contrato com produto final realtime. | Manter como `implemented_partial/scaffold` ate LiveKit Agents/runtime de audio/UX real. |
| AP static scan muda enquanto docs sao editados. | Snapshot fica obsoleto rapido. | Sempre rerodar readiness antes de implementar. |
| Feature Placement bloqueia relatorios novos quando scanner existente cobre o caso. | Risco de criar governanca duplicada. | Reusar `architecture-validate`, docs-health, readiness e esta matriz; so criar comando novo com placement desbloqueado. |

## Safe Next Blocks

| Ordem | Bloco | Porque e seguro | DoD minimo |
|---:|---|---|---|
| 1 | Voice Realtime phase 0 hardening | Ja tem contratos, certification artifact sanitization e testes; falta produto real. | Certification verde, callback loop, mobile contract, privacy gates. |
| 2 | AP-683 Local RAG promotion review | Readiness + benchmark + contrato `LOCAL_RAG_*` + finding `proposal_only` estao prontos sem criar cerebro Python paralelo. | Manter `LOCAL_RAG_GRAPH_PROMOTION_BLOCKED` como autoridade ate review humano/Curator e AP futuro de Graph RAG/Python. |
| 3 | AP-684 External Graph Harness | Contrato, validação de candidato, privacy/confidence gates, CLI/API e report Architecture Operations foram implementados read-only. | Usar apenas como candidato de Code Intelligence; nao promover Graphify para memoria/contexto/runtime sem AP futuro. |
| 4 | Constelacao Lente 1 usage review | UX mobile contemplativa, tap/detail, telemetria de uso, zero elementos operacionais na Lente 1 e finding `atlas.self_improvement.constelacao_usage_review.v1` ja estao governados. | Coletar uso real por 30 dias, revisar `constelacao_*` telemetry e manter Graph RAG bloqueado ate AP/review. |

## Handoff Rule

Antes de qualquer implementacao nova:

```bash
php artisan atlas:ai:architecture-readiness --json
php artisan atlas:ai:place-feature "<feature>" --strict --json
php artisan atlas:ai:architecture-validate --json
```

Se `readiness.status=attention`, corrigir o blocker ou registrar decisao
explicita antes de expandir. Nao criar fluxo paralelo para acelerar.
