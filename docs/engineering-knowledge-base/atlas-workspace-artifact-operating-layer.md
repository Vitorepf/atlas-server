---
id: atlas-workspace-artifact-operating-layer
type: engineering_knowledge
title: Atlas Workspace Artifact Operating Layer
status: building
category: workspace-intelligence
priority: 94
implementation_state: workroom_route_diff_replay_timeline_outcome_retire_apply_aemor_cli_api_cartography_present
summary: Camada operacional que transforma artefatos AWIS em workrooms, replay points, diffs, handoffs e pacotes humanos para Dev, Forge, subagentes e Cartografia.
tags:
  - atlas
  - workspace
  - artifacts
  - cartography
  - dev
  - forge
  - handoff
capabilities:
  - artifact_workroom
  - artifact_timeline
  - artifact_hash_diff
  - artifact_replay_point
  - artifact_route
  - artifact_human_packet
  - artifact_agent_packet
  - artifact_merge_room
  - artifact_memory_lens
  - artifact_recovery_point
decisions:
  - Artefato AWIS deve virar unidade de operacao, nao apenas item salvo no lake.
  - Humano ve workroom visual; IA recebe packet minimo verificavel.
  - Replay, diff, fonte, teste, risco e outcome precisam estar no mesmo eixo visual.
  - Conversa bruta continua como auditoria, nunca como contexto principal quando ha artifact certificado.
maintenance:
  - Atualizar antes de implementar drilldown de artefato, artifact workroom, replay point, diff de hash ou handoff visual.
  - Manter abaixo de 520 linhas.
  - Rodar docs-health apos alterar.
related_paths:
  - docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md
  - docs/engineering-knowledge-base/atlas-workspace-artifact-fabric.md
  - docs/engineering-knowledge-base/atlas-workspace-artifact-intelligence-runtime.md
  - docs/engineering-knowledge-base/atlas-workspace-contract-orchestrator.md
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-code-programming-obras-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-conversation-surface-and-atlas-dev-v1.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-workspace-artifact-operating-layer
graph_title: Atlas Workspace Artifact Operating Layer
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-workspace-artifact-intelligence-runtime
graph_status: building
graph_source: repo
product_name: Atlas Workspace Artifact Operating Layer
runtime_acronym: AWAOL
internal_product_name: Atlas Artifact Workroom
technical_runtime: AtlasWorkspaceArtifactOperatingLayerRuntime
human_name: Atlas Workspace Artifact Operating Layer
canonical_name: Atlas Workspace Artifact Operating Layer
technical_name: AtlasWorkspaceArtifactOperatingLayerRuntime
cartography_type: runtime
canonical_source: docs/engineering-knowledge-base/atlas-workspace-artifact-operating-layer.md
owner: workspace-intelligence
repo_paths:
  - docs/engineering-knowledge-base/atlas-workspace-artifact-operating-layer.md
allowed_changes:
  - Evoluir artifact workroom, replay point, timeline, diff, route e packet contracts.
forbidden_changes:
  - Mostrar conversa bruta no workroom por padrao.
  - Permitir provider/subagente consumir artifact sem source policy.
  - Tratar artifact diff visual como prova se nao houver hash/fonte/teste.
depends_on:
  - atlas-workspace-intelligence-system
  - atlas-workspace-artifact-fabric
  - atlas-workspace-artifact-intelligence-runtime
flows_to:
  - atlas-dev
  - atlas-forge
  - atlas-cartography
unlocks:
  - artifact-workrooms
  - artifact-time-travel
  - artifact-native-human-understanding
governs:
  - artifact_workroom
  - artifact_route
  - artifact_replay_point
  - artifact_human_packet
