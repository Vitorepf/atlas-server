---
id: atlas-workspace-twin-runtime
type: engineering_knowledge
title: Atlas Workspace Twin Runtime
status: active
category: workspace-intelligence
priority: 98
implementation_state: dedicated_read_only_projection_present
summary: Runtime planejado que cria um gemeo operacional vivo de cada workspace para guiar contexto, testes, riscos, comandos, memoria e execucao por IA.
tags:
  - atlas
  - workspace
  - twin
  - code-intelligence
  - context
  - execution-safety
capabilities:
  - workspace_genome
  - living_code_map
  - context_autopilot
  - test_command_intelligence
  - risk_fragility_map
  - provider_skill_memory
  - workspace_learning_loop
  - workspace_next_session_brain
decisions:
  - AWTR vive dentro de AWIS e nunca substitui workspace binding.
  - O twin e derivado de docs, codigo, testes, receipts, outcomes e comandos reais.
  - O twin nunca inventa capacidade; lacuna vira unknown ou blocker.
  - O provider recebe contexto gerado pelo twin, nao o twin bruto inteiro.
maintenance:
  - Atualizar antes de implementar workspace genome, living code map, context autopilot ou test intelligence.
  - Manter abaixo de 520 linhas.
  - Rodar docs-health apos alterar.
related_paths:
  - docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md
  - docs/engineering-knowledge-base/atlas-continuity-intelligence-os.md
  - docs/engineering-knowledge-base/code-intelligence.md
  - docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md
  - docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-workspace-twin-runtime
graph_title: Atlas Workspace Twin Runtime
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-workspace-intelligence-system
graph_status: active
graph_source: repo
product_name: Atlas Workspace Twin Runtime
runtime_acronym: AWTR
internal_product_name: Atlas Project Twin
technical_runtime: AtlasWorkspaceTwinRuntime
human_name: Atlas Workspace Twin Runtime
canonical_name: Atlas Workspace Twin Runtime
technical_name: AtlasWorkspaceTwinRuntime
cartography_type: runtime
canonical_source: docs/engineering-knowledge-base/atlas-workspace-twin-runtime.md
owner: workspace-intelligence
repo_paths:
  - docs/engineering-knowledge-base/atlas-workspace-twin-runtime.md
allowed_changes:
  - Evoluir contracts de genome, code map, command intelligence, context autopilot e learning loop.
forbidden_changes:
  - Usar twin stale para executar patch.
  - Tratar twin como fonte canonica acima de docs/codigo/testes/receipts.
  - Misturar twins de workspaces diferentes.
depends_on:
  - atlas-workspace-intelligence-system
  - code-intelligence
  - atlas-code-reality-usage-intelligence
flows_to:
  - atlas-dev
  - atlas-forge
  - atlas-cartography
unlocks:
  - repo-native-context-autopilot
  - test-selection-by-workspace
  - risk-aware-provider-routing
governs:
  - workspace_genome
  - context_autopilot
  - test_command_intelligence
evidence:
  - docs/engineering-knowledge-base/atlas-workspace-twin-runtime.md
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceRuntimeService.php
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceRuntimeProjectionRepository.php
  - app/Console/Commands/AtlasWorkspaceIntelligenceCommand.php
  - app/Http/Controllers/AtlasWorkspaceIntelligenceController.php
  - app/Models/AtlasWorkspaceRuntimeProjectionSnapshot.php
  - database/migrations/2026_05_25_021500_create_atlas_workspace_runtime_projection_snapshots.php
  - tests/Feature/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceRuntimeServiceTest.php
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: critical
visual_tags:
  - twin
  - workspace
  - code-map
  - context
ai_entrypoints:
  - Leia este doc antes de implementar twin de repo, contexto automatico por workspace, test selection ou risk map por projeto.
ai_usage_notes:
  - Se o twin estiver stale, gere refresh ou bloqueie execucao.
quality_gates:
  - docs-health
  - php artisan atlas:workspace-intelligence twin --workspace=atlas --json --strict
failure_modes:
  - Twin velho guiar patch errado.
  - Heuristica virar verdade canonica.
  - Teste relevante nao ser selecionado.
observability_signals:
  - workspace_twin_hash
  - genome_hash
  - code_map_hash
  - command_registry_hash
  - risk_map_hash
next_actions:
  - Persistir twin dedicado quando houver refresh daemon.
  - Ampliar evidence receipts do Context Autopilot em Dev e Forge.
  - Evoluir ranking de testes com correlacao de duracao por stack, area e comando.
