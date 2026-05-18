---
id: atlas-self-construction-os-handoff
type: engineering_knowledge
title: Atlas Self-Construction OS Handoff
status: active
category: self-construction
priority: 100
summary: Handoff operacional para retomar o fechamento do Atlas Self-Construction OS sem depender de historico de chat, preservando os tres blockers finais que exigem operador ou provider real.
tags:
  - atlas-ai
  - self-construction
  - agent-control-plane
  - handoff
  - completion-evidence
capabilities:
  - completion_evidence_handoff
  - operator_resume
  - terminal_loop_proof
  - self_programming_gate
decisions:
  - Este handoff e read-only e nao substitui receipts, provider smoke ou completion audit.
  - Self-Programming permanece bloqueado ate o Self-Construction OS completar com evidencia real.
  - Os tres blockers finais nao podem ser fabricados por agente.
maintenance:
  - Atualizar quando o completion audit, operator handoff, terminal-loop proof ou self-programming gate mudarem contrato.
related_paths:
  - docs/engineering-knowledge-base/self-construction/agent-control-plane-contract.md
  - docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php
  - app/Services/Ai/SelfConstruction/AtlasSelfConstructionFinalCompletionReadinessGateService.php
  - app/Console/Commands/AtlasAiSelfConstructionCommand.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-self-construction-os-handoff
graph_title: Atlas Self-Construction OS Handoff
graph_world: atlas
graph_layer: gear
graph_kind: runbook
graph_parent: atlas-ai-self-construction-os
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-self-construction-os-handoff.md
allowed_changes:
  - Atualizar comandos, blockers e evidencias quando o estado canonico do Self-Construction OS mudar.
forbidden_changes:
  - Declarar OS complete, runtime enabled, provider smoke green ou self-programming allowed sem evidencia real.
depends_on:
  - atlas-ai-self-construction-os
  - atlas-self-construction-agent-control-plane-contract
flows_to:
  - atlas-self-programming-os
unlocks:
  - operator-safe-self-construction-resume
governs:
  - self-construction
evidence:
  - docs/engineering-knowledge-base/atlas-self-construction-os-handoff.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: high
visual_tags:
  - gear
  - runbook
  - self-construction
ai_entrypoints:
  - Leia este handoff antes de continuar o fechamento do Self-Construction OS.
ai_usage_notes:
  - Use os comandos abaixo como fonte de verdade antes de propor qualquer proxima acao.
quality_gates:
  - "php artisan atlas:ai:self-construction --atlas-self-construction-os-handoff-status --json"
  - "php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json"
  - "php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json"
failure_modes:
  - Fabricar receipt humano, provider smoke ou completion claim.
observability_signals:
  - completion audit failed_count
  - technical_blocker_count
  - terminal_loop_operational_proof_passed
next_actions:
  - Persistir evidencia real na ordem indicada pelo completion evidence status.
---
# Atlas Self-Construction OS Handoff

## Resumo

Este handoff permite retomar o fechamento do Atlas Self-Construction OS a partir
do estado atual do Agent Control Plane, sem depender do historico de chat. A
fonte de verdade continua sendo o comando de completion audit e os payloads de
completion evidence.

## Papel no Atlas

Serve como ponto de retomada humano/IA para a ultima milha do
Self-Construction OS. Ele nao promove status, nao persiste evidencia e nao
autoriza Self-Programming.

## Onde Se Encaixa

Fica entre o Agent Control Plane e o futuro Self-Programming OS. O Control Plane
ja expoe proof, release dossier, finalization gate, operator action packet,
submission preflight e runbooks finais.

## Contratos

- `completion_allowed` deve permanecer `false` enquanto qualquer blocker final
  existir.
- `self_programming_allowed` deve permanecer `false` ate o completion audit
  estar completo.
- `technical_blocker_count=0` significa que o proximo passo e evidencia de
  operador/provider, nao fabricacao de codigo.
- Receipts e smoke real so podem ser aceitos por comandos de persistencia com
  flags explicitas.

## Fluxo

1. Consultar o handoff compacto:

```bash
php artisan atlas:ai:self-construction --atlas-self-construction-os-handoff-status --json
```

2. Revalidar o audit:

```bash
php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json
```

3. Consultar o proximo artifact/comando:

```bash
php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --json
```

4. Gerar o runtime promotion receipt draft:

```bash
php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --signed-by="<operator>" --reason="<operator reason with at least 32 chars>" --json
```

5. Persistir apenas com payload real revisado:

```bash
php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --runtime-promotion-receipt-json=@/path/to/runtime-promotion.json --persist-runtime-promotion-receipt --json
```

6. Depois do runtime receipt, seguir o mesmo padrao para real provider smoke e
human completion receipt, sempre reexecutando audit e preflight antes de cada
persistencia.

## Regras para IA

- Nao assinar por operador.
- Nao simular provider smoke.
- Nao alterar blockers finais para passar teste.
- Nao habilitar runtime, dispatch, adapter execution, provider call, token spend
  ou self-programming.
- Preservar mudancas paralelas de outros agentes.

## Escopo de Implementacao

Permitido: endurecer status read-only, runbooks, commands, docs e testes do
Self-Construction OS / Agent Control Plane. Proibido: Forge Rivals, AtlasCode,
Voice, Cartografia, desktop e provider real drivers, salvo compatibilidade.

## Dependencias

- Terminal Loop operational proof binding canonico.
- Release dossier verde.
- Certification status batch verde.
- Runtime promotion receipt assinado.
- Real provider smoke observado fora do Atlas.
- Human completion receipt assinado apos runtime e smoke.

## Evidencias

Estado esperado antes de operator/provider evidence:

```text
status=incomplete
failed_count=3
technical_blocker_count=0
human_blocker_count=2
real_provider_blocker_count=1
terminal_loop_operational_proof_passed=true
```

Blockers finais esperados:

```text
runtime_gap_matrix_all_runtime_y
human_signed_os_complete_receipt_present
end_to_end_real_provider_smoke_green
```

## Riscos

- Promover completion sem receipt humano.
- Persistir smoke sem provider run real observado.
- Rodar audit sem terminal-loop proof binding canonico.
- Confundir runbook read-only com execucao real.

## Exemplos

O campo `current_required_operator_artifact` em
`--atlas-self-construction-os-handoff-status --json` e em
`--atlas-self-construction-os-completion-evidence-status --json` indica o
proximo artifact concreto. No estado atual, deve apontar para
`runtime_promotion_receipt`. O handoff status tambem deve expor
`canonical_final_blocker_count=3`, `technical_blocker_count=0` no workspace
verde e `self_programming_allowed=false`. Se `release_dossier_green` voltar a
aparecer como blocker tecnico, use o `release_dossier_refresh_command` exposto
no handoff status para capturar um replay snapshot fresco e revalidar. Para uma
retomada sem historico de chat, siga `operator_resume_command_sequence` em
ordem; ela agrupa inspeção do handoff, checagem/refresh do release dossier,
verificacao do certification status batch, preflight de submissao de completion
evidence, draft, persistencia explicita do artifact atual e rerun do completion
audit com terminal-loop proof canonico.
Quando estiver operando pelo terminal, a forma humana de
`--atlas-self-construction-completion-evidence-submission-preflight-status`
tambem deve ser consultada: ela lista o artifact atual, os blockers
classificados, a origem do terminal-loop proof, a integridade da command
surface, o comando de draft, o comando de persistencia e a fila ordenada de
steps bloqueados/prontos.
Use tambem a forma humana de
`--atlas-self-construction-final-operator-evidence-closure-corridor-status`
para uma visao de corredor: ela expõe technical closure, blockers humanos,
blocker de provider real, progresso, blocking artifacts, closure artifact
sequence, prompt-to-artifact checklist, shell packet, recovery matrix, command
surface e terminal proof guardrails sem depender do JSON bruto.
Depois de persistir evidencias, `post_evidence_guardrail_sequence` deve rodar
`docs-health`, `architecture-validate` e `git diff --check` antes de qualquer
claim de fechamento.
O mesmo status deve expor `closure_artifact_sequence` e
`prompt_to_artifact_checklist`, ambos com hash, para mapear a ultima milha sem
historico de chat: `runtime_gap_matrix_all_runtime_y` para
`runtime_promotion_receipt`, `end_to_end_real_provider_smoke_green` para
`real_provider_smoke`, `human_signed_os_complete_receipt_present` para
`human_completion_receipt` e `completion_audit_authorizes_completion_claim` para
`final_completion_audit`.

## Proximas Acoes

1. Operador revisa e assina runtime promotion receipt.
2. Operador executa/observa real provider smoke fora do Atlas.
3. Operador assina human completion receipt apos runtime e smoke verdes.
4. Rerun completion audit com proof canonico.
5. Apenas se `failed_count=0`, avaliar Self-Programming transition readiness.
