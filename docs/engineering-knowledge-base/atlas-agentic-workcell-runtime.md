---
id: atlas-agentic-workcell-runtime
type: engineering_knowledge
title: Atlas Agentic Workcell Runtime
status: active
category: autonomous-intelligence
priority: 100
summary: Define o AAWR, Atlas Agentic Workcell Runtime, o runtime que transforma metas complexas em organizacoes cognitivas temporarias com topologia, papeis, contexto minimo, verificacao independente, evidencia e aprendizado organizacional.
implementation_state: runtime_implemented_l1_to_l5
macro_layer: true
product_name: Atlas Agentic Workcell Runtime
runtime_acronym: AAWR
internal_product_name: Atlas Cognitive Workcell
technical_runtime: AtlasAgenticWorkcellRuntimeService
tags:
  - atlas-ai
  - aawr
  - agentic-workcell
  - organizational-intelligence
  - multi-agent
  - context-isolation
  - verification
capabilities:
  - workcell_planning
  - topology_selection
  - role_synthesis
  - context_pack_per_role
  - task_graph
  - execution_schedule
  - independent_verification
  - counterfactual_replay_consumption
  - org_pattern_learning
decisions:
  - O nome canonico/produto e Atlas Agentic Workcell Runtime.
  - O acronimo tecnico obrigatorio e AAWR.
  - O nome interno de experiencia/superficie e Atlas Cognitive Workcell.
  - O runtime tecnico canonico e AtlasAgenticWorkcellRuntimeService.
  - AAWR nunca spawna agentes diretamente; ele gera contrato organizacional auditavel.
  - AAWR usa AREG antes de admitir paralelismo ou camadas caras.
  - AAWR exige verificacao independente e evidencia antes de completion.
  - AAWR aprende padroes organizacionais a partir de outcomes.
  - AAWR nao substitui Atlas Agentic Engineering OS, Programming Governance, Atlas Dev ou Atlas Forge; ele fornece contrato organizacional quando o runtime correto precisar de workcell.
maintenance:
  - Atualizar antes de criar novos agentes, topologias, subagentes ou fluxos paralelos.
  - Nao criar multi-agent paralelo fora do AAWR sem contrato de compatibilidade.
  - Nao permitir que AAWR substitua Dev, Forge, AREG, AEMOR ou Hyperflow.
related_paths:
  - docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-documentation-inventory.md
  - docs/engineering-knowledge-base/atlas-runtime-efficiency-governor.md
  - docs/engineering-knowledge-base/atlas-execution-memory-outcome-runtime.md
  - docs/engineering-knowledge-base/atlas-context-intelligence-engine.md
  - docs/engineering-knowledge-base/atlas-persistent-context-runtime.md
  - docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system.md
  - docs/engineering-knowledge-base/atlas-intelligence-factory-os.md
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-agentic-workcell-runtime
graph_title: Atlas Agentic Workcell Runtime
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-ai
graph_status: active
graph_source: repo
human_name: Atlas Agentic Workcell Runtime
canonical_name: Atlas Agentic Workcell Runtime
technical_name: AtlasAgenticWorkcellRuntimeService
cartography_type: system
canonical_source: docs/engineering-knowledge-base/atlas-agentic-workcell-runtime.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-agentic-workcell-runtime.md
allowed_changes:
  - Criar runtime, contracts, commands, read models, tests e certificacao AAWR.
  - Adicionar novas topologias se houver teste e evidencia de utilidade.
forbidden_changes:
  - Spawnear agente real diretamente no AAWR.
  - Invocar provider, executar ferramenta externa ou mutar workspace pelo AAWR.
  - Declarar benchmark ou superioridade externa sem gate proprio.
  - Entregar subagente sem context pack minimo e output contract.
depends_on:
  - atlas-runtime-efficiency-governor
  - atlas-execution-memory-outcome-runtime
  - atlas-context-intelligence-engine
  - atlas-persistent-context-runtime
  - atlas-hyperflow-operation
flows_to:
  - atlas_ai
  - atlas_dev
  - atlas_forge
  - atlas_research
  - atlas_strategy
unlocks:
  - atlas-organizational-intelligence-engine
governs:
  - agentic_workcell_runtime
  - multi_agent_contracts
evidence:
  - docs/engineering-knowledge-base/atlas-agentic-workcell-runtime.md
evidence_refs:
  - symbol: AtlasAgenticWorkcellRuntimeService
  - command: atlas:agentic-workcell
  - test: AtlasAgenticWorkcellRuntimeServiceTest
required_tests:
  - php artisan test tests/Feature/Ai/AgenticWorkcell
  - php artisan atlas:agentic-workcell:certify --json --strict
next_actions:
  - Manter AAWR como contrato organizacional auditavel antes de qualquer paralelismo real.
requires_evidence: true
risk_level: high
line_limit: 520
---
# Atlas Agentic Workcell Runtime

## Resumo

AAWR transforma uma meta complexa em uma **workcell cognitiva temporaria**:
uma organizacao de papeis especializados, contexto minimo por papel, task graph,
schedule, verificacao independente, evidencia, replay e aprendizado. O patamar
maximo e **Atlas Organizational Intelligence Engine**.

## Papel no Atlas

