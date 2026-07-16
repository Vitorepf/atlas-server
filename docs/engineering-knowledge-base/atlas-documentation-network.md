---
doc_schema: atlas_canonical_module_doc.v1
id: atlas-documentation-network
type: engineering_knowledge
title: Atlas Documentation Network (ADN) — rede documentacional federada por repositório
status: active
implementation_state: backlog_partial_registry_ready
owner: operator (Vitor)
priority: 92
category: knowledge
summary: >-
  Contrato canônico para o Atlas operar N repositórios/empresas: cada repo tem
  um "canto de docs" padronizado (docs/engineering-knowledge-base/ com
  frontmatter atlas_canonical_module_doc.v1) que o ACOS ingere por
  workspace_id, formando uma rede federada — não um corpus concatenado.
  Diagnóstico 2026-07-16: o pipeline (identidade de workspace, retrieval do
  context pack, AKIF, code intelligence poliglota) JÁ é federável; os
  bloqueios são o docsRoot hardcoded do KB sync, a tabela de KB sem
  workspace_id, o gate do index-code para workspaces não-registrados e a
  ausência de perfis por repo.
when_to_use:
  - Onboarding de um novo repositório/empresa no Atlas
  - Qualquer mudança em EngineeringKnowledgeBaseService, index-code ou AKIF
  - Dúvida sobre onde um repo deve escrever documentação que o Atlas ingere
trigger_signals:
  - rede documentacional
  - multi-repo
  - docs por workspace
  - knowledge federation
tags: [acos, knowledge, federation, workspaces, akif]
repo_paths:
  - app/Services/Engineering/EngineeringKnowledgeBaseService.php
  - app/Services/Engineering/CodeGraph/CodeGraphWorkspaceIdentity.php
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspacePathResolverService.php
  - app/Services/Ai/Knowledge/AtlasKnowledgeSourcePacketRegistryService.php
  - config/atlas_projects.php
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/atlas-cognition-operating-system.md
  - docs/engineering-knowledge-base/atlas-knowledge-ingestion-fabric.md
  - docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md
depends_on:
  - atlas-ai-knowledge-governance-system
  - atlas-cognition-operating-system
allowed_changes:
  - Federar docsRoot por perfil (docs_roots) mantendo o default atual
  - Adicionar workspace_id a atlas_engineering_knowledge_items (migration aditiva)
forbidden_changes:
  - Corpus único sem workspace_id (docs de repos distintos colidindo por slug)
  - Ingestão de docs sem frontmatter validado (lixo na rede)
  - Relaxar provider-safety do pack para "enriquecer" a rede
next_actions:
  - F2 docs_roots por perfil + sync por workspace
  - F3 migration workspace_id na KB + prune por workspace
  - F4 gate do index-code + verificação do grammar swift no runtime tree-sitter
  - F5 binding AKIF no workspace activate
quality_gates:
  - PHPUnit red→green por fase
  - atlas:engineering:knowledge docs-health --json verde
requires_evidence: true
---

# Atlas Documentation Network (ADN)

## Tese

O Atlas vai operar N repositórios de N empresas. Cada repositório tem UM canto
padronizado de documentação canônica — **`<repo>/docs/engineering-knowledge-base/`**
com frontmatter `atlas_canonical_module_doc.v1` — e o ACOS ingere cada canto
**escopado por `workspace_id`**, formando uma rede federada: os docs de um
repo alimentam o context pack daquele workspace; o umbrella agrega; nada
colide por slug entre empresas; privacy/provider-safety por construção.

Regra-mãe (herdada do Knowledge Governance): **docs no repo são a fonte
autoral; Postgres KB e Code Intelligence são read models.** A rede não muda
isso — ela multiplica a regra por N repos.

## Diagnóstico verificado (2026-07-16)

**Já federável (não tocar, já é a fundação):**
- Identidade estável por repo: `CodeGraphWorkspaceIdentity` (perfil → primário
  → git-remote slug → basename+hash; monorepo `base::sub`).
- Registry de perfis config-driven: `config/atlas_projects.php` +
  `AtlasCodeWorkspaceProfileService` (config ∪ tabela `atlas_workspace_profiles`).
- Context pack inteiramente chaveado por `workspace_id` (code/reality/memory/
  retomada), com scope umbrella opcional (AP-818 F2.5) e honest-empty.
- Code intelligence poliglota: `EngineeringCodeIntelligenceService::EXTENSIONS`
  já inclui swift/rust/go/kotlin etc. (tree-sitter).
- AKIF: `AtlasKnowledgeSourcePacket` com `source_type` `repo|url|doc|...`,
  lineage, privacy e quarentena — substrato pronto, hoje órfão.

