---
id: atlas-workspace-twin-runtime
type: engineering_knowledge
title: Atlas Workspace Twin Runtime
status: building
category: workspace-intelligence
priority: 98
implementation_state: read_only_runtime_projection_present
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
graph_status: planned
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
  - future: atlas:workspace-twin:certify --json --strict
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
  - Criar schemas de genome, code map, command intelligence e risk map.
  - Criar comandos shadow de twin.
  - Integrar com AWIS readiness.
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
| Living Code Map | modulos/fluxos/testes | `code_map_hash` |
| Context Autopilot | contexto por tarefa | `task_context_pack` |
| Test Command Intelligence | comandos e testes certos | `focused_tests` |
| Risk Fragility Map | zonas sensiveis | `risk_map_hash` |
| Workspace Memory Core | outcomes e decisoes | recall scoped |
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

Evidencia atual: esta especificacao e a primeira projecao AWTR no runtime AWIS.
Ainda faltam persistence propria, refresh daemon e enforcement mutativo.

Comandos planejados:

```bash
php artisan atlas:workspace-twin:build --workspace=atlas --json
php artisan atlas:workspace-twin:show --workspace=atlas --json
php artisan atlas:workspace-twin:task-pack --workspace=atlas --task="bug login" --json
php artisan atlas:workspace-twin:certify --workspace=atlas --json --strict
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
- task context pack e produzido por pedido;
- focused tests sao emitidos com motivo;
- risk map bloqueia areas sensiveis;
- twin stale limita Dev/Forge;
- Cartografia mostra o twin;
- `atlas:workspace-twin:certify --json --strict` fica verde.

## Proximas Acoes

1. Implementar schema read-only de Workspace Genome.
2. Criar builder shadow do Workspace Twin.
3. Integrar com AWIS readiness.
4. Conectar Context Autopilot ao Atlas Dev.
5. Conectar Test Command Intelligence ao Forge.
