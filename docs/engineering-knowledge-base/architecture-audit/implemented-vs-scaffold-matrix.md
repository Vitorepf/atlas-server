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
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-implemented-vs-scaffold-matrix

graph_title: Atlas AI Implemented vs Scaffold Matrix

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: architecture-audit

repo_paths:
  - docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md

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
  - architecture-audit

evidence:
  - docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - architecture-audit

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

Para classificar codigo vivo, headless, scaffold, legacy adapter, duplicacao,
unused candidate ou dead code confirmado, use
`atlas-code-reality-usage-intelligence.md`. Esta matriz e snapshot macro; ACRUI
e o contrato de classificacao operacional por alvo.

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
| Architecture operations | `published`, 81 commands. |

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
| Architecture Operations | implemented_ready | Catalogo publicado, readiness command/API/MCP declarados, `owner_layer_operations.runtime` exposto em readiness/bootstrap/placement, report surfaces para Capture/Inbox, Task Orchestration, Tool Action Runtime, Long-Running Work e Proactive Layer, mais baseline declaration command para Long-Running Work. | Usar antes de expandir arquitetura. |
| Documentation OS | implemented_ready | docs-health verde e limites de linha respeitados nos docs obrigatorios. | Manter indices sincronizados depois da higiene. |
| Self-Improvement base | implemented_ready | Runtime, schedule, health/report, proposal inbox e filtros por replay existem. | Curator recomenda/revisa; nao aplica mudanca sozinho. |
| AP-99/AP-146 provider cost/performance | implemented_ready | Provider performance, cost rates, inbox replay e Curator finding para rates nao aplicados. | Rates continuam humanos/revisaveis. |
| Dynamic Compute Market | implemented_partial | Advisor/report, proposal gate, evidence contract, Curator proposal e recomendacoes existem em modo shadow/proposal. | Nao virar roteador automatico sem benchmark, review humano, policy patch e novo receipt. |
| Cognitive development plane | implemented_partial | Dreyfus, worked examples, patterns, failure tracker, SRL, Productive Failure, Personal Worked Examples e Predictive Failure tem servicos/testes/comandos; AP-168 exige topico explicito, AP-170 exige alvo explicito e nenhum deles gera desafio `unknown`; AP-169 scheduler semanal e registrado, fail-closed, review-only, preserva duplicatas read-only e nao grava candidato bruto no Ledger. | AP-168/AP-169/AP-170 ainda nao tem UX App/Mobile/Voice final nem daily-plan bootstrap real; validar maturidade por dominio antes de vender como tutor final. |
| Provider Projection | implemented_ready | Projection status passed para `claude` e `agents`; AGENTS/CLAUDE sao artefatos gerenciados. | Nao editar bloco gerenciado manualmente fora do projection flow. |
| AtlasVault boundary | implemented_ready | Vault docs/contracts/runbook definem sync gerenciado, frontmatter e promocao para Memory. | Vault nao e fonte operacional crua. |
| Telemetry / Observability | implemented_ready | SLOs, provider performance, telemetry commands e reports existem. | Observability reporta; nao decide policy sozinho. |
| Mobile gateway base | implemented_partial | Mobile surface, rotas voice/AI, Inbox safety, proactive delivery receipts and presence/eclipse push controls exist in API layer. | UX mobile final e contratos por feature ainda precisam maturidade. |
| Attachments / multimodal input | implemented_partial | Attachment index, chunked upload, file/image attachment services e tests existem. | Garantir que tudo entre por Atlas Input/context policy. |
| Search / retrieval surfaces | implemented_partial | Session search, retrieval inputs, context router, `atlas:ai:local-rag-readiness`, corpus benchmark, eventos `LOCAL_RAG_*` sanitizados, `promotion_review_contract`, `review_packet` com rollback/proibicoes e AP-683 review gate proposal-only existem. | Graph RAG/rerank Python ainda e futuro governado; falta review humano/Curator antes de policy patch real. |
| Inbox / Proposal loop | implemented_ready | Inbox actions, proposal commands, mobile inbox, action replay report e Curator refs existem. | Inbox e review humano continuam gate; nao auto-aplicar proposals. |
| Open Brain MCP surfaces | implemented_ready | Open Brain MCP service, context command/API e architecture operations MCP reports existem. | MCP e read-only/projection; nao vira executor oculto. |
| Engineering Knowledge / Code Intelligence | implemented_ready | KB sync, docs-health, code-index, code-status, API e commands existem. | Reindexar depois de docs/codigo grandes. |
| AP Agent Workflow registry | implemented_ready | AP workflow command, registry, handoff/preflight/decision/receipt classes e tests existem. | Nao confundir workflow contract com execucao runtime real. |
| Policy / permission / runtime budget | implemented_partial | Policy service, permission engine, runtime budget input/commands e tests existem. | Autonomia forte ainda exige gates e receipts por dominio. |