---
# Atlas Workspace Twin Runtime

## Resumo

**Nome canonico / produto:** Atlas Workspace Twin Runtime  
**Acronimo tecnico:** AWTR  
**Nome interno de experiencia / superficie:** Atlas Project Twin  
**Runtime tecnico:** `AtlasWorkspaceTwinRuntime`

AWTR e o salto planejado em que cada workspace ganha um gemeo operacional vivo.
Ele nao e so um index de arquivos. Ele representa como o projeto funciona,
quais partes sao frageis, quais testes importam, quais comandos existem, quais
docs governam e que memoria operacional deve orientar Dev e Forge.

## Papel no Atlas

AWIS responde: "qual workspace esta ativo?". AWTR responde: "como este
workspace funciona e como uma IA deve trabalhar nele sem nascer zerada?".

Ele reduz:

- contexto errado;
- patch no arquivo errado;
- teste omitido;
- comando inventado;
- provider mal escolhido;
- repeticao de erro antigo;
- dependencia de o operador reexplicar o projeto.

## Onde Se Encaixa

```text
AWIS / workspace ativo
-> AWTR / twin vivo do workspace
-> Current Truth Pack + Task Context Pack
-> Atlas Dev, Atlas Forge, Cartografia ou provider
```

AWTR e por-workspace. Ele nao aprende entre projetos; isso pertence ao AWEF.

## Contratos

### Workspace Genome

```json
{
  "schema_version": "atlas.workspace_genome.v1",
  "workspace_id": "atlas",
  "stack": ["laravel", "expo", "tauri", "typescript"],
  "apps": [],
  "commands": [],
  "owner_docs": [],
  "risk_zones": [],
  "test_families": [],
  "hash": "sha256:..."
}
```

### Workspace Twin

```json
{
  "schema_version": "atlas.workspace_twin.v1",
  "workspace_id": "atlas",
  "genome_hash": "sha256:...",
  "code_map_hash": "sha256:...",
  "risk_map_hash": "sha256:...",
  "command_registry_hash": "sha256:...",
  "stale": false
}
```

## Fluxo

```text
workspace registered
-> fingerprint stack
-> map apps/modules/docs/tests/commands
-> build risk and fragility map
-> learn outcomes from Dev/Forge
-> emit twin
-> generate task context/test plan for each request
```

Exemplo:

```text
"bug na tela de login"
-> AWTR identifica auth/session/mobile/backend
-> seleciona docs donas
-> aponta arquivos provaveis
-> escolhe testes focados
-> marca risco auth
-> gera Task Context Pack
```

## Escopo de Implementacao

Blocos:

| Bloco | Funcao | Saida |
|---|---|---|
| Workspace Genome | identidade tecnica | `genome_hash` |
| Repository Inventory | manifests/repos seguros | `inventory_hash` |
| Living Code Map | modulos/fluxos/testes | `code_map_hash` |
| Context Autopilot | contexto por tarefa | `task_context_pack` |
| Test Command Intelligence | comandos e testes certos | `focused_tests` |
| Risk Fragility Map | zonas sensiveis | `risk_map_hash` |
| Workspace Memory Core | outcomes e decisoes | recall scoped |
| Workspace Change Memory | mudancas Git provider-safe | `change_hash` |
| Workspace Focus Map | foco por tarefa/mudanca | `focus_hash` |
| Workspace Next Session Brain | retomada operacional segura | `brain_hash` |
| Provider Skill Memory | provider por tarefa | routing hint |
| Simulation Layer | impacto antes de executar | risk report |

## Dependencias

- AWIS: workspace ativo e readiness.
- Code Intelligence: simbolos, arquivos e relacoes.
- ACRUI: verdade de uso de codigo e docs.
- AEMOR: outcome memory.
- ACIOS: continuidade e truth pack.

## Regras para IA

- Nao use AWTR sem `workspace_id`.
- Nao use twin stale para patch.
- Nao trate heuristic como verdade sem evidence.
- Nao rode comando fora do command registry.
- Nao selecione teste sem declarar motivo ou skip reason.
- Se o twin nao conhece uma area, marque unknown e investigue.

## Evidencias

