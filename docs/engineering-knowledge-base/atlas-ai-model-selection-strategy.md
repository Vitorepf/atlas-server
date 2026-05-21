---
id: atlas-ai-model-selection-strategy
type: engineering_knowledge
title: Atlas AI Model Selection Strategy
status: active
category: architecture
priority: 96
summary: Contrato canonico para Atlas Decide escolher provider/modelo por tarefa, dominio, flow, specialist profile, custo, latencia, qualidade historica e policy.
tags:
  - atlas-ai
  - atlas-decide
  - model-selection
  - provider-performance
  - programming
capabilities:
  - model_selection_strategy
  - provider_performance_signal_consumption
  - atlas_decide_signal_consumption
decisions:
  - Atlas Decide e a unica autoridade para selecao automatica de provider/modelo.
  - Surfaces podem solicitar override manual, mas isso vira `manual_override` auditavel e policy dura ainda vence.
  - `auto_best_allowed` e o default de produto; `auto_best_available` so quando autorizado por budget/policy.
  - AP-99 Provider Performance Contract deve alimentar a escolha com outcome real por domain, flow, task_type e specialist_profile.
  - Novas capacidades de provider entram como sinais e benchmarks; nunca como hardcode permanente em surface ou domain.
  - Specialist profiles de Programming devem influenciar selecao sem criar provider hardcoded na surface.
maintenance:
  - Manter abaixo de 240 linhas.
  - Atualizar quando Atlas Decide, ModelSelectionContractFactory, AP-99, provider performance, specialist profiles ou provider matrix mudarem.
  - Nao colocar tabela hardcoded eterna de modelos; registrar principios, sinais e fallback ate haver metricas reais suficientes.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md
  - app/Services/Ai/Kernel/Decision/ModelSelectionContractFactory.php
  - app/Services/Ai/AtlasDecideService.php
  - docs/engineering-knowledge-base/domains/programming-specialist-profiles.md
  - docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md
  - docs/engineering-knowledge-base/atlas-ai-autonomy-power-backlog.md
  - docs/engineering-knowledge-base/atlas-ai-master-architecture.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-model-selection-strategy

graph_title: Atlas AI Model Selection Strategy

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo
human_name: Atlas AI Model Selection Strategy
canonical_name: Atlas AI Model Selection Strategy
technical_name: atlas-ai-model-selection-strategy
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md

owner: architecture

repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - architecture

evidence:
  - docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - architecture

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas AI Model Selection Strategy

Este documento define como o Atlas deve escolher o melhor provider/modelo para
cada tarefa sem transformar UI, CLI ou dominio em autoridade de modelo.

## Regra Mae

```text
Surface pede.
Domain/flow/specialist descreve necessidade.
Policy limita.
Telemetry/AP-99 informa.
Atlas Decide escolhe.
Decision Receipt audita.
```

## Modos De Selecao

| Modo | Uso | Regra |
|---|---|---|
| `auto_best_allowed` | Default | Melhor rota dentro de policy, budget, privacidade e disponibilidade. |
| `auto_best_available` | Premium/critico | Melhor rota disponivel mesmo com custo maior, se policy permitir. |
| `manual_override` | Operador escolheu provider/modelo | Auditado; policy dura ainda pode bloquear. |

`manual_override` nao significa "sem Atlas Decide". Significa que Decide registra,
valida e limita a escolha manual.

## Sinais De Decisao

Atlas Decide deve considerar:

1. domain e flow;
2. `specialist_profile`;
3. task_type e risco;
4. complexidade e blast radius;
5. multimodal/visual input;
6. long-context need;
7. necessidade de tool use ou patch quality;
8. privacy/provider-safe constraints;
9. provider health, rate limit e disponibilidade;
10. budget e latencia;
11. AP-99 outcome historico por provider/model/domain/flow/task_type;
12. repair success e regression rate;
13. provider release capabilities catalogadas por Curator/Rivals;
14. preferencia do operador apenas como sinal, nao autoridade.

Release novo de provider pode aumentar prioridade temporaria de benchmark, mas
nao troca roteamento critico sem evidence suficiente ou override auditado.