## Implemented Partial

| Bloco | Status | O que existe | O que falta |
|---|---|---|---|
| Voice Realtime | implemented_partial/scaffold | Doc canonico, adapter `voice_realtime`, rotas API/mobile, health/readiness/rivals, `phase0_hardening` no readiness, callbacks fail-closed sem `VOICE_TURN_DECIDED` incluindo `runtime_failed`, payload contract service-side com rejeicao recursiva de segredo aninhado, `activation_governance` mobile-first sem direct-provider/daemon/always-on, certification tests, artifact sanitization gate, AP-686 Python runtime boundary, AP-687 gate com `promotion_allowed=false`, receipt e rollback obrigatorios, `review_packet` propagado para Rivals/Curator, `bootstrap/runtime-certify.base_url` validado antes de publicar manifest ou gerar env file, runner Python de certificacao/CLI com timeout fail-closed, `runtime-certify` publica `artifacts.product_loop_check` e gate `product_loop_check_available`, `sdk-check` import-safe com `package_checks`, `missing_imports`, `sdk_imported=false`, `import_probe_only=true` e `sdk_probe_import_safe` no product loop/promotion gate, `voice readiness` aponta `product_loop_check` como contrato obrigatorio antes de daemon, runtime Python valida URL de env, URLs do manifest bootstrap, session leases do Kernel, `ATLAS_VOICE_ROOM_PREFIX` e `session_lease.room_prefix` sob `atlas-voice-`, `session_lease.required_room_prefix=atlas-voice-`, `session_lease.participant_namespace_source=client_surface`, sala LiveKit sempre escopada por `atlas-voice-`, `participant_identity` escopado por `client_surface`, token LiveKit nao emite sem URL/key/secret configurados e o issuer rejeita lease arbitrario mesmo se chamado direto, `pre-start-health-checks-smoke` em CLI/API/mobile prova env temporario placeholder, checks pre-start e `subprocess_start_contract` sem processo, cliente mobile start/end/readiness e Voice Mode com fallback local; runtime contract/bootstrap carregam AP-201 `runtime_invocation_contract` e o Python falha sem `DecisionReceipt`, `evidence_sink` e autoridade proibida. | LiveKit Agents runtime real, audio streaming de produto, UX mobile completa e uso em producao. |
| Constelacao | implemented_partial | Backend v1 de posicoes, rota API/mobile, consumo mobile, fallback deterministico, privacy/evidence, readiness semantico, promotion gate vector-only, lens gate bloqueando Command Sky, lens maturity gate de 30 dias, contrato UI Lente 1, `atlas.constelacao.lens1_usage_review.v1` com rollback/proibicoes, AP-201 `future_runtime_invocation_contract`, telemetria mobile e Curator usage review existem. | Embeddings/Graph RAG benchmarkado, review humano para promocao e uso real por 30 dias antes de Lente 2. |
| Provider Release Intelligence | implemented_partial | Source registry, source watchlist CLI/API read-only, `continuous_ingestion_contract` + `future_activation_review_contract` fail-closed, release review command/API/MCP, `anti_wrapper_contract`, `absorption_plan`, `promotion_gate` e `promotion_review_packet` proposal-only com rollback/proibicoes, Architecture Operations e Self-Improvement schedule. | Crawler/fetch runtime real e promocao de sinais em Decide sempre via proposta, AP-99/Rivals, novo receipt e review humano. |
| Open Brain / Memory context | implemented_partial | Context injection, retrieval inputs, memory services, provider projections e readiness de RAG local. | Graph RAG/reranker e quality scoring avancado. |
| Programming harness | implemented_partial | CLI dev/fix/continue/forge, repair aliases, receipts, gates e tests. | Fechar unificacao com workers/runtime pesado e durable execution real. |
| Finance domain | implemented_partial | Domain contract, runtime, compliance gate, profile factory e tests. | Skill packs/agentes financeiros profundos e benchmarks contra agentes especializados. |
| Strategic Decision | implemented_partial | Domain contract, command/API e QL roadmap. | QL-5 curator/mutation e simulacoes robustas. |
| Tool Runtime | implemented_partial | Core, contracts, policy/evidence gates e catalogos de programming tools. | Promotion de recipes novas e runtime pesado padronizado. |
| Mac Agent service | implemented_partial | `MacAgentService` tem readiness, power helper, wake/background checks e API local relacionada. | Produto nativo/Swift futuro ainda nao substitui runtime governado. |
| Skills system | implemented_partial | Skill store/parser/discovery e dev quality gates existem. | Promotion para skill packs enterprise por dominio ainda precisa APs. |
| Engineering Blueprint / Harness | implemented_partial | Blueprint commands/API, engineering runs, gates, benchmark, visual/API/security scans e replay existem. | Durable execution enterprise e worker runtime pesado ainda precisam fechamento. |
| Scheduler / background jobs | implemented_partial | Scheduler inputs, tick command, Self-Improvement schedules, Provider Release recurring review proposal-only e recurring review surfaces existem. | Jobs autonomos precisam stop conditions, evidence e proposal gates por fluxo. |
| Semantic notes/search layer | implemented_partial | Semantic commands/controllers para notes, search, activation, `EmbeddingService` fallback/hash local e curation proposals existem. | Tratar PHP como adapter temporario; nao promover para Vector RAG, Graph RAG, reranker ou clustering sem AP de `python_ai_data`. |

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
| Cognitive future APs | scaffold | `docs/ap/AP-COG-EDGE-*` futuros | AP-168/AP-169/AP-170 ja tem runtime minimo parcial; nao confundir specs cognitivas ativas com APs futuros; implementar AP por AP. |
| Native Mac Agent | future | `atlas-native-mac-agent.md` | Nao transformar em primeira surface de voz antes do mobile. |
| Local Graph RAG / embeddings / reranker | future | `atlas-ai-local-performance-memory-strategy.md` | Nao criar cerebro Python paralelo ao Kernel. |
| Hot Context Pack Cache | future | `atlas-ai-local-performance-memory-strategy.md` | Nao cachear sem freshness, hashes e privacy gate. |
| Evidence distillation | future | `atlas-ai-local-performance-memory-strategy.md` | Nao resumir evidence sem fonte/citacao. |
| Local model triage / KV cache | future | `atlas-ai-local-performance-memory-strategy.md` | Nao decidir policy/provider fora do Laravel Kernel. |
| External Graph Harness / Graphify AP-684 | implemented_partial | `code-intelligence/external-graph-harness.md` + `docs/ap/AP-684-graphify-external-graph-harness.md` | P0/P2 tem contrato, validator fail-closed, rejeicao de metadata aninhada com autoridade proibida, bloqueio de node duplicado, CLI/API, Architecture Operations, `review_only_constraints` e `review_packet` com rollback/proibicoes; nao rodar Graphify direto em memoria/docs privados nem promover `graph.json` para contexto/runtime. |
| Cyber Security extension | scaffold | `cyber-security-extension.md` e `cyber-security/README.md` | Nao promover recipes ofensivas sem policy/refusal/tool gates. |
| Personal worked examples generator AP-169 | implemented_partial | AP-169 docs + `atlas:worked-example extract/personal/extract schedule/extract scheduled` | Nao criar gerador solto; continuar pelo fluxo existente de Worked Example/AP-164. |
| QL-5 Curator mutation classes | future | `atlas-ai-qualitative-levels-roadmap.md` | Curator nao deve mutar sistema sem Proposal Inbox/review. |

