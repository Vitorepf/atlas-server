---
id: atlas-loop-pattern-registry
type: engineering_knowledge
title: Atlas Loop Pattern Registry
status: planned
implementation_state: partially_implemented
category: autonomous-evolution
priority: 99
summary: Design canonico do registry que transforma skills, loop catalogs, agent workflow OSs e aprendizados internos em padroes governados de execucao para o Atlas Loop escolher, provar e otimizar.
tags:
  - atlas-ai
  - loop
  - autonomous-evolution
  - skill-system
  - self-construction
  - pattern-registry
  - machinaos-source-material
  - maintainer-orchestrator-source-material
  - deerflow-source-material
capabilities:
  - loop_pattern_registry
  - governed_pattern_selection
  - skill_to_execution_contract
  - pattern_champion_challenger
  - external_agent_os_pattern_intake
  - schema_driven_execution_contracts
  - sandbox_profile_selection
  - cli_agent_worktree_patterns
  - control_plane_orchestrator_patterns
  - decision_ready_handoff
  - authorization_lattice
  - live_proof_gate
  - deferred_tool_promotion
  - run_journal_pattern
decisions:
  - O LoopPatternRegistry e repertorio governado de estruturas de execucao; nao e runtime paralelo, prompt book ou permissao de autonomia sem gates.
  - Skills externas e catalogs entram como source material/candidate; so viram ready/default depois de avaliacao Atlas, evidencia e rollback.
  - MachinaOS entra como agent workflow OS source material: absorver NodeSpec/plugin/durable-execution/CLI-agent/sandbox patterns, nunca copiar autonomia prompt-only ou runtime inseguro.
  - Maintainer Orchestrator entra como source material de control-plane: absorver root-orchestrator/worker-contract, decision-ready handoff, authorization lattice, live-proof gate e release gate; nunca copiar escopo pessoal, credenciais ou GitHub-only workflow como autoridade Atlas.
  - DeerFlow entra como super-agent harness source material: absorver run journal, deferred tools, subagent non-recursion, sandbox virtual paths, MCP session state, tool output budgeting, memory hygiene e config reload boundaries; nunca copiar memoria externa, stream in-memory ou provider auth como autoridade Atlas.
  - O loop escolhe o menor padrao capaz de produzir o maior avanco comprovavel no escopo atual.
  - O loop pode criar ou otimizar padroes proprios, mas a melhoria so e aceita por champion/challenger com gate fresco e verificador independente.
maintenance:
  - Atualizar quando mudar selecao de padroes, importacao de skills externas, pipeline do Loop, Self-Construction OS ou Skill System.
  - Rodar docs lint, sync e index-code depois de alterar esta doc ou docs que a referenciam.
related_paths:
  - docs/loop-canonical-definition.md
  - docs/loop-os-architecture.md
  - docs/loop-self-evolution-architecture.md
  - docs/engineering-knowledge-base/atlas-unified-evolution-loop.md
  - docs/engineering-knowledge-base/atlas-evolution-loop-acde-runtime.md
  - docs/engineering-knowledge-base/atlas-ai-skill-system.md
  - docs/engineering-knowledge-base/atlas-ai-self-construction-os.md
  - docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md
  - docs/engineering-knowledge-base/atlas-loop-deerflow-harness-analysis.md
  - https://signals.forwardfuture.ai/loop-library/
  - https://github.com/zeenie-ai/MachinaOS
  - https://www.npmjs.com/package/machinaos
  - https://github.com/steipete/agent-scripts/blob/main/skills/maintainer-orchestrator/SKILL.md
  - https://github.com/bytedance/deer-flow
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-loop-pattern-registry
graph_title: Atlas Loop Pattern Registry
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-unified-evolution-loop
graph_status: planned
graph_source: repo
human_name: Atlas Loop Pattern Registry
canonical_name: Atlas Loop Pattern Registry
technical_name: LoopPatternRegistry
cartography_type: design
canonical_source: docs/engineering-knowledge-base/atlas-loop-pattern-registry.md

owner: autonomous-evolution
repo_paths:
  - docs/engineering-knowledge-base/atlas-loop-pattern-registry.md

