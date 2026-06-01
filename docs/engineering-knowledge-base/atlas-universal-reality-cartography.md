---
id: atlas-universal-reality-cartography
type: engineering_knowledge
title: Atlas Universal Reality Cartography
status: active
category: cartography
priority: 100
summary: Doc filha da ADRS que define AURC como a superficie visual universal para humanos entenderem documentacao, codigo, fluxos, empresas, projetos, provas e riscos por Cartografia em escala.
human_summary: Transforma a documentacao em mapa visual para o humano entender sistemas, fluxos, riscos e provas sem precisar ler centenas de arquivos.
human_what: Superficie visual que transforma docs canonicos em mapa navegavel por escala, relacao, fonte, risco e prova.
human_purpose: Dar acesso humano real a documentacao e arquitetura sem exigir leitura manual de centenas de arquivos.
human_input: Recebe docs canonicos, system graph, relacoes, status, fontes, riscos, provas e projetos externos indexados.
human_output: Entrega Cartografia com zoom semantico, modais humanos, deep links, cenas e sinais de saude documental.
human_change_when: Mexa quando mudar contrato visual, grafo, fonte canonica, fluxo de zoom, modal ou leitura humana.
human_block_when: Bloqueie quando o mapa esconder fonte, mostrar texto demais, confundir humano ou renderizar verdade sem doc canonica.
human_name: Cartografia Universal da Realidade
canonical_name: Atlas Universal Reality Cartography
technical_name: AtlasUniversalRealityCartographyService
cartography_type: surface
canonical_source: docs/engineering-knowledge-base/atlas-universal-reality-cartography.md
tags:
  - atlas-ai
  - cartography
  - documentation-reality
  - human-access
  - semantic-zoom
  - multi-project
capabilities:
  - universal_reality_cartography
  - human_visual_documentation_access
  - universal_reality_semantic_zoom
  - cross_project_cartography
  - visual_reality_navigation
  - cartography_task_simulation
  - human_clarity_score_9_8
  - awis_runtime_projection_stale_visualization
decisions:
  - Nome canonico/produto obrigatorio: Atlas Universal Reality Cartography.
  - Acronimo tecnico obrigatorio: AURC.
  - Nome interno de experiencia/superficie: Atlas Universe Map.
  - Runtime tecnico atual: AtlasUniversalRealityCartographyService.
  - AURC e filha do Atlas Documentation Reality System; ela nao cria segunda documentacao.
  - AURC usa Cartographic Knowledge OS como contrato visual, mas foca no produto universal multiempresa/multiprojeto.
  - O humano deve entender 90% do fluxo por imagem, posicao, escala, cor, movimento e relacao; texto denso fica no modal.
  - Meta operacional de clareza humana visual: score minimo 9.8 no contrato `atlas.universal_reality_cartography.human_clarity.v1`.
  - Cada empresa/projeto mantem sua documentacao canonica no proprio repo; AURC consome, indexa e renderiza sem copiar verdade.
  - Stale AWIS runtime projection deve aparecer visualmente como atencao no workspace, sem substituir Control Plane ou docs canonicos.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar quando ADRS, Cartographic Knowledge OS, System Graph, Vault Cartography ou Atlas Desktop Cartografia mudarem contrato.
  - Rodar docs-health, docs-authority-audit e architecture-validate apos alteracoes.
related_paths:
  - docs/engineering-knowledge-base/atlas-documentation-reality-system.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-block-registry.md
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
  - docs/engineering-knowledge-base/atlas-cartography-nomenclature-contract.md
  - docs/engineering-knowledge-base/atlas-system-graph.md
  - docs/engineering-knowledge-base/system-graph/living-architecture-graph-contract.md
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema.md
  - docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema-contracts.md
  - docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md
  - docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - app/Services/Engineering/AtlasUniversalRealityCartographyService.php
  - app/Console/Commands/AtlasUniversalRealityCartographyCommand.php
  - tests/Feature/Engineering/AtlasUniversalRealityCartographyServiceTest.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-universal-reality-cartography
graph_title: Atlas Universal Reality Cartography
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-documentation-reality-system
graph_status: active
graph_source: repo
owner: atlas-cartography
repo_paths:
  - docs/engineering-knowledge-base/atlas-universal-reality-cartography.md