evidence:
  - docs/engineering-knowledge-base/atlas-workspace-artifact-operating-layer.md
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceArtifactWorkroomService.php
  - app/Console/Commands/AtlasWorkspaceArtifactsCommand.php
  - app/Http/Controllers/AtlasWorkspaceIntelligenceController.php
  - app/Services/Ai/Programming/AtlasDevRuntimeService.php
  - app/Services/Ai/Programming/AtlasForgeRuntimeDispatchService.php
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceArtifactIntelligenceRepository.php
  - app/Services/Ai/WorkspaceIntelligence/AtlasWorkspaceArtifactAemorBridgeService.php
  - app/Services/Engineering/AtlasUniversalRealityCartographyService.php
  - app/Models/AtlasWorkspaceArtifactLakeEntry.php
  - app/Models/AtlasWorkspaceArtifactTimelineEvent.php
  - app/Models/AtlasWorkspaceArtifactRetirementProposal.php
  - database/migrations/2026_05_25_021100_create_atlas_workspace_artifact_operating_tables.php
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
  - php artisan atlas:workspace-artifacts workroom --workspace=atlas --json --strict
  - php artisan atlas:workspace-artifacts route --workspace=atlas --artifact=task_packet --json --strict
  - php artisan atlas:workspace-artifacts diff --workspace=atlas --artifact=task_packet --json --strict
  - php artisan atlas:workspace-artifacts replay-point --workspace=atlas --artifact=task_packet --json --strict
  - php artisan atlas:workspace-artifacts outcome --workspace=atlas --artifact=task_packet --outcome-status=passed --summary=ok --json --strict
  - php artisan atlas:workspace-artifacts timeline --workspace=atlas --artifact=task_packet --json --strict
  - php artisan atlas:workspace-artifacts retire --workspace=atlas --artifact=task_packet --reason=stale_context_pack --json --strict
  - php artisan atlas:workspace-artifacts retirement-apply --workspace=atlas --artifact=task_packet --replacement-artifact=... --json --strict
  - php artisan test tests/Feature/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceRuntimeServiceTest.php --filter=api_artifact_timeline
  - php artisan test tests/Feature/Ai/WorkspaceIntelligence/AtlasWorkspaceIntelligenceRuntimeServiceTest.php --filter=workroom
  - php artisan test tests/Feature/Ai/Programming/AtlasForgeRuntimeDispatchTest.php --filter=awaol
requires_evidence: true
risk_level: critical
visual_tags:
  - artifact
  - workroom
  - replay
  - cartography
ai_entrypoints:
  - Leia este doc antes de implementar qualquer UI/CLI/API que abra, compare, roteie ou reutilize artefatos AWIS.
ai_usage_notes:
  - Artefato deve reduzir ambiguidade. Se adiciona texto sem prova, nao e artifact operacional.
quality_gates:
  - docs-health
  - atlas:workspace-artifacts workroom --json --strict
  - atlas:workspace-artifacts route --json --strict
  - atlas:workspace-artifacts diff --json --strict
  - atlas:workspace-artifacts replay-point --json --strict
  - atlas:workspace-artifacts outcome --json --strict
  - atlas:workspace-artifacts timeline --json --strict
  - atlas:workspace-artifacts retire/retirement-apply --json --strict
failure_modes:
  - Workroom virar pagina textual longa.
  - Diff de hash sem explicacao humana.
  - Replay point reconstruir estado incompleto.
  - Subagente receber contexto bruto em vez de packet.
observability_signals:
  - artifact_workroom_hash
  - artifact_route_decision
  - artifact_timeline_event_hash
  - artifact_retirement_proposal_hash
  - artifact_replay_point_hash
  - artifact_diff_count
  - artifact_human_packet_score
next_actions:
  - Criar UI de selecao individual de artifact no mobile/desktop.
  - Expor outcome AEMOR no modal humano e Control Plane.
---
# Atlas Workspace Artifact Operating Layer

## Resumo

**Nome canonico / produto:** Atlas Workspace Artifact Operating Layer  
**Acronimo tecnico:** AWAOL  
**Nome interno de experiencia / superficie:** Atlas Artifact Workroom  
**Runtime tecnico:** `AtlasWorkspaceArtifactOperatingLayerRuntime`

AWAOL ja possui primeira fatia read-only com service, CLI/API e projecao em
Cartografia. A camada faz artefatos AWIS deixarem de ser apenas registros e
virarem espacos operacionais. O humano abre um workroom visual; a IA recebe um
packet minimo; Dev/Forge recebem uma rota; Cartografia mostra fonte, risco,
teste, replay e outcome sem exigir leitura de conversa longa.

Regra central:

```text
Artifact Lake guarda.
AWAIR entende.
AWAOL opera.
Cartografia explica.
Dev/Forge executam somente se o artifact estiver valido.
AEMOR aprende somente outcome com timeline/evidence.
```

## Problema Que Resolve

