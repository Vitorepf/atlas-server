---
id: atlas-forge-work-packet-native-capabilities
type: engineering_knowledge
title: Atlas Forge Work Packet Native Capabilities
status: active
category: programming-forge
priority: 98
summary: Especificacao dos 10 blocos Forge-native que tornam cada work packet inteligente, verificavel, simulavel, roteavel, reparavel e memoravel sem transformar Forge em Atlas Dev.
human_summary: Versao Forge-native dos recursos de inteligencia que tornam cada Obra mais segura, testavel, roteavel e reparavel.
human_what: Dez blocos que enriquecem cada work packet antes, durante e depois da execucao.
human_purpose: Dar ao Forge capacidade propria de contexto, escopo, teste, simulacao, revisao, roteamento, falha e memoria sem copiar o fluxo curto do Atlas Dev.
human_input: Recebe Obra, intake, milestone, work packet, arquivos permitidos, contexto, evidence e estado terminal.
human_output: Entrega execution_plan com forge_native_capabilities, failure capsule e outcome memory.
human_change_when: Atualize quando o ciclo de work packet, o contrato de Obra, os gates de Forge ou a certificacao de programming runtime mudarem.
human_block_when: Bloqueie se o packet nao tiver contexto minimo, escopo permitido, teste sugerido ou risco senior declarado.
tags:
  - atlas
  - forge
  - work-packet
  - programming
  - canonical-glossary
capabilities:
  - forge_work_packet_intelligence
  - forge_context_gate
  - forge_test_impact
  - forge_failure_intelligence
  - forge_outcome_memory
decisions:
  - Forge nao copia Atlas Dev; Forge implementa versoes nativas para Obra longa.
  - Todo work packet deve carregar `forge_native_capabilities` no execution_plan.
  - Falha de packet deve gerar FFIR failure capsule.
  - Toda conclusao terminal deve gerar FOMR outcome memory.
maintenance:
  - Atualizar quando qualquer bloco FWPIR/FOCG/FTIR/FFIR/FSORB/FSWR/FPPR/FOSG/FOSR/FOMR mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleService.php
  - app/Services/Ai/Programming/Forge/Intelligence/ForgeWorkPacketCapabilityOrchestrator.php
  - app/Models/AiForgeOutcomeMemory.php
  - app/Models/AiForgeWorkPacketWorkcellRoute.php
  - tests/Feature/Ai/Programming/Forge/ForgeWorkPacketNativeCapabilitiesTest.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-forge-work-packet-native-capabilities
graph_title: Atlas Forge Work Packet Native Capabilities
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-programming-forge-flow
graph_status: active
graph_source: repo
human_name: Atlas Forge Work Packet Native Capabilities
canonical_name: Atlas Forge Work Packet Native Capabilities
technical_name: atlas-forge-work-packet-native-capabilities
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-forge-work-packet-native-capabilities.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-work-packet-native-capabilities.md
allowed_changes:
  - Atualizar contratos dos 10 blocos quando codigo e testes mudarem juntos.
forbidden_changes:
  - Declarar que Forge virou Atlas Dev.
  - Remover blocos do cycle sem atualizar certificacao e testes.
depends_on:
  - atlas-programming-forge-flow
flows_to:
  - atlas-forge-operating-system
unlocks:
  - forge-work-packet-native-capabilities
governs:
  - programming.forge
evidence:
  - app/Services/Ai/Programming/Forge/Intelligence/ForgeWorkPacketCapabilityOrchestrator.php
  - database/migrations/2026_05_22_150000_create_ai_forge_packet_intelligence_materializations.php
  - tests/Feature/Ai/Programming/Forge/ForgeWorkPacketNativeCapabilitiesTest.php
evidence_refs:
  - symbol: ForgeWorkPacketCapabilityOrchestrator
  - test: ForgeWorkPacketNativeCapabilitiesTest
required_tests:
  - "php artisan test tests/Feature/Ai/Programming/Forge/ForgeWorkPacketNativeCapabilitiesTest.php"
  - "php artisan atlas:programming:final-certify --json"
requires_evidence: true
risk_level: high
next_actions:
  - Manter os 10 blocos sincronizados com o ciclo real de work packet.
  - Expandir evidencias quando Forge ganhar execucao multi-workcell persistida.
visual_tags:
  - forge
  - work-packet
  - capability
ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de alterar Forge.
ai_usage_notes:
  - Use esta doc para diferenciar Forge longo de Atlas Dev curto.
quality_gates:
  - "php artisan test tests/Feature/Ai/Programming/Forge/ForgeWorkPacketNativeCapabilitiesTest.php"
  - "php artisan atlas:programming:final-certify --json"
failure_modes:
  - Forge copiar Atlas Dev e perder semantica de Obra longa.
  - Work packet executar sem contexto, escopo ou teste minimo.
observability_signals:
  - forge_work_packet_native_capabilities pass
---
# Atlas Forge Work Packet Native Capabilities

## Resumo