allowed_changes:
  - Evoluir o contrato de produto/superficie AURC, escalas visuais, modos de saida, task simulator e coverage matrix.
  - Criar docs filhas para visual grammar, graph API, semantic zoom, inspector, task simulator e performance quando necessario.
forbidden_changes:
  - Tratar AURC como fonte primaria de verdade.
  - Copiar documentacao canonica de projetos externos para docs canonicos do Atlas.
  - Renderizar node sem source_path, owner, status, evidence ou lacuna explicita.
  - Criar mapa visual que exige leitura longa para entender fluxo macro.
depends_on:
  - atlas-documentation-reality-system
  - atlas-cartographic-knowledge-os
  - atlas-cartography-nomenclature-contract
  - atlas-system-graph
flows_to:
  - atlas-cartography
  - atlas-code
  - atlas-desktop
  - atlas-mobile
unlocks:
  - human-readable-operational-truth
  - visual-doc-navigation
  - cross-project-reality-map
governs:
  - atlas-cartography
  - universal-reality-map
  - human-documentation-access
evidence:
  - docs/engineering-knowledge-base/atlas-universal-reality-cartography.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-system.md
  - docs/engineering-knowledge-base/atlas-documentation-reality-block-registry.md
  - docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md
  - app/Services/Engineering/AtlasUniversalRealityCartographyService.php
  - app/Console/Commands/AtlasUniversalRealityCartographyCommand.php
  - tests/Feature/Engineering/AtlasUniversalRealityCartographyServiceTest.php
evidence_refs:
  - symbol: AtlasUniversalRealityCartographyService
  - command: atlas:universal-reality-cartography
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test tests/Feature/Engineering/AtlasUniversalRealityCartographyServiceTest.php"
  - "php artisan atlas:universal-reality-cartography human-clarity --strict --json"
requires_evidence: true
risk_level: critical
visual_tags:
  - system
  - cartography
  - human-access
  - semantic-zoom
ai_entrypoints:
  - Leia este doc antes de implementar Cartografia universal, mapa de empresas/projetos, zoom semantico, modal humano ou visual coverage.
  - Use ADRS para fronteira de documentacao e Cartographic Knowledge OS para regras visuais gerais.
ai_usage_notes:
  - AURC mostra a verdade; nao substitui docs canonicos, codigo, testes, evidence ou ACRUI.
  - Quando faltar fonte, renderize lacuna explicita e nao invente node.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test tests/Feature/Engineering/AtlasUniversalRealityCartographyServiceTest.php"
  - "php artisan atlas:universal-reality-cartography navigation-slice --strict --json"
failure_modes:
  - Mapa bonito sem fonte real.
  - Universo visual grande demais para humano com TDAH localizar fluxo.
  - Projeto externo misturado com Atlas e virando verdade concorrente.
  - Modal textual tentando compensar mapa visual ruim.
  - IA usando layout como prova de implementacao.
observability_signals:
  - docs-health status ok
  - workspace_scope.runtime_projection_replay.status
  - workspace_scope.runtime_projection_replay.stale_families
  - workspace_scope.artifact_graph_replay.status
  - coverage_audit.visual_completeness_score
  - human_clarity.score
  - human_route_map.invalid_route_count
  - semantic_zoom_scenes.invalid_scene_count
next_actions:
  - Conectar `visual_scene` e `human_clarity` a surface visual da Cartografia.
  - Expandir task simulator com perguntas canonicas de navegacao humana reais.
  - Renderizar stale AWIS runtime projection como badge/alerta visual na UI mobile/desktop.
---
# Atlas Universal Reality Cartography

## Resumo

Atlas Universal Reality Cartography, ou AURC, e a doc filha da ADRS que define
como o humano acessa a documentacao real por Cartografia. Ela transforma docs,
codigo, evidence, status, fluxos e riscos em mapa visual navegavel.

AURC existe porque o humano nao deve depender de ler milhares de linhas para
entender o Atlas ou qualquer empresa/projeto operado pelo Atlas. A leitura deve
ser excecao. O normal e ver: escala, relacao, fluxo, gargalo, estado e prova.