allowed_changes:
  - Atualizar schema, flow, gates, seed set e relacao com Skill System/Self-Construction quando a arquitetura do Loop mudar.

forbidden_changes:
  - Declarar registry implementado, default ou autonomo sem codigo, testes, evidence e gates verdes.
  - Tratar catalog externo como autoridade maior que docs canonicos, Kernel, Evidence ou gates Atlas.
  - Permitir que o mesmo agente crie, aprove e promova um padrao de alto impacto.

depends_on:
  - atlas-unified-evolution-loop
  - atlas-ai-skill-system
  - atlas-ai-self-construction-os
  - atlas-evidence-certification-runtime

flows_to:
  - atlas-loop-delivery-pipeline
  - atlas-skill-evolution-runtime
  - atlas-self-construction-os

unlocks:
  - governed-loop-pattern-selection
  - loop-self-optimized-execution-structures

governs:
  - loop-patterns
  - skill-derived-loop-contracts

evidence:
  - docs/engineering-knowledge-base/atlas-loop-pattern-registry.md
  - https://signals.forwardfuture.ai/loop-library/catalog.json
  - https://github.com/zeenie-ai/MachinaOS
  - https://www.npmjs.com/package/machinaos
  - https://github.com/steipete/agent-scripts/blob/main/skills/maintainer-orchestrator/SKILL.md
  - https://github.com/bytedance/deer-flow

required_tests:
  - "php artisan atlas:docs:lint-file --path=docs/engineering-knowledge-base/atlas-loop-pattern-registry.md --json"

requires_evidence: false
risk_level: high

next_actions:
  - Criar AP de implementacao para storage, selector, seed importer, eval battery e interfaces read-only.
  - Criar adapter de source-intake para agent workflow OSs externos, com snapshot/hash, redacao provider-safe e quarentena.
  - Seed inicial deve cobrir primeiro o proprio escopo do Loop antes de qualquer territorio maior.
---
# Atlas Loop Pattern Registry

`LoopPatternRegistry` e a camada de repertorio que permite ao Atlas Loop escolher
a melhor estrutura de trabalho para cada evolucao. Ele nao executa provider, nao
aprova merge e nao substitui ACDE, Skill System ou Self-Construction OS. Ele
compila conhecimento operacional em um contrato Atlas verificavel.

## Resumo

O Loop nao deve improvisar a cada ciclo. Ele deve reconhecer o tipo de problema,
selecionar um padrao provado, adaptar esse padrao ao escopo atual e so entao
entrar em projecao, implementacao, teste, wiring e registro de evidencia.

Skills externas, como a Loop Library da Forward Future, sao repertorio inicial:
elas ensinam formatos de loop, mas nao mandam no Atlas. O Atlas importa o padrao,
registra fonte/hash, reduz a autoridade ao escopo permitido e exige prova local.

Agent workflow OSs externos, como MachinaOS, entram no mesmo corredor: source
material. Eles podem ensinar forma de plugin, contrato de schema, execucao
duravel, worktree por agente, skill packaging e sandbox, mas nao governam o
Loop. O Atlas absorve o padrao, traduz para contrato Atlas e descarta qualquer
parte que dependa de autonomia prompt-only, execucao insegura ou sucesso
auto-declarado.

Super-agent harnesses externos, como DeerFlow, tambem entram apenas como source
material. Eles podem ensinar run journal, lifecycle de runs, deferred tools,
subagentes nao-recursivos, sandbox virtual-path, MCP sessions, tool output
budget, hygiene de memoria e reload boundaries; nao podem importar memoria
externa, stream in-memory, provider auth, UI/chat authority ou scanner LLM como
fronteira unica de seguranca.

## Papel no Atlas

O registry fica entre EV selection e origination no Loop. Ele escolhe a
estrutura de execucao e entrega um contrato que ProjectionEngine, Orchestrator,
Intent Verifier, Grinder e Certifier conseguem provar.

## Onde Se Encaixa

Ele consome o Skill System, Self-Construction OS, Evidence e ACDE. Ele nao
substitui nenhum deles: skills continuam cuidando de permissao/versao/eval,
ACDE executa/certifica, e Self-Construction governa autoevolucao.

