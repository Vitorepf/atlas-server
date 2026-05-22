---
id: atlas-ai-assisted-execution-quality
type: engineering_knowledge
title: Atlas AI Assisted Execution Quality
status: active
category: atlas-ai
priority: 99
summary: Contrato que transforma pedido humano em envelope verificavel para Dev ou Forge com spec, contexto, testes, scope, repair, memoria e certificacao.
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
envelope com rota, contrato, gate, evidencias e hash.

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
  -> DevTaskPacket/ForgeWorkPacket
  -> context_gate
  -> test_impact + scope_guard + simulation
  -> provider_prompt_projection
  -> execution_or_escalation
  -> failure_capsule_or_success
  -> completion_gate
  -> outcome_memory
  -> run_certification
```

## Fluxo

1. Recebe `human_request`, workspace e contexto opcional.
2. Decide rota `atlas_dev` ou `atlas_forge`.
3. Monta `execution_contract` com objetivo, risco, arquivos, testes e evidencia.
4. Para Dev, chama `DevRuntimeIntelligenceService::preview`.
5. Bloqueia quando falta workspace ou o context gate nao permite provider.
6. Emite `assisted_execution_hash` deterministico.

## Regras para IA

- Sem workspace, status `needs_context`.
- Login/auth sobe risco para `high`.
- Dev so executa se `provider_safe=true`.
- Forge recebe trabalho longo, multi-arquivo, milestone ou Obra.
- Completion exige evidencia minima.

## Escopo de Implementacao

Inclui envelope, rota, contrato, blockers, preview Dev, wiring HTTP, testes e
check na product certification. Nao executa provider e nao declara superioridade.

## Dependencias

- `AiInteractionController`
- `AtlasAiAssistedExecutionQualityService`
- `DevRuntimeIntelligenceService`
- `ForgeWorkPacketExecutionCycleService`
- `AtlasAiProductCertificationService`

## Evidencias

O servico `AtlasAiAssistedExecutionQualityService` gera envelope sem chamar
provider. A certificacao de produto exige esse contrato para declarar Atlas AI
ready.

## Riscos

- Heuristica simples rotear Dev quando deveria ir para Forge.
- Contexto default mascarar ausencia de owner doc especifico.
- Surface futura usar payload de programacao sem passar pelo controller atual.
- IA declarar pronto sem run certification real.

## Exemplos

Pedido: `estou com um bug na tela de login`.

Resultado esperado: `programming.repair`, risco `high`, teste sugerido
`Login|Auth|Session`, context gate provider-safe e completion com evidencia.

Pedido: `crie uma Obra para refatorar o sistema inteiro`.

Resultado esperado: rota `atlas_forge`, sem preview Dev e sem provider bruto.

## Proximas Acoes

1. Manter product-certify 20/20.
2. Conectar outcome real do executor quando Dev/Forge finalizarem runs.
3. Adicionar novas heuristicas somente com teste de regressao.
