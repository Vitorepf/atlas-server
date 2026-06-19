---
id: atlas-loop-pattern-registry
type: engineering_knowledge
title: Atlas Loop Pattern Registry
status: planned
implementation_state: design_only
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
capabilities:
  - loop_pattern_registry
  - governed_pattern_selection
  - skill_to_execution_contract
  - pattern_champion_challenger
  - external_agent_os_pattern_intake
  - schema_driven_execution_contracts
  - sandbox_profile_selection
  - cli_agent_worktree_patterns
decisions:
  - O LoopPatternRegistry e repertorio governado de estruturas de execucao; nao e runtime paralelo, prompt book ou permissao de autonomia sem gates.
  - Skills externas e catalogs entram como source material/candidate; so viram ready/default depois de avaliacao Atlas, evidencia e rollback.
  - MachinaOS entra como agent workflow OS source material: absorver NodeSpec/plugin/durable-execution/CLI-agent/sandbox patterns, nunca copiar autonomia prompt-only ou runtime inseguro.
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
  - https://signals.forwardfuture.ai/loop-library/
  - https://github.com/zeenie-ai/MachinaOS
  - https://www.npmjs.com/package/machinaos
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

## Source Intake

```text
External source (Loop Library, MachinaOS, paper, repo, skill)
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

## Proximas Acoes

1. Criar AP/spec de implementacao com storage, selector, compiler e eval battery.
2. Seedar primeiro os patterns necessarios para o proprio Loop.
3. Rodar champion/challenger antes de qualquer promocao para `ready/default`.