## Compute Effort

Selecao de modelo sem selecao de esforco e incompleta. Atlas usa uma linguagem
canonica de esforco, independente do provider:

| Atlas effort | Uso | Traducao inicial |
|---|---|---|
| `fast` | resposta rapida, baixo custo, baixo risco | Claude `--effort low`; Codex `model_reasoning_effort=low`; Gemini observado/API low |
| `balanced` | default diario | Claude `medium`; Codex `medium`; Gemini observado/API medium |
| `deep` | debug/refactor/analise pesada | Claude `high`; Codex `high`; Gemini observado/API high ou budget alto |
| `max` | arquitetura critica, Forge pesado, revisao final | Claude `max`; Codex `xhigh`; Gemini observado ate driver SDK/API |

Surfaces podem pedir `--effort`, `/effort` ou `policy_hints.compute_effort`,
mas isso vira `atlas.compute_effort_contract.v1` com autoridade
`atlas_decide`. Provider adapter traduz para a flag/config real; UI/mobile nunca
deve expor `thinkingBudget`, `model_reasoning_effort` ou flags de vendor como
autoridade de produto.

Metrica de esforco deve registrar nivel pedido, mapeamento provider, duracao,
tokens/custo quando disponivel, retries, status de gates, completion e outcome.
Esses sinais alimentam AP-99/Provider Performance, mas nao mudam roteamento
automatico sem evidencia suficiente.

## Programming Specialist Matrix

Esta matriz e uma policy inicial. Ela deve ser substituida por AP-99 quando houver
amostra suficiente.

| Specialist/Profile | Sinais fortes | Preferencia inicial | Gates obrigatorios |
|---|---|---|---|
| `programming.frontend` | UI, React/Expo, layout, screenshot, a11y | modelo forte em multimodal + coding; Codex/Claude/Gemini conforme AP-99 | visual, TS/lint, a11y, responsive |
| `programming.backend_api` | service, API, auth, queue, transaction | modelo forte em code reasoning e patch discipline | tests, API contract, security |
| `programming.mobile` | Expo/RN/iOS, push, offline, device state | modelo forte em mobile context + visual reasoning | mobile smoke, visual, state checks |
| `programming.architecture` | boundaries, refactor, multi-module | modelo com alto reasoning/contexto longo | architecture, diff scope, tests |
| `programming.performance` | latency, memory, bundle, query | modelo forte em diagnosis + data analysis | benchmark/profile evidence |
| `programming.accessibility` | a11y, keyboard, screen reader, contrast | modelo com visual + standards knowledge | axe/Pa11y/manual checklist |
| `programming.testing` | test strategy, flaky, mutation/property | modelo forte em causal debugging | targeted tests, repair loop |
| `programming.security_appsec` | auth, secrets, vuln, trust boundary | modelo conservador + security gates | gitleaks/SAST/dependency policy |

Nunca hardcodar "frontend sempre provider X" em surface. A matriz define sinais;
AP-99 decide empiricamente quando houver dados.

## AP-99 Provider Performance

AP-99 deve medir por:

```text
provider
model
domain
flow
specialist_profile
task_type
risk
selection_mode
outcome
quality_score
repair_success
regression_rate
latency
cost
human_correction
```

Sem amostra suficiente, Decide deve usar heuristic + provider health + policy e
marcar `confidence=low|medium`.

Com amostra suficiente, Decide deve preferir outcome real sobre opiniao geral de
modelo.

## Fallback E Degradacao

Fallback permitido quando:

1. provider indisponivel ou rate limited;
2. policy bloqueia dados para provider externo;
3. custo/budget excedido;
4. AP-99 mostra baixa qualidade recente para aquela tarefa;
5. repair loop indica repeticao de falha.

Fallback deve ser registrado em Decision Receipt e Evidence Ledger com motivo.

## O Que Nao Fazer

1. surface escolher provider automaticamente fora de Decide;
2. domain hardcodar provider como autoridade final;
3. confundir provider com executor/harness;
4. declarar melhor modelo sem AP-99, benchmark ou evidence;
5. usar `auto_best_available` como default silencioso;
6. ignorar privacy/budget por preferencia de qualidade.

