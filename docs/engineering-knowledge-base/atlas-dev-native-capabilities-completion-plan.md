---
id: atlas-dev-native-capabilities-completion-plan
type: engineering_knowledge
title: Atlas Dev Native Capabilities Completion Plan
status: active
category: programming-dev
priority: 97
summary: Registro de conclusao dos 15 blocos nativos do Atlas Dev inspirados no Forge sem transformar Dev em Obra longa.
human_summary: Registro canonico para proximas IAs entenderem que os 15 blocos Dev-native foram materializados e certificados.
human_what: Plano promovido para runtime dos 15 blocos Dev-native.
human_purpose: Guiar implementacao de contexto, testes, falha, review, delegacao, prompt seguro, scope, simulacao, completion e memoria no Dev.
human_input: ADRI entregue, Forge-native capabilities, Atlas Dev atual, gaps de execucao real.
human_output: Ordem, criterio de pronto, fatias, arquivos provaveis, testes e riscos.
human_change_when: Atualize ao implementar qualquer bloco ou promover este plano para spec canonica.
human_block_when: Bloqueie se alguem duplicar Forge, remover RAG/ScopeGuard ou declarar pronto sem teste.
tags:
  - atlas
  - atlas-dev
  - programming
  - implementation-plan
  - canonical-glossary
capabilities:
  - dev_native_capabilities_completion
  - dev_context_gate_enforcement
  - dev_run_materialization
  - dev_run_certification
decisions:
  - Atlas Dev continua leve; Forge continua sendo Obra longa.
  - Todo bloco promovido deste plano precisa de teste focado e cert final.
  - Conversa nunca deve ser fonte primaria de run anterior; materializacao vence.
maintenance:
  - Arquivar esta doc quando os 15 blocos virarem runtime/docs canonicas.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-runtime-intelligence.md
  - docs/engineering-knowledge-base/atlas-forge-work-packet-native-capabilities.md
  - docs/engineering-knowledge-base/atlas-dev-forge-relationship-critical-audit.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - app/Services/Ai/Programming/AtlasDev
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-native-capabilities-completion-plan
graph_title: Atlas Dev Native Capabilities Completion Plan
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-dev-runtime-intelligence
graph_status: active
graph_source: repo
human_name: Atlas Dev Native Capabilities Completion Plan
canonical_name: Atlas Dev Native Capabilities Completion Plan
technical_name: atlas-dev-native-capabilities-completion-plan
product_name: Atlas Dev Native Capabilities Completion Plan
internal_product_name: Atlas Dev Completion Backlog
technical_runtime: AtlasDevNativeCapabilitiesCompletionPlanDocument
runtime_acronym: ADNCP
cartography_type: implementation_plan
canonical_source: docs/engineering-knowledge-base/atlas-dev-native-capabilities-completion-plan.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-native-capabilities-completion-plan.md
  - app/Services/Ai/Programming/AtlasDev
allowed_changes:
  - Promover itens para specs/runtime com teste e certificacao.
forbidden_changes:
  - Copiar Forge literalmente.
  - Declarar bloco pronto sem materializacao, teste e evidencia.
depends_on:
  - atlas-dev-runtime-intelligence
  - atlas-forge-work-packet-native-capabilities
flows_to:
  - atlas-programming-superiority-architecture
unlocks:
  - dev-native-capabilities-completion
governs:
  - programming.dev
  - programming.review
  - programming.repair
evidence:
  - docs/engineering-knowledge-base/atlas-dev-runtime-intelligence.md
  - tests/Feature/Ai/Programming/AtlasDev/AtlasDevRuntimeIntelligenceTest.php
required_tests:
  - "php artisan test tests/Feature/Ai/Programming/AtlasDev/AtlasDevRuntimeIntelligenceTest.php"
  - "php artisan test tests/Unit/Ai/Programming/AtlasDevRuntimeServiceTest.php"
  - "php artisan atlas:programming:final-certify --json"
requires_evidence: true
risk_level: high
implementation_state: active_runtime
next_actions:
  - Manter esta doc alinhada com atlas-dev-runtime-intelligence.md.
  - Arquivar quando ADRI absorver totalmente o historico do plano.
visual_tags:
  - atlas-dev
  - plan
  - completion
ai_entrypoints:
  - Leia Resumo, Blocos Restantes, Fatias e Gates antes de alterar Atlas Dev.
