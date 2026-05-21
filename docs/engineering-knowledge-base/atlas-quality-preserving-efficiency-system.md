---
id: atlas-quality-preserving-efficiency-system
type: engineering_knowledge
title: Atlas Quality-Preserving Efficiency System
status: planned
implementation_state: v2_technical_limit_spec_ready; child runtimes partly existing under AUCRI/AREG, ACCCR/ALVE not implemented.
blocker: Implementar ACCCR e ALVE exige AP, receipts, gates e integracao progressiva com Atlas Dev/Forge.
category: intelligence-runtime
priority: 100
summary: Documentacao mae para reduzir custo de token e usar CPU/RAM local como prova, busca, cache, teste e compressao sem reduzir qualidade, evidencia, must-keep ou seguranca.
human_summary: Define como o Atlas economiza token e usa sua maquina local para provar, testar e compactar contexto sem piorar qualidade.
human_what: Sistema-mae de eficiencia com qualidade preservada para contexto, cache, CPU local, RAM, testes e repair.
human_purpose: Reduzir custo e retrabalho sem trocar qualidade por economia aparente.
human_input: Recebe contexto, docs, code graph, diffs, testes, logs, provider profile, budget, memoria, CPU/RAM disponivel e risco.
human_output: Entrega contexto cacheavel, delta minimo, failure capsule, prova local, plano de testes e receipt de economia segura.
human_change_when: Mexa quando mudar prompt caching, compiler, token economy, CPU/RAM policy, verification loop ou Dev/Forge runtime.
human_block_when: Bloqueie quando economia remover must-keep, evidence, owner doc, risco, teste, fonte ou quando CPU/RAM prejudicar o usuario.
macro_layer: true
product_name: Atlas Quality-Preserving Efficiency System
runtime_acronym: AQPES
internal_product_name: Atlas Cognitive Efficiency Plant
technical_runtime: AtlasQualityPreservingEfficiencySystemService
tags: [atlas-ai, efficiency, token-economy, context-cache, local-verification, cpu, ram, quality]
capabilities: [quality_preserving_token_reduction, context_cache, local_verification, cpu_proof_engine, ram_working_memory, failure_capsules, delta_context]
decisions:
  - Economia de token so e aceita quando qualidade, evidencia, must-keep e sufficiency continuam preservados.
  - CPU/RAM local nao substitui modelo frontier; ela prova, testa, indexa, comprime e reduz trabalho cego.
  - Prompt caching e delta context devem vir antes de compressao agressiva.
  - Toda otimizacao precisa de baseline, variante, quality gate, rollback e receipt.
  - AQPES coordena AUCRI, ACCR, ATER, ACPFR, AREG e novos ACCCR/ALVE sem duplicar cada runtime.
maintenance:
  - Atualizar antes de criar runtime de cache, verificacao local, CPU/RAM governor ou token-saving strategy.
  - Rodar docs-health apos alterar.
  - Nao declarar economia como ganho se nao houver quality-preservation receipt.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md
  - docs/engineering-knowledge-base/atlas-context-compiler-runtime.md
  - docs/engineering-knowledge-base/atlas-token-economy-runtime.md
  - docs/engineering-knowledge-base/atlas-context-pareto-frontier-runtime.md
  - docs/engineering-knowledge-base/atlas-aucri-continuous-optimization-protocol.md
  - docs/engineering-knowledge-base/atlas-runtime-efficiency-governor.md
  - docs/engineering-knowledge-base/atlas-retrieval-evaluation-benchmark-arena.md
  - docs/engineering-knowledge-base/atlas-context-observability-plane.md
  - docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md
  - docs/engineering-knowledge-base/atlas-software-twin-verified-evolution-runtime.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-quality-preserving-efficiency-system
graph_title: Atlas Quality Preserving Efficiency System
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-unified-context-retrieval-intelligence
graph_status: planned
graph_source: repo
human_name: Sistema de Eficiencia com Qualidade Preservada
canonical_name: Atlas Quality-Preserving Efficiency System
technical_name: AtlasQualityPreservingEfficiencySystemService
cartography_type: system
canonical_source: docs/engineering-knowledge-base/atlas-quality-preserving-efficiency-system.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-quality-preserving-efficiency-system.md
allowed_changes:
  - Adicionar blocos de cache, CPU proof, RAM working memory, delta context, test impact e failure capsules.
  - Promover child runtime para building/active somente com service, command, tests e receipt.
