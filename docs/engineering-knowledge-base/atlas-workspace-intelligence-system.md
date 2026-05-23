---
id: atlas-workspace-intelligence-system
type: engineering_knowledge
title: Atlas Workspace Intelligence System
status: building
category: workspace-intelligence
priority: 100
implementation_state: runtime_gate_snapshot_persisted_registry_and_edit_contract_present
summary: Sistema canonico que torna Project/Workspace ativo obrigatorio para Atlas Dev, Forge, memoria, contexto, index-code e execucao por IA.
tags:
  - atlas
  - workspace
  - atlas-dev
  - atlas-forge
  - cartography
  - memory
  - execution-safety
capabilities:
  - mandatory_workspace_binding
  - workspace_memory_isolation
  - workspace_readiness_gate
  - workspace_context_pack
  - project_scoped_cartography
  - workspace_command_registry
  - workspace_execution_memory
  - conversation_fusion_workspace
  - continuity_intelligence_os
  - current_truth_pack
  - raw_conversation_archive
  - workspace_artifact_fabric
  - artifact_intelligence_runtime
decisions:
  - Sem workspace ativo, Atlas pode conversar, mas nao pode executar Dev, Forge, patch, teste, index-code, provider patch ou memoria operacional.
  - Workspace e a unidade de realidade operacional; Obra, conversa, run, memoria e Cartografia precisam apontar para um workspace.
  - Fixar projeto e estado operacional real, nao decoracao visual.
  - Memoria, contexto, outcomes, comandos e riscos nunca devem vazar entre workspaces.
  - Conversa longa e materia-prima bruta; nunca deve ser enviada inteira como contexto principal para provider.
  - A verdade atual do workspace vence historico de conversa, resumo antigo, projection e memoria derivada.
  - AWIS e camada macro; a implementacao deve ser incremental e fail-closed.
maintenance:
  - Atualizar antes de mudar seletor de projeto, Atlas Dev, Forge, Cartografia, index-code, memoria operacional ou workspace registry.
  - Manter abaixo de 520 linhas e dividir specs filhas quando iniciar implementacao.
  - Rodar docs-health apos alterar.
related_paths:
  - docs/engineering-knowledge-base/atlas-workspace-artifact-fabric.md
  - docs/engineering-knowledge-base/atlas-workspace-artifact-intelligence-runtime.md
  - docs/engineering-knowledge-base/atlas-workspace-contract-orchestrator.md
  - docs/engineering-knowledge-base/atlas-workspace-twin-runtime.md
  - docs/engineering-knowledge-base/atlas-workspace-evolution-fabric.md
  - docs/engineering-knowledge-base/atlas-continuity-intelligence-os.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-code-multi-project-workspace-os.md
  - docs/engineering-knowledge-base/atlas-ai-conversation-surface-and-atlas-dev-v1.md
  - docs/engineering-knowledge-base/atlas-code-programming-obras-operating-system.md
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
  - docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/atlas-ai-session-bootstrap.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-workspace-intelligence-system
graph_title: Atlas Workspace Intelligence System
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-code-multi-project-workspace-os
graph_status: planned
graph_source: repo
macro_layer: true
product_name: Atlas Workspace Intelligence System
runtime_acronym: AWIS
internal_product_name: Atlas Project Command
technical_runtime: AtlasWorkspaceIntelligenceRuntime
human_name: Atlas Workspace Intelligence System
canonical_name: Atlas Workspace Intelligence System
technical_name: AtlasWorkspaceIntelligenceRuntime
cartography_type: system
canonical_source: docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md
owner: workspace-intelligence
repo_paths:
  - docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md
allowed_changes:
  - Evoluir schema de workspace, readiness, context pack e command registry.
  - Adicionar paths reais de services, migrations, comandos e testes quando implementados.
forbidden_changes:
  - Permitir execucao Dev/Forge sem workspace ativo.
  - Tratar conversa, Obra ou provider session como substituto de workspace.
  - Misturar memoria ou outcomes entre projetos.
  - Rodar index-code sem workspace definido.
