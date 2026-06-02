---
id: atlas-engineering-run-conductor
type: engineering_knowledge
title: Atlas Engineering Run Conductor
status: active
category: agentic-engineering
priority: 103
summary: Conductor canonico que consolida o swarm Patamar 4 num unico run de engenharia governado e provider-agnostico - plano/rota, execucao cross-provider real, gate de verificacao e envelope governado - sem duplicar subsistemas.
tags:
  - atlas-ai
  - agentic-engineering
  - atlas-decide
  - patamar4
  - provider-agnostic
  - runtime-evidence
capabilities:
  - engineering_run_conductor
  - cross_provider_governed_execution
  - provider_open_registry
decisions:
  - O conductor compoe subsistemas existentes (Swarm Conductor, Swarm Executor, Production Resolver, AVER); ele nao reimplementa nem cria mecanismo paralelo.
  - Modo efetivo e SHADOW por padrao; LIVE exige flag do production resolver mais admissao de autonomia (autonoma, ou com aprovacao explicita do operador).
  - Provider e sempre resolvido pelo registry ABERTO de AiProviderManager; um provider novo entra por registro, nao por edicao de classe.
maintenance:
  - Atualizar quando Swarm Conductor/Executor, Production Resolver, AVER ou o registry de providers mudarem.
  - Manter abaixo de 220 linhas.
related_paths:
  - app/Services/Ai/AtlasDecide/AtlasEngineeringRunConductorService.php
  - app/Services/Ai/AtlasDecide/AtlasSwarmConductorService.php
  - app/Services/Ai/AtlasDecide/AtlasSwarmExecutorService.php
  - app/Services/Ai/AtlasDecide/AtlasSwarmProductionResolverService.php
  - app/Services/Ai/AiProviderManager.php
  - app/Console/Commands/AtlasSwarmConductCommand.php
  - app/Http/Controllers/AtlasPatamar4SurfaceController.php
  - app/Services/Ai/RealExecution/AtlasLiveCodeDeliveryService.php
  - app/Console/Commands/AtlasEngineeringDeliverCommand.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-engineering-run-conductor
graph_title: Atlas Engineering Run Conductor
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-agentic-engineering-os
graph_status: active
graph_source: repo
human_name: Engineering Run Conductor
canonical_name: Atlas Engineering Run Conductor
technical_name: atlas-engineering-run-conductor
cartography_type: runtime
canonical_source: docs/engineering-knowledge-base/atlas-engineering-run-conductor.md
owner: documentation-governance
repo_paths:
  - app/Services/Ai/AtlasDecide/AtlasEngineeringRunConductorService.php
  - app/Console/Commands/AtlasSwarmConductCommand.php
allowed_changes:
  - Estender composicao com novos gates governados quando provados por teste.
forbidden_changes:
  - Duplicar Swarm Conductor/Executor ou criar mecanismo de execucao paralelo.
  - Escalar para LIVE sem flag e sem admissao de autonomia.
  - Emitir claim de benchmark, rivals ou superiority.
depends_on:
  - atlas-agentic-engineering-os
  - atlas-decide-gateway-consultation
flows_to:
  - atlas-agentic-engineering-os-runtime-gap-matrix
unlocks:
  - governed-cross-provider-engineering-run
governs:
  - atlas_ai.engineering_run_conductor
evidence:
  - app/Services/Ai/AtlasDecide/AtlasEngineeringRunConductorService.php
evidence_refs:
  - symbol: AtlasEngineeringRunConductorService
  - command: atlas:swarm:conduct
  - test: AtlasEngineeringRunConductorServiceTest
  - test: AtlasSwarmConductCommandTest
required_tests:
  - "php artisan test tests/Unit/Ai/AtlasDecide/AtlasEngineeringRunConductorServiceTest.php"
  - "php artisan test tests/Feature/Console/AtlasSwarmConductCommandTest.php"
requires_evidence: true
risk_level: high
visual_tags:
  - provider-agnostic
  - governed-execution
ai_entrypoints:
  - Leia antes de adicionar superfacie de execucao cross-provider ou novo provider ao runtime de engenharia.
ai_usage_notes:
  - Use o conductor para um run governado; nao chame providers diretos fora dele quando quiser governanca + evidencia.
quality_gates:
  - docs-health
  - architecture-validate
failure_modes:
  - IA cria um segundo conductor paralelo em vez de estender este.
  - LIVE habilitado sem admissao -> gasto de provider nao autorizado.
observability_signals:
  - engineering_run_status
  - engineering_run_mode
  - engineering_run_downgrade_reason
next_actions:
  - Pipeline de compounding promove compounding_candidate (pipeline_ready) via AtlasCompoundingMemoryService::promote.
  - Ligar AVER runTest real ao winner quando o output LIVE carrega um patch.
---
# Atlas Engineering Run Conductor

## Resumo

O Engineering Run Conductor e o tecido conectivo que faltava: ele consolida os
subsistemas reais do Patamar 4 num unico run de engenharia governado e
provider-agnostico. Os motores (Swarm Conductor, Swarm Executor, Production
Resolver, AVER) ja existiam e tinham teste; o que nao existia era uma chamada
unica de operador que os atravessasse com governanca, soberania e evidencia.

```text
doc_maturity: DOC L2-L3   implementation_state: implemented_partial
```

## Papel no Atlas

Atlas e o cerebro; providers (Hermes runtime, Claude, Codex, MiniMax3, Gemini e
os que vierem) sao motor. O conductor fica ACIMA dos providers e roteia tudo
pelo registry ABERTO de `AiProviderManager`. Ele substitui produtos externos
como produto enquanto usa os engines por baixo. Nao mede, nao compara.

## Onde Se Encaixa