Sem AWAOL, o Atlas pode ter bons artefatos salvos, mas o humano e a IA ainda
ficam presos a tres problemas:

- abrir uma conversa enorme para entender o que aconteceu;
- pedir para provider/subagente "ler tudo" em vez de receber um pacote curto;
- nao saber qual artefato esta vigente, stale, substituido ou pronto para uso.

AWAOL transforma cada artefato em uma peca navegavel, comparavel, roteavel e
reexecutavel.

## Papel no Atlas

AWAOL e o modo como AWIS entrega artefatos para uso real. AWAF cria os tipos,
AWAIR entende lake/graph/replay/simulation, e AWAOL organiza a operacao diaria:
abrir, comparar, rotear, explicar, recuperar e aposentar artefatos.

## Onde Se Encaixa

```text
AWIS workspace ativo
-> AWAF cria artefatos
-> AWAIR valida inteligencia do artifact
-> AWAOL abre workroom e define rota
-> Dev/Forge/subagente/Cartografia consomem o packet certo
```

## Modelo Mental

```text
Workspace
-> Artifact Lake
-> Artifact Workroom
   -> Essencial humano
   -> Fonte e prova
   -> Timeline
   -> Hash diff
   -> Replay point
   -> Rotas Dev/Forge/subagente
   -> Outcome e memoria
```

O humano nao precisa ler o lake. Ele abre o workroom. A IA nao precisa receber
chat bruto. Ela recebe `artifact_agent_packet`.

## Escopo de Implementacao

Blocos maximos:

| Bloco | Funcao | Resultado |
|---|---|---|
| Artifact Workroom | tela/unidade de trabalho por artefato | humano entende e decide rapido |
| Artifact Timeline | mostra origem, mudancas, consumo e outcome | continuidade sem reler chat |
| Artifact Hash Diff | compara artifact atual vs anterior | detecta drift e regressao |
| Artifact Replay Point | reconstrui estado minimo por hash | retomar trabalho com prova |
| Artifact Route | decide Dev, Forge, subagente, review ou archive | execucao proporcional |
| Artifact Human Packet | resumo 30s + detalhe humano | Cartografia clara para TDAH |
| Artifact Agent Packet | prompt provider-safe minimo | menos token sem perder must_keep |
| Artifact Merge Room | funde conversas/runs/packs conflitantes | verdade atual sem ruido |
| Artifact Memory Lens | mostra memoria/outcome que influenciou artifact | evita memoria invisivel |
| Artifact Recovery Point | volta ao ultimo artifact confiavel | rollback operacional |
| Artifact Retirement Queue | remove artifact stale do caminho ativo | lake nao vira lixeira |
| Artifact Reuse Ledger | mede reuso, ganho, falha e utilidade | aprende quais packs valem |

## Contratos

### Artifact Workroom

```json
{
  "schema_version": "atlas.workspace_artifact_workroom.v1",
  "workspace_id": "atlas",
  "artifact_id": "artifact_123",
  "artifact_hash": "sha256:...",
  "status": "ready|stale|blocked|archived",
  "human_packet": {},
  "agent_packet": {},
  "timeline": [],
  "diffs": [],
  "routes": [],
  "replay_point": {},
  "source_policy": {
    "includes_raw_conversation": false,
    "includes_artifact_body": false
  }
}
```

### Artifact Human Packet

```json
{
  "schema_version": "atlas.workspace_artifact_human_packet.v1",
  "title": "Corrigir bug de login",
  "what_it_is": "Task packet certificado para bug de login",
  "why_it_matters": "Evita patch fora do escopo auth/session",
  "current_status": "ready",
  "safe_next_action": "Rodar Dev com testes focados",
  "source": "docs/... ou conversation_fusion_pack hash",
  "risk": "auth/session",
  "proof": "test plan + source hashes"
}
```

### Artifact Agent Packet

```json
{
  "schema_version": "atlas.workspace_artifact_agent_packet.v1",
  "workspace_id": "atlas",
  "allowed_paths": [],
  "forbidden_paths": [],
  "task": {},
  "must_keep": [],
  "context_refs": [],
  "test_plan": [],
  "risk_sheet": {},
  "done_when": [],
  "redaction": "provider_safe"
}
```

## Fluxo

