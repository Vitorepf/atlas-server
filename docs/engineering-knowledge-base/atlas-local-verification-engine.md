---
id: atlas-local-verification-engine
type: engineering_knowledge
title: Atlas Local Verification Engine
status: active
implementation_state: runtime_surface_local_verification_ready
blocker: Enforcement em Dev/Forge depende de shadow receipts reais; ALVE atual e read-only e nao executa comandos.
category: intelligence-runtime
priority: 100
summary: Doc filha AQPES para usar CPU local como prova operacional por diff scope, test impact, failure capsules e gate plan sem chamar provider.
human_summary: Usa sua maquina para checar escopo, escolher testes e comprimir falhas antes de gastar tokens com reparo.
tags: [atlas-ai, aqpes, alve, local-verification, cpu-proof, test-impact, failure-capsule]
capabilities: [diff_scope_guard, test_impact_selector, failure_capsules, local_gate_plan, resource_aware_verification]
decisions:
  - ALVE nao substitui testes oficiais; ele decide a ordem e reduz o contexto/log que volta ao modelo.
  - ALVE atual e read-only: nao executa comandos, nao chama provider e nao escreve estado.
  - Failure capsule pode liberar repair apenas quando preserva causa raiz verificavel.
maintenance:
  - Atualizar antes de mudar selecao de testes, redacao de logs, resource policy ou gate plan.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-quality-preserving-efficiency-system.md
  - docs/engineering-knowledge-base/atlas-runtime-efficiency-governor.md
  - app/Services/Ai/RuntimeEfficiency/AtlasLocalVerificationEngineService.php
  - app/Console/Commands/AtlasLocalVerificationEngineCommand.php
  - tests/Feature/Ai/RuntimeEfficiency/AtlasLocalVerificationEngineServiceTest.php
doc_schema: atlas_canonical_module_doc.v1
macro_layer: true
product_name: Atlas Local Verification Engine
runtime_acronym: ALVE
internal_product_name: Atlas CPU Proof Engine
technical_runtime: AtlasLocalVerificationEngineService
graph_id: atlas-local-verification-engine
graph_title: Atlas Local Verification Engine
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-quality-preserving-efficiency-system
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-local-verification-engine.md
allowed_changes:
  - Ajustar diff scope, test impact, failure capsule, gate plan e resource policy.
forbidden_changes:
  - Executar comandos reais dentro do shadow read-only.
  - Expor log bruto sensivel.
  - Permitir repair automatico sem causa raiz.
  - Rodar deep gates quando resource policy negar heavy jobs.
depends_on: [atlas-quality-preserving-efficiency-system, atlas-runtime-efficiency-governor]
flows_to: [atlas-dev, atlas-forge, atlas-quality-preserving-efficiency-system]
unlocks: [cpu_proof_engine, failure_capsule_repair, test_impact_selection]
governs: [atlas.local_verification.engine.v1, atlas.failure_capsule.v1, atlas.local_verification.test_impact.v1]
evidence:
  - app/Services/Ai/RuntimeEfficiency/AtlasLocalVerificationEngineService.php
  - app/Console/Commands/AtlasLocalVerificationEngineCommand.php
  - tests/Feature/Ai/RuntimeEfficiency/AtlasLocalVerificationEngineServiceTest.php
required_tests:
  - "php artisan atlas:local-verification:run --json"
  - "php artisan test tests/Feature/Ai/RuntimeEfficiency/AtlasLocalVerificationEngineServiceTest.php"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Persistir local verification receipts depois de shadow real em Dev/Forge.
  - Conectar ALVE ao repair loop com rollback e AEMOR outcome.
---

# Atlas Local Verification Engine

## Resumo

ALVE usa CPU local como camada de prova antes de gastar tokens. Ele recebe diff,
riscos, logs e politica de recursos; devolve escopo validado, testes provaveis,
plano de gates e failure capsule.

## Papel no Atlas

Claude, Codex ou Gemini nao devem receber log gigante nem contexto mecanico
quando a maquina local consegue reduzir isso para evidencia objetiva. ALVE
transforma trabalho mecanico em sinal compacto para Atlas Dev e Forge.

## Onde Se Encaixa

```text
diff/log/resource policy
-> ALVE: scope guard + test impact + failure capsule
-> AQPES: quality/cost/resource decision
-> provider forte apenas se houver nova evidencia
```

ALVE nao escolhe modelo, nao executa patch e nao escreve no ledger. Ele prepara
a prova local que outros runtimes podem consumir.

## Contratos

- `atlas.local_verification.engine.v1`
- `atlas.local_verification.test_impact.v1`
- `atlas.failure_capsule.v1`
- `atlas.local_verification.diff_scope_guard.v1`
- `atlas.local_verification.gate_plan.v1`

Campos essenciais: `status`, `flow_id`, `risk_level`, `scope_guard`,
`test_impact`, `gate_plan`, `failure_capsule`, `blockers`, `metrics`,
`claim_policy` e `local_verification_hash`.

## Fluxo

1. Normalizar `changed_files`, `allowed_files` e `forbidden_files`.
2. Bloquear diff fora de escopo antes de qualquer repair.
3. Rodar `ProgrammingTestImpactAnalyzer` para selecionar testes provaveis.
4. Montar gate plan conforme risco e resource policy.
5. Se houver falha, gerar failure capsule com log redigido e hash do raw log.
6. Liberar repair apenas se a capsula tiver causa raiz.

## Regras para IA

- Nao tratar ALVE como prova de sucesso quando ele so gerou plano read-only.
- Nao reenviar log bruto ao provider se failure capsule preserva causa raiz.
- Nao permitir arquivo proibido porque o teste passou.
- Nao usar no-test selection como bloqueio quando nao existe diff.
- Nao executar deep gates se `heavy_jobs_allowed=false`.

## Escopo de Implementacao

Ativo agora:

- Service `AtlasLocalVerificationEngineService`;
- comando `atlas:local-verification:run`;
- diff scope guard;
- test impact selector;
- gate plan resource-aware;
- failure capsule com redacao de secrets;
- hash deterministico e claim policy read-only;
- testes focados cobrindo escopo, capsula, resource policy e comando.

Ainda nao ativo:

- execucao real de lint/test/typecheck;
- receipts persistidos por run real;
- integracao blocking em Atlas Dev/Forge;
- repair loop automatico.

## Dependencias

Depende de `ProgrammingTestImpactAnalyzer` para impacto de testes e de AQPES/AREG
para politica de recursos. Dev/Forge devem consumir ALVE como shadow antes de
qualquer enforcement.

## Evidencias

- `php artisan atlas:local-verification:run --json`;
- teste de scope violation bloqueando antes de comando;
- teste de failure capsule hashando log bruto e removendo segredo;
- teste de high-risk sem deep gate quando recurso nega heavy jobs;
- AQPES certification incluindo `local_verification_runtime_ready`.

## Riscos

- Failure capsule cortar detalhe essencial.
- Selecao de teste incompleta gerar confianca falsa.
- Diff scope mal configurado bloquear arquivo valido.
- CPU loop caro se ALVE virar executor sem resource governor.
- Repair automatico com causa raiz fraca.

## Exemplos

Um teste falha com log grande e segredo. ALVE remove segredo, preserva arquivo,
linha, teste e causa raiz, hasha o raw log e envia so a capsula ao proximo repair.

## Proximas Acoes

1. Rodar ALVE em shadow nos fluxos Atlas Dev e Forge.
2. Persistir receipts por run real.
3. Conectar failure capsule ao repair loop com limite de tentativas.
