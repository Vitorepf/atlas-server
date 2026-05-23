---
id: atlas-workspace-artifact-fabric
type: engineering_knowledge
title: Atlas Workspace Artifact Fabric
status: building
category: workspace-intelligence
priority: 96
implementation_state: read_only_artifact_generation_and_snapshot_persistence_present
summary: Camada planejada que transforma conhecimento do workspace em artefatos operacionais versionados para Dev, Forge, providers, subagentes, Cartografia e memoria.
tags:
  - atlas
  - workspace
  - artifacts
  - dev
  - forge
  - context-pack
capabilities:
  - workspace_brief
  - task_packet
  - context_pack
  - execution_plan
  - test_plan
  - risk_sheet
  - handoff_packet
  - failure_capsule
  - outcome_record
  - workspace_runbook
  - artifact_graph
  - artifact_replay
  - artifact_fusion
  - artifact_simulation
  - artifact_marketplace
  - artifact_intelligence_runtime
decisions:
  - AWAF produz artefatos; AWCO certifica e orquestra contratos.
  - Artefato operacional substitui prompt solto e conversa bruta.
  - Todo artefato deve declarar workspace_id, schema_version, source_hashes e validade.
  - Artefatos gerados sem fonte entram como draft, nao como canon.
  - Artefato vivo deve poder ser refeito, comparado, fundido, simulado e auditado.
maintenance:
  - Atualizar antes de implementar artifacts de workspace, task packet, context pack, handoff pack ou runbook.
  - Manter abaixo de 520 linhas.
  - Rodar docs-health apos alterar.
related_paths:
  - docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md
  - docs/engineering-knowledge-base/atlas-workspace-artifact-intelligence-runtime.md
  - docs/engineering-knowledge-base/atlas-workspace-twin-runtime.md
  - docs/engineering-knowledge-base/atlas-continuity-intelligence-os.md
  - docs/engineering-knowledge-base/atlas-workspace-contract-orchestrator.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-workspace-artifact-fabric
graph_title: Atlas Workspace Artifact Fabric
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-workspace-intelligence-system
graph_status: planned
graph_source: repo
product_name: Atlas Workspace Artifact Fabric
runtime_acronym: AWAF
internal_product_name: Atlas Project Artifact Desk
technical_runtime: AtlasWorkspaceArtifactFabricRuntime
human_name: Atlas Workspace Artifact Fabric
canonical_name: Atlas Workspace Artifact Fabric
technical_name: AtlasWorkspaceArtifactFabricRuntime
cartography_type: system
canonical_source: docs/engineering-knowledge-base/atlas-workspace-artifact-fabric.md
owner: workspace-intelligence
repo_paths:
  - docs/engineering-knowledge-base/atlas-workspace-artifact-fabric.md
allowed_changes:
  - Evoluir schemas de artifacts, builders, source hashes e validity windows.
forbidden_changes:
  - Gerar artifact sem workspace_id.
  - Enviar artifact stale para provider/subagente.
  - Tratar artifact draft como evidence canonica.
depends_on:
  - atlas-workspace-intelligence-system
  - atlas-workspace-twin-runtime
  - atlas-continuity-intelligence-os
flows_to:
  - atlas-dev
  - atlas-forge
  - atlas-cartography
unlocks:
  - artifact-driven-execution
  - provider-safe-handoffs
  - workspace-runbooks
governs:
  - workspace_artifacts
  - task_packets
  - handoff_packets
evidence:
  - docs/engineering-knowledge-base/atlas-workspace-artifact-fabric.md
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceRuntimeService.php
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceSnapshotRepository.php
  - tests/Feature/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceRuntimeServiceTest.php
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: critical
visual_tags:
  - artifacts
  - workspace
  - task-packet
  - handoff
ai_entrypoints:
  - Leia este doc antes de implementar artefatos de workspace, task packet, handoff packet ou runbook.
ai_usage_notes:
  - Prefira artifact pequeno e verificavel a prompt longo.
quality_gates:
  - docs-health
  - future: atlas:workspace-artifacts:certify --json --strict
