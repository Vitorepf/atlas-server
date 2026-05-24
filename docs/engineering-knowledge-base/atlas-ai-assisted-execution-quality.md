---
id: atlas-ai-assisted-execution-quality
type: engineering_knowledge
title: Atlas AI Assisted Execution Quality
status: active
category: atlas-ai
priority: 99
summary: Contrato que transforma pedido humano em envelope verificavel para Dev ou Forge com AEDPDS, AUCRI/ACMF, AREG, AEMOR, spec, contexto, testes, scope, repair, memoria e certificacao.
human_summary: Garante que uma frase como "bug na tela de login" vire uma execucao assistida com contexto, teste, prova e memoria.
human_what: Camada de qualidade para execucao assistida por IA.
human_purpose: Impedir que Atlas AI trate pedido humano como chat solto; todo trabalho de codigo precisa virar contrato verificavel.
human_input: Pedido humano, workspace, contexto opcional, arquivos esperados e criterios.
human_output: Assisted execution envelope com rota Dev/Forge, contrato, pipeline obrigatorio, blockers e hash.
human_change_when: Atualize quando Dev, Forge, certificacao de produto ou qualidade assistida mudarem.
human_block_when: Bloqueie se provider puder rodar sem context gate, teste, scope ou completion gate.
tags:
  - atlas
  - atlas-ai
  - assisted-execution
  - atlas-dev
  - atlas-forge
capabilities:
  - assisted_execution_quality
  - human_request_to_execution_contract
  - dev_forge_quality_bridge
decisions:
  - Pedido humano nunca vira provider call bruto.
  - Trabalho curto vai para Atlas Dev; trabalho longo/incerto vai para Forge.
  - Completion exige evidencia, failure capsule quando falha e outcome memory.
  - Programacao assistida precisa de AEDPDS, AUCRI/ACMF, AREG e AEMOR no envelope antes de provider.
  - Outcome pos-execucao volta para AREG/AEMOR por `recordOutcomeFeedback`.
  - Runtime UX expõe estado operacional assistido sem prompt bruto pelo schema `atlas.ai.assisted_execution.operational_ux.v1`.
maintenance:
  - Atualizar quando Atlas Dev, Forge ou Product Certification mudarem o contrato de execucao assistida.
  - Rodar docs-health e product-certify apos qualquer mudanca nesta doc.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - app/Services/Ai/Product/AtlasAiAssistedExecutionQualityService.php
  - app/Services/Ai/Product/AtlasAiProductCertificationService.php
  - app/Services/Ai/Programming/AtlasDev/RuntimeIntelligence/DevRuntimeIntelligenceService.php
  - app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleService.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-assisted-execution-quality
graph_title: Atlas AI Assisted Execution Quality
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-product-certification
graph_status: active
graph_source: repo
human_name: Atlas AI Assisted Execution Quality
canonical_name: Atlas AI Assisted Execution Quality
technical_name: atlas-ai-assisted-execution-quality
product_name: Atlas AI Assisted Execution Quality
internal_product_name: Assisted Execution Quality Gate
technical_runtime: AtlasAiAssistedExecutionQualityService
runtime_acronym: AAEQ
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-ai-assisted-execution-quality.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-assisted-execution-quality.md
  - app/Services/Ai/Product/AtlasAiAssistedExecutionQualityService.php
allowed_changes:
  - Ajustar heuristica de rota e contrato quando testes e certificacao forem atualizados.
forbidden_changes:
  - Permitir provider sem context gate.
  - Declarar completion sem evidencia.
  - Tratar Obra longa como Dev curto.
depends_on:
  - atlas-dev-runtime-intelligence
  - atlas-forge-work-packet-native-capabilities
  - atlas-execution-doctrine-product-delivery-system
  - atlas-cognitive-memory-fabric
  - atlas-runtime-efficiency-governor
  - atlas-execution-memory-outcome-runtime
flows_to:
  - atlas-ai-product-certification
unlocks:
  - assisted-ai-execution-quality