## Contratos

| Campo | Papel |
|---|---|
| `pattern_id` | Nome estavel, por exemplo `ticket_to_pr_ready` ou `self_improving_champion`. |
| `source` | `atlas_native`, `external_skill`, `external_catalog`, `run_learning` ou `operator_seed`. |
| `status` | `source_material`, `candidate`, `ready`, `default`, `deprecated`. |
| `use_when` | Fit operacional, gatilho e pre-condicoes. |
| `inputs_authority` | O que pode ler, quais tools pode usar e quem autoriza. |
| `allowed_writes` | Escopos e artefatos que o padrao pode alterar ou propor. |
| `cycle` | Observe, choose, act, verify, record, repeat/stop. |
| `success_gate` | Prova reprodutivel, independente e observavel. |
| `terminal_states` | Success, no-op, blocked, approval-required, exhausted, stagnated. |
| `guardrails` | Acoes proibidas, aprovacoes obrigatorias e limites de egress. |
| `budget` | Tempo, iteracoes, custo, tentativas, territorio e escalacao. |
| `memory_writeback` | O que registrar em Evidence/Learning sem promover cru. |
| `eval_battery` | Casos frescos que comparam pattern antigo vs challenger. |
| `params_schema` | Schema backend-owned de parametros do pattern; UI/CLI apenas projetam. |
| `output_schema` | Saida esperada, receipts e invariantes que o certifier precisa provar. |
| `durability_mode` | `single_cycle`, `resumable_state_machine`, `campaign_supervised` ou `external_queue_adapter`. |
| `sandbox_profile` | Capacidades explicitamente permitidas: read-only, worktree-write, command, network, credentials, external-egress. |
| `agent_lane_policy` | Como dividir designer, implementer, verifier, critic e repair sem permitir autoaprovacao. |
| `source_snapshot` | Fonte, versao/hash/data e observacoes de drift do material externo. |
| `authorization_lattice` | Permissoes separadas para triage, monitoramento, edicao local, push, CI repair, merge, release, credencial e egress. |
| `decision_ready_boundary` | Condicao minima antes de pedir decisao humana: artefato preparado, prova rodada, tradeoffs claros e escolha exata. |
| `live_proof_gate` | Prova no caminho real afetado; mocks, fixtures e CI complementam, mas nao substituem boundary vivo quando aplicavel. |
| `public_surface_gate` | Varredura de identificadores, provider details, prompts, traces, segredos e dados privados antes de qualquer mutacao publica. |

## MachinaOS Absorption 2026-06-19

MachinaOS foi dissecado como OS local de workflows e agentes: FastAPI backend,
React Flow frontend, plugins de node, skills, agentes CLI, execucao duravel e
sandboxes. A conclusao arquitetural e: ele nao substitui ACDE; ele fornece bons
patterns de produto/runtime para o `LoopPatternRegistry`.

| Valor observado | Adaptacao Atlas | Estado |
|---|---|---|
| Backend-owned node schema / NodeSpec | `PatternSpec` backend-owned; surfaces so renderizam/projetam campos declarados. | candidate |
| Plugin folder + self-registration | Um pattern vive em pasta propria com manifest, compiler, gates e eval battery. | candidate |
| Temporal/Redis/sequential fallback | `durability_mode` escolhe estado resumivel/campaign supervisor antes de executar. | candidate |
| CLI agents com worktree por task | `agent_lane_policy` exige worktree isolada, allowed files, session receipt e callback MCP/provider-neutral. | candidate |
| Skills como arquivos | Skill vira fonte de pattern; precisa pin/hash/quarantine/eval antes de `ready`. | already aligned |
| Monty-style sandbox deny-by-default | `sandbox_profile` deve declarar capacidades opt-in; execucao insegura vira blocked/approval. | candidate |
| Credentials/integration catalog | Pattern declara capability requirements; nao le segredo nem decide credential policy. | candidate |
| Memory/compaction/resume | Pattern escreve Evidence/AEMOR/Learning provider-safe; memoria canonica continua Atlas. | candidate |

O que nao deve ser copiado:

