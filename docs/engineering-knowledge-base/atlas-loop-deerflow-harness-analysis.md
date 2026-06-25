---
id: atlas-loop-deerflow-harness-analysis
type: engineering_knowledge
title: Atlas Loop DeerFlow Harness Analysis
status: source_material
implementation_state: documented_source_material
category: autonomous-evolution
priority: 94
summary: Dissecacao do bytedance/deer-flow como material externo para o Atlas Loop. DeerFlow nao e o Loop nem autoridade runtime do Atlas; ele fornece padroes fortes de harness, subagentes, sandbox, tools, MCP, skills, memoria, run journal e configuracao que devem entrar apenas via LoopPatternRegistry, quarentena, eval fresco e verificador independente.
tags:
  - atlas-ai
  - loop
  - autonomous-evolution
  - loop-pattern-registry
  - deerflow-source-material
  - external-agent-harness-analysis
capabilities:
  - external_agent_harness_pattern_intake
  - run_journal_pattern
  - deferred_tool_promotion_pattern
  - subagent_nonrecursive_contract
  - sandbox_virtual_path_pattern
  - loop_repetition_guard_pattern
  - skill_evolution_guard_pattern
decisions:
  - DeerFlow entra como source material de harness e runtime discipline; nunca como runtime paralelo, autoridade de memoria, autoridade de merge ou substituto do ACDE.
  - Padroes uteis de DeerFlow so podem virar Atlas PatternSpec por LoopPatternRegistry, snapshot/hash, quarentena, normalizacao Atlas, eval battery fresca, champion/challenger e verificador independente.
  - O Atlas deve absorver a disciplina de execucao observavel de DeerFlow: run manager, journal, middleware gates, subagent contract, virtual sandbox paths, deferred tools, memory hygiene e reload boundaries.
  - O Atlas nao deve copiar memoria JSON como memoria canonica, stream bridge in-memory para operacao 24/7, scanner LLM como security boundary unica, skill evolution prompt-only ou credenciais/provider auth sem governanca Atlas.
maintenance:
  - Atualizar quando DeerFlow mudar de forma relevante ou quando algum padrao inspirado nele for implementado/promovido no LoopPatternRegistry.
  - Rodar docs lint, sync e index-code depois de alterar esta doc ou docs que a referenciam.
related_paths:
  - docs/engineering-knowledge-base/atlas-loop-pattern-registry.md
  - docs/engineering-knowledge-base/atlas-evolution-loop-acde-runtime.md
  - docs/loop-canonical-definition.md
  - docs/loop-os-architecture.md
  - docs/loop-self-evolution-architecture.md
  - https://github.com/bytedance/deer-flow
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-loop-deerflow-harness-analysis
graph_title: Atlas Loop DeerFlow Harness Analysis
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-loop-pattern-registry
graph_status: active
graph_source: repo
human_name: Atlas Loop DeerFlow Harness Analysis
canonical_name: Atlas Loop DeerFlow Harness Analysis
technical_name: atlas-loop-deerflow-harness-analysis
cartography_type: research
canonical_source: docs/engineering-knowledge-base/atlas-loop-deerflow-harness-analysis.md

owner: autonomous-evolution
repo_paths:
  - docs/engineering-knowledge-base/atlas-loop-deerflow-harness-analysis.md

allowed_changes:
  - Atualizar snapshot, padroes candidatos, rejeicoes e ponte com LoopPatternRegistry.
  - Marcar um padrao como implementado somente com link para codigo, teste e evidence.

forbidden_changes:
  - Declarar DeerFlow runtime authority do Atlas.
  - Promover pattern inspirado em DeerFlow para ready/default sem eval fresco, champion/challenger e verificador independente.
  - Copiar credenciais, provider auth, memoria externa ou fluxo de merge como regra Atlas.

depends_on:
  - atlas-loop-pattern-registry
  - atlas-evolution-loop-acde-runtime
  - atlas-ai-skill-system
  - atlas-evidence-certification-runtime