governs:
  - atlas-ai
  - programming.dev
  - programming.forge
evidence:
  - tests/Feature/Ai/Product/AtlasAiAssistedExecutionQualityServiceTest.php
required_tests:
  - "php artisan test tests/Feature/Ai/Product/AtlasAiAssistedExecutionQualityServiceTest.php"
  - "php artisan test tests/Feature/Ai/Product/AtlasAiProductCertificationServiceTest.php"
requires_evidence: true
risk_level: high
quality_gates:
  - "php artisan atlas:ai:product-certify --json"
next_actions:
  - Manter o envelope AAEQ anexado ao payload de programacao em AiInteractionController.
  - Expandir heuristicas de rota somente com testes e evidence de produto.
  - Integrar sinais reais de completion/outcome quando o executor Dev/Forge fechar runs.
failure_modes:
  - Pedido humano virar prompt bruto.
  - Login/auth sem risco alto.
  - Provider rodar sem workspace/contexto.
  - Falha sem capsule.
---
# Atlas AI Assisted Execution Quality

## Resumo

AAEQ garante que pedido humano vire contrato de execucao. Exemplo:
"estou com um bug na tela de login" precisa gerar objetivo, risco, arquivos
provaveis, testes, contexto minimo, scope, simulation, repair loop, outcome e
certificacao.

## Papel no Atlas

AAEQ fica entre Atlas AI e os runtimes de programacao. Ele impede que uma frase
humana ambigua seja tratada como prompt bruto. Antes de provider, a frase vira
envelope com rota, contrato, gate, evidencias e hash. Esse envelope inclui as
areas responsaveis da atuacao assistida por IA:

- `AEDPDS`: seleciona drivers e bloqueia execucao quando a doutrina exige contexto, teste, review, UX ou contrato.
- `AUCRI/ACMF`: produz plano de memoria de trabalho, must-keep coverage, delta e spillover sem expor texto bruto.
- `AREG`: decide caminho, budget, admissao de camadas e plano de verificacao sem persistir no preview.
- `AEMOR`: declara o contrato de outcome obrigatorio para fechar a execucao com evidencia e eficacia dos drivers.
- `Atlas Dev/Forge`: executa ou escala depois que os gates anteriores estiverem claros.

## Onde Se Encaixa

AAEQ roda no `AiInteractionController` para payloads de programacao. Trabalho
curto segue para Atlas Dev; Obra, milestone, sistema inteiro ou escopo grande
segue para Atlas Forge. Os nomes Dev/Forge seguem o glossario canonico.

## Contratos

```text
pedido humano
  -> human_intake
  -> route_selection Dev/Forge
  -> execution_contract
  -> AEDPDS doctrine + gate
  -> AUCRI/ACMF cognitive memory plan
  -> AREG efficiency decision
  -> DevTaskPacket/ForgeWorkPacket
  -> context_gate
  -> test_impact + scope_guard + simulation
  -> provider_prompt_projection
  -> execution_or_escalation
  -> failure_capsule_or_success
  -> completion_gate
  -> AEMOR outcome_memory
  -> run_certification
```

## Fluxo

1. Recebe `human_request`, workspace e contexto opcional.
2. Decide rota `atlas_dev` ou `atlas_forge`.
3. Monta `execution_contract` com objetivo, risco, arquivos, testes, UX, review e evidencia.
4. Executa AEDPDS selector/gate sem LLM.
5. Executa ACMF para memoria de trabalho provider-safe.
6. Executa AREG em modo preview sem persistencia.
7. Anexa contrato AEMOR de outcome obrigatorio.
8. Para Dev, chama `DevRuntimeIntelligenceService::preview`.
9. Bloqueia quando falta workspace, AEDPDS gate, ACMF, AREG ou context gate.
10. Emite `assisted_execution_hash` deterministico.

## Regras para IA