Evidencia atual: AWTR possui projecao dedicada via
`atlas:workspace-intelligence twin` e endpoint
`/atlas-code/workspace-intelligence/twin`. O payload inclui Workspace Genome,
Living Code Map, Context Autopilot, Test Command Intelligence, Command Registry,
Risk Fragility Map, Provider Skill Memory e Workspace Learning Loop com hashes
deterministicos. Com `--persist`/`persist=1`, AWTR e salvo em
`atlas_workspace_runtime_projection_snapshots` e pode ser reaberto por
`latest=1`. O Atlas AI Control Plane agora agrega snapshots AWTR/AWCO/AWEF em
`workspace_intelligence`, incluindo status por familia, hashes recentes e
blockers quando alguma projection persistida estiver `blocked`.
Replays `latest=1` de AWTR/AWCO/AWEF falham com `409` quando o
`workspace_hash` atual diverge do snapshot persistido ou quando o snapshot nao
tem hash verificavel. O `workspace_hash` inclui path real, HEAD resolvido
incluindo o conteudo da ref atual quando existir, e hashes de arquivos
estruturais do workspace/docs AWIS. Isso impede twin stale depois de mudanca em
git, lockfile, package, tsconfig ou docs criticas de workspace.

AWIS tambem emite `workspace_change_memory`, uma memoria operacional
provider-safe do estado Git atual. Ela suporta tanto repo Git direto quanto uma
pasta ampla com repos filhos, como `atlas-server`, `atlas-desktop` e `atlas-app`.
Ela registra apenas `repo_key`, caminhos relativos, areas tocadas, areas
criticas tocadas, branch, `head_sha_short`, `diff_hash` agregado e
`change_hash`; nao retorna `diff_excerpt`, conteudo bruto de arquivo nem caminho
absoluto do workspace. O Workspace Learning Loop consome esse `change_hash`
como unidade candidata de memoria, mantendo `auto_promotes_memory=false` ate
revisao por evidencia.

AWTR tambem emite `repository_inventory`, um inventario provider-safe dos repos
e manifests do workspace. Ele usa nomes de manifests, tags de stack, nomes de
scripts e command hints derivados, mas nao retorna manifest bruto nem corpo de
scripts. Esse inventario permite que pasta ampla carregue stack real e comandos
provaveis sem depender apenas do `stack_summary` manual do profile.

AWIS tambem emite `workspace_focus_map`, calculado a partir de tarefa, mudancas
atuais, areas criticas e `repository_inventory`. O foco aponta repos provaveis,
areas, arquivos relativos de preview e comandos sugeridos para `task_packet`,
`context_pack`, `test_plan`, `risk_sheet` e Learning Loop. Ele nao retorna
conteudo bruto de arquivos, diff ou caminho absoluto.