flows_to:
  - loop-pattern-source-absorption
  - governed-pattern-selection
  - atlas-loop-delivery-pipeline

unlocks:
  - deerflow-harness-pattern-candidates
  - loop-runtime-discipline-improvements

governs:
  - deerflow-source-material

evidence:
  - docs/engineering-knowledge-base/atlas-loop-deerflow-harness-analysis.md
  - https://github.com/bytedance/deer-flow

required_tests:
  - "php artisan atlas:docs:lint-file --path=docs/engineering-knowledge-base/atlas-loop-deerflow-harness-analysis.md --json"

requires_evidence: false
risk_level: medium

next_actions:
  - Criar entries quarantined no PatternSourceIntake para os padroes DeerFlow de maior valor.
  - Construir eval battery fresca do proprio Loop antes de promover qualquer pattern DeerFlow-inspired.
  - Ligar PatternLearningLedger ao certifier/Evidence para medir se esses padroes melhoram entrega real.
---
# Atlas Loop DeerFlow Harness Analysis

Esta doc registra o que vale a pena absorver de `bytedance/deer-flow` para o
Atlas Loop. Ela e deliberadamente uma doc de `source_material`: DeerFlow nao
governa Atlas, nao substitui ACDE e nao vira runtime paralelo. O unico caminho
permitido e `LoopPatternRegistry -> PatternSpec -> ExecutionContract -> gates`.

## Resumo

DeerFlow e uma referencia externa de harness: run manager, journaling,
subagentes, sandbox, MCP, tools, skills, memoria e config. O Atlas deve absorver
somente os padroes que aumentam disciplina de execucao do Loop, sempre via
quarentena no `LoopPatternRegistry`.

## Papel no Atlas

Esta doc informa a importacao governada de padroes para o Loop. Ela ajuda o
Loop a reconhecer estruturas de execucao melhores para o proprio escopo, mas
nao autoriza execucao, merge, memoria, provider auth, UI authority ou egress.

## Onde Se Encaixa

Fica abaixo de `atlas-loop-pattern-registry.md` e ao lado de outras fontes
externas como Loop Library, MachinaOS e maintainer-orchestrator. O ACDE continua
dono de execucao, certifier, Evidence, merge governance e writeback.

## Contratos

- Todo padrao DeerFlow-inspired nasce `source_material` ou `candidate`.
- Promocao exige eval battery fresca, champion/challenger e verificador
  independente.
- `PatternSpec` deve declarar params, outputs, durability, sandbox, lanes,
  authorization, success gate, terminal states, rollback e memory writeback.
- `ExecutionContract` deve preservar journal, gates, terminal state honesto e
  rollback deterministico.

## Fluxo

```text
DeerFlow finding
-> source_snapshot
-> PatternSourceIntake
-> source_material/candidate
-> PatternNormalizer
-> Atlas eval battery
-> champion/challenger
-> independent verifier
-> ready/default only with evidence + rollback
```

## Regras para IA

- Nao copie runtime, provider auth, memoria, stream bridge ou UI authority do
  DeerFlow.
- Nao trate scanner LLM como fronteira unica de seguranca.
- Nao promova pattern externo sem prova Atlas fresca.
- Nao permita subagente recursivo nem autoaprovacao.
- Declare lacunas quando a evidencia vier apenas do snapshot pesquisado.

## Escopo de Implementacao

Implementado agora: documentacao e ligacao canonica com os docs do Loop.
Nao implementado aqui: entries runtime de PatternSourceIntake, eval battery,
ExecutionJournal, repetition guard, output externalization ou MCP session scope.

## Dependencias

- `docs/engineering-knowledge-base/atlas-loop-pattern-registry.md`
- `docs/engineering-knowledge-base/atlas-evolution-loop-acde-runtime.md`
- `app/Services/Ai/AutonomousEvolution/Pattern/`
- Evidence Ledger e certifiers do Loop.