**Bloqueios reais (a rede não existe por causa destes):**
1. `EngineeringKnowledgeBaseService::docsRoot()` retorna
   `base_path('docs/engineering-knowledge-base')` fixo — o sync só ingere o
   atlas-server.
2. `atlas_engineering_knowledge_items` não tem `workspace_id` — docs de N
   repos colidiriam por slug num corpus único.
3. `AtlasWorkspacePathResolverService::resolveForExecution()` bloqueia
   workspace sem perfil (`workspace_not_registered`) — repos não registrados
   nunca são indexados (era o caso do atlas-native até 2026-07-16).
4. Ausência do binding AKIF ↔ perfil: nada registra o canto de docs de um
   repo como fonte com lineage.
5. Grammar tree-sitter por linguagem precisa existir no runtime — ausência
   degrada para 0 símbolos em silêncio (verificar swift explicitamente).

## O padrão ouro por repositório (contrato do "canto")

Todo repo operado pelo Atlas DEVE ter:

```
<repo>/docs/engineering-knowledge-base/
  <repo>-overview.md          ← obrigatório: entrada canônica (piloto: atlas-native-overview)
  <dominio>.md ...            ← um doc canônico por domínio relevante
```

Frontmatter mínimo (validado pelo `FrontmatterParser`): `id`, `type`,
`title`, `status`, `summary` + pelo menos um de `when_to_use[]` /
`trigger_signals[]`. Recomendado (schema de facto do corpus):
`implementation_state`, `owner`, `priority`, `repo_paths[]`,
`related_paths[]`, `depends_on[]`, `forbidden_changes[]`, `quality_gates[]`.
Docs sem frontmatter válido NÃO entram na rede (gate de qualidade da
ingestão — lixo documentado é pior que ausência).

E o repo DEVE ter perfil em `config/atlas_projects.php` (ou na tabela via
ativação) com `workspace_path`, `code_index_roots`, `critical_areas`,
`docs_status` e comandos de gate.

## Plano de implementação (fases, cada uma com PHPUnit red→green)

- **F1 · Registro (FEITO 2026-07-16):** perfil `atlas-native` adicionado a
  `config/atlas_projects.php` + `atlas-native` no `code_index_roots` do
  umbrella `atlas`. Piloto do canto criado:
  `atlas-native/docs/engineering-knowledge-base/atlas-native-overview.md`.
- **F2 · docsRoot federado:** novo campo `docs_roots[]` por perfil (default
  `['docs/engineering-knowledge-base']`, relativo ao `repo_root`);
  `EngineeringKnowledgeBaseService::sync(workspace?)` itera os cantos dos
  perfis registrados; sem perfil → não ingere (rede é opt-in explícito).
- **F3 · KB por workspace:** migration aditiva `workspace_id` (default
  `atlas-server` para o corpus atual — zero breaking) em
  `atlas_engineering_knowledge_items`; catálogo, recall e `--prune`
  chaveados por workspace; umbrella consulta o conjunto dos membros.
- **F4 · Index-code destravado:** com o perfil registrado (F1), o gate
  `workspace_not_registered` liberou, mas o AWIS bloqueia no passo seguinte
  (verificado 2026-07-16): `awis_execution_boundaries_audited` falha porque
  `process_inventory_unclassified > 0` — os processos/comandos do
  atlas-native (swift/xcodebuild/make) ainda não estão classificados nas
  fronteiras de execução (seção 7 do envelope AWIS; 15/16 checks passam).
  F4 = classificar o inventário de processos do workspace (via o fluxo AWIS
  canônico) e então rodar
  `atlas:engineering:knowledge index-code --workspace=<path>`; em seguida
  verificar grammar `swift` no runtime tree-sitter
  (`CodeGraphRuntimeInvoker` op `treesitter`) com smoke real de 1 arquivo
  Swift — ausência de grammar deve virar erro DITO, nunca 0 símbolos
  silencioso.
- **F5 · AKIF binding:** `atlas:aobg:workspace activate` registra o canto do
  repo como `AtlasKnowledgeSourcePacket` (`source_type=repo`, `origin_uri` =
  git remote) — lineage e provider-safety da rede.

## O que a rede entrega quando fechada

Abrir qualquer workspace → o context pack daquele repo carrega code-graph
(símbolos da linguagem do repo), memory escopada, E os docs canônicos DO
PRÓPRIO repo — com o umbrella agregando a visão Atlas-inteira. Empresas
diferentes = workspaces diferentes = cantos diferentes = zero vazamento
cruzado, por construção.
