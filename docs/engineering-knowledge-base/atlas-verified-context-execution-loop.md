---
id: atlas-verified-context-execution-loop
type: engineering_knowledge
title: Atlas Verified Context Execution Loop
status: building
implementation_state: v1_shadow_runtime_active; service, command and focused tests implement read-only AVCEL loop over ACCCR, ATER, ALVE and AEMOR outcome candidate.
category: intelligence-runtime
priority: 100
summary: Loop canonico que une contexto minimo perfeito, cache, economia de token, verificacao local, failure capsule, repair strategy e outcome memory candidate sem chamar provider e sem executar comandos.
human_summary: AVCEL garante que Atlas Dev/Forge trabalhem com contexto certo, prova local e aprendizado por resultado antes de gastar tokens caros.
human_what: Runtime de shadow/read-only que verifica se o contexto esta completo, se a economia e segura, quais testes importam, qual falha deve ir para repair e qual outcome deve alimentar AEMOR depois da execucao real.
human_purpose: Reduzir erro de IA, custo de token e tentativas cegas sem diminuir qualidade.
human_input: Flow, risco, provider, workspace, arquivos alterados, allowed/forbidden files, evidence refs, logs de falha, CPU/RAM e task type.
human_output: Envelope AVCEL com 8 estagios, quality contract, resource policy, repair strategy, outcome memory candidate, metricas e hash deterministico.
human_change_when: Mexa quando mudar Dev/Forge execution loop, context cache, token economy, ALVE, AEMOR ou repair policy.
human_block_when: Bloqueie quando must-keep cair abaixo de 1.0, cache ficar stale/poisoned, verificação local bloquear, evidence sumir ou CPU/RAM invadir reserva do operador.
product_name: Atlas Verified Context Execution Loop
runtime_acronym: AVCEL
internal_product_name: Atlas Proof-Before-Repair Loop
technical_runtime: AtlasVerifiedContextExecutionLoopService
tags: [atlas-ai, avcel, context, local-verification, token-economy, repair, aemor]
capabilities: [verified_context_execution, failure_capsule_repair, local_cpu_proof, context_cache_guard, outcome_memory_candidate]
decisions:
  - AVCEL v1 opera apenas em shadow/certify, sem provider, sem comando local e sem write.
  - Outcome AEMOR e apenas candidato ate haver execucao real com evidence refs.
  - Qualquer economia de token precisa preservar must_keep_coverage igual a 1.0.
maintenance:
  - Atualizar quando ACCCR, ALVE, AEMOR, AQPES, Dev ou Forge mudarem contrato.
  - Rodar docs-health e teste focado apos alterar.
  - Nao promover para enforcement sem rollback e receipts reais.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-quality-preserving-efficiency-system.md
  - docs/engineering-knowledge-base/atlas-context-cache-compiler-runtime.md
  - docs/engineering-knowledge-base/atlas-local-verification-engine.md
  - docs/engineering-knowledge-base/atlas-aemor-runtime.md
  - app/Services/Ai/VerifiedContextExecution/AtlasVerifiedContextExecutionLoopService.php
  - app/Console/Commands/AtlasVerifiedContextExecutionLoopCommand.php
  - tests/Feature/Ai/VerifiedContextExecution/AtlasVerifiedContextExecutionLoopServiceTest.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-verified-context-execution-loop
graph_title: Atlas Verified Context Execution Loop
graph_world: atlas
graph_layer: system
graph_kind: system
graph_parent: atlas-quality-preserving-efficiency-system
graph_status: building
graph_source: repo
human_name: Loop Verificado de Contexto e Execucao
canonical_name: Atlas Verified Context Execution Loop
technical_name: AtlasVerifiedContextExecutionLoopService
cartography_type: runtime
canonical_source: docs/engineering-knowledge-base/atlas-verified-context-execution-loop.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-verified-context-execution-loop.md
  - app/Services/Ai/VerifiedContextExecution/AtlasVerifiedContextExecutionLoopService.php
  - app/Console/Commands/AtlasVerifiedContextExecutionLoopCommand.php
  - tests/Feature/Ai/VerifiedContextExecution/AtlasVerifiedContextExecutionLoopServiceTest.php
allowed_changes:
  - Conectar novos verificadores locais, repair policies e outcome receipts.
  - Promover shadow para opt-in somente com evidence e rollback.
forbidden_changes:
  - Chamar provider dentro do shadow/certify.
  - Executar comandos locais dentro do shadow/certify.
  - Persistir outcome AEMOR antes de execucao real.
  - Economizar token removendo must-keep, owner doc, teste, risco ou evidence.
depends_on:
  - atlas-quality-preserving-efficiency-system
  - atlas-context-cache-compiler-runtime
  - atlas-local-verification-engine
  - atlas-aemor-runtime
flows_to:
  - atlas-dev
  - atlas-forge
  - atlas-control-plane
unlocks:
  - verified_context_execution_shadow
  - failure_capsule_repair_context
  - aemor_outcome_candidate
governs:
  - atlas.verified_context_execution_loop.shadow.v1
  - atlas.verified_context_execution_loop.certification.v1
  - atlas.verified_context_execution_loop.repair_strategy.v1
  - atlas.verified_context_execution_loop.outcome_candidate.v1
evidence:
  - app/Services/Ai/VerifiedContextExecution/AtlasVerifiedContextExecutionLoopService.php
  - app/Console/Commands/AtlasVerifiedContextExecutionLoopCommand.php
  - tests/Feature/Ai/VerifiedContextExecution/AtlasVerifiedContextExecutionLoopServiceTest.php
