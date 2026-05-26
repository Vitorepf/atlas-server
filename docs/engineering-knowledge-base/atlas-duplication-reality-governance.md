---
id: atlas-duplication-reality-governance
type: engineering_knowledge
title: Atlas Duplication Reality Governance
status: active
category: documentation-governance
priority: 100
summary: Politica canonica que consolida como Atlas detecta e bloqueia duplicacao entre docs, features, runtimes, codigo, status implementado/scaffold e fluxos de IA.
human_summary: Define o caminho unico para provar se algo esta duplicado ou se deve reutilizar doc, runtime ou feature existente antes de qualquer IA codar.
human_what: Governanca anti-duplicacao para documentacao, codigo e features.
human_purpose: Impedir que multiplas IAs criem caminhos paralelos, documentacao concorrente ou claims falsos sobre o que esta implementado.
human_input: Recebe tarefa, feature, alvo de codigo, owner docs, outputs de ADER, docs-authority, ACRUI, docs-health, bootstrap e placement.
human_output: Entrega decisao de reuse, extend, supersede, review ou blocked com evidencia e comandos obrigatorios.
human_change_when: Atualize quando mudar ADER, ACRUI, docs-authority, session-bootstrap, feature-placement ou regras de status implementado/scaffold.
human_block_when: Bloqueie quando houver duplicate id/runtime, owner gap, overlap sem decisao de owner, codigo sem reachability ou doc que declare implementado sem prova.
tags:
  - atlas-ai
  - documentation
  - anti-duplication
  - code-reality
  - feature-placement
  - enforcement
capabilities:
  - duplication_reality_governance
  - anti_duplicate_documentation_gate
  - anti_duplicate_feature_gate
  - implemented_vs_scaffold_truth
  - ai_safe_reuse_decision
decisions:
  - Este doc nao cria runtime novo; ele organiza ADER, ADRS, ACRUI, docs-authority, bootstrap e placement.
  - Duplicacao real em docs canonicos e bloqueio; overlap contextual e sinal de review ate owner decidir reuse, extend ou supersede.
  - Codigo so pode ser chamado de duplicado, morto, pronto ou scaffold com evidencia de reachability, testes, docs e owner.
  - Se doc diz que algo nao esta implementado e o codigo prova o contrario, o estado canonico entra em drift e deve ser corrigido antes de claim forte.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar snapshot apos mudancas relevantes em docs, ACRUI ou ADER.
  - Rodar ADER strict, docs-health, docs-authority-audit, ACRUI reality-audit e ACRUI global-duplication-audit apos alteracoes amplas.
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-system.md
  - docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md
  - docs/engineering-knowledge-base/atlas-documentation-enforcement-runtime.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md
  - app/Services/Engineering/EngineeringDocumentationAuthorityAuditService.php
  - app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php
  - app/Services/Engineering/AtlasDocumentationEnforcementService.php
  - app/Services/Ai/Kernel/Architecture/AtlasFeaturePlacementService.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-duplication-reality-governance
graph_title: Atlas Duplication Reality Governance
graph_world: atlas
graph_layer: system
graph_kind: policy
graph_parent: atlas-documentation-reality-system
graph_status: active
graph_source: repo
owner: documentation-governance
repo_paths:
  - docs/engineering-knowledge-base/atlas-duplication-reality-governance.md
allowed_changes:
  - Atualizar regras, snapshot e comandos quando os gates anti-duplicacao mudarem.
forbidden_changes:
  - Transformar este doc em segunda fonte de verdade ou runtime paralelo ao ACRUI/ADER.
  - Declarar duplicacao zero fora do escopo provado pelos comandos.
  - Declarar implementado sem codigo, teste, comando, rota, migration ou evidence verificavel.
depends_on:
  - atlas-documentation-reality-system
  - atlas-code-reality-usage-intelligence
  - atlas-documentation-enforcement-runtime
flows_to:
  - session-bootstrap
  - feature-placement
  - atlas-dev
  - atlas-forge
  - atlas-cartography
unlocks:
  - ai-safe-reuse-before-implementation
  - duplicate-free-documentation-governance
  - implemented-vs-scaffold-drift-control