forbidden_changes:
  - Remover contexto critico para economizar token.
  - Usar CPU/RAM local sem budget e sem reserva minima para o operador.
  - Rodar testes caros em loop sem utility score.
  - Declarar qualidade superior sem AREBA/AEMOR/evidence.
depends_on:
  - atlas-unified-context-retrieval-intelligence
  - atlas-runtime-efficiency-governor
  - atlas-code-reality-usage-intelligence
  - atlas-software-twin-verified-evolution-runtime
flows_to:
  - atlas-dev
  - atlas-forge
  - atlas-context-observability-plane
  - atlas-aucri-continuous-optimization-protocol
unlocks:
  - quality_preserving_token_savings
  - local_cpu_verification_loop
  - prompt_cache_warmup
  - delta_context_runtime
  - failure_capsule_repair
governs:
  - atlas.context_cache.compiler.v1
  - atlas.local_verification.engine.v1
  - atlas.resource_budget.policy.v1
  - atlas.token_savings_quality_receipt.v1
evidence:
  - docs/engineering-knowledge-base/atlas-quality-preserving-efficiency-system.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:context:compile --json"
  - "php artisan atlas:context:token-economy --json"
  - "php artisan atlas:context:pareto-frontier --json"
requires_evidence: true
risk_level: critical
line_limit: 520
next_actions:
  - Implementar ACCCR read-only com Merkle Context Cache e prompt cache warmup.
  - Implementar ALVE read-only com CPU/RAM budget e failure capsules.
  - Conectar AQPES ao Atlas Dev/Forge primeiro em shadow mode.
  - Criar certification command e receipts antes de qualquer enforcement.
---
# Atlas Quality-Preserving Efficiency System

## Resumo

AQPES e a doc-mae da area que busca economia extrema de token e uso inteligente
da maquina local sem reduzir qualidade. A regra central e simples:

```text
economizar token ou CPU so vale se evidencia, must-keep, sufficiency,
seguranca e resultado final continuarem iguais ou melhores.
```

## Papel no Atlas

O Atlas nao deve tentar vencer Claude/Codex/Gemini apenas com prompt. Ele deve
usar o ambiente ao redor do modelo: contexto canonico, cache, CPU, RAM, testes,
indexacao, verificacao, failure capsules e receipts. AQPES coordena essa area
para Atlas AI, Dev, Forge, Research e futuros flows.

## Onde Se Encaixa

```text
APCR/ACIE/AUCRI
-> ACCCR: contexto cacheavel, Merkle pack, delta context
-> ALVE: prova local, testes, static checks, failure capsules
-> ATER/ACPFR: token/custo/qualidade e fronteira de Pareto
-> AREG: orcamento cognitivo, CPU/RAM/tool/provider/subagente
-> Provider forte apenas quando necessario
-> Evidence + AEMOR outcome
```

## Contratos

Schemas obrigatorios: `atlas.context_cache.compiler.v1`,
`atlas.context_merkle_pack.v1`, `atlas.prompt_cache_warmup.v1`,
`atlas.delta_context_request.v1`, `atlas.local_verification.engine.v1`,
`atlas.failure_capsule.v1`, `atlas.resource_budget.policy.v1`,
`atlas.token_savings_quality_receipt.v1` e `atlas.efficiency.certification.v1`.

Campos minimos: `flow_id`, `risk_level`, `provider`, `context_pack_hash`,
`cache_hit_status`, `input_tokens_before`, `input_tokens_after`,
`must_keep_coverage`, `quality_gate_status`, `cpu_budget`, `ram_budget`,
`operator_reserved_ram_gb`, `evidence_refs`, `rollback_ref`.

Contrato duro de qualidade preservada:

- `must_keep_coverage = 1.0`;
- `quality_regression_allowed = false`;
- `evidence_loss_allowed = false`;
- `operator_resource_floor_gb >= 3`;
- `rollback_required = true`.

Score formal:

```text
quality_preservation_score =
  min(must_keep_coverage, evidence_coverage, source_freshness)
  * test_gate_score
  * outcome_non_regression
  * human_override_respect
```

Promocao exige `quality_preservation_score >= 0.98` e nenhum hard gate falho.

Payload minimo do receipt:

```json
{
  "schema_version": "atlas.token_savings_quality_receipt.v1",
  "flow_id": "atlas_dev|atlas_forge|research",
  "strategy": "cache|delta|compression|local_verification",
  "before": {"input_tokens": 0, "latency_ms": 0, "cost_units": 0},
  "after": {"input_tokens": 0, "latency_ms": 0, "cost_units": 0},
  "quality": {"must_keep_coverage": 1.0, "evidence_loss": false, "regression": false},
  "resources": {"cpu_mode": "light|normal|deep", "operator_reserved_ram_gb": 3},
  "rollback_ref": "receipt-or-config-id"
}
```

Schemas minimos implementaveis:

`atlas.efficiency.certification.v1`: `status`, `phase`, `flow_id`,
`baseline_hash`, `variant_hash`, `quality_preservation_score`,
`hard_gate_failures[]`, `token_savings`, `cache_hit_rate`,
`cpu_minutes_spent`, `ram_peak_gb`, `operator_floor_respected`,
`promotion_decision`, `rollback_ref`, `evidence_refs[]`.

`atlas.failure_capsule.v1`: `command`, `exit_code`, `failing_test`,
`file`, `line`, `stack_excerpt`, `root_cause_candidate`, `raw_log_hash`,
`redaction_status`, `missing_information[]`, `repair_allowed`.

`atlas.resource_budget.policy.v1`: `mode`, `cpu_limit_pct`,
`concurrency_limit`, `ram_limit_gb`, `operator_floor_gb`, `battery_policy`,
`swap_policy`, `thermal_policy`, `pause_resume_token`.

## Fluxo

1. AREG classifica risco, budget e caminho: fast, standard, deep ou forge.
2. ACCCR monta prefixos estaveis, Merkle Context Pack e delta context.
3. Cache warmer aquece docs, policy, schemas, tool contracts e code map.
4. ALVE usa CPU local para code graph, test impact, lint, static checks e diff scope.
5. Failure Capsule Compressor reduz logs grandes para erro minimo verificavel.
6. ATER/ACPFR medem token, custo, latencia, qualidade e fronteira segura.
7. Provider forte recebe apenas contexto necessario e evidencia nova.
8. Resultado volta para tests, receipts, AEMOR e ACOPRO.

## Arquitetura em Fases

| Fase | Nome | Saida exigida |
| --- | --- | --- |
| 0 | read-only audit | inventario de token, cache, CPU/RAM e quality baseline |
| 1 | ACCCR shadow | Merkle pack, prefix hash, cache warmup e delta receipt sem enforcement |
| 2 | ALVE shadow | test impact, static checks e failure capsule sem bloquear fluxo |
| 3 | Dev/Forge opt-in | usuario/flow ativa AQPES em obras e runs selecionados |
| 4 | enforcement parcial | bloqueia economia que perde must-keep ou estoura CPU/RAM |
| 5 | self-optimization | promove/reverte variantes com AEMOR, AREBA e rollback |

Promocao entre fases:

- F0 -> F1: baseline de tokens/latencia/qualidade coletado.
- F1 -> F2: cache hit sem perda, invalidation test verde.
- F2 -> F3: failure capsules reduzem logs sem perder causa raiz.
- F3 -> F4: minimo 30 runs opt-in, zero regressao high-risk.
- F4 -> F5: receipts persistidos, rollback testado e AEMOR calibrado.

Politica de rollout:

- shadow nunca altera contexto enviado, apenas mede variante;
- opt-in exige operador ou flow explicitamente marcado;
- enforcement parcial so bloqueia hard gates objetivos;
- default exige 50 runs totais, 20 runs Atlas Dev, 10 runs Forge, zero
  regressao critica e economia comprovada por receipt;
- rollback deve restaurar baseline por hash, nao por descricao textual.

## Regras para IA

- Nao economizar token removendo objetivo, constraints, blockers, DoD, tests,
  owner docs, allowed/forbidden files ou evidence refs.