failure_modes:
  - Artifact stale guiar execucao errada.
  - Handoff packet omitir constraint critica.
  - Task packet virar prompt frouxo sem escopo/teste/risco.
observability_signals:
  - artifact_hash
  - artifact_schema_version
  - artifact_source_hashes
  - artifact_valid_until
  - artifact_replay_hash
  - artifact_quality_score
next_actions:
  - Criar schemas de Workspace Brief, Task Packet, Context Pack e Handoff Packet.
  - Evoluir a camada AWAIR para lake, graph, replay, simulation e Cartografia por artifact.
  - Criar builders shadow read-only.
  - Integrar com AWCO para certificacao.
---
# Atlas Workspace Artifact Fabric

## Resumo

**Nome canonico / produto:** Atlas Workspace Artifact Fabric  
**Acronimo tecnico:** AWAF  
**Nome interno de experiencia / superficie:** Atlas Project Artifact Desk  
**Runtime tecnico:** `AtlasWorkspaceArtifactFabricRuntime`

AWAF e a camada planejada que transforma conhecimento do workspace em artefatos
operacionais. O objetivo e trocar conversa solta por pacotes pequenos,
versionados e verificaveis que qualquer IA consiga usar sem baguncar o projeto.

No estado final, artefato nao e anexo. Artefato e uma unidade operacional viva:
tem fonte, hash, validade, consumidor, replay, qualidade, risco e resultado.

## Papel no Atlas

AWAF faz o Atlas trabalhar por artefatos:

- Workspace Brief;
- Task Packet;
- Context Pack;
- Execution Plan;
- Test Plan;
- Risk Sheet;
- Handoff Packet;
- Failure Capsule;
- Outcome Record;
- Workspace Runbook.

Isso reduz prompt gigante, contexto errado, handoff ruim e teste esquecido.

O salto maior e transformar cada trabalho em uma cadeia de artefatos:

```text
pedido ruim do humano
-> task packet claro
-> context pack minimo
-> execution/test/risk plan
-> handoff packet seguro
-> failure capsule se falhar
-> outcome record se concluir
-> workspace runbook aprende o padrao
```

Assim o Atlas para de depender da conversa como memoria operacional. A conversa
continua existindo, mas o trabalho real passa a viver em artefatos pequenos,
reusaveis e auditaveis.

## Onde Se Encaixa

```text
AWIS / workspace ativo
-> AWTR / entende o workspace
-> ACIOS / verdade atual
-> AWAF / gera artefatos
-> AWCO / certifica contratos
-> Dev, Forge, provider, subagente ou Cartografia
```

## Contratos

Todo artefato deve declarar:

```json
{
  "schema_version": "atlas.workspace_artifact.v1",
  "artifact_type": "task_packet",
  "workspace_id": "atlas",
  "artifact_hash": "sha256:...",
  "source_hashes": [],
  "status": "draft",
  "valid_until": null
}
```

Estados:

- `draft`: gerado, nao certificado.
- `certified`: valido para execucao.
- `stale`: precisa refresh.
- `superseded`: substituido por artifact novo.
- `rejected`: nao usar.

## Fluxo

```text
pedido humano
-> workspace ativo
-> twin + truth pack
-> AWAF gera artifacts
-> AWCO certifica artifacts
-> provider/subagente recebe handoff pequeno
-> outcome record atualiza memoria
```

## Escopo de Implementacao

Artefatos principais:

| Artefato | Funcao | Consumidor |
|---|---|---|
| Workspace Brief | explica o projeto | humano/IA |
| Task Packet | define tarefa | Dev/Forge |
| Context Pack | contexto minimo | provider |
| Execution Plan | ordem de execucao | runtime |
| Test Plan | testes e motivo | Dev/Forge |
| Risk Sheet | riscos e bloqueios | gates |
| Handoff Packet | prompt seguro | subagente/provider |
| Failure Capsule | erro minimo | repair |
| Outcome Record | resultado | AEMOR/AWTR |
| Workspace Runbook | operar projeto | humano/IA |

