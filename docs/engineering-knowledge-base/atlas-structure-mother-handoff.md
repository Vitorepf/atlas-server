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

Estado atual validado em 2026-05-13 11:37 UTC: `implementation_complete=true`,
`complete=false`, `status=operational_blocked`, `ready_count=8` e
`completion_gate.update_goal_allowed=false`. Os oito modulos tem superficie
governada pronta, mas a conclusao operacional ainda depende de calendario/humano.
`operator_action_plan` lista exatamente as acoes pendentes:

- registrar review real de Rivals quando a janela vencer;
- revisar insights criticos no Inbox sem auto-resolve/auto-dismiss por agente.

O `action_summary` separa acoes acionaveis agora de bloqueios por calendario:
Inbox/push podem ser operados pelo app; Rivals fica em espera ate `review_due_at`.

Novas superficies de revisao humana:

```bash
php artisan atlas:cli:inbox review-critical
php artisan atlas:memory:review-queue --area=semantic_curation --area=memory_delta --json
php artisan atlas:semantic:curation-review <proposal-id> --decision=accept --promote-to-memory --memory-type=strategic_insight --promoted-by=<operator> --json
```

`review-critical` e read-only: mostra headline, resumo, metricas visiveis,
proximo passo e comandos por item, sem expor payload cru e sem alterar status.
Use `--json` apenas para automacao ou integracao.
`semantic:curation-review` grava a decisao do operador e, quando promovido,
registra receipt SHA-256 para Memory/Verbatim.
Toda acao executada via `InboxActionRegistry` registra Evidence Ledger com
`atlas.inbox_action.receipt.v1` e `receipt_hash` SHA-256, inclusive
`mark_read`, `snooze`, `dismiss`, Rivals review e provider cost-rate actions.

Mobile Inbox expoe `GET /v1/mobile/inbox/critical-review` com resumo humano,
metricas visiveis e `decision_options`; o app mostra todos os itens retornados
com acoes governadas de marcar revisado, adiar 7 dias ou descartar apos revisao.
O read model tambem declara `api_contract` com endpoints, acoes permitidas e
receipt `atlas.inbox_action.receipt.v1` para ligar decisao humana a evidencia.

Atlas Rivals/Atlas-Bench expoem bateria real pendente: `battery_execution_contract`
explica zeros sem bateria comparavel; o app tem launcher `Justa oficial`,
`Mesmo modelo` e `Maximo`; o backend exige `rivals/battery-plan`, `plan_hash`,
revisao e aceite de custo antes de qualquer run.
O plano tambem devolve `selection_contract` com modos, requisitos e opcoes
provider/model vindas da config; a tela Rivals usa esse contrato para orientar a
escolha em vez de depender apenas de texto local.
No app, a logica pura do launcher fica em `lib/rivalsBatteryModels.ts` e e
coberta por `scripts/rivals-battery.test.ts`, incluido em `npm run
test:engineering`; isso verifica os modos `Justa oficial`, `Mesmo modelo` e
`Maximo` sem disparar providers.
O `operator_action_plan` tambem declara `POST /ai/rivals-strategy/review` para
registrar scores reais quando `review_due_at` vencer; scores sinteticos seguem
proibidos para liberar P4/completion gate.

Proactive Layer reporta `mobile_push_configuration.delivery_diagnostics`: mobile,
devices, tokens, permissoes e ausencia de tentativa, sem expor token/device id.
Push pendente pode ser auditado com `atlas:cli:mobile replay-push --json`.
Disparo real por CLI tambem e fail-closed: exige `--apply`,
`--confirm-external-dispatch`, `--reason` e receipt de dry-run dos ultimos 15
minutos com candidatos. O structure-mother audit lista essa acao no
`operator_action_plan` quando houver push solicitado sem tentativa.
Tambem ha `POST /ai/mobile/push/replay`: dry-run por padrao; envio real exige
receipt de dry-run dos ultimos 15 minutos com candidatos, `apply`,
`confirm_external_dispatch` e motivo de operador. Toda chamada grava receipt
`mobile.push_replay.requested` com hash do motivo e sem token/device id.
O app consome `GET /ai/structure-mother-audit` para mostrar esse plano no
Atlas-Bench sem depender de JSON cru ou CLI; o plano tambem declara o endpoint,
body seguro e receipt esperado para replay de push.
Esses endpoints operacionais internos ficam sob `atlas.token`; o app usa o
cliente interno existente e testes negativos cobrem token invalido.

## Voice/LiveKit