## Evidencias

- Snapshot local `/tmp/atlas-deer-flow-analysis`.
- Commit `90328d5651b22d80e537cc6db8cf54dde551f611`.
- Analise dos diretorios `backend/packages/harness/deerflow/**`.
- Validacao documental por `atlas:docs:lint-file`.

## Riscos

- Copiar runtime externo em vez de importar padrao.
- Confundir memoria DeerFlow com memoria canonica do Atlas.
- Promover source material sem eval.
- Goodhart por medir "harness elegante" em vez de evolucao real do Loop.
- Reintroduzir autonomia prompt-only ou permissao implicita.

## Exemplos

- Use `deerflow_run_journal` quando o Loop precisa provar cada ciclo com ledger.
- Use `deerflow_deferred_tool_promotion` quando um pattern precisa de tool sob
  demanda e schema hash.
- Use `deerflow_subagent_nonrecursive_contract` antes de fan-out multi-agente.
- Use `deerflow_sandbox_virtual_path` quando um pattern toca arquivo/comando.

## Proximas Acoes

1. Criar source entries quarantined para os patterns de maior valor.
2. Construir eval battery do proprio Loop para comparar champion/challenger.
3. Ligar PatternLearningLedger ao certifier/Evidence.
4. Provar ExecutionJournal real antes de expandir autonomia.

## Source Snapshot

Snapshot analisado:

| Campo | Valor |
|---|---|
| Repo | `https://github.com/bytedance/deer-flow` |
| Commit | `90328d5651b22d80e537cc6db8cf54dde551f611` |
| Author date | `2026-06-19T23:15:11+07:00` |
| Commit date | `2026-06-20T00:15:11+08:00` |
| Commit subject | `Avoid blank previews for unsupported artifacts (#3644)` |
| Snapshot local | `/tmp/atlas-deer-flow-analysis` |

Este snapshot e evidencia de pesquisa, nao dependencia do Atlas. Se o upstream
mudar, a entrada deve ser reavaliada e nao simplesmente promovida.

## O Que DeerFlow E

DeerFlow 2.0 e um harness de super-agente: um backend LangGraph/FastAPI, uma UI,
um sistema de subagentes, sandboxes, tools, MCP, skills, memoria persistente,
run manager, journaling, stream e configuracao extensivel.

Para o Atlas, a leitura correta e:

- DeerFlow e um catalogo vivo de padroes de harness.
- DeerFlow nao e o objetivo do Atlas Loop.
- DeerFlow nao e o runtime do Loop.
- DeerFlow nao e fonte canonica de memoria, seguranca, merge ou evidencia.
- DeerFlow mostra como disciplinar execucoes longas com estado, limites,
  isolamento, middleware e observabilidade.

## Arquitetura Observada

Camadas principais do snapshot:

| Camada | Valor para o Atlas |
|---|---|
| `agents/lead_agent` | Factory do agente principal, prompt, middleware chain, tool filtering e skill activation. |
| `runtime/runs` | Run registry, worker, rollback, persistence, status, orphan recovery e shutdown drain. |
| `runtime/journal.py` | Ledger de eventos, token usage, summaries, fontes externas e progress snapshots. |
| `subagents` | Execucao isolada de subagentes com config, timeout, max turns e tools filtradas. |
| `sandbox` | Interface de arquivo/comando, paths virtuais, deny-by-default e limites de leitura/escrita. |
| `mcp` | Session pool persistente, deferred tool discovery e wrappers provider-safe de outputs. |
| `tools` | Built-ins, tool search, output budgeting, error handling e tool catalog. |
| `skills` | Skills com frontmatter, allowed-tools, scanner, storage atomico e skill_manage. |
| `agents/memory` | Memoria por usuario/agente, fila debounce, dedupe, token budget e injecao. |
| `config` | Config YAML ampla e `reload_boundary` para campos que exigem restart. |
| `frontend`/gateway | Superficie de chat/run; util para produto, nao para autoridade do Loop. |