governs:
  - documentation-governance
  - architecture-audit
  - programming-domain
evidence:
  - docs/engineering-knowledge-base/atlas-duplication-reality-governance.md
  - app/Services/Engineering/EngineeringDocumentationAuthorityAuditService.php
  - app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php
required_tests:
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:code-reality reality-audit --json"
  - "php artisan atlas:code-reality global-duplication-audit --json"
  - "php artisan atlas:documentation:enforce --task=\"<task>\" --feature=\"<feature>\" --strict --json"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - documentation
  - anti-duplication
  - code-reality
ai_entrypoints:
  - Leia este doc antes de criar doc macro, runtime, feature, fluxo, comando, surface ou claim de implementado/scaffold.
ai_usage_notes:
  - Use os comandos deste doc como gate; nao use chat ou busca parcial para declarar que algo nao existe.
quality_gates:
  - "php artisan atlas:documentation:enforce --task=\"<task>\" --feature=\"<feature>\" --strict --json"
  - "php artisan atlas:ai:docs-authority-audit --json"
  - "php artisan atlas:code-reality anti-duplicate --feature=\"<feature>\" --json"
failure_modes:
  - IA cria feature paralela porque nao reconheceu owner doc.
  - Doc declara planned enquanto codigo esta ativo.
  - Codigo ativo e chamado de morto por falta de reachability scan.
  - Overlap contextual e confundido com duplicacao real sem decisao de owner.
observability_signals:
  - docs-authority duplicate group counts
  - ACRUI anti-duplicate match_count
  - ACRUI reality-audit unknown_or_unused_count
  - ACRUI global-duplication-audit duplicate_class_group_count and legacy_signal_count
  - ADER status and score
next_actions:
  - Triar os candidatos do ACRUI global-duplication-audit por owner antes de qualquer merge, supersede ou quarantine.
---
# Atlas Duplication Reality Governance
## Resumo
Atlas Duplication Reality Governance e a politica que impede IAs de criarem
documentacao, features, comandos, runtimes ou fluxos paralelos quando ja existe
um caminho canonico.

Ela nao substitui ADRS, ACRUI ou ADER. Ela explica como usar esses gates para
diferenciar duplicacao real, overlap contextual, scaffold, implementado e drift.

## Papel no Atlas
Documentacao e a camada mais critica do Atlas porque o projeto e construido por
multiplas IAs. Se a documentacao nao condiz com codigo, testes e evidence, a IA
seguinte implementa no lugar errado ou duplica o que ja existe.

Esta politica responde:

```text
Isso ja existe?
Qual owner doc manda?
Existe runtime/codigo/teste provando implementacao?
A doc esta atrasada em relacao ao codigo?
Devo reutilizar, extender, supersede ou bloquear?
```

## Onde Se Encaixa
```text
ADER hard gate
  -> docs-authority-audit: duplicacao documental canonica
  -> feature-placement: owner/layer/surface/runtime
  -> ACRUI anti-duplicate: duplicacao por feature
  -> ACRUI reachability/reality-audit: realidade de codigo
  -> implemented-vs-scaffold matrix: vocabulario de status
```

ADRS e o doc mae. ACRUI prova codigo. ADER bloqueia ou libera. Este doc e o
contrato operacional anti-duplicacao.

## Contratos
Tipos de duplicacao:

| Tipo | Bloqueio |
|---|---|
| `duplicate_doc_id` ou `duplicate_graph_id` | blocked |
| `duplicate_technical_runtime` | blocked |
| capability overlap cross-owner sem decisao | review |
| feature com match ACRUI | review ate reuse/extend/supersede |
| codigo sem reachability mas doc diz implemented | blocked |
| doc diz planned/scaffold mas codigo ativo prova runtime | review/drift |

## Fluxo
Antes de criar qualquer doc, feature, runtime, comando ou surface:

```bash
php artisan atlas:documentation:enforce --task="<task>" --feature="<feature>" --strict --json
php artisan atlas:ai:session-bootstrap --task="<task>" --json
php artisan atlas:ai:place-feature "<feature>" --json
php artisan atlas:ai:docs-authority-audit --json
php artisan atlas:code-reality anti-duplicate --feature="<feature>" --json
php artisan atlas:code-reality global-duplication-audit --json
php artisan atlas:code-reality reachability --target="<target>" --json
```

