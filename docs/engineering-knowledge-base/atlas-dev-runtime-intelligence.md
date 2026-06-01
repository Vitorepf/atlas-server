---
id: atlas-dev-runtime-intelligence
type: engineering_knowledge
title: Atlas Dev Runtime Intelligence
status: active
category: programming-dev
priority: 98
summary: Especificacao dos 15 blocos Atlas Dev que materializam pacote, contexto, teste, falha, review, delegacao, prompt seguro, scope, simulacao, completion, memoria e certificacao por run.
human_summary: Camada que impede o Atlas Dev de executar uma tarefa curta sem pacote, contexto minimo, teste, escopo, evidencia, memoria de resultado e certificacao.
human_what: Quinze blocos nativos para cada run Dev relevante.
human_purpose: Tornar Atlas Dev mais verificavel, reparavel, aprendivel e seguro sem virar Forge/Obra longa.
human_input: Recebe objetivo, workspace, arquivos esperados, contexto, testes, evidencia e estado terminal do run.
human_output: Entrega task packet, context gate, decisoes materializadas, failure capsule opcional, outcome memory e run certification.
human_change_when: Atualize quando o fluxo Dev, runtime preview, failure capsule, outcome memory ou certificacao de programming runtime mudar.
human_block_when: Bloqueie se Dev tentar enviar provider sem contexto minimo, escopo, plano de teste ou evidencia declarada.
tags:
  - atlas
  - atlas-dev
  - programming
  - runtime-intelligence
  - canonical-glossary
capabilities:
  - dev_task_packet_runtime
  - dev_context_gate
  - dev_failure_capsule
  - dev_outcome_memory
  - dev_run_certification
  - dev_native_capability_orchestration
  - dev_decision_materialization
decisions:
  - Atlas Dev continua sendo fluxo curto; Forge continua sendo fluxo de Obra longa.
  - Todo request de programming pode carregar preview provider-safe em `atlas_dev_runtime_intelligence`.
  - Runs persistidos podem materializar os 15 blocos para auditoria e aprendizado.
  - Falha ou bloqueio terminal deve ter capsula de falha antes de ser certificado.
maintenance:
  - Atualizar quando qualquer bloco DevTaskPacketRuntime/DevContextGate/DevNativeCapabilityOrchestrator/DevDecisionMaterialization/DevFailureCapsule/DevOutcomeMemory/DevRunCertification mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-conversation-surface-and-atlas-dev-v1.md
  - docs/engineering-knowledge-base/atlas-dev-forge-relationship-critical-audit.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - app/Services/Ai/Programming/AtlasDevRuntimeService.php
  - app/Services/Ai/Programming/AtlasDev/RuntimeIntelligence/DevRuntimeIntelligenceService.php
  - app/Services/Ai/Programming/AtlasDev/RuntimeIntelligence/DevNativeCapabilityOrchestrator.php
  - app/Models/AtlasDevDecisionMaterialization.php
  - tests/Feature/Ai/Programming/AtlasDev/AtlasDevRuntimeIntelligenceTest.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-runtime-intelligence
graph_title: Atlas Dev Runtime Intelligence
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-conversation-surface-and-atlas-dev-v1
graph_status: active
graph_source: repo
human_name: Atlas Dev Runtime Intelligence
canonical_name: Atlas Dev Runtime Intelligence
technical_name: atlas-dev-runtime-intelligence
product_name: Atlas Dev Runtime Intelligence
internal_product_name: Atlas Dev Run Intelligence Layer
technical_runtime: DevRuntimeIntelligenceService
runtime_acronym: ADRI
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-dev-runtime-intelligence.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-runtime-intelligence.md
  - app/Services/Ai/Programming/AtlasDev/RuntimeIntelligence
allowed_changes:
  - Ajustar os 15 blocos quando codigo, testes e certificacao mudarem juntos.
forbidden_changes:
  - Transformar Atlas Dev em Forge.
  - Declarar run Dev como ready sem contexto ou evidencia minima.
  - Persistir texto cru sensivel em failure/outcome memory.