## Implementation Roadmap

| Fase | Entrega | Status |
|---|---|---|
| MS-0 | Doc canonica e regra de autoridade | active |
| MS-1 | Adicionar `specialist_profile` no model contract/provider usage | implemented |
| MS-2 | AP-99 rollup e filtro por specialist_profile | implemented |
| MS-3 | Decide report explica escolha por sinais e confidence | implemented |
| MS-4 | Self-Improvement revisa provider performance e propõe policy patches | implemented |
| MS-5 | Dynamic Compute Market usa AP-99 para custo/qualidade/latencia | implemented_shadow |

## Implementado Em AP-99

Model selection contract agora carrega `domain`, `flow` e `specialist_profile`
quando o contexto permitir. Provider usage registra `specialist_profile` quando
vier de receipt, payload, plano de programming, metadata ou kernel context. A
projection AP-99 agrega e filtra por esse campo em CLI, API e MCP. Isso permite
comparar provider/modelo por especialidade sem criar regra fixa em surface.

## Implementado Em MS-3

Atlas Decide emite `selection_explanation` no `provider_selection`, no
`Decision Receipt v2`, no receipt de trace e na API de decisions. A explicacao
carrega `confidence_score`, `confidence_band`, `evidence_level`, sinais primarios
da tarefa, limites de policy, fallback usado e dimensoes AP-99 exigidas. Esse
contrato impede que a escolha de modelo seja uma caixa preta: qualquer IA ou
humano consegue ver se a decisao veio de heuristic/policy, fallback, override
manual ou evidencia futura de AP-99.

## Implementado Em MS-4

`self_improvement.provider_performance_review` consome o read model AP-99 e,
quando encontra falhas, fallback ou baixa taxa de sucesso, gera finding
`atlas.self_improvement.provider_performance.v1` com `review_signal`,
`policy_patch_candidate`, dimensoes AP-99 e acoes assistidas para benchmark ou
draft de patch de policy. O Curator nunca aplica a mudanca sozinho: ele abre
proposta revisavel para melhorar a matriz de modelo/provedor.

## Implementado Em MS-5 Shadow

`DynamicComputeMarketAdvisor` roda dentro do `Atlas Decide` em modo
`shadow_advisory`. Ele consulta AP-99 por provider, domain, flow, task_type e
specialist_profile, anexa `compute_market` ao `selection_explanation` e recomenda
`collect_ap99_evidence`, `keep_selected_provider`, `review_provider_policy_patch`,
`benchmark_lower_latency_alternative` ou `configure_provider_cost_rates`. O
advisor ainda nao troca provider sozinho; ele informa Decide, receipt, API e
Curator.

O Dynamic Compute Market e auditavel e recommendation-only: ele pode apontar que
uma rota parece melhor ou pior, mas qualquer troca automatica continua exigindo
policy/receipt emitidos por Atlas Decide. A explicacao do `compute_market`
mantem o schema existente e adiciona bases estruturadas para auditoria
enterprise: `quality_basis`, `latency_basis`, `cost_basis`,
`missing_cost_status`, `sample_size`, `confidence.risk` e
`recommended_next_action`, alem de `recommendation_reason` canonico e
`decision_factors`. Esses fatores expõem os sinais booleanos e a precedencia
usada pelo advisor: coletar AP-99, revisar qualidade/policy, benchmark por alta
latencia, configurar custo, benchmark por candidato de mercado e, por fim,
manter rota. Quando uma alternativa aparenta menor latencia ou menor custo com
amostra insuficiente, a recomendacao correta e benchmark controlado, nao troca
silenciosa de provider. Quando a qualidade esta boa mas o custo do selecionado
e desconhecido, a acao vem antes da otimizacao: configurar rates do provider.

Para evitar ambiguidade operacional, `compute_market.routing_control` declara
`changes_provider=false`, preserva provider/modelo selecionados e lista
`policy_patch` + `decision_receipt` como requisitos para qualquer mudanca de
rota futura. `market_basis` expoe apenas a base agregada AP-99 usada para
comparar candidatos em shadow, sem virar autoridade paralela.

