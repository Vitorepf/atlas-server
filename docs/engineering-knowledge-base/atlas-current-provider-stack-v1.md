---
id: atlas-current-provider-stack-v1
type: engineering_knowledge
title: Atlas Current Provider Stack v1
status: active
implementation_state: canonical_policy_only_no_runtime_change
category: programming-forge
priority: 99
summary: Stack canonica atual de providers que o operador pretende usar no Atlas, limitada aos trilhos ativos de subsidio/conta e excluindo providers fora da decisao atual.
human_summary: Define a stack real de uso agora: Codex, Cursor CLI/Composer, MiniMax, Antigravity SDK e Gemini enquanto subsidiado.
human_what: Mapa de provider/modelo por papel operacional no Atlas AI, Atlas Dev e Atlas Forge.
human_purpose: Evitar que a estrategia de provider vire catalogo infinito, gasto por API sem controle ou roteamento baseado em opiniao.
human_input: Recebe disponibilidade de contas, planos, pools, budgets, Decision Receipts, AP-99, Rivals, evidence e operator policy.
human_output: Entrega papeis claros por provider, limites de gasto, triggers de uso e regras de exclusao da stack atual.
human_change_when: Mexa quando o operador mudar assinatura, plano, budget, provider principal, meta-provider ou policy de API/paygo.
human_block_when: Bloqueie quando uma IA tentar adicionar provider fora da stack atual sem decisao humana, AP/Rivals/evidence e budget policy.
tags:
  - atlas-ai
  - provider-stack
  - subsidy-first
  - cursor
  - codex
  - minimax
  - antigravity
  - gemini
capabilities:
  - current_provider_stack_policy
  - subsidy_first_provider_routing
  - provider_role_assignment
  - api_paygo_exclusion_policy
decisions:
  - A stack atual deve listar somente providers que o operador pretende usar agora.
  - O Atlas deve maximizar subsidio por conta/plano antes de API/paygo.
  - Codex GPT-5.5 fica como premium judge, arquiteto e reviewer final, nao worker 24/7.
  - Cursor CLI com Composer/Auto e o executor principal por assinatura Cursor, usando login local/conta.
  - MiniMax M2.7 Token Plan e o worker barato 24/7 para fluxo fatiado, scout, spec, reduce, repair pequeno e TTS.
  - Antigravity entra via SDK governado como meta-provider/agent harness, nao via CLI hot path.
  - Gemini entra enquanto houver cota/subsidio disponivel para long-context scout, repo sweep e context compression.
  - Providers fora desta decisao nao entram em current routing nem em recomendacao de assinatura sem nova decisao humana.
  - Cursor SDK fica fora do uso atual; e reservado para um futuro com API/Cloud Agent e budget explicito.
maintenance:
  - Atualizar antes de mudar assinatura, auth mode, default provider, spend cap, API/paygo ou auto-routing.
  - Revalidar docs oficiais e dashboards antes de declarar pool, limite ou custo como fato canonico.
  - Manter alinhado com AQPES, ATER, MiniMax-first, Cursor CLI e Antigravity SDK.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md
  - docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md
  - docs/engineering-knowledge-base/atlas-token-economy-runtime.md
  - docs/engineering-knowledge-base/atlas-quality-preserving-efficiency-system.md
  - docs/engineering-knowledge-base/atlas-minimax-first-24h-flow-v1.md
  - docs/engineering-knowledge-base/atlas-cursor-cli-governed-executor-v1.md
  - docs/engineering-knowledge-base/atlas-cursor-antigravity-meta-provider-dossier-v1.md
  - docs/engineering-knowledge-base/atlas-antigravity-sdk-governed-executor-v1.md
  - docs/engineering-knowledge-base/atlas-antigravity-cli-governed-terminal-executor-v1.md
  - docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md
  - docs/engineering-knowledge-base/atlas-forge-provider-capacity-continuity-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-provider-performance-ledger-v1.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-current-provider-stack-v1
graph_title: Atlas Current Provider Stack v1
graph_world: atlas
graph_layer: system
graph_kind: policy
graph_parent: atlas-ai-model-selection-strategy
graph_status: active
graph_source: repo
human_name: Atlas Current Provider Stack
canonical_name: Atlas Current Provider Stack v1
technical_name: atlas-current-provider-stack-v1
cartography_type: policy
canonical_source: docs/engineering-knowledge-base/atlas-current-provider-stack-v1.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-current-provider-stack-v1.md
allowed_changes:
  - Refinar papeis, triggers, budgets e routing depois de smoke real, AP-99, Rivals ou mudanca explicita de plano/conta.
forbidden_changes:
  - Adicionar provider fora da stack atual por curiosidade, benchmark externo ou marketing.
  - Usar API/paygo como default sem decisao humana e spend cap.
  - Usar Cursor SDK como caminho atual enquanto a estrategia for assinatura por conta/CLI.
  - Usar Antigravity CLI como runtime hot path quando o caminho canonico for SDK governado.
  - Gastar Codex premium em scouts, varreduras amplas ou retries baratos.