depends_on:
  - atlas-code-multi-project-workspace-os
  - atlas-ai-session-bootstrap
  - atlas-code-reality-usage-intelligence
  - atlas-cartographic-knowledge-os
flows_to:
  - atlas-dev
  - atlas-forge
  - atlas-cartography
  - atlas-code-intelligence
unlocks:
  - project-scoped-memory
  - project-scoped-context-pack
  - project-scoped-cartography
  - safer-provider-handoffs
governs:
  - atlas_dev.workspace_binding
  - atlas_forge.workspace_binding
  - atlas_cartography.project_scope
  - atlas_memory.workspace_scope
  - atlas_code_intelligence.workspace_scope
evidence:
  - docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceRuntimeService.php
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceExecutionGateService.php
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceExecutionBoundaryAuditService.php
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspacePathResolverService.php
  - app/Http/Controllers/AtlasCodeWorkspaceController.php
  - app/Http/Controllers/AtlasWorkspaceIntelligenceController.php
  - app/Console/Commands/AtlasWorkspaceIntelligenceCommand.php
  - app/Models/AtlasWorkspaceIntelligenceSnapshot.php
  - app/Models/AtlasWorkspaceArtifactLakeEntry.php
  - app/Models/AtlasWorkspaceArtifactGraphSnapshot.php
  - app/Models/AtlasWorkspaceProfile.php
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceSnapshotRepository.php
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceArtifactIntelligenceRepository.php
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceArtifactShadowExecutionService.php
  - database/migrations/2026_05_25_020000_create_atlas_workspace_intelligence_snapshots.php
  - database/migrations/2026_05_25_021000_create_atlas_workspace_artifact_intelligence_tables.php
  - database/migrations/2026_05_25_022000_create_atlas_workspace_profiles.php
  - tests/Feature/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceRuntimeServiceTest.php
  - tests/Feature/Ai/Programming/AtlasForgeRuntimeDispatchTest.php
  - tests/Feature/AtlasCodeWorkspaceProfileTest.php
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
  - php artisan test tests/Feature/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceRuntimeServiceTest.php
  - php artisan test tests/Feature/AtlasCodeContractTest.php --filter='diff_apply_requires_awis_workspace_before_queueing_run'
  - php artisan atlas:workspace-intelligence boundary-audit --json --strict
requires_evidence: true
risk_level: critical
visual_tags:
  - workspace
  - project
  - execution-gate
  - memory-isolation
ai_entrypoints:
  - Leia este doc antes de implementar seletor de pasta, workspace registry, Dev/Forge binding, memoria por projeto ou Cartografia por projeto.
ai_usage_notes:
  - Se nao houver workspace ativo, proponha conversa ou selecao de projeto; nao implemente execucao.
quality_gates:
  - docs-health
  - atlas:workspace-intelligence --json --strict
failure_modes:
  - IA executar no repo errado.
  - Memoria de um cliente influenciar outro projeto.
  - Cartografia mostrar mundo errado para o projeto ativo.
  - Workspace fixado virar apenas preferencia visual.
observability_signals:
  - active_workspace_id
  - workspace_root_hash
  - workspace_readiness_status
  - execution_boundary_audit_hash
  - workspace_intelligence_snapshots_total
  - workspace_intelligence_blocked
  - workspace_intelligence_workspaces_total
  - workspace_intelligence.by_family
  - workspace_intelligence.latest.projection_hash
  - pinned_workspace_ids
  - workspace_memory_scope
  - current_truth_pack_hash
  - continuity_graph_hash
  - conversation_archive_hash
next_actions:
  - Conectar Cartografia visual ao Workspace Registry persistido.
  - Projetar AWAIR artifact graph na Cartografia.
  - Manter replay `latest=1` fail-closed quando `workspace_hash` divergir.