depends_on:
  - atlas-dev-forge-relationship-critical-audit
  - atlas-ai-conversation-surface-and-atlas-dev-v1
flows_to:
  - atlas-programming-superiority-architecture
unlocks:
  - dev-runtime-intelligence
governs:
  - programming.dev
  - programming.review
  - programming.repair
evidence:
  - app/Services/Ai/Programming/AtlasDev/RuntimeIntelligence/DevRuntimeIntelligenceService.php
  - database/migrations/2026_05_22_160000_create_atlas_dev_runtime_intelligence_tables.php
  - tests/Feature/Ai/Programming/AtlasDev/AtlasDevRuntimeIntelligenceTest.php
evidence_refs:
  - symbol: AtlasDevRuntimeService
  - command: atlas:dev:runtime-flows
  - test: AtlasDevRuntimeServiceTest
required_tests:
  - "php artisan test tests/Feature/Ai/Programming/AtlasDev/AtlasDevRuntimeIntelligenceTest.php"
  - "php artisan atlas:dev:run-certify --json"
  - "php artisan atlas:programming:final-certify --json"
requires_evidence: true
risk_level: high
next_actions:
  - Conectar materializacao persistida aos runs reais quando o executor Dev gravar terminal status.
  - Usar outcome memory como entrada do AEMOR quando houver runs reais suficientes.
visual_tags:
  - atlas-dev
  - runtime
  - certification
ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de alterar Atlas Dev.
ai_usage_notes:
  - Use esta doc para diferenciar Dev curto de Forge longo.
quality_gates:
  - "php artisan test tests/Feature/Ai/Programming/AtlasDev/AtlasDevRuntimeIntelligenceTest.php"
  - "php artisan atlas:programming:final-certify --json"
failure_modes:
  - Dev executar provider sem contexto suficiente.
  - Falha de run virar texto solto sem capsula compacta.
  - Outcome terminal nao alimentar memoria/evidence.
observability_signals:
  - dev_runtime_intelligence pass
---
# Atlas Dev Runtime Intelligence

## Resumo

ADRI e a camada de inteligencia operacional do Atlas Dev. Ela cria quinze
artefatos/blocos por run: pacote de tarefa, gate de contexto, impacto de teste,
review senior, rota de delegacao, prompt seguro, scope guard, simulacao,
completion gate, memoria de provider, politica de escalacao, materializacao de
decisoes, capsula de falha, memoria de resultado e certificacao final.

## Papel no Atlas

Atlas Dev resolve tarefas curtas de codigo, debug, review e repair. Ele nao
deve carregar a semantica pesada de uma Obra Forge, mas precisa de prova
minima para nao virar um chat de codigo sem estado.

ADRI fecha essa lacuna: cada run pode ser entendido, bloqueado, reparado,
lembrado e certificado.

## Onde Se Encaixa

`AtlasDevRuntimeService` injeta um preview em `payload.atlas_dev_runtime_intelligence`
antes do provider. Esse preview e read-only e provider-free.

Quando existe run terminal real, `DevRuntimeIntelligenceService::materialize()`
persiste os blocos base nas tabelas `atlas_dev_*` e as decisoes em
`atlas_dev_decision_materializations`.

## Contratos

1. `DevTaskPacketRuntime`
   - Schema: `atlas.dev.task_packet.v1`
   - Define objetivo, risco, workspace, arquivos esperados, escopo, testes e evidencia.

2. `DevContextGate`
   - Schema: `atlas.dev.context_gate.v1`
   - Decide se o packet e seguro para provider.
   - Bloqueia risco alto sem docs/context refs e acceptance criteria.

3. `DevFailureCapsule`
   - Schema: `atlas.dev.failure_capsule.v1`
   - Compacta falha, gate que falhou, classe de erro, arquivos tocados, reparo sugerido e se deve escalar para Forge.

4. `DevOutcomeMemory`
   - Schema: `atlas.dev.outcome_memory.v1`
   - Registra resultado terminal, evidencia, testes selecionados, arquivos mudados e candidatos de aprendizado.