evidence_refs:
  - symbol: AtlasVerifiedContextExecutionLoopService
  - command: atlas:verified-context-execution
required_tests:
  - "php artisan atlas:verified-context-execution certify --json"
  - "php artisan atlas:verified-context-execution shadow --json"
  - "php artisan test tests/Feature/Ai/VerifiedContextExecution/AtlasVerifiedContextExecutionLoopServiceTest.php"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: critical
line_limit: 260
next_actions:
  - Conectar AVCEL ao Dev/Forge como opt-in shadow.
  - Registrar outcomes reais no AEMOR depois de execucao verificada.
  - Medir economia real de token antes de enforcement.
---
# Atlas Verified Context Execution Loop

## Resumo

AVCEL e o loop que junta os blocos de eficiencia e qualidade em uma decisao
operacional unica:

```text
contexto correto -> must-keep guard -> cache/delta -> economia segura
-> verificacao local -> failure capsule -> repair strategy -> AEMOR candidate
```

Ele existe para Atlas Dev e Atlas Forge pararem de gastar tokens tentando
adivinhar. Primeiro o Atlas prova contexto, escopo, testes e falha localmente.
So depois um modelo caro entra, se ainda for necessario.

## Papel no Atlas

AVCEL fica acima de ACCCR, ATER, ALVE e AEMOR. Ele nao substitui esses blocos:
ele junta os receipts em uma decisao unica para Dev e Forge.

## Onde Se Encaixa

```text
AQPES -> AVCEL -> Dev/Forge execution -> AEMOR outcome -> proximo contexto
```

## Contratos

- `must_keep_coverage` precisa ser `1.0`.
- `quality_regression_allowed` e sempre `false`.
- `evidence_loss_allowed` e sempre `false`.
- `providers_invoked` e sempre `false` em `shadow` e `certify`.
- `commands_executed` e sempre `false` em `shadow` e `certify`.
- `writes` e sempre `false` em `shadow` e `certify`.
- `rollback_required` e sempre `true`.

## Fluxo

1. `context_compile`: monta o pack e hash de contexto.
2. `must_keep_guard`: prova que decisoes, blockers, riscos e evidence ficaram.
3. `cache_delta`: separa prefixo cacheavel e delta mutavel.
4. `token_economy`: estima economia sem perda de qualidade.
5. `local_verification`: usa ALVE para diff scope, test impact e gate plan.
6. `failure_capsule`: compacta falha sem vazar log bruto.
7. `repair_strategy`: decide se repara, bloqueia ou pede evidencia melhor.
8. `outcome_memory_candidate`: prepara candidato para AEMOR apos execucao real.

## Regras para IA

- Nao chame provider dentro de AVCEL shadow/certify.
- Nao execute comando local dentro de AVCEL shadow/certify.
- Nao persista aprendizado AEMOR sem evidence real.
- Nao economize token se remover owner doc, teste, risco ou decision.

## Escopo de Implementacao

Implementado hoje: service, command, shadow envelope, certification envelope, repair
strategy, outcome candidate e testes focados.

Fora do v1: enforcement automatico, provider call, test execution real e persistencia
AEMOR.

## Dependencias

- AQPES define resource policy e contrato de qualidade.
- ACCCR monta cache/delta de contexto.
- ATER estima economia segura.
- ALVE seleciona testes e compacta falhas.
- AEMOR recebe outcome real depois.

## Evidencias

- `AtlasVerifiedContextExecutionLoopService`
- `AtlasVerifiedContextExecutionLoopCommand`
- `AtlasVerifiedContextExecutionLoopServiceTest`
- `php artisan atlas:verified-context-execution certify --json`

## Riscos

- Economia falsa: bloqueada por `must_keep_coverage`.
- Cache stale/poisoned: bloqueado pelo runtime ACCCR.
- Log bruto em repair: bloqueado por failure capsule.
- Aprendizado falso: outcome e apenas candidato ate execucao real.

## Como Usar

```bash
php artisan atlas:verified-context-execution certify --json
php artisan atlas:verified-context-execution shadow --changed-file=app/Foo.php --json
```

Use `shadow` antes de uma fatia Dev/Forge para saber:

- se o contexto esta completo;
- quais testes provaveis devem rodar;
- se ha violacao de escopo;
- se uma falha pode virar repair capsule;
- se o resultado pode alimentar AEMOR depois.

## Exemplos

```bash
php artisan atlas:verified-context-execution shadow \
  --changed-file=app/Services/Ai/Foo.php \
  --command="php artisan test tests/Feature/FooTest.php" \
  --exit-code=1 \
  --failing-test="Tests\\Feature\\FooTest::test_example" \
  --json
```

## Proximas Acoes

AVCEL so pode virar enforcement parcial quando:

- shadow passar por runs reais de Dev/Forge;
- AEMOR receber outcomes reais com evidence refs;
- failure capsules demonstrarem repair melhor que log bruto;
- token savings forem medidos sem regressao de qualidade;
- CPU/RAM ficarem dentro da reserva do operador;
- rollback estiver testado.

## Limites

AVCEL v1 nao executa teste, nao chama provider e nao persiste memoria. Ele cria
o envelope canônico que conecta os blocos existentes e impede que a otimizacao
vire chute. A execucao real continua nos runtimes Dev/Forge.