---
# Atlas Workspace Intelligence System
## Resumo
**Nome canonico / produto:** Atlas Workspace Intelligence System  
**Acronimo tecnico:** AWIS  
**Nome interno de experiencia / superficie:** Atlas Project Command  
**Runtime tecnico:** `AtlasWorkspaceIntelligenceRuntime`

AWIS ja possui runtime read-only/certificacao inicial. Ele transforma "escolher
uma pasta" em contrato operacional: projeto ativo, regras, memoria, comandos e
contexto antes de qualquer execucao por IA.

Esta e a documentacao mae do espaco de trabalho do Atlas. Ela governa
workspace obrigatorio, memoria/contexto por projeto, fusao de conversas longas,
continuidade operacional, current truth pack, Cartografia por projeto e
provider/subagente com contexto minimo verificavel.

Regra central:

```text
Sem workspace ativo: Atlas pode conversar.
Sem workspace ativo: Atlas nao pode executar Dev, Forge, patch, teste,
index-code, provider patch ou memoria operacional.
```

## Papel no Atlas

Ferramentas como Cursor, Windsurf, Codex e Claude Code geralmente exigem uma
pasta aberta porque isso reduz ambiguidade. No Atlas isso e ainda mais critico:
o sistema possui memoria, Cartografia, Forge, Dev, evidence, index-code,
providers e subagentes. Sem workspace explicito, todos esses recursos podem
trabalhar sobre contexto errado.

AWIS elimina erro de repo, memoria cruzada, patch com doc errada, Obra sem raiz,
index-code fora do escopo, Cartografia do projeto errado e prompt frouxo para
provider/subagente.

## Onde Se Encaixa

```text
Universo
-> Organizacao / Empresa
-> Project / Workspace
-> App / Servico / Repo
-> Modulo / Fluxo
-> Arquivo / Contrato / Teste
```

`Project/Workspace` e a unidade obrigatoria de realidade tecnica. Uma Obra nao
substitui workspace. Uma conversa nao substitui workspace. Um provider session
nao substitui workspace.

AWIS fica acima de Atlas Dev, Atlas Forge, Cartografia, memoria operacional e
Code Intelligence. `atlas-code-multi-project-workspace-os.md` define o conceito
multi-projeto; AWIS define o runtime/gate que torna esse conceito obrigatorio.

Subcamadas planejadas:

- **AWIS:** define onde o trabalho vive.
- **AWTR / Atlas Workspace Twin Runtime:** entende profundamente um workspace.
- **AWEF / Atlas Workspace Evolution Fabric:** aprende padroes entre workspaces sem vazar contexto.
- **AWAF / Atlas Workspace Artifact Fabric:** gera artefatos operacionais vivos por workspace; **AWAIR / Atlas Workspace Artifact Intelligence Runtime** leva isso ao limite com lake, graph, merge room, memory lens, contract lock, replay, simulation, context proof, recovery point, Cartografia por artifact e marketplace privado.
- **AWCO / Atlas Workspace Contract Orchestrator:** certifica e orquestra artefatos.
- **ACFW / Atlas Conversation Fusion Workspace:** funde conversas, docs e runs dentro do workspace.
- **ACIOS / Atlas Continuity Intelligence OS:** mantem a continuidade viva do workspace.
- **Current Truth Pack:** pacote pequeno que representa o estado atual confiavel.
- **Raw Archive:** guarda conversas enormes para auditoria, nao para prompt direto.

Regra de artefatos:

```text
conversa/docs/runs -> artefatos curados -> contratos -> provider/subagente
```

AWIS nao deve passar conversa bruta para IA quando pode passar artefato. O
artefato e menor, auditavel, cacheavel e tem dono.

## Contratos

Todo workspace registrado deve possuir, no minimo:

```json
{
  "workspace_id": "atlas",
  "name": "Atlas",
  "root_path": "/Users/vitorepf/develop/Atlas/atlas-server",
  "repo_type": "monorepo",
  "status": "active",
  "pinned": true,
  "memory_scope": "workspace",
  "cartography_scope": "workspace",
  "allowed_execution_modes": ["conversation", "dev", "forge"]
}
```

