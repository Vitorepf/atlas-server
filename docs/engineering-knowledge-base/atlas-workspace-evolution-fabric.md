---
id: atlas-workspace-evolution-fabric
type: engineering_knowledge
title: Atlas Workspace Evolution Fabric
status: active
category: workspace-intelligence
priority: 97
implementation_state: dedicated_read_only_evolution_projection_present
summary: Camada planejada que permite ao Atlas aprender padroes entre workspaces sem vazar contexto privado, criando biblioteca de capacidades, falhas, templates e melhorias reutilizaveis.
tags:
  - atlas
  - workspace
  - evolution
  - cross-workspace
  - learning
  - privacy
capabilities:
  - cross_workspace_pattern_learning
  - privacy_preserving_transfer
  - failure_signature_bank
  - workspace_benchmark
  - capability_generator
  - evolution_roadmap
decisions:
  - AWEF aprende entre workspaces por abstracao, nao por copia de contexto bruto.
  - Dados privados de um workspace nunca entram em outro workspace.
  - Padrao reutilizavel precisa de fonte, evidence e escopo.
  - AWEF e camada acima de AWTR; depende de twins confiaveis por workspace.
maintenance:
  - Atualizar antes de implementar pattern library, failure signature bank, cross-workspace benchmark ou capability generator.
  - Manter abaixo de 520 linhas.
  - Rodar docs-health apos alterar.
related_paths:
  - docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md
  - docs/engineering-knowledge-base/atlas-workspace-twin-runtime.md
  - docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md
  - docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-workspace-evolution-fabric
graph_title: Atlas Workspace Evolution Fabric
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-workspace-intelligence-system
graph_status: active
graph_source: repo
product_name: Atlas Workspace Evolution Fabric
runtime_acronym: AWEF
internal_product_name: Atlas Project Evolution Command
technical_runtime: AtlasWorkspaceEvolutionFabricRuntime
human_name: Atlas Workspace Evolution Fabric
canonical_name: Atlas Workspace Evolution Fabric
technical_name: AtlasWorkspaceEvolutionFabricRuntime
cartography_type: runtime
canonical_source: docs/engineering-knowledge-base/atlas-workspace-evolution-fabric.md
owner: workspace-intelligence
repo_paths:
  - docs/engineering-knowledge-base/atlas-workspace-evolution-fabric.md
allowed_changes:
  - Evoluir schemas de pattern, failure signature, benchmark, transfer policy e capability proposal.
forbidden_changes:
  - Copiar contexto privado de um workspace para outro.
  - Aplicar template sem compatibilidade de stack.
  - Promover padrao sem evidence e falsificacao.
depends_on:
  - atlas-workspace-intelligence-system
  - atlas-workspace-twin-runtime
  - atlas-execution-memory-outcome-runtime
flows_to:
  - atlas-dev
  - atlas-forge
  - atlas-cartography
unlocks:
  - cross-project-learning
  - reusable-capability-patterns
  - workspace-evolution-roadmaps
governs:
  - cross_workspace_pattern_library
  - failure_signature_bank
  - privacy_preserving_transfer
evidence:
  - docs/engineering-knowledge-base/atlas-workspace-evolution-fabric.md
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceRuntimeService.php
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceRuntimeProjectionRepository.php
  - app/Console/Commands/AtlasWorkspaceIntelligenceCommand.php
  - app/Http/Controllers/AtlasWorkspaceIntelligenceController.php
  - app/Models/AtlasWorkspaceRuntimeProjectionSnapshot.php
  - database/migrations/2026_05_25_021500_create_atlas_workspace_runtime_projection_snapshots.php
  - tests/Feature/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceRuntimeServiceTest.php
evidence_refs:
  - symbol: AtlasWorkspaceIntelligenceRuntimeService
  - command: atlas:workspace-intelligence
  - test: AtlasWorkspaceIntelligenceRuntimeServiceTest
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: critical
visual_tags:
  - evolution
  - cross-workspace
  - pattern-library
  - privacy
ai_entrypoints:
  - Leia este doc antes de implementar aprendizado entre projetos, pattern library, transfer learning ou benchmark de workspaces.
ai_usage_notes:
  - Reutilize padroes abstratos, nunca dados privados ou contexto bruto.
quality_gates:
  - docs-health
  - php artisan atlas:workspace-intelligence evolution --workspace=atlas --json --strict
failure_modes:
  - Vazamento cross-workspace.
  - Padrao errado aplicado em stack diferente.
  - Failure signature falsa poluir roteamento.
observability_signals:
  - pattern_library_hash
  - failure_signature_bank_hash
  - transfer_policy_hash
  - workspace_benchmark_hash
next_actions:
  - Persistir pattern library por evidence real.
  - Conectar recomendacoes ao Forge como proposta.
  - Criar benchmark historico por workspace.
---
# Atlas Workspace Evolution Fabric

## Resumo

**Nome canonico / produto:** Atlas Workspace Evolution Fabric  
**Acronimo tecnico:** AWEF  
**Nome interno de experiencia / superficie:** Atlas Project Evolution Command  
**Runtime tecnico:** `AtlasWorkspaceEvolutionFabricRuntime`

AWEF e o salto planejado acima do twin por workspace. AWTR entende um projeto.
AWEF aprende entre projetos sem vazar contexto sensivel. Ele transforma erros,
padroes, templates, gates, testes e estrategias bem-sucedidas em conhecimento
abstrato reutilizavel.

## Papel no Atlas

AWEF elimina o problema de cada novo projeto comecar do zero. Se o Atlas
aprendeu uma forma boa de estruturar login, upload, rich input, Cartografia,
readiness, Forge, Dev ou docs canonicas em um workspace, ele pode reaproveitar
o padrao em outro projeto de forma privada e verificavel.