AWIS tambem emite `workspace_next_session_brain`, um pacote provider-safe para
retomada da proxima sessao. Ele cruza binding, inventario, change memory, focus
map, artifact graph, contratos e runbook para declarar ordem de carregamento,
repositorios focados, docs donas, comandos priorizados, revisoes obrigatorias e
candidatos de memoria. O pacote e desenhado para reduzir varredura repetida e
evitar que a proxima IA nasca zerada, mas mantem `auto_promote=false` e nao
retorna arquivo bruto, diff, manifest bruto, conversa bruta ou caminho absoluto.
O runtime completo tambem publica `repository_inventory` no topo do payload,
alem de `awtr.repository_inventory`, para que surfaces e gates nao precisem
desempacotar o Twin inteiro para entender a pasta. O brain inclui
`context_loading_plan`, com `repository_inventory_hash`, stack tags, refs de
manifest por repo focado, command hints, cache keys e gatilhos de refresh. Esse
plano e a ponte performatica entre "escolher pasta" e "nascer com contexto":
ele orienta o que carregar primeiro sem enviar manifest bruto, corpo de script
ou caminho absoluto ao provider.
Ele possui projection dedicada `AWNSB`, comando
`atlas:workspace-intelligence next-session-brain`, endpoint
`/atlas-code/workspace-intelligence/next-session-brain` e replay `latest=1`
bloqueado quando o `workspace_hash` muda.
O AWIS execution gate tambem exige `awnsb_next_session_brain` para execucao
mutativa e projeta `execution_context` com `brain_hash`, ordem de carregamento,
repositorios/areas focadas, comandos priorizados, refs de memoria e
`context_loading_plan`. O Handoff Pack e o Atlas Dev Runtime tambem carregam
esse plano antes de liberar `provider_execution_allowed`, garantindo que Dev,
Forge e subagentes recebam o mesmo contrato de pasta performatico e
provider-safe, sem precisar reabrir o Twin inteiro nem receber conteudo bruto.
Atlas Dev tambem converte esse plano em `workspace_context_selection`, anexando
refs provider-safe (`awis_repo`, `awis_manifest`, `awis_stack`,
`awis_cache:repository_inventory`) e comandos sugeridos ao
`atlas_dev_runtime_intelligence.task_packet`. Assim o plano deixa de ser apenas
metadado de retomada e passa a influenciar a selecao real de contexto/testes
antes do prompt de provider.
Forge tambem consome o mesmo plano no intake: `context_refs` ganha refs AWIS
provider-safe e os work packets recebem `suggested_tests` derivados de
`execution_priority` e `context_loading_plan.command_hints`. Assim Obras
nascem com a mesma memoria de pasta, stack e comandos que Dev usa, sem depender
de prompt manual ou de varredura bruta de repositorio.
AWTR tambem publica `workspace_outcome_command_memory` dentro de
`test_command_intelligence`. Essa memoria le outcomes reais de Dev, Forge,
Engineering Test Runs e resultados certificados de teste, resume sucesso/falha
por comando, calcula afinidade por area afetada, incorpora recencia e duracao,
marca estabilidade (`stable`, `mixed`, `failing`) e classifica performance
(`fast`, `normal`, `heavy`, `slow`) usando amostras capadas, buckets e p95 de
duracao. Tambem correlaciona duracao por area e por stack usando o inventario
de repositorio, sem abrir conteudo bruto. Comandos lentos ou instaveis entram
em `slow_commands`, `flaky_commands` e `avoid_commands`. O contrato retorna
apenas comando, contadores, buckets de duracao, p95, area/stack agregadas e
refs hashadas de outcome/evidencia; nao retorna log bruto, diff, texto de
provider ou caminho absoluto.
`workspace_focus_map` e `workspace_next_session_brain.execution_priority`
consomem esse ranking, entao a proxima sessao nasce com testes ordenados por
evidencia real, area tocada e estabilidade, nao so por heuristica de manifest.
O `context_loading_plan` tambem carrega `outcome_command_memory_hash`,
`outcome_ranked_commands`, `area_ranked_commands`, `flaky_commands`,
`slow_commands`, `avoid_commands`, `command_performance_policy` e
`command_performance_histogram`, alem dos indices
`area_performance_index_hash` e `stack_performance_index_hash`. Dev e Forge
transformam esses hashes em refs cache provider-safe
(`awis_cache:outcome_command_memory`,
`awis_cache:command_performance_histogram`,
`awis_cache:area_performance_index` e
`awis_cache:stack_performance_index`), mesclam comandos rankeados aos testes
sugeridos e filtram comandos evitaveis/lentos antes de montar task packets ou
work packets.
AWIS tambem publica `workspace_learning_snapshot`, uma foto provider-safe do
aprendizado do workspace. Ela persiste junto ao snapshot AWIS completo e guarda
apenas scores, contagens e hashes: `learning_score`, `readiness_score`, hashes
de inventario, mudanca, foco, outcome memory, histograma de performance e
indices area/stack. Esse snapshot nao promove memoria sozinho, nao reescreve
docs canonicos e nao transfere aprendizado bruto entre workspaces. O
`context_loading_plan` inclui `learning_snapshot_hash` e
`cache_keys.workspace_learning_snapshot_hash`, permitindo que a proxima sessao
reaproveite a evolucao validada do workspace sem reler log, diff, arquivo,
conversa ou texto de provider. Dev e Forge propagam esse hash como
`awis_cache:workspace_learning_snapshot:<hash>` nos context refs, entao task
packets e work packets recebem a memoria de aprendizado validada como cache
ref, nao como conteudo bruto.
O mesmo plano publica `execution_optimization_policy`, derivada de outcome
memory e performance memory. Essa politica separa comandos em preferidos,
padrao, adiados e bloqueados, define tiers `instant`, `standard` e `deep`, e
tambem e propagada como
`awis_cache:execution_optimization_policy:<hash>`. Dev e Forge usam essa
politica para prepend de comandos rapidos/estaveis e para excluir comandos
lentos, falhos ou bloqueados do pacote default, mantendo validacao profunda
para contextos de risco sem transformar o caminho feliz em execucao pesada.
A policy tambem publica `scope_routing`: rotas provider-safe por area e stack
com comandos preferidos, adiados e bloqueados para aquele escopo. Quando uma
mudanca toca uma area conhecida, o AWIS pode preferir a rota especifica da
area/stack antes do ranking global, reduzindo custo e aumentando precisao.
Atlas Dev e Forge consomem essas rotas ao montar `suggested_tests`: se os
`expected_files` batem com uma area conhecida, os comandos preferidos daquela
rota entram antes de `execution_priority`, comandos globais e hints. Se nao
houver rota de area compativel, os consumidores inferem stacks pelos
`focused_manifest_refs` e usam `stack_routes` como fallback antes do ranking
global. A rota usada tambem e anexada como ref provider-safe por comando
(`awis_execution_route_command:<command_hash>:area|stack:<scope_hash>`),
permitindo que AWTR agregue `execution_route_effectiveness_index` e aprenda
qual estrategia de selecao funcionou sem expor caminhos, logs ou conteudo.
Esse indice volta para a proxima `execution_optimization_policy`: rotas
efetivas sao reutilizadas, enquanto rotas mistas ou falhas sao rebaixadas
para validacao profunda antes de voltarem ao caminho preferido.
A mesma policy publica `validation_tier_routing`, que transforma feedback de
rota em profundidade de validacao: rotas efetivas e rapidas podem usar tier
`instant`, rotas desconhecidas ficam em `standard`, e rotas mistas, falhas,
lentas ou bloqueadas exigem `deep`. Assim o AWIS aprende tambem quao fundo
validar cada escopo, nao apenas qual comando sugerir. Atlas Dev e Forge
consomem isso como `validation_depth_decision` e refs
`awis_validation_tier:*` / `awis_validation_route:*`, mantendo a decisao
provider-safe e acoplada ao task/work packet. Outcomes posteriores agregam
`validation_tier_effectiveness_index`, permitindo que tiers mistos ou falhos
sejam guardados na proxima policy sem expor logs, prompts ou conteudo bruto.
Quando Dev ou Forge produzem outcomes com esse cache ref, AWTR agrega
`execution_policy_effectiveness_index`: sucesso, falha, comandos e score por
policy hash. Isso fecha o loop de aprendizado da propria politica: o AWIS
passa a aprender quais escolhas de validacao funcionaram no workspace sem
armazenar logs, prompts, diffs ou conteudo bruto. A proxima
`execution_optimization_policy` consome esse indice como
`policy_feedback`: policies efetivas podem ser reutilizadas como formato de
execucao, enquanto policies mistas ou falhas apertam o caminho default para
comandos rapidos/preferidos e deixam validacoes amplas para tiers profundos.
O Atlas AI Control Plane considera a familia AWIS operacional somente quando
as projections persistidas incluem `AWTR`, `AWCO`, `AWEF`, `AWIL` e `AWNSB`.
Se um workspace tiver projection parcial, o Control Plane emite
`workspace_intelligence_required_projection_missing` em vez de declarar pronto.