Quando AWIS tem projection persistida divergente do workspace atual, AURC marca
Workspace Intelligence/AWIS como atencao visual e inclui
`workspace_scope.runtime_projection_replay`. Quando AWAIR tem artifact graph
persistido divergente, AURC marca AWAIR/Artifact Graph como atencao visual e
inclui `workspace_scope.artifact_graph_replay`. Isso orienta refresh antes de
confiar em replay, sem transformar Cartografia em fonte primaria.

## Papel no Atlas

AURC e o produto/superficie visual da area ADRS:

```text
ADRS = sistema mae da documentacao real
ACRUI = prova operacional do que existe/esta vivo/esta duplicado
AURC = acesso visual humano e navegacao por Cartografia
```

AURC nao substitui `atlas-cartographic-knowledge-os.md`. A diferenca e:

| Doc | Papel |
|---|---|
| Cartographic Knowledge OS | contrato visual geral da Cartografia |
| AURC | produto/superficie universal para navegar empresas, projetos, sistemas e docs reais |
| ADRS | doc mae que organiza documentacao real, ACRUI e AURC |

## Onde Se Encaixa

```text
Universo
  -> Organizacao
  -> Projeto / Workspace
  -> Sistema
  -> Dominio / Surface / Runtime
  -> Fluxo
  -> Componente
  -> Fonte / Codigo / Teste / Evidence / Risco
```

Exemplo:

```text
Universo
  -> Atlas
     -> Documentation Reality System
        -> ACRUI
        -> AURC
  -> Blackink
     -> repos canonicos Blackink
     -> sistemas Blackink
```

O Atlas pode operar Blackink, Google-like workspaces ou outros negocios. AURC
mostra esses mapas, mas a documentacao canonica de cada empresa fica no local
canonico daquela empresa.

## Contratos

Nome obrigatorio:

| Campo | Valor |
|---|---|
| Nome canonico / produto | Atlas Universal Reality Cartography |
| Acronimo tecnico | AURC |
| Nome interno de experiencia / superficie | Atlas Universe Map |
| Runtime tecnico atual | `AtlasUniversalRealityCartographyService` |
| Schema atual | `atlas.universal_reality_cartography.v1` |

Contratos duros:

1. Todo node visual precisa de source real ou lacuna explicita.
2. Todo zoom muda realidade semantica, nao so tamanho.
3. Tap navega; long press explica.
4. Texto denso fica no modal, nao no mapa.
5. Projeto externo nunca vira doc canonico Atlas por copia.
6. Estado visual deve refletir status real: active, scaffold, future, legacy,
   quarantine, unknown ou blocked.
7. Nenhum visual pode promover claim de implementacao sem evidence.
8. Se humano nao entende o fluxo macro por imagem, o mapa falhou.

Contrato humano da Cartografia:

| Camada | Funcao no modal |
|---|---|
| Essencial | identidade minima: nome humano, nome canonico/tecnico, tipo, status, fonte, o que e, para que serve e quando usar |
| Fluxo | antes -> peca -> depois, com travas principais |
| Relacoes | pai, filhos, dependencias, desbloqueios e fronteiras |
| Evolucao | estado atual, lacunas e proxima acao segura |
| Patamares | saltos de maturidade declarados por `patamar_*` |
| Versoes | releases, schemas e versoes internas da mesma familia |
| Prova e Seguranca | testes, evidence, riscos, allowed/forbidden changes, owner e lacunas |

AURC deve renderizar o mapa para o humano entender 90% por imagem. O modal
serve para confirmar, auditar e agir, nao para substituir a clareza visual.

## Fluxo

Fluxo de construcao visual:

```text
docs/codigo/evidence
-> ADRS authority
-> ACRUI operational classification
-> System Graph / Semantic Graph
-> AURC visual projection
-> Cartografia desktop/mobile
-> humano navega / IA recebe context slice
```

Fluxo de interacao:

```text
tap
-> troca cena para o nivel selecionado
-> mostra filhos, edges, fluxo e estados

long press
-> abre modal humano
-> mostra fonte, prova, riscos, regras, testes, owner e next action
```

## Regras para IA

1. Nunca desenhar node sem source path, owner e status ou lacuna explicita.
2. Nunca usar Cartografia como prova; usar docs, codigo, testes e evidence.
3. Nunca misturar Atlas plataforma com projeto externo operado pelo Atlas.
4. Nunca esconder ausencia de doc com layout bonito.
5. Nunca criar texto longo no mapa para compensar fluxo visual ruim.
6. Sempre seguir `atlas-cartography-nomenclature-contract.md`.
7. Sempre separar visual coverage de implementation readiness.
8. Sempre preservar caminho de volta: universo -> foco -> universo.

