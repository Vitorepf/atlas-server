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

Snapshot read-only gerado em 2026-05-08 para ajudar a estrutura mae a seguir
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
php artisan atlas:engineering:knowledge docs-health --json
git status --short
```

Resultado do snapshot:

| Area | Resultado |
|---|---|
| Documentation health | `ok`: 193 docs, 0 missing required, 0 oversized, 0 frontmatter violations. |
| Architecture readiness | `attention`: nao expandir sem olhar o failed static scan. |
| Static scanner | 158/159 checks passing. |
| Blocker atual | `ap173_session_bootstrap_docs_split_plan_contract` em `atlas-ai-kernel-architecture.md`. |
| Provider projection | `passed`. |
| Architecture operations | `published`, 54 commands. |

Observacao: o blocker mudou durante a higiene documental. Isso indica trabalho
ativo do Codex principal nos docs/scanner; nao editar essa area sem coordenar.

## Implemented Ready

| Bloco | Status | Evidencia | Proximo cuidado |
|---|---|---|---|
| Kernel contracts | implemented_ready | Surface Adapter, Provider Driver, failure domains, classifier, SLOs e static scans majoritariamente verdes. | Corrigir AP-173 antes de chamar tudo de verde. |
| Surface adapters | implemented_ready | 9 surfaces mapeadas: CLI dev/chat/forge, API, app, worker, MCP readonly, Vault, voice. | Nao criar surface paralela para capacidade ja mapeada. |
| Provider drivers | implemented_ready | 4 providers: `claude_cli`, `codex_cli`, `gemini_cli`, `claude_codex`. | Provider nao decide fluxo, memoria ou policy. |
| Domain plane | implemented_ready | Validador reporta 15 domains ready e 92 flows. | Reconciliar docs que ainda digam scaffold apos higiene. |
| Evidence Ledger foundation | implemented_ready | Append-only model, commands/API, replay service e 3 projections ready. | Ledger projection drift ainda depende de ledger presente. |
| Decision Receipt v2 | implemented_ready | Receipt issuer, runtime guard, hash, replay/report tests e AP scans verdes. | Runtime nao pode executar sem receipt onde contrato exige. |
| Architecture Operations | implemented_ready | Catalogo publicado e readiness command/API/MCP declarados. | Usar antes de expandir arquitetura. |
| Documentation OS | implemented_ready | docs-health verde e limites de linha respeitados nos docs obrigatorios. | Manter indices sincronizados depois da higiene. |
| Self-Improvement base | implemented_ready | Runtime, schedule, health/report, proposal inbox e filtros por replay existem. | Curator recomenda/revisa; nao aplica mudanca sozinho. |
| AP-99/AP-146 provider cost/performance | implemented_ready | Provider performance, cost rates, inbox replay e Curator finding para rates nao aplicados. | Rates continuam humanos/revisaveis. |
| Dynamic Compute Market | implemented_partial | Advisor/report e recomendacoes existem em modo shadow/proposal. | Nao virar roteador automatico sem AP e gates. |
| Cognitive development plane | implemented_partial | Dreyfus, worked examples, patterns, failure tracker e SRL tem servicos/testes/comandos. | Validar maturidade por dominio antes de vender como tutor final. |
| Provider Projection | implemented_ready | Projection status passed para `claude` e `agents`; AGENTS/CLAUDE sao artefatos gerenciados. | Nao editar bloco gerenciado manualmente fora do projection flow. |
| AtlasVault boundary | implemented_ready | Vault docs/contracts/runbook definem sync gerenciado, frontmatter e promocao para Memory. | Vault nao e fonte operacional crua. |
| Telemetry / Observability | implemented_ready | SLOs, provider performance, telemetry commands e reports existem. | Observability reporta; nao decide policy sozinho. |
| Mobile gateway base | implemented_partial | Mobile surface e rotas voice/AI existem em camada API. | UX mobile final e contratos por feature ainda precisam maturidade. |
| Attachments / multimodal input | implemented_partial | Attachment index, chunked upload, file/image attachment services e tests existem. | Garantir que tudo entre por Atlas Input/context policy. |
| Search / retrieval surfaces | implemented_partial | Session search, retrieval inputs e context router existem. | RAG local/graph/rerank ainda e futuro governado. |
| Inbox / Proposal loop | implemented_ready | Inbox actions, proposal commands, mobile inbox, action replay report e Curator refs existem. | Inbox e review humano continuam gate; nao auto-aplicar proposals. |
| Open Brain MCP surfaces | implemented_ready | Open Brain MCP service, context command/API e architecture operations MCP reports existem. | MCP e read-only/projection; nao vira executor oculto. |
| Engineering Knowledge / Code Intelligence | implemented_ready | KB sync, docs-health, code-index, code-status, API e commands existem. | Reindexar depois de docs/codigo grandes. |
| AP Agent Workflow registry | implemented_ready | AP workflow command, registry, handoff/preflight/decision/receipt classes e tests existem. | Nao confundir workflow contract com execucao runtime real. |
| Policy / permission / runtime budget | implemented_partial | Policy service, permission engine, runtime budget input/commands e tests existem. | Autonomia forte ainda exige gates e receipts por dominio. |

## Implemented Partial

| Bloco | Status | O que existe | O que falta |
|---|---|---|---|
| Voice Realtime | implemented_partial/scaffold | Doc canonico, adapter `voice_realtime`, rotas API, health/readiness/rivals, callbacks, certification tests. | LiveKit Agents runtime real, audio streaming de produto, UX mobile completa e uso em producao. |
| Constelacao | scaffold | Doc canonico encaixado, spec/vault source material, cliente mobile parcial com domain+jitter. | Endpoint server-side de posicoes, embeddings/Graph RAG, privacy/evidence e troca do fallback no app. |
| Provider Release Intelligence | implemented_partial | Source registry, release review command/API e docs de provider evolution. | Ingestao continua/web sources, review recorrente e impacto automatico em Decide via proposta. |
| Open Brain / Memory context | implemented_partial | Context injection, retrieval inputs, memory services e provider projections. | RAG local/graph/reranker e quality scoring avancado. |
| Programming harness | implemented_partial | CLI dev/fix/continue/forge, repair aliases, receipts, gates e tests. | Fechar unificacao com workers/runtime pesado e durable execution real. |
| Finance domain | implemented_partial | Domain contract, runtime, compliance gate, profile factory e tests. | Skill packs/agentes financeiros profundos e benchmarks contra agentes especializados. |
| Strategic Decision | implemented_partial | Domain contract, command/API e QL roadmap. | QL-5 curator/mutation e simulacoes robustas. |
| Tool Runtime | implemented_partial | Core, contracts, policy/evidence gates e catalogos de programming tools. | Promotion de recipes novas e runtime pesado padronizado. |
| Mac Agent service | implemented_partial | `MacAgentService` tem readiness, power helper, wake/background checks e API local relacionada. | Produto nativo/Swift futuro ainda nao substitui runtime governado. |
| Skills system | implemented_partial | Skill store/parser/discovery e dev quality gates existem. | Promotion para skill packs enterprise por dominio ainda precisa APs. |
| Engineering Blueprint / Harness | implemented_partial | Blueprint commands/API, engineering runs, gates, benchmark, visual/API/security scans e replay existem. | Durable execution enterprise e worker runtime pesado ainda precisam fechamento. |
| Scheduler / background jobs | implemented_partial | Scheduler inputs, tick command, self-improvement schedules e recurring review surfaces existem. | Jobs autonomos precisam stop conditions, evidence e proposal gates por fluxo. |
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
| Native Mac Agent | future | `atlas-native-mac-agent.md` | Nao transformar em primeira surface de voz antes do mobile. |
| Local Graph RAG / embeddings / reranker | future | `atlas-ai-local-performance-memory-strategy.md` | Nao criar cerebro Python paralelo ao Kernel. |
| Hot Context Pack Cache | future | `atlas-ai-local-performance-memory-strategy.md` | Nao cachear sem freshness, hashes e privacy gate. |
| Evidence distillation | future | `atlas-ai-local-performance-memory-strategy.md` | Nao resumir evidence sem fonte/citacao. |
| Local model triage / KV cache | future | `atlas-ai-local-performance-memory-strategy.md` | Nao decidir policy/provider fora do Laravel Kernel. |
| Cyber Security extension | scaffold | `cyber-security-extension.md` e `cyber-security/README.md` | Nao promover recipes ofensivas sem policy/refusal/tool gates. |
| Personal worked examples generator AP-169 | scaffold | AP-169 docs | Nao criar gerador solto sem fluxo Cognitive/Memory. |
| QL-5 Curator mutation classes | future | `atlas-ai-qualitative-levels-roadmap.md` | Curator nao deve mutar sistema sem Proposal Inbox/review. |

## Conflicts To Reconcile

| Sinal | Risco | Acao segura |
|---|---|---|
| Validador diz 15 domains ready, alguns docs falam scaffold. | IA pode subestimar ou superestimar dominio. | Depois da higiene, rodar busca por `status: scaffold` e reconciliar doc dono. |
| Voice tem muitas rotas e testes, mas doc ainda `status: scaffold`. | Confundir contrato com produto final realtime. | Manter como `implemented_partial/scaffold` ate LiveKit/runtime/UX real. |
| AP static scan muda enquanto docs sao editados. | Snapshot fica obsoleto rapido. | Sempre rerodar readiness antes de implementar. |
| Architecture-audit folder e indices estao sujos/untracked. | Risco de conflito com Codex principal. | Adicionar docs novos isolados; linkar indices so depois da higiene estabilizar. |

## Safe Next Blocks

| Ordem | Bloco | Porque e seguro | DoD minimo |
|---:|---|---|---|
| 1 | Fechar AP-173 docs split plan contract | E o blocker unico do readiness atual. | `architecture-validate --json` verde. |
| 2 | Rodar matriz pos-higiene | Confirma que docs/scanner estabilizaram. | Atualizar este snapshot com novo failed/passed count. |
| 3 | Provider Release Intelligence ingest/review | Complementa AP-99 sem tocar voice/mobile. | Sources, envelope, command, report, tests, docs. |
| 4 | Dynamic Compute Market proposal gates | Continua shadow/read-only, sem risco de auto-route. | Proposals revisaveis e replay evidence. |
| 5 | Voice Realtime phase 0 hardening | Ja tem contratos e testes; falta produto real. | Certification verde, callback loop, mobile contract, privacy gates. |
| 6 | Constelacao backend v1 | Escopo claro e isolado por endpoint. | Positions endpoint, fallback, privacy, evidence, tests. |
| 7 | Local RAG readiness AP | Prepara Graph RAG sem bagunca Python. | Contract, language boundary, index schema, no provider bypass. |

## Handoff Rule

Antes de qualquer implementacao nova:

```bash
php artisan atlas:ai:architecture-readiness --json
php artisan atlas:ai:place-feature "<feature>" --strict --json
php artisan atlas:ai:architecture-validate --json
```

Se `readiness.status=attention`, corrigir o blocker ou registrar decisao
explicita antes de expandir. Nao criar fluxo paralelo para acelerar.
