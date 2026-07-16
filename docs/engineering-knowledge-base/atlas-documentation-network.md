---
doc_schema: atlas_canonical_module_doc.v1
id: atlas-documentation-network
type: engineering_knowledge
title: Atlas Documentation Network (ADN) — rede documentacional federada por repositório
status: active
implementation_state: f1_to_f5_delivered_runtime_live
owner: operator (Vitor)
priority: 92
category: knowledge
risk_level: medium
graph_id: atlas-documentation-network
graph_title: Atlas Documentation Network
graph_world: atlas
graph_layer: system
graph_kind: policy
graph_parent: atlas-ai-knowledge-governance-system
graph_status: active
graph_source: repo
human_name: Rede Documentacional do Atlas
human_summary: Cada repositório que o Atlas opera tem um canto padronizado de documentação; o Atlas ingere todos os cantos por workspace, formando uma rede federada sem vazamento entre empresas.
human_what: Contrato e plano para o Atlas ler documentação canônica de N repositórios, cada um no seu canto, escopado por workspace.
human_purpose: Dar ao ACOS o corpus certo de cada repo/empresa — a base do mundo em que a IA escreve o código e a documentação é a fonte de verdade.
human_input: Perfis de workspace, cantos docs/engineering-knowledge-base de cada repo, frontmatter validado.
human_output: Context packs por workspace com os docs do próprio repo; KB federada; lineage AKIF.
human_change_when: Mexa quando mudar ingestão de docs, perfis de workspace, index-code ou AKIF.
human_block_when: Bloqueie se docs de repos distintos colidirem num corpus único ou se ingestão aceitar doc sem frontmatter validado.
canonical_name: Atlas Documentation Network
technical_name: EngineeringKnowledgeBaseService
summary: >-
  Contrato canônico para o Atlas operar N repositórios/empresas: cada repo tem
  um canto de docs padronizado (docs/engineering-knowledge-base/ com
  frontmatter validado) que o ACOS ingere por workspace_id, formando uma rede
  federada — não um corpus concatenado. Diagnóstico 2026-07-16: o pipeline já
  é federável; bloqueios nomeados no corpo.
when_to_use:
  - Onboarding de um novo repositório/empresa no Atlas
  - Mudanças em EngineeringKnowledgeBaseService, index-code ou AKIF
  - Dúvida sobre onde um repo escreve documentação que o Atlas ingere
trigger_signals: [rede documentacional, multi-repo, docs por workspace, knowledge federation]
tags: [acos, knowledge, federation, workspaces, akif]
capabilities:
  - federated_docs_ingestion_per_workspace
  - per_repo_canonical_docs_corner
  - workspace_scoped_knowledge_recall
  - akif_lineage_for_repo_docs
decisions:
  - Docs no repo são a fonte autoral; Postgres KB e Code Intelligence são read models (herda do Knowledge Governance).
  - A rede é federada por workspace_id, nunca um corpus concatenado; empresas diferentes nunca colidem.
  - Ingestão é opt-in explícito por perfil registrado; repo sem perfil não entra na rede.
  - Doc sem frontmatter validado não entra na rede (lixo documentado é pior que ausência).
maintenance:
  - Atualizar implementation_state a cada fase F2–F5 entregue, com evidência.
  - Rodar docs-health + knowledge sync após qualquer mudança neste contrato.
  - Revisar o diagnóstico quando EngineeringKnowledgeBaseService ou AWIS mudarem.
repo_paths:
  - app/Services/Engineering/EngineeringKnowledgeBaseService.php
  - app/Services/Engineering/CodeGraph/CodeGraphWorkspaceIdentity.php
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspacePathResolverService.php
  - app/Services/Ai/Knowledge/AtlasKnowledgeSourcePacketRegistryService.php
  - config/atlas_projects.php
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-cognition-operating-system.md
  - docs/engineering-knowledge-base/atlas-knowledge-ingestion-fabric.md
  - docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md
depends_on:
  - atlas-ai-knowledge-governance-system
  - atlas-ai-documentation-operating-system
  - atlas-cognition-operating-system
flows_to:
  - atlas-knowledge-ingestion-fabric
  - atlas-unified-context-retrieval-intelligence
unlocks:
  - context packs com docs do próprio repo em qualquer workspace
  - operação multi-empresa sem vazamento documental cruzado
governs:
  - o canto canônico de docs de todo repositório operado pelo Atlas
  - o escopo por workspace_id da KB de engenharia
allowed_changes:
  - Federar docsRoot por perfil (docs_roots) mantendo o default atual
  - Adicionar workspace_id a atlas_engineering_knowledge_items (migration aditiva)