- Sem workspace, status `needs_context`.
- Login/auth sobe risco para `high`.
- Login/auth/security/billing sem review fica bloqueado por AEDPDS.
- Dev so executa se `provider_safe=true`.
- Provider nao roda sem AEDPDS gate, ACMF e AREG presentes.
- Forge recebe trabalho longo, multi-arquivo, milestone ou Obra.
- Completion exige evidencia minima e outcome AEMOR.

## Escopo de Implementacao

Inclui envelope, rota, contrato, blockers, preview Dev, wiring HTTP, testes e
check na product certification. Nao executa provider e nao declara superioridade.

## Dependencias

- `AiInteractionController`
- `AtlasAiAssistedExecutionQualityService`
- `AtlasExecutionDoctrineRuntimeService`
- `AtlasExecutionDoctrineGateService`
- `AtlasCognitiveMemoryFabricService`
- `AtlasRuntimeEfficiencyGovernorService`
- `AtlasAemorRuntimeService`
- `DevRuntimeIntelligenceService`
- `ForgeWorkPacketExecutionCycleService`
- `AtlasAiProductCertificationService`

## Evidencias

O servico `AtlasAiAssistedExecutionQualityService` gera envelope sem chamar
provider. Ele tambem expõe `recordOutcomeFeedback`, cujo schema
`atlas.ai.assisted_execution_outcome_feedback.v1` fecha o loop pos-execucao:
por padrao emite feedback AREG/AEMOR sem writes; com `persist=true`, grava
AREG outcome e AEMOR episode/outcome quando as tabelas locais existem. A
certificacao de produto exige esse contrato para declarar Atlas AI ready.

## Runtime UX Operacional

`AtlasAiRuntimeReadinessService::uxBundle()` publica uma projeção leve de
execucao assistida em `assisted_execution`, com schema
`atlas.ai.assisted_execution.operational_ux.v1`. Essa projeção e feita a partir
do envelope AAEQ e do feedback AREG/AEMOR em modo `persist=false`, portanto:

- não chama provider;
- não grava outcome;
- não expõe pedido humano bruto;
- mostra `doctrine_gate_status`, `selected_drivers`, `context_memory_status`,
  `areg_path`, `outcome_feedback_status`, `aemor_feedback_status`, blockers e
  hash.

Desktop e Mobile consomem esse campo por view-model tipado. A certificação
`atlas:ai:runtime-ux-certify --json --strict` exige o check
`assisted_execution_operational_ux`; se essa projeção virar só documentação ou
perder AEDPDS/contexto/AREG/AEMOR, a cert deixa de retornar `ready`.

## Riscos

- Heuristica simples rotear Dev quando deveria ir para Forge.
- Contexto default mascarar ausencia de owner doc especifico.
- Surface futura usar payload de programacao sem passar pelo controller atual.
- IA declarar pronto sem run certification real.

## Exemplos

Pedido: `estou com um bug na tela de login`.

Resultado esperado sem review: `needs_context`, `programming.repair`, risco
`high`, teste sugerido `Login|Auth|Session`, context gate provider-safe, mas
AEDPDS bloqueado por `missing_senior_review_for_sensitive_change`.

Resultado esperado com review: `ready_for_assisted_execution`, AEDPDS gate
`passed`, ACMF `ready`, AREG com `writes=false` e contrato AEMOR exigindo
`driver_effectiveness`.

Fechamento esperado: `recordOutcomeFeedback` recebe o envelope e evidencias
reais da execucao. Sem `persist=true`, retorna receipt sem escrita e com
`requires_aemor_judgment_for_learning_promotion=true`. Com `persist=true`,
registra AREG outcome e AEMOR outcome; aprendizagem/promocao continua bloqueada
ate AEMOR Judgment Guard.

Pedido: `crie uma Obra para refatorar o sistema inteiro`.

Resultado esperado: rota `atlas_forge`, sem preview Dev e sem provider bruto.

## Proximas Acoes

1. Manter product-certify 21/21.
2. Conectar outcome real do executor quando Dev/Forge finalizarem runs.
3. Adicionar novas heuristicas somente com teste de regressao.