## Camada Avancada de Artefatos

AWAF final deve incluir seis capacidades acima dos artefatos basicos:
O estado maximo desta camada fica detalhado em
`atlas-workspace-artifact-intelligence-runtime.md` (AWAIR). Este doc governa os
artefatos base; AWAIR governa lake, graph, branching, replay, simulation,
context compiler, quality governor, Cartografia por artifact e marketplace.

| Capacidade | Funcao | Ganho |
|---|---|---|
| Artifact Graph | liga artefatos entre si | mostra dependencia real entre tarefa, contexto, teste e outcome |
| Artifact Replay | reconstrui uma execucao | permite retomar trabalho sem reler conversa enorme |
| Artifact Fusion | funde conversas/runs | transforma 4 conversas longas em um workspace coerente |
| Artifact Simulation | testa plano antes da execucao | reduz patch errado e provider call inutil |
| Artifact Marketplace | reusa pacotes bons | acelera padroes repetidos por repo/stack |
| Artifact Quality Score | mede utilidade real | evita artefato bonito que nao ajuda a execucao |

### Artifact Graph

Cada artefato deve declarar pais, filhos e consumidores:

```json
{
  "artifact_hash": "sha256:task...",
  "parents": ["sha256:workspace_brief..."],
  "children": ["sha256:test_plan...", "sha256:risk_sheet..."],
  "consumers": ["atlas_dev", "atlas_forge", "provider_handoff"]
}
```

Isso permite ao Atlas responder: "qual contexto gerou este patch?", "qual teste
validou esta decisao?" e "qual outcome ensinou este runbook?".

### Artifact Replay

Replay e obrigatorio para trabalhos longos. Um run futuro deve conseguir
reconstruir o estado minimo sem depender da memoria da conversa:

```text
workspace_id + artifact_hash
-> source_hashes
-> task packet
-> context pack
-> plan/test/risk
-> outcome/failure
```

Se o replay nao reconstruir a decisao com fontes suficientes, o artefato entra
como `stale` ou `rejected`.

### Artifact Fusion

Quando varias conversas ou runs apontarem para o mesmo workspace, AWAF nao deve
colar texto. Ele deve fundir por tipo:

- decisoes viram decision records;
- pendencias viram task packets;
- falhas viram failure capsules;
- entregas viram outcome records;
- padroes repetidos viram runbook entries;
- divergencias viram conflict reports para ACIOS.

### Artifact Simulation

Antes de chamar provider ou subagente em tarefa mutativa, AWAF pode gerar uma
simulacao leve:

```text
task packet
-> arquivos provaveis
-> testes afetados
-> riscos
-> comandos necessarios
-> possivel escalacao para Forge
```

A simulacao nao edita codigo. Ela decide se a tarefa esta pronta para execucao
ou se falta contexto, owner doc, teste, workspace readiness ou escopo.

### Artifact Marketplace

Alguns artefatos devem ser reutilizaveis:

- runbooks por stack;
- test plans por tipo de bug;
- risk sheets por area sensivel;
- handoff templates por provider/subagente;
- failure capsule patterns;
- context pack recipes.

Marketplace nao significa copiar entre clientes. Todo artefato reutilizado deve
passar por `workspace_id`, `source_hashes`, redaction e readiness do workspace
atual.

### Artifact Quality Score

Todo artefato executavel deve receber score simples:

```json
{
  "coverage": 1.0,
  "freshness": "ready",
  "source_integrity": "ready",
  "consumer_fit": "ready",
  "risk_declared": true,
  "test_declared": true,
  "quality_score": 0.98
}
```

Artefato com score baixo pode existir como draft, mas nao deve conduzir Dev,
Forge, provider ou subagente.

## Regras de Consumo

