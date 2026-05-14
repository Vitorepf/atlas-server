---
id: atlas-forge-provider-capacity-continuity-v1
type: engineering_knowledge
title: Atlas Forge Provider Capacity & Continuity v1
status: active
category: programming-forge
priority: 100
summary: Camada local de capacidade + failure memory dos 5 providers runtime canonicos do Atlas Forge Continuum (claude_cli, codex_cli, gemini_cli, claude_codex, atlas-local). Read-model puro; nunca chama provider externo; alimenta Atlas Decide / Provider Topology / Cockpit; cooldown e blocker honesto provider_capacity_exhausted.
tags:
  - atlas
  - forge
  - continuum
  - provider-capacity
  - failure-memory
  - governed-fallback
capabilities:
  - forge_provider_capacity
  - forge_provider_failure_memory
  - capacity_runtime_dispatch_signal
  - capacity_exhausted_blocker
decisions:
  - Capacidade e local: nunca probe ativo a provider externo; sinais sao config presence, runtime binary presence, health snapshot cache, worker events, failure memory persistida em metadata da Obra.
  - Quando o sinal nao existe, o entry vira `unknown` honesto — nunca `available` por inferencia.
  - Failure memory persiste em `AtlasProject.metadata.atlas_forge_provider_failure_memory` (capped 50, dedupe 60s) e tambem em `atlas_ledger_events` quando a tabela existe.
  - Cooldown vem de tabela canonica por failure type; politica e a mesma em policy + memory.
  - Provider Topology consome capacity automaticamente: provider unavailable → role unavailable; capacity exhausted → status `provider_capacity_exhausted` + runtime_dispatch_allowed=false.
  - Fallback Policy registra failure memory automaticamente quando ha Obra; evento carrega capacity_snapshot_id, provider_status_before/after, cooldown_until.
  - Dispatcher executavel + child receipt sao eixo separado (Atlas Forge Continuum Runtime Dispatcher v1, do Claude 1) — esta camada NAO implementa dispatcher.
maintenance:
  - Atualize quando AtlasForgeProviderCapacityService, AtlasForgeProviderFailureMemoryService, AtlasForgeProviderTopologyService, AtlasForgeProviderFallbackPolicyService ou AtlasForgeContinuumCertificationService mudarem.
related_paths:
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
  - docs/engineering-knowledge-base/atlas-forge-provider-topology-and-fallback-v1.md
  - docs/engineering-knowledge-base/system-graph/atlas-decide.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
  - docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md
  - app/Services/Ai/Programming/AtlasForgeProviderCapacityService.php
  - app/Services/Ai/Programming/AtlasForgeProviderFailureMemoryService.php
  - app/Services/Ai/Programming/AtlasForgeProviderTopologyService.php
  - app/Services/Ai/Programming/AtlasForgeProviderFallbackPolicyService.php
  - app/Console/Commands/AtlasForgeProviderCapacityCommand.php
  - app/Console/Commands/AtlasForgeProviderFailureRecordCommand.php
  - app/Http/Controllers/AtlasCodeForgeProviderCapacityController.php
  - apps/desktop/src/surfaces/code/panels/ForgeProviderCapacityPanel.tsx
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-forge-provider-capacity-continuity-v1
graph_title: Atlas Forge Provider Capacity & Continuity v1
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-forge-continuum-os
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-provider-capacity-continuity-v1.md
  - app/Services/Ai/Programming/AtlasForgeProviderCapacityService.php
  - app/Services/Ai/Programming/AtlasForgeProviderFailureMemoryService.php
  - app/Console/Commands/AtlasForgeProviderCapacityCommand.php
  - app/Console/Commands/AtlasForgeProviderFailureRecordCommand.php
  - app/Http/Controllers/AtlasCodeForgeProviderCapacityController.php
  - tests/Feature/Ai/Programming/AtlasForgeProviderCapacityTest.php