```text
operador (linguagem natural / atlas:swarm:conduct)
-> AtlasEngineeringRunConductorService::run()
   -> AtlasSwarmConductorService::dispatch()    (Kernel + Admission + ADML route)
   -> AtlasSwarmExecutorService::execute()        (cross-provider real / plan shadow)
   -> AtlasVerifiedExecutionRuntimeService::verifyDiff()  (gate opcional, bloqueante)
   -> envelope governado (+ compounding_candidate)
```

## Contratos

| Campo | Significado |
|---|---|
| `mode` | `shadow` (plano, zero gasto) ou `live` (providers reais). |
| `requested_mode` | Modo pedido pelo operador antes dos guards. |
| `mode_downgrade_reason` | Por que LIVE virou SHADOW: `production_resolver_disabled`, `autonomy_requires_operator_approval` ou `autonomy_admission_denied`. |
| `status` | `spec_blocked`, `no_dispatch`, `executed` ou `verified_blocked`. |
| `spec_review` | Resultado do SDD scope gate opt-in (null quando nenhum spec foi enviado). |
| `context_injection` | Memoria governada recuperada e injetada no prompt. |
| `winner` | Arm vencedor do tie-break do executor. |
| `compounding_candidate` | Sinal pipeline-ready (flow_id + evidence_refs); o conductor nunca auto-promove. |

## Fluxo

```text
run(work, options)
-> SDD scope gate opt-in (spec ambiguo -> spec_blocked, sem dispatch nem spend)
-> dispatch governado (arms ou no_dispatch)
-> resolve modo efetivo sob guards de soberania (allowlist)
-> recall de memoria governada -> injeta no prompt (context_injection)
-> wire resolver (production em LIVE, plano deterministico em SHADOW)
-> execute cross-provider (+ feedback ADML por arm + tie-break)
-> verify opcional (bloqueia em changed_files sem cobertura)
-> envelope governado + run_hash (+ compounding_candidate pipeline-ready)
```

## Escopo de Implementacao

Escopo: a composicao governada do run cross-provider e a superficie de operador
`atlas:swarm:conduct`. Fora de escopo: reimplementar dispatch/execucao/verify,
construir AiLearningCandidate (pipeline de compounding), e gasto de provider sem
admissao.

## Regras para IA

- Nao crie um segundo conductor; estenda este por composicao.
- SHADOW e o default. So va LIVE com flag `atlas.patamar4.swarm_production_resolver_enabled`
  e admissao de autonomia (autonoma, ou com `--approved` quando allow_with_approval).
- Resolva provider sempre pelo registry de `AiProviderManager`; provider novo
  entra por `config('atlas.ai.provider_drivers')` ou `registerDriver()`.
- Declare sempre `doc_maturity` e `implementation_state`; este conductor e
  `implemented_partial` (composicao provada por teste; gasto LIVE depende de
  flag, tokens reais e acumulo de dados reais para o loop de aprendizagem).

## Dependencias

- `atlas-agentic-engineering-os`;
- Swarm Conductor / Executor / Production Resolver (Patamar 4);
- AVER (`AtlasVerifiedExecutionRuntimeService`);
- `AiProviderManager` registry aberto.

## Evidencias

Evidence runtime: `AtlasEngineeringRunConductorService`, comando
`atlas:swarm:conduct`, testes `AtlasEngineeringRunConductorServiceTest` (6 casos,
inclui execucao real de provider via fake double e gate de verify bloqueante) e
`AtlasSwarmConductCommandTest`. `next_actions` e `required_tests` sao exigencias,
nao evidence.

## Riscos

- IA cria um conductor paralelo em vez de estender este (viola "nao repetir
  mecanismos paralelos").
- LIVE habilitado sem admissao de autonomia leva a gasto de provider nao
  autorizado; os guards de soberania existem para impedir isso.
- Tratar o run governado como entrega de runtime 10/10 sem flag, tokens reais e
  dados reais acumulados (claim narrativo, nao evidence).

## Exemplos

Correto: "O conductor e implemented_partial; o run governado SHADOW e provado por
teste, e LIVE executa providers reais sob flag + admissao".

Incorreto: "O conductor entrega engenharia 10/10 em producao" (LIVE depende de
flag, tokens e dados reais acumulados).

## Proximas Acoes

Feito + PROVADO LIVE (operador autorizou gasto): superficie HTTP/mobile
(`POST /atlas/patamar4/conduct`); SDD scope gate opt-in; recall de memoria governada
no prompt; Context Pack completo opt-in (`rich_context`); `compounding_candidate`
pipeline-ready; bind explicito em AppServiceProvider; override `forced_provider`
(bootstrap de rota antes do ADML, ainda gated por Kernel+Admission); loop de
compounding fechado (`compound`, LIVE-only) provado com memoria recall no run
seguinte; **entrega de codigo real** (`AtlasLiveCodeDeliveryService` + opt-in
`deliver_code`): o provider gera codigo (read-only), Atlas grava em sandbox isolado,
gate `php -l`, certify-for-review — NUNCA faz merge. Codex gerou `atlas_is_prime`
e `atlas_gcd` reais, verificados, via o caminho governado.

Restante:

1. Pipeline de compounding consome `compounding_candidate` (pipeline_ready) e
   promove via `AtlasCompoundingMemoryService::promote` (confidence>=70 + evidence +
   revalidation). O conductor nunca promove sozinho (seria mecanismo paralelo).
2. Ligar AVER `runTest` real ao winner quando o output LIVE carrega um patch —
   gated por LIVE: executa comando real, fora do caminho SHADOW.
3. Acumular runs LIVE reais (tokens) para ADML/compounding aprenderem — esta
   metade e decisao do operador + tempo, nao codigo.