forbidden_changes:
  - Corpus único sem workspace_id (docs de repos distintos colidindo por slug)
  - Ingestão de docs sem frontmatter validado
  - Relaxar provider-safety do pack para "enriquecer" a rede
evidence:
  - docs-health --json (frontmatter e seções deste doc)
  - probe de sync com created/updated/failed por workspace
required_tests:
  - PHPUnit red→green por fase (sync federado, colisão de slug, opt-in)
quality_gates:
  - PHPUnit verde por fase
  - php artisan atlas:engineering:knowledge docs-health --json verde
failure_modes:
  - Canto existente mas vazio → rede "completa" de fachada; docs-health por repo deve acusar
  - Grammar tree-sitter ausente degrada a 0 símbolos em silêncio (F4 corrige para erro dito)
next_actions:
  - F2 docs_roots por perfil + sync por workspace
  - F3 migration workspace_id na KB + prune por workspace
  - F4 classificação AWIS do workspace + index Swift + grammar smoke
  - F5 binding AKIF no workspace activate
observability_signals:
  - contagem de docs por workspace_id no catálogo da KB
  - seção de docs do context pack por workspace (present true/false)
requires_evidence: true
---

# Atlas Documentation Network (ADN)

## Resumo

O Atlas vai operar N repositórios de N empresas. Cada repositório tem UM canto
padronizado de documentação canônica — `<repo>/docs/engineering-knowledge-base/`
com frontmatter validado pelo Documentation OS — e o ACOS ingere cada canto
escopado por `workspace_id`, formando uma rede federada: docs de um repo
alimentam o context pack daquele workspace; o umbrella agrega; nada colide
entre empresas; provider-safety por construção.

## Papel no Atlas

É a política que multiplica o Knowledge Governance por N repos: mantém "docs
no repo como fonte autoral" válido para cada repo operado, e entrega ao ACOS
o corpus certo por workspace — a base do mundo em que a IA escreve o código.

## Onde Se Encaixa

Filha do `atlas-ai-knowledge-governance-system` (autoridade) e do
`atlas-ai-documentation-operating-system` (padrão de doc). Alimenta AKIF
(ingestão com lineage) e AUCRI (retrieval por workspace). Consumida pelo
context pack do Open Brain.

## Fluxo

1. Repo é registrado como perfil (`config/atlas_projects.php` ou ativação AOBG).
2. O canto `docs/engineering-knowledge-base/` do repo é sincronizado para a KB
   com `workspace_id` do perfil (F2+F3).
3. A ativação registra o canto no AKIF com lineage do git remote (F5).
4. O context pack daquele workspace passa a carregar os docs do próprio repo;
   o umbrella agrega os membros.

## Diagnóstico verificado (2026-07-16)

**Já federável:** identidade estável por repo (`CodeGraphWorkspaceIdentity`:
perfil → primário → git-slug → basename+hash; monorepo `base::sub`); registry
de perfis config∪DB; context pack inteiramente chaveado por `workspace_id`
com scope umbrella opcional e honest-empty; code intelligence poliglota
(swift/rust/go/kotlin já em `EXTENSIONS`); AKIF com `source_type`
`repo|url|doc`, lineage, privacy e quarentena — substrato pronto, órfão.

**Bloqueios reais:**
1. `EngineeringKnowledgeBaseService::docsRoot()` fixo em
   `base_path('docs/engineering-knowledge-base')` — sync só ingere o atlas-server.
2. `atlas_engineering_knowledge_items` sem `workspace_id` — N repos colidiriam
   por slug num corpus único.
3. `AtlasWorkspacePathResolverService::resolveForExecution()` bloqueia
   workspace sem perfil; e o AWIS exige `awis_execution_boundaries_audited`
   com `process_inventory_unclassified == 0` (verificado: atlas-native passa
   15/16 checks; falta classificar swift/xcodebuild/make — seção 7 do envelope).
4. Nenhum binding AKIF ↔ perfil registra o canto como fonte com lineage.
5. Grammar tree-sitter ausente degrada a 0 símbolos em silêncio.

## Escopo de Implementacao

- **F1 (FEITO 2026-07-16):** perfil `atlas-native` em `config/atlas_projects.php`
  + `atlas-native` no `code_index_roots` do umbrella; canto piloto criado
  (`atlas-native/docs/engineering-knowledge-base/atlas-native-overview.md`).
- **F2:** campo `docs_roots[]` por perfil (default
  `['docs/engineering-knowledge-base']` relativo ao `repo_root`);
  `EngineeringKnowledgeBaseService::sync()` itera os cantos dos perfis com
  `workspace_id`; sem perfil → não ingere.