## Escopo de Implementacao

| # | Bloco | Funcao | Saida |
|---:|---|---|---|
| 1 | Universe Layer | mostra organizacoes, projetos e fronteiras de verdade | mapa global |
| 2 | Organization Layer | mostra empresa, repos, produtos e areas | mapa da empresa |
| 3 | Project Layer | mostra workspace, docs canonicos, systems e owners | mapa do projeto |
| 4 | System Layer | mostra sistemas, dominios, surfaces, runtimes e evidence | mapa tecnico |
| 5 | Flow Layer | mostra entrada, decisao, execucao, saida e feedback | fluxo visual |
| 6 | Component Layer | mostra modulo, codigo, teste, doc, risco e status | componente auditavel |
| 7 | Semantic Zoom Engine | muda cena por escala e foco | drilldown |
| 8 | Human Modal Engine | gera modal curto, claro e verificavel | explicacao humana |
| 9 | Visual State Encoding | cor/forma/opacidade/selo por status, risco, freshness e confidence | estado legivel |
| 10 | Source Inspector | abre fonte real, related paths, evidence e tests | prova navegavel |
| 11 | Cross-Project Boundary Renderer | separa Atlas de projetos externos | fronteira visual |
| 12 | Visual Completeness Auditor | detecta orfaos, links sem tipo e nodes invisiveis | audit visual |
| 13 | Cognitive Load Meter | mede excesso de nodes/texto/edges por tela | score de clareza |
| 14 | Cartography Task Simulator | testa se humano/IA acha resposta pelo mapa | QA de navegacao |
| 15 | AI Navigation Slice API | retorna context slice por node, profundidade e objetivo | contexto minimo |
| 16 | Reality Replay View | mostra mudancas e fluxo vivo por evidence/replay | movimento auditavel |
| 17 | Human Clarity Score | prova visual hierarchy, microcopy, rotas, zoom e carga cognitiva | nota 9.8 verificavel |

## Modos De Visualizacao

| Modo | Pergunta | Conteudo |
|---|---|---|
| `universe` | onde estou no mundo de organizacoes/projetos? | Atlas, Blackink, outros workspaces |
| `system` | que sistemas existem e como se relacionam? | domains, surfaces, runtimes, docs |
| `flow` | como esta peca se movimenta? | input, decision, execution, output |
| `evidence` | o que prova isso? | tests, commands, receipts, traces |
| `risk` | o que quebra ou esta stale? | blockers, drift, gaps, unknowns |
| `implementation` | como uma IA deve mexer aqui? | owner docs, files, tests, forbidden changes |

## Definition Of Complete

AURC so pode ser tratada como completa quando:

1. Universe -> Organization -> Project -> System -> Flow -> Component funciona.
2. Todo node renderizado tem source ou lacuna explicita.
3. Tap troca cena semanticamente.
4. Long press abre modal humano estruturado.
5. Visual state diferencia active, scaffold, future, legacy, quarantine e unknown.
6. Projetos externos ficam separados por fronteira visual e fonte canonica local.
7. O humano encontra fonte/prova/risco em poucos passos.
8. A IA recebe context slice minimo por node.
9. Auditor detecta orfaos, source ausente e links quebrados.
10. Task simulator valida perguntas canonicas.

## Runtime Atual

O runtime read-only atual ja emite cinco contratos consumiveis por CLI, Atlas Dev,
Forge, Context Pack e UI de Cartografia:

| Contrato | Schema | Uso |
|---|---|---|
| Mapa completo | `atlas.universal_reality_cartography.v1` | grafo fonte para auditoria |
| Visual scene | `atlas.universal_reality_cartography.visual_scene.v1` | cena pronta para UI por modo |
| Semantic zoom scenes | `atlas.universal_reality_cartography.semantic_zoom_scenes.v1` | cenas validas universe -> evidence |
| Human route map | `atlas.universal_reality_cartography.human_route_map.v1` | caminhos humanos para achar doc/prova/status |
| Human clarity | `atlas.universal_reality_cartography.human_clarity.v1` | score 9.8 de clareza visual nao tecnica |
| AI navigation slice | `atlas.universal_reality_cartography.ai_navigation_slice.v1` | contexto minimo provider-safe |