ai_usage_notes:
  - Use como plano temporario; runtime canonico atual fica em atlas-dev-runtime-intelligence.md.
quality_gates:
  - "php artisan test tests/Feature/Ai/Programming/AtlasDev/AtlasDevRuntimeIntelligenceTest.php"
  - "php artisan atlas:programming:final-certify --json"
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Dev virar Forge improvisado.
  - IA depender de conversa para entender run anterior.
observability_signals:
  - dev_runtime_intelligence pass
---
# Atlas Dev Native Capabilities Completion Plan

## Resumo

ADRI entregou a base persistida e a promocao dos 15 blocos: task packet,
context gate, test impact, senior review, delegation route, prompt projection,
scope guard, simulation, completion gate, provider capacity memory, Forge
escalation policy, decision materialization, failure capsule, outcome memory e
run certification. Esta doc fica como registro de implementacao.

## Papel no Atlas

Este plano orienta a evolucao do Atlas Dev para tarefas curtas de codigo. Ele
existe para impedir que cada IA reinvente contexto, teste, scope e fechamento.

## Onde Se Encaixa

Fica abaixo de `atlas-dev-runtime-intelligence.md` e acima das proximas fatias
de implementacao em `app/Services/Ai/Programming/AtlasDev`.

## Contratos

- Dev continua leve.
- Forge continua responsavel por Obra longa.
- Cada bloco promovido precisa de runtime, teste e evidencia.
- Decisao importante deve ser materializada, nao perdida em conversa.

## Fluxo

```text
payload Dev -> task packet -> context/test/scope/simulation
  -> provider/subagente/local/Forge
  -> failure/outcome -> completion -> run certify
```

## Regra Central

Atlas Dev deve resolver tarefa curta. Se houver muitos arquivos, muitos
dominios, incerteza alta, falha repetida ou milestone, escale para Forge.

## Blocos Restantes

### 1. Outcome Memory por run

Status: entregue em `DevOutcomeMemoryService`.

Conectar `atlas_dev_outcome_memories` ao terminal real do executor Dev.
Persistir status, testes, evidencias, arquivos, causa, repair e aprendizado.

Pronto: sucesso/falha/bloqueio gravam outcome; falha exige capsule.

### 2. Task Packet Intelligence leve

Status: entregue em `DevTaskPacketRuntimeService`.

Melhorar extracao real de objetivo, arquivos provaveis, risco, escopo,
criterios e testes a partir do payload/RAG/discovery.

Pronto: todo run relevante tem packet deterministico e hash estavel.

### 3. Context Gate obrigatorio

Status: entregue em `DevContextGateService`.

Transformar preview `provider_safe` em gate efetivo para tarefa nao trivial.
Bloquear sem owner doc, arquivos relevantes, contrato, escopo ou teste.

Pronto: provider nao roda se `provider_safe=false`, salvo bypass auditavel.

### 4. Test Impact por task

Status: entregue em `DevNativeCapabilityOrchestrator` e `AtlasDevDecisionMaterialization`.

Emitir `focused_tests`, `fallback_tests` e `skip_reason`.

Pronto: todo packet tem teste selecionado ou motivo formal.

### 5. Failure Capsule padronizado

Status: entregue em `DevFailureCapsuleRuntimeService`.

Conectar capsule ao repair loop real: erro minimo, causa provavel, arquivo
suspeito, comando falho, proxima tentativa e retry budget.

Pronto: repair consome capsule, nao log bruto.

### 6. Senior Review proporcional

Status: entregue em `DevNativeCapabilityOrchestrator` e `AtlasDevDecisionMaterialization`.

Ativar review automatico apenas para risco alto: auth, migrations, payment,
provider, docs canon, cartografia, runtime critico, security e dados.

Pronto: patch simples nao paga review; patch sensivel bloqueia sem review.

### 7. Workcell/delegation route leve

Status: entregue em `DevNativeCapabilityOrchestrator` e `AtlasDevDecisionMaterialization`.

Decidir `local`, `explorer_subagent`, `worker_subagent` ou `forge_escalation`.

Pronto: Dev sabe quando nao deve tentar sozinho.

### 8. Prompt Projection segura

Status: entregue em `DevNativeCapabilityOrchestrator` e `AtlasDevDecisionMaterialization`.

Todo prompt provider/subagente deve ter redaction, escopo, nao-goals, arquivos,
criterio de pronto e evidencia esperada.

Pronto: nenhum prompt envia contexto bruto desnecessario.