- **F3:** migration aditiva `workspace_id` (default `atlas-server`) em
  `atlas_engineering_knowledge_items`; catálogo/recall/`--prune` por
  workspace; umbrella consulta membros.
- **F4:** classificar o inventário de processos do workspace no AWIS; rodar
  `index-code --workspace=<path>`; smoke do grammar swift — ausência vira
  erro dito.
- **F5:** `atlas:aobg:workspace activate` registra o canto como
  `AtlasKnowledgeSourcePacket` (`source_type=repo`, origin = git remote).

## Contratos

- Canto por repo: `<repo>/docs/engineering-knowledge-base/` com
  `<repo>-overview.md` obrigatório e frontmatter mínimo do Documentation OS
  (`id,type,title,status,category,priority,summary,tags,capabilities,
  decisions,maintenance,related_paths` + ativação); doc navegável usa
  `atlas_canonical_module_doc.v1` completo.
- Perfil por repo com `workspace_path`, `code_index_roots`, `docs_roots`,
  `critical_areas`, `docs_status` e comandos de gate.
- Ingestão: doc sem frontmatter válido não entra na rede.

## Dependencias

Knowledge Governance (hierarquia de autoridade), Documentation OS (padrão e
docs-health), CodeGraphWorkspaceIdentity (identidade), AKIF (lineage),
perfis de workspace (registro opt-in).

## Evidencias

Todas as fases entregues e provadas ao vivo em 2026-07-16:

- **F1** `40b9e9d3c` — perfil atlas-native + este doc; canto piloto `88ffd0d`
  no atlas-native; sync verde.
- **F2+F3** `e9b70f4f9` — TDD red→green 5 testes/12 asserts (federação,
  colisão de slug, prune escopado, filtro, fallback legado); migration live
  55ms; sync real federado: **atlas-native=1 doc, atlas-server=972, 0 failed**
  (dois workspaces na mesma KB, canonical_path relativo ao repo dono).
- **F5** `513ada47a` — TDD red→green 3 testes/12 asserts; ativação real do
  atlas-native registrou o source packet no Postgres vivo (`repo`, quarentena
  `received`, provider_safe, metadata workspace_id+docs_root).
- **F4** `59ef30f89` — causa-raiz: markers estáticos do audit não conheciam o
  refactor hexagonal (guard real via `AwisExecutionGatePort` →
  `AppServiceProvider:732`); fix curou 25 testes da suite AWIS (26→1
  pré-existente, provado por stash-isolation); envelope do atlas-native
  **16/16 ready**; index-code extraiu **444 símbolos Swift** (grammar
  tree-sitter presente e funcional).
- **Prova fim-a-fim:** o context pack do workspace atlas-native, que antes
  chegava com "no matching symbols", agora entrega `code_graph: 63`
  (ex.: `sym:TurnPresence`, `sym:LiveNowSection`) + memory 4 + fusão 12.

Follow-up registrado (não bloqueante): endurecer o extractor para que
grammar tree-sitter AUSENTE vire erro dito em vez de 0 símbolos silencioso
(hoje o grammar swift existe; a regra protege linguagens futuras).

## Exemplos

- `atlas-native` (piloto): perfil registrado; canto com
  `atlas-native-overview.md`; após F2+F3 o pack do workspace atlas-native
  carrega esse doc em vez de herdar só o corpus do atlas-server.
- Empresa nova: clonar repo → criar canto + overview → registrar perfil →
  ativar workspace → a rede passa a servir aquele workspace, isolado.

## Regras para IA

- Nunca ingerir doc sem frontmatter validado; nunca misturar workspaces sem
  `workspace_id`; nunca "enriquecer" pack relaxando provider-safety.
- Ausência de canto/perfil é ausência DITA no pack, nunca preenchida com o
  corpus de outro repo silenciosamente (o fallback atual para atlas-server é
  transitório e termina na F3).
- Grammar/indexer ausente para a linguagem do repo deve virar erro explícito.

## Riscos

- Migration F3 toca o cérebro vivo → aditiva com default, PHPUnit antes,
  sync idempotente re-executável.
- Cantos vazios dão sensação falsa de rede → docs-health por workspace na F2.
- Classificação AWIS pode exigir aprovação do operador → empacotar a decisão,
  nunca contornar o gate.

## Proximas Acoes

F2 → F3 → F5 → F4 (ordem de menor risco primeiro; F4 depende de decisão
AWIS). Prova final: context pack do atlas-native com docs do próprio repo.