### Dynamic Compute Market Report

`atlas ai dynamic-compute-market --provider=<provider> --domain=<domain> --flow=<flow> --json`
e `/ai/dynamic-compute-market?provider=<provider>` expõem o mesmo advisor em
modo `report_only`. O MCP read-only `atlas_dynamic_compute_market_report` expõe
o mesmo contrato para agentes. Esse report serve para operador, App e sessoes de
IA auditarem custo/qualidade/latencia antes de qualquer patch de policy. O
schema `atlas.dynamic_compute_market_report.v1` declara
`authority=read_only_no_routing_change`, preserva
`routing_control.changes_provider=false` e retorna o bloco
`dynamic_compute_market` exatamente como o Decide anexaria ao receipt.

Quando ha mais de uma alternativa promissora, o advisor normaliza candidatos
com `improvement_basis` (`latency`, `cost`) e prefere amostra suficiente antes
de numeros pontuais melhores. Entre candidatos com amostra suficiente, qualidade
comparavel mais alta vence antes de latencia/custo. Essa regra reduz falso
positivo de mercado e mantem benchmark como ponte obrigatoria entre observacao
AP-99 e policy.

`self_improvement.provider_performance_review` usa o mesmo advisor para abrir
finding proposal-only quando AP-99 aponta candidato melhor. O payload
`atlas.self_improvement.dynamic_compute_market.v1` recomenda
`run_controlled_provider_benchmark_before_policy_change`, preserva
`routing_control.changes_provider=false` e exige `policy_patch` +
`decision_receipt`. Isso fecha o ciclo: Decide anexa explicacao, CLI/API/MCP
exibem report read-only e Curator transforma oportunidade recorrente em
trabalho revisavel sem criar roteador paralelo.

O `proposal_review_packet` bloqueia promocao ate benchmark, review humano,
novo receipt e rollback; ele tambem proibe mudar provider/modelo, hardcodar
preferencia ou bypassar Atlas Decide antes da revisao.

AP-99 agora carrega tokens e custo por chamada quando houver rate configurado:
`total_tokens`, `average_total_tokens`, `total_cost_microusd`,
`average_cost_microusd`, `cost_confidence_counts` e `cost_mode_counts`. Quando
o custo estiver indisponivel, o score basis marca
`cost_status=missing_cost_rate_or_unavailable`, mantendo a decisao honesta e
criando trabalho claro para completar rates antes de qualquer otimizacao de
mercado.

CLI, API, MCP e Observability expoem os rollups de tokens/custo no mesmo
`provider_performance`. O Curator tambem cria finding
`atlas.self_improvement.provider_cost_rates.v1` quando a qualidade esta boa mas
o custo permanece `unknown`, sempre como acao assistida e revisavel.

A Inbox action `configure_provider_cost_rates` fecha esse ciclo sem burlar
policy: ela pode gerar template quando falta preco, ou gravar rate versionado em
`ai_provider_cost_rates` quando o operador informar valores. A action resolve o
item somente quando aplicar rate real e grava `INBOX_ACTION_RECORDED`.
O replay de Inbox projeta essa evidencia como `provider_cost_rate_action_count`,
`provider_cost_rate_applied_count`, contagens provider/modelo e reason
`provider_cost_rates_configured` quando o ciclo foi fechado. Se o operador
apenas visualizou o template, o review signal continua apontando
`configure_provider_cost_rates`.

## Definition Of Done

Model selection esta completo quando:

1. toda surface usa `ModelSelectionContractFactory` ou contrato equivalente;
2. Decision Receipt carrega `selection_mode`, provider/model, motivo e confidence;
3. provider usage registra domain, flow, task_type e specialist_profile;
4. AP-99 consegue comparar outcomes por tarefa;
5. Self-Improvement gera propostas quando uma rota fica pior;
6. override manual aparece como excecao auditavel, nao como caminho invisivel.

## Resumo

Contrato canonico para Atlas Decide escolher provider/modelo por tarefa, dominio, flow, specialist profile, custo, latencia, qualidade historica e policy.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
