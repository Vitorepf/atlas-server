---
id: atlas-workspace-artifact-intelligence-runtime
type: engineering_knowledge
title: Atlas Workspace Artifact Intelligence Runtime
status: building
category: workspace-intelligence
priority: 95
implementation_state: read_only_runtime_cartography_and_control_plane_shadow_projection_present
summary: Camada final de inteligencia de artefatos do AWIS/AWAF, tornando cada workspace executavel por pacotes vivos, versionados, simulaveis, reutilizaveis e explicaveis pela Cartografia.
tags:
  - atlas
  - workspace
  - artifacts
  - intelligence
  - cartography
  - execution
capabilities:
  - artifact_lake
  - artifact_dependency_graph
  - artifact_branching
  - artifact_replay
  - artifact_simulation
  - artifact_operating_graph
  - artifact_delta_context
  - artifact_proof_bundle
  - artifact_skill_capsule
  - artifact_garbage_collector
  - artifact_context_compiler
  - artifact_quality_governor
  - artifact_cartography_projection
  - artifact_marketplace
  - artifact_outcome_learning
decisions:
  - Artefato operacional e a unidade executavel entre workspace, conversa, Dev, Forge, provider, subagente e Cartografia.
  - Conversa bruta nunca deve ser o estado principal quando existe artefato certificado.
  - Artefato pode ser reutilizado somente com workspace_id, source_hashes, freshness e redaction validos.
  - Cartografia deve mostrar artefatos como mapa vivo, nao como texto longo.
maintenance:
  - Atualizar antes de implementar artifact lake, artifact graph, replay, simulation, marketplace ou Cartografia por artefato.
  - Manter abaixo de 520 linhas.
  - Rodar docs-health apos alterar.
related_paths:
  - docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md
  - docs/engineering-knowledge-base/atlas-workspace-artifact-fabric.md
  - docs/engineering-knowledge-base/atlas-workspace-artifact-operating-layer.md
  - docs/engineering-knowledge-base/atlas-workspace-contract-orchestrator.md
  - docs/engineering-knowledge-base/atlas-workspace-twin-runtime.md
  - docs/engineering-knowledge-base/atlas-continuity-intelligence-os.md
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-workspace-artifact-intelligence-runtime
graph_title: Atlas Workspace Artifact Intelligence Runtime
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-workspace-artifact-fabric
graph_status: planned
graph_source: repo
product_name: Atlas Workspace Artifact Intelligence Runtime
runtime_acronym: AWAIR
internal_product_name: Atlas Artifact Command
technical_runtime: AtlasWorkspaceArtifactIntelligenceRuntime
human_name: Atlas Workspace Artifact Intelligence Runtime
canonical_name: Atlas Workspace Artifact Intelligence Runtime
technical_name: AtlasWorkspaceArtifactIntelligenceRuntime
cartography_type: runtime
canonical_source: docs/engineering-knowledge-base/atlas-workspace-artifact-intelligence-runtime.md
owner: workspace-intelligence
repo_paths:
  - docs/engineering-knowledge-base/atlas-workspace-artifact-intelligence-runtime.md
allowed_changes:
  - Evoluir schemas de artifact graph, replay, simulation, quality score e Cartografia projection.
forbidden_changes:
  - Tratar conversa bruta como artefato certificado.
  - Reutilizar artefato entre workspaces sem redaction e source policy.
  - Permitir provider/subagente consumir artifact stale.
depends_on:
  - atlas-workspace-intelligence-system
  - atlas-workspace-artifact-fabric
  - atlas-workspace-contract-orchestrator
flows_to:
  - atlas-dev
  - atlas-forge
  - atlas-cartography
unlocks:
  - artifact-native-workspace
  - time-travel-replay
  - artifact-driven-cartography
governs:
  - artifact_lake
  - artifact_graph
  - artifact_replay
  - artifact_simulation
  - artifact_quality_score