- Nao rodar CPU/RAM pesada sem budget e sem reserva operacional para o usuario.
- Nao usar modelo local pequeno para decisao arquitetural high-risk.
- Nao declarar cache hit como melhoria de qualidade; cache reduz custo/latencia.
- Nao promover compressao sem ablation, quality gate e rollback.
- Nao repetir testes caros se failure capsule ja explica a causa.

## Escopo de Implementacao

| Bloco | Status | Funcao | Input | Output | Risco | Metrica | Comando | Teste | Owner |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| ACCCR | design | cache/delta context | docs, schemas, code map | merkle pack | cache stale | cache hit, token saved | `atlas:context:cache-warm` | cache contract | AQPES |
| ALVE | design | prova local | diff, logs, tests | failure capsule | CPU runaway | failed patch avoided | `atlas:local-verification:run` | verification test | AQPES |
| ATER | planned | token budget | compiled pack | saving receipt | perda contexto | loss score | `atlas:context:token-economy` | token test | AUCRI |
| ACPFR | building | fronteira Pareto | variantes | promocao segura | custo>qualidade | quality delta | `atlas:context:pareto-frontier` | frontier test | AUCRI |
| AREG | active | orcamento cognitivo | risco, task, recursos | path/budget | under/overcontext | context ROI | `atlas:runtime-efficiency:certify` | efficiency test | AREG |
| AREBA | building | eval retrieval | golden set | regression report | benchmark fraco | recall/groundedness | `atlas:context:evaluate-retrieval` | eval test | AUCRI |
| ACOPRO | active | melhoria continua | metrics/outcomes | rollout/rollback | otimizacao falsa | ROI por flow | `atlas:aucri:optimize-audit` | audit test | AUCRI |
| ACOP | planned | observabilidade | traces/receipts | painel/read model | metricas cegas | drift/cost | `atlas:context:observability` | obs test | AUCRI |

### ACCCR

Obrigatorio:

- prefixo estavel por flow/provider sem timestamp antes do bloco cacheavel;
- Merkle DAG de governance, policy, owner docs, code map, schemas e tools;
- delta request contendo apenas tarefa nova, arquivos tocados e receipts recentes;
- cache warmup por workspace/obra;
- invalidation por hash de doc, schema, tool contract, provider profile e policy.

Cache invalida quando: `graph_id` muda, source hash muda, owner doc muda,
provider profile muda, tool schema muda, policy muda, freshness expira ou
prompt prefix drift e detectado.

Zonas do prefixo cacheavel:

1. `system_contract_zone`: instrucoes estaveis e politicas duras;
2. `governance_zone`: docs mae, owner docs e allowed/forbidden;
3. `tool_schema_zone`: tools, commands e contracts;
4. `project_map_zone`: code graph, rotas, migrations e testes;
5. `task_delta_zone`: pedido atual, diff, logs e evidencia nova.

Apenas a quinta zona deve variar por request. Se uma zona anterior variar sem
mudanca real de hash, isso e `prompt_prefix_drift` e bloqueia cache.

Merkle pack node minimo: `node_id`, `kind`, `authority_level`,
`source_path`, `content_hash`, `freshness`, `depends_on[]`,
`redaction_status`, `must_keep`.

Estados do cache: `cold`, `warm`, `hit`, `stale`, `poisoned`, `blocked`.
`poisoned` exige purge, receipt e rewarm completo. Nenhum cache de fonte
`chat`, `obsidian_unreviewed` ou `provider_projection` entra em hard-authority.

### ALVE

ALVE maximiza qualidade usando CPU local como realidade operacional.

Obrigatorio:

- Code Graph Daemon incremental;
- Test Impact Selector por diff, import, rota, migration e owner doc;
- Scope/Diff Critic para allowed/forbidden files;
- Local Gate Runner para lint, typecheck, docs-health, tests afetados;
- Failure Capsule Compressor preservando comando, exit code, arquivo, linha,
  teste, stack essencial, causa provavel e log hash;
- Repair Loop Controller com max tentativas e stop quando nao houver nova prova.

Ordem de execucao local:

1. `diff_scope_guard`: verifica arquivos tocados e risco;
2. `impact_selector`: escolhe testes por arquivo, import, rota e owner doc;
3. `cheap_gates`: lint, typecheck parcial, docs-health quando docs mudarem;
4. `targeted_tests`: testes afetados;
5. `deep_gates`: fuzz, mutation, build completo ou Forge gates, apenas com
   modo profundo ou risco alto;
6. `failure_capsule`: comprime falha para reparo;
7. `repair_decision`: provider forte so recebe nova chamada se a capsula
   trouxer evidencia nova.

ALVE nao substitui testes oficiais; ele escolhe a ordem e reduz o volume de
contexto/log que volta ao modelo.

## Politica CPU/RAM

O usuario sempre tem prioridade maior que Atlas.

| Modo | Quando usar | CPU | RAM | Regra |
| --- | --- | --- | --- | --- |
| leve | uso normal/interativo | baixa | floor + 3GB livres | so index/cache pequeno |
| normal | notebook carregando, baixa pressao | media | floor + 3GB livres | tests afetados e cache warmup |
| profundo | usuario autorizou work pesado | alta controlada | floor + 3GB livres | fuzz/mutation/deep checks |
| bateria | sem energia ou bateria baixa | minima | floor + 4GB livres | pausar tarefas caras |
| swap-pressure | swap/pressao alta | emergencia | preservar usuario | suspender ALVE pesado |

Nenhum modo pode reduzir `operator_resource_floor_gb` abaixo de 3.

Algoritmo de admissao:

```text
available_ram = physical_ram - used_ram - wired_or_reserved_ram
if battery_low or thermal_pressure: mode=bateria
if swap_used_gb rising or memory_pressure high: mode=swap-pressure
if available_ram < operator_floor + requested_ram: deny_or_downgrade
if cpu_load > threshold and user_interactive: downgrade
```

Jobs devem ser pausaveis, retomaveis e idempotentes. Tarefa local so entra se
`expected_value > expected_resource_cost` ou se for gate obrigatorio high-risk.

Controle de concorrencia:

```text
base_concurrency = min(cpu_cores - 2, 4)
if mode=leve: concurrency=min(base_concurrency, 1)
if mode=normal: concurrency=min(base_concurrency, 2)
if mode=profundo: concurrency=base_concurrency
if mode in [bateria, swap-pressure]: concurrency=0 for heavy jobs
```

O AREG deve reavaliar recursos a cada janela curta. Se o usuario voltar a usar
a maquina, ALVE reduz intensidade antes de abrir novo processo.

## Dependencias

Mapa rigoroso: AUCRI fornece contexto; ACCR compila; ACCCR cacheia; ATER reduz
token; ACPFR escolhe fronteira; AREG governa orcamento; ALVE prova localmente;
AEMOR aprende outcome; ACRUI impede bagunca de codigo; Software Twin preve
impacto.

## Evidencias

Evidencia minima antes de ativar em fluxo padrao:

- compiled context hash deterministico;
- prompt cache warmup receipt;
- delta context receipt;
- must_keep_coverage = 1.0;
- failure capsule com log bruto redigido e erro minimo;
- CPU/RAM budget com reserva minima do operador;
- teste que falha quando economia remove fonte critica;
- comparison antes/depois por flow.

Metricas obrigatorias:

- token savings real;
- cache hit rate;
- context loss score;
- repair loop count;
- failed patch avoided;
- local verification value;
- CPU minutes saved vs spent;
- quality delta.

Hard gates:

- `must_keep_coverage < 1.0` bloqueia;
- `context_loss_score > 0.02` bloqueia high-risk;
- `cache_source_freshness = stale` bloqueia;
- `failure_capsule_root_cause_present = false` bloqueia repair automatico;
- `operator_resource_floor_gb < 3` bloqueia ALVE pesado;
- `quality_delta < 0` bloqueia promocao.

Quality Preservation Score nao e media cosmetica. Antes da formula, hard gates
precisam passar. Depois:

```text
qps = min(must_keep_coverage, evidence_coverage, source_freshness)
      * test_gate_score
      * context_sufficiency_score
      * output_equivalence_score
      - stale_penalty
      - operator_override_penalty
```

`qps < 0.98` nao pode promover variante. Para high-risk, qualquer perda em
evidencia, owner doc, teste ou constraint vale como regressao, mesmo que tokens
economizados sejam altos.