```text
Artifact Lake entry
-> AWAIR valida fonte, freshness e quality
-> AWAOL cria workroom
-> Cartografia mostra human packet
-> Dev/Forge recebe agent packet se rota permitir
-> Execucao/teste gera outcome
-> AEMOR registra resultado
-> Artifact Timeline atualiza
```

## Regras De Qualidade

- `must_keep_coverage` precisa ser `1.0`.
- `includes_raw_conversation` precisa ser `false` por padrao.
- `includes_artifact_body` precisa ser `false` em Cartografia compacta.
- `source_hashes` nao pode estar vazio em artifact executavel.
- `agent_packet` precisa declarar allowed/forbidden paths.
- `human_packet` precisa responder: o que e, para que serve, estado, risco,
  prova, proxima acao segura.
- `route=dev|forge|subagent` exige replay point pronto.
- `route=archive` exige motivo e substituto quando existir.

## Cartografia

Cartografia deve mostrar artifact workroom de forma visual:

- uma linha do tempo curta;
- badges de status, consumer, score e freshness;
- caminho `fonte -> artifact -> rota -> outcome`;
- diffs de hash como "mudou / nao mudou / stale";
- botao para abrir fonte canonica;
- botao para abrir replay point;
- botao para criar rota Dev/Forge quando permitido.

Texto longo fica no detalhe. A primeira tela precisa explicar 80% visualmente.

## Dev E Forge

Atlas Dev usa AWAOL para patch curto:

```text
artifact route = dev
-> task packet
-> focused tests
-> scope guard
-> failure capsule se falhar
-> outcome memory
```

Atlas Forge usa AWAOL para Obra:

```text
artifact route = forge
-> milestone/work packet
-> context gate
-> simulation
-> senior review
-> outcome by packet
```

Regra: se o artifact indica muitos arquivos, incerteza alta, cross-domain ou
falha repetida, Dev deve escalar para Forge.

## Subagentes

Subagente nunca recebe workroom inteiro. Recebe `artifact_agent_packet`:

- objetivo curto;
- allowed paths;
- forbidden paths;
- source refs;
- teste esperado;
- done_when;
- formato de retorno.

O retorno do subagente tambem vira artifact: `subagent_result_packet`.

## Regras para IA

- Leia AWIS, AWAF e AWAIR antes de implementar AWAOL.
- Nao implemente UI textual longa como substituto de workroom visual.
- Nao envie artifact body ou conversa bruta para provider se agent packet basta.
- Nao permita rota Dev/Forge sem source hashes, risk sheet e test plan.
- Nao marque artifact como pronto se replay point falhar.
- Nao reaproveite artifact entre workspaces sem redaction e compatibilidade.

## Economia De Token

AWAOL economiza token porque troca:

```text
conversa bruta + docs longas + repeticao
```

por:

```text
artifact_agent_packet + source hashes + delta context
```

Essa economia so e valida se o quality gate provar que nenhuma fonte, risco,
teste, contrato ou decisao vigente foi perdida.

## Dependencias

- AWIS fornece workspace, boundary e readiness.
- AWAF define os tipos base de artifacts.
- AWAIR fornece lake, graph, replay, simulation e quality score.
- AWCO certifica source hashes, freshness e consumer fit.
- Cartografia mostra o workroom para humano.
- AEMOR aprende outcome por artifact.

## Evidencias

Estado atual: `AtlasWorkspaceArtifactWorkroomService` cria workroom read-only
provider-safe a partir do report AWIS/AWAIR. `atlas:workspace-artifacts`
expoe `workroom`, `route`, `diff`, `replay-point`, `timeline`, `outcome` e
`retire`, `retirement-queue` e `retirement-apply`; route/outcome/retire/apply
persistem eventos/propostas auditaveis sem remover artifact. A API expoe
`artifact-workroom`, `artifact-timeline`, `artifact-outcome`, `artifact-retirement`,
`artifact-retirement-queue` e `artifact-retirement-apply`. O endpoint
`/atlas-code/workspace-intelligence/artifact-workroom` expoe human packet,
agent packet, route, timeline, diff baseline e replay point sem corpo bruto.
Cartografia expoe `workspace_scope.artifact_workroom` e nodes
`system.awaol`/`flow.workspace-artifact-workroom`.
Dev consome `artifact_agent_packet` como contexto de patch provider-safe.
Forge consome `artifact_agent_packet` somente quando `route_target=forge`.
Outcome abre episodio AEMOR, fecha outcome e gera memory candidate pendente sem
corpo bruto.