evidence:
  - docs/engineering-knowledge-base/atlas-workspace-artifact-intelligence-runtime.md
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceRuntimeService.php
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceArtifactIntelligenceRepository.php
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceArtifactShadowExecutionService.php
  - app/Services/Ai/ControlPlane/AtlasAiControlPlaneService.php
  - app/Services/Engineering/AtlasUniversalRealityCartographyService.php
  - app/Models/AtlasWorkspaceArtifactLakeEntry.php
  - app/Models/AtlasWorkspaceArtifactGraphSnapshot.php
  - database/migrations/2026_05_25_021000_create_atlas_workspace_artifact_intelligence_tables.php
  - tests/Feature/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceRuntimeServiceTest.php
  - tests/Feature/Ai/ControlPlane/AtlasAiControlPlaneServiceTest.php
  - tests/Feature/Engineering/AtlasUniversalRealityCartographyServiceTest.php
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: critical
visual_tags:
  - artifact
  - graph
  - replay
  - cartography
ai_entrypoints:
  - Leia este doc antes de implementar artefatos vivos, replay, graph, simulation ou marketplace de artifacts.
ai_usage_notes:
  - Qualquer implementacao deve manter artefatos pequenos, hashaveis, reconstruiveis e explicaveis para humano.
quality_gates:
  - docs-health
  - php artisan atlas:workspace-intelligence artifact-intelligence --workspace=atlas --json --strict
  - php artisan atlas:workspace-artifacts graph --workspace=atlas --json --strict
failure_modes:
  - Artifact lake vira lixeira sem qualidade.
  - Replay reconstrui contexto incompleto.
  - Cartografia mostra artefato sem fonte real.
  - Marketplace reaplica template em stack errada.
observability_signals:
  - artifact_lake_hash
  - artifact_graph_hash
  - replay_success_rate
  - simulation_block_rate
  - artifact_reuse_rate
  - artifact_quality_delta
next_actions:
  - Expor outcome AEMOR do artifact no Control Plane.
---
# Atlas Workspace Artifact Intelligence Runtime

## Resumo

**Nome canonico / produto:** Atlas Workspace Artifact Intelligence Runtime  
**Acronimo tecnico:** AWAIR  
**Nome interno de experiencia / superficie:** Atlas Artifact Command  
**Runtime tecnico:** `AtlasWorkspaceArtifactIntelligenceRuntime`

AWAIR e a evolucao maxima da camada de artefatos do AWIS. Ele transforma um
workspace em um sistema operacional de artefatos: cada decisao, contexto, plano,
teste, falha, outcome e handoff vira uma unidade pequena, versionada,
verificavel e legivel pela Cartografia.

Regra:

```text
Conversa bruta explica origem.
Artefato certificado dirige execucao.
Cartografia mostra a relacao entre artefatos.
```

## Papel no Atlas

Sem AWAIR, o Atlas depende mais de conversa, docs longas e memoria textual. Com
AWAIR, o Atlas trabalha por pecas:

- a tarefa vira `task_packet`;
- o contexto vira `context_pack`;
- o risco vira `risk_sheet`;
- o teste vira `test_plan`;
- a falha vira `failure_capsule`;
- o resultado vira `outcome_record`;
- a continuidade vira `workspace_runbook`;
- a explicacao humana vira Cartografia por artefato.

Isso reduz token, reduz erro de contexto, melhora handoff e deixa o humano ver o
que a IA esta usando.

## Onde Se Encaixa

AWAIR e filho do AWAF dentro do AWIS. Ele recebe workspace, twin, continuidade e
contratos; depois entrega artefatos certificados para Atlas Dev, Atlas Forge,
providers, subagentes, AEMOR e Cartografia.

## Escopo de Implementacao