depends_on:
  - atlas-ai-model-selection-strategy
  - atlas-token-economy-runtime
  - atlas-minimax-first-24h-flow-v1
  - atlas-cursor-cli-governed-executor-v1
flows_to:
  - atlas-forge-provider-topology-and-fallback-v1
  - atlas-forge-provider-capacity-continuity-v1
  - atlas-forge-rivals-provider-performance-ledger-v1
unlocks:
  - subsidy-first-current-routing
  - minimax-first-24h-loop
  - cursor-cli-primary-executor-experiment
governs:
  - atlas.provider_stack.current
  - atlas.provider_strategy.subsidy_first
  - atlas.forge.current_provider_roles
evidence:
  - docs/engineering-knowledge-base/atlas-current-provider-stack-v1.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:architecture-validate --json"
  - "git diff --check"
requires_evidence: true
risk_level: high
visual_tags:
  - provider-stack
  - subsidy-first
  - current
ai_entrypoints:
  - Leia Resumo, Stack Atual, Regras De Gasto e Hard Stops antes de recomendar provider, assinatura, CLI, SDK ou API.
ai_usage_notes:
  - Este doc e a stack atual, nao catalogo de todos os modelos bons do mercado.
quality_gates:
  - docs-health status ok
  - no-paygo-without-explicit-policy
  - Decision Receipt before auto-routing
  - AP-99 before promotion
failure_modes:
  - Uma IA adiciona provider fora da stack atual porque parece barato.
  - Cursor SDK vira caminho atual e consome API quando a meta era assinatura.
  - Codex premium vira worker barato e esgota plano.
  - MiniMax vira dump gigante sem sharding nem receipts.
  - Gemini subsidiado vira dependencia permanente sem budget review.
observability_signals:
  - provider_stack_version
  - account_subsidy_mode
  - paygo_enabled
  - codex_premium_calls
  - cursor_cli_calls
  - minimax_worker_calls
  - antigravity_sdk_calls
  - gemini_subsidy_calls
  - provider_route_blocked_reason
next_actions:
  - Rodar smoke real dos drivers ativos antes de auto-routing.
  - Criar AP-99/Rivals para comparar Codex, Cursor CLI, MiniMax, Antigravity SDK e Gemini nos mesmos work packets.
---
# Atlas Current Provider Stack v1

## Resumo

Este doc fixa a stack atual de providers que o Atlas deve considerar para uso
real agora. Ele nao e lista de todos os modelos bons. Ele e a decisao operacional
do que entra no Atlas sem abrir gasto solto.

Regra curta:

```text
Usar subsidio por conta/plano primeiro.
Usar API/paygo somente depois de decisao humana, cap e evidence.
Nao listar provider fora da stack atual como rota ativa.
```

## Papel no Atlas

Esta policy governa Atlas AI geral, Atlas Dev e Atlas Forge quando a pergunta
for "qual provider usar agora?". Atlas Decide continua sendo a autoridade de
roteamento automatico, mas esta doc define o conjunto permitido da stack atual.

## Onde Se Encaixa

```text
Provider Evolution Intelligence
-> Current Provider Stack
-> Model Selection Strategy / Atlas Decide
-> Forge Provider Topology
-> Provider Invocation Drivers
-> Evidence / AP-99 / Rivals
```

## Contratos

Contratos minimos que runtime futuro deve materializar:

- `atlas.provider_stack.current.v1`;
- `atlas.provider_stack.account_subsidy.v1`;
- `atlas.provider_stack.paygo_policy.v1`;
- `atlas.provider_stack.route_reason.v1`;
- `atlas.provider_stack.exclusion_receipt.v1`.

## Stack Atual

| Trilho | Como usar | Papel principal | Nao usar para |
|---|---|---|---|
| Codex GPT-5.5 | Conta Codex unica 20x, via Codex/Codex CLI quando disponivel | Arquiteto premium, judge, reviewer final, repair dificil, decisao critica | Scout barato, varredura enorme, retry repetido, worker 24/7 |
| Cursor CLI / Composer | Assinatura Cursor, login local, `cursor_cli`, Composer/Auto conforme conta | Executor principal de codigo, patch, refactor, builder de Forge, experimento de melhor custo por conta | API/SDK atual, completion claim automatico, edicao fora de `allowed_files` |
| MiniMax M2.7 Token Plan | Plano MiniMax por request, sem overflow de credits/paygo por default | Worker 24/7 barato: scout, spec, map/reduce, cross-check, repair pequeno, TTS/voz quando fizer sentido | Dump gigante, writer paralelo sem lease, substituir premium judge |
| Antigravity SDK | SDK governado, conta/subsidio quando disponivel | Meta-provider agent harness, agent loop governado, experimentos de autonomia e modelos internos | CLI hot path, `/model` como autoridade, bypass de Atlas Decide |
| Gemini subsidiado | Usar enquanto houver cota gratis/subsidiada | Long-context scout, repo sweep, compressao de contexto, comparacao e pesquisa ampla | Dependencia paga permanente sem revisao de budget |