Comandos planejados:

```bash
php artisan atlas:workspace-intelligence twin --workspace=atlas --json --strict
php artisan atlas:workspace-intelligence next-session-brain --workspace=atlas --json --strict
curl /atlas-code/workspace-intelligence/twin?workspace=atlas
curl /atlas-code/workspace-intelligence/next-session-brain?workspace=atlas
```

## Riscos

- Twin stale orientar execucao errada.
- Code map incompleto esconder teste relevante.
- Risk map subestimar auth, billing, migrations ou provider runtime.
- Outcome antigo enviesar nova tarefa.
- Provider skill memory virar preferencia fixa sem evidence.

## Exemplos

Pedido humano ruim:

```text
"arruma a tela"
```

AWTR deve responder com contexto operacional:

```text
workspace: atlas
area provavel: mobile/cartografia
docs: cartographic knowledge OS
risco: UI humana/documentacao
testes: mobile visual/snapshot quando disponivel
acao: pedir tela ou reproduzir via app
```

## Definition Of Done

AWTR esta pronto quando:

- genome e code map sao gerados por workspace;
- command registry impede comando inventado;
- context autopilot declara unidades obrigatorias;
- test command intelligence emite comandos e fallback policy;
- risk map bloqueia areas sensiveis;
- twin stale limita Dev/Forge via AWIS gate;
- Cartografia mostra o twin;
- `atlas:workspace-intelligence twin --workspace=atlas --json --strict` fica verde.

## Proximas Acoes

1. Conectar Context Autopilot ao Atlas Dev.
2. Conectar Test Command Intelligence ao Forge.
3. Refinar Cartografia para mostrar estado runtime real por workspace quando houver snapshots persistidos.