| Bloco | Funcao | Resultado |
|---|---|---|
| Artifact Lake | guarda artefatos por workspace | fonte unica de artefatos vivos |
| Artifact Dependency Graph | liga task, contexto, teste, diff e outcome | mapa causal da execucao |
| Artifact Branching | permite variantes de plano/contexto | comparar caminhos sem sujar estado |
| Artifact Replay | reconstrui run antigo | continuar sem reler conversa gigante |
| Artifact Simulation | testa plano antes de executar | bloqueia patch/provider call ruim |
| Artifact Context Compiler | compila contexto minimo | menos token sem perder qualidade |
| Artifact Quality Governor | mede utilidade/freshness/coverage | artefato ruim nao guia execucao |
| Artifact Cartography Projection | mostra artefatos visualmente | humano entende sem ler pasta |
| Artifact Marketplace | reusa pacotes abstratos bons | acelera stacks e tarefas recorrentes |
| Artifact Outcome Learning | aprende com resultado real | melhora proximos runs por evidence |

## Saltos Maximos Com Artefatos

AWAIR deve elevar AWIS para execucao orientada por artefatos, nao por chat:

| Salto | Regra | Efeito |
|---|---|---|
| Artifact-First Context | provider recebe artifact pack certificado | reduz token e contexto solto |
| Conversation Collapse | conversas enormes viram graph + current truth | permite retomar runs de dias |
| Subagent Artifact Escrow | subagente recebe pacote minimo e devolve artifact | nao suja contexto principal |
| Artifact Time Travel | replay por hash reconstrui run antigo | elimina dependencia de memoria fraca |
| Artifact Simulation Arena | compara plano A/B/C antes de executar | evita chamadas caras e patches ruins |
| Artifact Diff Budget | mede ganho de cada artifact vs tokens usados | impede burocracia sem valor |
| Artifact Trust Chain | cada artifact aponta fonte, teste e outcome | humano e IA sabem o que e real |

Regra operacional: se um artifact nao reduz risco, token, ambiguidade ou tempo de
handoff, ele nao deve ser criado. Artifact bom substitui conversa longa; artifact
ruim vira ruido institucional.

O detalhamento operacional desses saltos vive em
`atlas-workspace-artifact-operating-layer.md`: workroom, timeline, hash diff,
replay point, artifact route, packet humano e packet seguro para IA.

## Saltos Avancados Com Artefatos

Estes blocos levam AWIS alem de "workspace com contexto" e transformam o
workspace em uma malha operacional auditavel:

| Bloco | O que faz | Bloqueia |
|---|---|---|
| Artifact Merge Room | funde conversas/runs grandes em um pacote coerente | conflito nao resolvido entre fontes |
| Artifact Memory Lens | mostra quais memorias afetaram a decisao | memoria sem workspace_id ou stale |
| Artifact Contract Lock | congela criterios antes de provider/subagente | patch sem acceptance/test/risk |
| Artifact Inbox | fila de artifacts que precisam decisao humana | artifact critico sem dono |
| Artifact Provenance Map | trilha fonte->artifact->execucao->outcome | fonte canonica ausente |
| Artifact Minimal Context Proof | prova que o contexto minimo preserva must_keep | economia com perda de qualidade |
| Artifact Agent Packet | pacote seguro para subagente especialista | prompt bruto ou escopo aberto |
| Artifact Review Duel | compara reviewer humano/IA/local heuristics | aprovacao sem evidencia |
| Artifact Retirement Policy | remove artifact stale do caminho ativo | reuse de artifact vencido |
| Artifact Recovery Point | permite voltar ao ultimo estado confiavel | replay incompleto |

Esses blocos existem para reduzir token sem reduzir qualidade. Se a economia
remove fonte, teste, risco, contrato ou decisao vigente, o artifact deve falhar
em shadow e nao pode dirigir Dev/Forge.

## Artifact-Native Workspace

O salto maximo nao e guardar artefatos; e fazer o workspace operar por
artefatos. Nesse patamar, conversa, docs, testes, outcomes e handoffs viram um
grafo operacional que pode ser consultado, simulado, compactado e reexecutado.