5. `DevRunCertification`
   - Schema: `atlas.dev.run_certification.v1`
   - Declara `ready`, `needs_review` ou `blocked` para todos os 15 blocos.
   - Run falho sem failure capsule nunca recebe `ready`.

6. `DevNativeCapabilityOrchestrator`
   - Schema: `atlas.dev.native_capabilities.v1`
   - Emite as decisoes dos blocos 4-15: test impact, senior review,
     delegation route, prompt projection guard, scope guard, simulation,
     completion gate, provider capacity memory, Forge escalation policy e
     decision materialization.

7. `AtlasDevDecisionMaterialization`
   - Schema: `atlas.dev.decision_materialization.v1`
   - Persiste cada decisao importante por `run_id`, `task_id` e `decision_kind`.

8. `atlas:dev:run-certify`
   - Comando curto de auditoria por run/task.
   - Retorna a certificacao persistida mais recente em JSON.

## Fluxo

```text
request programming
  -> AtlasDevRuntimeService
  -> DevTaskPacketRuntime preview
  -> DevContextGate preview
  -> DevNativeCapabilityOrchestrator preview
  -> provider apenas se provider_safe=true
  -> run terminal
  -> DevFailureCapsule se falhou
  -> DevOutcomeMemory
  -> AtlasDevDecisionMaterialization
  -> DevRunCertification
```

## Regras para IA

- Nao remova RAG gate, ScopeGuard, VerificationGate ou CompletionStateGate.
- Nao copie Forge: Dev e curto; Forge e Obra longa.
- Nao declare provider-safe quando faltarem objetivo, contexto/escopo ou verificacao.
- Nao grave logs enormes em failure capsule; compacte para o erro essencial.
- Nao certifique falha sem `DevFailureCapsule`.
- Nao declare os 15 blocos prontos se `atlas_dev_decision_materializations`
  nao tiver as decisoes canonicas por run/task.

## Escopo de Implementacao

Servicos:

- `DevTaskPacketRuntimeService`
- `DevContextGateService`
- `DevFailureCapsuleRuntimeService`
- `DevOutcomeMemoryService`
- `DevRunCertificationService`
- `DevNativeCapabilityOrchestrator`
- `DevDecisionMaterializationService`
- `DevRuntimeIntelligenceService`

Persistencia:

- `atlas_dev_task_packets`
- `atlas_dev_context_gates`
- `atlas_dev_failure_capsules`
- `atlas_dev_outcome_memories`
- `atlas_dev_run_certifications`
- `atlas_dev_decision_materializations`

## Dependencias

- `AtlasDevRuntimeService`
- `MissionCanonicalHash`
- `AtlasProgrammingFinalCertificationService`
- `AtlasDevRuntimeIntelligenceTest`

## Evidencias

Gates obrigatorios:

```bash
php artisan test tests/Feature/Ai/Programming/AtlasDev/AtlasDevRuntimeIntelligenceTest.php
php artisan atlas:dev:run-certify --json
php artisan atlas:programming:final-certify --json
php artisan atlas:engineering:knowledge docs-health --json
```

## Riscos

- Contexto incompleto gerar patch errado.
- Escopo ausente permitir arquivo errado.
- Teste ausente transformar sucesso aparente em regressao.
- Falha sem capsula impedir reparo preciso.
- Memoria de resultado virar ruido se registrar eventos sem evidencia.

## Exemplos

Packet Dev minimo seguro:

```json
{
  "objective": "Corrigir bug pequeno no Atlas Dev",
  "risk_band": "medium",
  "context_refs": ["docs/engineering-knowledge-base/atlas-dev-runtime-intelligence.md"],
  "expected_files": ["app/Services/Ai/Programming/AtlasDevRuntimeService.php"],
  "suggested_tests": ["php artisan test --filter=AtlasDevRuntimeIntelligence"],
  "required_evidence": ["phpunit", "pint"]
}
```

## Proximas Acoes

- Promover outcome memory para AEMOR quando houver run real suficiente.
- Expor status `provider_safe` na surface quando a UX pedir transparencia.