- Autonomia definida por prompt/skill sem certifier independente.
- Python `exec`, Node `vm` ou shell local como fronteira de seguranca.
- Node desconhecido retornando sucesso.
- Docs/status contados manualmente sem geracao ou prova.
- `.env` ou credenciais como material de exemplo versionado.
- Um frontend/workflow OS paralelo para decidir o que o Loop deve fazer.

## Maintainer Orchestrator Absorption 2026-06-19

O `maintainer-orchestrator` de `steipete/agent-scripts` foi dissecado como
skill de control-plane para manutencao multi-repositorio. A conclusao
arquitetural e: ele nao e executor de Loop nem runtime Atlas; ele e um padrao
operacional de coordenacao, autorizacao, prova e decisao humana tardia que deve
alimentar o `LoopPatternRegistry`.

| Valor observado | Adaptacao Atlas | Estado |
|---|---|---|
| Root orchestrator leve + workers por repositorio | `control_plane_orchestrator`: o Loop coordenador observa, delega, monitora e registra; work pesado fica em lanes/workers escopados. | candidate |
| Regra de nao-subdelegacao | `agent_lane_policy` deve impedir worker de criar subworker ou gerenciar outro chat/processo sem permissao do control-plane. | candidate |
| Classificacao `Autonomous` / `Needs owner` / `Ignored by owner` | Queue triage do Loop separa trabalho executavel, decisao humana e excecao explicita do operador. | candidate |
| Decision-ready queue rule | `decision_ready_handoff`: antes de pedir decisao, o Loop prepara PR/patch/prova/tradeoffs ate o limite autonomo. | candidate |
| Owner Decision Brief | `output_schema` para pedidos humanos: URL/titulo, mudanca, beneficio, prova, riscos, recomendacao e escolhas exatas. | candidate |
| Monitoring protocol conservador | Worker so recebe intervencao se ha blocker, conclusao, desvio grosseiro, risco ou conflito com instrucao nova. | candidate |
| Permissoes separadas | `authorization_lattice`: triage, monitoramento, implementacao, push, CI repair, merge/close e release nao implicam uns aos outros. | candidate |
| Credential access minimizado | Pattern declara capability/credential requirement; nao enumera segredos, nao transfere valor entre lanes e pede acesso exato. | candidate |
| Live proof pre-land | `live_proof_gate`: caminho real afetado antes de merge/release; waiver precisa ser explicito e item-specific. | candidate |
| Public Model Identifier Gate | `public_surface_gate`: bloqueia vazamento de model IDs nao publicos, provider details, prompts, traces, segredos e dados sensiveis. | candidate |
| Release gate | `release_readiness_gate`: release so com fila efetiva zerada, CI verde, prova/waiver, checkout limpo, SemVer correto e autorizacao atual. | candidate |
| Compact reporting ledger | `PatternLearningLedger` e report do Loop usam estados `Active`, `Intervened`, `Needs owner`, `Ignored`, `Released`, `Ready next`. | candidate |

O que nao deve ser copiado:

- Escopo pessoal de repositorios, organizacoes excluidas ou regras de ownership
  do autor original.
- GitHub/Codex threads como unica abstracao de worker; Atlas deve usar adapter.
- 1Password/tmux/npm/macOS release como contrato universal.
- Polling fixo de cinco minutos como default global.
- Merge, close, push ou release implicitos por monitoramento ou triage.
- Pergunta humana prematura com link cru, status label ou `land/delete` sem
  briefing decisorio completo.

## DeerFlow Harness Absorption 2026-06-19

`bytedance/deer-flow` foi dissecado como super-agent harness LangGraph/FastAPI:
lead agent, middleware chain, run manager, journal, subagentes, sandbox, MCP,
tools, skills, memoria, gateway e configuracao. A conclusao arquitetural e:
ele nao e o Atlas Loop; ele fornece disciplina de harness para patterns que o
Atlas deve absorver pelo `LoopPatternRegistry`.

Doc dedicada: `docs/engineering-knowledge-base/atlas-loop-deerflow-harness-analysis.md`.