| Capacidade | Funcao | Resultado |
|---|---|---|
| Artifact Operating Graph | grafo executavel de task/context/test/risk/outcome | Atlas sabe qual peca usar antes de chamar modelo |
| Artifact Delta Context | envia ao provider so o delta desde o ultimo artifact certificado | economia de token sem perder must_keep |
| Artifact Proof Bundle | junta fonte, hash, teste, diff, receipt e outcome | resposta "pronto" vira verificavel |
| Artifact Skill Capsule | empacota padrao reutilizavel sem dados do cliente | reuso seguro entre workspaces |
| Artifact Garbage Collector | retira draft/stale/noise do caminho ativo | contexto menor e menos poluido |

Regra: artefato avancado so entra no caminho ativo se melhorar pelo menos um dos
quatro eixos: menos token, menos risco, melhor handoff ou melhor prova.

## Fluxo

```text
pedido humano ruim
-> AWIS escolhe workspace
-> AWTR entende repo/stack/risco
-> ACIOS monta verdade atual
-> AWAIR gera artefatos candidatos
-> AWCO certifica artefatos
-> Dev/Forge/provider/subagente consome pacote pequeno
-> testes/execucao produzem outcome
-> AEMOR/AWEF aprendem sem vazar contexto
-> Cartografia mostra mapa vivo
```

## Contratos

Todo bloco AWAIR deve produzir schema com `workspace_id`, `artifact_hash`,
`source_hashes`, `status`, `consumer`, `freshness` e `quality_score`.

Entrypoint dedicado:

```bash
php artisan atlas:workspace-artifacts graph --workspace=atlas --json --strict
php artisan atlas:workspace-artifacts replay --workspace=atlas --json --strict
php artisan atlas:workspace-artifacts simulate --workspace=atlas --json --strict
php artisan atlas:workspace-artifacts shadow --workspace=atlas --json --strict
```

Esse comando existe para provider/subagente/CI consumir AWAIR sem receber o
envelope AWIS completo.
Com `--latest`, graph/replay/simulate so reabrem artefato persistido se
`workspace_hash` atual bater com o snapshot; hash ausente ou divergente gera
`atlas.awair.artifact_graph_stale.v1` e bloqueia `--strict`.

## Artifact Lake

O lake nao e uma pasta de arquivos soltos. E um registro por workspace com:

- `artifact_id`;
- `artifact_type`;
- `workspace_id`;
- `source_hashes`;
- `artifact_hash`;
- `status`;
- `consumer`;
- `valid_until`;
- `quality_score`;
- `supersedes`;
- `created_from`.

Estados permitidos:

```text
draft -> certified -> consumed -> superseded
                  -> stale
                  -> rejected
```

Somente `certified` pode guiar execucao mutativa.

## Artifact Dependency Graph

O grafo deve responder perguntas operacionais:

- qual contexto gerou este patch?
- qual teste justificou esta execucao?
- qual falha originou este repair?
- qual outcome atualizou este runbook?
- qual artifact esta stale e bloqueia provider?

Schema minimo:

```json
{
  "schema_version": "atlas.workspace_artifact_graph.v1",
  "workspace_id": "atlas",
  "nodes": [],
  "edges": [],
  "graph_hash": "sha256:..."
}
```

## Artifact Branching

Tarefas complexas podem ter variantes:

```text
task_packet
-> branch A: patch minimo
-> branch B: refactor maior
-> branch C: escalar para Forge
```

Branch nao executa codigo. Ela permite comparar custo, risco, testes e contexto
antes de escolher um caminho.

## Artifact Replay

Replay precisa reconstruir o estado minimo:

```text
workspace_id
artifact_hash
source_hashes
task/context/test/risk/outcome
commands/evidence
```

Se o replay perde fonte critica, o artefato vira `stale`.

## Artifact Simulation

Simulation roda antes de provider/subagente em tarefa mutativa:

- arquivos provaveis;
- testes afetados;
- owner docs;
- risco;
- escopo proibido;
- custo de contexto;
- se Dev deve escalar para Forge.

Saida:

```json
{
  "schema_version": "atlas.workspace_artifact_simulation.v1",
  "decision": "ready|blocked|escalate_to_forge",
  "blockers": [],
  "required_artifacts": []
}
```

## Artifact Context Compiler

Compila contexto por hash e delta:

```text
workspace base pack
+ owner docs
+ arquivos relevantes
+ task packet
+ test/risk plan
+ failure/outcome recentes
```

Objetivo: economizar token sem reduzir qualidade. Se `must_keep` perder
cobertura, a economia e rejeitada.

## Artifact Quality Governor

Todo artefato executavel recebe score:

```json
{
  "coverage": 1.0,
  "freshness": "ready",
  "source_integrity": "ready",
  "consumer_fit": "ready",
  "risk_declared": true,
  "tests_declared": true,
  "quality_score": 0.98
}
```

Regra:

```text
quality_score baixo = pode existir como draft, nao pode dirigir execucao.
```

## Dependencias

- AWIS define workspace e bloqueio.
- AWAF define tipos de artefato.
- AWCO certifica freshness, source hashes e consumer fit.
- AWTR fornece mapa de stack, risco e comandos.
- ACIOS fornece verdade atual.
- AEMOR aprende outcomes.

## Regras para IA

- Nao use conversa bruta quando existe artefato certificado.
- Nao envie artifact stale para provider ou subagente.
- Nao reutilize artifact entre workspaces sem redaction e compatibilidade.
- Nao execute tarefa mutativa sem simulation ou quality gate quando o risco for medio/alto.

## Evidencias

Evidencia atual: especificacao canonica e projecao read-only dentro do runtime
AWIS. Persistencia dedicada, comandos proprios e UI ainda sao proximas fatias.

## Riscos

- Artifact Lake virar deposito sem qualidade.
- Replay omitir constraint critica.
- Simulation bloquear demais e reduzir velocidade.
- Marketplace aplicar padrao em stack errada.
- Cartografia exibir artifact sem fonte canonica.

## Exemplos

`bug na tela de login` deve virar task packet, context pack, risk sheet de auth,
test plan focado, simulation e outcome record antes de aprender padrao.

## Cartografia Por Artefato

Cartografia ja deve mostrar a entrada dedicada `AWAIR Artifact Graph` e deve
evoluir para mostrar, por workspace:

- workspace;
- artefatos principais;
- dependencias;
- stale nodes;
- bloqueios;
- qual artifact o provider recebeu;
- qual artifact provou a entrega.

Texto longo fica no modal. A tela principal deve ser visual: grafo, estado, cor,
fluxo e relacao.

## Marketplace Privado

Marketplace reutiliza padroes, nao dados:

- runbook de stack;
- test plan de login;
- risk sheet de auth/payment;
- handoff template de provider;
- failure capsule pattern;
- context recipe.

Todo reuso exige redaction, compatibilidade de stack, source policy e AWCO.

## Definition Of Done

AWAIR esta pronto quando:

- Artifact Lake dedicado existe por workspace;
- Artifact Graph dedicado existe por workspace;
- Replay e simulation existem em shadow executor read-only;
- CLI/API expoem AWAIR sem exigir relatorio AWIS completo;
- Shadow execution bloqueia planos ruins antes do provider;
- AWIS Execution Gate exige shadow execution em modo mutativo;
- Context Compiler economiza token com quality gate;
- Cartografia mostra artefatos sem texto excessivo;
- Dev e Forge consomem `artifact_agent_packet` provider-safe e bloqueiam rota errada;
- AWAOL persiste route/outcome timeline e retirement proposal;
- AEMOR aprende outcome por artifact;
- AWCO certifica freshness, source hashes e consumer fit.

## Proximas Acoes

1. Projetar Artifact Graph dedicado na Cartografia.
2. Expor outcome AEMOR do artifact no Control Plane.
3. Criar score historico por artifact reutilizado.
4. Usar shadow execution para explicar bloqueios no Control Plane.