evidence:
  - docs/engineering-knowledge-base/atlas-forge-provider-capacity-continuity-v1.md
  - app/Services/Ai/Programming/AtlasForgeProviderCapacityService.php
  - app/Services/Ai/Programming/AtlasForgeProviderFailureMemoryService.php
  - tests/Feature/Ai/Programming/AtlasForgeProviderCapacityTest.php
allowed_changes:
  - Adicionar sinais locais novos quando o catalogo de provider drivers do Atlas evoluir.
  - Endurecer dedupe/cooldown quando o evidence ledger ganhar fingerprints melhores.
forbidden_changes:
  - Probar provider externo no capacity service.
  - Mascarar `unknown` como `available`.
  - Mascarar workspace/provider dirty como clean.
  - Reduzir quality gates por causa de cooldown ou fallback.
  - Implementar dispatcher executavel ou child receipt aqui.
  - Liberar `external_rivals_certification` a partir desta camada.
depends_on:
  - atlas-forge-continuum-os
  - atlas-forge-provider-topology-and-fallback-v1
  - atlas-decide
flows_to:
  - atlas-forge-provider-topology-and-fallback-v1
  - atlas-code-forge-operator-cockpit-v1
  - programming-professional-completion-audit
unlocks:
  - governed-runtime-continuity
  - capacity-aware-decision-receipts
governs:
  - forge_provider_capacity
  - forge_provider_failure_memory
required_tests:
  - "php artisan test --filter='AtlasForgeProviderCapacityTest|AtlasForgeProviderTopologyTest|AtlasForgeContinuumCertificationTest'"
  - "php artisan atlas:forge:provider-capacity --json --strict"
requires_evidence: true
risk_level: critical
visual_tags:
  - forge
  - continuum
  - provider-capacity
  - failure-memory
ai_entrypoints:
  - Leia este doc antes de tocar capacity, failure memory, cooldown, topology consume capacity ou completion audit do bloco provider_capacity.
ai_usage_notes:
  - Capacity e read-model; Atlas Decide despacha runtime.
  - Sinal ausente → `unknown`; nunca inferir `available`.
  - Cooldown e diagnostico, nao reduz quality gate.
quality_gates:
  - obra-bound-failure-recording
  - capacity-honest-unknown
  - no-silent-fallback
  - capacity-exhausted-blocks
  - dispatcher-eixo-separado
failure_modes:
  - Usar capacity service para chamar provider externo (proibido).
  - Marcar provider como available sem sinal real (proibido).
  - Esquecer de propagar capacity_snapshot_id no fallback event.
  - Reduzir gate por causa de cooldown (proibido).
observability_signals:
  - capacity_snapshot_id
  - provider_status_before
  - provider_status_after
  - cooldown_until
  - failure_memory_event_id
next_actions:
  - Quando o Continuum Runtime Dispatcher (Claude 1) ficar pronto, conectar provider_status_before/after via dispatcher receipt.
  - Adicionar sinais reais de telemetry (latency p50, error rate 24h) ao capacity quando o monitor existir.
---
# Atlas Forge Provider Capacity & Continuity v1

## Resumo

Camada local que materializa a capacidade dos 5 providers runtime canonicos do
Atlas Forge Continuum (`claude_cli`, `codex_cli`, `gemini_cli`, `claude_codex`,
`atlas-local`) e a memoria de falhas governadas por Obra. Atlas Decide consome
o snapshot para escolher quem despacha; o Continuum Cockpit renderiza estado e
acoes; nada disso chama provider externo, gasta token ou auto-completa work.

## Papel no Atlas

Sem capacity local, Atlas Decide ficaria refem de heuristica ou probe ativo. A
camada provider-capacity fecha o gap: Atlas Code sabe quais providers estao
disponiveis, degradados ou exauridos; Provider Topology reflete isso em cada
papel; Fallback Policy registra cada falha governada com cooldown e
status_before/after; Continuum Certification audita 19 invariantes; Dispatcher
(eixo separado) consome o snapshot para emitir Decision Receipt + child
receipt quando aplicavel.

## Onde Se Encaixa