## Fluxo

1. Contexto, index, shards e ownership map rodam localmente no Atlas.
2. Gemini subsidiado ou MiniMax faz scout/contexto quando o trabalho for amplo.
3. MiniMax faz work packets pequenos e baratos no loop 24/7.
4. Cursor CLI/Composer executa patches e refactors quando a tarefa precisa de
   builder forte por assinatura.
5. Codex GPT-5.5 entra para arquitetura critica, review final, juiz entre rotas
   ou repair que exige inteligencia premium.
6. Antigravity SDK entra em fluxos agenticos governados, quando o harness dele
   agregar mais que uma chamada simples de modelo.

## Regras De Gasto

- Default: assinatura/conta/subsidio.
- API/paygo: bloqueado por default.
- Codex premium: gastar pouco e em decisoes de alta alavancagem.
- Cursor CLI: preferido sobre Cursor SDK enquanto a meta for usar a conta Cursor.
- MiniMax: preferido para volume 24/7, sempre com sharding e receipts.
- Gemini: usar enquanto o subsidio existir; se virar custo relevante, reavaliar.
- Antigravity: usar como SDK governado; CLI fica referencia fora do hot path.

## Regras para IA

- Nao recomendar provider fora desta stack como rota atual.
- Nao confundir meta-provider com modelo bruto.
- Nao promover benchmark externo como decisao de routing.
- Nao ligar API/paygo porque "parece barato".
- Nao gastar Codex em trabalho que MiniMax, Gemini ou Cursor CLI conseguem
  preparar.
- Nao assumir que Composer, MiniMax ou Gemini sao ilimitados.
- Nao permitir writer sem `allowed_files`, lease, diff pequeno e testes.

## Escopo de Implementacao

Inclui:

- policy canonica de stack atual;
- papeis de uso por provider;
- limites de gasto;
- criterios de routing;
- exclusao de providers fora da decisao atual.

Nao inclui:

- novo driver;
- chamada real a provider;
- mudanca de default runtime;
- smoke real;
- AP-99/Rivals;
- ativacao de API/paygo.

## Dependencias

- `atlas-ai-model-selection-strategy.md`;
- `atlas-token-economy-runtime.md`;
- `atlas-minimax-first-24h-flow-v1.md`;
- `atlas-cursor-cli-governed-executor-v1.md`;
- `atlas-cursor-antigravity-meta-provider-dossier-v1.md`;
- `atlas-antigravity-sdk-governed-executor-v1.md`.

## Evidencias

Evidencia minima para promover qualquer trilho da stack:

- config/auth local verificada sem expor segredo;
- smoke real isolado;
- receipt com provider, model observado, budget e arquivos tocados;
- AP-99/Rivals quando virar routing automatico;
- dashboard/billing observado quando houver risco de custo.

## Riscos

- Assinatura parecer ilimitada e virar desperdicio.
- API/paygo entrar escondida por SDK.
- Meta-provider escolher modelo/escopo fora do Atlas.
- Premium judge virar worker barato.
- Worker barato gerar patch quebrado por falta de ownership map.

## Exemplos

```text
Caso: varrer repo grande
Usar: Atlas index -> Gemini/MiniMax scout -> context pack -> Cursor/Codex se precisar.

Caso: implementar feature media
Usar: MiniMax spec/cross-check -> Cursor CLI patch -> testes locais -> Codex review se risco alto.

Caso: loop 24/7
Usar: MiniMax workers em fila -> Cursor CLI para patches escolhidos -> Codex so em checkpoints criticos.

Caso: agent harness complexo
Usar: Antigravity SDK governado -> receipt -> Atlas Decide -> Evidence.
```

## Hard Stops

Bloqueie quando:

- algum fluxo exigir API/paygo sem cap;
- provider fora da stack atual aparecer como rota ativa;
- Cursor SDK for usado para substituir Cursor CLI sem decisao humana;
- Antigravity CLI for usado como hot path;
- Codex for escalado para scout barato ou retry repetido;
- MiniMax tentar editar sem lease e `allowed_files`.

## Proximas Acoes

1. Rodar smoke real do `cursor_cli` com login local.
2. Materializar config MiniMax com cap de credits/paygo bloqueado.
3. Validar Antigravity SDK em smoke governado.
4. Rodar battery AP-99/Rivals com os trilhos da stack atual.
5. Projetar esta stack no cockpit/provider topology apenas depois dos receipts.