## Riscos

Failure modes:

- **Workroom textual demais:** humano volta a depender de leitura longa.
- **Agent packet frouxo:** subagente faz trabalho fora do escopo.
- **Hash diff opaco:** humano ve hash, mas nao entende mudanca.
- **Replay incompleto:** run antigo nao pode ser retomado com seguranca.
- **Artifact stale reutilizado:** contexto velho guia patch novo.
- **Merge room sem decisao:** conflitos ficam escondidos.
- **Retirement ausente:** lake acumula ruido e piora retrieval.

## Exemplos

`bug na tela de login`:

```text
conversation_fusion_pack
-> task_packet: corrigir login
-> artifact workroom: risco auth/session, testes focados, fonte
-> route: Dev se escopo pequeno; Forge se tocar auth + backend + mobile
-> replay point: hash do contexto minimo
-> outcome: teste verde ou failure capsule
```

## Comandos

```bash
php artisan atlas:workspace-artifacts workroom --workspace=atlas --artifact=... --json
php artisan atlas:workspace-artifacts route --workspace=atlas --artifact=... --json --strict
php artisan atlas:workspace-artifacts diff --workspace=atlas --artifact=... --against=latest --json
php artisan atlas:workspace-artifacts replay-point --workspace=atlas --artifact=... --json --strict
php artisan atlas:workspace-artifacts outcome --workspace=atlas --artifact=... --outcome-status=passed --summary=ok --json
php artisan atlas:workspace-artifacts timeline --workspace=atlas --artifact=... --json --strict
php artisan atlas:workspace-artifacts retire --workspace=atlas --artifact=... --reason=stale --json
php artisan atlas:workspace-artifacts retirement-apply --workspace=atlas --artifact=... --replacement-artifact=... --json
```

API: timeline/outcome/retirement/retirement-queue/retirement-apply retornam
somente payload provider-safe.

`retire` propoe. `retirement-apply` aplica status governado sem apagar artifact;
se o artifact for critico, exige replacement antes de ocultar do caminho ativo.

## Saltos De Produto Com Artefatos

AWAOL deve permitir que AWIS deixe de ser apenas "workspace selecionado" e vire
um ambiente operacional por artefatos:

| Salto | Como aparece para humano | Como aparece para IA |
|---|---|---|
| Artifact Workcell | sala por artifact com fonte, risco, timeline e rota | packet minimo para Dev/Forge/subagente |
| Artifact Merge Room | juntar 2+ conversas em verdade atual navegavel | conflict report + current artifact pack |
| Artifact Time Travel | voltar para estado confiavel por replay point | source hashes + replay contract |
| Artifact Outcome Memory | ver se o artifact funcionou ou falhou antes | AEMOR/outcome ligado ao artifact hash |
| Artifact Retirement Flow | tirar stale do caminho ativo sem apagar historico | proposta governada, nunca delete direto |

Regra: artifact so entra no caminho ativo se reduzir contexto, risco ou
ambiguidade. Se ele aumenta texto sem melhorar execucao, deve ficar fora do
provider packet e da primeira tela da Cartografia.

## Definition Of Done

AWAOL esta pronto quando:

- Artifact Workroom existe por CLI/API e Cartografia;
- workroom nunca expoe conversa bruta por padrao;
- human packet tem modo 30s e modo detalhe;
- agent packet e provider-safe e escopado;
- route, diff, replay-point e retire existem como projeções CLI testadas;
- route/outcome/timeline/retire persistem recibos provider-safe por CLI/API;
- diff de hash explica o que mudou;
- replay point bloqueia quando fonte/freshness diverge;
- Dev e Forge recebem `artifact_agent_packet` e bloqueiam rota errada;
- subagente recebe packet minimo e devolve artifact;
- stale/retired artifacts tem fila e apply governado antes de sair do caminho ativo;
- testes provam ausencia de raw tail e artifact body em Cartografia compacta.

## Proximas Acoes

1. Adicionar modal mobile/desktop com selecao de artifact, 30s/detalhe/timeline/diff.
2. Expor fila/apply de retirement no modal mobile/desktop.