## Runtime Discipline Que Vale Absorver

O maior valor de DeerFlow e a disciplina de execucao. O Atlas Loop precisa
rodar por horas/dias; isso exige contratos que sobrevivem a crash, resume,
limites, token accounting, outputs grandes, subagentes e tools stateful.

### Lead Agent + Middleware

Pontos uteis:

- O agente principal e montado por uma factory; comportamento load-bearing mora
  em middlewares, nao em prompt solto.
- Tracing deve ser anexado na raiz da invocacao; subchamadas internas nao
  devem duplicar tracing.
- O prompt dinamico injeta data/memoria em ponto separado do prompt estatico
  para preservar cache e evitar drift.
- Middleware cobre loop detection, tool output budget, tool error handling,
  clarification stop, safety finish reason, todo, memory, title e token usage.

Adaptacao Atlas: cada capacidade dessa vira pattern/gate ou middleware Atlas,
nunca texto de prompt como contrato.

### Runs + Journal

Pontos uteis:

- Run manager com status persistente, inflight index por thread, retry bounded,
  orphan recovery e shutdown drain.
- Worker registra pre-run snapshot para rollback e persiste completion mesmo em
  erro/interrupcao.
- Journal captura `run.start`, `run.end`, erro, token usage por caller/model,
  primeiro humano, ultima resposta, snapshots e fontes externas dedupadas.

Adaptacao Atlas: o Loop precisa de `ExecutionJournal` obrigatorio por ciclo:
objetivo, PatternSpec, diff, testes, verifier, custo, terminal state, learning e
proximo gargalo. Sem journal, nao ha 24/7 honesto.

### Subagents

Pontos uteis:

- Subagente tem config propria: modelo herdado/custom, tools permitidas,
  tools proibidas, max turns e timeout.
- `task` delega para subagente, mas subagente nao pode chamar `task` de novo.
- Execucao de subagente e isolada, cancelavel e com terminal status exato uma
  vez.
- Ferramentas sao filtradas por config/allowed/disallowed antes da execucao.

Adaptacao Atlas: `agent_lane_policy` deve impedir recursao de subagente e
autoaprovacao. Designer, implementer, verifier, critic e repair precisam ser
lanes declaradas, nao improviso do modelo.

### Sandbox

Pontos uteis:

- Interface explicita para `execute_command`, `read_file`, `write_file`,
  `grep`, `glob`, `list_dir`, `download_file`, `update_file`.
- Paths virtuais separam `/mnt/user-data`, `/mnt/skills`,
  `/mnt/acp-workspace` e mounts customizados.
- `file://`, traversal e host paths sao bloqueados/mascarados.
- Host bash local e opt-in; sandbox local e sandbox de container tem fronteiras
  diferentes.
- Writes grandes e grep/glob sao limitados.

Adaptacao Atlas: `sandbox_profile` do PatternSpec deve ser deny-by-default e
declarar capacidades, caminhos e egress. Sem isso, o pattern compila para
`blocked`.

### Tools, MCP e Deferred Tool Promotion

Pontos uteis:

- Tool catalog pode ser grande; agente ve nomes primeiro e pede schema por
  `tool_search` quando precisa.
- Tools MCP stateful usam session pool persistente por server/scope.
- Catalog hash protege promocoes contra drift.
- Outputs de MCP que apontam arquivo local sao reescritos para caminhos
  virtuais.

Adaptacao Atlas: o Loop deve escolher ferramentas por contrato e registrar
promocao. Um pattern que precisa de tool nova deve declarar `required_tools`,
schema esperado, fallback e terminal `blocked` quando ausente.

### Skills

Pontos uteis:

- Skill exige frontmatter com `name`, `description` e opcional
  `allowed-tools`.