## Integracao Dev/Forge

Atlas Dev:

- antes do provider: ACCCR compila contexto, aquece cache e gera delta;
- durante implementacao: ALVE roda diff scope e testes afetados;
- se falhar: failure capsule substitui log bruto no proximo repair;
- ao final: AQPES receipt registra economia, gates e rollback.

Atlas Forge:

- intake aquece workspace, owner docs, policy e source manifest;
- work orders usam ALVE por etapa, nao apenas no fim;
- closeout exige certification receipt com `must_keep_coverage=1.0`;
- se AQPES falhar, Forge continua pelo caminho baseline sem economia.

Regra: AQPES nunca pode virar motivo para o Forge entregar menos evidencia.

## Riscos

- Economia falsa gerar retrabalho.
- Cache stale contaminar provider.
- Cache poisoning por prefixo ou fonte errada.
- Prompt prefix drift quebrar cache.
- Test over-selection gastar CPU sem valor.
- CPU loop runaway.
- CPU local rodar testes demais e atrapalhar uso normal da maquina.
- RAM working memory crescer ate pressionar swap.
- Failure capsule cortar detalhe necessario.
- Failure capsule incompleto induzir repair errado.
- Modelo local barato tomar decisao que exige frontier.
- Provider cobrar tokens apesar do cache esperado; so receipt real conta.
- Otimizacao local gerar latencia maior que economia pratica.
- Variante boa em task simples e ruim em Forge high-risk.

## Comandos Planejados

- `php artisan atlas:efficiency:certify --json`;
- `php artisan atlas:efficiency:shadow --flow=dev --json`;
- `php artisan atlas:context:cache-warm --workspace=...`;
- `php artisan atlas:local-verification:run --diff`;
- `php artisan atlas:efficiency:receipt --last`.

Comandos de diagnostico:

- `php artisan atlas:efficiency:resources --json`;
- `php artisan atlas:efficiency:cache-audit --json`;
- `php artisan atlas:local-verification:impact --diff --json`;
- `php artisan atlas:failure-capsule:make --from-log=... --json`.

## Criterios de Pronto

AQPES so vira fluxo padrao quando:

- shadow verde por pelo menos 50 runs reais cobrindo Dev, Forge e docs;
- zero regressao de qualidade em golden set e runs high-risk;
- economia media comprovada por flow e por provider;
- cache hit real medido, nao estimado;
- CPU/RAM sempre dentro do budget e com 3GB reservados ao operador;
- Atlas Dev e Atlas Forge integrados primeiro em opt-in;
- receipts persistidos com rollback.

Definicao de done tecnico:

- ACCCR e ALVE possuem docs filhas, service read-only, command e testes;
- shadow report compara baseline vs variante por flow;
- Dev/Forge usam AQPES em opt-in sem quebrar caminho legado;
- enforcement parcial bloqueia apenas hard gates objetivos;
- AEMOR registra outcome e ACPFR decide promocao/reversao;
- cartografia mostra status, economia real, risco e evidencia.

Limite tecnico honesto:

- com APIs externas, AQPES nao controla MTP, Medusa ou speculative decoding do
  provider; esses ganhos pertencem ao serving do modelo;
- o ganho limpo do Atlas vem de contexto cacheavel, delta context, selecao de
  testes, CPU proof, RAM working memory, failure capsules e repair loops;
- se houver runtime local/self-hosted no futuro, tecnicas de speculative
  decoding podem entrar como camada separada, mas nao podem ser vendidas como
  economia de token de provider externo.

## Exemplos

Atlas Dev: prefixo fixo de governance, owner docs e tool contracts fica
cacheavel. A tarefa nova envia so delta, arquivos tocados e failure capsule.

Atlas Forge: ALVE escolhe testes afetados e roda gates locais antes de chamar
provider caro para repair. O provider recebe o erro essencial, nao 5 mil linhas
de log.

## Proximas Acoes

1. Criar docs filhas ACCCR e ALVE com service, command, tests e receipts.
2. Implementar Resource Budget Policy com reserva minima de 3 GB para operador.
3. Rodar shadow no Atlas Dev/Forge e promover apenas com receipts comparativos.