Campos obrigatorios:

- `workspace_id`: identificador estavel, curto e humano.
- `root_path`: pasta real validada no sistema local.
- `root_hash`: hash de `root_path + git_remote + git_head`.
- `status`: `discovered`, `registered`, `active`, `stale`, `archived` ou `quarantined`.
- `pinned`: prioridade operacional e visual.
- `memory_scope`: limite de memoria e outcomes.
- `command_registry`: comandos permitidos.
- `risk_map`: zonas sensiveis do projeto.
- `readiness_status`: estado antes de executar.

Contratos adicionais:

- todo Dev run carrega `workspace_id`;
- toda Obra Forge carrega `workspace_id`;
- todo context pack carrega `workspace_context_pack_hash`;
- todo provider/subagente recebe Workspace Handoff Pack;
- toda memoria operacional declara `memory_scope=workspace`.
- toda conversa fundida declara `source_conversation_ids`, `workspace_id` e `truth_status`.
- todo Current Truth Pack declara hash, fontes, conflitos resolvidos e stale checks.

## Fluxo

Atlas Dev:

- exige workspace ativo;
- exige readiness minima;
- usa contexto e memoria daquele workspace;
- bloqueia patch fora de allowed paths.

Atlas Forge:

- exige workspace ativo;
- exige readiness `ready`;
- exige scope, owner docs, testes e risk map;
- toda Obra tem `workspace_id`.

Cartografia:

- mostra o Universo e permite escolher projeto;
- ao entrar em um workspace, filtra nodes, docs, fluxos e riscos daquele projeto;
- nunca inventa fonte se o workspace nao tem doc/index real.

Memoria:

- toda memoria operacional tem `workspace_id`;
- recall cross-workspace so com declaracao explicita;
- outcome memory e provider memory sao separados por workspace.

Index-code:

- nao roda sem workspace;
- grava `workspace_id`, `root_hash`, `index_hash` e `indexed_at`;
- se stale, Dev/Forge entram em modo limitado.

Conversas longas:

- conversas de dias ou semanas entram como `raw_conversation_archive`;
- AWIS segmenta por meta, decisao, entrega, arquivo, blocker e mudanca de direcao;
- ACFW deduplica, remove repeticao e identifica conflitos;
- ACIOS promove somente verdade atual para `current_truth_pack`;
- provider recebe pacote minimo, nao conversa inteira.

Fluxo ideal:

```text
Usuario pede algo
-> AWIS verifica workspace ativo
-> se ausente: pedir selecao de projeto
-> se presente: readiness gate
-> fingerprint + memory scope + risk map
-> context pack minimo
-> Dev, Forge, Cartografia ou conversa
-> outcome memory no mesmo workspace
```

## Escopo de Implementacao

Blocos do AWIS:

| Bloco | Funcao | Saida |
|---|---|---|
| Workspace Registry | registra projetos e paths reais | lista canonica de workspaces |
| Active Workspace Binding | define projeto ativo | `active_workspace_id` |
| Workspace Fingerprint | detecta stack, apps, docs, comandos | perfil tecnico |
| Workspace Readiness Gate | bloqueia execucao insegura | ready/limited/blocked |
| Workspace Context Pack | monta contexto minimo por tarefa | `context_pack_hash` |
| Workspace Memory Isolation | separa memorias/outcomes | recall seguro |
| Workspace Command Registry | impede comando inventado | comandos permitidos |
| Workspace Risk Map | identifica zonas sensiveis | gates proporcionais |
| Workspace Handoff Pack | projeta prompt seguro | provider/subagente scoped |
| Workspace Cartography Binding | filtra mapa por projeto | Cartografia correta |
| Workspace Execution Memory | grava runs e resultados | aprendizado por projeto |
| Workspace Release/Forge Binding | amarra Obra/release ao projeto | evidence rastreavel |
| Workspace Artifact Fabric | gera packs vivos do workspace | spec/test/risk/context/handoff pack |
| Conversation Fusion Workspace | funde conversas longas | `fusion_pack_hash` |
| Continuity Intelligence OS | mantem linha do tempo operacional | `continuity_graph_hash` |
| Current Truth Pack | compila verdade atual minima | `current_truth_pack_hash` |
| Raw Conversation Archive | guarda bruto para auditoria | arquivo/ledger consultavel |