- Dev consome `task_packet`, `context_pack`, `test_plan`, `risk_sheet` e `failure_capsule`.
- Forge consome `workspace_brief`, `task_packet`, `execution_plan`, `test_plan`, `risk_sheet`, `handoff_packet` e `outcome_record`.
- Provider/subagente consome `handoff_packet`, nunca conversa bruta.
- Cartografia consome `workspace_brief`, `artifact_graph` e `quality_score`.
- AEMOR consome `outcome_record` e `failure_capsule`.
- ACIOS consome `artifact_fusion`, `conflict_report` e `current_truth_pack`.

## Schemas Minimos

Artefato executavel:

```json
{
  "schema_version": "atlas.workspace_artifact.executable.v1",
  "workspace_id": "atlas",
  "artifact_type": "task_packet",
  "artifact_hash": "sha256:...",
  "source_hashes": ["sha256:..."],
  "status": "certified",
  "validity": {"state": "ready", "expires_at": null},
  "scope": {"allowed_paths": [], "forbidden_paths": []},
  "risk": {"level": "medium", "reasons": []},
  "tests": {"focused": [], "fallback": []},
  "consumer": {"surface": "atlas_dev", "mode": "mutative"}
}
```

Resultado:

```json
{
  "schema_version": "atlas.workspace_artifact.outcome.v1",
  "workspace_id": "atlas",
  "task_packet_hash": "sha256:...",
  "commands_run": [],
  "tests_passed": [],
  "tests_failed": [],
  "changed_paths": [],
  "lessons": [],
  "next_artifacts": []
}
```

## Dependencias

- AWIS: workspace_id e boundaries.
- AWTR: genome, code map, risk map e comandos.
- ACIOS: current truth pack.
- AWCO: certificacao e orquestracao.
- AEMOR: outcome records.

## Regras para IA

- Nao execute sem Task Packet certificado quando a tarefa for mutativa.
- Nao use artifact sem `workspace_id`.
- Nao remova `source_hashes`.
- Nao envie raw conversation se Handoff Packet existir.
- Se artifact estiver stale, gere refresh antes de executar.

## Evidencias

Evidencia atual: esta especificacao, geracao read-only dos 10 artefatos AWAF e
persistencia opcional do snapshot AWIS. Ainda faltam UI e builders dedicados por
artifact.

Comandos planejados:

```bash
php artisan atlas:workspace-artifacts:brief --workspace=atlas --json
php artisan atlas:workspace-artifacts:task --workspace=atlas --task="bug login" --json
php artisan atlas:workspace-artifacts:handoff --artifact=... --json
php artisan atlas:workspace-artifacts:graph --workspace=atlas --json
php artisan atlas:workspace-artifacts:replay --artifact=sha256:... --json
php artisan atlas:workspace-artifacts:simulate --artifact=sha256:... --json
php artisan atlas:workspace-artifacts:certify --workspace=atlas --json --strict
```

## Riscos

- Artifact parece limpo mas omite constraint critica.
- Artifact velho entra no provider.
- Task Packet vira burocracia e nao melhora execucao.
- Runbook nao acompanha mudanca real do repo.

Mitigacoes:

- source hashes obrigatorios;
- stale gate;
- AWCO certificando;
- outcome feedback;
- docs/codigo/testes/receipts acima de artifact.

## Exemplos

Pedido:

```text
"bug na tela de login"
```

Artifacts:

```text
Task Packet: objetivo, escopo, arquivos provaveis, criterios
Risk Sheet: auth/session sensivel
Test Plan: testes focados + fallback
Handoff Packet: prompt pequeno para provider
Outcome Record: patch/teste/resultado
```

## Definition Of Done

AWAF esta pronto quando:

- builders geram artifacts deterministicas;
- todo artifact tem schema, hash, fontes e workspace;
- Dev/Forge consomem Task Packet e Test Plan;
- providers recebem Handoff Packet;
- failures viram Failure Capsule;
- outcomes viram Outcome Record;
- AWCO certifica antes de execucao mutativa.

## Proximas Acoes

1. Definir schemas v1 para os 10 artefatos.
2. Criar builders read-only em shadow.
3. Integrar Task Packet com Atlas Dev.
4. Integrar Handoff Packet com providers/subagentes.
5. Conectar Outcome Record ao AEMOR/AWTR.