Claude Code/Codex puro operam principalmente como um agente forte por sessao.
AAWR muda o eixo: o Atlas escolhe a organizacao certa para o problema. Ele nao
e "mais agentes"; e engenharia organizacional governada.

AAWR responde:

```text
Qual topologia, papeis, contexto, verificacao e aprendizado esta tarefa exige?
```

## Onde Se Encaixa

Fluxo canonico:

```text
Hyperflow -> AREG -> AAWR -> Dev/Forge/Research/etc consomem contrato
```

AAWR fica acima dos workers e abaixo do roteamento principal. Ele nao executa o
trabalho; ele gera o contrato que permite executar com menos contexto errado,
mais paralelismo util e verificacao independente.

## Contratos

Schemas canonicos:

- `atlas.agentic_workcell.v1`
- `atlas.agentic_workcell.event.v1`
- `atlas.agentic_workcell.outcome.v1`
- `atlas.agentic_workcell.org_pattern.v1`
- `atlas.agentic_workcell.control_plane.v1`
- `atlas.agentic_workcell.certification.v1`

Persistencia:

- `atlas_agentic_workcells`
- `atlas_agentic_workcell_events`
- `atlas_agentic_workcell_outcomes`
- `atlas_agentic_workcell_org_patterns`

## Fluxo

1. Recebe objetivo, dominio, flow, contexto e evidencia.
2. Chama AREG para budget, risco e path.
3. Escolhe topologia organizacional.
4. Sintetiza papeis temporarios.
5. Gera context pack minimo por papel.
6. Cria task graph com ownership e dependencies.
7. Monta execution schedule.
8. Exige verifier, critic e auditor quando risco/escopo justificam.
9. Gera evidence ledger.
10. Gera memory packet e replay contrafactual.
11. Ao fechar outcome, compila org pattern reutilizavel.

## Regras Para IA

- Nunca usar AAWR para chamar provider.
- Nunca spawnear agente real dentro do AAWR.
- Nunca passar contexto bruto completo para todos os papeis.
- Nunca permitir completion sem evidencia.
- Nunca deixar agente revisar o proprio trabalho como unica verificacao.
- Sempre separar lead, worker, critic/verifier e evidence auditor quando risco
  ou escopo forem altos.
- Sempre registrar hash do contrato.

## Escopo De Implementacao

Topologias suportadas:

| Topologia | Uso |
| --- | --- |
| `solo_agent` | tarefa pequena |
| `lead_workers` | trabalho multi-parte |
| `parallel_scouts` | exploracao ampla leve |
| `debate_council` | decisao ambigua |
| `tournament` | comparar solucoes |
| `red_blue_team` | risco alto, estrategia, financas |
| `mapreduce_research` | pesquisa ampla |
| `forge_milestone_crew` | Obra longa |
| `critic_chain` | bloqueio/policy/safety |
| `tool_builder_loop` | gap de capability/tool |

Niveis finais:

- **AAWR-L1 Structured Delegation**: papeis e contratos.
- **AAWR-L2 Context-Isolated Workcells**: context pack por papel.
- **AAWR-L3 Verified Parallel Execution**: schedule + verifier/critic.
- **AAWR-L4 Adaptive Workcell Intelligence**: outcome vira org pattern.
- **AAWR-L5 Organizational Intelligence Engine**: topologia + replay +
  aprendizado organizacional.

## Dependencias

- AREG decide se vale gastar paralelismo e contexto.
- ACIE/APCR alimentam contexto minimo.
- AEMOR registra outcome e aprendizado.
- Forge consome workcells de Obra longa.
- Dev consome workcells de programacao media.
- Hyperflow injeta AAWR no envelope sem executar provider.

## Evidencias

Implementado:

- `AtlasAgenticWorkcellRuntimeService`
- `AtlasAgenticWorkcellCertificationService`
- comandos `atlas:agentic-workcell` e `atlas:agentic-workcell:certify`
- migration `2026_05_20_180000_create_atlas_agentic_workcell_tables.php`
- modelos `AtlasAgenticWorkcell*`
- testes `tests/Feature/Ai/AgenticWorkcell`
- envelope `agentic_workcell` no Hyperflow

## Riscos

| Risco | Mitigacao |
| --- | --- |
| Multi-agent caro | AREG budget antes do AAWR |
| Contexto sujo | Context packs isolados |
| Conflito de ownership | Task graph + schedule |
| Falso sucesso | Verifier independente |
| Agente inventar evidencia | Evidence ledger |
| Aprendizado falso | Outcome exige evidencia |
| Overengineering | Fast/solo path permitido |

## Exemplos

Bug medio:

```text
topology: lead_workers
roles: lead_planner, worker, critic, verifier, synthesizer
```

Pesquisa profunda:

```text
topology: mapreduce_research
roles: source_scout, counter_source_scout, domain_analyst, evidence_auditor
```

Obra:

```text
topology: forge_milestone_crew
roles: lead_architect, cartographer, workers, test_engineer, certifier
```

## Proximas Acoes

1. Alimentar AAWR com outcomes reais de AEMOR em todos os flows.
2. Expor workcells no Control Plane visual.
3. Criar adapter de execucao para agentes reais sem quebrar planning-only.
4. Medir custo/ROI por topologia.
5. Promover org patterns validados para politicas AREG.
