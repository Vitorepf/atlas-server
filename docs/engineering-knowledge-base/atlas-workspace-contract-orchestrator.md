---
id: atlas-workspace-contract-orchestrator
type: engineering_knowledge
title: Atlas Workspace Contract Orchestrator
status: active
category: workspace-intelligence
priority: 95
implementation_state: dedicated_read_only_contract_projection_present
summary: Camada planejada que certifica, versiona, invalida e orquestra artefatos AWAF antes de execucao mutativa por Dev, Forge, provider ou subagente.
tags:
  - atlas
  - workspace
  - contracts
  - artifacts
  - certification
  - execution-safety
capabilities:
  - artifact_certification
  - schema_validation
  - artifact_versioning
  - stale_invalidation
  - contract_orchestration
  - execution_readiness_gate
decisions:
  - AWCO nao cria artefatos; ele valida, certifica, ordena e invalida.
  - Execucao mutativa deve preferir artefato certificado a prompt livre.
  - Artifact stale, sem fonte ou sem workspace_id bloqueia execucao.
  - AWCO e o gate entre AWAF e Dev/Forge/provider/subagente.
maintenance:
  - Atualizar antes de implementar certificacao de artifacts, readiness gate ou invalidacao.
  - Manter abaixo de 520 linhas.
  - Rodar docs-health apos alterar.
related_paths:
  - docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md
  - docs/engineering-knowledge-base/atlas-workspace-artifact-fabric.md
  - docs/engineering-knowledge-base/atlas-workspace-twin-runtime.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-workspace-contract-orchestrator
graph_title: Atlas Workspace Contract Orchestrator
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-workspace-intelligence-system
graph_status: active
graph_source: repo
product_name: Atlas Workspace Contract Orchestrator
runtime_acronym: AWCO
internal_product_name: Atlas Project Contract Desk
technical_runtime: AtlasWorkspaceContractOrchestratorRuntime
human_name: Atlas Workspace Contract Orchestrator
canonical_name: Atlas Workspace Contract Orchestrator
technical_name: AtlasWorkspaceContractOrchestratorRuntime
cartography_type: system
canonical_source: docs/engineering-knowledge-base/atlas-workspace-contract-orchestrator.md
owner: workspace-intelligence
repo_paths:
  - docs/engineering-knowledge-base/atlas-workspace-contract-orchestrator.md
allowed_changes:
  - Evoluir certification envelope, invalidation rules e readiness gate.
forbidden_changes:
  - Certificar artifact sem source_hashes.
  - Permitir execucao mutativa com artifact rejected/stale.
  - Substituir evidence real por certification envelope.
depends_on:
  - atlas-workspace-intelligence-system
  - atlas-workspace-artifact-fabric
flows_to:
  - atlas-dev
  - atlas-forge
  - atlas-cartography
unlocks:
  - artifact-readiness-gate
  - contract-driven-workspace-execution
  - safer-provider-handoffs
governs:
  - artifact_certification
  - artifact_invalidation
  - workspace_execution_contracts
evidence:
  - docs/engineering-knowledge-base/atlas-workspace-contract-orchestrator.md
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceRuntimeService.php
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceRuntimeProjectionRepository.php
  - app/Console/Commands/AtlasWorkspaceIntelligenceCommand.php
  - app/Http/Controllers/AtlasWorkspaceIntelligenceController.php
  - app/Models/AtlasWorkspaceRuntimeProjectionSnapshot.php
  - database/migrations/2026_05_25_021500_create_atlas_workspace_runtime_projection_snapshots.php
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceSnapshotRepository.php
  - tests/Feature/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceRuntimeServiceTest.php
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: critical
visual_tags:
  - contract
  - certification
  - artifact
  - readiness
ai_entrypoints:
  - Leia este doc antes de implementar certificacao de artifacts, readiness gate ou invalidacao.
ai_usage_notes:
  - Se o artifact nao e certificado, trate como draft.
quality_gates:
  - docs-health
  - php artisan atlas:workspace-intelligence contracts --workspace=atlas --json --strict
failure_modes:
  - Artifact ruim receber selo.
  - Certificacao antiga sobreviver a mudanca de repo.
  - Orquestrador virar burocracia sem bloquear risco real.
observability_signals:
  - artifact_certification_hash
  - artifact_status
  - invalidation_reason
  - execution_readiness_status
next_actions:
  - Persistir contratos dedicados por runtime hash.
  - Criar stale invalidation por git/docs/testes.
  - Integrar projection no Control Plane e Cartografia.
---
# Atlas Workspace Contract Orchestrator

## Resumo

**Nome canonico / produto:** Atlas Workspace Contract Orchestrator  
**Acronimo tecnico:** AWCO  
**Nome interno de experiencia / superficie:** Atlas Project Contract Desk  
**Runtime tecnico:** `AtlasWorkspaceContractOrchestratorRuntime`

AWCO e a camada planejada que valida, certifica, versiona, invalida e orquestra
os artefatos gerados pelo AWAF. Se AWAF produz o material de trabalho, AWCO
decide se esse material esta pronto para guiar execucao.