| Valor observado | Adaptacao Atlas | Estado |
|---|---|---|
| Run manager + RunJournal | `deerflow_run_journal`: cada ciclo do Loop registra objective, PatternSpec, diff, testes, token/custo, terminal state, learning e next bottleneck. | source_material |
| Deferred tool search + catalog hash | `deerflow_deferred_tool_promotion`: tools entram por schema sob demanda, hash e terminal `blocked` em drift/ausencia. | source_material |
| Loop detection middleware | `deerflow_loop_repetition_guard`: repeticao de tool/action vira warn, hard stop ou `stagnated`, com receipt. | source_material |
| Tool output budget/externalization | `deerflow_tool_output_externalization`: output grande vira artifact/path verificavel, nao truncamento de prova load-bearing. | source_material |
| Subagent task tool sem recursao | `deerflow_subagent_nonrecursive_contract`: worker lane nao cria worker; fan-out e autoridade ficam no control-plane. | source_material |
| Stateful MCP sessions por scope | `deerflow_stateful_mcp_session`: tool/MCP session escopada por workspace/run, com leak cross-workspace bloqueado. | source_material |
| Sandbox virtual paths | `deerflow_sandbox_virtual_path`: paths virtuais e deny-by-default viram `sandbox_profile` Atlas. | source_material |
| Skill evolution guard | `deerflow_skill_evolution_guard`: criar/patchar skill/pattern so por eval fresca, objective score e sem self-approval. | source_material |
| Safety finish/tool suppression | `deerflow_provider_safety_tool_suppression`: provider safety/refusal nunca executa tool args truncados. | source_material |
| Memory debounce/dedupe/budget | `deerflow_memory_hygiene_debounce`: writeback governado, allowlistado e provider-safe; memoria canonica continua Atlas. | source_material |
| Config reload boundary | `deerflow_runtime_config_boundary`: campo de config do Loop declara hot-reload, restart, blocked ou approval-required. | source_material |

O que nao deve ser copiado:

- Stream bridge in-memory como fundacao 24/7.
- Memoria JSON como canonica.
- Scanner LLM como unica fronteira de seguranca.
- Skill evolution prompt-only.
- UI/chat/IM channels como nucleo do Loop.
- Direct provider CLI/OAuth sem governanca Atlas.
- Subagente recursivo.
- Fallback de tool desconhecida como sucesso.
- Status manual sem Evidence/test/receipt gerado.

## Source Intake

```text
External source (Loop Library, MachinaOS, maintainer-orchestrator, DeerFlow, paper, repo, skill)
-> snapshot/hash + freshness note
-> PatternSourceIntake (provider-safe summary, no Atlas secret/code egress)
-> quarantine as source_material
-> PatternNormalizer (Atlas vocabulary)
-> EvalBatteryBuilder (fresh Atlas cases)
-> champion/challenger
-> ready/default only with independent proof + rollback
```

Intake nao executa codigo externo, nao instala dependencia, nao chama provider e
nao escreve runtime. Ele so materializa propostas de pattern para avaliacao.

## Fluxo

```text
StateOfAtlas
-> candidatos de evolucao
-> LoopPatternRegistry.rank(task, risk, evidence, scope)
-> pattern escolhido + adaptacao minima
-> ExecutionContract Atlas
-> ProjectionEngine / Orchestrator / Grinder / Certifier
-> Evidence + outcome
-> PatternLearningLedger
```

O selector nunca escolhe por titulo, popularidade ou novidade. Ele ranqueia por:
fit do objetivo, permissao de input/output, qualidade do gate, capacidade de
provar progresso, custo, risco, terminal states e historico local de resultado.

## Exemplos

O seed set inicial vem de padroes publicados na Loop Library e deve ser reduzido
ao vocabulario Atlas antes de executar:

| Pattern | Uso inicial no Atlas |
|---|---|
| The docs sweep | Atualizar docs canonicos apos mudanca real de codigo/arquitetura. |
| The architecture satisfaction loop | Refatoracao arquitetural com criterio concreto e checkpoints. |
| The production error sweep | Bugs e falhas reais com raiz, fix e prova. |
| The ticket-to-PR-ready loop | Transformar pedido/bug solto em patch bounded com evidencia. |
| The Loop Harness verification loop | Separar criador e verificador em worktree isolada. |
| The self-improving champion loop | Melhorar prompts, policies, selectors e padroes sem Goodhart. |
| The devil's-advocate loop | Critica adversarial antes de arquitetura/rollout consequente. |
| The fresh-clone loop | Provar setup, docs e onboarding em ambiente limpo. |
| The post-release baseline loop | Registrar baseline apos entrega para medir regressao/evolucao. |
| The full product evaluation loop / The quality streak loop | Avaliar capacidade ampla com cenarios frescos e streak honesto. |
| Maintainer control-plane orchestrator | Coordenar lanes/workers sem subdelegacao, com autorizacao separada, prova viva e handoff decision-ready. |
| Maintainer live-proof/release gate | Bloquear merge/release ate prova real, CI, fila efetiva, waiver explicito ou decisao humana preparada. |
| DeerFlow runtime harness discipline | Registrar cada ciclo como run/journal, controlar tools, subagentes, sandbox, output budget e terminal states. |
| DeerFlow deferred tool promotion | Resolver schemas de tools sob demanda com hash/catalogo e bloquear drift em vez de improvisar. |
| DeerFlow subagent non-recursion | Impedir worker de criar worker; fan-out e autoridade pertencem ao control-plane do Loop. |

O catalog completo pode ficar como `source_material`. Virar `ready/default` exige
casos Atlas, resultado comparavel e rollback.

## Dependencias

Skill e contrato operacional versionado. Pattern e estrutura de ciclo. Uma skill
pode conter varios patterns, e um pattern pode acionar uma ou mais skills. O
registry so guarda a decisao de quando usar a estrutura; o Skill System continua
dono de manifests, permissoes, versoes, pinning, rollback e evals de skill.

Dependencias diretas: `atlas-unified-evolution-loop`, `atlas-ai-skill-system`,
`atlas-ai-self-construction-os`, Evidence Ledger e Self-Improvement Governance
Ladder.

## Auto-Otimizacao

O Loop pode propor pattern novo ou editar pattern existente quando:

1. nenhum pattern `ready/default` cobre o gap;
2. um pattern atual falha repetidamente num terminal state honesto;
3. um challenger vence o champion por margem minima em gate fresco;
4. nenhum guardrail, safety gate ou invariantes de soberania regressa.

Se houver incerteza, mantem o champion. Erro, budget exausto ou run travada nunca
promovem pattern.

## Evidencias

Evidencia inicial: esta documentacao e o catalog externo usado como source
material. Evidencia de implementacao futura exige codigo, testes, receipt,
outcomes comparaveis e diff que prove o registry escolhendo contratos distintos.

## Estados Terminais

| Estado | Significado |
|---|---|
| `success` | Gate observavel passou e evidencia foi registrada. |
| `clean_no_op` | Estado fresco mostra que nada em escopo precisa mudar. |
| `blocked` | Fonte, permissao, tool, credencial ou prova necessaria esta ausente. |
| `approval_required` | A proxima acao e destrutiva, externa, sensivel ou muda policy. |
| `exhausted` | Orcamento acabou sem prova suficiente. |
| `stagnated` | Ciclos repetem as mesmas objecoes sem novo progresso. |

## Riscos

- Goodhart: escolher pattern pela metrica facil em vez do avanco real.
- Autoridade externa: catalog externo virar fonte de verdade acima do Atlas.
- Autoaprovacao: o mesmo agente criar e promover pattern novo.
- Refragmentacao: criar registry paralelo a Skill System, ACDE ou Self-Construction.

## Regras para IA

- Nao importa prompt cru como autoridade.
- Nao ativa agenda, producao, mensagem externa ou merge.
- Nao promove skill externa sem pinning, fonte, hash e rollback.
- Nao substitui o Intent Verifier Factory, Semantic Implementation Certifier,
  Evidence Ledger ou Self-Improvement Governance Ladder.
- Nao deixa o criador aprovar sua propria melhoria de padrao.

## Escopo de Implementacao