Decisao:
- `reuse`: usar doc/runtime existente.
- `extend`: adicionar capacidade no owner existente.
- `supersede`: substituir com decisao explicita e doc antigo apontando para o novo.
- `review`: owner/operator decide antes de claim forte.
- `blocked`: nao implementar.

## Regras para IA
1. Nao crie feature nova se `place-feature` ou ACRUI apontar owner existente.
2. Nao trate lista de candidatos do bootstrap como duplicacao real; ela e triagem.
3. Nao declare duplicacao zero global fora dos escopos que os comandos provaram.
4. Nao use `rg` sozinho para chamar codigo de morto ou ausente.
5. Nao declare implementado se nao houver codigo, teste, comando, rota, migration
   ou evidence verificavel.
6. Se docs e codigo divergem, registre drift e corrija a fonte canonica.
7. Se existir overlap cross-owner, pare e peça decisao de reuse, extend ou
   supersede.

## Escopo de Implementacao
Snapshot real em 2026-05-25:

| Gate | Resultado |
|---|---|
| `docs-health` | 798 docs, 0 warnings, 0 oversized, 0 frontmatter violations |
| `docs-authority-audit` | 734 docs canonicos, 0 identity duplicate groups, 0 runtime duplicate groups, 0 capability overlap groups, 0 owner gaps |
| `ACRUI reality-audit` | 6 targets ADRS/ACRUI/AURC, 6 active_runtime, 0 unknown_or_unused, 0 weak_reachability |
| `ACRUI anti-duplicate` para esta feature | `match_count=0`, `decision=proceed_with_owner_lookup` |
| `ACRUI global-duplication-audit` | `status=blocked`, 0 grupos em `duplicate_class_cleanup_queue`, 1 grupo de nome de classe restante (`SmokeSubject`) fora da fila de cleanup de producao, runtime action aliases, itens em `runtime_route_alias_cleanup_queue`, 50 itens priorizados em `legacy_cleanup_queue` e 39 itens em `ai_confusion_cleanup_queue` |
| `ACRUI status-drift-audit` | `status=review`, 734 docs canonicos ativos/building/planned/future, 682 `active`, 40 `building`, 5 `planned`, 7 `future`, 3 planned/future com evidencia de codigo existente, 3 com boundary explicado, 110 itens de pressao por linguagem scaffold/implemented misturada, 26 owner groups, 5 area groups |
| `php artisan route:list --json` | 647 metodo+URI runtime, 0 duplicatas metodo+URI reais, 0 nomes de rota duplicados, 30 actions expostas por multiplas rotas |
| `session-bootstrap/place-feature` | encontrou overlap contextual e bloqueou ate leitura dos owner docs |

Interpretacao: os gates documentais nao provaram duplicacao canonica por id,
graph_id, titulo ativo, runtime ou owner. Os 7 overlaps de stem sao nomes de
familia como `README`, `contracts`, `runbook` e `failure-modes`; eles exigem
review de boundary, mas nao provam duplicacao semantica. A varredura global de
codigo encontrou candidatos reais que bloqueiam claim de limpeza global e exigem
triagem por owner antes de merge, supersede, quarantine ou delecao.

Drift `implemented/planned/scaffold`: o ACRUI agora compara frontmatter e
linguagem do corpo com evidencias existentes de codigo, testes, migrations e
comandos. Os 3 planned/future mais concretos agora tem boundary explicado:

| Doc | Status | Interpretacao |
|---|---|---|
| `atlas-forge-rivals-intelligence-ledger-v1` | planned | proximo patamar sobre Provider Performance Ledger existente; nao completo |
| `atlas-cartographic-knowledge-os` | future | alvo visual futuro sobre Vault/Cartografia atuais; nao runtime ativo por si |
| `atlas-vox-v4-contextual-operator-plan` | planned | paper-only; refs Voice/Vox sao boundary proibido, nao evidencia de V4 |