```text
Atlas Forge Continuum OS
└─ Atlas Decide
   └─ Provider Topology
      └─ Provider Capacity (this doc)
         ├─ AtlasForgeProviderCapacityService (read-model)
         ├─ AtlasForgeProviderFailureMemoryService (50-cap, dedupe 60s)
         ├─ Topology consume capacity (role status, fallback chain)
         ├─ Fallback Policy record failure memory
         ├─ Continuum Certification block
         ├─ Operator Cockpit panel
         └─ Runtime Dispatcher (separado — Claude 1)
```

## Contratos

| Schema | Quem produz | Quem consome |
|---|---|---|
| `atlas.forge.provider_capacity.v1` | `AtlasForgeProviderCapacityService` | Topology, Cockpit, Audit, Dispatcher |
| `atlas.forge.provider_capacity_entry.v1` | Capacity service (per provider) | UI, Topology, Fallback Policy |
| `atlas.forge.provider_failure_memory.v1` | `AtlasForgeProviderFailureMemoryService` | Capacity service, Cockpit, Audit |
| `atlas.forge.provider_failure_memory_event.v1` | Memory service (per event) | Ledger, Cockpit, replay |

### Providers canonicos

| Provider | Label | Notas |
|---|---|---|
| `claude_cli` | Claude CLI | Anthropic CLI runtime |
| `codex_cli` | Codex CLI | OpenAI Codex CLI runtime |
| `gemini_cli` | Gemini CLI | Google Gemini CLI runtime |
| `claude_codex` | Claude orchestrating Codex | Composto: precisa claude_cli + codex_cli configurados |
| `atlas-local` | Atlas local runtime | Sempre `available`; lint/test/graph/evidence locais |

### Tipos de falha conhecidos

`rate_limit`, `quota_exhausted`, `auth_failed`, `timeout`, `context_limit`,
`model_unavailable`, `provider_error`, `insufficient_capability`,
`provider_capacity_exhausted`.

### Cooldown canonico (segundos)

```text
rate_limit               60
quota_exhausted         600
auth_failed               0   (block)
timeout                  30
context_limit             0   (handoff)
model_unavailable       120
provider_error           30
insufficient_capability   0   (escalate)
provider_capacity_exhausted 900
```

## Fluxo

```text
1. Capacity service inspeciona sinais locais → snapshot
   (claude_cli/codex_cli/gemini_cli/claude_codex/atlas-local).
2. Topology consome snapshot → roles refletem capacity, fallback_chain.capable
   considera capacity, blocker provider_capacity_exhausted quando aplicavel.
3. Fallback policy classifica falha → registra failure memory event
   (capacity_snapshot_id, status_before/after, cooldown_until) na Obra.
4. Cockpit mostra capacity + memoria + acoes operadora (refresh / record).
5. Continuum certification audita 19 invariantes.
6. Dispatcher (Claude 1) consome capacity para emitir Decision Receipt + child
   receipt quando reroute exigir.
```

## Regras para IA

- Nao chamar provider externo no service / verifier / CLI / API.
- Nao marcar `available` sem sinal real.
- Nao mascarar workspace/provider dirty.
- Nao reduzir quality gate por causa de cooldown ou fallback.
- Nao implementar dispatcher executavel aqui.
- Nao liberar Rivals externo a partir desta camada.

## Escopo de Implementacao

| Componente | Path |
|---|---|
| Capacity service | `app/Services/Ai/Programming/AtlasForgeProviderCapacityService.php` |
| Failure memory service | `app/Services/Ai/Programming/AtlasForgeProviderFailureMemoryService.php` |
| CLI capacity | `app/Console/Commands/AtlasForgeProviderCapacityCommand.php` |
| CLI failure-record | `app/Console/Commands/AtlasForgeProviderFailureRecordCommand.php` |
| Controller | `app/Http/Controllers/AtlasCodeForgeProviderCapacityController.php` |
| Audit block | `atlas_forge_provider_capacity_certification` em `ProgrammingProfessionalCompletionAuditService` |
| Desktop panel | `apps/desktop/src/surfaces/code/panels/ForgeProviderCapacityPanel.tsx` |
| Domain types | `AtlasForgeProviderCapacity` / `AtlasForgeProviderCapacityEntry` / `AtlasForgeProviderFailureMemory` / `AtlasForgeProviderFailureMemoryEvent` |
| Bridge | `getForgeProviderCapacity`, `recordForgeProviderFailure` |
| Tauri commands | `bridge_get_forge_provider_capacity`, `bridge_record_forge_provider_failure` |
| Feature tests | `tests/Feature/Ai/Programming/AtlasForgeProviderCapacityTest.php` |