1. `LoopPatternRegistry` read-only com seed Atlas + import externo em quarentena.
2. `LoopPatternSelector` usado dentro do `AtlasLoopObjectiveProducer`.
3. `LoopPatternCompiler` gerando `ExecutionContract` e acceptance skeleton.
4. `PatternLearningLedger` recebendo outcome, custo, bloqueios e regressao.
5. `PatternChampionGate` para promover/deprecar patterns via fresh eval battery.

Primeiro territorio: o proprio Loop. O registry so deve ganhar escopo maior
depois de provar que melhora selecao, execucao, verificacao e documentacao do
Loop sem rebaixar a Constituicao.

## Slice 1 — Runtime Nucleus (implementado + testado 2026-06-19)

O primeiro slice runtime existe em `app/Services/Ai/AutonomousEvolution/Pattern/` e e advisory
no produtor. NAO e o registry 24/7 completo; e o nucleo deterministico que permite o proximo salto.

Implementado e testado (phpunit verde, provider-free):

| Classe | Papel | Garantia testada |
|---|---|---|
| `AtlasLoopPatternSpec` | VO imutavel do pattern | fail-closed: sem gate/terminal/sandbox nao constroi; fonte externa nasce `candidate` (nunca selecionavel) |
| `AtlasLoopExecutionContract` | VO do contrato compilado | fail-closed: 14 campos; sem gate/terminal-success/sandbox lanca |
| `AtlasLoopPatternRegistry` | repertorio deterministico | 8 seeds (`docs_sweep`, `loop_harness_verification`, `ticket_to_pr_ready`, `self_improving_champion`, `fresh_clone`, `production_error_sweep`, `devils_advocate`, `post_release_baseline`) + 1 externo em quarentena + 1 tombstone cosmetico depreciado; `selectable()` so devolve `ready/default` |
| `AtlasLoopPatternSelector` | escolhe pattern por objetivo | rejeita trabalho cosmetico/`expected_impact` desprezivel (inclui NaN/±INF); so considera selecionaveis; prefere verificacao forte; roteia por tipo |
| `AtlasLoopPatternCompiler` | Spec + objetivo -> contrato | fail-closed; objetivo vazio lanca; nunca fabrica gate placeholder |
| `AtlasLoopPatternSourceIntake` | ingestao de material externo | sempre `source_material` (no maximo `candidate`); nunca executa/instala/fetch; preserva resumo/fonte/data/rationale |
| `AtlasLoopPatternLearningLedger` | outcomes provider-safe | JSONL fail-closed; descarta chaves nao-allowlistadas (sem prompt/segredo/diff) |
| `AtlasLoopPatternChampionGate` | promocao governada | bloqueia self-approval (proposer==approver), exige eval fresca + vitoria estrita por margem + zero regressao de guardrail; fail-closed |

Integracao (advisory/read-only): `AtlasLoopObjectiveProducer::produce()` anexa `pattern` +
`execution_contract` ao objetivo originado (top-level e no `payload`), atras do flag
`atlas.loop.pattern_advisory_enabled` (default ON, fail-open). NAO reordena nem porta a originacao —
o EV brain + o critico adversarial continuam os unicos decisores. Verificado por
`AtlasLoopObjectiveProducerPatternSelectionTest` e o regression `...EvPickTest`.

Ainda DESIGN (nao implementado neste slice): eval battery fresca real, normalizer de material externo
para vocabulario Atlas, ledger ligado ao certifier/Evidence runtime, e o loop de auto-otimizacao
champion/challenger rodando vivo. O selector e champion gate sao honestos mas dependem de numeros
auto-reportados (impacto/score) ate o eval battery e o outcome real estarem ligados.

## Proximas Acoes

1. Campanha curta do PROPRIO Loop rodando com PatternRegistry + ExecutionContract, medindo se ele
   escolhe melhor trabalho e nao faz cosmetica (a prova viva que falta).
2. Ligar o `AtlasLoopPatternLearningLedger` ao outcome real do certifier/Evidence (fechar o feedback).
3. Construir o eval battery fresco que o `AtlasLoopPatternChampionGate` consome antes de qualquer
   promocao para `ready/default`.
4. Normalizer de source-intake (vocabulario Atlas) antes de promover material externo de `candidate`.