Comandos:

```bash
php artisan atlas:universal-reality-cartography map --strict --json
php artisan atlas:universal-reality-cartography visual-scene --mode=implementation --strict --json
php artisan atlas:universal-reality-cartography semantic-zoom --strict --json
php artisan atlas:universal-reality-cartography human-routes --strict --json
php artisan atlas:universal-reality-cartography human-clarity --strict --json
php artisan atlas:universal-reality-cartography navigation-slice --strict --json
```

Surface HTTP read-only:

```text
GET /atlas-cartography/graph
GET /atlas-cartography/human-clarity
```

`/graph` inclui `human_clarity_contract` para desktop/mobile atuais receberem a
prova junto do grafo principal. `/human-clarity` retorna o mesmo contrato de
forma focada: `human_clarity`, `visual_scene`, `human_route_map` e
`semantic_zoom_scenes`, sem escrita.

Regra: `visual_scene` e contrato de dados, nao fonte primaria. Ele existe para
impedir que qualquer superficie visual invente layout sem source, owner,
status, semantic zoom e prova.

`human_clarity` e o contrato que impede a Cartografia de virar apenas grafo
bonito: a cena precisa ter budget cognitivo, breadcrumb, legenda nao tecnica,
microcopy curta, rotas humanas verificadas, zoom semantico e source real. A meta
minima para declarar o acesso humano visual como excelente e `score >= 9.8`.

## Dependencias

| Dependencia | Uso |
|---|---|
| ADRS | autoridade da area e fronteira com ACRUI |
| ADRS Block Registry | ids, planes, tipos, status, fontes e evaluation refs dos 52 blocos |
| ACRUI | classificacao operacional do que o mapa mostra |
| Cartographic Knowledge OS | regras visuais gerais |
| Nomenclature Contract | separacao patamar/versao/camada/fonte |
| System Graph | hierarquia macro |
| Vault Cartography Schema | projeções Obsidian/Vault |
| Code Intelligence | links para codigo, rotas, comandos e tests |

## Evidencias

Evidencia atual:

```bash
php artisan atlas:universal-reality-cartography map --strict --json
php artisan atlas:universal-reality-cartography visual-scene --strict --json
php artisan atlas:universal-reality-cartography semantic-zoom --strict --json
php artisan atlas:universal-reality-cartography human-routes --strict --json
php artisan atlas:universal-reality-cartography human-clarity --strict --json
php artisan atlas:universal-reality-cartography navigation-slice --strict --json
php artisan atlas:ai:session-bootstrap --task="<task>" --json
php artisan atlas:engineering:knowledge docs-health --json
```

Backlog governado, ainda nao tratado como evidencia runtime:

```bash
npm run test:cartografia
atlas cartography graph validate --json
atlas cartography task-simulator --json
atlas cartography visual-coverage --json
```

## Riscos

| Risco | Controle |
|---|---|
| Mapa lindo mas falso | source/evidence obrigatorio |
| Mapa muito complexo | cognitive load meter |
| Projeto externo misturado | cross-project boundary renderer |
| IA usar visual como prova | regras para IA + source inspector |
| Humano depender de texto | semantic zoom + flow layer |
| Node invisivel gerar falsa ausencia | visual completeness auditor |

## Exemplos

### ADRS

```text
Universe -> Atlas -> Documentation Reality System
  -> ACRUI: realidade operacional
  -> AURC: acesso visual humano
  -> evidence: docs-health, docs-authority, architecture-validate
```

### Atlas Decide

```text
Universe -> Atlas -> Kernel -> Atlas Decide
  -> provider decision
  -> budget/policy
  -> decision receipt
  -> evidence
```

### Projeto Externo

```text
Universe -> Blackink -> repo canonico Blackink
  -> systems Blackink
  -> docs locais
  -> Atlas renderiza sem copiar verdade
```

## Proximas Acoes

1. Conectar `visual_scene` a surface visual da Cartografia.
2. Ampliar perguntas canonicas do Cartography Task Simulator.
3. Adicionar telemetry de human route success quando houver UI.
4. Manter `session-bootstrap` consumindo AURC navigation slice como mapa, nao fonte primaria.
5. Criar coverage matrix para desktop, mobile, CLI e Atlas Code.