Isso nao autoriza mudar status automaticamente: owner deve decidir se o status
continua planejado, vira partial/implemented ou se precisa separar runtime atual
de trabalho futuro. Os 110 itens restantes sao pressao de linguagem, nao
violacao comprovada.

Ranking inicial de limpeza por owner:

| Owner | Itens | Prioridade |
|---|---:|---|
| `atlas-ai` | 35 | separar roadmap/scaffold de runtime ja provado nos docs mae |
| `programming` | 20 | limpar Forge/TEOS/Programming para reduzir escolha errada de fluxo |
| `architecture` | 9 | reconciliar inventories/audits com status operacional atual |
| `domains` | 7 | separar planos locais implementados de claims externos bloqueados |
| `documentation-governance` | 5 | manter gates e snapshots alinhados com numeros reais |
| `programming_rivals` | 5 | distinguir baterias prontas, arena, ledger e proximos patamares |

Ranking por area fisica: `root` concentra 162 itens, `self-construction` 13,
`domains` 10, `vault` 2, `architecture-audit` 1 e
`research-self-improvement` 1. A ordem de limpeza deve ser owner primeiro,
area depois; path root e um lote grande demais para decisao cega.

Pressao por tema critico: RAG/retrieval e o maior foco, com owner canonico
`atlas-ai-local-performance-memory-strategy.md`, 27 hits em source-material
arquivado, 596 matches de codigo, 20 familias de fluxo e 9 papeis de codigo.
As subareas mais sensiveis sao
`app/Services/Ai/Programming` (126), `app/Services/Ai/SelfConstruction` (70),
`app/Console/Commands` (68), `app/Http/Controllers` (29), `app/Services/Ai/Kernel`
(28) e `app/Services/Ai/Context` (24). Isso nao prova fluxo duplicado sozinho;
prova que qualquer RAG/memory novo deve ler o owner canonico e decidir reuse,
boundary ou supersede antes de codar.

Fila RAG/retrieval de boundary review:

| Familia | Severidade | Decisao |
|---|---:|---|
| `graph_retrieval` | high | decidir boundary entre `Context/AtlasGraphRetrievalNetworkService` e `ProgrammingGraphRagRuntime` |
| `semantic_embedding` | high | decidir boundary entre `Context/AtlasSemanticEmbeddingFoundationService` e `Semantic/EmbeddingService` |
| `retrieval_feedback` | medium | garantir owner unico entre Context, Compounding, model e migration |
| `context_pack` | medium | garantir contrato unico entre builder, value object, store, model e migration |
| `local_rag` | medium | documentar como benchmark/readiness ou promover a runtime owner |
| `context_ranking_rerank` | medium | decidir boundary entre ranking de contexto e reranker de Programming |
| `open_brain` | review | documentar como surface/projection, nao memory paralela |
| `python_data_retrieval` | review | manter atras de runtime boundary Python e decision receipt |

Reachability manual dos itens principais confirmou: `Context` e o owner canonico
estao ativos, mas ha adapters/consumers ativos em `Programming`, `Semantic` e
`Compounding`. Portanto a limpeza segura e boundary/contrato, nao delete:
`AtlasGraphRetrievalNetworkService` e `AtlasSemanticEmbeddingFoundationService`
tem command, teste e owner doc; `ProgrammingGraphRagRuntime`,
`EmbeddingService`, `AtlasRagFeedbackService` e `ProgrammingContextPackStore`
tambem sao alcancaveis. O risco e IA escolher o runtime errado, nao codigo morto.

Contratos de direcao para limpeza RAG:

| Familia | Owner | Adapter/consumer | Proibido |
|---|---|---|---|
| `graph_retrieval` | `Context/AtlasGraphRetrievalNetworkService` | `ProgrammingGraphRagRuntime` | Programming virar segundo owner global |
| `semantic_embedding` | `Context/AtlasSemanticEmbeddingFoundationService` policy/manifest | `Semantic/EmbeddingService` primitive provider | Semantic bypassar policy/privacy/context ou gerar embedding pesado sem runtime receipt |
| `retrieval_feedback` | `Context/AtlasRetrievalFeedbackLoopService` event/ROI/learning contract | `Compounding/AtlasRagFeedbackService` persisted consumer | Compounding criar schema/owner paralelo |
| `context_pack` | `AiContextPackBuilder` composition contract | `ProgrammingContextPackStore` domain persistence/replay | Store redefinir contrato base de context pack |
| `local_rag` | `LocalRagReadinessService` | `LocalRagBenchmarkService` | Benchmark virar runtime canonico por acidente |
| `context_compiler_cache` | `AtlasContextCompilerRuntimeService` | `AtlasContextCacheCompilerRuntimeService` | cache compiler virar segundo owner de context compiler |
| `persistent_context` | `AtlasPersistentContextRuntimeService` | `AtlasPersistentContextPack` | redefinir contrato base de context pack ou Open Brain |
| `open_brain` | `Open Brain Context Injection` | MCP/projection adapters | Open Brain autorar docs ou sobrescrever docs canonicos |
| `python_data_retrieval` | `AtlasPythonDataRetrievalRuntimeService` | comando Laravel de invocacao | implementar RAG/vector/ML Python dentro de `app/Services` Laravel |
| `retrieval_eval` | `AtlasRetrievalEvaluationBenchmarkArenaService` | `ProgrammingRetrievalBenchmarkService` | benchmark virar runtime primario de retrieval |
| `agentic_rag` | `AtlasAgenticRagFrameworkService` | specs Programming Agentic RAG | criar store de memoria paralelo ou autoridade de contexto paralela |
| `hybrid_retrieval` | `AtlasHybridRetrievalInfrastructureService` | Unified Context Retrieval Intelligence | virar RAG isolado sem owner review de contexto |
| `memory_recall` | `AtlasMemoryRecallCommand` | `AtlasMemoryRecallController` | criar policy de retrieval ou store de memoria paralelo |
| `memory_governance_quality` | `AtlasMemoryQualityService` | Memory foundation/retrieval docs | usar metricas de qualidade como owner de retrieval/contexto |

Regra: familia RAG/retrieval sem contrato explicito e `owner_boundary_review_required`,
nunca convite para runtime novo. `rag_retrieval_cleanup_queue` traz owner,
adapter, reachability e `delete_allowed=false`; runtime pesado exige boundary.

`programming.frontend` tambem tem pressao alta: 147 matches de codigo, 6 familias
e 5 itens em `frontend_programming_cleanup_queue`. A fila fixa `private-benchmark-plan` canonico, `world-best-plan` alias legado, evidence-kit -> run-certify -> handoff, portfolio -> selected-workspace -> control-plane, live-source-patch como receipt owner e `delete_allowed=false`.

Overlaps literais de rota sao review: regex nao expande `Route::prefix()`, mas
`route:list` provou 0 duplicatas metodo+URI e 0 nomes duplicados. ACRUI gera
`runtime_route_alias_cleanup_queue`; mobile/base exige mesmo payload, auth e resposta.

Contratos de direcao para aliases de rota:

| Alias | Owner | Permitido | Proibido |
|---|---|---|---|
| Voice mobile/base API | `atlas-ai-voice-realtime-surface.md` | `/v1/mobile/ai/voice/*` chamar a mesma action da rota `/ai/voice/*` com mesmo auth/payload/resposta | logica mobile separada na mesma action sem wrapper/doc |
| Telemetry mobile/base API | `atlas-ai-telemetry-evidence-performance.md` | `/v1/mobile/telemetry/events` chamar a mesma action de `/ai/telemetry/events` | criar collector mobile paralelo ou schema de telemetry paralelo |
| Constelacao mobile/base API | `atlas-constelacao-surface.md` | `/v1/mobile/atlas/celestial/positions` chamar a mesma action de `/atlas/celestial/positions` | transformar alias mobile em surface ou domain separado |
| `AtlasCodeObservedSessionController@import` | `atlas-code-interactive-observed-provider-workflow-v1.md` | `import-result` so como alias da mesma action ate owner deprecar | terceiro endpoint de import sem decisao de owner |
| outros aliases | owner review | documentar compatibilidade ou merge | criar nova rota antes da revisao |