Esta doc define os 10 blocos Forge-native que tornam cada work packet mais
inteligente antes da execucao e mais auditavel depois dela. Eles rodam dentro
do ciclo de Obra longa, nao como copia do Atlas Dev.

## Papel no Atlas

Forge trabalha com Obras, milestones e packets. Por isso, precisa decidir
contexto, escopo, testes, roteamento, simulacao, revisao senior e memoria por
packet. Sem essa camada, Forge vira apenas um executor grande com pouca
inteligencia operacional.

## Onde Se Encaixa

Fica abaixo de `atlas-programming-forge-flow.md` e acima do
`ForgeWorkPacketExecutionCycleService`. O doc principal explica o fluxo inteiro;
este doc governa os blocos nativos que aparecem no `execution_plan`.

## Contratos

Todo work packet Forge deve carregar `forge_native_capabilities` no
`execution_plan`. O envelope e read-only, provider-free e nao executa benchmark.

`ForgeWorkPacketCapabilityOrchestrator` monta FWPIR, FOCG, FTIR, FSWR, FSORB,
FOSG, FPPR e FOSR antes da execucao. O status do envelope vira `blocked` se
contexto, scope ou review senior bloquearem o packet.

`ForgeWorkPacketExecutionCycleService` tambem materializa:

- `failure_intelligence` ao repair hook de falha;
- `outcome_memory` ao next action de sucesso, falha ou bloqueio.
- rota workcell por packet em `ai_forge_work_packet_workcell_routes`;
- schedule multi-agent Forge em `ai_forge_multi_agent_schedules`;
- memoria terminal em `ai_forge_outcome_memories`.

## Fluxo

Entrada:

- Obra/intake/milestone/work packet;
- arquivos permitidos e contexto declarado;
- evidence e comandos de teste.

Saida:

- plano enriquecido por FWPIR, FOCG, FTIR, FSWR, FSORB, FOSG, FPPR e FOSR;
- schedule workcell persistida no inicio do cycle;
- rota workcell persistida por packet/cycle;
- capsule FFIR quando falha;
- memoria FOMR persistida quando conclui, falha ou bloqueia.

## Regras para IA

- Nao trate Forge como Atlas Dev.
- Nao execute packet sem contexto minimo e scope guard.
- Nao envie prompt para provider/subagente sem FPPR.
- Nao declare sucesso terminal sem FOMR.
- Nao esconda falha: gere FFIR failure capsule.

## Escopo de Implementacao

| Bloco | Nome | Runtime | Quando roda |
|---|---|---|---|
| FWPIR | Forge Work Packet Intelligence Runtime | `ForgeWorkPacketIntelligenceRuntimeService` | `planExecution()` |
| FOCG | Forge Obra Context Gate | `ForgeObraContextGateService` | `planExecution()` |
| FTIR | Forge Test Impact Runtime | `ForgeTestImpactRuntimeService` | `planExecution()` |
| FFIR | Forge Failure Intelligence Runtime | `ForgeFailureIntelligenceService` | `fail()` |
| FSORB | Forge Senior Obra Review Board | `ForgeSeniorObraReviewService` | `planExecution()` |
| FSWR | Forge Specialist Workcell Router | `ForgeSpecialistWorkcellRouterService` | `planExecution()` |
| FPPR | Forge Provider Projection Runtime | `ForgeProviderProjectionService` | `planExecution()` |
| FOSG | Forge Obra Scope Guard | `ForgeObraScopeGuardService` | `planExecution()` |
| FOSR | Forge Obra Simulation Runtime | `ForgeObraSimulationService` | `planExecution()` |
| FOMR | Forge Outcome Memory Runtime | `ForgeOutcomeMemoryService` | `complete()`, `fail()`, `block()` |

## Dependencias

- `ForgeWorkPacketExecutionCycleService`
- `ForgeWorkPacketCapabilityOrchestrator`
- `ForgeMultiAgentSchedulerService`
- `AiForgeWorkPacketWorkcellRoute`
- `AiForgeOutcomeMemory`
- `AtlasProgrammingFinalCertificationService`
- `ForgeWorkPacketNativeCapabilitiesTest`

## Evidencias

Gate principal:

```bash
php artisan atlas:programming:final-certify --json
```

Deve retornar `forge_work_packet_native_capabilities = pass`.

Teste focado:

```bash
php artisan test tests/Feature/Ai/Programming/Forge/ForgeWorkPacketNativeCapabilitiesTest.php
```

## Riscos

- Envelope existir, mas nao bloquear contexto ruim.
- Test impact escolher teste demais e travar execucao.
- Failure capsule resumir erro demais e perder causa.
- Provider projection vazar contexto bruto para subagente.

## Exemplos

Packet de UI grande sem contexto declarado deve ficar `blocked` em FOCG antes
de qualquer provider. Packet de backend com falha deve gerar FFIR com
`failure_mode`, `repair_hint` e retry budget.

## Proximas Acoes

Conectar `ai_forge_outcome_memories` ao AEMOR quando a promocao de outcome
cross-runtime sair do modo candidato para o modo enforce.
