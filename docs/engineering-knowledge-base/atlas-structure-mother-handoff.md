---
id: atlas-structure-mother-handoff
type: engineering_knowledge
title: Atlas Structure Mother Handoff
status: active
category: architecture-handoff
priority: 99
summary: Handoff compacto para novas sessoes continuarem a estrutura mae enterprise do Atlas AI sem depender do historico da conversa.
tags:
  - atlas-ai
  - structure-mother
  - handoff
  - governance
  - backend
capabilities:
  - architecture_handoff
  - documentation_governance
  - session_bootstrap
decisions:
  - Voice/LiveKit permanece scaffold governado e estacionado fora do caminho critico.
  - Memory/Open Brain, Capture, Tasks, Tools, Long-Running Work, Rivals readiness and Proactive contracts now have read-only audit surfaces.
  - Completion requires real Rivals scored review and human review of critical proactive insights; do not synthesize either.
maintenance:
  - Atualizar ao fechar blocos estruturais grandes.
  - Manter objetivo e abaixo de 220 linhas.
related_paths:
  - docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md
  - docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md
  - docs/engineering-knowledge-base/atlas-ai-pipeline.md
  - docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md
---

# Atlas Structure Mother Handoff

## Missao

A estrutura mae do Atlas AI e o backend governado que transforma o Atlas em uma camada superior aos providers, nao um wrapper. O Kernel decide dominio, fluxo, provider/modelo, politica, memoria, gates, evidencias e receipts. Runtimes, surfaces e tools executam, mas nao decidem.

## Ordem Correta Dos Modulos

1. Memory/Context Engine.
2. Knowledge Base/Open Brain.
3. Inbox/Capture Pipeline.
4. Task/Agent Orchestration.
5. Tool/Action Runtime.
6. Autonomy/Long-Running Work.
7. Evaluation/Rivals Framework.
8. Notification/Proactive Layer.
9. Voice/LiveKit Runtime.

Audit canônico atual:

```bash
php artisan atlas:ai:structure-mother-audit --hours=720 --workspace=/Users/vitorepf/develop/Atlas/atlas-server --json
```

Esse comando agrega os oito modulos sem chamar provider, executar runtime,
promover memoria, resolver Inbox ou tocar Voice/Self-Construction. Ele e o gate
para decidir se `update_goal` e permitido. O campo `completion_checklist` mapeia
cada requisito da estrutura mae para artefato, comando de evidencia, status e
blockers.
O campo `prompt_to_artifact_checklist` e o mapa explicito do pedido para
artefatos: handoff canonico, oito modulos, regras de Voice/Self-Construction,
validacoes obrigatorias e blockers restantes. Ele marca o handoff raiz como
`covered` quando `/Users/vitorepf/develop/Atlas/docs/...` aponta para este
handoff server-local canonico.

Estado atual validado em 2026-05-13: `implementation_complete=true`,
`complete=false`, `status=operational_blocked`, `ready_count=8` e
`completion_gate.update_goal_allowed=false`. Os oito modulos tem superficie
governada pronta, mas a conclusao operacional ainda depende de calendario/humano.
`operator_action_plan` lista exatamente as acoes pendentes:

- registrar review real de Rivals quando a janela vencer;
- revisar insights criticos no Inbox sem auto-resolve/auto-dismiss por agente.
- configurar rates atuais de providers quando `cost-rates --missing` indicar
  `missing_active_cost_rate`; agente nao pode inferir precos.

## Voice/LiveKit

Voice esta em scaffold governado e validado, mas estacionado fora do caminho critico. Foi longe o suficiente para provar LiveKit local, token issuer, SDK, probes, runtime certification e gates. Nao foi promovido a runtime real.

Estado atual:

- LiveKit Server local foi testado e parado.
- Worker LiveKit real nao foi iniciado.
- Runtime real nao foi promovido.
- Gate final permanece `review_required`.

Motivo: voz e surface de experiencia. Memory, Open Brain e Orchestration sao nucleo funcional do Atlas.

## Contratos E Padroes

- Kernel e autoridade de provider, domain, flow, policy e memory.
- Surface nao decide.
- Provider nao decide.
- Tool nao decide.
- Domain nao burla policy.
- Runtime nao executa sem Decision Receipt.
- Promotion exige review/receipt auditavel.
- Tudo relevante vira Evidence Ledger.
- Tudo repetido vira Core.
- Fail-closed por padrao.
- Secrets, tokens e audio cru nunca entram em logs, docs ou ledger.
- Mobile/frontend ficam pendentes governados; nao sao foco agora.

## Validacoes Obrigatorias

Comandos recorrentes:

```bash
php artisan test <tests focados>
php artisan atlas:ai:structure-mother-audit --hours=720 --json
php artisan atlas:ai:architecture-validate --json
php artisan atlas:ai:runtime-boundary --json
atlas engineering knowledge docs-health --json
atlas engineering knowledge sync --prune --json
atlas engineering knowledge index-code --prune --summary-only --json
git diff --check
```

Quando tocar em voice:

```bash
php artisan atlas:ai:voice runtime-certify --require-sdk --callback-loop-wired --production-sdk-loop-wired --json
PYTHONDONTWRITEBYTECODE=1 PYTHONPATH=runtimes/python/voice_realtime:runtimes/python/voice_realtime/tests runtimes/python/voice_realtime/.venv/bin/python -m unittest discover -s runtimes/python/voice_realtime/tests
```

## Docs Canonicos Para Ler Primeiro

- `docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md`
- `docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md`
- `docs/engineering-knowledge-base/atlas-ai-pipeline.md`
- `docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md`
- `docs/engineering-knowledge-base/atlas-ai-master-architecture.md`
- `docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md`
- `docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md`
- `runtimes/python/voice_realtime/README.md`

Para Memory/Open Brain, localizar e ler docs/classes existentes antes de criar qualquer contrato novo.

## Restricoes Criticas

- Nao reverter mudancas existentes sem pedido explicito.
- Preservar trabalho de outras frentes/agentes.
- Nao mexer em mobile/frontend agora.
- Nao criar fluxo paralelo se ja houver contrato/doc.
- Nao promover scaffold como implemented.
- Nao iniciar daemon/processo persistente sem start, stop, status, rollback e logs seguros.
- Nao usar pip global.
- Nao expor secrets.
- Atualizar doc dono e KB quando mudar contrato.
- Respeitar limites de linhas da documentacao.

## Estado Do Repo

O worktree esta sujo. Ha mudancas de voice desta frente e mudancas paralelas de self-construction/autonomy.

Voice relevante:

- `app/Services/Ai/Voice/AtlasVoiceLiveKitServerProbe.php`
- `docker-compose.livekit.yml`
- `app/Console/Commands/AtlasAiVoiceRealtimeCommand.php`
- `app/Http/Controllers/AtlasAiVoiceRealtimeController.php`
- `app/Services/Ai/Voice/AtlasVoiceRuntimeCertificationService.php`
- `app/Services/Ai/Voice/AtlasVoiceRealtimeService.php`
- `routes/api.php`
- `tests/Unit/Ai/Voice/AtlasVoiceLiveKitServerProbeTest.php`
- docs de voice/runtime

Provavel outra frente:

- `app/Models/AtlasSelfConstruction*`
- migration `create_atlas_self_construction_agent_control_plane_tables`
- docs `self-construction/*`
- `atlas-system-graph*`
- alteracoes em `AtlasAiSelfConstructionCommand*`

Preservar tudo.

## Estado Atual Dos Oito Modulos

- Memory/Context Engine: pronto por scorecard, recall metadata, privacy e quality.
- Knowledge Base/Open Brain: pronto por docs health, sync/index-code e Open Brain safety/audit.
- Inbox/Capture Pipeline: pronto por `capture-inbox-pipeline-report`; legacy capture backfill aplicado sem abrir provider/context/memory.
- Task/Agent Orchestration: pronto por receipt/hash-chain report e backfill local seguro.
- Tool/Action Runtime: pronto como report/evidence read-only; sem executar tools.
- Autonomy/Long-Running Work: pronto como report/autonomy receipt read-only; baseline declarada com 5 schedules desabilitados via `atlas:ai:long-running-work-declare-baseline --apply --json`, sem dispatch, `enabled=false` e `next_run_at=null`.
- Evaluation/Rivals Framework: implementado; P4 operacional bloqueado ate review real pontuada.
- Notification/Proactive Layer: implementado; 8 insights criticos exigem operador.

## Blockers Reais

1. Rivals/P4: primeira review real vence em 2026-06-12. Nao registrar score
   sintetico de regret/alignment/agency.
2. Proactive Layer: insights criticos ativos precisam de operador. Agente nao
   pode auto-resolver nem auto-dismissar.
3. Provider cost rates: traces projetados do Evidence Ledger nao contam mais
   como `missing_provider_identity`; restam rates ativos a serem preenchidos
   pelo operador para `claude_cli`, `codex_cli` e `gemini_cli` conforme
   `php artisan atlas:ai:telemetry:cost-rates --missing --hours=240 --json`.
4. P6/P7 continuam futuros: presence/eclipse amplo e memoria longitudinal.