- Skill storage usa escrita atomica, historico e locks.
- Skill manage permite criar/editar/patch/deletar, mas passa por scanner.
- Allowed-tools e union explicita; skill legado sem policy nao ganha tools por
  acidente quando existem policies declaradas.

Adaptacao Atlas: skill externa e fonte de pattern, nao permissao. O Loop pode
criar/otimizar skills, mas so depois de evidence, eval fresca e sem
autoaprovacao.

### Memory

Pontos uteis:

- Memoria escopada por usuario/agente.
- Fila debounce evita escrever learning duplicado a cada turno.
- Updater valida JSON, confidence e fatos; dedupe por conteudo.
- Injecao respeita token budget.

Adaptacao Atlas: Atlas ja possui memoria canonica. Absorver apenas a higiene:
debounce, dedupe, budget e writeback governado. Nao copiar memoria JSON como
fonte canonica.

### Config e Reload Boundary

Pontos uteis:

- `reload_boundary.py` lista campos que exigem restart: database,
  checkpointer, run events, stream bridge, sandbox, log level e channels.
- Campos hot-reload e restart-required nao se misturam implicitamente.

Adaptacao Atlas: qualquer config do Loop que altera runtime, sandbox, memoria,
merge, provider ou watchdog precisa ter boundary explicita: hot reload,
restart, blocked ou approval-required.

### Tests

Pontos uteis:

- Cobertura forte em sandbox, MCP session, deferred tools, auth/user isolation,
  run lifecycle, rollback, stream, memory, skills, subagents, token usage, loop
  detection, safety finish reason e output budget.

Adaptacao Atlas: padrao inspirado em DeerFlow so sobe de `candidate` se houver
teste Atlas para o comportamento equivalente. Teste upstream nao prova Atlas.

## Pattern Candidates Para LoopPatternRegistry

Todos os itens abaixo entram como `source_material` ou, no maximo, `candidate`.
Nenhum e `ready/default` sem eval Atlas.

| Pattern candidate | Ideia DeerFlow | Adaptacao Atlas | Gate minimo |
|---|---|---|---|
| `deerflow_run_journal` | RunJournal com eventos, token usage, summaries e progress snapshots. | ExecutionJournal obrigatorio por ciclo do Loop. | Ciclo registra objective, pattern, diff, tests, terminal state, custo e next bottleneck. |
| `deerflow_deferred_tool_promotion` | Tool catalog por nome, schema sob demanda, catalog hash. | Pattern declara required tools e promove schemas sob hash. | Tool ausente ou schema drift => `blocked`, nunca fallback silencioso. |
| `deerflow_loop_repetition_guard` | Hash de tool calls e sliding window com warn/hard stop. | Repetition guard para loops de implementacao/verificacao. | Repeticao acima do limite vira `stagnated` com journal. |
| `deerflow_tool_output_externalization` | Output grande vira arquivo/preview em sandbox. | Evidence/output grande vai para artifact path com resumo. | Preview + path verificavel; sem truncar prova load-bearing. |
| `deerflow_subagent_nonrecursive_contract` | `task` tool nao e permitido dentro de subagente. | Worker lane nao cria worker; coordena via control-plane. | Violacao de lane policy => fail-closed. |
| `deerflow_stateful_mcp_session` | Session pool por server/scope para tools stateful. | MCP/tool sessions Atlas escopadas por workspace/run. | Session scope registrado; cross-workspace leak bloqueado. |
| `deerflow_sandbox_virtual_path` | Paths virtuais e host path masking. | SandboxProfile com mounts e egress declarados. | Path traversal/host leak/file URL => blocked. |
| `deerflow_skill_evolution_guard` | Skill patch apos erro recorrente, tool count, correcao do usuario. | Loop cria/otimiza skill/pattern apenas por objective score e eval fresca. | Sem champion/challenger, fica `source_material`. |
| `deerflow_provider_safety_tool_suppression` | Safety finish reason suprime tool calls truncados/safety. | Provider stop/refusal nunca executa tool args incompletos. | Safety/refusal => terminal `blocked` ou `approval_required`. |
| `deerflow_memory_hygiene_debounce` | Queue debounce, dedupe e token budget para memoria. | Memory writeback Atlas com allowlist e budget. | Prompt/diff/segredo nunca entram em memoria bruta. |
| `deerflow_runtime_config_boundary` | Reload boundary separa hot reload de restart-required. | Config do Loop declara boundary por campo. | Mudanca sem boundary => docs/test fail. |
| `deerflow_output_budget_middleware` | Resultado grande externalizado antes de afetar contexto. | Cert evidence e run output preservam prova sem explodir contexto. | O verifier consegue resolver o artifact referenciado. |