## Conflicts To Reconcile

| Sinal | Risco | Acao segura |
|---|---|---|
| Domain readiness e produto final sao coisas diferentes. | IA pode vender dominio ready como produto final completo. | `15 domains ready` significa contrato/orchestrator/flows/gates prontos; maturidade de produto vive nas linhas partial/scaffold desta matriz. |
| Cognitive Plane specs sao ativas, mas AP-168/AP-169/AP-170 seguem parciais. | IA pode vender runtime minimo como tutor final. | Ler `cognitive/implementation-briefing.md`; implementar AP por AP, com status granular. |
| Voice tem muitas rotas e testes, mas doc ainda `status: scaffold`. | Confundir contrato com produto final realtime. | Manter como `implemented_partial/scaffold` ate LiveKit Agents/runtime de audio/UX real. |
| AP static scan muda enquanto docs sao editados. | Snapshot fica obsoleto rapido. | Sempre rerodar readiness antes de implementar. |
| Feature Placement bloqueia relatorios novos quando scanner existente cobre o caso. | Risco de criar governanca duplicada. | Reusar `architecture-validate`, docs-health, readiness e esta matriz; so criar comando novo com placement desbloqueado. |
| `EmbeddingService` PHP existe enquanto Python RAG ainda e futuro. | IA pode expandir PHP ate virar RAG/ML pesado fora da linguagem dona. | Manter como fallback/adapter; criar scan de fronteira ou AP Python antes de FAISS/Chroma/LangGraph/NetworkX/Pandas/Polars/scikit/reranker/clustering. |