Voice esta em scaffold governado e validado, mas estacionado fora do caminho
critico. LiveKit local, token issuer, SDK, probes, runtime certification e gates
foram provados; worker/runtime real nao foram promovidos. Motivo: voz e surface
de experiencia. Memory, Open Brain e Orchestration sao nucleo funcional.

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
- Mobile/frontend podem refletir read models governados quando isso remove
  ambiguidade operacional; nao podem decidir, resolver gate ou executar provider.

## Validacoes Obrigatorias

Comandos recorrentes:

```bash
php artisan test <tests focados>
php artisan atlas:ai:structure-mother-audit --hours=720 --workspace=/Users/vitorepf/develop/Atlas/atlas-server
php artisan atlas:ai:structure-mother-audit --hours=720 --json
php artisan atlas:ai:architecture-validate --json
php artisan atlas:ai:runtime-boundary --json
atlas engineering knowledge docs-health --json
atlas engineering knowledge sync --prune --json
atlas engineering knowledge index-code --prune --summary-only --json
git diff --check
```

A versao sem `--json` do structure-mother audit e a superficie diaria para
operador: mostra modulos, completion gate e a tabela `Operator actions` com o
primeiro comando seguro para revisar Inbox, fazer dry-run de push ou aguardar
Rivals sem fabricar score.

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
- Mobile/frontend so podem refletir read models governados.
- Nao criar fluxo paralelo se ja houver contrato/doc.
- Nao promover scaffold como implemented.
- Nao iniciar daemon/processo persistente sem start, stop, status, rollback e logs seguros.
- Nao usar pip global.
- Nao expor secrets.
- Atualizar doc dono e KB quando mudar contrato.
- Respeitar limites de linhas da documentacao.

## Estado Atual Dos Oito Modulos

- Memory/Context Engine: pronto por scorecard, recall metadata, privacy e quality.
- Knowledge Base/Open Brain: pronto por docs health, sync/index-code e Open Brain safety/audit.
- Inbox/Capture Pipeline: pronto por `capture-inbox-pipeline-report`; legacy capture backfill aplicado sem abrir provider/context/memory.
- Task/Agent Orchestration: pronto por receipt/hash-chain report e backfill local seguro.
- Tool/Action Runtime: pronto como report/evidence read-only; sem executar tools.
- Autonomy/Long-Running Work: pronto como report/autonomy receipt read-only; baseline declarada com 5 schedules desabilitados via `atlas:ai:long-running-work-declare-baseline --apply --json`, sem dispatch, `enabled=false` e `next_run_at=null`.
- Evaluation/Rivals Framework: implementado; P4 operacional bloqueado ate review real pontuada.
- Notification/Proactive Layer: implementado; 9 insights criticos exigem operador. Usar `atlas:cli:inbox review-critical` ou `/v1/mobile/inbox/critical-review` para revisao humana consolidada antes de marcar lido, adiar ou descartar.

## Blockers Reais

1. Rivals/P4: primeira review real vence em 2026-06-12. Nao registrar score
   sintetico de regret/alignment/agency.
2. Proactive Layer: 9 insights criticos ativos precisam de operador. Agente nao
   pode auto-resolver nem auto-dismissar; comando consolidado:
   `php artisan atlas:cli:inbox review-critical`.
3. Rivals/Bench: bateria comparavel Atlas vs Claude Code ainda nao foi
   executada; precisa aprovacao do operador por custo/provider externo. Sem isso,
   `comparable_count=0` e claim continua bloqueado.
4. P6/P7 continuam futuros: presence/eclipse amplo e memoria longitudinal.

## Resolvido Neste Ciclo

- Provider cost rates ativos foram preenchidos para `claude_cli`,
  `codex_cli` e `gemini_cli`; performance reports agora recuperam custo
  estimado e nao ficam opacos por `unknown cost`.
  `claude-sonnet-4-6` tem fonte oficial Anthropic: 3000/15000 uUSD por 1K
  tokens. `gpt-5.3-codex-spark` e `gemini-3.1-pro-preview` ficam marcados em
  metadata como estimativas operacionais/research-preview porque as fontes
  oficiais ainda nao publicam rate final especifico desses aliases.
- Inbox critico ganhou read model humano compartilhado entre CLI/mobile/app.
- Painel mobile de Inbox critico ganhou acoes humanas diretas por item.
- Proactive Layer ganhou diagnostico de push delivery para explicar quando Inbox
  foi criado mas push nao chegou.
- Mobile CLI ganhou `replay-push` dry-run/apply para reprocessar push pendente;
  apply exige confirmacao explicita, motivo e dry-run recente com candidatos.
- Atlas Rivals e Atlas-Bench ganharam contrato visual para bateria real pendente,
  launcher com aceite de custo e metricas zeradas explicadas.
- Execucao Rivals agora exige preflight hash, revisao do plano e aceite de custo
  no backend antes de criar run.