### 9. Scope Guard por task

Status: entregue em `DevNativeCapabilityOrchestrator` e `AtlasDevDecisionMaterialization`.

Amarrar ScopeGuard ao `DevTaskPacket` e validar diff contra allowed/forbidden.

Pronto: arquivo fora de escopo bloqueia patch/certificacao.

### 10. Simulation antes de executar

Status: entregue em `DevNativeCapabilityOrchestrator` e `AtlasDevDecisionMaterialization`.

Simular impacto, arquivos tocados, testes afetados, risco de conflito e docs.

Pronto: medium/high risk tem simulation antes do provider.

### 11. Completion Gate explicito

Status: entregue em `DevNativeCapabilityOrchestrator`, `DevRunCertificationService` e `AtlasDevDecisionMaterialization`.

Fechar com diff limpo, testes/skip, evidencia, docs se aplicavel, scope guard
e outcome memory.

Pronto: nenhum run declara pronto sem prova minima.

### 12. Provider capacity/failure memory

Status: entregue em `DevNativeCapabilityOrchestrator` e `AtlasDevDecisionMaterialization`.

Registrar provider/model por tipo de tarefa, falha, custo/latencia e resultado.

Pronto: roteamento usa evidencia historica, sem aprendizado falso.

### 13. Escalation mais agressiva para Forge

Status: entregue em `DevNativeCapabilityOrchestrator` e `AtlasDevDecisionMaterialization`.

Escalar por incerteza alta, muitos arquivos, varios dominios, falha repetida,
risco senior ou necessidade de milestone.

Pronto: Dev nao vira Forge improvisado.

### 14. Run certification curta

Status: entregue em `AtlasDevRunCertifyCommand`.

Comando:

```bash
php artisan atlas:dev:run-certify --run=<id> --json
```

Pronto: comando retorna `ready`, `needs_review` ou `blocked`.

### 15. Materializacao de decisoes importantes

Status: entregue em `AtlasDevDecisionMaterialization` e `DevDecisionMaterializationService`.

Persistir selected tests, prompt projection, scope result, simulation, senior
review, delegation route e provider observation.

Pronto: proxima IA entende run anterior sem depender da conversa.

## Fatias

1. **Fatia A — Terminal Run Materialization:** entregue.
2. **Fatia B — Pre-provider Enforcement:** entregue.
3. **Fatia C — Test/Scope/Simulation:** entregue.
4. **Fatia D — Delegation/Prompt/Senior:** entregue.
5. **Fatia E — Dev Run Certify Command:** entregue.

## Regras para IA

- Leia `atlas-dev-runtime-intelligence.md` antes desta doc.
- Nao implemente tudo em um patch gigante.
- Cada bloco promovido precisa de teste focado e check na cert final.
- Nao remova RAG gate, ScopeGuard, VerificationGate ou CompletionStateGate.

## Escopo de Implementacao

Manter os 15 blocos em cinco fatias. Nao tocar UX, TEOS ou Forge salvo
quando a escalacao Dev->Forge exigir contrato.

## Dependencias

- `DevRuntimeIntelligenceService`
- `AtlasDevRuntimeService`
- `MandatoryRagGate`
- `ScopeGuard`
- `VerificationGate`
- `CompletionStateGate`

## Evidencias

Cada fatia deve atualizar testes, cert final e docs-health.

```bash
php artisan test tests/Feature/Ai/Programming/AtlasDev/AtlasDevRuntimeIntelligenceTest.php
php artisan test tests/Unit/Ai/Programming/AtlasDevRuntimeServiceTest.php
php artisan atlas:programming:final-certify --json
php artisan atlas:engineering:knowledge docs-health --json
git diff --check
```

## Riscos

- Dev virar Forge improvisado.
- Provider rodar sem contexto suficiente.
- Falha ficar em log bruto, sem capsule.
- Outcome nao alimentar memoria.
- Proxima IA depender de conversa antiga.

## Exemplos

Promocao correta: implementar Test Impact por task, criar service, persistir
resultado, cobrir teste, atualizar cert final e marcar item como entregue.

Promocao incorreta: adicionar texto na doc dizendo que existe sem runtime.

## Proximas Acoes

1. Manter testes verdes.
2. Atualizar esta doc quando ADRI mudar.
3. Arquivar este plano quando `atlas-dev-runtime-intelligence.md` absorver todo
   historico operacional.