Todo alias intencional precisa provar mesma controller action, contrato
auth/payload/resposta, owner doc, `route:list` e ACRUI. Mesma action em varias
URLs e pressao de alias, nao duplicata metodo+URI; merge/delecao exige owner.

Fila de triagem inicial gerada pelo ACRUI:

| Item | Severidade | Decisao pendente |
|---|---:|---|
| `route_action_alias:AtlasCodeObservedSessionController@import` | medium | documentar alias `import` vs `import-result` ou unificar contrato |
| aliases mobile/base de Voice, Telemetry e Constelacao | low | documentar como alias intencional ou criar wrapper mobile |

Conclusao: nenhum grupo deve ser deletado por ausencia de uso. ACRUI emite `duplicate_class_cleanup_queue` apenas para grupos de producao/review; `SmokeSubject` fica documentado na triage como fixture gerado e nao entra na fila de cleanup de classe de producao. A fila mestra prioriza duplicacao de classe, RAG/retrieval, status drift, source-material sombra e legacy/scaffold com comandos de proxima prova.

Archive/source-material nao e owner atual: ACRUI emite `source_material_shadow_queue` para docs historicas que podem confundir retrieval/IA, especialmente RAG. Esses docs ranqueiam abaixo do owner canonico e so promovem conteudo por patch no owner. Stems repetidos como `README`, `contracts` e `runbook` entram em `doc_path_stem_boundary_queue`: sao familias por area, nao ids duplicados; IA deve ranquear por path+owner+frontmatter id.

Hotspots de legado/scaffold no codigo:

| Area | Sinais |
|---|---:|
| `app/Services/Ai` | 143 |
| `app/Console/Commands` | 35 |
| `app/Http/Controllers` | 12 |
| `app/Services/Engineering` | 7 |
| `database` | 7 |

Por tipo: `legacy=102`, `todo=44`, `scaffold=36`, `duplicate=32`,
`deprecated=9`, `executed_scaffold=1`, `planned_scaffold=1`. Esses sinais sao
fila de triagem, nao autorizacao de delete. `legacy_triage_queue` exige
reachability, deletion-preflight, owner doc e teste focado antes de cleanup, com
linha, excerto, fonte, bucket e subtipo operacional.

Contratos para status drift:

| Sinal | Significado | Proibido |
|---|---|---|
| `status_language_review_over_existing_evidence` | codigo, teste ou comando citado cria pressao de revisao sobre a doc | mudar frontmatter automaticamente ou declarar doc errada por heuristica |
| `planned_or_future_status_references_existing_dependency_boundary` | doc planejada/futura referencia runtime existente como dependencia ou boundary | declarar camada futura implementada so porque dependencia existe |
| `body_mixes_scaffold_and_implemented_language_with_code_evidence` | corpo mistura linguagem de scaffold e implementado com evidencia real de codigo | IA escolher status final sem owner doc, reachability e decisao de operador |

Regra operacional: status drift e fila de owner review. ACRUI deve carregar
`boundary_contract` em todos os itens para dizer que referencias de codigo sao
evidencia candidata, nao verdade final. A decisao segura e
`confirm_planned`, `mark_partial`, `mark_implemented` ou
`split_future_work_from_implemented_runtime`.

Dentro de `app/Services/Ai`, o ACRUI contou 2111 arquivos e 143 sinais. Os
maiores focos por subarea sao:

| Subarea | Arquivos | Sinais |
|---|---:|---:|
| `app/Services/Ai/Programming` | 448 | 39 |
| `app/Services/Ai/SelfConstruction` | 285 | 28 |
| `app/Services/Ai/Kernel` | 636 | 18 |
| `app/Services/Ai/Telemetry` | 33 | 11 |
| `app/Services/Ai/Vox` | 38 | 7 |

Por tema em `app/Services/Ai`: `runtime_orchestration=106` sinais,
`programming_forge=97`, `memory_rag_retrieval=73`, `self_construction=32`,
`voice_vox=26`. RAG/Memory exige `rag_retrieval_cleanup_queue`: owner,
adapter/consumer, bucket e sequencia antes de novo runtime ou rename.

Grupos de classe com mesmo nome curto encontrados pelo ACRUI:

| Nome curto | Paths |
|---|---|
| `smokesubject` | `app/Console/Commands/AtlasDevSeniorLoopAuditCommand.php`; `app/Console/Commands/AtlasDevDesktopRealSmokeCommand.php`; `app/Console/Commands/AtlasDevSeniorLoopRunCommand.php` |

Contratos de direcao para classes duplicadas:

| Classe | Owner | Variante | Proibido |
|---|---|---|---|
| `OperationEnvelope` | Resolvido como grupo duplicado: `Kernel/Envelope/OperationEnvelope` continua contrato Kernel; AtlasDev e SDD viraram classes reais explicitas com aliases compat | AtlasDev schema e SDD pipeline ficam domain-local | importar variante Programming como contrato Kernel |
| `FrontmatterParser` | Resolvido como grupo duplicado: `Services/Semantic` continua parser canonico; `Services/Vault/VaultNoteFrontmatterParser` virou classe real e `Services/Vault/FrontmatterParser` ficou alias compat | consolidar so com adapter que preserve erros e listas Vault | usar parser Vault como parser canonico de engineering docs |
| `SmokeSubject` | fixture gerado em workspace local | comandos smoke/senior-loop; fica fora de `duplicate_class_cleanup_queue` de producao | tratar como classe de dominio/producao |

Resolvidos: `AtlasKnowledgeIngestionFabricService` preserva runtime AUCRI em
`Ai/Context` e renomeia persistencia para `AtlasKnowledgeSourcePacketRegistryService`;
`AtlasTokenEconomyRuntimeService` preserva runtime AUCRI em `Ai/Context` e
renomeia policy para `AtlasTokenEconomyBudgetPolicyService`. FQCNs antigos
ficam apenas como aliases compat.
`AtlasUnifiedRealityGraphService` preserva runtime AUCRI em `Ai/Context` e renomeia builder in-memory para `AtlasRealityGraphSnapshotBuilderService`.
`AtlasCognitiveMemoryFabricService` preserva runtime AUCRI em `Ai/Context` e renomeia working-set policy para `AtlasCognitiveWorkingSetMemoryService`.
`AiExecutionPlan` preserva aliases antigos e promove classes reais explicitas: `PersistentAiExecutionPlan` para a tabela `ai_execution_plans` e `AiPromptExecutionPlan` para payload de prompt.
`VerificationCommandRunner` preserva aliases antigos e promove classes reais explicitas: `AtlasCodeVerificationCommandRunner` para evidence `atlas.code.verification_run.v1` e `AtlasDevVerificationCommandRunnerContract` para o gate AtlasDev.
`OperationEnvelope` preserva o contrato Kernel real em `Kernel/Envelope/OperationEnvelope` e promove classes reais explicitas para variantes locais: `AtlasDevOperationEnvelope` e `SddPipelineOperationEnvelope`. FQCNs antigos das variantes Programming ficam apenas como aliases compat.

Schemas conhecidos de `OperationEnvelope`:

| Variante | Schema | Regra |
|---|---|---|
| Kernel | `atlas.envelope.v1` | contrato Kernel, dono de `kernel/contracts.md` |
| Atlas Dev | `atlas.dev.operation_envelope.v1` | DTO local real em `AtlasDevOperationEnvelope`; FQCN antigo `AtlasDev/Schemas/OperationEnvelope` fica somente como compatibility alias |
| SDD pipeline | DTO local sem schema Kernel | DTO local real em `SddPipelineOperationEnvelope`; FQCN antigo `Sdd/Pipeline/OperationEnvelope` fica somente como compatibility alias |

Contratos conhecidos de `VerificationCommandRunner`:

| Variante | Uso permitido | Proibido |
|---|---|---|
| `Services/AtlasCode/AtlasCodeVerificationCommandRunner` | servico concreto real `atlas.code.verification_run.v1`; FQCN antigo `Services/AtlasCode/VerificationCommandRunner` fica somente como alias compat | usar como interface injetavel do gate AtlasDev |
| `Ai/Programming/AtlasDev/Gate/AtlasDevVerificationCommandRunnerContract` | interface real do `VerificationGate`; FQCN antigo `Ai/Programming/AtlasDev/Gate/VerificationCommandRunner` fica somente como alias compat | substituir o runner AtlasCode ou escrever evidence AtlasCode diretamente |