## Dependencias

- `atlas-forge-continuum-os.md` (doc-mae)
- `atlas-forge-provider-topology-and-fallback-v1.md` (consome capacity)
- `system-graph/atlas-decide.md` (autoridade runtime)
- `atlas-programming-forge-flow.md` (mapa pesado)
- `domains/programming-professional-completion-audit.md` (audit eixo)

## Evidencias

```bash
php artisan atlas:forge:provider-capacity --json --strict
php artisan atlas:forge:provider-capacity --obra=<uuid> --json --strict
php artisan atlas:forge:provider-failure-record --obra=<uuid> --provider=claude_cli --model=claude-opus-4-7 --role=primary_builder --failure=rate_limit --json --strict
php artisan atlas:forge:provider-failure-record --obra=<uuid> --provider=claude_cli --failure=provider_capacity_exhausted --json --strict
php artisan atlas:programming:completion-audit --json
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
php artisan test --filter='AtlasForgeProviderCapacityTest|AtlasForgeProviderTopologyTest|AtlasForgeContinuumCertificationTest'
```

## Riscos

| Risco | Bloqueio correto |
|---|---|
| Probar provider externo | Service nao tem cliente HTTP de provider; auditado pelo invariant `no_external_provider_call` |
| Mascarar unknown como available | `runtimePresent=false` ou config missing → status=unavailable; sem sinal → unknown honesto |
| Failure memory virar provider call | Service so escreve em metadata + ledger local; nao chama nada |
| Reduzir quality gate por cooldown | Cooldown e diagnostico; gates sao decididos por governance, nao por capacity |
| Dispatcher escondido aqui | Documento e teste deixam claro: dispatcher e Claude 1; capacity so produz read-model |

## Exemplos

### Snapshot global read-only

```bash
php artisan atlas:forge:provider-capacity --json
```

```json
{
  "schema_version": "atlas.forge.provider_capacity.v1",
  "status": "degraded",
  "providers": [
    { "provider": "claude_cli", "status": "available", ... },
    { "provider": "codex_cli", "status": "available", ... },
    { "provider": "gemini_cli", "status": "available", ... },
    { "provider": "claude_codex", "status": "unavailable", ... },
    { "provider": "atlas-local", "status": "available", ... }
  ],
  "best_available_provider": "claude_cli",
  "available_count": 4,
  "runtime_dispatch_allowed": true
}
```

### Record failure (Obra-bound)

```bash
php artisan atlas:forge:provider-failure-record \
  --obra=<uuid> --provider=claude_cli --model=claude-opus-4-7 \
  --role=primary_builder --failure=rate_limit --json --strict
```

```json
{
  "status": "recorded",
  "event": {
    "schema_version": "atlas.forge.provider_failure_memory_event.v1",
    "provider": "claude_cli",
    "failure_type": "rate_limit",
    "cooldown_until": "...+60s",
    "silent": false,
    "external_provider_call": false
  }
}
```

### Capacity exhausted bloqueia topology

Apos `provider_capacity_exhausted` em todos os capable providers:

```text
topology.status = provider_capacity_exhausted
topology.runtime_dispatch_allowed = false
topology.blockers = [provider_capacity_exhausted]
```

## Proximas Acoes

1. Conectar dispatcher receipt (Claude 1) ao status_before/after quando o
   eixo Runtime Dispatcher ficar pronto.
2. Adicionar telemetry real (latency, error rate) ao capacity quando monitor
   estiver instrumentado.
3. Manter snapshot/memoria isolados de Rivals externo.