## Papel no Atlas

AWCO impede que artefatos bonitos mas ruins virem contrato operacional. Ele
garante que Task Packet, Context Pack, Test Plan, Risk Sheet e Handoff Packet
tenham fonte, schema, hash, workspace, validade e estado correto.

## Onde Se Encaixa

```text
AWIS / workspace ativo
-> AWTR + ACIOS
-> AWAF gera artifacts
-> AWCO certifica/invalida/orquestra
-> Dev, Forge, provider ou subagente executa
```

AWCO e gate, nao gerador de conteudo.

## Contratos

Certification envelope:

```json
{
  "schema_version": "atlas.workspace_artifact_certification.v1",
  "workspace_id": "atlas",
  "artifact_hash": "sha256:...",
  "artifact_type": "task_packet",
  "status": "certified",
  "source_hashes_verified": true,
  "stale": false,
  "blocking_reasons": []
}
```

Estados:

- `certified`: pode guiar execucao.
- `limited`: pode guiar leitura/planejamento, nao patch.
- `blocked`: nao usar.
- `superseded`: substituido.
- `expired`: passou validade.

## Fluxo

```text
artifact generated
-> schema validation
-> source hash validation
-> workspace boundary validation
-> stale validation
-> risk validation
-> certification envelope
-> execution readiness
```

## Escopo de Implementacao

Blocos:

| Bloco | Funcao | Saida |
|---|---|---|
| Schema Validator | valida shape | passed/failed |
| Source Hash Verifier | confirma fontes | source status |
| Workspace Boundary Gate | impede bleed | ready/blocked |
| Stale Invalidation | invalida velho | invalidation reason |
| Risk Completeness Gate | exige risco/teste | readiness |
| Certification Envelope | sela artifact | certification hash |
| Execution Readiness Gate | libera Dev/Forge | ready/limited/blocked |

## Dependencias

- AWIS: workspace e boundary.
- AWAF: artefatos.
- AWTR: twin/risk/code map.
- ACIOS: truth pack e continuidade.
- Knowledge Governance: autoridade de fontes.

## Regras para IA

- Nao trate artifact draft como certificado.
- Nao execute patch se certification status nao for `certified`.
- Nao ignore invalidation reason.
- Nao altere source hashes para fazer gate passar.
- Se AWCO bloquear, corrija artifact ou peça decisao humana.

## Evidencias

Evidencia atual: AWCO possui projection dedicada via
`atlas:workspace-intelligence contracts` e endpoint
`/atlas-code/workspace-intelligence/contracts`. O payload inclui certification
envelope, artifact certifications, blocked artifacts, versioning policy,
invalidation rules, orchestration plan e contract hash deterministico. Com
`--persist`/`persist=1`, AWCO e salvo em
`atlas_workspace_runtime_projection_snapshots` e pode ser reaberto por
`latest=1`. O Atlas AI Control Plane agrega AWCO dentro de
`workspace_intelligence`; se uma projection AWCO persistida estiver `blocked`,
o runtime report tambem fica `blocked` com blocker
`workspace_intelligence_projection_blocked`. Replays `latest=1` agora sao
bloqueados quando o `workspace_hash` atual diverge do hash persistido; snapshots
sem hash verificavel tambem falham fechados. O binding salvo em cada projection
usa `awis_projection.schema_version =
atlas.awis.runtime_projection_binding.v1`, com `workspace_id`,
`workspace_hash`, `runtime_hash`, `family`, `projection_hash` e politica
fail-closed para impedir contrato stale de liberar execucao.

Comandos planejados:

```bash
php artisan atlas:workspace-intelligence contracts --workspace=atlas --json --strict
curl /atlas-code/workspace-intelligence/contracts?workspace=atlas
```

## Riscos

- Certificar artifact incompleto.
- Falhar em invalidar artifact apos mudanca de git head/docs/testes.
- Bloquear demais e reduzir velocidade sem ganho real.
- Deixar provider receber handoff desatualizado.

Mitigacoes:

- hashes e validity windows;
- stale invalidation;
- evidence refs;
- status limited para planejamento;
- enforcement apenas em execucao mutativa.

## Exemplos

Task Packet gerado para bug de login:

```text
AWAF gera task packet
AWCO valida auth risk + test plan + owner docs
se faltar teste: status limited
se completo: certified
Dev usa o artifact
```

## Definition Of Done

AWCO esta pronto quando:

- valida schemas dos artifacts AWAF;
- bloqueia artifact sem workspace_id;
- declara regras de invalidacao para artifact stale;
- certifica Task Packet, Context Pack, Test Plan e Handoff Packet;
- Dev/Forge respeitam readiness;
- provider/subagente nunca recebe artifact blocked;
- `atlas:workspace-intelligence contracts --workspace=atlas --json --strict` fica verde.

## Proximas Acoes

1. Refinar Cartografia para abrir AWCO com blocked artifacts/versioning policy por workspace.
2. Evoluir invalidation para fingerprints por artifact individual.