## O Que O Atlas Nao Deve Copiar

| Item | Por que rejeitar |
|---|---|
| Stream bridge in-memory como base 24/7 | Nao basta para operacao multi-dia/distribuida; Atlas precisa durabilidade auditavel. |
| Memoria JSON como canonica | Atlas memory/docs/Evidence sao canonicos; JSON externo vira no maximo cache/read model. |
| Scanner LLM como fronteira unica de seguranca | Pode ajudar como reviewer, mas policy/sandbox/gates precisam ser deterministicos e fail-closed. |
| Skill evolution prompt-only | O Loop precisa objective score, eval fresca e champion/challenger. |
| UI/chat/IM channels como nucleo do Loop | Sao superficies de produto, nao autoridade de execucao. |
| Direct provider CLI/OAuth copy | Atlas precisa provider governance propria, nao credencial herdada de outro harness. |
| Subagente recursivo | Explode controle, budget e responsabilidade; worker nao cria worker. |
| Success por tool unknown/fallback | Fallback desconhecido compila para `blocked`, nao `success`. |
| Status manual sem prova gerada | Manual counts e labels nao substituem Evidence, tests e receipts. |

## Contrato De Integracao Atlas

Para qualquer pattern inspirado em DeerFlow, a promocao deve seguir:

```text
DeerFlow finding
-> source_snapshot {repo, commit, file area, rationale}
-> PatternSourceIntake
-> source_material/candidate
-> PatternNormalizer para vocabulario Atlas
-> eval battery fresca no escopo do proprio Loop
-> champion/challenger contra pattern atual
-> independent verifier
-> ready/default somente com rollback e evidence
```

Campos obrigatorios do `PatternSpec`:

- `params_schema`
- `output_schema`
- `durability_mode`
- `sandbox_profile`
- `agent_lane_policy`
- `authorization_lattice`
- `success_gate`
- `terminal_states`
- `budget`
- `rollback`
- `memory_writeback`
- `source_snapshot`

Campos obrigatorios do `ExecutionContract` produzido:

- objetivo concreto;
- allowed scope;
- required inputs;
- expected outputs;
- gates independentes;
- terminal states honestos;
- sandbox e lane policy herdados do pattern;
- journal/writeback provider-safe;
- rollback deterministico;
- proximo gargalo a medir.

## Leitura Para O Loop

Se o Loop estiver rodando sobre o proprio escopo, DeerFlow inspira a seguinte
prioridade:

1. Fechar `ExecutionJournal` real antes de aumentar autonomia.
2. Ligar `PatternLearningLedger` ao certifier/Evidence.
3. Criar repetition guard e output externalization para ciclos longos.
4. Definir `agent_lane_policy` nao-recursiva antes de fan-out multi-agente.
5. Tornar sandbox/tool/session boundaries explicitas no PatternSpec.
6. Criar eval battery fresca para comparar padroes.

O ponto central: DeerFlow mostra que harness bom nao e "modelo forte". E
contrato, estado, observabilidade, limites, isolamento e gates. Atlas deve
absorver essa disciplina dentro do seu proprio loop soberano.