Fases:

- Fase 1: registry persistido, active binding e bloqueio sem workspace.
- Fase 2: fingerprint, readiness e command registry.
- Fase 3: context pack e memory isolation.
- Fase 4: Cartografia por workspace.
- Fase 5: AWAF/ACFW para artefatos e fusao manual de conversas.
- Fase 6: ACIOS para continuidade automatica.
- Fase 7: certification, observability e replay.

## Continuidade de Conversas Longas

AWIS governa conversas enormes por meio da doc filha
`atlas-continuity-intelligence-os.md`. A regra operacional fica aqui:

```text
raw_conversation_archive
-> segmentation_map
-> decision_ledger
-> conflict_report
-> current_truth_pack
-> task_context_pack
```

O bruto fica preservado para auditoria. Provider/subagente recebe apenas
`task_context_pack`, com verdade atual, fontes e conflitos relevantes.

## Fixar Projeto

Fixar projeto nao e detalhe visual. Um workspace fixado:

- aparece no topo;
- pode virar default de abertura;
- pre-aquece context pack;
- prioriza index-code;
- preserva comandos recentes;
- mantem memoria e Cartografia prontas.

Fixar nunca pode permitir execucao se readiness estiver bloqueada.

## Estados

```text
discovered -> registered -> indexed -> active -> pinned
                         -> stale -> limited
                         -> archived
                         -> quarantined
```

- `discovered`: pasta encontrada, nao confiavel.
- `registered`: path validado.
- `indexed`: fingerprint e code intelligence disponiveis.
- `active`: pode ser usado.
- `pinned`: prioridade operacional.
- `stale`: index/docs/memoria precisam refresh.
- `limited`: conversa permitida, execucao bloqueada ou reduzida.
- `archived`: leitura historica.
- `quarantined`: nao usar sem revisao humana.

## Comandos

```bash
php artisan atlas:workspace-intelligence register --workspace=cliente --path=/repo --test-command="npm test" --json
php artisan atlas:workspace-intelligence list --json
php artisan atlas:workspace-intelligence certify --workspace=atlas --persist --json
php artisan atlas:workspace-intelligence artifact-intelligence --workspace=atlas --json
php artisan atlas:workspace-intelligence gate --workspace=atlas --mode=forge --json --strict
php artisan atlas:engineering:knowledge index-code --workspace=atlas --summary-only --json
```

Exemplo:

```text
"estou com bug na tela de login"
-> workspace ativo: Atlas
-> stack detectada: Laravel + Expo + Desktop
-> rota: Atlas Dev
-> risk map: auth/session sensivel
-> testes: auth/login/focused
-> patch permitido apenas no escopo
-> outcome salvo em workspace Atlas
```

## Relacao Com Sistemas Existentes

- `Atlas Code Multi-Project Workspace OS`: define que Atlas/Blackink/clientes sao projetos, nao Obras.
- `Atlas Dev`: usa AWIS para binding, contexto, comandos, risk map e tests.
- `Atlas Forge`: usa AWIS para criar Obra dentro de workspace real.
- `Cartografia`: usa AWIS para escopo visual por projeto.
- `Code Intelligence`: indexa por workspace e bloqueia stale context.
- `AEMOR`: aprende outcomes por workspace.
- `AREG/AQPES`: governam custo e contexto dentro do workspace ativo.
- `ACRUI`: audita realidade de codigo/documentacao por workspace.

## Dependencias

