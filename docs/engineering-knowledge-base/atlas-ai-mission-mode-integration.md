---
id: atlas-ai-mission-mode-integration
type: engineering_knowledge
title: Atlas AI Mission Mode Integration
status: active
category: runtime
priority: 91
summary: Runtime ativo que integra Mission Mode ao Hyperflow para detectar objetivos persistentes, criar missions canonicas e injetar mission_id no pipeline sem executar specialist flows.
tags:
  - atlas-ai
  - mission
  - hyperflow
capabilities:
  - mission_mode
decisions:
  - Mission Mode cria e planeja missions, mas nao executa specialist flows.
maintenance:
  - Atualizar quando MissionModeService, comandos ou Hyperflow entry mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-kernel-mission-foundation.md
  - app/Services/Ai/Mission/MissionModeService.php
  - app/Services/Ai/Mission/MissionDetectionService.php
  - app/Services/Ai/Mission/MissionSignal.php
  - app/Services/Ai/Mission/MissionModeResult.php
  - app/Console/Commands/AtlasAiMissionCommand.php
  - app/Services/Ai/RouterRuntime/AtlasHyperflowEntryService.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-mission-mode-integration
graph_title: Atlas AI Mission Mode Integration
graph_world: atlas
graph_layer: flow
graph_kind: flow
graph_parent: atlas-kernel-mission-foundation
graph_status: active
graph_source: repo
human_name: Atlas AI Mission Mode Integration
canonical_name: Atlas AI Mission Mode Integration
technical_name: atlas-ai-mission-mode-integration
cartography_type: flow
canonical_source: docs/engineering-knowledge-base/atlas-ai-mission-mode-integration.md
owner: mission-runtime
repo_paths:
  - docs/engineering-knowledge-base/atlas-ai-mission-mode-integration.md
allowed_changes:
  - Atualizar quando Mission Mode mudar contratos ou entrypoints.
forbidden_changes:
  - Transformar toda conversa em mission pesada.
depends_on:
  - atlas-kernel-mission-foundation
flows_to:
  - atlas-code
unlocks:
  - persistent-mission-mode
governs:
  - mission-mode
evidence:
  - docs/engineering-knowledge-base/atlas-ai-mission-mode-integration.md
  - app/Services/Ai/Mission/MissionModeService.php
  - app/Console/Commands/AtlasAiMissionCommand.php
  - tests/Feature/Ai/Mission/MissionModeServiceTest.php
  - tests/Feature/Ai/Mission/MissionModeHyperflowIntegrationTest.php
evidence_refs:
  - symbol: MissionModeService
  - command: atlas:ai:mission
required_tests:
  - "php artisan test tests/Feature/Ai/Mission/MissionDetectionServiceTest.php tests/Feature/Ai/Mission/MissionModeServiceTest.php tests/Feature/Ai/Mission/AtlasAiMissionCommandTest.php tests/Feature/Ai/Mission/MissionModeHyperflowIntegrationTest.php"
requires_evidence: true
risk_level: medium
next_actions:
  - Manter deteccao, lifecycle e Hyperflow injection alinhados aos testes de Mission Mode.
---
# Atlas AI Mission Mode · integração canônica

## Resumo

Camada leve de deteccao e criacao de missions persistentes.

## Papel no Atlas

Transforma objetivos persistentes em Mission Foundation sem duplicar runtime.

## Onde Se Encaixa

Antes de IntentKernelService no Hyperflow.

## Contratos

Cria/plana missions; nao executa provider nem specialist flow.

## Fluxo

Detecta intent, cria mission, decompoe objectives e transita para o estado operacional inicial.

## Regras para IA

Nao usar Mission Mode para perguntas simples ou tasks triviais.

## Escopo de Implementacao

Mission detection, command surface e integracao com Hyperflow.

## Dependencias

Mission Foundation, Hyperflow entry e specialist flows.

## Evidencias

Service, comando e testes de mission.

## Riscos

Criar missions pesadas para conversas simples.

## Exemplos

`php artisan atlas:ai:mission create --goal="..." --json`.

## Proximas Acoes

Manter signal detection e certification coerentes.

**Status:** ativo · entregue 2026-05-19
**Schema raiz:** `atlas.ai.mission_signal.v1` · `atlas.ai.mission_mode_result.v1` · `atlas.ai.mission_mode_snapshot.v1`
**Camada:** acima dos Specialist Flows, dentro do pipeline Hyperflow.

---

## O que é