Contratos conhecidos de `SmokeSubject`:

| Variante | Uso permitido | Proibido |
|---|---|---|
| `src/SmokeSubject.php` gerado em workspace local | fixture temporario de comandos AtlasDev smoke/senior-loop; nunca criar classe repo de producao para ele | tratar como classe de dominio, model, rota, provider runtime ou feature real do Atlas |
| `tests/SmokeSubjectTest.php` gerado em workspace local | teste local do fixture; prova patch/verificacao do smoke, nao feature Atlas implementada | usar como evidencia de feature implementada no repo principal |

Ordem segura de limpeza para classes com mesmo nome curto:

| Prioridade | Grupo | Decisao segura |
|---:|---|---|
| resolvido | `OperationEnvelope` | Kernel preservado como contrato real; variantes Programming viraram classes reais explicitas com aliases compat |
| resolvido | `FrontmatterParser` | classe Vault renomeada para `VaultNoteFrontmatterParser`; manter alias compat ate nao haver consumers externos |
| resolvido | `VerificationCommandRunner` | classes reais explicitas; FQCNs antigos viraram aliases compat |
| resolvido | `AiExecutionPlan` | classes reais explicitas; FQCNs antigos viraram aliases compat |
| fora da cleanup queue | `SmokeSubject` | manter como fixture gerado; extrair template comum so com owner decision AtlasDev |

Em todos os grupos acima, `delete_allowed=false` ate reachability, owner doc,
testes e decisao humana provarem merge, rename ou quarantine.

## Dependencias
- `EngineeringDocumentationAuthorityAuditService`
- `AtlasCodeRealityUsageIntelligenceService`
- `AtlasDocumentationEnforcementService`
- `AtlasFeaturePlacementService`
- `EngineeringDocumentationHealthService`

## Evidencias
Comandos usados para este snapshot:

```bash
php artisan atlas:ai:docs-authority-audit --json
php artisan atlas:code-reality reality-audit --json
php artisan atlas:code-reality global-duplication-audit --json
php artisan atlas:code-reality status-drift-audit --json
php artisan route:list --json
php artisan atlas:code-reality anti-duplicate --feature="documentation and code duplication governance audit" --json
php artisan atlas:engineering:knowledge docs-health --json
```

## Riscos
- ACRUI global-duplication-audit e candidato conservador; ele nao decide que
  codigo e morto, nao autoriza delecao e nao prova equivalencia semantica final.
- Read models podem ficar stale se `sync --prune` e `index-code --prune` nao
  rodarem depois de alteracoes.
- Source material arquivado pode parecer duplicado, mas nao e canonico.

## Exemplos
Se a IA quer criar "novo runtime de documentacao":

```text
ADER ready -> place-feature aponta documentation-governance -> ACRUI match
review -> extender ADER/ACRUI/ADRS em vez de criar runtime paralelo
```

Se a doc diz `planned`, mas existe comando com teste:

```text
ACRUI reachability high -> doc entra em drift -> atualizar status/evidence
antes de usar a doc como contexto para outra IA
```

## Proximas Acoes
1. Triar os 2 grupos de classes duplicadas por owner: reuse, boundary, supersede ou merge.
2. Revisar os 7 overlaps de stem de doc ativo para separar indices/familias
   intencionais de docs concorrentes.
3. Confirmar os 30 runtime action aliases com `php artisan route:list`,
   decidindo se cada um e alias intencional, wrapper mobile ou fluxo paralelo
   confuso.
4. Triar RAG/retrieval primeiro: 27 source-material hits, 596 code matches e 15
   familias precisam ser separados em owner canonico, read model, legacy,
   runtime governado ou surface alias.
5. Revisar os 569 sinais legado/scaffold com reachability antes de qualquer
   cleanup claim.
6. Fazer ADER consumir o global-duplication-audit quando a politica de bloqueio
   global estiver aceita pelo operador.
7. Triar os 3 docs `planned/future` com evidencia de codigo existente e reduzir
   a fila de 110 pressoes de linguagem por owner doc.