## Safe Next Blocks

| Ordem | Bloco | Porque e seguro | DoD minimo |
|---:|---|---|---|
| 1 | Voice Realtime product loop | Fase 0 agora tem `phase0_hardening`, certification, callback loop, mobile contract, privacy gates e runtime boundary verdes. | Implementar LiveKit Agents loop real sem provider direto, manter mobile-first, gerar VOICE_* real, rodar Rivals-Voice e exigir review humano antes de producao. |
| 2 | Programming harness durable execution | Programming ja tem dev/fix/continue/forge, repair aliases, receipts e gates, mas ainda precisa fechar worker/runtime pesado duravel. | Unificar execucao pesada atras de Decision Receipt, Evidence Ledger, repair policy e AP/harness existente sem criar fluxo paralelo ao `atlas dev/forge`. |
| 3 | Cognitive Plane UX hooks | Cognitive runtime minimo existe em AP-168/AP-169/AP-170, mas falta UX App/Mobile/Voice e daily-plan bootstrap real. | Conectar flows existentes sem vender como tutor final; respeitar C1-C22, review-only para mudancas de curriculo e status granular por AP. |
| 4 | Provider Release ingestion runtime | Review, watchlist, MCP/API e Curator proposal-only existem; falta crawler/fetch runtime real. | Criar ingestion como proposal-only, sem mudar Decide/Policy ate AP-99/Rivals, review humano, policy patch e novo receipt. |
| 5 | Runtime language boundary maintenance | AP-201 esta verde e protege Laravel/Python/Go/Swift, mas vira regressao facil quando novos agentes adicionam runtime. | Manter `atlas:ai:runtime-boundary --json`, `/ai/runtime-boundary`, `atlas_runtime_boundary`, preflight/promotion policy e `atlas.runtime_invocation_contract.v1` verdes em toda expansao. |

## Preserved Guardrails

- AP-683 Local RAG promotion review segue `proposal_only`; `LOCAL_RAG_GRAPH_PROMOTION_BLOCKED` continua autoridade contra cerebro Python paralelo ate review humano/Curator e AP futuro.
- External Graph Harness / Graphify AP-684 segue `implemented_partial`, com `review_only_constraints`; nao rodar Graphify direto em memoria/docs privados nem promover Graphify para memoria/contexto/runtime sem AP futuro; nao promover Graphify para memoria/contexto/runtime sem AP futuro.
- Constelacao Lente 1 usage review segue contemplativa: zero elementos operacionais na Lente 1, Coletar uso real por 30 dias e manter Graph RAG/Command Sky bloqueados ate AP/review.

## Handoff Rule

Antes de qualquer implementacao nova:

```bash
php artisan atlas:ai:architecture-readiness --json
php artisan atlas:ai:place-feature "<feature>" --strict --json
php artisan atlas:ai:architecture-validate --json
```

Se `readiness.status=attention`, corrigir o blocker ou registrar decisao
explicita antes de expandir. Nao criar fluxo paralelo para acelerar.

## Resumo

Snapshot read-only do que esta implementado, scaffold, futuro ou bloqueado na estrutura mae do Atlas AI, para orientar proximas sessoes sem duplicar fluxo.

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