Mission Mode é uma camada **leve** de detecção + orquestração que conecta o operador a Mission Foundation já existente (`MissionLifecycleService`, `MissionFactoryService`, `ObjectiveDecomposerService`, `WorkOrderFactoryService`, `MissionEvidenceService`, `MissionCertificationService`).

Resolve o que o operador hoje força no Codex/Claude via `/goal`: persistência, decomposição, evidência exigida antes de declarar conclusão, e certificação determinística (`certification_hash`).

**Sem duplicar nada.** Tudo o que Mission Foundation já cobre (persistência, lifecycle 9-estados, completion gate, 13 checks) continua sendo a fonte da verdade. Mission Mode adiciona apenas:

1. **`MissionDetectionService`** — keyword heuristics determinístico que decide se o prompt deve ativar Mission Mode ou seguir como TRIVIAL/TASK one-shot.
2. **`MissionModeService`** — orchestrator que conecta detect → create → decompose → transition inicial, e mais tarde `running → certifying → completed`.
3. **`AtlasAiMissionCommand`** — CLI canônico `atlas:ai:mission {create|show|certify|list|detect}`.
4. **Ligação ao `AtlasHyperflowEntryService`** — Mission Mode roda **antes** do `IntentKernelService::classify()`. Quando ativa, o `mission_uuid` resultante é injetado no contexto da classificação para que o pipeline downstream (Domain → Flow → Dispatch) consiga ligar trace ↔ mission.

## Quando usar

Mission Mode **ativa** quando o prompt carrega um dos sinais canon:

- **Keywords de persistência** (PT/EN, sem acento): `missão`, `meta`, `objetivo`, `mission`, `goal`, `until completed`, `do not stop`, `keep going`, `faça até`, `não pare`, `long horizon`, etc.
- **Keywords de obra**: `obra`, `épico`, `epic`, `rewrite all`, `projeto inteiro`, `major refactor`, etc. → promove para `mission_type=obra` com `risk_level=high`.
- **Classificação Factory MISSION/OBRA** vinda do `MissionFactoryService::classify()` (≥ 2 bullets, ≥ 2 conjunções).

## Quando NÃO usar

- Pergunta simples (`"O que é Hyperflow?"`) → **skipped**, nenhum `AiMission` criado.
- Task curta com action verb único e ≤ 200 chars sem persistence signal (`"Implementar feature de login"`) → **skipped**.
- Caller upstream já criou a mission e passou `mission_id` no payload → Mission Mode respeita e não recria.

Mission Mode **NUNCA**:
- transforma toda pergunta em missão pesada;
- executa specialist flow (apenas marca lifecycle);
- chama provider externo;
- transita para `completed` sem `MissionCertificationService` passar (evidence + checks críticos verdes).

## Lifecycle

Reusa o canon `MissionLifecycleService::STATUS_*` (9 estados). O texto aqui
descreve a camada Mission Mode; os nomes exatos dos estados vivem no service.

Mission Mode opera transições:
- estado inicial → estado operacional automaticamente no fim do `processIntent()`
- `running → certifying → completed` quando `certify()` retorna `PASSED`

Caller (controller / specialist flow / artisan) é responsável por:
- estado operacional inicial → `running` quando início real
- `running → blocked` quando blocker explícito
- attach evidence_refs ao longo da execução

## Comandos canônicos

```bash
# Detect (dry-run · NÃO persiste)
php artisan atlas:ai:mission detect --goal="..." --json

# Create (detecta + persiste se ativar)
php artisan atlas:ai:mission create --goal="..." [--mission-type=mission] \
  [--primary-domain=programming] [--autonomy-level=suggest] --json

# Show snapshot completo
php artisan atlas:ai:mission show --mission=<uuid> --json

# List com filtros
php artisan atlas:ai:mission list [--status=running] [--limit=20] --json

# Certify (run gate + transit completed se passed)
php artisan atlas:ai:mission certify --mission=<uuid> --json
```

Exit codes:
- `0` — operação executada (PASSED ou FAILED vai no payload `ok`)
- `1` — erro runtime (mission não encontrada, exception)
- `2` — usage error (faltando `--goal`/`--mission`)

## Integração Hyperflow

`AtlasHyperflowEntryService::run()` aceita `MissionModeService` como dependência **opcional nullable** (back-compat preservado). Quando o service está disponível e o payload **não** já carrega `mission_id`:

1. `processIntent($rawInput, $contextFromPayload)` roda **antes** do `IntentKernelService::classify()`.
2. Se Mission Mode ativou, o `mission_uuid` vai no contexto da classify().
3. O envelope canônico `payload.hyperflow_runtime.mission` carrega `mission_uuid`, `mission_type`, `status`, `objectives_count`, `work_orders_count` e o `signal` que originou.
4. `payload.mission_mode` carrega o `MissionModeResult` completo (snapshot leve do orchestrator).
5. `data.mission_id` (top-level) é populado para o controller usar no associate_trace.

Falha em Mission Mode **nunca quebra** o pipeline — qualquer exception degrada silenciosamente para "no-mission" e o Hyperflow segue normalmente.

## Completion gate

**Não há atalho.** Para uma mission transitar para `completed`:

1. `MissionCertificationService::certify()` precisa retornar `STATUS_PASSED` (todos os 13 checks CRITICAL verdes — entre eles: evidence_refs não-vazio, work_orders com receipt_hash, DoD com criteria, eventos canônicos registrados, sem blockers pendentes).
2. `MissionModeService::certify()` então transita `certifying → completed` e sela `certification_hash` (SHA-256 determinístico via `MissionCanonicalHash`).

Mesmo input → mesmo hash. Auditável.

## Control Plane

`AtlasControlPlaneSnapshotService` já agrega missions via `AtlasControlPlaneMissionService::summary()`. Missions criadas pelo Mission Mode aparecem ali automaticamente:

```bash
GET /atlas/ai/control-plane                    # snapshot master
GET /atlas/ai/control-plane/missions/{uuid}    # detalhe (delega para MissionControlPlaneService)
```

Frontend Desktop pode consumir os mesmos endpoints sem mudança — Mission Mode produz registros canônicos da mesma família `AiMission`.

## Limitações reais

- **Detection é determinístico keyword-based.** Prompts com semantics de persistência mas sem keywords (ex: `"continue trabalhando nisso por uma semana"` sem keywords explícitas) podem ser classificados como TASK. Evolução posterior: expandir keyword set conforme telemetria real, ou adicionar LLM-fallback opt-in com cache.
- **`MissionModeService::processIntent()` cria objectives + work_orders síncronos** dentro da transaction. Para missions grandes (Obra) isso pode demorar — não é problema hoje (decomposer + workOrder factory são deterministic e rápidos), mas pode ser movido para queue se necessário.
- **`associateTrace()` é idempotency-naive** — chamadas duplicadas geram múltiplos evidence_refs. Caller decide deduplicação se importar.
- **Não há UI dedicada hoje.** Backend-first. Frontend Desktop consome via Control Plane endpoints. UI dedicada de mission é evolução posterior.

## Relação com peças adjacentes

| Peça | Relação |
|---|---|
| **Hyperflow V2 / Router Runtime** | Mission Mode roda dentro do `AtlasHyperflowEntryService::run()`, antes do IntentKernel. Não substitui. |
| **Universal Composer Runtime** | Independente. Mission Mode lê o prompt já assembled pelo composer; rich input chega via `payload`. |
| **Response Presentation Contract** | Mission Mode injeta `payload.mission_mode` no envelope; Presentation pode renderizar como bloco premium opcional. |
| **Specialist Flows** | Mission Mode é uma camada acima. Specialist flows recebem o `mission_id` e podem anexar evidence_refs ao longo da execução. |
| **Forge / Obras** | `MissionFactoryService.classify()` promove para `obra` quando keywords; Forge intake consome `AiMission` + `AiWorkOrder` canônicos. |
| **Mission Foundation** | Fonte da verdade. Mission Mode é **camada de conveniência**, não duplica. |
| **Control Plane** | Already aggregates missions; Mission Mode populates the same tables. |
| **Evidence Ledger** | `MissionEvidenceService.attach()` é o ponto canônico. Mission Mode chama via `associateTrace()`. |

## Arquivos

- `app/Services/Ai/Mission/MissionSignal.php` — DTO output detection
- `app/Services/Ai/Mission/MissionDetectionService.php` — keyword heuristics
- `app/Services/Ai/Mission/MissionModeResult.php` — DTO output orchestrator
- `app/Services/Ai/Mission/MissionModeService.php` — orchestrator
- `app/Console/Commands/AtlasAiMissionCommand.php` — CLI
- `app/Services/Ai/RouterRuntime/AtlasHyperflowEntryService.php` — integração (dep opcional nullable)
- `tests/Feature/Ai/Mission/MissionDetectionServiceTest.php`
- `tests/Feature/Ai/Mission/MissionModeServiceTest.php`
- `tests/Feature/Ai/Mission/AtlasAiMissionCommandTest.php`
- `tests/Feature/Ai/Mission/MissionModeHyperflowIntegrationTest.php`