- `atlas-canonical-glossary-and-naming.md`: nomes canonicos de Dev, Forge e Obra.
- `atlas-code-multi-project-workspace-os.md`: contrato multi-projeto.
- `atlas-ai-conversation-surface-and-atlas-dev-v1.md`: uso diario do Atlas Dev.
- `atlas-code-programming-obras-operating-system.md`: Obra/Forge como trabalho pesado.
- `atlas-cartographic-knowledge-os.md`: Cartografia por escala.
- `code-intelligence.md`: indexacao e grafo por workspace.

## Regras Para IA

- Antes de implementar, descubra ou exija `active_workspace_id`.
- Nao use memoria de outro workspace como fato operacional.
- Nao rode comando se `command_registry` nao autorizar.
- Nao crie Obra sem `workspace_id`.
- Nao chame provider/subagente sem Workspace Handoff Pack.
- Nao prometa que Cartografia esta correta sem fonte do workspace.
- Se workspace estiver `stale`, faca refresh ou bloqueie execucao.

## Evidencias

Evidencia atual: registry persistido com API/CLI, runtime read-only, execution gate, path resolver, snapshot/replay, boundary audit e consumo em Dev/Forge. Registry editing cobre list/show/create/update/archive por API, list/register por CLI e usa archive por status, sem hard delete.
Atlas Dev Run/Worker, Engineering Runner, Super Tool Runtime (`awis_workspace_required_for_tool_execution`), Engineering Quality Scan (`awis_workspace_required_for_quality_scan`), Atlas Code diff apply (`awis_workspace_required_for_diff_apply`), `atlas:runtime` mutativo, replay patch, `index-code` e Forge Intake/Fast Path/Dispatch/Provider/Live/Governed Execution/Promotion ja bloqueiam sem workspace certificado. `atlas:workspace-intelligence certify --json --strict` inclui `execution_boundaries`; `boundary-audit --json --strict` prova 17 boundaries mutativos e inventaria 21 subprocessos como guardados, read-only, caller-gated ou externos; unclassified=0.

Snapshots AWIS e projections AWTR/AWCO/AWEF persistidos por `--persist` ou
`persist=1` so reabrem por `latest=1` se o `workspace_hash` atual bater com o
hash salvo. Divergencia ou hash ausente gera `409` fail-closed. O hash inclui
path real, HEAD resolvido e arquivos estruturais/docs AWIS, impedindo replay
antigo apos mudanca em git, lockfile, package, tsconfig ou docs criticas.

## Riscos

- **Workspace falso:** path existe, mas nao e o repo certo.
- **Root drift:** git head mudou e context pack ficou stale.
- **Memory bleed:** recall de outro projeto entra no prompt.
- **Command hallucination:** IA inventa script inexistente.
- **Pinned illusion:** projeto fixado parece pronto, mas readiness esta blocked.
- **Cartography bleed:** mapa de Atlas aparece quando projeto ativo e Blackink.
- **Provider leakage:** prompt para provider inclui contexto fora do workspace.
- **Index-code stale:** IA confia em grafo antigo.

## Exemplos
`bug login`, `cria ecommerce` e `indexa codigo` exigem workspace ativo; sem isso, AWIS pede selecao ou bloqueia execucao.

## Definition Of Done
AWIS so esta pronto quando Dev/Forge carregam `workspace_id`, provider/subagente recebe handoff scoped, index-code exige workspace, memoria/outcome sao workspace-scoped, registry editing API/CLI cobre create/update/archive sem hard delete, Cartografia filtra por workspace, readiness bloqueia execucao insegura, projeto fixado persiste, execution boundaries entram no certify e `atlas:workspace-intelligence certify --json --strict` fica verde.

## Proximas Acoes
Control Plane ja agrega AWTR/AWCO/AWEF persistidos e Cartografia ja projeta
`Workspace Intelligence`, `AWIS`, `AWTR`, `AWCO`, `AWEF` e o fluxo AWIS sem
estourar orçamento visual. Proxima melhoria: Control Plane comparar stale contra
o `workspace_hash` atual, nao apenas contra status salvo na captura.