Ele nao copia:

- dados de cliente;
- texto bruto;
- segredos;
- historico privado;
- arquivos proprietarios.

Ele copia somente padroes abstratos aprovados.

## Onde Se Encaixa

```text
AWIS / workspace boundary
-> AWTR / twin de cada workspace
-> AWEF / aprendizado entre workspaces
-> pattern library + failure bank + roadmap
-> Dev, Forge, Cartografia e novos workspaces
```

AWEF depende de twins confiaveis. Sem AWTR por workspace, AWEF vira heuristica
solta e deve ficar bloqueado.

## Contratos

### Pattern

```json
{
  "schema_version": "atlas.workspace_pattern.v1",
  "pattern_id": "rich-input-canon-package",
  "source_workspace_id": "atlas",
  "privacy_level": "abstracted",
  "applies_to": ["typescript", "mobile", "desktop"],
  "evidence_refs": [],
  "forbidden_transfer": [],
  "quality_score": 0.0
}
```

### Failure Signature

```json
{
  "schema_version": "atlas.failure_signature.v1",
  "signature_id": "stale-index-code-context",
  "symptoms": [],
  "likely_causes": [],
  "avoidance_policy": [],
  "evidence_refs": [],
  "confidence": 0.0
}
```

## Fluxo

```text
workspace outcomes
-> abstract patterns
-> remove private context
-> verify evidence
-> falsify against failures
-> publish to pattern library
-> apply only when stack/risk/constraints match
```

Uso em novo projeto:

```text
new workspace registered
-> AWTR builds twin
-> AWEF compares genome with known patterns
-> proposes reusable capabilities
-> Dev/Forge receive scoped suggestions
```

## Escopo de Implementacao

Blocos:

| Bloco | Funcao | Saida |
|---|---|---|
| Pattern Library | padroes aprovados | reusable patterns |
| Failure Signature Bank | erros recorrentes | avoidance policies |
| Privacy Transfer Gate | remove contexto privado | safe transfer |
| Workspace Benchmark | compara saude dos projetos | scorecard |
| Capability Generator | transforma padrao em pacote/runtime | proposals |
| Evolution Roadmap | sugere proximos saltos | roadmap |
| Provider Reliability Matrix | aprende provider por tarefa | routing signal |

## Dependencias

- AWIS: boundary entre workspaces.
- AWTR: twins confiaveis.
- AEMOR: outcomes e falhas reais.
- ACRUI: realidade de codigo e docs.
- Knowledge Governance: autoridade e promocao canonica.

## Regras para IA

- Nao transfira raw context entre workspaces.
- Nao aplique padrao sem compatibilidade de stack.
- Nao promova pattern sem evidence refs.
- Nao use failure signature com baixa confianca para bloquear execucao critica.
- Se houver dado privado, pare e gere redaction/abstraction primeiro.

## Evidencias

Evidencia atual: AWEF possui projection dedicada via
`atlas:workspace-intelligence evolution` e endpoint
`/atlas-code/workspace-intelligence/evolution`. O payload inclui pattern
library, failure signature bank, privacy transfer gate, workspace benchmark
shadow e evolution hash deterministico. Com `--persist`/`persist=1`, AWEF e
salvo em `atlas_workspace_runtime_projection_snapshots` e pode ser reaberto por
`latest=1`. O Atlas AI Control Plane agrega AWEF dentro de
`workspace_intelligence`, com contagem por familia/workspace, projection hashes
recentes e blockers quando uma projection persistida estiver bloqueada.
Replays `latest=1` falham com `409` quando o `workspace_hash` atual diverge do
hash persistido ou quando a projection nao possui binding verificavel. O binding
`awis_projection` sela `workspace_id`, `workspace_hash`, `runtime_hash`,
`family`, `projection_hash` e a politica fail-closed. Isso evita que patterns,
benchmarks ou failure signatures stale sejam reaproveitados como recomendacao
atual.

Comandos planejados:

```bash
php artisan atlas:workspace-intelligence evolution --workspace=atlas --json --strict
curl /atlas-code/workspace-intelligence/evolution?workspace=atlas
```

## Riscos

- **Privacy leak:** padrao carrega dado privado.
- **Wrong transfer:** padrao de Laravel aplicado em stack incompatível.
- **Over-generalization:** uma solucao local vira regra global indevida.
- **Benchmark falso:** projeto parece pior/melhor por metricas ruins.
- **Provider bias:** uma falha isolada vira memoria global.

Mitigacoes:

- privacy transfer gate;
- evidence refs obrigatorias;
- confidence score;
- falsification gate;
- operador aprova pattern critical;
- aplicacao sempre scoped por workspace.

## Exemplos

Atlas aprende:

```text
rich input canon package
-> mobile/desktop usam mesmo contrato
-> backend preserva payload
-> testes cross-platform bloqueiam drift
```

Novo SaaS entra:

```text
AWEF recomenda shared package canonico
AWTR valida stack
Forge cria Obra de implementacao
Dev aplica fatias pequenas
```

## Definition Of Done

AWEF esta pronto quando:

- pattern library existe e e versionada;
- failure signature bank tem evidence refs;
- privacy transfer gate bloqueia raw data;
- workspace benchmark e read-only e deterministico;
- recommendations futuras declaram compatibilidade e risco;
- Dev/Forge consomem recomendacoes como hint, nao como verdade absoluta;
- `atlas:workspace-intelligence evolution --workspace=atlas --json --strict` fica verde.

## Proximas Acoes

1. Criar benchmark historico por workspace.
2. Conectar recomendacoes ao Forge como proposta, nao execucao automatica.
3. Refinar Cartografia para abrir AWEF com pattern library e privacy transfer gate por workspace.
